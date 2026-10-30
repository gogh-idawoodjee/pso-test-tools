# Load audit port: execution notes

Plan: `docs/superpowers/plans/2026-10-11-load-audit-port.md`. Spec: `docs/superpowers/specs/2026-10-10-load-audit-port-design.md`.
Executed with subagent-driven development: a fresh implementer per task, a spec and quality review after each, fix rounds where the review found Important issues, then one whole-branch review and one fix wave with one scoped re-review.

- pso-tools (Task 1): branch `load-audit-progress`, one commit `98672d5`, off `main` at `e1d1bf9`. Not pushed.
- pso-test-tools (Tasks 2 to 7): branch `feat/load-audit`, from `a30e5bc`. Not merged, not pushed.

## Result

- pso-test-tools full suite: 462 passed, 18 skipped (17 sample-file tests that need local data, plus the load audit golden test, which needs env vars). `ExampleTest` excluded as before.
- pso-tools `python3 -m unittest discover -s tests/load_audit -t .`: 6 passed.
- Golden test ran once on real data (Acme-style `sunday` LOAD and plan, no SYS): the web run and the command line produced identical findings JSON (66 findings: 30 green, 16 amber, 3 red, 17 info).
- pso-tools parity: `Compare-LoadAudit.ps1` reported all 66 checks match, exit 0, on the real LOAD and plan, before and after the progress change; the two JSON and markdown outputs are byte identical.
- Not exercised on real data: a SYS file (none on this machine). The SYS path is covered by unit and fixture tests only.
- Not done on purpose: no browser check of the run page, dashboard iframe or Runs tab (standing preference); worth a look after the merge.

## Rulings made during execution

1. **Progress throttle keys on the stage, not the label.** The plan's labels carry the percent ("Reading the LOAD: 45%"), so comparing labels would never throttle. `ToolContext::reportProgress` gained an optional 4th argument `?string $stage`; the "changed, save at once" check compares `$stage ?? $label`. `ProgressLine::parse` returns a 4th key `stage`. Cost if wrong: one optional argument to remove.
2. **pso-tools parity check used a throwaway worktree of `main`**, not `git stash` and `git switch`, to avoid stashing untracked files in the private repo. Cost if wrong: none.
3. **Models:** implementers sonnet (haiku for Task 2), reviewers sonnet, whole-branch review opus.
4. **A second Claude session switched the shared checkout to `main` mid-run** and committed `d319312` (docs, export-sets notes) there. `feat/load-audit` was not affected. From Task 5 the work ran in a git worktree at `.worktrees/load-audit` (vendor copied, `.env` copied). That worktree is git-ignored; `git worktree remove .worktrees/load-audit` when done. Cost if wrong: none, the commits live on the branch.
5. **README migrate step follows spec section 8** (`migrate --force --path=<progress migration>`), not the plan's plain `migrate --force`. Cost if wrong: README wording.
6. **Golden test count assertion includes INFO findings.** The script emits INFO findings; the spec's counts (red, amber, green) leave them out on purpose. The assertion now checks that every finding is one of the four. Cost if wrong: the assertion only.
7. **Final fix wave was one dispatch** covering all final-review findings, followed by one scoped re-review; no second wave.
8. **Routes use Filament's `Authenticate` middleware**, not `auth` (the app has no `login` route; login is the Filament panel). Unauthenticated users go to the panel login; users who cannot access the panel get 403.

## Review rounds

| Task | Result |
| --- | --- |
| 1 pso-tools progress lines | clean first time |
| 2 config and checkout locator | clean first time |
| 3 progress and summary on runs | clean first time |
| 4 option fields, command builder | clean first time |
| 5 LoadAuditTool | 1 fix round: non-string `stage` threw a TypeError inside the process callback; stderr lines split across chunks were dropped |
| 6 dashboard route, pages | 1 fix round: the Runs tab Findings column printed the counts three times (array state) |
| 7 golden test, README | 1 fix round: README migrate step, customer-flavoured values and a personal path in a public file, temp dirs holding real findings not removed |
| Whole branch (opus) | 0 Critical, 3 Important (scratch subfolder never removed; missing Python gave an `sh` error instead of the "not set up" message; README lacked the 1 GB RAM step), fixed in one wave and re-reviewed clean |

## Left undone or deferred

### Fixed after the whole-branch review
Scratch removal is recursive; missing Python gives the spec message; README has the RAM step and Python 3 note; error text capped at 300 characters; view guards for a run with no page; cross-dataset 404 and non-panel 403 tests; option validation test; temp folders cleaned in tests.

### Deferred minors
- Runs tab loads the whole encrypted `result` column (about 0.7 MB per load audit row) on every poll; select without it.
- `ToolRunPage::getToolRun()` re-queries and decrypts about six times per render; memoize per request.
- The log keeps the last 200 stderr lines; spec 4.2 says 50 (for the failure message, which uses the last line only).
- Failure message lower-cases the stage ("reading the load"); `lcfirst` would keep LOAD and SYS capitalised.
- Last stderr line goes to `error_message` (plain text column) and can quote customer values from a traceback. The spec mandates this message; it is capped at 300 characters now. Owner to confirm the exposure is acceptable.
- `removeScratch` guard is a string prefix check, not real paths; paths are uuid-built and app-controlled.
- A relative `TOOLS_PYTHON` containing `/` is checked against the PHP cwd but run from the scratch folder.
- Throttled progress is dropped, not deferred, so the last percent in a stage can be stale until the next stage.
- `--review` differs between the web run (Runs tab URL) and the golden test's command line run (`about:blank`); it only affects the HTML.
- pso-tools: `ReadProgress.done()` also writes 100% when a read failed (the exit code tells the truth); an untracked `load-audit/.audit_load.py.swp` sits in that checkout and is not part of the commit.
- Redundant `'web'` middleware on the new routes; process callback and fake script details noted in the task reports.

### Found, not part of this branch
- **`/download/{filename}` in `routes/web.php` has no auth** and `{filename}` matches `.*`. A reviewer's in-process check returned 500 for a traversal attempt (Symfony refuses `/` in the download name), so it looks blocked by accident, not by design. Worth its own fix.

## To do after merge

- Push `load-audit-progress` in pso-tools (Ish), then `git pull` in `/var/www/pso-tools` on the server.
- On the server: 1 GB RAM, `TOOLS_PSO_TOOLS_PATH=/var/www/pso-tools`, `php artisan migrate --force --path=database/migrations/2026_10_11_000004_add_progress_and_summary_to_tool_runs_table.php`, `optimize:clear`, `optimize`, restart the queue worker (README has the list).
- Run one real audit with a SYS file and look at the run page in the browser (progress bar, iframe, both downloads, Runs tab counts).
- Windows note: none. `Build-LoadAudit.ps1` is untouched.
