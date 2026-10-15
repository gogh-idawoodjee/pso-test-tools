#!/usr/bin/env bats
# Tests for deploy/pull-deploy.sh. Real git against a local bare "origin"; stubbed composer, php and curl.
# Run: bats tests/deploy

SCRIPT="$BATS_TEST_DIRNAME/../../deploy/pull-deploy.sh"

setup() {
    SANDBOX="$BATS_TEST_TMPDIR/sandbox"
    mkdir -p "$SANDBOX/bin" "$SANDBOX/home"
    export HOME="$SANDBOX/home"
    export CALLS="$SANDBOX/calls"
    export CURL_CALLS="$SANDBOX/curl"
    export PULL_DEPLOY_TARGETS="$SANDBOX/targets"
    export PULL_DEPLOY_ENV="$SANDBOX/pull-deploy.env"
    export PULL_DEPLOY_LOCK="$SANDBOX/lock"
    export SANDBOX
    printf 'PUSHOVER_TOKEN=tok\nPUSHOVER_USER=usr\n' > "$PULL_DEPLOY_ENV"
    : > "$PULL_DEPLOY_TARGETS"

    make_stub composer 'composer'
    make_stub php '$2'
    make_stub npm '"npm-$1"'
    cat > "$SANDBOX/bin/curl" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CURL_CALLS"
EOF
    chmod +x "$SANDBOX/bin/curl"
    export PATH="$SANDBOX/bin:$PATH"

    export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
}

# make_stub <command> <step expression>: records "<command> <args>"; fails once when $SANDBOX/fail-<step> exists.
make_stub() {
    cat > "$SANDBOX/bin/$1" <<EOF
#!/usr/bin/env bash
step=$2
printf '%s %s\n' "$1" "\$*" >> "\$CALLS"
if [ -e "\$SANDBOX/fail-\$step" ]; then
    rm "\$SANDBOX/fail-\$step"
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do echo "\$step output line \$i"; done
    exit 1
fi
EOF
    chmod +x "$SANDBOX/bin/$1"
}

# add_target <name> <full|pull>: a bare origin with one commit, a clone at the target path, a work clone to push from.
add_target() {
    local name="$1" mode="$2"
    git init -q --bare -b main "$SANDBOX/$name.git"
    git clone -q "$SANDBOX/$name.git" "$SANDBOX/$name-work" 2>/dev/null
    (cd "$SANDBOX/$name-work" && git checkout -q -b main && echo one > file && git add file && git commit -q -m one && git push -q origin main)
    git clone -q "$SANDBOX/$name.git" "$SANDBOX/$name" 2>/dev/null
    printf '%s %s main %s\n' "$name" "$SANDBOX/$name" "$mode" >> "$PULL_DEPLOY_TARGETS"
}

add_package_json() {
    (cd "$SANDBOX/$1-work" && echo '{}' > package.json && git add package.json && git commit -q -m pkg && git push -q origin main)
}

push_to() {
    (cd "$SANDBOX/$1-work" && echo "$RANDOM" >> file && git add file && git commit -q -m more && git push -q origin main)
}

head_of() { git -C "$SANDBOX/$1" rev-parse HEAD; }
origin_of() { git -C "$SANDBOX/$1.git" rev-parse main; }

calls() { cat "$CALLS" 2>/dev/null; }

@test "nothing to do: no calls, no output, exit 0" {
    add_target app full
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    [ -z "$output" ]
    [ ! -e "$CALLS" ]
    [ ! -e "$CURL_CALLS" ]
}

@test "a push to a full target runs the five steps in order (no package.json) and notifies at normal priority" {
    add_target app full
    push_to app
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    [ "$(head_of app)" = "$(origin_of app)" ]
    expected="composer install --no-dev --optimize-autoloader --no-interaction --no-progress
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart"
    [ "$(calls)" = "$expected" ]
    [ "$(wc -l < "$CURL_CALLS")" -eq 1 ]
    grep -q 'priority=0' "$CURL_CALLS"
    grep -q 'app deployed' "$CURL_CALLS"
    [[ "$output" == *"app "*"deploying"* ]]
    [[ "$output" == *" ok in "* ]]
}

@test "a full target with a package.json runs the six steps in order" {
    add_target app full
    add_package_json app
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    expected="composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart"
    [ "$(calls)" = "$expected" ]
    grep -q 'priority=0' "$CURL_CALLS"
}

@test "a full target without a package.json skips assets silently" {
    add_target app full
    push_to app
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    ! grep -q '^npm' "$CALLS"
    [ "$(wc -l < "$CALLS")" -eq 5 ]
    [[ "$output" != *assets* ]]
}

@test "an npm build failure stops before migrate, notifies at high priority, exits 1" {
    add_target app full
    add_package_json app
    touch "$SANDBOX/fail-npm-run"
    run "$SCRIPT"
    [ "$status" -eq 1 ]
    ! grep -q 'migrate' "$CALLS"
    grep -q '^npm run build' "$CALLS"
    grep -q 'priority=1' "$CURL_CALLS"
    grep -q 'failed at assets' "$CURL_CALLS"
    [[ "$output" == *"FAILED at assets"* ]]
}

@test "a pull-only target resets and runs nothing else" {
    add_target tools pull
    push_to tools
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    [ "$(head_of tools)" = "$(origin_of tools)" ]
    [ ! -e "$CALLS" ]
    grep -q 'tools deployed' "$CURL_CALLS"
}

@test "a composer failure stops before migrate, notifies at high priority, exits 1" {
    add_target app full
    push_to app
    touch "$SANDBOX/fail-composer"
    run "$SCRIPT"
    [ "$status" -eq 1 ]
    [ "$(calls)" = "composer install --no-dev --optimize-autoloader --no-interaction --no-progress" ]
    grep -q 'priority=1' "$CURL_CALLS"
    grep -q 'failed at composer' "$CURL_CALLS"
    grep -q 'composer output line 12' "$CURL_CALLS"
    ! grep -q 'composer output line 2$' "$CURL_CALLS"
    [[ "$output" == *"FAILED at composer"* ]]
    [[ "$output" == *"composer output line 1"* ]]
}

@test "a failing first target does not stop the second" {
    add_target one full
    add_target two full
    push_to one
    push_to two
    touch "$SANDBOX/fail-migrate"
    run "$SCRIPT"
    [ "$status" -eq 1 ]
    [ "$(head_of two)" = "$(origin_of two)" ]
    [ "$(grep -c 'queue:restart' "$CALLS")" -eq 1 ]
    [ "$(grep -c 'migrate --force' "$CALLS")" -eq 2 ]
    grep -q 'one deploy failed' "$CURL_CALLS"
    grep -q 'two deployed' "$CURL_CALLS"
}

@test "dry run reports what would move and touches nothing" {
    add_target app full
    add_target tools pull
    push_to app
    before="$(head_of app)"
    run "$SCRIPT" --dry-run
    [ "$status" -eq 0 ]
    [ "$(head_of app)" = "$before" ]
    [ ! -e "$CALLS" ]
    [ ! -e "$CURL_CALLS" ]
    [[ "${lines[0]}" == "app ${before:0:7}..$(origin_of app | cut -c1-7)" ]]
    [[ "${lines[1]}" == "tools up to date" ]]
}

@test "force deploys a named target with nothing pending" {
    add_target app full
    add_target tools pull
    run "$SCRIPT" --force app
    [ "$status" -eq 0 ]
    [ "$(grep -c 'queue:restart' "$CALLS")" -eq 1 ]
    grep -q 'app deployed' "$CURL_CALLS"
    ! grep -q 'tools' "$CURL_CALLS"
}

@test "a second concurrent run is refused by the lock" {
    add_target app full
    exec 8>"$PULL_DEPLOY_LOCK"
    flock -n 8
    run "$SCRIPT"
    exec 8>&-
    [ "$status" -eq 1 ]
    [[ "$output" == *"another run holds the lock"* ]]
}

@test "a failed fetch is reported as the fetch step" {
    add_target app full
    rm -rf "$SANDBOX/app.git"
    run "$SCRIPT"
    [ "$status" -eq 1 ]
    [[ "$output" == *"FAILED at fetch"* ]]
    grep -q 'failed at fetch' "$CURL_CALLS"
}

@test "without pushover keys the deploy still runs and only logs" {
    add_target app full
    push_to app
    rm "$PULL_DEPLOY_ENV"
    run "$SCRIPT"
    [ "$status" -eq 0 ]
    [ ! -e "$CURL_CALLS" ]
    [[ "$output" == *" ok in "* ]]
}
