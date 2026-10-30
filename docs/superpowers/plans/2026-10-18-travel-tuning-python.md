# Travel Tuning Report (pso-tools Python) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Python reference implementation of pso-tools `travel/Build-TravelTuningReport.ps1`: `travel/travel_tuning.py` reads a baseline and one or more tuned plan travels CSVs (plus the polygon file each run used) and writes the same one-page report as the script, with its report data value-for-value equal to a pwsh 7 run of the script.

**Architecture:** One standard-library module, `travel/travel_tuning.py`, built in layers that mirror the script: helpers and the run list, run CSVs, matching across runs, metrics without polygons, polygons and the model check, polygon callouts and map data, then the report data, page and command. The page is the script's here-string template extracted verbatim into `travel/travel_tuning_template.html` with two additions (a warnings banner and a footer note listing the three deviations). Tests compare every section with a committed pwsh capture of the script on a synthetic set, and the whole report with the real golden run in `~/pso-data/ACME/1013/`.

**Tech Stack:** Python 3.9+ standard library (`argparse`, `csv`, `decimal`, `json`, `math`, `re`, `xml.etree.ElementTree.iterparse`), `unittest`. PowerShell 7 (`pwsh`, on the Mac) only to capture the script's expected output.

**Spec:** `/Users/idawoodjee/Herd/pso-test-tools/docs/superpowers/specs/2026-10-18-travel-tuning-design.md` (sections 1 to 3 and 5 to 6 are this plan's; section 4, the web tool, is plan B). Behaviour reference where the spec is silent: `/Users/idawoodjee/Herd/pso-test-tools/docs/superpowers/notes/2026-10-18-travel-tuning-inventory.md` (ignore its section 12 figures: superseded data).

**Where the code goes:** every task runs in the pso-tools repo, `/Users/idawoodjee/Documents/Projects/PSO Tools`, on a branch `travel-tuning-python` made from `main` in Task 1. Never commit to `main` directly; the branch is merged when Ish has checked the page. All commands below run from the repo root.

## Global Constraints

- Standard library only; Python 3.9+ (the Mac's `/usr/bin/python3` is 3.9.6: run the suite with it once at the end).
- Large XML is streamed with `xml.etree.ElementTree.iterparse`; `elem.clear()` only after the fields are read.
- The PowerShell script `travel/Build-TravelTuningReport.ps1`, `make-windows-bundle.sh` and the Windows bundle stay unchanged.
- No em dashes (or en dashes) in generated text or the page; "Allocated", never "placed"; plain English for a customer audience.
- Warnings carry no ids: only run labels and counts. Each goes to stderr as `warning: <text>` and into `meta.warnings`.
- The last stdout line is `summary: <text>` with no ids.
- Exit 0 with the page written; exit 1 on any failure with `Stopped during: <stage>: <sentence>` (known failure, `TravelTuningError`) or `Stopped during: <stage>` plus the error.
- Never commit `*.xml`, `*.zip`, run CSVs, generated reports or anything from `~/pso-data/`; never paste customer names or ids into code, tests or commit messages. `git add` named paths only and check `git status --short` before every commit (the `travel/` folder holds git-ignored client CSVs).
- Every commit message ends with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu
  ```
- Test command for the whole suite: `python3 -m unittest discover -s tests/travel_tuning -t .`

## Review Focus

1. A run whose every row is excluded (all validation errors): the script accepts it, divides by 1 and the common-legs check reads 0%; a person expects a page and the common-legs warning, not a crash. Test: `test_a_run_with_every_leg_excluded` (Task 7).
2. A polygon file that is a SYS export without a Polygon table: a person expects the report without polygon sections (no hotspot, no corridors, model check 0 of 0), not a failure. Test: `test_a_polygon_file_without_polygons` (Task 7).
3. A run CSV that is not UTF-8 (a Windows-1252 export with accented technician names): a person expects it to read, with the bad bytes shown as replacement characters, as `Import-Csv -Encoding UTF8` does. Test: `test_a_csv_that_is_not_utf8_still_reads` (Task 2).
4. Same-location legs written by the web Plan travels tool (Google 0 s, Google minutes blank, PSO time positive): the script keeps them on the Google value, so a long PSO time there is flagged. Test: `test_google_zero_is_kept_and_google_seconds_win` (Task 2).
5. A manifest with absolute paths (what the web app will write from its scratch folder): a person expects the paths to be used as they are. Test: `test_manifest_with_absolute_paths` (Task 1).

---

## File structure

| Path (pso-tools) | Responsibility | Task |
|---|---|---|
| `travel/travel_tuning.py` | The whole port, one module in the script's order | 1 to 7 |
| `travel/travel_tuning_template.html` | The script's page, extracted verbatim, plus the warnings banner and the deviations note | 7 |
| `tests/travel_tuning/__init__.py` | Makes the folder a test package (empty) | 1 |
| `tests/travel_tuning/tt_fixtures.py` | Synthetic run CSVs, polygon files, review CSV, notes and manifest written at test time (`*.xml` is git-ignored, so no input file is committed); the pwsh capture; `json_diff`; `run_main` | 1, 2 |
| `tests/travel_tuning/fixtures/synthetic_pwsh.json` | The script's report data for the synthetic set (pwsh 7.6.6), committed | 2 |
| `tests/travel_tuning/test_helpers.py` | Rounding, numbers, text, Markdown, run list and manifest | 1 |
| `tests/travel_tuning/test_runs.py` | Run CSV reader, exclusions, key, edge cases, the capture itself | 2 |
| `tests/travel_tuning/test_matching.py` | Pairing, shared and common legs | 3 |
| `tests/travel_tuning/test_metrics.py` | Accuracy, pairs, bands, GB summary, worst, check these, timeline | 4 |
| `tests/travel_tuning/test_polygons.py` | Polygon reader, geometry, model check, overlap count | 5 |
| `tests/travel_tuning/test_callouts.py` | Hotspot, strongest, corridors, review CSV, map data, counts | 6 |
| `tests/travel_tuning/test_page.py` | Whole command against the capture, template, warnings, errors | 7 |
| `tests/travel_tuning/test_golden.py` | Real Acme run against the pwsh and Windows reports (skips without the data) | 8 |
| `CLAUDE.md` | Repo structure entry, test command, the parity exception | 9 |

Leg records are plain dicts with these keys (produced by `read_run`, Task 2, and used by every later task): `key` (5 dp coordinate key), `nth` (index among the run's legs with the same key, file order), `la1`, `lo1`, `la2`, `lo2`, `p`, `g`, `d` (minutes, unrounded; `d = p - g`), `ad` (`r1(abs(d))`), `km` (Google distance, else PSO distance, else None), `res`, `res_name`, `gb_from`, `gb_to`, `row` (1-based CSV data row), `merged`, `gps_fix`, `fix_time`, `prefix_km`. A run is a dict: `label`, `file`, `rows`, `legs`, `excluded`, `ex_google`, `ex_pso`, `ex_validation`, `flagged`, `over`, `under`, `total`, `has_fix_cols`. A polygon is a dict: `id`, `desc`, `group`, `gtype`, `w`, `has_w`, `s`, `n`, `west`, `east`, `rect`.

Spec 3.2 pinned behaviours and deviations, and where each is tested:

| Spec item | Task | Tests |
|---|---|---|
| 1. Rounding (R1/R2 away from zero on the scaled double; key and map coordinates half to even on the scaled double) | 1, 2, 6 | `RoundingTests` (Task 1), `test_flag_uses_the_rounded_difference`, `test_leg_values` (Task 2), `test_map_leg_null_km_deviation` (Task 6), golden (Task 8) |
| 2. Corridor groups case-insensitive, sorted by name case-insensitively (pwsh 7) | 6, 8 | `test_corridor_groups_merge_case_and_sort_like_pwsh_7` (Task 6), golden order C15 to C18 (Task 8) |
| 3. String sorts key `(casefold, original)` | 1, 4, 6 | `test_text_key_orders_case_insensitively_then_by_spelling`, `test_gb_keys_are_case_sensitive_and_sorted_case_insensitively`, `test_strongest_ties_by_id` |
| 4. "Check these" ties stable in file order | 4 | `test_check_these_ties_stay_in_file_order` |
| 5. Plain left-to-right sums | 4 | `test_accuracy_and_pairs`, `test_bands_gb_worst_check` (exact equality with the script at halves), golden |
| 6. Corridor weightings `0.00` as .NET Core formats them | 1, 6 | `test_net_fixed2_matches_dotnet_core_custom_format`, `test_weightings_text` |
| Deviation 1: null km on a flagged map leg | 6, 7 | `test_map_leg_null_km_deviation`, `test_map_data_and_counts`, `test_deviations_listed_in_the_footer` |
| Deviation 2: warnings on the page | 7 | `test_exit_summary_and_warnings`, `test_verbatim_from_the_script_apart_from_two_additions` |
| Deviation 3: `<` written as `\u003c` | 7 | `test_page_embeds_the_same_data_with_lt_escaped` |

Rulings this plan makes where the spec is silent or wrong (keep them; they are tested):

- **Spec 3.2 rule 6 is corrected:** .NET Core's custom format `0.00` does not format the exact binary value; it rounds to 15 significant digits first, then halves go away from zero. Checked in pwsh 7.6.6: `(1.005).ToString('0.00')` is `1.01` (the exact value is 1.00499...), `2.675` gives `2.68`, `0.125` gives `0.13`. `net_fixed2` follows pwsh.
- **`meta.page_warnings`:** the script already shows three warnings as banners (`base_misplaced`, `model_warn`, `common_warn`). The page shows a banner for the other two (overlaps, non-rectangles) from `meta.page_warnings`, so nothing is shown twice. `meta.warnings` holds all five for stderr and the web summary. Golden comparisons ignore these two keys (they are ours alone), `meta.generated` and the null km deviation.
- **Synthetic inputs are written by code at test time**, because `.gitignore` has `*.xml`. Only the pwsh capture (JSON, synthetic names such as "Tech A") is committed.
- **The Python command does not split list arguments on commas** (`argparse` lists replace the script's Split-List workaround for `powershell -File`), so labels may contain commas.
- **CSV header names are exact** (inventory 2), messages name files by base name (as plan_travels.py does) and mention the Python flags instead of the script's.
- **A missing `id` or `weighting` child does not stop the run:** PowerShell returns null for a property of null, so the script keeps a polygon with id `''` and skips a weighting it cannot read (checked in pwsh; the inventory's "throws" is wrong). Python does the same.
- **The script file is LF, not CRLF.** The template is its lines 624 to 943 (the here-string body).
- **`(casefold, original)` puts `B1` before `b1`;** culture order (Sort-Object) would put `b1` first. Strings that differ only in case are rare in GB codes and polygon ids; the spec's key stands.
- **A segment lying along a rectangle's edge counts as inside** (Clip keeps it: no axis has p = 0 with q < 0); only a segment meeting an edge or corner at a single point gives None. This is the script's algorithm, kept.

---

### Task 1: Branch, helpers, Markdown subset and the run list

**Files:**
- Create: `travel/travel_tuning.py`
- Create: `tests/travel_tuning/__init__.py` (empty)
- Create: `tests/travel_tuning/tt_fixtures.py` (short version; Task 2 replaces it)
- Test: `tests/travel_tuning/test_helpers.py`

**Interfaces:**
- Consumes: nothing.
- Produces (in `travel_tuning`): `TravelTuningError(ValueError)`, `_ArgError`, constants (`COMMON_WARN_PCT`, `MODEL_WARN_PCT`, `CORRIDOR_MIN_SHARE`, `HOTSPOT_CALLOUT_PCT`, `CHECK_ROWS`, `DISTANCE_BANDS`, `CHART_JS_URL`, `LEAFLET_JS_URL`, `LEAFLET_CSS_URL`, `LIB_FILES`, `TILES`, `TEXT_*`, `HERE`); `num(s) -> float|None`; `r_away(x, digits)`, `r1(x)`, `r2(x)`; `ps_round(x, digits)`; `pct(a, b)`; `net_fixed2(x) -> str`; `ps_num(x) -> str`; `text_key(s) -> tuple`; `esc(s)`; `ndiv(a, b)`; `md_inline(s)`, `markdown_to_html(md) -> str`; `build_parser() -> argparse.ArgumentParser` (raises `_ArgError` on bad arguments); `resolve_in(path, base, what) -> str`; `read_manifest(path) -> [(label, run_csv, polygon_xml)]`; `resolve_runs(args, cwd) -> (labels, run_paths, poly_paths)`; `misplaced_baseline(labels) -> dict|None`.
- Produces (in `tt_fixtures`): `tt` (the module), `ROOT`, `tmpdir() -> str`, `run_main(argv) -> (code, stdout, stderr)`.

- [ ] **Step 1: Make the branch**

```bash
cd "/Users/idawoodjee/Documents/Projects/PSO Tools"
git status --short          # must be empty
git switch main && git switch -c travel-tuning-python
mkdir -p tests/travel_tuning && touch tests/travel_tuning/__init__.py
```

- [ ] **Step 2: Write the short fixtures module**

`tests/travel_tuning/tt_fixtures.py`:

```python
"""Synthetic inputs for the travel tuning tests (no customer data), and the helpers the tests share."""
import atexit
import contextlib
import io
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'travel'))
import travel_tuning as tt  # noqa: E402

_ROOT = []


def tmpdir() -> str:
    if not _ROOT:
        _ROOT.append(tempfile.mkdtemp(prefix='travel-tuning-tests-'))
        atexit.register(shutil.rmtree, _ROOT[0], True)
    return tempfile.mkdtemp(dir=_ROOT[0])


def run_main(argv):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = tt.main(argv)
    return code, out.getvalue(), err.getvalue()
```

- [ ] **Step 3: Write the failing tests**

`tests/travel_tuning/test_helpers.py` (the Markdown expectations are the script's own output: its `Esc`, `Md-Inline` and `ConvertFrom-Md` run in pwsh 7.6.6 on these inputs):

```python
"""Rounding, number, text and Markdown helpers, and the command's run list (spec 3.1, 3.2 rules 1, 3, 6)."""
import argparse
import csv
import math
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir  # noqa: E402


class RoundingTests(unittest.TestCase):
    def test_r1_is_dotnet_away_from_zero_on_the_scaled_double(self):
        self.assertEqual(tt.r1(19.95), 20.0)           # 19.95 * 10 is exactly 199.5; round(19.95, 1) gives 19.9
        self.assertEqual(round(19.95, 1), 19.9)
        self.assertEqual(tt.r1(903 / 60), 15.1)        # whole seconds with sec mod 6 = 3 land on x.x5
        self.assertEqual(tt.r1(-903 / 60), -15.1)
        self.assertEqual(tt.r1(0.25), 0.3)
        self.assertEqual(tt.r1(-0.25), -0.3)
        self.assertEqual(tt.r1(2.04), 2.0)

    def test_r2_away_from_zero(self):
        self.assertEqual(tt.r2(1.125), 1.13)
        self.assertEqual(tt.r2(-1.125), -1.13)
        self.assertEqual(tt.r2(2.675), 2.68)           # 267.5 after scaling, unlike round(2.675, 2) = 2.67

    def test_ps_round_is_half_to_even_on_the_scaled_double(self):
        self.assertEqual(tt.ps_round(0.125, 2), 0.12)
        self.assertEqual(tt.ps_round(50.123455, 5), 50.12346)
        self.assertEqual(tt.ps_round(14.25, 1), 14.2)
        self.assertEqual('%.5f' % tt.ps_round(-100.000004, 5), '-100.00000')

    def test_pct(self):
        self.assertEqual(tt.pct(1, 0), 0.0)
        self.assertEqual(tt.pct(1, 7), 14.3)
        self.assertEqual(tt.pct(2112, 3369), 62.7)

    def test_net_fixed2_matches_dotnet_core_custom_format(self):
        # .NET formats 15 significant digits first, then rounds halves away from zero (checked in pwsh 7.6.6)
        for x, want in ((0.125, '0.13'), (1.005, '1.01'), (2.675, '2.68'), (0.285, '0.29'), (0.3, '0.30'),
                        (1.3, '1.30'), (0.19, '0.19'), (1.0, '1.00'), (0.67, '0.67')):
            self.assertEqual(tt.net_fixed2(x), want, x)

    def test_ps_num(self):
        self.assertEqual(tt.ps_num(25.0), '25')
        self.assertEqual(tt.ps_num(14.3), '14.3')
        self.assertEqual(tt.ps_num(62.7), '62.7')

    def test_num(self):
        self.assertEqual(tt.num(' 1.5 '), 1.5)
        self.assertEqual(tt.num('1e3'), 1000.0)
        for bad in (None, '', '  ', 'abc', '1_0', 'inf', 'nan', '1,5'):
            self.assertIsNone(tt.num(bad), bad)

    def test_ndiv_follows_dotnet_doubles(self):
        self.assertEqual(tt.ndiv(1.0, 4.0), 0.25)
        self.assertEqual(tt.ndiv(0.5, 0.0), math.inf)
        self.assertEqual(tt.ndiv(-0.5, 0.0), -math.inf)
        self.assertTrue(math.isnan(tt.ndiv(0.0, 0.0)))


class TextTests(unittest.TestCase):
    def test_text_key_orders_case_insensitively_then_by_spelling(self):
        self.assertEqual(sorted(['b1', 'B2', 'a1', 'A1', 'JN3S', '10', '9'], key=tt.text_key),
                         ['10', '9', 'A1', 'a1', 'b1', 'B2', 'JN3S'])

    def test_esc(self):
        self.assertEqual(tt.esc('<a href="x">&\'</a>'), '&lt;a href=&quot;x&quot;&gt;&amp;\'&lt;/a&gt;')


class MarkdownTests(unittest.TestCase):
    # expected strings are the script's own output (its Esc, Md-Inline and ConvertFrom-Md run in pwsh 7.6.6)
    def test_escaped_first_and_inline_rules(self):
        self.assertEqual(tt.markdown_to_html('<b>x</b> & "y"'), '<p>&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;</p>')
        self.assertEqual(tt.md_inline('`a*b*` **bold** __b2__ *em* _em2_ snake_case_name 2*3*4'),
                         '<code>a*b*</code> <strong>bold</strong> <strong>b2</strong> <em>em</em> <em>em2</em> '
                         'snake_case_name 2*3*4')

    def test_links_only_for_safe_schemes(self):
        self.assertEqual(tt.md_inline('[ok](https://example.com/a) [no](javascript:alert(1)) [rel](./x.html)'),
                         '<a href="https://example.com/a">ok</a> [no](javascript:alert(1)) <a href="./x.html">rel</a>')

    def test_headings_lists_paragraphs(self):
        md = '# One\n#### Four\n####### seven\ntext\nmore text\n\n- a\n* b\n1. c\n2) d\nafter\n'
        self.assertEqual(tt.markdown_to_html(md),
                         '<h3>One</h3><h6>Four</h6><p>####### seven text more text</p><ul><li>a</li><li>b</li></ul>'
                         '<ol><li>c</li><li>d</li></ol><p>after</p>')

    def test_table(self):
        # '|2||' loses both outer pipes (Trim('|') strips every one), so it is one cell
        md = '| A | B |\r\n|:--|--:|\r\n| 1 | *x* |\r\n|2||\r\nend'
        self.assertEqual(tt.markdown_to_html(md),
                         '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td><em>x</em></td>'
                         '</tr><tr><td>2</td></tr></tbody></table><p>end</p>')


def args_for(*argv):
    return tt.build_parser().parse_args(list(argv) + ['--out', 'x.html'])


class RunListTests(unittest.TestCase):
    def setUp(self):
        self.d = tmpdir()
        for f in ('a.csv', 'b.csv', 'c.csv', 'p.xml'):
            Path(self.d, f).write_text('x', encoding='utf-8')

    def manifest(self, rows, header=('Label', 'RunCsv', 'PolygonXml'), name='runs.csv'):
        path = os.path.join(self.d, name)
        with open(path, 'w', encoding='utf-8-sig', newline='') as fh:
            csv.writer(fh).writerows([list(header)] + rows)
        return path

    def test_runs_with_default_labels_and_no_polygons(self):
        labels, runs, polys = tt.resolve_runs(args_for('--runs', 'a.csv', 'b.csv', 'c.csv'), self.d)
        self.assertEqual(labels, ['Baseline', 'Run 1', 'Run 2'])
        self.assertEqual(runs, [os.path.join(self.d, f) for f in ('a.csv', 'b.csv', 'c.csv')])
        self.assertEqual(polys, ['', '', ''])

    def test_runs_with_labels_and_polygons(self):
        labels, _, polys = tt.resolve_runs(args_for('--runs', 'a.csv', 'b.csv', '--labels', 'Prod', 'Grid, v1',
                                                    '--polygons', '', 'p.xml'), self.d)
        self.assertEqual(labels, ['Prod', 'Grid, v1'])      # no comma splitting in the Python command
        self.assertEqual(polys, ['', os.path.join(self.d, 'p.xml')])

    def test_run_list_errors(self):
        cases = [(('--runs', 'a.csv'), 'Give at least two run CSVs'),
                 (('--runs', 'a.csv', 'b.csv', '--labels', 'x'), '--labels has 1 entries but there are 2 runs'),
                 (('--runs', 'a.csv', 'b.csv', '--polygons', 'p.xml'), "--polygons has 1 entries but there are 2 runs"),
                 (('--runs', 'a.csv', 'missing.csv'), 'The run CSV missing.csv was not found.')]
        for argv, msg in cases:
            with self.assertRaises(tt.TravelTuningError) as cm:
                tt.resolve_runs(args_for(*argv), self.d)
            self.assertIn(msg, str(cm.exception))

    def test_manifest_paths_resolve_against_its_folder(self):
        m = self.manifest([['Prod', 'a.csv', ''], ['', 'b.csv', 'p.xml']])
        labels, runs, polys = tt.resolve_runs(args_for('--manifest', m, '--runs', 'ignored.csv'), '/')
        self.assertEqual(labels, ['Prod', ''])              # a blank label stays blank, as in the script
        self.assertEqual(runs, [os.path.join(self.d, 'a.csv'), os.path.join(self.d, 'b.csv')])
        self.assertEqual(polys, ['', os.path.join(self.d, 'p.xml')])

    def test_manifest_with_absolute_paths(self):
        # review focus: the web app writes absolute paths into the manifest from its scratch folder
        other = tmpdir()
        m = os.path.join(other, 'm.csv')
        with open(m, 'w', encoding='utf-8', newline='') as fh:
            csv.writer(fh).writerows([['Label', 'RunCsv', 'PolygonXml'], ['A', os.path.join(self.d, 'a.csv'), ''],
                                      ['B', os.path.join(self.d, 'b.csv'), os.path.join(self.d, 'p.xml')]])
        _, runs, polys = tt.resolve_runs(args_for('--manifest', m), '/')
        self.assertEqual(runs, [os.path.join(self.d, 'a.csv'), os.path.join(self.d, 'b.csv')])
        self.assertEqual(polys, ['', os.path.join(self.d, 'p.xml')])

    def test_manifest_errors(self):
        cases = [([], ('Label', 'RunCsv', 'PolygonXml'), 'has no rows (expected columns Label,RunCsv,PolygonXml)'),
                 ([['Prod', 'a.csv']], ('Label', 'Run'), 'has no RunCsv column'),
                 ([['Prod', 'a.csv', '']], ('Label', 'RunCsv', 'PolygonXml'), 'At least two runs are needed'),
                 ([['Prod', 'a.csv', ''], ['Next', '', '']], ('Label', 'RunCsv', 'PolygonXml'),
                  'Row 2 of the manifest runs.csv has no RunCsv'),
                 ([['Prod', 'a.csv', ''], ['Next', 'b.csv', 'nope.xml']], ('Label', 'RunCsv', 'PolygonXml'),
                  'The polygon file nope.xml was not found.')]
        for rows, header, msg in cases:
            with self.assertRaises(tt.TravelTuningError) as cm:
                tt.resolve_runs(args_for('--manifest', self.manifest(rows, header)), self.d)
            self.assertIn(msg, str(cm.exception))

    def test_misplaced_baseline(self):
        self.assertIsNone(tt.misplaced_baseline(['Baseline', 'Run 1']))
        self.assertEqual(tt.misplaced_baseline(['Prod', 'Grid', 'New BASELINE', 'baseline 2']),
                         {'label': 'New BASELINE', 'at': 3, 'first': 'Prod'})

    def test_bad_options_are_argument_errors(self):
        for argv in (['--tiles', 'carto', '--out', 'x'], ['--runs', 'a', 'b']):
            with self.assertRaises(tt._ArgError):
                tt.build_parser().parse_args(argv)


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 4: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_helpers -v`
Expected: ERROR, `ModuleNotFoundError: No module named 'travel_tuning'`.

- [ ] **Step 5: Write the module's first part**

Create `travel/travel_tuning.py` with exactly this content (later tasks append to it):

```python
#!/usr/bin/env python3
"""
Travel tuning report: a baseline plan travels run against one or more tuned runs of the same dataset, with the
polygon weightings each run used, as one self-contained page.

Python reference implementation of travel/Build-TravelTuningReport.ps1 (which stays the Windows version).
Python 3.9+, standard library only.

Usage:
    python3 travel_tuning.py --manifest <csv> --out <html> [--json <path>] [options]
    python3 travel_tuning.py --runs a.csv b.csv .. [--labels ..] [--polygons '' p.xml ..] --out <html> [options]

The manifest is a CSV with the columns Label,RunCsv,PolygonXml, baseline first and final run last; relative paths
in it are resolved against the manifest's folder.

Exit codes: 0 with the page written; 1 on any failure, with "Stopped during: <stage>: <sentence>" on stderr for a
known failure, else "Stopped during: <stage>" and the error.

Warnings go to stderr as 'warning: <text>' lines and into meta.warnings. The last stdout line is 'summary: <text>'.
"""
import argparse
import csv
import datetime as dt
import decimal
import json
import math
import os
import re
import sys
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
COMMON_WARN_PCT = 60        # warn when the legs common to all runs are under this % of the smallest run
MODEL_WARN_PCT = 80         # warn when a model check finds fewer than this % of legs moved as predicted
CORRIDOR_MIN_SHARE = 0.5    # corridor table: share of a leg's straight-line path inside the group
HOTSPOT_CALLOUT_PCT = 15    # underestimate hotspot callout when one polygon holds this % or more
CHECK_ROWS = 15             # "check these" table: legs shown per run
DISTANCE_BANDS = ((0, 5, 'Under 5 km'), (5, 15, '5-15 km'), (15, 30, '15-30 km'), (30, 60, '30-60 km'),
                  (60, 100, '60-100 km'), (100, 1e9, '100+ km'))
CHART_JS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'
LEAFLET_JS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'
LEAFLET_CSS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'
LIB_FILES = ('chart.umd.min.js', 'leaflet.min.js', 'leaflet.min.css')
TILES = ('esri-gray', 'esri', 'osm', 'none')
TEXT_BENCHMARK = ("Google is the benchmark, not the truth. Google's time is without live traffic: an ideal drive with "
                  "no stops, gravel or weather. Real rural driving is usually slower, so some of an old overestimate "
                  "may have been acting as a buffer. The way to settle it is to compare against actual drive times "
                  "from technicians' en-route and on-site timestamps after go-live.")
TEXT_OVER_UNDER = ("Overestimates and underestimates cost different things. An overestimate reserves drive time that "
                   "isn't needed, so fewer jobs get booked. An underestimate costs punctuality and compounds across a "
                   "day: five trips each 5 minutes short puts a technician 25 minutes behind by the last job.")
TEXT_STAY_WRONG = ("Why individual legs can stay wrong. The travel matrix snaps every location to its nearest road "
                   "node; in sparse rural networks a location can snap to the wrong road. Every trip in or out of that "
                   "location inherits the error while nearby trips are correct, so a polygon that fixes one leg would "
                   "break the trips around it.")


class TravelTuningError(ValueError):
    """A known failure, printed after 'Stopped during: <stage>:' with no exception class name (exit 1)."""


class _ArgError(Exception):
    pass


# ---------------------------------------------------------------------------------------------------------------
#  Numbers, rounding and text helpers (inventory 9 and 10)
# ---------------------------------------------------------------------------------------------------------------
def num(s):
    """The script's Num: invariant float of the trimmed text; None when blank, not a number or not finite."""
    s = (s or '').strip()
    if not s or '_' in s:
        return None
    try:
        v = float(s)
    except ValueError:
        return None
    return v if math.isfinite(v) else None


def r_away(x, digits):
    """[Math]::Round(x, digits, AwayFromZero) as .NET does it: scale, round the scaled double, unscale."""
    p = 10.0 ** digits
    frac, whole = math.modf(x * p)
    if abs(frac) >= 0.5:
        whole += math.copysign(1.0, frac)
    return whole / p


def r1(x):
    return r_away(x, 1)


def r2(x):
    return r_away(x, 2)


def ps_round(x, digits):
    """[Math]::Round(x, digits): scale by 10^digits, round half to even, unscale."""
    p = 10.0 ** digits
    return round(x * p) / p


def pct(a, b):
    return 0.0 if b == 0 else r1(100.0 * a / b)


def net_fixed2(x):
    """x.ToString('0.00', invariant) on .NET Core: 15 significant digits first, then halves away from zero."""
    return str(decimal.Decimal('%.15g' % x).quantize(decimal.Decimal('0.01'), rounding=decimal.ROUND_HALF_UP))


def ps_num(x):
    """A double as PowerShell writes it into a string: shortest round trip, no '.0' on whole values."""
    s = repr(float(x))
    return s[:-2] if s.endswith('.0') else s


def text_key(s):
    """Pinned stand-in for Sort-Object's culture order on strings (spec 3.2 rule 3)."""
    return (s.casefold(), s)


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')


def ndiv(a, b):
    """a / b with .NET double semantics: dividing by zero gives an infinity, or NaN for 0 / 0."""
    if b != 0:
        return a / b
    if a == 0 or math.isnan(a):
        return math.nan
    return math.inf if (a > 0) == (math.copysign(1.0, b) > 0) else -math.inf


# ---------------------------------------------------------------------------------------------------------------
#  Consultant notes: the script's Markdown subset (inventory 8)
# ---------------------------------------------------------------------------------------------------------------
_MD_CODE = re.compile(r'`([^`]+)`')
_MD_LINK = re.compile(r'\[([^\]]+)\]\(((?:https?://|mailto:|#|\./|/)[^)\s]*)\)')
_MD_BOLD1 = re.compile(r'\*\*([^*]+)\*\*')
_MD_BOLD2 = re.compile(r'__([^_]+)__')
_MD_EM1 = re.compile(r'(?<![\w*])\*([^*\s][^*]*)\*(?!\w)')
_MD_EM2 = re.compile(r'(?<![\w])_([^_\s][^_]*)_(?![\w])')
_MD_ROW = re.compile(r'^\|.*\|$')
_MD_SEP = re.compile(r'^\|[\s:|-]+\|$')
_MD_HEAD = re.compile(r'^(#{1,6})\s+(.*)$')
_MD_UL = re.compile(r'^[-*+]\s+(.*)$')
_MD_OL = re.compile(r'^\d+[.)]\s+(.*)$')
_MD_LINES = re.compile(r'\r?\n')


def md_inline(s):
    s = _MD_CODE.sub(r'<code>\1</code>', s)
    s = _MD_LINK.sub(r'<a href="\2">\1</a>', s)
    s = _MD_BOLD1.sub(r'<strong>\1</strong>', s)
    s = _MD_BOLD2.sub(r'<strong>\1</strong>', s)
    s = _MD_EM1.sub(r'<em>\1</em>', s)
    return _MD_EM2.sub(r'<em>\1</em>', s)


def _md_cells(row):
    return [md_inline(c.strip()) for c in row.strip().strip('|').split('|')]


def markdown_to_html(md):
    """ConvertFrom-Md: everything HTML-escaped first, then tables, headings, lists and paragraphs."""
    lines = _MD_LINES.split(esc(md))
    out, para, lst, i = [], [], None, 0

    def flush():
        if para:
            out.append('<p>' + md_inline(' '.join(para)) + '</p>')
            para.clear()

    while i < len(lines):
        t = lines[i].strip()
        if _MD_ROW.match(t) and i + 1 < len(lines) and _MD_SEP.match(lines[i + 1].strip()):
            flush()
            if lst:
                out.append('</%s>' % lst)
                lst = None
            out.append('<table><thead><tr>' + ''.join('<th>%s</th>' % c for c in _md_cells(t)) + '</tr></thead><tbody>')
            i += 2
            while i < len(lines) and _MD_ROW.match(lines[i].strip()):
                out.append('<tr>' + ''.join('<td>%s</td>' % c for c in _md_cells(lines[i])) + '</tr>')
                i += 1
            out.append('</tbody></table>')
            continue
        m = _MD_HEAD.match(t)
        if m:
            flush()
            if lst:
                out.append('</%s>' % lst)
                lst = None
            lv = min(6, len(m.group(1)) + 2)
            out.append('<h%d>%s</h%d>' % (lv, md_inline(m.group(2)), lv))
            i += 1
            continue
        kind = item = None
        m = _MD_UL.match(t)
        if m:
            kind, item = 'ul', m.group(1)
        else:
            m = _MD_OL.match(t)
            if m:
                kind, item = 'ol', m.group(1)
        if kind:
            flush()
            if lst != kind:
                if lst:
                    out.append('</%s>' % lst)
                out.append('<%s>' % kind)
                lst = kind
            out.append('<li>' + md_inline(item) + '</li>')
            i += 1
            continue
        if t == '':
            flush()
            if lst:
                out.append('</%s>' % lst)
                lst = None
            i += 1
            continue
        if lst:
            out.append('</%s>' % lst)
            lst = None
        para.append(t)
        i += 1
    flush()
    if lst:
        out.append('</%s>' % lst)
    return ''.join(out)


# ---------------------------------------------------------------------------------------------------------------
#  Arguments and the manifest (inventory 1)
# ---------------------------------------------------------------------------------------------------------------
_GENERATED = re.compile(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$')


class _Parser(argparse.ArgumentParser):
    def error(self, message):          # argparse would exit 2; every failure here is exit 1
        raise _ArgError(message)


def build_parser():
    ap = _Parser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--manifest', help='CSV with Label,RunCsv,PolygonXml (replaces --runs, --labels, --polygons)')
    ap.add_argument('--runs', nargs='+', help='run CSVs, baseline first, final last')
    ap.add_argument('--labels', nargs='+', help='one label per run (default Baseline, Run 1, Run 2, ...)')
    ap.add_argument('--polygons', nargs='+', help="one polygon XML per run, '' for a run without polygons")
    ap.add_argument('--out', required=True, help='the page to write')
    ap.add_argument('--json', help='also write the report data as JSON here')
    ap.add_argument('--title', default='Travel Tuning Report')
    ap.add_argument('--client', default='')
    ap.add_argument('--dataset', default='')
    ap.add_argument('--region-label', default='GB')
    ap.add_argument('--review-minutes', type=float, default=20.0)
    ap.add_argument('--small-minutes', type=float, default=5.0)
    ap.add_argument('--model-tol-min', type=float, default=1.0)
    ap.add_argument('--model-tol-pct', type=float, default=5.0)
    ap.add_argument('--long-leg-km', type=float, default=300.0)
    ap.add_argument('--tiles', choices=TILES, default='esri-gray')
    ap.add_argument('--offline', action='store_true', help='inline Chart.js and Leaflet from lib/ next to this script')
    ap.add_argument('--review-csv', help="the final run's polygon review CSV")
    ap.add_argument('--notes', help='Markdown notes for the Consultant notes section')
    ap.add_argument('--generated', help='"YYYY-MM-DD HH:MM" shown as the generated time (default: now, local time)')
    ap.add_argument('--template', default=os.path.join(HERE, 'travel_tuning_template.html'))
    ap.add_argument('--lib-dir', default=os.path.join(HERE, 'lib'))
    return ap


def resolve_in(path, base, what):
    """The script's Resolve-In: blank gives ''; relative paths are joined to base; a missing file is an error."""
    if not path:
        return ''
    full = path if os.path.isabs(path) else os.path.join(base, path)
    if not os.path.isfile(full):
        raise TravelTuningError('The %s %s was not found.' % (what, os.path.basename(path)))
    return os.path.abspath(full)


def read_manifest(path):
    """[(label, run_csv, polygon_xml or '')] from a Label,RunCsv,PolygonXml CSV, paths against its folder."""
    base = os.path.dirname(path)
    name = os.path.basename(path)
    with open(path, encoding='utf-8-sig', errors='replace', newline='') as fh:
        rows = [r for r in csv.DictReader(fh)]
    if not rows:
        raise TravelTuningError('The manifest %s has no rows (expected columns Label,RunCsv,PolygonXml).' % name)
    if 'RunCsv' not in rows[0]:
        raise TravelTuningError('The manifest %s has no RunCsv column (expected columns Label,RunCsv,PolygonXml).'
                                % name)
    out = []
    for n, r in enumerate(rows, 1):
        run = (r.get('RunCsv') or '').strip()
        if not run:
            raise TravelTuningError('Row %d of the manifest %s has no RunCsv.' % (n, name))
        out.append((r.get('Label') or '', resolve_in(run, base, 'run CSV'),
                    resolve_in((r.get('PolygonXml') or '').strip(), base, 'polygon file')))
    return out


def resolve_runs(args, cwd):
    """(labels, run paths, polygon paths) from --manifest or --runs/--labels/--polygons, checked as the script does."""
    if args.manifest:
        rows = read_manifest(resolve_in(args.manifest, cwd, 'manifest'))
        labels = [r[0] for r in rows]
        runs = [r[1] for r in rows]
        polys = [r[2] for r in rows]
    else:
        if not args.runs or len(args.runs) < 2:
            raise TravelTuningError('Give at least two run CSVs with --runs (baseline first, final last), '
                                    'or a --manifest.')
        runs = [resolve_in(r, cwd, 'run CSV') for r in args.runs]
        labels = list(args.labels) if args.labels else []
        polys = [resolve_in(p, cwd, 'polygon file') for p in args.polygons] if args.polygons else []
    nr = len(runs)
    if nr < 2:
        raise TravelTuningError('At least two runs are needed (baseline and one tuned run).')
    if not labels:
        labels = ['Baseline' if i == 0 else 'Run %d' % i for i in range(nr)]
    if len(labels) != nr:
        raise TravelTuningError('--labels has %d entries but there are %d runs. Give one label per run.'
                                % (len(labels), nr))
    if not polys:
        polys = [''] * nr
    if len(polys) != nr:
        raise TravelTuningError("--polygons has %d entries but there are %d runs. Use '' for runs without polygons."
                                % (len(polys), nr))
    return labels, runs, polys


def misplaced_baseline(labels):
    """{label, at, first} for the first run after the first whose label says baseline, else None."""
    for i in range(1, len(labels)):
        if re.search('baseline', labels[i], re.I):
            return {'label': labels[i], 'at': i + 1, 'first': labels[0]}
    return None
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `python3 -m unittest tests.travel_tuning.test_helpers -v`
Expected: 22 tests, OK.

- [ ] **Step 7: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/__init__.py tests/travel_tuning/tt_fixtures.py tests/travel_tuning/test_helpers.py
git status --short
git commit -m "Travel tuning (Python): helpers, .NET rounding, Markdown subset and the run list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 2: Run CSVs, the synthetic set and the pwsh capture

**Files:**
- Modify: `travel/travel_tuning.py` (append)
- Replace: `tests/travel_tuning/tt_fixtures.py` (full version)
- Create: `tests/travel_tuning/fixtures/synthetic_pwsh.json` (captured, Step 2)
- Test: `tests/travel_tuning/test_runs.py`

**Interfaces:**
- Consumes: `num`, `r1`, `ps_round`, `TravelTuningError` (Task 1).
- Produces: `NEED_COLS`; `read_run(path, label, threshold) -> run dict` (leg and run keys in "File structure" above). In `tt_fixtures`: `write_synthetic(dir) -> {name: path}` (names `base`, `mid`, `final`, `polys_mid`, `polys_final`, `review`, `notes`, `manifest`), `write_csv`, `NEW_COLS`, `OLD_COLS`, `polygon_xml(polys, weights)`, `rect(s, n, west, east)`, `LABELS`, `SYNTHETIC_ARGS`, `synthetic_argv(paths) -> [str]`, `page_json(html) -> dict`, `json_diff(expected, ours, path='', ignore=()) -> [str]`, `OURS_ONLY`, `null_km_paths(expected, ours) -> [str]`, `capture_pwsh() -> dict`, `expected() -> dict`, `SCRIPT`, `EXPECTED`.

The synthetic set (no customer data) is three runs labelled `Baseline`, `Grid A`, `Grid B (baseline v2)` (the last label trips the misplaced-baseline check): the baseline in the old 30-column layout (no `Google Duration (sec)`, no GPS fix columns) with one exclusion of each kind; two runs in the current 41-column layout with GPS fix rows (`Yes` and `yes`), merged legs, a repeated key (two legs in the baseline, three in the final run), a rounding half (903 s), a flagged final leg with no distance at all, and a long leg. Two polygon files: the final one has overlapping rectangles (G01 and G02), corridor groups `K1-01` and `k1-02` (one group, two spellings), `Z9` (no dash, weighting 0.125), `A5-01` (no trips, weighting 1.005, route from the review CSV), a triangle `T1`, a two-vertex polygon `B2` (skipped), `N1` without a weighting, and a repeated weighting row for G01 (last wins); the middle one has G01 at weighting 0 (so predicting from it divides by zero).

- [ ] **Step 1: Replace the fixtures module and write the failing tests**

`tests/travel_tuning/tt_fixtures.py`, full content:

```python
"""Synthetic inputs for the travel tuning tests (no customer data), and the helpers the tests share.

    python3 tests/travel_tuning/tt_fixtures.py --capture   runs the PowerShell script (pwsh) on the synthetic set and
                                                           writes tests/travel_tuning/fixtures/synthetic_pwsh.json
    python3 tests/travel_tuning/tt_fixtures.py <dir>       writes the synthetic set into <dir> to look at
"""
import atexit
import contextlib
import csv
import io
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'travel'))
import travel_tuning as tt  # noqa: E402

SCRIPT = ROOT / 'travel' / 'Build-TravelTuningReport.ps1'
EXPECTED = Path(__file__).resolve().parent / 'fixtures' / 'synthetic_pwsh.json'
_ROOT = []

BASE_COLS = ['Resource ID', 'Resource Name', 'Direction', 'Previous Activity ID', 'GB (Previous Activity)',
             'Previous Activity Type', 'Previous Activity Description', 'Activity ID (Destination)',
             'GB (Destination Activity)', 'Destination Activity Type', 'Destination Activity Description',
             'Start Location Latitude', 'Start Location Longitude', 'End Location Latitude', 'End Location Longitude',
             'Distance (km)', 'Expected Travel Time (min)', 'Expected Travel Time (sec)', 'Merged Fragments']
NEW_COLS = BASE_COLS + ['Merge Type', 'GPS Fix', 'GPS Fix Time', 'Pre-Fix Distance (km)', 'Pre-Fix Travel (min)',
                        'Pre-Shift Distance (km)', 'Pre-Shift Travel (min)', 'Google Maps Directions Link', 'Plan ID',
                        'Call ID', 'Siebel Order Number', 'Google Duration (min)', 'Google Duration (sec)',
                        'Google Distance (km)', 'Google Source', 'Ratio (Google / PSO)', '% Divergence',
                        'Abs Divergence (min)', 'Difference', 'Flag', 'Estimated Weighting Adjustment',
                        'Validation Error']
# the older 30-column layout: no Google Duration (sec), no Google Source, no GPS fix or pre-fix columns
OLD_COLS = BASE_COLS + ['Google Maps Directions Link', 'Plan ID', 'Google Duration (min)', 'Google Distance (km)',
                        'Ratio', '% Divergence', 'Abs Divergence (min)', 'Difference', 'Flag',
                        'Estimated Weighting Adjustment', 'Validation Error']

# trip: (name, start lat, start lon, end lat, end lon, Google seconds, Google km)
TRIPS = {
    'g01': (50.02, -100.98, 50.08, -100.82, 900, 15.0),         # inside G01
    'ovl': (50.06, -100.95, 50.09, -100.75, 1203, 18.2),        # crosses the G01 / G02 overlap
    'k1': (50.22, -100.58, 50.27, -100.32, 1500, 22.0),         # along corridor group K1 (K1-01 and k1-02)
    'z9': (50.41, -100.19, 50.44, -100.01, 840, 14.0),          # along corridor Z9
    'out': (50.70, -100.50, 50.75, -100.40, 600, 9.5),          # outside every polygon
    'long': (50.00, -101.00, 53.00, -97.00, 16200, 450.0),      # a long leg, flagged in both runs
    'rep': (50.50, -100.50, 50.52, -100.48, 300, 3.0),          # repeated key
    'tri': (50.31, -100.94, 50.32, -100.90, 420, 5.0),          # inside the triangle's bounding rectangle
    'n1': (50.46, -100.69, 50.49, -100.61, 480, 7.5),           # polygon N1 (no weighting)
    'half': (50.80, -100.20, 50.81, -100.10, 903, 12.0),        # 903 s = 15.05 min, a rounding half
}
# leg: (trip, PSO seconds, technician, GB from, GB to, extras)
BASE_LEGS = [
    ('g01', 2400, 'Tech A', 'AA1', 'BB2', {}), ('ovl', 2500, 'Tech B', 'AA1', 'aa1', {'Merged Fragments': '2'}),
    ('k1', 2700, 'Tech C', 'BB2', '', {}), ('z9', 900, 'Tech A', 'CC3', 'CC3', {}),
    ('out', 630, 'Tech D', '', 'DD4', {'Merged Fragments': '3'}), ('long', 18000, 'Tech E', 'EE5', 'FF6', {}),
    ('rep', 400, 'Tech A', 'AA1', 'AA1', {}), ('rep', 2100, 'Tech B', 'AA1', 'AA1', {'Merged Fragments': '2'}),
    ('tri', 450, 'Tech C', 'bb2', 'BB2', {}), ('n1', 500, 'Tech D', 'GG7', '', {}),
    ('half', 2103, 'Tech E', 'HH8', 'hh8', {'Merged Fragments': '2'}),
    ('g01', 0, 'Tech F', 'AA1', 'BB2', {}),                                                 # no PSO time
    ('out', 700, 'Tech F', 'DD4', 'DD4', {'Validation Error': 'Missing/invalid coordinates -- skipped'}),
    ('z9', 900, 'Tech F', 'CC3', 'CC3', {'Google Duration (min)': ''}),                     # no Google time
    ('n1', 500, 'Tech F', 'GG7', 'GG7', {'End Location Latitude': ''}),                     # no coordinates
]
MID_LEGS = [
    ('g01', 1500, 'Tech A', 'AA1', 'BB2', {}), ('ovl', 1700, 'Tech B', 'AA1', 'aa1', {'GPS Fix': 'Yes',
     'GPS Fix Time': '2026-10-05T14:03:00Z', 'Pre-Fix Distance (km)': '2.5'}),
    ('k1', 1900, 'Tech C', 'BB2', '', {}), ('z9', 870, 'Tech A', 'CC3', 'CC3', {}),
    ('out', 610, 'Tech D', '', 'DD4', {}), ('long', 17800, 'Tech E', 'EE5', 'FF6', {}),
    ('rep', 330, 'Tech A', 'AA1', 'AA1', {}), ('rep', 1500, 'Tech B', 'AA1', 'AA1', {}),
    ('tri', 430, 'Tech C', 'bb2', 'BB2', {}), ('n1', 490, 'Tech D', 'GG7', '', {}),
    ('half', 963, 'Tech E', 'HH8', 'hh8', {}),
]
FINAL_LEGS = [
    ('g01', 960, 'Tech A', 'AA1', 'BB2', {}),
    ('ovl', 1150, 'Tech B', 'AA1', 'aa1', {'GPS Fix': 'yes', 'GPS Fix Time': '2026-10-05T14:03:00Z',
                                           'Pre-Fix Distance (km)': '2.5', 'Merged Fragments': '2'}),
    ('k1', 600, 'Tech G', 'BB2', '', {}), ('z9', 400, 'Tech A', 'CC3', 'CC3', {}),
    ('out', 2000, 'Tech D', '', 'DD4', {'Google Distance (km)': '', 'Distance (km)': ''}),  # flagged, no distance
    ('long', 17700, 'Tech E', 'EE5', 'FF6', {}),
    ('rep', 2000, 'Tech A', 'AA1', 'AA1', {'Merged Fragments': '2'}), ('rep', 310, 'Tech B', 'AA1', 'AA1', {}),
    ('rep', 305, 'Tech C', 'AA1', 'AA1', {'Merged Fragments': '2'}),
    ('tri', 140, 'Tech C', 'bb2', 'BB2', {'Merged Fragments': '2'}), ('n1', 480, 'Tech D', 'GG7', '', {}),
    ('half', 909, 'Tech E', 'HH8', 'hh8', {'Merged Fragments': '2'}),
]


def _row(cols, leg, n):
    trip, psec, tech, gb_from, gb_to, extra = leg
    la1, lo1, la2, lo2, gsec, gkm = TRIPS[trip]
    v = {'Resource ID': 'R%d' % (ord(tech[-1]) - 64), 'Resource Name': tech, 'Direction': 'Activity -> Activity',
         'Previous Activity ID': 'P%d' % n, 'GB (Previous Activity)': gb_from, 'Activity ID (Destination)': 'A%d' % n,
         'GB (Destination Activity)': gb_to, 'Start Location Latitude': repr(la1),
         'Start Location Longitude': repr(lo1), 'End Location Latitude': repr(la2), 'End Location Longitude': repr(lo2),
         'Distance (km)': repr(gkm), 'Expected Travel Time (min)': '%.1f' % (psec / 60),
         'Expected Travel Time (sec)': str(psec), 'Merged Fragments': '1', 'Plan ID': 'PL1',
         'Google Duration (min)': '%.1f' % (gsec / 60), 'Google Duration (sec)': str(gsec),
         'Google Distance (km)': repr(gkm), 'Google Source': 'API', 'Validation Error': ''}
    v.update(extra)
    return [v.get(c, '') for c in cols]


def write_csv(path, cols, legs):
    with open(path, 'w', encoding='utf-8-sig', newline='') as fh:
        w = csv.writer(fh, quoting=csv.QUOTE_ALL)
        w.writerow(cols)
        for n, leg in enumerate(legs, 1):
            w.writerow(_row(cols, leg, n))


def rect(s, n, west, east):
    return [(n, west), (n, east), (s, east), (s, west)]


def polygon_xml(polys, weights):
    """polys: [(id, description or None, [(lat, lon), ...])]; weights: [(polygon_id, weighting)] in file order."""
    out = ['<?xml version="1.0" encoding="utf-8"?>',
           '<DsSystemData xmlns="http://360scheduling.com/Schema/DsSystemData.xsd">']
    out.append('  <Application_Data><id>x1</id><data_string>[]</data_string></Application_Data>')
    for pid, desc, pts in polys:
        out.append('  <Polygon>')
        out.append('    <id>%s</id>' % pid)
        if desc is not None:
            out.append('    <description>%s</description>' % desc)
        out.append('    <polygon_type>TTW</polygon_type>')
        out += ['    <latitude_%d>%r</latitude_%d>' % (i, la, i) for i, (la, _) in enumerate(pts, 1)]
        out += ['    <longitude_%d>%r</longitude_%d>' % (i, lo, i) for i, (_, lo) in enumerate(pts, 1)]
        out.append('  </Polygon>')
    for pid, w in weights:
        out.append('  <Travel_Time_Polygon><travel_time_profile_id>Default</travel_time_profile_id>'
                   '<polygon_id>%s</polygon_id><weighting>%s</weighting></Travel_Time_Polygon>' % (pid, w))
    out.append('</DsSystemData>')
    return '\n'.join(out) + '\n'


FINAL_POLYS = [
    ('G01', 'Grid w0.80 | 12 legs | AA1', rect(50.00, 50.10, -101.00, -100.80)),
    ('G02', 'Grid w1.25 | 3 legs | BB2', rect(50.05, 50.15, -100.90, -100.70)),     # overlaps G01
    ('K1-01', 'Corridor w0.67 | AA1 to BB2', rect(50.20, 50.25, -100.60, -100.40)),
    ('k1-02', 'corridor w0.50 | east end', rect(50.25, 50.30, -100.50, -100.30)),   # same group as K1-01
    ('Z9', 'Corridor | ZZ run', rect(50.40, 50.45, -100.20, -100.00)),
    ('A5-01', 'Corridor w1.00', rect(50.60, 50.65, -99.00, -98.90)),                 # no trips, route from review
    ('T1', 'Grid w0.90 | tri', [(50.30, -100.95), (50.35, -100.95), (50.30, -100.85)]),   # not a rectangle
    ('B2', 'Grid w2.00 | too few corners', [(50.0, -100.0), (50.1, -100.1)]),       # skipped (2 vertices)
    ('N1', '123 | numeric first token', rect(50.45, 50.50, -100.70, -100.60)),       # no weighting: 1.0
]
FINAL_WEIGHTS = [('G01', '0.75'), ('G01', '0.8'), ('G02', '1.25'), ('K1-01', '0.67'), ('k1-02', '0.5'),
                 ('Z9', '0.125'), ('A5-01', '1.005'), ('T1', '0.9'), ('B2', '2')]
MID_POLYS = [
    ('G01', 'Grid w0 | zero weighting', rect(50.00, 50.10, -101.00, -100.80)),
    ('G02', 'Grid w1.00 | 3 legs', rect(50.05, 50.15, -100.90, -100.70)),
    ('K1-01', 'Corridor w0.80 | AA1 to BB2', rect(50.20, 50.25, -100.60, -100.40)),
]
MID_WEIGHTS = [('G01', '0'), ('G02', '1.0'), ('K1-01', '0.8')]
REVIEW = [['Polygon', 'Weighting', 'Effect', 'GBs / Route', 'Lat South', 'Lat North', 'Lon West', 'Lon East', 'Note'],
          ['A5-01', '1.005', 'none', '', '', '', '', '', 'Spare corridor, kept for later'],
          ['K1-01', '0.67', 'faster', 'AA1 highway', '', '', '', '', 'Highway section'],
          ['G01', '0.8', 'faster', '', '', '', '', '', 'Town grid']]
NOTES = '''# Round 2 notes

The *grid* cut **phantom travel** in town; see [the brief](https://example.com/brief) and `G01`.

- first point
- second point with __bold__
1. numbered
2) also numbered

| Polygon | Change |
|:--|--:|
| G01 | 0.80 |
| K1-01 | _0.67_ |
<script>alert(1)</script> & "quotes"
'''
LABELS = ['Baseline', 'Grid A', 'Grid B (baseline v2)']   # a misplaced baseline label on the final run


def write_synthetic(d):
    """The synthetic set in folder d: three run CSVs (the first in the old layout), two polygon files, a review CSV,
    notes and a manifest. Returns the paths by name."""
    d = Path(d)
    d.mkdir(parents=True, exist_ok=True)
    write_csv(d / 'base.csv', OLD_COLS, BASE_LEGS)
    write_csv(d / 'mid.csv', NEW_COLS, MID_LEGS)
    write_csv(d / 'final.csv', NEW_COLS, FINAL_LEGS)
    (d / 'polys_mid.xml').write_text(polygon_xml(MID_POLYS, MID_WEIGHTS), encoding='utf-8')
    (d / 'polys_final.xml').write_text(polygon_xml(FINAL_POLYS, FINAL_WEIGHTS), encoding='utf-8')
    with open(d / 'review.csv', 'w', encoding='utf-8-sig', newline='') as fh:
        csv.writer(fh).writerows(REVIEW)
    (d / 'notes.md').write_text(NOTES, encoding='utf-8')
    with open(d / 'manifest.csv', 'w', encoding='utf-8', newline='') as fh:
        csv.writer(fh).writerows([['Label', 'RunCsv', 'PolygonXml'], [LABELS[0], 'base.csv', ''],
                                  [LABELS[1], 'mid.csv', 'polys_mid.xml'], [LABELS[2], 'final.csv', 'polys_final.xml']])
    return {k: str(d / f) for k, f in (('base', 'base.csv'), ('mid', 'mid.csv'), ('final', 'final.csv'),
                                        ('polys_mid', 'polys_mid.xml'), ('polys_final', 'polys_final.xml'),
                                        ('review', 'review.csv'), ('notes', 'notes.md'), ('manifest', 'manifest.csv'))}


SYNTHETIC_ARGS = ['--title', 'Synthetic <tuning>', '--client', 'Example Co', '--dataset', 'TEST',
                  '--generated', '2026-10-18 09:00']


def tmpdir() -> str:
    if not _ROOT:
        _ROOT.append(tempfile.mkdtemp(prefix='travel-tuning-tests-'))
        atexit.register(shutil.rmtree, _ROOT[0], True)
    return tempfile.mkdtemp(dir=_ROOT[0])


def run_main(argv):
    out, err = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        code = tt.main(argv)
    return code, out.getvalue(), err.getvalue()


def page_json(html_text):
    """The data block of a page written by the script (</ escaped as <\\/) or by travel_tuning.py (< as \\u003c)."""
    start = html_text.index('<script type="application/json" id="data">') + len('<script type="application/json" id="data">')
    end = html_text.index('</script>', start)
    return json.loads(html_text[start:end].replace('<\\/', '</'))


def _kind(v):
    if isinstance(v, bool):
        return 'bool'
    if isinstance(v, (int, float)):
        return 'number'
    if v is None:
        return 'null'
    return type(v).__name__


def json_diff(expected, ours, path='', ignore=(), out=None):
    """Value-by-value differences (numbers compared as values, bools never equal to numbers). Paths in ignore are
    skipped; keys only in ours are reported as 'extra' unless ignored."""
    out = [] if out is None else out
    if path in ignore:
        return out
    ke, ko = _kind(expected), _kind(ours)
    if ke != ko:
        out.append('%s: %r (script) vs %r (ours)' % (path, expected, ours))
    elif ke == 'dict':
        for k in expected:
            if k not in ours:
                out.append('%s.%s: missing' % (path, k))
            else:
                json_diff(expected[k], ours[k], '%s.%s' % (path, k), ignore, out)
        for k in ours:
            if k not in expected and '%s.%s' % (path, k) not in ignore:
                out.append('%s.%s: extra' % (path, k))
    elif ke == 'list':
        if len(expected) != len(ours):
            out.append('%s: %d items (script) vs %d (ours)' % (path, len(expected), len(ours)))
        for i, (e, o) in enumerate(zip(expected, ours)):
            json_diff(e, o, '%s[%d]' % (path, i), ignore, out)
    elif expected != ours:
        out.append('%s: %r (script) vs %r (ours)' % (path, expected, ours))
    return out


# meta.warnings and meta.page_warnings are ours alone (deviation 2); meta.generated is pinned separately
OURS_ONLY = ('.meta.warnings', '.meta.page_warnings')


def null_km_paths(expected, ours):
    """Deviation 1: map legs where the script wrote km 0.0 and we write null. Returns their paths, to ignore."""
    paths = []
    for k in ('base', 'final'):
        for i, (e, o) in enumerate(zip(expected['flagged'][k], ours['flagged'][k])):
            if o[9] is None and e[9] == 0.0:
                paths.append('.flagged.%s[%d][9]' % (k, i))
    return paths


def synthetic_argv(paths):
    """Our command line for the synthetic set: the same inputs and options as the pwsh capture."""
    return ['--manifest', paths['manifest'], '--review-csv', paths['review'], '--notes', paths['notes']] + SYNTHETIC_ARGS


def capture_pwsh():
    """The script's report data for the synthetic set (pwsh 7), with meta.generated pinned to SYNTHETIC_ARGS'."""
    d = tmpdir()
    write_synthetic(d)
    page = Path(d, 'pwsh.html')
    r = subprocess.run(['pwsh', '-NoProfile', '-File', str(SCRIPT), '-Manifest', 'manifest.csv',
                        '-ReviewCsv', 'review.csv', '-NotesFile', 'notes.md', '-Title', 'Synthetic <tuning>',
                        '-ClientName', 'Example Co', '-DatasetName', 'TEST', '-OutputPath', str(page)],
                       cwd=d, capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError('pwsh failed:\n' + r.stdout + r.stderr)
    data = page_json(page.read_text(encoding='utf-8'))
    data['meta']['generated'] = '2026-10-18 09:00'
    return data


def expected():
    return json.loads(EXPECTED.read_text(encoding='utf-8'))


if __name__ == '__main__':
    if sys.argv[1:] == ['--capture']:
        EXPECTED.parent.mkdir(exist_ok=True)
        EXPECTED.write_text(json.dumps(capture_pwsh(), indent=1, ensure_ascii=False) + '\n', encoding='utf-8')
        print('wrote %s' % EXPECTED)
    else:
        print(json.dumps(write_synthetic(sys.argv[1]), indent=1))
```

`tests/travel_tuning/test_runs.py`:

```python
"""Run CSVs: old and new layouts, exclusions, the 5 dp key and the n-th occurrence index (inventory 2 and 3)."""
import csv
import os
import shutil
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir, write_synthetic, NEW_COLS, capture_pwsh, expected, json_diff  # noqa: E402


class SyntheticRunTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.paths = write_synthetic(tmpdir())
        cls.base = tt.read_run(cls.paths['base'], 'Baseline', 20.0)
        cls.final = tt.read_run(cls.paths['final'], 'Final', 20.0)

    def test_old_layout_counts_and_exclusions(self):
        b = self.base
        self.assertEqual((b['rows'], len(b['legs']), b['excluded']), (15, 11, 4))
        self.assertEqual((b['ex_google'], b['ex_pso'], b['ex_validation']), (1, 2, 1))   # no coordinates counts as PSO
        self.assertEqual((b['flagged'], b['file'], b['has_fix_cols']), (5, 'base.csv', False))

    def test_old_layout_google_seconds_come_from_rounded_minutes(self):
        ovl = self.base['legs'][1]                  # Google 1,203 s, written as 20.1 min in the old layout
        self.assertEqual(ovl['g'], 20.1 * 60.0 / 60.0)
        self.assertEqual(ovl['merged'], 2)
        self.assertEqual((ovl['gps_fix'], ovl['fix_time'], ovl['prefix_km']), (False, '', None))

    def test_new_layout_fields(self):
        f = self.final
        self.assertEqual((f['rows'], len(f['legs']), f['excluded'], f['flagged'], f['has_fix_cols']),
                         (12, 12, 0, 3, True))
        ovl = f['legs'][1]
        self.assertEqual(ovl['g'], 1203 / 60.0)
        self.assertEqual((ovl['gps_fix'], ovl['fix_time'], ovl['prefix_km']), (True, '2026-10-05T14:03:00Z', 2.5))
        self.assertIsNone(f['legs'][4]['km'])       # no Google distance and no PSO distance

    def test_leg_values(self):
        g01 = self.base['legs'][0]
        self.assertEqual(g01['key'], '50.02000|-100.98000|50.08000|-100.82000')
        self.assertEqual((g01['p'], g01['g'], g01['d'], g01['ad']), (40.0, 15.0, 25.0, 25.0))
        self.assertEqual((g01['res'], g01['res_name'], g01['gb_from'], g01['gb_to'], g01['row']),
                         ('R1', 'Tech A', 'AA1', 'BB2', 1))
        self.assertEqual(g01['km'], 15.0)

    def test_nth_counts_repeats_of_a_key_in_file_order(self):
        reps = [leg for leg in self.final['legs'] if leg['key'] == '50.50000|-100.50000|50.52000|-100.48000']
        self.assertEqual([leg['nth'] for leg in reps], [0, 1, 2])
        self.assertEqual([leg['res_name'] for leg in reps], ['Tech A', 'Tech B', 'Tech C'])

    def test_totals(self):
        b = self.base
        self.assertEqual(round(b['over']), 151)
        self.assertEqual(b['under'], 0.0)
        self.assertEqual(b['total'], b['over'] + b['under'])


class EdgeTests(unittest.TestCase):
    def write(self, rows, cols=NEW_COLS):
        path = os.path.join(tmpdir(), 'run.csv')
        with open(path, 'w', encoding='utf-8-sig', newline='') as fh:
            csv.writer(fh, quoting=csv.QUOTE_ALL).writerows([cols] + rows)
        return path

    def row(self, **v):
        base = {'Start Location Latitude': '50', 'Start Location Longitude': '-100', 'End Location Latitude': '50.1',
                'End Location Longitude': '-100.1', 'Expected Travel Time (sec)': '600',
                'Google Duration (min)': '9.0', 'Google Duration (sec)': '540'}
        base.update(v)
        return [base.get(c, '') for c in NEW_COLS]

    def test_errors(self):
        cases = [(self.write([]), 'Prod (run.csv) has no rows.'),
                 (self.write([['1']], cols=['Resource ID']), "Prod (run.csv) has no 'Start Location Latitude' column. "
                                                              "Is it a plan travels CSV?"),
                 (self.write([['1'] * 5], cols=NEW_COLS[:19][11:15] + ['Expected Travel Time (sec)']),
                  'Prod (run.csv) has no Google columns. Run plan travels again for this run with the Google check on.')]
        for path, msg in cases:
            with self.assertRaises(tt.TravelTuningError) as cm:
                tt.read_run(path, 'Prod', 20.0)
            self.assertEqual(str(cm.exception), msg)

    def test_merged_fragments_round_half_to_even_and_blank_is_one(self):
        run = tt.read_run(self.write([self.row(**{'Merged Fragments': '2.5'}), self.row(**{'Merged Fragments': '3.5'}),
                                      self.row(**{'Merged Fragments': ''}), self.row(**{'Merged Fragments': 'x'})]),
                          'R', 20.0)
        self.assertEqual([leg['merged'] for leg in run['legs']], [2, 4, 1, 1])

    def test_google_zero_is_kept_and_google_seconds_win(self):
        # review focus: plan_travels.py writes same-location legs with Google 0 s and a blank minutes column; the
        # script keeps them on the Google value, so a long PSO time there is flagged
        run = tt.read_run(self.write([self.row(**{'Google Duration (sec)': '0', 'Google Duration (min)': '',
                                                  'Expected Travel Time (sec)': '1500'}),
                                      self.row(**{'Google Duration (sec)': '', 'Google Duration (min)': '9.5'})]),
                          'R', 20.0)
        self.assertEqual([leg['g'] for leg in run['legs']], [0.0, 9.5])
        self.assertEqual(run['flagged'], 1)

    def test_a_csv_that_is_not_utf8_still_reads(self):
        # review focus: a Windows-1252 export: bad bytes become U+FFFD (as Import-Csv -Encoding UTF8 does)
        path = self.write([self.row(**{'Resource Name': 'Ren\u00e9'})])
        raw = Path(path).read_bytes().replace('Ren\u00e9'.encode('utf-8'), b'Ren\xe9')
        Path(path).write_bytes(raw)
        run = tt.read_run(path, 'R', 20.0)
        self.assertEqual(run['legs'][0]['res_name'], 'Ren\ufffd')

    def test_flag_uses_the_rounded_difference(self):
        # PSO 1,797 s = 29.95 min, Google 10 min: |d| = 19.95, which R1 makes 20.0, so flagged at 20
        run = tt.read_run(self.write([self.row(**{'Expected Travel Time (sec)': '1797',
                                                  'Google Duration (sec)': '600'})]), 'R', 20.0)
        self.assertEqual((run['legs'][0]['ad'], run['flagged']), (20.0, 1))

    def test_ragged_rows_and_blank_lines(self):
        path = os.path.join(tmpdir(), 'ragged.csv')
        Path(path).write_text(','.join(NEW_COLS[:19] + ['Google Duration (min)']) + '\n\n' +
                              ',,,,,,,,,,,50,-100,50.1,-100.1,,,600\n', encoding='utf-8')
        run = tt.read_run(path, 'R', 20.0)
        self.assertEqual((run['rows'], len(run['legs']), run['ex_google']), (1, 0, 1))


class FixtureTests(unittest.TestCase):
    def test_committed_capture_is_pinned(self):
        data = expected()
        self.assertEqual(data['meta']['generated'], '2026-10-18 09:00')
        self.assertEqual([r['legs'] for r in data['runs']], [11, 11, 12])

    @unittest.skipUnless(shutil.which('pwsh'), 'pwsh not installed')
    def test_committed_capture_matches_a_fresh_pwsh_run(self):
        self.assertEqual(json_diff(capture_pwsh(), expected()), [])


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Capture the script's output for the synthetic set**

```bash
pwsh -NoProfile -Command '$PSVersionTable.PSVersion.ToString()'      # 7.x (captured with 7.6.6)
python3 tests/travel_tuning/tt_fixtures.py --capture
shasum -a 256 tests/travel_tuning/fixtures/synthetic_pwsh.json
```

Expected: `wrote .../tests/travel_tuning/fixtures/synthetic_pwsh.json`, and the hash `c3044d11615aa2e2306af26cf84722ae5c1151e7e68643083e4141370ab31d58` (the capture made while writing this plan with pwsh 7.6.6). If the hash differs, open the file and check these values before going on; they are what the script printed: runs legs 11, 11, 12; timeline flagged 5, 2, 3; `acc.pair` = `{"shared": 11, "fixed": 4, "newly_broken": 2, "still": 1}`; model checks 1 of 4 and 1 of 7; corridors `A5` (`"1.01"`, route `Spare corridor, kept for later`), `K1` (`"0.50, 0.67"`, 1 leg), `Z9` (`"0.13"`, 1 leg); hotspot `Z9`; `flagged.final[0][9]` is `0.0`. The script's console also printed `9 leg pieces fall inside more than one polygon`.

- [ ] **Step 3: Run the tests to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_runs -v`
Expected: the two `FixtureTests` pass; the rest ERROR with `AttributeError: module 'travel_tuning' has no attribute 'read_run'`.

- [ ] **Step 4: Append the run reader**

Append to `travel/travel_tuning.py` (two blank lines before it):

```python
# ---------------------------------------------------------------------------------------------------------------
#  Run CSVs (inventory 2 and 3)
# ---------------------------------------------------------------------------------------------------------------
NEED_COLS = ('Start Location Latitude', 'Start Location Longitude', 'End Location Latitude', 'End Location Longitude',
             'Expected Travel Time (sec)')


def read_run(path, label, threshold):
    """One run CSV -> {label, file, rows, legs, excluded, ex_google, ex_pso, ex_validation, flagged, over, under,
    total, has_fix_cols}. Legs keep file order; leg['nth'] is its index among the legs with the same key."""
    name = os.path.basename(path)
    with open(path, encoding='utf-8-sig', errors='replace', newline='') as fh:
        reader = csv.reader(fh)
        header = next(reader, [])
        rows = [r for r in reader if r]
    if not rows:
        raise TravelTuningError('%s (%s) has no rows.' % (label, name))
    col = {}
    for i, h in enumerate(header):
        col.setdefault(h, i)
    for c in NEED_COLS:
        if c not in col:
            raise TravelTuningError("%s (%s) has no '%s' column. Is it a plan travels CSV?" % (label, name, c))
    if 'Google Duration (min)' not in col:
        raise TravelTuningError('%s (%s) has no Google columns. Run plan travels again for this run with the '
                                'Google check on.' % (label, name))
    has_gsec = 'Google Duration (sec)' in col
    has_val = 'Validation Error' in col
    has_merged = 'Merged Fragments' in col
    has_fix = 'GPS Fix' in col
    legs, seen = [], {}
    ex_google = ex_pso = ex_val = 0
    for ix, r in enumerate(rows, 1):
        def get(name, r=r):
            i = col.get(name)
            return r[i] if i is not None and i < len(r) else ''
        psec = num(get('Expected Travel Time (sec)'))
        gmin = num(get('Google Duration (min)'))
        gsec = num(get('Google Duration (sec)')) if has_gsec else None
        if gsec is None and gmin is not None:
            gsec = gmin * 60.0
        if has_val and get('Validation Error').strip():
            ex_val += 1
            continue
        if psec is None or psec <= 0:
            ex_pso += 1
            continue
        if gsec is None:
            ex_google += 1
            continue
        la1, lo1 = num(get('Start Location Latitude')), num(get('Start Location Longitude'))
        la2, lo2 = num(get('End Location Latitude')), num(get('End Location Longitude'))
        if la1 is None or lo1 is None or la2 is None or lo2 is None:
            ex_pso += 1
            continue
        p = psec / 60.0
        g = gsec / 60.0
        d = p - g
        key = '|'.join('%.5f' % ps_round(v, 5) for v in (la1, lo1, la2, lo2))
        km = num(get('Google Distance (km)'))
        if km is None:
            km = num(get('Distance (km)'))
        merged = 1
        if has_merged:
            mv = num(get('Merged Fragments'))
            merged = 1 if mv is None else int(round(mv))
        nth = seen.get(key, 0)
        seen[key] = nth + 1
        legs.append({
            'key': key, 'nth': nth, 'la1': la1, 'lo1': lo1, 'la2': la2, 'lo2': lo2,
            'p': p, 'g': g, 'd': d, 'ad': r1(abs(d)), 'km': km,
            'res': get('Resource ID').strip(), 'res_name': get('Resource Name').strip(),
            'gb_from': get('GB (Previous Activity)').strip(), 'gb_to': get('GB (Destination Activity)').strip(),
            'row': ix, 'merged': merged,
            'gps_fix': has_fix and get('GPS Fix').strip().casefold() == 'yes',
            'fix_time': get('GPS Fix Time').strip() if has_fix else '',
            'prefix_km': num(get('Pre-Fix Distance (km)')) if has_fix else None,
        })
    flagged, over, under = 0, 0.0, 0.0
    for leg in legs:
        if leg['ad'] >= threshold:
            flagged += 1
        if leg['d'] > 0:
            over += leg['d']
        elif leg['d'] < 0:
            under -= leg['d']
    return {'label': label, 'file': name, 'rows': len(rows), 'legs': legs,
            'excluded': ex_google + ex_pso + ex_val, 'ex_google': ex_google, 'ex_pso': ex_pso,
            'ex_validation': ex_val, 'flagged': flagged, 'over': over, 'under': under, 'total': over + under,
            'has_fix_cols': has_fix}
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `python3 -m unittest discover -s tests/travel_tuning -t .`
Expected: 36 tests, OK (`test_committed_capture_matches_a_fresh_pwsh_run` reruns pwsh, about a second).

- [ ] **Step 6: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/tt_fixtures.py tests/travel_tuning/test_runs.py tests/travel_tuning/fixtures/synthetic_pwsh.json
git status --short          # nothing else staged; no .csv or .xml anywhere in the list
git commit -m "Travel tuning (Python): run CSV reader, synthetic set and the pwsh capture of the script

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 3: Matching legs across runs

**Files:**
- Modify: `travel/travel_tuning.py` (append)
- Test: `tests/travel_tuning/test_matching.py`

**Interfaces:**
- Consumes: `read_run` leg dicts (`key`, `nth`), `pct`, `ps_num`, `COMMON_WARN_PCT`.
- Produces: `group_by_key(legs) -> {key: [leg]}` (first-appearance order); `match_runs(legs_a, by_key_b) -> [(leg_a, leg_b)]`; `match_all(runs) -> {'pairs': {(i-1, i): pairs, (0, nr-1): pairs}, 'shared': pairs, 'common': [tuple of one leg per run], 'min_run': int, 'common_pct': float, 'common_warn': bool}`; `common_warning(pop) -> str`.

- [ ] **Step 1: Write the failing tests**

`tests/travel_tuning/test_matching.py`:

```python
"""Matching legs across runs: n-th occurrence of a key to n-th, consecutive pairs, shared and common legs."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir, write_synthetic, expected  # noqa: E402


def leg(key, nth, tag):
    return {'key': key, 'nth': nth, 'tag': tag}


def run(*legs):
    return {'legs': list(legs)}


class MatchTests(unittest.TestCase):
    def test_nth_occurrence_pairs_in_run_a_file_order(self):
        a = run(leg('k1', 0, 'a1'), leg('k2', 0, 'a2'), leg('k1', 1, 'a3'), leg('k1', 2, 'a4'), leg('k3', 0, 'a5'))
        b = run(leg('k1', 0, 'b1'), leg('k1', 1, 'b2'), leg('k2', 0, 'b3'), leg('k4', 0, 'b4'))
        pairs = tt.match_runs(a['legs'], tt.group_by_key(b['legs']))
        self.assertEqual([(x['tag'], y['tag']) for x, y in pairs], [('a1', 'b1'), ('a2', 'b3'), ('a3', 'b2')])

    def test_populations_for_three_runs(self):
        r0 = run(leg('k1', 0, 'x0'), leg('k1', 1, 'x1'), leg('k2', 0, 'x2'), leg('k3', 0, 'x3'))
        r1 = run(leg('k1', 0, 'y0'), leg('k2', 0, 'y1'), leg('k3', 0, 'y2'))
        r2 = run(leg('k2', 0, 'z0'), leg('k1', 0, 'z1'), leg('k1', 1, 'z2'))
        pop = tt.match_all([r0, r1, r2])
        self.assertEqual(sorted(pop['pairs']), [(0, 1), (0, 2), (1, 2)])
        self.assertEqual([(a['tag'], b['tag']) for a, b in pop['shared']], [('x0', 'z1'), ('x1', 'z2'), ('x2', 'z0')])
        self.assertEqual([tuple(x['tag'] for x in c) for c in pop['common']], [('x0', 'y0', 'z1'), ('x2', 'y1', 'z0')])
        self.assertEqual((pop['min_run'], pop['common_pct'], pop['common_warn']), (3, 66.7, False))

    def test_two_runs_share_one_pair_list(self):
        pop = tt.match_all([run(leg('k', 0, 'a')), run(leg('k', 0, 'b'))])
        self.assertIs(pop['shared'], pop['pairs'][(0, 1)])

    def test_common_warning_under_60_percent(self):
        pop = tt.match_all([run(leg('k1', 0, 'a'), leg('k2', 0, 'b')), run(leg('k1', 0, 'c'), leg('k9', 0, 'd'))])
        self.assertEqual((pop['common_pct'], pop['common_warn']), (50.0, True))
        self.assertEqual(tt.common_warning(pop), 'Only 50% of legs are common to every run (under 60%). Something '
                                                 'besides the weightings may have changed between runs: a different '
                                                 'dataset, date window or load.')

    def test_synthetic_set_matches_the_script(self):
        paths = write_synthetic(tmpdir())
        runs = [tt.read_run(paths[k], k, 20.0) for k in ('base', 'mid', 'final')]
        pop = tt.match_all(runs)
        exp = expected()
        self.assertEqual((len(pop['shared']), len(pop['common']), pop['common_pct']),
                         (exp['meta']['shared'], exp['meta']['common'], exp['meta']['common_pct']))
        # repeated key: base legs 6.7 and 35.0 min pair with final legs 33.3 and 5.2 min (n-th with n-th)
        self.assertEqual([[tt.r1(a['g']), tt.r1(a['p']), tt.r1(b['p'])] for a, b in pop['shared']], exp['shared'])


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_matching -v`
Expected: ERROR, `AttributeError: module 'travel_tuning' has no attribute 'match_runs'` (and `match_all`).

- [ ] **Step 3: Append the matching code**

```python
# ---------------------------------------------------------------------------------------------------------------
#  Matching legs across runs (inventory 4)
# ---------------------------------------------------------------------------------------------------------------
def group_by_key(legs):
    m = {}
    for leg in legs:
        m.setdefault(leg['key'], []).append(leg)
    return m


def match_runs(legs_a, by_key_b):
    """(leg_a, leg_b) pairs in run A's file order: the n-th leg of a key in A with the n-th in B."""
    out = []
    for leg in legs_a:
        qb = by_key_b.get(leg['key'])
        if qb is not None and leg['nth'] < len(qb):
            out.append((leg, qb[leg['nth']]))
    return out


def match_all(runs):
    """The leg populations: pairs[(i-1, i)], shared (baseline vs final), common (every run), and the common check."""
    nr = len(runs)
    by_key = [group_by_key(r['legs']) for r in runs]
    pairs = {}
    for i in range(1, nr):
        pairs[(i - 1, i)] = match_runs(runs[i - 1]['legs'], by_key[i])
    if (0, nr - 1) not in pairs:
        pairs[(0, nr - 1)] = match_runs(runs[0]['legs'], by_key[nr - 1])
    common = []
    for k, q in by_key[0].items():
        n = len(q)
        for m in by_key[1:]:
            if n == 0:
                break
            n = min(n, len(m.get(k, ())))
        for j in range(n):
            common.append(tuple(m[k][j] for m in by_key))
    min_run = min(len(r['legs']) for r in runs)
    common_pct = pct(len(common), min_run)
    return {'pairs': pairs, 'shared': pairs[(0, nr - 1)], 'common': common, 'min_run': min_run,
            'common_pct': common_pct, 'common_warn': common_pct < COMMON_WARN_PCT}


def common_warning(pop):
    return ('Only %s%% of legs are common to every run (under %d%%). Something besides the weightings may have '
            'changed between runs: a different dataset, date window or load.' % (ps_num(pop['common_pct']),
                                                                                   COMMON_WARN_PCT))
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `python3 -m unittest tests.travel_tuning.test_matching -v`
Expected: 5 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/test_matching.py
git status --short
git commit -m "Travel tuning (Python): match legs across runs on the 5 dp key, n-th occurrence to n-th

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 4: Metrics without polygons

**Files:**
- Modify: `travel/travel_tuning.py` (append)
- Test: `tests/travel_tuning/test_metrics.py`

**Interfaces:**
- Consumes: leg and run dicts (Task 2), `match_all` (Task 3), `r1`, `r2`, `pct`, `text_key`, `DISTANCE_BANDS`, `CHECK_ROWS`.
- Produces: `is_flagged(leg, threshold) -> bool`; `leg_gbs(leg) -> [str]`; `route_of(leg) -> str`; `accuracy(legs, small) -> dict` (keys `avg_abs`, `bias`, `pso_h`, `google_h`, `pso_vs_google_pct`, `pct_10`, `pct_over`, `pct_under`, `n_over`, `n_under`); `pair_stats(pairs, threshold) -> {'shared', 'fixed', 'newly_broken', 'still'}`; `newly_under(shared, small) -> [pair]`; `distance_bands(shared) -> [dict]`; `gb_flags(legs, threshold)`; `gb_summary(base_legs, final_legs, threshold) -> dict`; `worst_offenders(shared, threshold) -> [dict]`; `check_these(run, threshold) -> dict`; `timeline(runs, pop, poly_counts, threshold, small) -> [row dict]` with `model_ok` / `model_n` left None for Task 5.

- [ ] **Step 1: Write the failing tests**

`tests/travel_tuning/test_metrics.py`:

```python
"""Metrics that need no polygons: accuracy, pair stats, bands, GB summary, worst offenders, check these, timeline."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir, write_synthetic, expected, json_diff  # noqa: E402


def leg(d, p=30.0, row=1, km=None, gb_from='', gb_to='', merged=1, gps=False, name='T'):
    return {'d': d, 'p': p, 'g': p - d, 'ad': tt.r1(abs(d)), 'row': row, 'km': km, 'gb_from': gb_from, 'gb_to': gb_to,
            'merged': merged, 'gps_fix': gps, 'fix_time': '', 'prefix_km': None, 'res': 'R', 'res_name': name}


class UnitTests(unittest.TestCase):
    def test_accuracy_on_no_legs_divides_by_one(self):
        self.assertEqual(tt.accuracy([], 5.0), {'avg_abs': 0.0, 'bias': 0.0, 'pso_h': 0.0, 'google_h': 0.0,
                                                'pso_vs_google_pct': 0.0, 'pct_10': 0.0, 'pct_over': 0.0,
                                                'pct_under': 0.0, 'n_over': 0, 'n_under': 0})

    def test_accuracy_thresholds_use_raw_differences(self):
        acc = tt.accuracy([leg(10.0), leg(-5.0), leg(4.99), leg(-10.0)], 5.0)
        self.assertEqual((acc['n_over'], acc['n_under'], acc['pct_10']), (1, 2, 50.0))
        self.assertEqual((acc['avg_abs'], acc['bias']), (7.5, 0.0))

    def test_leg_gbs_and_route(self):
        self.assertEqual(tt.leg_gbs(leg(0, gb_from='AA1', gb_to='aa1')), ['AA1'])      # -ne is case-insensitive
        self.assertEqual(tt.leg_gbs(leg(0, gb_from='', gb_to='BB2')), ['BB2'])
        self.assertEqual(tt.route_of(leg(0, gb_from='', gb_to='BB2')), 'home to BB2')

    def test_gb_keys_are_case_sensitive_and_sorted_case_insensitively(self):
        base = [leg(25, gb_from='b1', gb_to='A1'), leg(25, gb_from='B1'), leg(25, gb_from='a1')]
        final = [leg(25, gb_from='a1'), leg(25, gb_from='C1')]
        self.assertEqual(tt.gb_summary(base, final, 20.0),
                         {'base_count': 4, 'cleared': ['A1', 'B1', 'b1'], 'still': [{'gb': 'a1', 'base': 1, 'final': 1}],
                          'new': [{'gb': 'C1', 'final': 1}]})

    def test_worst_ties_break_on_row(self):
        shared = [(leg(30.0, row=5), leg(1.0)), (leg(-30.0, row=2), leg(25.0)), (leg(10.0, row=1), leg(0.0))]
        self.assertEqual([(w['base'], w['fixed']) for w in tt.worst_offenders(shared, 20.0)],
                         [(-30.0, False), (30.0, True), (10.0, True)])

    def test_check_these_ties_stay_in_file_order(self):
        run = {'label': 'R', 'has_fix_cols': False,
               'legs': [leg(3.0, merged=2, name='first'), leg(-3.0, merged=2, name='second'), leg(25.0, merged=2, name='flag'),
                        leg(9.0, name='plain'), leg(3.0, gps=True, name='third')]}
        c = tt.check_these(run, 20.0)
        self.assertEqual([r['res'] for r in c['rows']], ['flag', 'first', 'second', 'third'])
        self.assertEqual((c['merged'], c['gps'], c['has_fix_cols']), (3, 1, False))

    def test_bands_skip_legs_without_distance(self):
        shared = [(leg(2.0, km=4.99), leg(1.0)), (leg(4.0, km=5.0), leg(2.0)), (leg(9.0), leg(9.0))]
        bands = tt.distance_bands(shared)
        self.assertEqual(bands[0], {'band': 'Under 5 km', 'legs': 1, 'base': 2.0, 'final': 1.0})
        self.assertEqual(bands[1], {'band': '5-15 km', 'legs': 1, 'base': 4.0, 'final': 2.0})
        self.assertEqual(bands[5], {'band': '100+ km', 'legs': 0, 'base': None, 'final': None})


class SyntheticTests(unittest.TestCase):
    """Every value that needs no polygons, against the script's output for the synthetic set."""
    @classmethod
    def setUpClass(cls):
        paths = write_synthetic(tmpdir())
        cls.runs = [tt.read_run(paths[k], label, 20.0)
                    for k, label in (('base', 'Baseline'), ('mid', 'Grid A'), ('final', 'Grid B (baseline v2)'))]
        cls.pop = tt.match_all(cls.runs)
        cls.exp = expected()

    def test_accuracy_and_pairs(self):
        sh = self.pop['shared']
        newu = tt.newly_under(sh, 5.0)
        ours = {'base': tt.accuracy([a for a, _ in sh], 5.0), 'final': tt.accuracy([b for _, b in sh], 5.0),
                'newly_under': len(newu), 'newly_under_5_10': sum(1 for _, b in newu if b['d'] > -10),
                'pair': tt.pair_stats(sh, 20.0)}
        self.assertEqual(json_diff(self.exp['acc'], ours), [])

    def test_bands_gb_worst_check(self):
        sh = self.pop['shared']
        self.assertEqual(json_diff(self.exp['bands'], tt.distance_bands(sh)), [])
        self.assertEqual(json_diff(self.exp['gb'], tt.gb_summary(self.runs[0]['legs'], self.runs[-1]['legs'], 20.0)), [])
        self.assertEqual(json_diff(self.exp['worst'], tt.worst_offenders(sh, 20.0)), [])
        self.assertEqual(json_diff(self.exp['check'], [tt.check_these(self.runs[0], 20.0),
                                                       tt.check_these(self.runs[-1], 20.0)]), [])

    def test_timeline_without_the_model_check(self):
        tl = tt.timeline(self.runs, self.pop, [0, 3, 8], 20.0, 5.0)
        exp = [dict(r, model_ok=None, model_n=None) for r in self.exp['timeline']]
        self.assertEqual(json_diff(exp, tl), [])


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_metrics -v`
Expected: ERROR, `AttributeError: module 'travel_tuning' has no attribute 'accuracy'` (and the other names).

- [ ] **Step 3: Append the metrics**

```python
# ---------------------------------------------------------------------------------------------------------------
#  Metrics without polygons (inventory 5)
# ---------------------------------------------------------------------------------------------------------------
def is_flagged(leg, threshold):
    return leg['ad'] >= threshold


def leg_gbs(leg):
    """The GB(s) a leg counts towards: its previous activity's GB, plus the destination's when it differs."""
    out = []
    if leg['gb_from']:
        out.append(leg['gb_from'])
    if leg['gb_to'] and leg['gb_to'].casefold() != leg['gb_from'].casefold():
        out.append(leg['gb_to'])
    return out


def route_of(leg):
    return '%s to %s' % (leg['gb_from'] or 'home', leg['gb_to'] or 'home')


def accuracy(legs, small):
    n = len(legs)
    sa = sd = big_p = big_g = 0.0
    t10 = o5 = u5 = 0
    for leg in legs:                 # plain left-to-right sums in list order (spec 3.2 rule 5)
        d = leg['d']
        sa += abs(d)
        sd += d
        big_p += leg['p']
        big_g += leg['g']
        if abs(d) >= 10:
            t10 += 1
        if d >= small:
            o5 += 1
        if d <= -small:
            u5 += 1
    if n == 0:
        n = 1
    return {'avg_abs': r2(sa / n), 'bias': r2(sd / n), 'pso_h': r1(big_p / 60), 'google_h': r1(big_g / 60),
            'pso_vs_google_pct': pct(big_p - big_g, big_g), 'pct_10': pct(t10, n), 'pct_over': pct(o5, n),
            'pct_under': pct(u5, n), 'n_over': o5, 'n_under': u5}


def pair_stats(pairs, threshold):
    fx = nb = st = 0
    for a, b in pairs:
        fa, fb = is_flagged(a, threshold), is_flagged(b, threshold)
        if fa and not fb:
            fx += 1
        elif fb and not fa:
            nb += 1
        elif fa and fb:
            st += 1
    return {'shared': len(pairs), 'fixed': fx, 'newly_broken': nb, 'still': st}


def newly_under(shared, small):
    return [pr for pr in shared if pr[1]['d'] <= -small and abs(pr[0]['d']) < small]


def distance_bands(shared):
    out = []
    for lo, hi, label in DISTANCE_BANDS:
        n, eb, ef = 0, 0.0, 0.0
        for a, b in shared:
            km = a['km']
            if km is None:
                continue
            if lo <= km < hi:
                n += 1
                eb += abs(a['d'])
                ef += abs(b['d'])
        out.append({'band': label, 'legs': n, 'base': r2(eb / n) if n else None, 'final': r2(ef / n) if n else None})
    return out


def gb_flags(legs, threshold):
    m = {}
    for leg in legs:
        if is_flagged(leg, threshold):
            for g in leg_gbs(leg):
                m[g] = m.get(g, 0) + 1
    return m


def gb_summary(base_legs, final_legs, threshold):
    gb_b, gb_f = gb_flags(base_legs, threshold), gb_flags(final_legs, threshold)
    return {'base_count': len(gb_b),
            'cleared': sorted((g for g in gb_b if g not in gb_f), key=text_key),
            'still': [{'gb': g, 'base': gb_b[g], 'final': gb_f[g]}
                      for g in sorted((g for g in gb_b if g in gb_f), key=text_key)],
            'new': [{'gb': g, 'final': gb_f[g]} for g in sorted((g for g in gb_f if g not in gb_b), key=text_key)]}


def worst_offenders(shared, threshold):
    top = sorted(shared, key=lambda pr: (-abs(pr[0]['d']), pr[0]['row']))[:10]
    return [{'res': a['res_name'], 'res_id': a['res'], 'route': route_of(a), 'base': r1(a['d']), 'final': r1(b['d']),
             'google': r1(a['g']), 'fixed': not is_flagged(b, threshold)} for a, b in top]


def check_these(run, threshold):
    """Merged and GPS-fix legs of one run, flagged first, then largest |d|; ties stay in file order."""
    cand = [leg for leg in run['legs'] if leg['merged'] > 1 or leg['gps_fix']]
    rows = sorted(cand, key=lambda leg: (-int(is_flagged(leg, threshold)), -abs(leg['d'])))[:CHECK_ROWS]
    return {'label': run['label'], 'merged': sum(1 for leg in cand if leg['merged'] > 1),
            'gps': sum(1 for leg in cand if leg['gps_fix']), 'has_fix_cols': bool(run['has_fix_cols']),
            'rows': [{'res': leg['res_name'], 'route': route_of(leg), 'merged': leg['merged'], 'gps': leg['gps_fix'],
                      'fix_time': leg['fix_time'], 'prefix_km': leg['prefix_km'], 'p': r1(leg['p']),
                      'g': r1(leg['g']), 'd': r1(leg['d']), 'flagged': is_flagged(leg, threshold)} for leg in rows]}


def timeline(runs, pop, poly_counts, threshold, small):
    """One row per run; model_ok / model_n stay None here (filled by apply_model_checks)."""
    out = []
    for i, run in enumerate(runs):
        ca = accuracy([c[i] for c in pop['common']], small)
        row = {'label': run['label'], 'polygons': poly_counts[i], 'legs': len(run['legs']),
               'excluded': run['excluded'], 'flagged': run['flagged'], 'over_h': r1(run['over'] / 60),
               'under_h': r1(run['under'] / 60), 'total_h': r1(run['total'] / 60),
               'over_min': float(round(run['over'])), 'under_min': float(round(run['under'])),
               'total_min': float(round(run['total'])), 'fixed': None, 'newly_broken': None, 'shared_prev': None,
               'model_ok': None, 'model_n': None, 'common_avg_abs': ca['avg_abs'], 'common_bias': ca['bias']}
        if i > 0:
            ps = pair_stats(pop['pairs'][(i - 1, i)], threshold)
            row['fixed'], row['newly_broken'], row['shared_prev'] = ps['fixed'], ps['newly_broken'], ps['shared']
        out.append(row)
    return out
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `python3 -m unittest tests.travel_tuning.test_metrics -v`
Expected: 10 tests, OK. The `SyntheticTests` compare with the script's own numbers, so a failure there at a half (for example 0.05 against 0.1) means a sum or a rounding helper drifted from spec 3.2 rules 1 and 5, not that the fixture is wrong.

- [ ] **Step 5: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/test_metrics.py
git status --short
git commit -m "Travel tuning (Python): accuracy, pair stats, distance bands, GB summary, worst legs, check these, timeline

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 5: Polygons, geometry and the model check

**Files:**
- Modify: `travel/travel_tuning.py` (append)
- Test: `tests/travel_tuning/test_polygons.py`

**Interfaces:**
- Consumes: `num`, `ndiv`, `ps_round`, `ps_num`, `MODEL_WARN_PCT`, `timeline` rows (Task 4), `match_all` (Task 3), `build_parser` options `model_tol_min` / `model_tol_pct` (Task 1).
- Produces: `read_polygons(path) -> [polygon dict]` (streamed; raises `TravelTuningError` on bad XML); `non_rectangle_warning(label, polys) -> str|None`; `clip(leg, r) -> (t0, t1)|None`; `crossings(leg, polys) -> [(poly, t0, t1)]`; `weight_at(cross, t, overlaps) -> float` (`overlaps` is a one-item list, incremented in place); `predict(leg_a, polys_a, polys_b, overlaps) -> float|None`; `model_check(pairs, polys_a, polys_b, tol_min, tol_pct, overlaps) -> (ok, n)`; `apply_model_checks(tl, pop, polys, poly_given, opts, overlaps, set_stage=...) -> (meta.model_warn list, warning texts)`.

- [ ] **Step 1: Write the failing tests**

`tests/travel_tuning/test_polygons.py`:

```python
"""Polygon reader, rectangles, Clip / Crossings / WeightAt / Predict and the model check (inventory 2 and 5)."""
import math
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir, write_synthetic, expected, json_diff, polygon_xml, rect  # noqa: E402


def leg(la1, lo1, la2, lo2, p=60.0):
    return {'la1': la1, 'lo1': lo1, 'la2': la2, 'lo2': lo2, 'p': p}


def box(s, n, west, east, w=1.0, pid='R'):
    return {'id': pid, 's': s, 'n': n, 'west': west, 'east': east, 'w': w}


class ReaderTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.paths = write_synthetic(tmpdir())
        cls.polys = tt.read_polygons(cls.paths['polys_final'])

    def test_ids_in_file_order_and_short_polygons_skipped(self):
        self.assertEqual([p['id'] for p in self.polys], ['G01', 'G02', 'K1-01', 'k1-02', 'Z9', 'A5-01', 'T1', 'N1'])

    def test_fields_match_the_script(self):
        ours = [{k: p[k] for k in ('id', 'w', 'desc', 'group', 'gtype', 's', 'n', 'west', 'east')} for p in self.polys]
        exp = [{k: v for k, v in p.items() if k != 'note'} for p in expected()['polygons']]
        self.assertEqual(json_diff(exp, ours), [])

    def test_weights_last_row_wins_and_missing_is_one(self):
        by_id = {p['id']: p for p in self.polys}
        self.assertEqual((by_id['G01']['w'], by_id['G01']['has_w']), (0.8, True))
        self.assertEqual((by_id['N1']['w'], by_id['N1']['has_w'], by_id['N1']['gtype']), (1.0, False, 'Other'))
        self.assertEqual((by_id['k1-02']['gtype'], by_id['k1-02']['group']), ('Corridor', 'k1'))

    def test_rectangles_and_the_warning(self):
        self.assertEqual([p['id'] for p in self.polys if not p['rect']], ['T1'])
        t1 = self.polys[6]
        self.assertEqual((t1['s'], t1['n'], t1['west'], t1['east']), (50.30, 50.35, -100.95, -100.85))
        self.assertEqual(tt.non_rectangle_warning('Grid B', self.polys),
                         'Grid B: 1 polygon is not an axis-aligned rectangle; the report uses the bounding rectangle '
                         'of each, which covers more ground than the polygon.')
        self.assertIsNone(tt.non_rectangle_warning('Grid A', tt.read_polygons(self.paths['polys_mid'])))

    def test_no_namespace_and_bad_xml(self):
        d = tmpdir()
        plain = os.path.join(d, 'plain.xml')
        Path(plain).write_text(polygon_xml([('P1', None, rect(1, 2, 3, 4))], [('P1', ' 0.5 ')]).replace(
            ' xmlns="http://360scheduling.com/Schema/DsSystemData.xsd"', ''), encoding='utf-8')
        p = tt.read_polygons(plain)[0]
        self.assertEqual((p['id'], p['desc'], p['gtype'], p['w']), ('P1', '', 'Other', 0.5))
        bad = os.path.join(d, 'bad.xml')
        Path(bad).write_text('<DsSystemData><Polygon>', encoding='utf-8')
        with self.assertRaises(tt.TravelTuningError) as cm:
            tt.read_polygons(bad)
        self.assertIn('The polygon file bad.xml is not valid XML', str(cm.exception))


class GeometryTests(unittest.TestCase):
    def test_clip(self):
        r = box(0, 1, 0, 1)
        self.assertEqual(tt.clip(leg(0.5, -1, 0.5, 2), r), (1 / 3, 2 / 3))
        self.assertEqual(tt.clip(leg(0.2, 0.2, 0.8, 0.8), r), (0.0, 1.0))
        self.assertEqual(tt.clip(leg(1.0, -1, 1.0, 2), r), (1 / 3, 2 / 3))  # along the top edge counts as inside
        self.assertIsNone(tt.clip(leg(2, 0, 2, 1), r))                     # outside, parallel
        self.assertIsNone(tt.clip(leg(1, 1, 2, 2), r))                     # touches a corner only
        self.assertEqual(tt.clip(leg(0.5, 0.5, 0.5, 0.5), r), (0.0, 1.0))  # zero length, inside
        self.assertIsNone(tt.clip(leg(5, 5, 5, 5), r))                     # zero length, outside

    def test_crossings_keep_file_order(self):
        a, b, c = box(0, 1, 2, 6, pid='A'), box(0, 1, 4, 8, pid='B'), box(5, 6, 0, 10, pid='C')
        cross = tt.crossings(leg(0.5, 0, 0.5, 10), [b, c, a])
        self.assertEqual([(x[0]['id'], round(x[1], 9), round(x[2], 9)) for x in cross], [('B', 0.4, 0.8), ('A', 0.2, 0.6)])
        self.assertEqual(tt.crossings(leg(0.5, 0, 0.5, 10), []), [])

    def test_weight_at_takes_the_first_and_counts_overlaps(self):
        ov = [0]
        cross = [(box(0, 1, 0, 1, w=0.5), 0.2, 0.6), (box(0, 1, 0, 1, w=2.0), 0.4, 0.8)]
        self.assertEqual([tt.weight_at(cross, t, ov) for t in (0.1, 0.3, 0.5, 0.7, 0.6)], [1.0, 0.5, 0.5, 2.0, 0.5])
        self.assertEqual(ov, [2])

    def test_predict_on_overlapping_rectangles(self):
        ov = [0]
        a = leg(0.5, 0, 0.5, 10, p=60.0)
        polys_b = [box(0, 1, 2, 6, w=0.5), box(0, 1, 4, 8, w=2.0)]
        # pieces of 0.2: w 1, 0.5, 0.5 (overlap: the first rectangle), 2, 1 -> factor 1.0
        self.assertAlmostEqual(tt.predict(a, [], polys_b, ov), 60.0, places=9)
        self.assertEqual(ov, [1])
        self.assertIsNone(tt.predict(leg(5, 0, 5, 10), [], polys_b, ov))
        # from run A's weighting 0.5 to run B's 1.0 over the middle 40% of the leg: 60 * (0.6 + 0.4 * 2) = 84
        self.assertAlmostEqual(tt.predict(a, [box(0, 1, 3, 7, w=0.5)], [box(0, 1, 3, 7, w=1.0)], [0]), 84.0, places=9)

    def test_zero_weighting_in_run_a_fails_the_leg(self):
        a = leg(0.5, 0, 0.5, 10, p=60.0)
        self.assertEqual(tt.predict(a, [box(0, 1, 3, 7, w=0.0)], [box(0, 1, 3, 7, w=1.0)], [0]), math.inf)
        self.assertTrue(math.isnan(tt.predict(a, [box(0, 1, 3, 7, w=0.0)], [box(0, 1, 3, 7, w=0.0)], [0])))
        pairs = [(a, leg(0.5, 0, 0.5, 10, p=60.0))]
        self.assertEqual(tt.model_check(pairs, [box(0, 1, 3, 7, w=0.0)], [box(0, 1, 3, 7, w=1.0)], 1.0, 5.0, [0]), (0, 1))

    def test_model_tolerance_is_the_larger_of_minutes_and_percent(self):
        a = leg(0.5, 0, 0.5, 10, p=60.0)
        polys_a, polys_b = [box(0, 1, 3, 7, w=0.5)], [box(0, 1, 3, 7, w=1.0)]     # predicted 84
        for actual, ok in ((88.4, 1), (88.6, 0), (80.1, 1), (79.9, 0)):
            self.assertEqual(tt.model_check([(a, leg(0, 0, 0, 0, p=actual))], polys_a, polys_b, 1.0, 5.0, [0]), (ok, 1))


class SyntheticModelTests(unittest.TestCase):
    def test_model_checks_and_overlaps_match_the_script(self):
        paths = write_synthetic(tmpdir())
        labels = ['Baseline', 'Grid A', 'Grid B (baseline v2)']
        runs = [tt.read_run(paths[k], lab, 20.0) for k, lab in zip(('base', 'mid', 'final'), labels)]
        polys = [[], tt.read_polygons(paths['polys_mid']), tt.read_polygons(paths['polys_final'])]
        pop = tt.match_all(runs)
        tl = tt.timeline(runs, pop, [0, 3, 8], 20.0, 5.0)
        opts = tt.build_parser().parse_args(['--out', 'x'])
        ov, stages = [0], []
        warn, texts = tt.apply_model_checks(tl, pop, polys, [False, True, True], opts, ov, stages.append)
        exp = expected()
        self.assertEqual([(r['model_ok'], r['model_n']) for r in tl], [(r['model_ok'], r['model_n']) for r in exp['timeline']])
        self.assertEqual(json_diff(exp['meta']['model_warn'], warn), [])
        self.assertEqual(ov, [9])                    # the script printed "9 leg pieces fall inside more than one polygon"
        self.assertEqual(stages, ['model check Baseline to Grid A', 'model check Grid A to Grid B (baseline v2)'])
        self.assertEqual(texts[1], 'Model check Grid A to Grid B (baseline v2): PSO moved as predicted on only 1 of 7 '
                                   'legs (14.3%, under 80%). The polygon import may not be what PSO used, or something '
                                   'besides the weightings changed between these runs; check before reading the '
                                   'polygon results.')

    def test_no_model_check_without_polygon_files(self):
        r = {'label': 'x', 'legs': [], 'excluded': 0, 'flagged': 0, 'over': 0.0, 'under': 0.0, 'total': 0.0}
        pop = tt.match_all([r, dict(r)])
        tl = tt.timeline([r, dict(r)], pop, [0, 0], 20.0, 5.0)
        opts = tt.build_parser().parse_args(['--out', 'x'])
        self.assertEqual(tt.apply_model_checks(tl, pop, [[], []], [False, False], opts, [0]), ([], []))
        self.assertEqual((tl[1]['model_ok'], tl[1]['model_n']), (None, None))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_polygons -v`
Expected: ERROR, `AttributeError: module 'travel_tuning' has no attribute 'read_polygons'` (and `clip`, `predict`, ...).

- [ ] **Step 3: Append the polygon code**

```python
# ---------------------------------------------------------------------------------------------------------------
#  Polygons and the model check (inventory 2 and 5)
# ---------------------------------------------------------------------------------------------------------------
def _local(tag):
    return tag.rsplit('}', 1)[-1]


def _kids(elem):
    """Text of the first direct child of each name ('' when empty)."""
    out = {}
    for c in elem:
        out.setdefault(_local(c.tag), c.text or '')
    return out


def _polygon(kids):
    pid = kids.get('id', '').strip()
    desc = kids.get('description', '').strip()
    las, los = [], []
    for c in range(1, 9):
        a, b = num(kids.get('latitude_%d' % c)), num(kids.get('longitude_%d' % c))
        if a is not None and b is not None:
            las.append(a)
            los.append(b)
    if len(las) < 3:
        return None
    dash = pid.find('-')
    first = re.split(r'[\s|]+', desc)[0]
    gtype = first[0].upper() + first[1:].lower() if re.fullmatch(r'[A-Za-z]+', first) else 'Other'
    s, n, west, east = min(las), max(las), min(los), max(los)
    corners = {(s, west), (s, east), (n, west), (n, east)}
    return {'id': pid, 'desc': desc, 'group': pid[:dash] if dash > 0 else pid, 'gtype': gtype, 'w': 1.0,
            'has_w': False, 's': s, 'n': n, 'west': west, 'east': east, 'rect': set(zip(las, los)) == corners}


def read_polygons(path):
    """Polygon and Travel_Time_Polygon rows anywhere in a DsSystemData file, streamed. Each polygon is reduced to
    its bounding rectangle (s, n, west, east); 'rect' says whether its vertices were that rectangle."""
    weights, polys, depth = {}, [], 0
    try:
        for event, elem in ET.iterparse(path, events=('start', 'end')):
            if event == 'start':
                depth += 1
                continue
            depth -= 1
            tag = _local(elem.tag)
            if tag == 'Travel_Time_Polygon':
                kids = _kids(elem)
                pid, wv = kids.get('polygon_id', ''), num(kids.get('weighting'))
                if pid and wv is not None:
                    weights[pid.strip()] = wv
            elif tag == 'Polygon':
                poly = _polygon(_kids(elem))
                if poly:
                    polys.append(poly)
            if depth == 1:
                elem.clear()
    except ET.ParseError as e:
        raise TravelTuningError('The polygon file %s is not valid XML (%s).' % (os.path.basename(path), e))
    for poly in polys:
        if poly['id'] in weights:
            poly['w'], poly['has_w'] = weights[poly['id']], True
    return polys


def non_rectangle_warning(label, polys):
    n = sum(1 for p in polys if not p['rect'])
    if not n:
        return None
    return ('%s: %s; the report uses the bounding rectangle of each, which covers more ground than the polygon.'
            % (label, '1 polygon is not an axis-aligned rectangle' if n == 1
               else '%d polygons are not axis-aligned rectangles' % n))


def clip(leg, r):
    """Liang-Barsky: (t0, t1) of the leg's straight line inside rectangle r, or None."""
    t0, t1 = 0.0, 1.0
    dx = leg['lo2'] - leg['lo1']
    dy = leg['la2'] - leg['la1']
    for p, q in ((-dx, leg['lo1'] - r['west']), (dx, r['east'] - leg['lo1']),
                 (-dy, leg['la1'] - r['s']), (dy, r['n'] - leg['la1'])):
        if p == 0:
            if q < 0:
                return None
        else:
            t = q / p
            if p < 0:
                if t > t1:
                    return None
                if t > t0:
                    t0 = t
            else:
                if t < t0:
                    return None
                if t < t1:
                    t1 = t
    if t1 <= t0:
        return None
    return (t0, t1)


def crossings(leg, polys):
    """[(polygon, t0, t1)] for every rectangle the leg's straight line crosses, in polygon file order."""
    out = []
    if not polys:
        return out
    mnla, mxla = min(leg['la1'], leg['la2']), max(leg['la1'], leg['la2'])
    mnlo, mxlo = min(leg['lo1'], leg['lo2']), max(leg['lo1'], leg['lo2'])
    for r in polys:
        if r['n'] < mnla or r['s'] > mxla or r['east'] < mnlo or r['west'] > mxlo:
            continue
        c = clip(leg, r)
        if c:
            out.append((r, c[0], c[1]))
    return out


def weight_at(cross, t, overlaps):
    """Weighting at path position t: the first crossing holding t (file order), 1.0 outside every polygon.
    overlaps[0] counts each t that falls inside a second crossing too."""
    w = None
    for poly, t0, t1 in cross:
        if t0 <= t <= t1:
            if w is None:
                w = poly['w']
            else:
                overlaps[0] += 1
                break
    return 1.0 if w is None else w


def predict(leg_a, polys_a, polys_b, overlaps):
    """PSO time expected in run B from run A's: p_A * sum over path pieces of (length * w_B / w_A); None when the
    leg crosses no rectangle in either run."""
    ca, cb = crossings(leg_a, polys_a), crossings(leg_a, polys_b)
    if not ca and not cb:
        return None
    ts = [0.0, 1.0]
    for _, t0, t1 in ca:
        ts += [t0, t1]
    for _, t0, t1 in cb:
        ts += [t0, t1]
    ts.sort()
    f = 0.0
    for k in range(len(ts) - 1):
        seg = ts[k + 1] - ts[k]
        if seg <= 0:
            continue
        mid = (ts[k] + ts[k + 1]) / 2
        wb = weight_at(cb, mid, overlaps)
        f += ndiv(seg * wb, weight_at(ca, mid, overlaps))
    return leg_a['p'] * f


def model_check(pairs, polys_a, polys_b, tol_min, tol_pct, overlaps):
    """(ok, n): legs touching a rectangle in either run, and those whose run B time is within tolerance of the
    prediction."""
    ok = tot = 0
    for a, b in pairs:
        pred = predict(a, polys_a, polys_b, overlaps)
        if pred is None:
            continue
        tot += 1
        tol = max(tol_min, b['p'] * tol_pct / 100.0)
        if abs(pred - b['p']) <= tol:
            ok += 1
    return ok, tot


def apply_model_checks(tl, pop, polys, poly_given, opts, overlaps, set_stage=lambda s: None):
    """Fill model_ok / model_n for run i when any run has polygons and run i-1 or i has a polygon file; return
    meta.model_warn and the warning texts."""
    labels = [r['label'] for r in tl]
    have = any(poly_given)
    for i in range(1, len(tl)):
        if have and (poly_given[i - 1] or poly_given[i]):
            set_stage('model check %s to %s' % (labels[i - 1], labels[i]))
            tl[i]['model_ok'], tl[i]['model_n'] = model_check(pop['pairs'][(i - 1, i)], polys[i - 1], polys[i],
                                                              opts.model_tol_min, opts.model_tol_pct, overlaps)
    warn, texts = [], []
    for i in range(1, len(tl)):
        row = tl[i]
        if not row['model_n']:
            continue
        mp = ps_round(100.0 * row['model_ok'] / row['model_n'], 1)
        if mp < MODEL_WARN_PCT:
            warn.append({'from': labels[i - 1], 'to': labels[i], 'ok': row['model_ok'], 'n': row['model_n'], 'pct': mp})
            texts.append('Model check %s to %s: PSO moved as predicted on only %d of %d legs (%s%%, under %d%%). The '
                         'polygon import may not be what PSO used, or something besides the weightings changed '
                         'between these runs; check before reading the polygon results.'
                         % (labels[i - 1], labels[i], row['model_ok'], row['model_n'], ps_num(mp), MODEL_WARN_PCT))
    return warn, texts
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `python3 -m unittest tests.travel_tuning.test_polygons -v`
Expected: 13 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/test_polygons.py
git status --short
git commit -m "Travel tuning (Python): streamed polygon reader, rectangle clip, prediction and model check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 6: Hotspot, strongest weightings, corridors and map data

**Files:**
- Modify: `travel/travel_tuning.py` (append)
- Test: `tests/travel_tuning/test_callouts.py`

**Interfaces:**
- Consumes: `crossings` (Task 5), `newly_under`, `is_flagged`, `route_of` (Task 4), `pct`, `r1`, `ps_round`, `net_fixed2`, `text_key`, `CORRIDOR_MIN_SHARE`, `HOTSPOT_CALLOUT_PCT`.
- Produces: `read_review(path) -> {polygon_id: (route, note)}`; `hotspot(newu, final_polys) -> dict|None`; `strongest(final_polys) -> {'low', 'high'}`; `corridor_groups(final_polys) -> [(name, [poly])]`; `corridors(final_polys, shared, review) -> [dict]`; `overlap_warning(n) -> str`; `map_leg(leg) -> list of 10`; `map_bounds(*runs) -> [s, w, n, e]`; `poly_count(label, polys, given) -> dict`.

- [ ] **Step 1: Write the failing tests**

`tests/travel_tuning/test_callouts.py`:

```python
"""Hotspot, strongest weightings, corridors (pinned group order), review CSV, map data and polygon counts."""
import csv
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tt, tmpdir, write_synthetic, expected, json_diff, null_km_paths  # noqa: E402


def poly(pid, w=1.0, gtype='Corridor', desc='', group=None):
    return {'id': pid, 'w': w, 'gtype': gtype, 'desc': desc, 'group': group if group is not None else pid.split('-')[0],
            's': 0.0, 'n': 1.0, 'west': 0.0, 'east': 1.0}


class UnitTests(unittest.TestCase):
    def test_corridor_groups_merge_case_and_sort_like_pwsh_7(self):
        # pwsh 7 Group-Object on b, A, a, c, B gives A, b, c (first spelling, sorted case-insensitively)
        polys = [poly(g + '-1', group=g) for g in ('b', 'A', 'a', 'c', 'B')] + [poly('G1', gtype='Grid')]
        self.assertEqual([(name, [p['id'] for p in gp]) for name, gp in tt.corridor_groups(polys)],
                         [('A', ['A-1', 'a-1']), ('b', ['b-1', 'B-1']), ('c', ['c-1'])])

    def test_strongest_ties_by_id(self):
        polys = [poly('b', 0.5), poly('A', 0.5), poly('z', 1.5), poly('c', 1.5)]
        s = tt.strongest(polys)
        self.assertEqual((s['low']['id'], s['high']['id']), ('A', 'c'))

    def test_corridor_route_precedence(self):
        gp = [poly('K1-01', desc='Corridor w0.5 | from desc'), poly('K1-02', desc='Corridor')]
        self.assertEqual(tt.corridors(gp, [], {})[0]['route'], 'from desc')
        self.assertEqual(tt.corridors(gp, [], {'K1-02': ('', 'a note')})[0]['route'], 'a note')
        self.assertEqual(tt.corridors(gp, [], {'K1-01': ('', ''), 'K1-02': ('GB route', 'n')})[0]['route'], 'GB route')
        self.assertEqual(tt.corridors([poly('K2', desc='Corridor')], [], {})[0]['route'], '')

    def test_weightings_text(self):
        gp = [poly('K-1', 0.125), poly('K-2', 1.005), poly('K-3', 0.125), poly('K-4', 0.3)]
        self.assertEqual(tt.corridors(gp, [], {})[0]['weightings'], '0.13, 0.30, 1.01')

    def test_map_leg_null_km_deviation(self):
        leg = {'la1': 50.123455, 'lo1': -100.0, 'la2': 50.2, 'lo2': -100.1, 'd': 20.05, 'p': 30.05, 'g': 10.0,
               'res_name': 'T', 'gb_from': '', 'gb_to': 'X', 'km': None}
        self.assertEqual(tt.map_leg(leg), [50.12346, -100.0, 50.2, -100.1, 20.1, 30.1, 10.0, 'T', 'home to X', None])

    def test_map_bounds_with_no_legs(self):
        self.assertEqual(tt.map_bounds({'legs': []}), [90.0, 180.0, -90.0, -180.0])

    def test_read_review(self):
        path = os.path.join(tmpdir(), 'review.csv')
        with open(path, 'w', encoding='utf-8-sig', newline='') as fh:
            csv.writer(fh).writerows([['Polygon', 'GBs / Route', 'Note'], [' K1 ', ' r1 ', ' n1 '], ['', 'x', 'y'],
                                      ['K1', 'r2', '']])
        self.assertEqual(tt.read_review(path), {'K1': ('r2', '')})
        self.assertEqual(tt.read_review(''), {})


class SyntheticTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        paths = write_synthetic(tmpdir())
        labels = ['Baseline', 'Grid A', 'Grid B (baseline v2)']
        cls.runs = [tt.read_run(paths[k], lab, 20.0) for k, lab in zip(('base', 'mid', 'final'), labels)]
        cls.polys = [[], tt.read_polygons(paths['polys_mid']), tt.read_polygons(paths['polys_final'])]
        cls.pop = tt.match_all(cls.runs)
        cls.review = tt.read_review(paths['review'])
        cls.exp = expected()

    def test_hotspot_strong_corridors(self):
        final = self.polys[-1]
        sh = self.pop['shared']
        self.assertEqual(json_diff(self.exp['hotspot'], tt.hotspot(tt.newly_under(sh, 5.0), final)), [])
        self.assertEqual(json_diff(self.exp['strong'], tt.strongest(final)), [])
        self.assertEqual(json_diff(self.exp['corridors'], tt.corridors(final, sh, self.review)), [])

    def test_map_data_and_counts(self):
        base, final = self.runs[0], self.runs[-1]
        ours = {'base': [tt.map_leg(x) for x in base['legs'] if tt.is_flagged(x, 20.0)],
                'final': [tt.map_leg(x) for x in final['legs'] if tt.is_flagged(x, 20.0)]}
        wrapped_exp, wrapped_ours = {'flagged': self.exp['flagged']}, {'flagged': ours}
        ignore = null_km_paths(wrapped_exp, wrapped_ours)
        self.assertEqual(ignore, ['.flagged.final[0][9]'])          # the flagged leg with no distance
        self.assertEqual(json_diff(wrapped_exp, wrapped_ours, ignore=ignore), [])
        self.assertEqual(json_diff(self.exp['bounds'], tt.map_bounds(base, final)), [])
        counts = [tt.poly_count(lab, p, g) for lab, p, g in zip(self.exp['meta']['labels'], self.polys,
                                                                 [False, True, True])]
        self.assertEqual(json_diff(self.exp['poly_counts'], counts), [])


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_callouts -v`
Expected: ERROR, `AttributeError: module 'travel_tuning' has no attribute 'corridor_groups'` (and the other names).

- [ ] **Step 3: Append the callouts and map data**

```python
# ---------------------------------------------------------------------------------------------------------------
#  Polygon callouts, corridors, map data (inventory 5)
# ---------------------------------------------------------------------------------------------------------------
def read_review(path):
    """The final run's polygon review CSV keyed by Polygon (trimmed, last row wins): (route, note) strings."""
    out = {}
    if not path:
        return out
    with open(path, encoding='utf-8-sig', errors='replace', newline='') as fh:
        for r in csv.DictReader(fh):
            k = (r.get('Polygon') or '').strip()
            if k:
                out[k] = ((r.get('GBs / Route') or '').strip(), (r.get('Note') or '').strip())
    return out


def hotspot(newu, final_polys):
    hits = {}
    for _, b in newu:
        for poly, _, _ in crossings(b, final_polys):
            hits[poly['id']] = hits.get(poly['id'], 0) + 1
    if not hits:
        return None
    top_id, legs = min(hits.items(), key=lambda kv: (-kv[1],) + text_key(kv[0]))
    tp = next(p for p in final_polys if p['id'].casefold() == top_id.casefold())
    share = pct(legs, len(newu))
    return {'id': top_id, 'legs': legs, 'of': len(newu), 'pct': share, 'w': tp['w'], 'desc': tp['desc'],
            'callout': share >= HOTSPOT_CALLOUT_PCT}


def strongest(final_polys):
    lo = sorted(final_polys, key=lambda p: (p['w'],) + text_key(p['id']))[0]
    hi = sorted(final_polys, key=lambda p: (-p['w'],) + text_key(p['id']))[0]
    return {'low': {'id': lo['id'], 'w': lo['w'], 'desc': lo['desc']},
            'high': {'id': hi['id'], 'w': hi['w'], 'desc': hi['desc']}}


def corridor_groups(final_polys):
    """Corridor polygons grouped by group name case-insensitively (first spelling kept), sorted by name
    case-insensitively: pwsh 7's Group-Object order (spec 3.2 rule 2)."""
    groups = {}
    for p in final_polys:
        if p['gtype'] == 'Corridor':
            groups.setdefault(p['group'].casefold(), (p['group'], []))[1].append(p)
    return [groups[k] for k in sorted(groups, key=lambda k: text_key(groups[k][0]))]


def corridors(final_polys, shared, review):
    out = []
    for name, gp in corridor_groups(final_polys):
        n, sb, sf, sg = 0, 0.0, 0.0, 0.0
        techs = set()
        for a, b in shared:
            inside = 0.0
            for _, t0, t1 in crossings(b, gp):
                inside += t1 - t0
            if inside >= CORRIDOR_MIN_SHARE:
                n += 1
                sb += a['p']
                sf += b['p']
                sg += b['g']
                for t in (a['res_name'], b['res_name']):
                    if t:
                        techs.add(t)
        route = ''
        for p in gp:
            if p['id'] in review:
                route = review[p['id']][0] or review[p['id']][1]
                if route:
                    break
        if not route:
            parts = gp[0]['desc'].split('|')
            if len(parts) > 1:
                route = parts[1].strip()
        out.append({'group': name, 'polygons': len(gp),
                    'weightings': ', '.join(net_fixed2(w) for w in sorted({p['w'] for p in gp})),
                    'route': route, 'legs': n, 'techs': sorted(techs),
                    'base': r1(sb / n) if n else None, 'final': r1(sf / n) if n else None,
                    'google': r1(sg / n) if n else None})
    return out


def overlap_warning(n):
    return ('%d leg %s inside more than one polygon; the first polygon in the file is used.'
            % (n, 'piece falls' if n == 1 else 'pieces fall'))


def map_leg(leg):
    """[la1, lo1, la2, lo2, d, p, g, technician, route, km]: km is None when the leg has no distance
    (deviation 1: the script wrote 0.0)."""
    return [ps_round(leg['la1'], 5), ps_round(leg['lo1'], 5), ps_round(leg['la2'], 5), ps_round(leg['lo2'], 5),
            r1(leg['d']), r1(leg['p']), r1(leg['g']), leg['res_name'], route_of(leg),
            None if leg['km'] is None else r1(leg['km'])]


def map_bounds(*runs):
    bs, bn, bw, be = 90.0, -90.0, 180.0, -180.0
    for run in runs:
        for leg in run['legs']:
            for la in (leg['la1'], leg['la2']):
                if la < bs:
                    bs = la
                if la > bn:
                    bn = la
            for lo in (leg['lo1'], leg['lo2']):
                if lo < bw:
                    bw = lo
                if lo > be:
                    be = lo
    return [bs, bw, bn, be]


def poly_count(label, polys, given):
    types = {}
    for p in polys:
        types.setdefault(p['gtype'].casefold(), [p['gtype'], 0])[1] += 1
    by_type = sorted(types.values(), key=lambda tn: text_key(tn[0]))
    return {'label': label, 'total': len(polys) if given else 0, 'by_type': [{'type': t, 'n': n} for t, n in by_type]}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `python3 -m unittest tests.travel_tuning.test_callouts -v`
Expected: 9 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add travel/travel_tuning.py tests/travel_tuning/test_callouts.py
git status --short
git commit -m "Travel tuning (Python): hotspot, strongest weightings, corridors in pwsh 7 order, map data

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 7: Report data, the page template and the command

**Files:**
- Create: `travel/travel_tuning_template.html` (extracted from the script, Step 3)
- Modify: `travel/travel_tuning.py` (append)
- Test: `tests/travel_tuning/test_page.py`

**Interfaces:**
- Consumes: everything above.
- Produces: `build_data(runs, polys, poly_given, opts, review, notes_html, warnings, page_warnings, set_stage=...) -> dict` (the JSON of inventory 6 plus `meta.warnings` and `meta.page_warnings`); `libs_html(offline, lib_dir) -> str`; `render_html(template, data, libs) -> str`; `summary_line(data) -> str`; `write_text(path, text)`; `main(argv=None) -> int`. Plan B (the web tool) runs `python3 travel/travel_tuning.py --manifest <csv> --out <html> --json <json> ...` and reads `meta.warnings`, `timeline`, `acc.pair` and `meta.shared` from the JSON.

- [ ] **Step 1: Write the failing tests**

`tests/travel_tuning/test_page.py`:

```python
"""The whole command on the synthetic set against the script, the page template, warnings, summary and errors."""
import json
import os
import re
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import (tt, tmpdir, write_synthetic, synthetic_argv, expected, json_diff, null_km_paths,  # noqa: E402
                         page_json, run_main, OURS_ONLY, SCRIPT)

TEMPLATE = Path(tt.__file__).with_name('travel_tuning_template.html')
WARNINGS = [
    "The run labelled 'Grid B (baseline v2)' is run 3 of 3. Runs go baseline first, final last, so this report treats "
    "'Baseline' as the baseline and every before and after figure reads backwards. Put the baseline run first.",
    'Grid B (baseline v2): 1 polygon is not an axis-aligned rectangle; the report uses the bounding rectangle of each, '
    'which covers more ground than the polygon.',
    'Model check Baseline to Grid A: PSO moved as predicted on only 1 of 4 legs (25%, under 80%). The polygon import '
    'may not be what PSO used, or something besides the weightings changed between these runs; check before reading '
    'the polygon results.',
    'Model check Grid A to Grid B (baseline v2): PSO moved as predicted on only 1 of 7 legs (14.3%, under 80%). The '
    'polygon import may not be what PSO used, or something besides the weightings changed between these runs; check '
    'before reading the polygon results.',
    '9 leg pieces fall inside more than one polygon; the first polygon in the file is used.',
]
SUMMARY = ('summary: Baseline to Grid B (baseline v2): flagged 5 to 3, total error 151 to 106 min, 4 fixed, '
           '2 newly broken')


def script_template():
    lines = SCRIPT.read_text(encoding='utf-8').split('\n')
    start = lines.index("$Template = @'") + 1
    return '\n'.join(lines[start:lines.index("'@", start)]) + '\n'


class SyntheticRunTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.paths = write_synthetic(tmpdir())
        cls.out = tmpdir()
        cls.html = os.path.join(cls.out, 'report.html')
        cls.json = os.path.join(cls.out, 'report.json')
        cls.code, cls.stdout, cls.stderr = run_main(synthetic_argv(cls.paths) + ['--out', cls.html, '--json', cls.json])
        cls.data = json.loads(Path(cls.json).read_text(encoding='utf-8'))
        cls.page = Path(cls.html).read_text(encoding='utf-8')

    def test_exit_summary_and_warnings(self):
        self.assertEqual(self.code, 0, self.stderr)
        self.assertEqual(self.stdout.strip().split('\n')[-1], SUMMARY)
        self.assertEqual(self.stderr, ''.join('warning: %s\n' % w for w in WARNINGS))
        self.assertEqual(self.data['meta']['warnings'], WARNINGS)
        self.assertEqual(self.data['meta']['page_warnings'], [WARNINGS[1], WARNINGS[4]])

    def test_report_data_matches_the_script_value_by_value(self):
        exp = expected()
        ignore = list(OURS_ONLY) + null_km_paths(exp, self.data)
        self.assertEqual(json_diff(exp, self.data, ignore=ignore), [])

    def test_page_embeds_the_same_data_with_lt_escaped(self):
        self.assertEqual(page_json(self.page), self.data)
        block = self.page.split('<script type="application/json" id="data">', 1)[1].split('</script>', 1)[0]
        self.assertNotIn('<', block)
        self.assertIn('Synthetic \\u003ctuning>', block)
        self.assertIn('<title>Synthetic &lt;tuning&gt;</title>', self.page)

    def test_cdn_libraries_by_default(self):
        self.assertIn('<script src="%s"></script>' % tt.CHART_JS_URL, self.page)
        self.assertIn('<link rel="stylesheet" href="%s">' % tt.LEAFLET_CSS_URL, self.page)
        self.assertNotIn('__LIBS__', self.page)

    def test_offline_inlines_the_libraries(self):
        out = os.path.join(tmpdir(), 'offline.html')
        code, _, err = run_main(synthetic_argv(self.paths) + ['--out', out, '--offline'])
        self.assertEqual(code, 0, err)
        page = Path(out).read_text(encoding='utf-8')
        self.assertNotIn('<script src=', page)
        self.assertNotIn('cdnjs.cloudflare.com/ajax/libs/Chart.js', page.split('<main id="app">')[0])
        self.assertTrue(page_json(page)['meta']['offline'])
        lib = Path(tt.HERE, 'lib', 'leaflet.min.css').read_text(encoding='utf-8')
        self.assertIn('<style>' + lib + '</style>', page)


class TemplateTests(unittest.TestCase):
    def setUp(self):
        self.t = TEMPLATE.read_text(encoding='utf-8')

    def test_verbatim_from_the_script_apart_from_two_additions(self):
        added = ['(M.page_warnings||[]).forEach(w=>{html+=`<div class="banner" data-warning>${esc(w)}</div>`;});\n']
        dev = self.t[self.t.index('\n<p id="deviations">'):self.t.index('</footer>`;')]
        self.assertEqual(self.t.replace(added[0], '').replace(dev, ''), script_template())

    def test_placeholders_once(self):
        for p in ('__TITLE__', '__LIBS__', '__DATA__'):
            self.assertEqual(self.t.count(p), 1, p)

    def test_deviations_listed_in_the_footer(self):
        dev = self.t[self.t.index('<p id="deviations">'):]
        for words in ('shows no distance on the map (the script showed 0 km)', 'warnings show at the top of this page',
                      'every &lt; character escaped'):
            self.assertIn(words, dev)

    def test_house_wording(self):
        self.assertNotIn('\u2014', self.t)
        self.assertNotIn('\u2013', self.t)
        self.assertIsNone(re.search(r'\bplaced\b', self.t, re.I))

    @unittest.skipUnless(shutil.which('node'), 'node not installed')
    def test_page_script_parses(self):
        scripts = re.findall(r'<script>(.*?)</script>', self.t, re.S)
        path = os.path.join(tmpdir(), 'page.js')
        Path(path).write_text('\n'.join(scripts), encoding='utf-8')
        r = subprocess.run(['node', '--check', path], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)


class CommandTests(unittest.TestCase):
    def setUp(self):
        self.paths = write_synthetic(tmpdir())
        self.out = os.path.join(tmpdir(), 'r.html')

    def test_runs_form_and_default_generated_time(self):
        code, out, err = run_main(['--runs', self.paths['base'], self.paths['final'], '--labels', 'Prod', 'Tuned',
                                   '--polygons', '', self.paths['polys_final'], '--out', self.out, '--json',
                                   self.out + '.json'])
        self.assertEqual(code, 0, err)
        meta = json.loads(Path(self.out + '.json').read_text(encoding='utf-8'))['meta']
        self.assertRegex(meta['generated'], r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$')
        self.assertEqual((meta['labels'], meta['have_polys'], meta['base_misplaced']), (['Prod', 'Tuned'], True, None))
        self.assertTrue(out.strip().split('\n')[-1].startswith('summary: Prod to Tuned: flagged 5 to 3, '))

    def test_failures_name_the_stage(self):
        bad_xml = os.path.join(tmpdir(), 'bad.xml')
        Path(bad_xml).write_text('<DsSystemData>', encoding='utf-8')
        empty = os.path.join(tmpdir(), 'empty.csv')
        Path(empty).write_text('"Resource ID"\n', encoding='utf-8')
        cases = [
            (['--runs', self.paths['base'], '--out', self.out],
             'Stopped during: reading the arguments: Give at least two run CSVs'),
            (['--runs', self.paths['base'], self.paths['final'], '--generated', 'today', '--out', self.out],
             'Stopped during: reading the arguments: --generated must look like "2026-10-18 14:05".'),
            (['--runs', self.paths['base'], empty, '--labels', 'Prod', 'Broken', '--out', self.out],
             'Stopped during: reading Broken: Broken (empty.csv) has no rows.'),
            (['--runs', self.paths['base'], self.paths['final'], '--polygons', '', bad_xml, '--out', self.out],
             'Stopped during: reading the polygon files: The polygon file bad.xml is not valid XML'),
            (['--runs', self.paths['base'], self.paths['final'], '--template', '/nope.html', '--out', self.out],
             'Stopped during: reading the arguments: The page template nope.html was not found.'),
            (['--runs', self.paths['base'], self.paths['final'], '--tiles', 'carto', '--out', self.out],
             'Stopped during: reading the arguments: argument --tiles: invalid choice'),
            (['--runs', self.paths['base'], self.paths['final'], '--offline', '--lib-dir', tmpdir(), '--out', self.out],
             'Stopped during: reading the arguments: --offline needs chart.umd.min.js in the lib folder'),
        ]
        for argv, msg in cases:
            code, _, err = run_main(argv)
            self.assertEqual(code, 1, argv)
            self.assertTrue(err.startswith(msg), err)
        self.assertFalse(os.path.exists(self.out))

    def test_a_run_with_every_leg_excluded(self):
        # review focus: accepted as in the script; the common-legs check then reads 0% and warns
        broken = os.path.join(tmpdir(), 'broken.csv')
        lines = Path(self.paths['final']).read_text(encoding='utf-8-sig').split('\n')
        Path(broken).write_text('\n'.join([lines[0]] + [ln[:ln.rindex(',')] + ',"bad"' for ln in lines[1:] if ln]),
                                encoding='utf-8')
        code, out, err = run_main(['--runs', broken, self.paths['final'], '--out', self.out, '--json', self.out + '.json'])
        self.assertEqual(code, 0, err)
        data = json.loads(Path(self.out + '.json').read_text(encoding='utf-8'))
        self.assertEqual((data['runs'][0]['legs'], data['runs'][0]['ex_validation'], data['meta']['shared']), (0, 12, 0))
        self.assertEqual((data['meta']['common_pct'], data['meta']['common_warn']), (0.0, True))
        self.assertIn('warning: Only 0% of legs are common to every run (under 60%).', err)
        self.assertEqual(data['acc']['base']['avg_abs'], 0.0)

    def test_a_polygon_file_without_polygons(self):
        # review focus: a SYS export with no Polygon table gives empty polygon sections, not a failure
        sys_xml = os.path.join(tmpdir(), 'sys.xml')
        Path(sys_xml).write_text('<DsSystemData xmlns="http://360scheduling.com/Schema/DsSystemData.xsd">'
                                 '<Application_Data><id>1</id></Application_Data></DsSystemData>', encoding='utf-8')
        code, _, err = run_main(['--runs', self.paths['base'], self.paths['final'], '--polygons', '', sys_xml,
                                 '--out', self.out, '--json', self.out + '.json'])
        self.assertEqual(code, 0, err)
        data = json.loads(Path(self.out + '.json').read_text(encoding='utf-8'))
        self.assertEqual((data['meta']['have_polys'], data['polygons'], data['corridors'], data['hotspot'],
                          data['strong']), (True, [], [], None, None))
        self.assertEqual((data['timeline'][1]['polygons'], data['timeline'][1]['model_ok'],
                          data['timeline'][1]['model_n']), (0, 0, 0))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run them to see them fail**

Run: `python3 -m unittest tests.travel_tuning.test_page -v`
Expected: ERROR; `SyntheticRunTests` with `AttributeError: module 'travel_tuning' has no attribute 'main'`, `TemplateTests` with `FileNotFoundError` for `travel_tuning_template.html`.

- [ ] **Step 3: Extract the template verbatim and add the two page changes**

```bash
python3 - <<'EOF'
src = open('travel/Build-TravelTuningReport.ps1', encoding='utf-8').read().split('\n')
start = src.index("$Template = @'") + 1
end = src.index("'@", start)
assert (start + 1, end) == (624, 943), (start + 1, end)      # the here-string body, lines 624 to 943
t = '\n'.join(src[start:end]) + '\n'
banner = "Treat run-to-run comparisons with care.</div>`;\n"
assert t.count(banner) == 1
t = t.replace(banner, banner + "(M.page_warnings||[]).forEach(w=>{html+=`<div class=\"banner\" data-warning>${esc(w)}</div>`;});\n")
foot = "Map tiles &copy; OpenStreetMap contributors.</p></footer>`;"
assert t.count(foot) == 1
t = t.replace(foot, "Map tiles &copy; OpenStreetMap contributors.</p>\n<p id=\"deviations\"><b>Built by travel_tuning.py.</b> It follows the Windows script Build-TravelTuningReport.ps1 with three differences: a flagged leg with no recorded distance shows no distance on the map (the script showed 0 km); warnings show at the top of this page as well as in the console; and the report data is embedded with every &lt; character escaped, so no value can break the page.</p></footer>`;")
open('travel/travel_tuning_template.html', 'w', encoding='utf-8').write(t)
EOF
```

The diff against the script's here-string is exactly two places: one added line after the `common_warn` banner, and the `deviations` paragraph before `</footer>`. Nothing else in the page changes (the page-side arithmetic stays in JavaScript, spec 3.2). Note that the page's own fallback notes still say "rebuild it with -Offline"; that is the script's wording, kept verbatim.

- [ ] **Step 4: Append the report data, page and command**

```python
# ---------------------------------------------------------------------------------------------------------------
#  The report data, the page and the command
# ---------------------------------------------------------------------------------------------------------------
def build_data(runs, polys, poly_given, opts, review, notes_html, warnings, page_warnings,
               set_stage=lambda s: None):
    """The JSON the page reads (inventory 6), plus meta.warnings and meta.page_warnings."""
    nr = len(runs)
    labels = [r['label'] for r in runs]
    base, final = runs[0], runs[-1]
    set_stage('matching legs across runs')
    pop = match_all(runs)
    if pop['common_warn']:
        warnings.append(common_warning(pop))
    set_stage('computing the metrics')
    th, small = opts.review_minutes, opts.small_minutes
    shared = pop['shared']
    newu = newly_under(shared, small)
    tl = timeline(runs, pop, [len(polys[i]) if poly_given[i] else 0 for i in range(nr)], th, small)
    overlaps = [0]
    model_warn, model_texts = apply_model_checks(tl, pop, polys, poly_given, opts, overlaps, set_stage)
    warnings.extend(model_texts)
    final_polys = polys[-1]
    hot = strong = None
    corr = []
    if final_polys:
        set_stage('polygon callouts')
        hot = hotspot(newu, final_polys)
        strong = strongest(final_polys)
        set_stage('corridor table')
        corr = corridors(final_polys, shared, review)
        if overlaps[0]:
            warnings.append(overlap_warning(overlaps[0]))
            page_warnings.append(overlap_warning(overlaps[0]))
    set_stage('building the report data')
    meta = {'title': opts.title, 'client': opts.client, 'dataset': opts.dataset, 'generated': opts.generated,
            'labels': labels, 'files': [r['file'] for r in runs], 'threshold': th, 'small': small,
            'tol_min': opts.model_tol_min, 'tol_pct': opts.model_tol_pct, 'shared': len(shared),
            'common': len(pop['common']), 'common_pct': pop['common_pct'], 'common_warn': pop['common_warn'],
            'common_warn_pct': COMMON_WARN_PCT, 'base_misplaced': misplaced_baseline(labels),
            'model_warn': model_warn, 'model_warn_pct': MODEL_WARN_PCT, 'have_polys': any(poly_given),
            'offline': bool(opts.offline), 'tiles': opts.tiles, 'long_leg_km': opts.long_leg_km,
            'corridor_share': CORRIDOR_MIN_SHARE, 'region': opts.region_label, 'check_rows': CHECK_ROWS,
            'hotspot_pct': HOTSPOT_CALLOUT_PCT, 'warnings': list(warnings), 'page_warnings': list(page_warnings)}
    return {
        'meta': meta,
        'runs': [{'label': r['label'], 'file': r['file'], 'rows': r['rows'], 'legs': len(r['legs']),
                  'excluded': r['excluded'], 'ex_google': r['ex_google'], 'ex_pso': r['ex_pso'],
                  'ex_validation': r['ex_validation']} for r in runs],
        'timeline': tl,
        'acc': {'base': accuracy([a for a, _ in shared], small), 'final': accuracy([b for _, b in shared], small),
                'newly_under': len(newu), 'newly_under_5_10': sum(1 for _, b in newu if b['d'] > -10),
                'pair': pair_stats(shared, th)},
        'bands': distance_bands(shared),
        'gb': gb_summary(base['legs'], final['legs'], th),
        'worst': worst_offenders(shared, th),
        'check': [check_these(base, th), check_these(final, th)],
        'corridors': corr, 'hotspot': hot, 'strong': strong,
        'shared': [[r1(a['g']), r1(a['p']), r1(b['p'])] for a, b in shared],
        'flagged': {'base': [map_leg(leg) for leg in base['legs'] if is_flagged(leg, th)],
                    'final': [map_leg(leg) for leg in final['legs'] if is_flagged(leg, th)]},
        'bounds': map_bounds(base, final),
        'polygons': [{'id': p['id'], 'w': p['w'], 'desc': p['desc'], 'group': p['group'], 'gtype': p['gtype'],
                      's': p['s'], 'n': p['n'], 'west': p['west'], 'east': p['east'],
                      'note': review[p['id']][1] if p['id'] in review else ''} for p in final_polys],
        'poly_counts': [poly_count(labels[i], polys[i], poly_given[i]) for i in range(nr)],
        'text': {'benchmark': TEXT_BENCHMARK, 'overunder': TEXT_OVER_UNDER, 'staywrong': TEXT_STAY_WRONG},
        'notes': notes_html,
    }


def libs_html(offline, lib_dir):
    if not offline:
        return ('<link rel="stylesheet" href="%s">\n<script src="%s"></script>\n<script src="%s"></script>'
                % (LEAFLET_CSS_URL, CHART_JS_URL, LEAFLET_JS_URL))

    def read(name):
        with open(os.path.join(lib_dir, name), encoding='utf-8') as fh:
            return fh.read()
    return '<style>%s</style>\n<script>%s</script>\n<script>%s</script>' % (
        read('leaflet.min.css'), read('chart.umd.min.js').replace('</script', '<\\/script'),
        read('leaflet.min.js').replace('</script', '<\\/script'))


def render_html(template, data, libs):
    """__TITLE__, __LIBS__ and __DATA__ filled in. Every '<' in the data is written as \\u003c (deviation 3), so no
    value can close the script block."""
    blob = json.dumps(data, ensure_ascii=False, separators=(',', ':')).replace('<', '\\u003c')
    blob = blob.replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
    head, sep, tail = template.partition('__DATA__')
    if not sep:
        raise TravelTuningError('The page template has no __DATA__ placeholder.')
    return head.replace('__TITLE__', esc(data['meta']['title']), 1).replace('__LIBS__', libs, 1) + blob + tail


def summary_line(data):
    tb, tf, pair = data['timeline'][0], data['timeline'][-1], data['acc']['pair']
    return ('%s to %s: flagged %s to %s, total error %s to %s min, %s fixed, %s newly broken'
            % (tb['label'], tf['label'], format(tb['flagged'], ','), format(tf['flagged'], ','),
               format(int(tb['total_min']), ','), format(int(tf['total_min']), ','),
               format(pair['fixed'], ','), format(pair['newly_broken'], ',')))


def write_text(path, text):
    tmp = path + '.tmp'
    try:
        with open(tmp, 'w', encoding='utf-8', newline='') as fh:
            fh.write(text)
        os.replace(tmp, path)
    except OSError as e:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise TravelTuningError('%s could not be written: %s.' % (os.path.basename(path), e.strerror or e))


def main(argv=None):
    if hasattr(sys.stderr, 'reconfigure'):
        sys.stderr.reconfigure(errors='backslashreplace')
    stage = ['reading the arguments']

    def set_stage(s):
        stage[0] = s
    try:
        args = build_parser().parse_args(argv)
        if args.generated is not None and not _GENERATED.match(args.generated):
            raise TravelTuningError('--generated must look like "2026-10-18 14:05".')
        args.generated = args.generated or dt.datetime.now().strftime('%Y-%m-%d %H:%M')
        if not os.path.isfile(args.template):
            raise TravelTuningError('The page template %s was not found.' % os.path.basename(args.template))
        if args.offline:
            for f in LIB_FILES:
                if not os.path.isfile(os.path.join(args.lib_dir, f)):
                    raise TravelTuningError('--offline needs %s in the lib folder next to this script. Download it '
                                            'from cdnjs (Chart.js 4.4.1, Leaflet 1.9.4) and put it there, or run '
                                            'without --offline.' % f)
        cwd = os.getcwd()
        labels, run_paths, poly_paths = resolve_runs(args, cwd)
        review_path = resolve_in(args.review_csv or '', cwd, 'review CSV')
        notes_path = resolve_in(args.notes or '', cwd, 'notes file')
        warnings, page_warnings = [], []
        bm = misplaced_baseline(labels)
        if bm:
            warnings.append("The run labelled '%s' is run %d of %d. Runs go baseline first, final last, so this report "
                            "treats '%s' as the baseline and every before and after figure reads backwards. Put the "
                            "baseline run first." % (bm['label'], bm['at'], len(labels), bm['first']))
        runs = []
        for label, path in zip(labels, run_paths):
            set_stage('reading ' + label)
            runs.append(read_run(path, label, args.review_minutes))
        set_stage('reading the polygon files')
        poly_given = [bool(p) for p in poly_paths]
        polys = [read_polygons(p) if p else [] for p in poly_paths]
        for label, ps in zip(labels, polys):
            w = non_rectangle_warning(label, ps)
            if w:
                warnings.append(w)
                page_warnings.append(w)
        review = read_review(review_path)
        notes_html = ''
        if notes_path:
            with open(notes_path, encoding='utf-8-sig', errors='replace', newline='') as fh:
                notes_html = markdown_to_html(fh.read())
        data = build_data(runs, polys, poly_given, args, review, notes_html, warnings, page_warnings, set_stage)
        set_stage('writing the report')
        with open(args.template, encoding='utf-8') as fh:
            page = render_html(fh.read(), data, libs_html(args.offline, args.lib_dir))
        if args.json:
            write_text(args.json, json.dumps(data, indent=1, ensure_ascii=False))
        write_text(args.out, page)
        for w in data['meta']['warnings']:
            sys.stderr.write('warning: %s\n' % w)
        print('summary: %s' % summary_line(data))
        return 0
    except (_ArgError, TravelTuningError) as e:
        sys.stderr.write('Stopped during: %s: %s\n' % (stage[0], e))
        return 1
    except Exception as e:  # noqa: BLE001 - every other failure is exit 1 with the stage
        sys.stderr.write('Stopped during: %s\n%s: %s\n' % (stage[0], type(e).__name__, e))
        return 1


if __name__ == '__main__':
    sys.exit(main())
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `python3 -m unittest discover -s tests/travel_tuning -t .`
Expected: 91 tests, OK (the node check runs when `node` is installed; it is on this Mac).

- [ ] **Step 6: Look at the page once**

```bash
D=$(mktemp -d)
python3 tests/travel_tuning/tt_fixtures.py "$D" >/dev/null
python3 travel/travel_tuning.py --manifest "$D/manifest.csv" --review-csv "$D/review.csv" \
  --notes "$D/notes.md" --title 'Synthetic tuning' --out "$D/report.html"
open "$D/report.html"
```

Expected: five `warning:` lines on stderr, then `summary: Baseline to Grid B (baseline v2): flagged 5 to 3, total error 151 to 106 min, 4 fixed, 2 newly broken`. In the browser: banners for the misplaced baseline, both model checks, the non-rectangle polygon and the overlaps; the map with rectangles; the footer's "Built by travel_tuning.py" paragraph; the Consultant notes table. No console errors.

- [ ] **Step 7: Commit**

```bash
git add travel/travel_tuning.py travel/travel_tuning_template.html tests/travel_tuning/test_page.py
git status --short
git commit -m "Travel tuning (Python): report data, the script's page template with warnings and deviations, the command

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 8: Golden test on the real customer run

**Files:**
- Test: `tests/travel_tuning/test_golden.py`

**Interfaces:**
- Consumes: `main` (Task 7), `run_main`, `page_json`, `json_diff`, `null_km_paths`, `OURS_ONLY` (Task 2).
- Produces: nothing new.

The golden folder `~/pso-data/ACME/1013/` is local only. It holds the two run CSVs, `PSO_Sys_pass_1.xml` (251 polygons), Ish's Windows report `travel_tuning_report_20261013_2235.html` and `golden/pwsh_report.html`, which was made with:

```bash
cd ~/pso-data/ACME/1013
pwsh -NoProfile -File "/Users/idawoodjee/Documents/Projects/PSO Tools/travel/Build-TravelTuningReport.ps1" \
  -Runs plan_travels_output_north_initial.csv,plan_travels_output_pass2.csv -Labels 'Prod (no polygons)','Grid v6' \
  -PolygonXml '',PSO_Sys_pass_1.xml -ClientName Acme -DatasetName NORTH_TRAVEL -OutputPath golden/pwsh_report.html
```

The Windows and pwsh reports agree on everything except `meta.generated` and the order of the four corridor groups with no trips (`corridors[14..17]`); Python must produce pwsh 7's order (`C15, C16, C17, C18`). When this plan was written the Python port matched the pwsh JSON on every value (only `meta.warnings` and `meta.page_warnings` extra), in 0.26 s against the script's 2.8 s, and the data has no flagged leg without a distance.

- [ ] **Step 1: Write the test**

`tests/travel_tuning/test_golden.py`:

```python
"""Golden run on real customer data (local only, never committed).

Runs when ~/pso-data/ACME/1013/ holds the two run CSVs, the polygon file, Ish's Windows report and the pwsh 7
report (golden/pwsh_report.html, made with the command in the spec); skips otherwise.

    python3 -m unittest tests.travel_tuning.test_golden
"""
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tt_fixtures import tmpdir, run_main, page_json, json_diff, null_km_paths, OURS_ONLY  # noqa: E402

DATA = Path.home() / 'pso-data' / 'ACME' / '1013'
BASE = DATA / 'plan_travels_output_north_initial.csv'
FINAL = DATA / 'plan_travels_output_pass2.csv'
POLYS = DATA / 'PSO_Sys_pass_1.xml'
WINDOWS = DATA / 'travel_tuning_report_20261013_2235.html'
PWSH = DATA / 'golden' / 'pwsh_report.html'
NEEDED = (BASE, FINAL, POLYS, WINDOWS, PWSH)


@unittest.skipUnless(all(p.exists() for p in NEEDED), 'golden data not in %s' % DATA)
class GoldenTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        out = tmpdir()
        cls.json_path = os.path.join(out, 'golden.json')
        cls.code, cls.stdout, cls.stderr = run_main([
            '--runs', str(BASE), str(FINAL), '--labels', 'Prod (no polygons)', 'Grid v6', '--polygons', '', str(POLYS),
            '--client', 'Acme', '--dataset', 'NORTH_TRAVEL', '--out', os.path.join(out, 'golden.html'),
            '--json', cls.json_path, '--generated', '2026-10-13 22:35'])
        cls.ours = json.loads(Path(cls.json_path).read_text(encoding='utf-8'))
        cls.pwsh = page_json(PWSH.read_text(encoding='utf-8'))
        cls.windows = page_json(WINDOWS.read_text(encoding='utf-8'))

    def test_run_ok(self):
        self.assertEqual(self.code, 0, self.stderr)
        self.assertEqual(self.stderr, '')                       # no warnings on this data
        self.assertEqual(self.stdout.strip().split('\n')[-1],
                         'summary: Prod (no polygons) to Grid v6: flagged 75 to 10, total error 8,294 to 5,889 min, '
                         '44 fixed, 0 newly broken')

    def test_every_value_matches_pwsh(self):
        ignore = list(OURS_ONLY) + ['.meta.generated'] + null_km_paths(self.pwsh, self.ours)
        diffs = json_diff(self.pwsh, self.ours, ignore=ignore)
        self.assertEqual(diffs[:20], [], '%d values differ' % len(diffs))

    def test_every_value_matches_windows_with_corridors_as_a_set(self):
        ignore = list(OURS_ONLY) + ['.corridors'] + null_km_paths(self.windows, self.ours)
        self.assertEqual(json_diff(self.windows, self.ours, ignore=ignore)[:20], [])
        key = lambda c: c['group']                              # noqa: E731
        self.assertEqual(json_diff(sorted(self.windows['corridors'], key=key), sorted(self.ours['corridors'], key=key)),
                         [])
        self.assertEqual([c['group'] for c in self.ours['corridors'][14:]], ['C15', 'C16', 'C17', 'C18'])

    def test_pinned_numbers(self):
        o = self.ours
        runs = o['runs']
        self.assertEqual((runs[0]['legs'], o['timeline'][0]['flagged']), (3463, 75))
        tf = o['timeline'][1]
        self.assertEqual((runs[1]['legs'], tf['flagged'], tf['fixed'], tf['newly_broken'], tf['model_ok'], tf['model_n']),
                         (3369, 10, 44, 0, 1055, 1056))
        self.assertEqual((o['meta']['shared'], o['acc']['base']['avg_abs'], o['acc']['final']['avg_abs']),
                         (2112, 2.58, 1.85))
        self.assertEqual((o['gb']['base_count'], len(o['gb']['cleared'])), (39, 30))
        self.assertEqual((o['hotspot']['id'], o['hotspot']['legs'], o['hotspot']['of']), ('G052', 9, 42))
        self.assertEqual(len(o['corridors']), 18)
        self.assertEqual((len(o['polygons']), o['poly_counts'][1]['by_type']),
                         (251, [{'type': 'Corridor', 'n': 82}, {'type': 'Grid', 'n': 160}, {'type': 'Other', 'n': 9}]))


if __name__ == '__main__':
    unittest.main()
```

- [ ] **Step 2: Run it**

Run: `python3 -m unittest tests.travel_tuning.test_golden -v`
Expected: 4 tests, OK (or 4 skipped on a machine without the golden folder). If `test_every_value_matches_pwsh` fails, the message lists the first 20 differing paths; fix the code, never the expectation: the pwsh JSON is the reference.

- [ ] **Step 3: Commit**

```bash
git add tests/travel_tuning/test_golden.py
git status --short
git commit -m "Travel tuning (Python): golden test against the pwsh and Windows reports of the 2026-10-13 run

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

---

### Task 9: CLAUDE.md and the final check

**Files:**
- Modify: `CLAUDE.md` (repo structure under `travel/` and `tests/`, the parity exceptions paragraph)

**Interfaces:**
- Consumes: the finished module and tests.
- Produces: documentation only.

- [ ] **Step 1: Update the repo structure**

In `CLAUDE.md`, replace

```
  travel/
    Build-TravelTuningReport.ps1  baseline vs tuned travel CSVs -> one HTML report (PowerShell only, no Python twin)
    lib/                        Chart.js 4.4.1 and Leaflet 1.9.4, inlined by -Offline
```

with

```
  travel/
    travel_tuning.py            baseline vs tuned plan travels CSVs (+ the polygon file of each run) -> one HTML
                                report and its JSON (Python only; the reference for Build-TravelTuningReport.ps1, run
                                by the web app). Tests: python3 -m unittest discover -s tests/travel_tuning -t .
    travel_tuning_template.html the script's page, verbatim, plus a warnings banner and a footer note of the deviations
    Build-TravelTuningReport.ps1  the Windows version (unchanged; not in the parity rule, see below)
    lib/                        Chart.js 4.4.1 and Leaflet 1.9.4, inlined by -Offline / --offline
```

and after the `plan_travels/` entry under `tests/` add

```
    travel_tuning/              travel tuning tests on a synthetic set written at test time, checked against a pwsh
                                capture of the script (fixtures/synthetic_pwsh.json, refresh with
                                python3 tests/travel_tuning/tt_fixtures.py --capture); test_golden.py also checks the
                                ~/pso-data/ACME/1013/ run against the pwsh and Windows reports when present
```

- [ ] **Step 2: Update the parity exceptions paragraph**

Replace

```
The activity trace and plan travels are the exceptions: `activity-trace/trace_activity.py` and
`plan-travels/plan_travels.py` are the reference implementations (run by the web app), and
`Build-AppointmentTimeline.ps1`, `Get-PsoTaskTimeline.ps1` and `Build-PlanTravelsReport.ps1` stay unchanged as their
Windows versions until the web app replaces them, so Python changes there do not ship to PowerShell. Plan travels
keeps one link to its script: the golden test compares every column of the 30 with a pwsh run of the script.
```

with

```
The activity trace, plan travels and travel tuning are the exceptions: `activity-trace/trace_activity.py`,
`plan-travels/plan_travels.py` and `travel/travel_tuning.py` are the reference implementations (run by the web app),
and `Build-AppointmentTimeline.ps1`, `Get-PsoTaskTimeline.ps1`, `Build-PlanTravelsReport.ps1` and
`Build-TravelTuningReport.ps1` stay unchanged as their Windows versions until the web app replaces them, so Python
changes there do not ship to PowerShell. Plan travels keeps one link to its script: the golden test compares every
column of the 30 with a pwsh run of the script. Travel tuning keeps two: the synthetic capture and the golden test
compare the report data value by value with pwsh runs of the script (only meta.generated, the null km of a flagged
leg with no distance, and our meta.warnings / meta.page_warnings differ).
```

- [ ] **Step 3: Run everything once more, on Python 3.9 too**

```bash
python3 -m unittest discover -s tests/travel_tuning -t .
/usr/bin/python3 --version && /usr/bin/python3 -m unittest discover -s tests/travel_tuning -t .
python3 -m unittest discover -s tests/plan_travels -t .          # untouched neighbour still green
python3 -c "t = open('travel/travel_tuning.py').read() + open('travel/travel_tuning_template.html').read(); print('dashes found' if '\u2014' in t or '\u2013' in t else 'no dashes')"
git diff --stat main -- travel/Build-TravelTuningReport.ps1 make-windows-bundle.sh   # must print nothing
```

Expected: 91 tests OK on both interpreters (4 golden tests skip only where the data is missing), plan travels OK, `no dashes`, and no diff for the script or the bundle builder.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git status --short
git commit -m "CLAUDE.md: travel tuning Python reference, its tests and the parity exception

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01TMSuKwN5UjNYXzPgUfXPFu"
```

No Windows test is needed: no PowerShell file changed. What Ish checks before the merge: the page from Task 7 Step 6 run on `~/pso-data/ACME/1013/` (the golden command in Task 8's test, with `--out ~/pso-data/ACME/1013/golden/python_report.html`), side by side with his Windows report.

---

## Self-review against the spec

- **3.1 Command:** every flag in the spec is in `build_parser` (Task 1), plus `--template` and `--lib-dir` (test seams, defaulting next to the script). Manifest columns, two-run minimum, defaults equal to the script's parameters, `--json`, `--generated` (validated, default now local) and the message and exit rules: Tasks 1 and 7. The five warnings: misplaced baseline (Task 7 `main`), common legs (Task 3, emitted in `build_data`), model check (Task 5), overlaps (Task 6, emitted in `build_data`), non-rectangles (Task 5, emitted in `main`); all tested in `test_exit_summary_and_warnings`. Summary line: Task 7, golden text pinned in Task 8.
- **3.2 Rules:** inventory 2 and 3 (Task 2), 4 (Task 3), 5 (Tasks 4 to 6), 6 (Task 7 `build_data`, key order as the script's), 7 (template, Task 7), 8 (Task 1). Pinned behaviours and deviations: table above.
- **3.3 Tests:** golden with the stated ignores and pinned numbers (Task 8); synthetic halves (Task 1 and 2), repeated keys (Task 3), old CSV layout (Task 2), Clip and Predict on overlapping rectangles (Task 5), non-rectangle warning (Task 5 and 7), manifest and its errors (Task 1), Markdown subset (Task 1); the pwsh run on the synthetic polygon set kept as a fixture (Task 2), including the review CSV path (corridor routes and map notes, Task 6) and notes (Task 7).
- **Out of scope, confirmed untouched:** the PowerShell script, the bundle, the web tool (plan B), writing or suggesting weightings.
