# Pull deploy: design

Date: 2026-10-11. Status: approved in conversation, awaiting written review.

## 1. Problem

Both Laravel apps and the private pso-tools checkout on LXC 199 (`pso-services`, Proxmox host
`technodrome`) are deployed by hand: SSH in as `deploy`, `git pull`, `composer install`, `migrate`,
`optimize`, restart the worker. It gets forgotten (the server clone of pso-tools was 22 commits behind on
2026-10-11) and it is the step after every merge that nobody wants to do.

Goal: push to `main` and have it live on the box within a few minutes, with no manual SSH, and a
notification when it happens or fails.

## 2. Decisions

- **Poll from the box, not push from GitHub.** The LXC is on a home LAN behind Nginx Proxy Manager; only
  HTTPS reaches it. A cron on the box that fetches and deploys when `origin/main` has moved needs no
  inbound access, no GitHub secrets and no new services. A self-hosted GitHub Actions runner can replace
  the trigger later without changing the deploy script.
- **Three targets, one mechanism:** `pso-test-tools` and `pso-services` (full sequence) and the private
  `pso-tools` checkout at `/var/www/pso-tools` (pull only; the load audit tool runs its Python from there).
- **No root.** Everything runs as `deploy`, who has no sudo and cannot talk to supervisor. The worker is
  restarted with `php artisan queue:restart`, which makes `queue:work` exit after its current job;
  supervisor (`autorestart=true`) starts it again.
- **Reset, never merge.** `git reset --hard origin/main` so a dirty tree (the gitignore noise already on
  the box) can never block a deploy. The box is not a place where code is edited.
- **Notify through Pushover.** One POST per deploy. The notifier is one function with the channel behind
  it so a Teams webhook can be added as a second channel later.
- **No automatic rollback.** Fix forward or push a revert, which deploys itself.

## 3. Files

In the pso-test-tools repo (the public repo that already carries the server notes):

```
deploy/
  pull-deploy.sh          the script; copied to /home/deploy/bin/pull-deploy on the box
  pull-deploy.env.example PUSHOVER_TOKEN=, PUSHOVER_USER=
  README.md               install steps (section 7 of this spec, kept current)
tests/deploy/
  pull-deploy.bats        shell tests (bats-core), see section 6
```

On the box, all owned by `deploy`:

```
/home/deploy/bin/pull-deploy          the script (mode 755)
/home/deploy/.pull-deploy.env         PUSHOVER_TOKEN, PUSHOVER_USER (mode 600, never in git)
/home/deploy/pull-deploy.log          append-only run log
/home/deploy/.pull-deploy.lock        flock file
```

Crontab for `deploy`:

```
*/2 * * * * flock -n /home/deploy/.pull-deploy.lock /home/deploy/bin/pull-deploy >> /home/deploy/pull-deploy.log 2>&1
```

Commenting that line out is the pause switch.

## 4. What one run does

Targets are a fixed list in the script, in this order:

| name | path | branch | after a pull |
|---|---|---|---|
| pso-test-tools | /var/www/pso-test-tools | main | full sequence |
| pso-services | /var/www/pso-services | main | full sequence |
| pso-tools | /var/www/pso-tools | main | nothing |

For each target, independently (a failure in one does not stop the next):

1. `git -C <path> fetch --quiet origin main`. If the fetch fails, that target fails (step `fetch`).
2. `old=$(git rev-parse HEAD)`, `new=$(git rev-parse origin/main)`. If equal, skip with no log line and no
   notification.
3. Log `<time> <name> <old7>..<new7> deploying`.
4. `git reset --hard --quiet origin/main`.
5. Full sequence only, in this order, each from `<path>`:
   - `composer install --no-dev --optimize-autoloader --no-interaction --no-progress`
   - `php artisan migrate --force`
   - `php artisan optimize:clear`
   - `php artisan optimize`
   - `php artisan queue:restart`
6. Log `<time> <name> <new7> ok in <seconds>s` and notify (normal priority): `pso-test-tools deployed
   a30e5bc in 41s`.

The order is deliberate: `migrate --force` is the only irreversible step, so it runs only after the code
and vendor tree it belongs to are in place. If composer fails, migrate never runs.

Commands used by the script are resolved from `PATH` (`git`, `composer`, `php`, `curl`, `flock`) so tests
can put stubs in front of them.

`pull-deploy --dry-run` runs steps 1 and 2 for every target and prints `<name> <old7>..<new7>` or
`<name> up to date`, touching nothing and sending nothing. It is the first thing to run on the box after
installing.

## 5. Failure

The target stops at the first failing step. The new code stays in place: a half-deployed app with caches
cleared is better than a stale optimize cache against new code, and the next push (or the next run after
the cause is fixed by hand) deploys again from a clean state because `reset --hard` does not care what
happened before.

On failure the script logs `<time> <name> <old7>..<new7> FAILED at <step>` followed by the step's output,
and notifies with high priority (Pushover `priority=1`): title `pso-test-tools deploy failed`, message
`<old7>..<new7> failed at migrate` and the last 10 lines of that step's output.

A target that failed is not retried on later runs: `HEAD` was already moved to `new` by the reset, so the
comparison in step 2 says "up to date" and nothing happens until the next push. That is the intended
behaviour: the notification is the signal to look, and the log holds the output. To force a
retry without a push: `git -C <path> reset --hard <old>` by hand, or run `pull-deploy --force <name>`,
which skips the equality check for that target.

If Pushover itself is unreachable the POST failure is logged and ignored; the deploy result stands.

## 6. Testing

`tests/deploy/pull-deploy.bats` runs the real script against a sandbox under `$BATS_TEST_TMPDIR`:

- A local bare repo plays `origin`; a clone of it plays the target path. "A push" is a commit made in a
  second clone and pushed to the bare repo.
- A `bin/` directory of stubs is put at the front of `PATH`: `composer`, `php` and `curl` append their
  arguments to a calls file and exit 0, unless a file named `fail-<step>` exists in the sandbox, in which
  case the matching call exits 1 after printing ten lines.
- The target list is overridden through `PULL_DEPLOY_TARGETS` (a file with `name path branch
  full|pull` lines) so the test never touches `/var/www`.

Cases:

1. Nothing to do: no calls, no output, exit 0.
2. One push to a full target runs reset then exactly `composer install`, `migrate`, `optimize:clear`,
   `optimize`, `queue:restart`, in that order, then one curl with `priority=0`.
3. A pull-only target runs reset and nothing else, and notifies.
4. `fail-composer`: reset ran, composer ran, no `migrate` call, one curl with `priority=1` and the word
   `composer`, exit code 1, log line `FAILED at composer`.
5. Two targets, the first failing: the second still deploys fully; exit code 1.
6. `--dry-run` with a pending push prints `<name> <old7>..<new7>`, HEAD is unchanged, no calls, no curl.
7. `--force <name>` with nothing pending deploys that target anyway.
8. A second concurrent run is refused by the lock (`flock -n` returns 1).

Run with `bats tests/deploy` (install: `brew install bats-core`). Pest's suite does not run these; the
README says how.

## 7. One-time setup on the box (Ish, by hand)

The harness does not write server configs. Steps, in order:

1. **Fix the shared tokens connection in `/var/www/pso-test-tools/.env`.** `SHARED_DB_DATABASE` is still
   the `path/to/shared.sqlite` placeholder, which is why three old migrations are pending and why
   `migrate --force` fails today. Point `SHARED_DB_*` at a real MariaDB database. Recommendation: the
   app's own `pso_tools` database and credentials (nothing is actually shared today; the migration has
   a has-table guard so it simply creates `personal_access_tokens` there). Then run
   `php artisan migrate --force` once by hand as `deploy` and confirm `migrate:status` shows nothing
   pending.
2. Create a Pushover application, then `/home/deploy/.pull-deploy.env` with `PUSHOVER_TOKEN` and
   `PUSHOVER_USER`, mode 600.
3. `cp /var/www/pso-test-tools/deploy/pull-deploy.sh /home/deploy/bin/pull-deploy && chmod 755` (the
   script is copied, not symlinked, so a broken push cannot break the deployer itself; the README notes
   to re-copy after the script changes).
4. `pull-deploy --dry-run` and read the output.
5. Add the crontab line.

## 8. Out of scope

- GitHub-side visibility of deploys (a self-hosted runner). The script is the unit that would be reused.
- Zero-downtime or atomic (symlinked release) deploys. The apps are single-user tools.
- Database backups before migrate: `backup:run` already runs nightly on the box.
- Teams notifications: a later second channel in the notifier.
