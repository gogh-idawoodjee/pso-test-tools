# Tool wizards and intent-based navigation

Date: 2026-10-15. Status: approved in conversation, awaiting written review.

## Problem

The app's sidebar is grouped by implementation (Base Data, API Services, Additional Tools, Core), not by
what a consultant is trying to do. Running a tool today takes three places: an entry page under Additional
Tools that only picks a dataset, the dataset's Exports tab for uploads, and its Runs tab for running and
reading results. Sys compare is environment-scoped while every other tool is dataset-scoped, and nothing on
screen says so. New consultants cannot tell how one thing leads to another.

## Audience and entry point

Ish plus other PSO consultants who know PSO but not this app. They arrive with a specific question
("audit this LOAD", "compare these SYS files"), so the tool is chosen first and the context (environment,
dataset, files) is collected afterwards. Repeat use on the same dataset must stay fast.

## Decision

Tool-first wizards on one shared base, plus a sidebar and home page regrouped by intent. The dataset page
stays as the record (exports, run history), not the place where work starts. Rejected: a dataset-first
workspace with a pipeline (wrong order for "question first"), and a rename-only tidy (keeps the three
places).

## 1. Navigation and home

Four sidebar groups replace the current ones:

| Group | Contents |
|---|---|
| Analysis tools | Load audit, Region availability, Sys compare, Appointment timeline, Plan travels; later Trim export, Find XML matches, Travel tuning. Each opens its wizard. Tools with no registered implementation stay hidden (existing `ToolRegistry::has` rule). |
| Live PSO actions | Existing API pages (Activity, Resource, Modelling homes and their sub-pages, Generic delete, Custom exception), Gateway upload, Travel Analyzer (until it merges with Plan travels, #29). Internals untouched. Each page gets a one-line banner: this changes a live PSO environment. |
| Setup | Environments, Datasets, Reference values. Dataset tabs (Reference values, Customers, Exports, Runs) unchanged. |
| Admin | Token usage log, Backups, Issue token. |

Home page: a custom Filament dashboard page replacing the default. Top: the four groups as card sections,
one line per tool with the question it answers and the files it needs (derived from
`requiredKinds()` / `optionalKinds()` labels). Hidden tools are hidden here too. Below: "Recent runs", the
current user's last 10 tool runs across all scopes, each linking to its result page.

## 2. The wizard

One abstract page, `App\Filament\Pages\Tools\ToolWizardPage`, using Filament's Wizard component, driven
entirely by the `Tool` contract (`id()`, `requiredKinds()`, `optionalKinds()`, `optionFields()`) and
`Tool::scope()`. Existing `*Entry` pages become one-line subclasses naming their tool. Slug stays
`tools/{tool}`.

Steps:

1. **Where.** Environment select (user's environments), then dataset select filtered to it. Both carry a
   "create new" inline action using the existing resource create forms. For environment-scoped tools
   (`Tool::scope() === Environment::class`) the dataset select is not rendered. Pre-filled from the user's
   last used context (section 3). Next requires a selected scope.
2. **Inputs.** One row per export kind the tool declares, required ones marked. Each row shows the newest
   usable `ExportFile` on the chosen scope for that kind (export date, time left before the 12 h purge) with
   a choice: Reuse or Upload. Upload uses the same component and validation as the Exports relation manager
   today, so root-element checks and expiry behave identically. Next is blocked while any required kind has
   no usable file; the message names the file ("A LOAD export is required").
3. **Options.** Form rendered from `optionFields()`. Skipped entirely (not shown in the step list) when the
   tool returns none.
4. **Run.** Creates the `ToolRun` exactly as the Runs tab does today, shows the existing progress feed, then
   the result inline on completion (same rendering as `ToolRunPage`). Buttons: open full result, run again
   with the same inputs (returns to Run with state kept), back to home. The run appears in the dataset's
   Runs tab as before.

Step state lives in the Livewire component; nothing is persisted until Run except uploads, which are
ordinary `ExportFile` rows the moment they finish (as today).

Out of scope for this spec: moving Sys compare, Filter Load File and Travel Analyzer off their bespoke
forms onto the wizard base. They are regrouped in the sidebar now and migrate in their own issues (#14,
#28, #29).

## 3. Data

One migration: nullable JSON `last_tool_context` on `users`, shape
`{"environment_id": string|null, "dataset_id": string|null}` (UUID strings). Written on Run, read on the Where step. No other
schema change.

## 4. Errors

- Missing or expired exports are caught at Inputs. If a file expires between Inputs and Run, Run refuses
  with a plain message and returns the user to Inputs.
- `ToolSetupException` (e.g. pso-tools checkout missing on the server) is shown on the Run step with its
  message in plain words.
- Leaving mid-wizard loses nothing; no partial run rows.
- Ownership: environments and datasets come through the existing `ownedByCurrentUser` scopes; the wizard
  never accepts a scope id the user does not own (server-side check on Run as well as in the selects).

## 5. Testing (Pest)

- Where: datasets filtered by environment; dataset select absent for environment-scoped tools; last
  context pre-filled; unowned scopes rejected.
- Inputs: required kind missing blocks Next with the named message; newest usable file offered for reuse;
  expired files not offered.
- Options: step absent when `optionFields()` is empty; rendered and validated when not.
- End to end: a fake `Tool` registered in `ToolRegistry`; drive the wizard; assert one `ToolRun` with the
  chosen inputs and options and `last_tool_context` updated.
- Navigation: every page in its new group; home hides unregistered tools; recent runs limited to the
  current user.
- Browser check on LXC 199 remains manual (Ish), tracked as an issue after deploy.

## Wording

Customer-facing plain English throughout: tool names as in `Tool::getLabel()`, "Allocated" never
"placed", no em dashes, warnings on the page not in tooltips (same rules as pso-tools CLAUDE.md).
