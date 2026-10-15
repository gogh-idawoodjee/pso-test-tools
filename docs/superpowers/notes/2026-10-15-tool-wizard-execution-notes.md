# Tool wizard and navigation rework: execution notes

Branch `feat/tool-wizard-ia`. Not merged, not pushed. Brief and per-task reports: `.superpowers/sdd/2026-10-15-tool-wizard-ia/`.

## What was built

1. `users.last_tool_context` (UUID strings) and `User::rememberToolContext`.
2. `StartToolRun` service and `UnusableInputException`, used by the Runs tab.
3. `Tool::question()`.
4. `ToolWizardPage` base with the Where step and inline create; the `*Entry` pages are subclasses; new `SysCompareEntry`, hidden until #14 registers a tool.
5. Inputs step: a Select per kind with a purge countdown via `RunInputPicker::optionsWithExpiryFor`, `ExportDropZone` embedded, cleared kinds stay cleared, newly uploaded files are selected.
6. Options step hidden when empty; `start()` goes through `StartToolRun`, with a 404 on ownership.
7. Run step shares `filament.tool-runs._run-panel` with `ToolRunPage`, with an `assertChosenFilesUsable` pre-check and `runAgain`.
8. `Groups` constants, four sidebar groups; `QuickLaunch` and `StatsOverview` deleted.
9. Live-environment banner via a `PAGE_START` render hook scoped to `LivePsoActionPages`.
10. Custom Dashboard with `ToolCatalogue` and `RecentRuns` widgets.
11. Empty Runs tab points at the wizards; README navigation paragraph; these notes.

## Deferred

- The usability rule is duplicated between `ToolWizardPage::assertChosenFilesUsable` and `StartToolRun`.
- `ExportKind::from()` on client-controlled file keys can throw.
- `resetWizard()` is unspecified and untested.
- The RecentRuns Open action fails for a run whose scope was deleted.
- The "Reference values" catalogue link goes to Regions only.
- `wizard-upload.blade` hard-codes 12 hours while the drop zone reads `exports.ttl_hours`.
- The option label uses a two-space separator that HTML collapses.
- File Selects are not live, so a clear is only sent with the next request (browser check).
- Stale "Base Data" docblock in `DatasetReferenceValueResource`.
- The Runs tab now validates files against the picker (behaviour change).
- Several test statics (`FakeTool::$kinds`, `$optionFields`) are reset inline rather than in `afterEach`.

## After merge

1. `php artisan migrate`
2. `php artisan optimize:clear`
3. Restart the queue worker.
4. Browser check on LXC 199:
   - Home shows four sections and Recent runs.
   - Sidebar shows Analyse exports, Live PSO actions, Setup, Admin in that order.
   - Load audit wizard on the SaskTel dataset: Where pre-fills; Inputs shows the newest LOAD with its countdown; uploading a file selects it; clearing SYS stays cleared; Run shows progress then the result; "Open the full result" and "Run again" work.
   - Sys compare entry is absent from the sidebar.
   - Banner shows on Activity Services.
   - Runs tab empty state reads "Start a run from a tool under Analyse exports; it will appear here."
