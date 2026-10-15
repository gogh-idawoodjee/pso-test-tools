#!/usr/bin/env bash
#
# pull-deploy: deploys the apps on LXC 199 whenever origin/main has moved.
#
# Runs as the deploy user from cron (see deploy/README.md). For each target it
# fetches origin, and when origin/main differs from HEAD it resets the checkout
# to it and, for a "full" target, runs composer install, assets (npm ci and npm run
# build, only when package.json exists), migrate, optimize and queue:restart. One Pushover message per deploy; failures at high priority.
#
#   pull-deploy                 deploy whatever has moved
#   pull-deploy --dry-run       say what would move, touch nothing
#   pull-deploy --force <name>  deploy that target even when nothing moved
#
# Design: docs/superpowers/specs/2026-10-11-pull-deploy-design.md
# Bash 3.2 compatible (the tests run on macOS); no root, no sudo.

set -u

ENV_FILE="${PULL_DEPLOY_ENV:-$HOME/.pull-deploy.env}"
LOCK_FILE="${PULL_DEPLOY_LOCK:-$HOME/.pull-deploy.lock}"
TARGETS_FILE="${PULL_DEPLOY_TARGETS:-}"
PUSHOVER_URL="${PUSHOVER_URL:-https://api.pushover.net/1/messages.json}"

# name  path  branch  full|pull   (full = composer, assets, migrate, optimize, queue:restart after the pull)
default_targets() {
    cat <<'EOF'
pso-test-tools /var/www/pso-test-tools main full
pso-services   /var/www/pso-services   main full
pso-tools      /var/www/pso-tools      main pull
EOF
}

targets() {
    if [ -n "$TARGETS_FILE" ]; then
        cat "$TARGETS_FILE"
    else
        default_targets
    fi
}

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

# notify <priority> <title> <message>; silent when no Pushover keys are configured.
notify() {
    [ -n "${PUSHOVER_TOKEN:-}" ] && [ -n "${PUSHOVER_USER:-}" ] || return 0

    curl -s -f --max-time 10 \
        -F "token=$PUSHOVER_TOKEN" \
        -F "user=$PUSHOVER_USER" \
        -F "priority=$1" \
        -F "title=$2" \
        -F "message=$3" \
        "$PUSHOVER_URL" > /dev/null 2>&1 || log "notify failed (pushover unreachable)"
}

# fail_target <name> <old> <new> <step> <output>
fail_target() {
    log "$1 $2..$3 FAILED at $4"
    printf '%s\n' "$5"
    notify 1 "$1 deploy failed" "$2..$3 failed at $4
$(printf '%s\n' "$5" | tail -n 10)"
}

# run_step <path> <command...>: runs in <path>, prints combined output, returns the command's status.
run_step() {
    local path="$1"
    shift
    (cd "$path" && "$@" 2>&1)
}

# assets_step <path>: npm ci then npm run build; skipped silently (status 0, no output) without a package.json.
assets_step() {
    local path="$1" out
    [ -f "$path/package.json" ] || return 0
    out=$(run_step "$path" npm ci --no-audit --no-fund) || { printf '%s\n' "$out"; return 1; }
    run_step "$path" npm run build
}

# deploy_target <name> <path> <branch> <mode> <force 0|1>
deploy_target() {
    local name="$1" path="$2" branch="$3" mode="$4" force="$5"
    local old new output started step

    output=$(git -C "$path" fetch --quiet origin "$branch" 2>&1) || {
        fail_target "$name" "$(git -C "$path" rev-parse --short=7 HEAD 2>/dev/null || echo unknown)" "?" fetch "$output"
        return 1
    }

    old=$(git -C "$path" rev-parse --short=7 HEAD)
    new=$(git -C "$path" rev-parse --short=7 "origin/$branch")

    if [ "$DRY_RUN" = 1 ]; then
        if [ "$old" = "$new" ]; then echo "$name up to date"; else echo "$name $old..$new"; fi
        return 0
    fi

    if [ "$old" = "$new" ] && [ "$force" != 1 ]; then
        return 0
    fi

    log "$name $old..$new deploying"
    started=$(date +%s)

    output=$(git -C "$path" reset --hard --quiet "origin/$branch" 2>&1) || {
        fail_target "$name" "$old" "$new" reset "$output"
        return 1
    }

    if [ "$mode" = full ]; then
        for step in composer assets migrate optimize:clear optimize queue:restart; do
            case "$step" in
                composer) output=$(run_step "$path" composer install --no-dev --optimize-autoloader --no-interaction --no-progress) ;;
                assets)   output=$(assets_step "$path") ;;
                migrate)  output=$(run_step "$path" php artisan migrate --force) ;;
                *)        output=$(run_step "$path" php artisan "$step") ;;
            esac || {
                fail_target "$name" "$old" "$new" "$step" "$output"
                return 1
            }
        done
    fi

    log "$name $new ok in $(( $(date +%s) - started ))s"
    notify 0 "$name deployed" "$name deployed $new in $(( $(date +%s) - started ))s"
}

DRY_RUN=0
FORCE_NAME=""
while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --force)
            shift
            FORCE_NAME="${1:-}"
            [ -n "$FORCE_NAME" ] || { echo "usage: pull-deploy [--dry-run] [--force <target>]" >&2; exit 2; }
            ;;
        -h|--help) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "another run holds the lock ($LOCK_FILE)" >&2
    exit 1
fi

if [ -f "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    . "$ENV_FILE"
fi

failed=0
while read -r name path branch mode; do
    [ -n "$name" ] || continue
    case "$name" in \#*) continue ;; esac
    if [ -n "$FORCE_NAME" ] && [ "$FORCE_NAME" != "$name" ]; then
        continue
    fi
    force=0
    [ "$FORCE_NAME" = "$name" ] && force=1
    deploy_target "$name" "$path" "$branch" "$mode" "$force" || failed=1
done <<EOF
$(targets)
EOF

exit "$failed"
