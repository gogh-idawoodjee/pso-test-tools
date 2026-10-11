# Deploying to LXC 199

`pull-deploy.sh` runs as the `deploy` user from cron every 2 minutes. For each target it fetches origin and,
when `origin/main` has moved, resets the checkout to it; the two Laravel apps then get `composer install`,
`migrate --force`, `optimize:clear`, `optimize` and `queue:restart` (supervisor restarts the worker). One
Pushover message per deploy, high priority on failure. Design and failure rules:
`docs/superpowers/specs/2026-10-11-pull-deploy-design.md`.

| target | path | after a pull |
|---|---|---|
| pso-test-tools | /var/www/pso-test-tools | full sequence |
| pso-services | /var/www/pso-services | full sequence |
| pso-tools | /var/www/pso-tools | nothing (private checkout the load audit runs from) |

## Install (once, as `deploy` on the box)

1. Fix the shared tokens connection in `/var/www/pso-test-tools/.env`: `SHARED_DB_*` still holds the
   `path/to/shared.sqlite` placeholder, which blocks `migrate --force`. Point it at a real MariaDB database
   (the app's own works; the migration only creates `personal_access_tokens` if it is missing), then run
   `php artisan migrate --force` by hand and check `php artisan migrate:status` shows nothing pending.
2. Create a Pushover application, then:
   ```
   cp /var/www/pso-test-tools/deploy/pull-deploy.env.example ~/.pull-deploy.env
   chmod 600 ~/.pull-deploy.env   # fill in PUSHOVER_TOKEN and PUSHOVER_USER
   ```
3. Install the script. It is copied, not symlinked, so a bad push cannot break the deployer itself:
   ```
   mkdir -p ~/bin
   cp /var/www/pso-test-tools/deploy/pull-deploy.sh ~/bin/pull-deploy
   chmod 755 ~/bin/pull-deploy
   ~/bin/pull-deploy --dry-run
   ```
4. Enable it (`crontab -e`):
   ```
   */2 * * * * $HOME/bin/pull-deploy >> $HOME/pull-deploy.log 2>&1
   ```
   The script takes its own lock (`~/.pull-deploy.lock`), so overlapping runs are refused.

Commenting out the crontab line pauses deploys. After changing `pull-deploy.sh`, repeat step 3.

## Day to day

- `tail -f ~/pull-deploy.log` on the box shows every deploy and the output of a failed step.
- A failed target is not retried until the next push. To redo it by hand: `~/bin/pull-deploy --force
  pso-test-tools`.
- A dirty working tree on the box is overwritten (`git reset --hard`); the box is not a place to edit code.

## Tests

```
brew install bats-core flock
bats tests/deploy
```

The tests run the real script against a local bare repo with stubbed `composer`, `php` and `curl`. They
are not part of the Pest suite.
