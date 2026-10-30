# Region availability port: execution notes

Plan: `docs/superpowers/plans/2026-10-11-region-availability-port.md`. Spec: `docs/superpowers/specs/2026-10-11-region-availability-port-design.md`.
Executed with subagent-driven development: a fresh implementer per task and a spec and quality review after each. The whole-branch review is done separately and has not run.

- pso-tools (Task 1): branch `region-availability-progress`, one commit `5e16c59`, off `main` at `1defb8a`. Fast-forward merged to `main` and pushed (as the task said).
- pso-test-tools (Tasks 2 to 9): branch `feat/region-availability`, from `0f04a30`, worked in the worktree `.worktrees/region-availability`. Not merged, not pushed.

## Result

- pso-test-tools full suite: 489 passed, 19 skipped (sample-file tests that need local data, plus the golden test when its env vars are unset). `ExampleTest` excluded. The count is lower than after Task 7 (498) because Task 8 removed the old service test.
- pso-tools `python3 -m unittest discover -s tests/region_availability -t .`: 35 passed (3 new), parity test included.
- Golden test ran on real data (`~/pso-data/ACME/sunday/load.zip`, the PSO Tools checkout): the web run and the command line produced identical gathered JSON apart from `meta.generated`. Run once by the implementer and again by the controller at the end: passed both times.
- pso-tools command line output before and after the progress change: identical normalised JSON, and 0 bytes on stderr without `PSO_PROGRESS`.
- Not done on purpose: no browser check of the run page, the iframe or the Runs tab (standing preference); worth a look after the merge.

## Rulings made during execution

1. **Task 2 step 1 (branch in the main checkout) skipped.** The branch was created with the worktree. Cost if wrong: none.
2. **Task 1 step 6 used a throwaway worktree of `main` and a scratch folder**, not `git stash` and `/tmp`, so the shared stash stack and customer-derived output were not touched. Cost if wrong: none.
3. **Account id is read without the user-owned global scope** (`Environment::query()->withoutGlobalScopes()->find(...)`), found by the Task 5 implementer. The job runs unauthenticated, so the plan's `->environment?->account_id` would have been null and every run would have been `ACCOUNT`. Matches `RunToolJob::scopeOf`. Cost if wrong: account name only.
4. **The old page's URL is `/technician-avail`**, not `/admin/technician-avail` as the spec says (panel path is `/`). The plan's test path was right. Cost if wrong: none.
5. **Placeholder assertion on the Runs tab** (review focus 4) was added in Task 7's commit rather than as a Task 6 fix round. Cost if wrong: none.
6. **Task 3's commit trailer** named the wrong model; the controller amended it to the session attribution before review. Cost if wrong: none.
7. **Task 4: the Carbon case** is its own `it()` instead of a closure inside a dataset row. Cost if wrong: none.
8. **Models:** implementers haiku (Tasks 2 to 4) and sonnet (Tasks 1, 5 to 8); reviewers sonnet.

## Review rounds

| Task | Result |
| --- | --- |
| 1 pso-tools progress lines | clean first time |
| 2 checkout, config, gather stage | clean first time |
| 3 account file | clean first time |
| 4 command builder | clean first time |
| 5 ScriptRun and the tool | clean first time (the scope lookup fix was the implementer's own) |
| 6 one page controller, Runs tab | clean first time |
| 7 run page view, form tests, golden | clean first time |
| 8 retire Technician Availability | clean first time |

No fix rounds were needed.

## Left undone or deferred

### Deferred minors
- `ScriptRun` logs `script` as `$argv[2]`, which assumes `[python, -I, script, ...]`; a basename of the script path would be sturdier.
- An environment-scoped run (not a dataset) always gets the account name `ACCOUNT`; use the environment's own `account_id` if the tool is ever offered there.
- `AccountSettingsFile::write()` ignores `mkdir` and `file_put_contents` failures; the script would then fail on a missing file.
- `DatasetSettings::toAccountFile()` uses `$out` (abbreviated name).
- Omitted empty maps rely on the script's own defaults; the golden test covers the ACME settings only.
- The golden test leaves a copy of the LOAD in `storage/framework/testing/disks/r2` (gitignored). Call `Storage::disk('r2')->deleteDirectory('')` in its `finally`. Removed by hand in the worktree.
- The golden test's `glob(...)[0]` is unguarded and a `load.zip` wins over `load.xml` when both exist.
- The retired test uses `class_exists` and so needs a fresh composer autoloader (after deletions a stale optimized classmap needed `composer dump-autoload` locally).
- The README's "removed 2026-10-11" line will go stale.
- Runs tab still loads the whole encrypted `result` column on every poll (carried over from the load audit; now heavier, see spec section 6).
- pso-tools: `CountingReader.next` naming and `time.time()` instead of `time.monotonic()`; the throttle is not asserted by a test (fixture too small).
- The load audit spec line 185 still quotes the old "The load audit is not set up" message (historical).

### Deploy
Spec section 8: pso-tools `main` is already pushed; the server pulls it, then this branch is merged to `main` and pushed. No migration.
