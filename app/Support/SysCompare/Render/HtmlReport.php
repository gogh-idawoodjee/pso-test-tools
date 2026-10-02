<?php

namespace App\Support\SysCompare\Render;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonResult;
use App\Support\SysCompare\ComparisonRow;
use App\Support\SysCompare\ComparisonTab;
use App\Support\SysCompare\EffectiveParameterResolver;
use App\Support\SysCompare\EnvironmentVersion;
use App\Support\SysCompare\VersionReport;

/**
 * A single self-contained HTML page: inline CSS and JS, no external requests.
 * Shows only the differing rows by default; the CSVs hold every row.
 * Every value is escaped, because parameter values contain {}, <, | and URLs.
 */
class HtmlReport
{
    public const string FILE_NAME = 'PSO_Compare_Summary.html';

    private const string CSS = <<<'CSS'
body{font-family:Arial,Helvetica,sans-serif;font-size:13px;margin:24px;color:#1a1a1a}
h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 8px;border-bottom:2px solid #1f3864;padding-bottom:3px}
.meta{color:#595959;font-size:12px;margin-bottom:12px}
table{border-collapse:collapse;margin:6px 0 14px;width:100%}
th{background:#1f3864;color:#fff;text-align:left;padding:5px 7px;font-size:12px;position:sticky;top:0}
td{border:1px solid #bfbfbf;padding:4px 7px;vertical-align:top;word-break:break-word}
td.diff{background:#ffe699} td.muted{color:#7f7f7f;font-style:italic}
td.k{word-break:normal;overflow-wrap:normal}
.vbanner{padding:14px 18px;font-size:18px;font-weight:bold;border-radius:4px;margin:8px 0 12px}
.vbanner small{display:block;font-size:13px;font-weight:normal;margin-top:4px}
.vbanner.bad{background:#c00000;color:#fff;border:3px solid #7f0000}
.vbanner.ok{background:#2e7d32;color:#fff}
.vbanner.warn{background:#ffe699;color:#1a1a1a}
td.vok{background:#c6efce;color:#006100;font-weight:bold} td.vbad{background:#f4b6b6;color:#9c0006;font-weight:bold}
table.sortable thead th{cursor:pointer;user-select:none}
table.sortable thead th:hover{background:#2f4a80}
table.sortable thead th[data-dir="asc"]::after{content:" \25B2";font-size:10px}
table.sortable thead th[data-dir="desc"]::after{content:" \25BC";font-size:10px}
table.tally td,table.tally th{text-align:center} table.tally td:first-child,table.tally th:first-child{text-align:left}
details{margin:8px 0} summary{cursor:pointer;font-weight:bold;padding:4px 0}
ul{margin:4px 0 4px 18px;padding:0} li{margin:2px 0}
.note{background:#f2f2f2;border-left:4px solid #1f3864;padding:6px 10px;margin:6px 0}
CSS;

    private const string JS = <<<'JS'
(function () {
  function isNum(s) { return /^-?[0-9]+(\.[0-9]+)?%?$/.test(s); }
  function compare(a, b) {
    if (isNum(a) && isNum(b)) { return parseFloat(a) - parseFloat(b); }
    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
  }
  var tables = document.querySelectorAll('table.sortable');
  Array.prototype.forEach.call(tables, function (t) {
    var body = t.tBodies[0];
    if (!body) { return; }
    var heads = t.tHead.rows[0].cells;
    Array.prototype.forEach.call(body.rows, function (r, i) { r.setAttribute('data-i', i); });
    Array.prototype.forEach.call(heads, function (th, col) {
      th.title = 'Click to sort (click again to reverse, a third time to reset)';
      th.addEventListener('click', function () {
        var rows = Array.prototype.slice.call(body.rows);
        if (rows.length < 2 || rows[0].cells.length !== heads.length) { return; }
        var cur = th.getAttribute('data-dir');
        var next = cur === 'asc' ? 'desc' : (cur === 'desc' ? '' : 'asc');
        Array.prototype.forEach.call(heads, function (h) { h.removeAttribute('data-dir'); });
        if (next) { th.setAttribute('data-dir', next); }
        rows.sort(function (ra, rb) {
          var ia = Number(ra.getAttribute('data-i')), ib = Number(rb.getAttribute('data-i'));
          if (!next) { return ia - ib; }
          var c = compare(ra.cells[col].textContent.trim(), rb.cells[col].textContent.trim());
          if (c === 0) { return ia - ib; }
          return next === 'asc' ? c : -c;
        });
        rows.forEach(function (r) { body.appendChild(r); });
      });
    });
  });
})();
JS;

    public function render(ComparisonResult $result, bool $showSameRows = false): string
    {
        $html = [
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>PSO environment comparison</title><style>',
            self::CSS,
            '</style></head><body>',
            '<h1>PSO system data comparison</h1>',
            '<div class="meta">Generated '.$this->e($result->generatedAt->format('Y-m-d H:i')).' UTC | Baseline: '.$this->e($result->baselineName()).' | Amber cell = differs from baseline | Click a column header to sort</div>',
            $this->defaultsWarning($result),
            $this->versionSection($result),
            $this->environmentsSection($result),
            $this->scopeSection(),
            $this->tallySection($result),
            $this->quickReadSection($result),
        ];

        $open = ' open';

        foreach ($result->tabs as $tab) {
            $shown = $showSameRows ? $tab->rowCount() : count($tab->differingRows());

            $html[] = '<h2>'.$this->e($tab->title).'</h2>';
            $html[] = '<details'.$open.'><summary>'.$shown.' of '.$tab->rowCount().' rows shown (full data in '.$this->e($tab->csvFileName).')</summary>';
            $html[] = $this->tabTable($tab, $result, $showSameRows);
            $html[] = '</details>';
            $open = '';
        }

        $html[] = $this->tableCountsSection($result);
        $html[] = '<script>';
        $html[] = self::JS;
        $html[] = '</script></body></html>';

        return implode("\n", $html)."\n";
    }

    /**
     * Without the parameter catalog, unset parameters cannot be matched to their defaults and the
     * parameter comparison is raw. That must be impossible to miss.
     */
    private function defaultsWarning(ComparisonResult $result): string
    {
        if ($result->defaultsApplied) {
            return '';
        }

        return '<div class="vbanner warn">Parameter defaults were NOT applied<small>The parameter catalog (pso_parameters_reference.csv) was not available, so unset parameters show as (absent) and may simply be using their default. Parameter differences below are raw and overstate the real differences.</small></div>';
    }

    private function versionSection(ComparisonResult $result): string
    {
        $versions = $result->versions;
        $html = ['<h2>PSO version</h2>', $this->versionBanner($versions)];

        $html[] = '<table><thead><tr><th>Environment</th><th>Current version</th><th>Status</th><th>Last upgrade (UTC)</th><th>Upgraded from -&gt; to</th><th>Last patch / update (UTC)</th><th>Days since upgrade</th><th>Created (UTC)</th></tr></thead><tbody>';

        foreach ($versions->environments as $version) {
            $class = match ($version->status) {
                EnvironmentVersion::BEHIND => ' class="vbad"',
                EnvironmentVersion::LATEST => ' class="vok"',
                default => '',
            };

            $upgraded = $version->upgradedAt !== null ? $version->upgradedAt->format('Y-m-d H:i') : '-';
            $fromTo = $version->upgradedAt !== null ? $version->upgradedFrom.' -> '.$version->upgradedTo : '-';
            $patched = $version->patchedAt !== null ? $version->patchVersion.' on '.$version->patchedAt->format('Y-m-d H:i') : '-';

            $html[] = '<tr><td>'.$this->e($version->name).'</td><td'.$class.'>'.$this->e($version->current).'</td><td'.$class.'>'.$this->e($version->status).'</td>'
                .'<td>'.$this->e($upgraded).'</td><td>'.$this->e($fromTo).'</td><td>'.$this->e($patched).'</td>'
                .'<td>'.$this->e($version->daysSinceUpgrade !== null ? (string) $version->daysSinceUpgrade : '-').'</td>'
                .'<td>'.$this->e($version->createdAt?->format('Y-m-d H:i') ?? '-').'</td></tr>';
        }

        $html[] = '</tbody></table>';
        $html[] = '<div class="note">Taken from the System_Version table; all times are UTC. Current version = highest version recorded. Last upgrade = most recent "Upgrade from" record. Last patch = most recent "Update" record. The vendor-stamped SYSTEM_USER patch records carry the same timestamp in every environment, so they say nothing about when an environment was upgraded.</div>';
        $html[] = '<details><summary>Full version history (oldest first)</summary><table class="sortable"><thead><tr><th>Environment</th><th>When (UTC)</th><th>Version</th><th>Type</th><th>User</th></tr></thead><tbody>';

        foreach ($versions->environments as $version) {
            foreach ($version->history as $record) {
                $html[] = '<tr><td>'.$this->e($version->name).'</td><td>'.$this->e($record->stamp?->format('Y-m-d H:i') ?? '').'</td><td>'.$this->e($record->version).'</td><td>'.$this->e($record->type).'</td><td>'.$this->e($record->user).'</td></tr>';
            }
        }

        $html[] = '</tbody></table></details>';

        return implode("\n", $html);
    }

    private function versionBanner(VersionReport $versions): string
    {
        return match ($versions->bannerState()) {
            VersionReport::BANNER_MISMATCH => '<div class="vbanner bad">NOT ALL ENVIRONMENTS ARE ON THE SAME PSO VERSION<small>Highest version: '
                .$this->e($versions->highestVersion).'. Behind: '
                .$this->e(implode('; ', array_map(static fn (EnvironmentVersion $version): string => $version->name.' is on '.$version->current, $versions->behind()))).'. '
                .$this->e($versions->kindText).'</small></div>',
            VersionReport::BANNER_MATCH => '<div class="vbanner ok">All '.$versions->knownCount.' environments are on the same PSO version: '.$this->e($versions->highestVersion).'</div>',
            default => '<div class="vbanner warn">PSO version could not be compared: System_Version data is missing in '
                .$this->e($versions->missingNames === [] ? 'one or more environments' : implode(', ', $versions->missingNames)).'.</div>',
        };
    }

    private function environmentsSection(ComparisonResult $result): string
    {
        $realms = $this->openIdRealms($result);
        $html = ['<h2>Environments</h2><table><thead><tr><th>Name</th><th>File</th><th>Size</th><th>OpenIdAuthority realm</th></tr></thead><tbody>'];

        foreach ($result->environments as $index => $environment) {
            $html[] = '<tr><td>'.$this->e($environment->name).'</td><td>'.$this->e($environment->fileName).'</td><td>'
                .$this->e(number_format($environment->sizeBytes / 1048576, 1).' MB').'</td><td>'.$this->e($realms[$index] ?? '').'</td></tr>';
        }

        $html[] = '</tbody></table>';

        return implode("\n", $html);
    }

    /**
     * The last path segment of the DEFAULT OpenIdAuthority parameter, per environment.
     *
     * @return array<int, string>
     */
    private function openIdRealms(ComparisonResult $result): array
    {
        $realms = [];

        foreach ($result->tab('Parameters')?->rows() ?? [] as $row) {
            if ($row->keyValues[0] === 'DEFAULT' && $row->keyValues[1] === 'OpenIdAuthority') {
                foreach ($row->cellValues as $index => $value) {
                    $segments = explode('/', rtrim($value, '/'));
                    $realms[$index] = (string) end($segments);
                }
            }
        }

        return $realms;
    }

    private function scopeSection(): string
    {
        $items = [
            '<b>Compared:</b> profile parameters, exception types, groups and group permissions, organisation permissions, org-default list layouts, travel-time setup, profiles, terminology, exception type data, organisation record.',
            '<b>Not compared:</b> Users and all per-user tables - saved filters, screen settings, list layouts, and which groups, permissions and parameters are assigned to each user. The set of users differs between environments, so comparing them would mostly be noise. This also means group membership (who is in which group) is not compared, only what each group is allowed to do.',
            '<b>System_Version:</b> not compared row by row; it is summarised in the PSO version section instead.',
            '<b>Parameters:</b> a parameter missing from the export is using its default. The default comes from the parameter catalog and is shown as (default: x), and it counts as equal to an explicit value that matches it. Rows that differ only because of defaults are marked Same (default) and are hidden. Profiles do not inherit from each other: a parameter unset in any profile uses its default.',
            '<b>Group permissions:</b> rows include explicit denies (allow = false), so a count of rows is not a count of permissions granted. Permissions show allow / allow_edit as T / F.',
            '<b>Lists and polygons:</b> IDs are GUIDs that differ per environment, so lists are matched on content and polygons are summarised by count.',
            '<b>Groups:</b> names that differ only by case are treated as one group.',
            '<b>Matching:</b> case- and whitespace-sensitive. A leading or trailing space is shown with a visible marker.',
            '<b>Secrets:</b> API key values are masked.',
        ];

        return '<h2>Scope</h2><div class="note"><ul><li>'.implode("</li>\n<li>", $items).'</li></ul></div>';
    }

    private function tallySection(ComparisonResult $result): string
    {
        $baseline = $result->baselineName();
        $others = array_values(array_filter($result->environmentNames(), static fn (string $name): bool => $name !== $baseline));
        $totalRows = $result->totalRowsCompared();

        $html = ['<h2>Rows that differ from '.$this->e($baseline).'</h2><table class="tally"><thead><tr><th>Area</th><th>Rows compared</th>'];

        foreach ($others as $name) {
            $html[] = '<th>'.$this->e($name).'</th>';
        }

        $html[] = '<th>Not identical across all</th></tr></thead><tbody>';

        foreach ($result->tally as $tally) {
            $row = '<tr><td>'.$this->e($tally->area).'</td><td>'.$tally->rowsCompared.'</td>';

            foreach ($others as $name) {
                $row .= '<td>'.$tally->differencesByEnvironment[$name].'</td>';
            }

            $html[] = $row.'<td>'.$tally->notIdenticalAcrossAll.'</td></tr>';
        }

        $total = '<tr><td><b>Total</b></td><td><b>'.$totalRows.'</b></td>';
        $share = '<tr><td>Share of rows differing</td><td></td>';

        foreach ($others as $name) {
            $differences = $result->totalDifferencesFor($name);
            $total .= '<td><b>'.$differences.'</b></td>';
            $share .= '<td>'.($totalRows > 0 ? (int) round(100 * $differences / $totalRows) : 0).'%</td>';
        }

        $html[] = $total.'<td><b>'.$result->totalNotIdentical().'</b></td></tr>';
        $html[] = $share.'<td></td></tr></tbody></table>';

        return implode("\n", $html);
    }

    private function quickReadSection(ComparisonResult $result): string
    {
        $quickRead = $result->quickRead;
        $items = [];

        if ($result->versions->mismatch) {
            $items[] = '<li><b>PSO versions differ between environments</b> (highest '.$this->e($result->versions->highestVersion).'). See the PSO version section at the top.</li>';
        }

        $items[] = '<li>'.$quickRead->differingParameterRows.' parameter rows have different effective values across the environments (a parameter that is unset counts as its default value).</li>';

        if ($quickRead->defaultedParameterRows > 0) {
            $shown = array_slice($quickRead->defaultedParameterNames, 0, 6);
            $more = count($quickRead->defaultedParameterNames) > 6 ? ', ...' : '';

            $items[] = '<li>'.$quickRead->defaultedParameterRows.' more parameter rows only look different in the export because one environment leaves the parameter unset and uses the default (hidden above): '
                .$this->e(implode(', ', $shown).$more).'.</li>';
        }

        if (! $result->defaultsApplied) {
            $items[] = '<li><b>Parameter defaults were NOT applied</b> because the parameter catalog was not available; unset parameters show as (absent) and may simply be using their default.</li>';
        }

        if ($quickRead->groupsNotPresentEverywhere !== []) {
            $items[] = '<li>Groups not present everywhere: '.$this->e(implode('; ', $quickRead->groupsNotPresentEverywhere)).'.</li>';
        }

        $only = [];

        foreach ($quickRead->exceptionTypesInOnlyOneEnvironment as $name => $count) {
            if ($count > 0) {
                $only[] = $name.' '.$count;
            }
        }

        if ($only !== []) {
            $items[] = '<li>Exception type rows that exist in only one environment: '.$this->e(implode(', ', $only)).'.</li>';
        }

        if ($quickRead->differingParametersWithoutDefinition !== []) {
            $items[] = '<li>Differing parameters with no definition yet (describe them in a ParamDefinitions.csv): '.$this->e(implode(', ', $quickRead->differingParametersWithoutDefinition)).'.</li>';
        }

        if ($result->apiKeyValuesDiffer) {
            $items[] = '<li><b>Routing API key values differ between environments</b> (the values are masked here).</li>';
        }

        return '<h2>Quick read</h2><ul>'.implode("\n", $items).'</ul>';
    }

    private function tabTable(ComparisonTab $tab, ComparisonResult $result, bool $showSameRows): string
    {
        $baselineIndex = $result->baselineIndex;
        $rows = $showSameRows ? $tab->rows() : $tab->differingRows();
        $html = ['<table class="sortable"><thead><tr>'];

        foreach ($tab->keyHeaders as $header) {
            $html[] = '<th>'.$this->e($header).'</th>';
        }

        foreach ($result->environmentNames() as $index => $name) {
            $html[] = '<th>'.$this->e($name.($index === $baselineIndex ? ' (baseline)' : '')).'</th>';
        }

        foreach ($tab->extraHeaders as $header) {
            $html[] = '<th>'.$this->e($header).'</th>';
        }

        $html[] = '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $line = '<tr>';

            foreach ($row->keyValues as $keyValue) {
                $line .= '<td class="k">'.$this->e($keyValue).'</td>';
            }

            foreach ($row->cellValues as $index => $value) {
                $line .= $this->cell($value, $index, $row, $baselineIndex);
            }

            foreach ($tab->extraHeaders as $header) {
                $line .= $this->extraCell($header, $row->extra[$header] ?? '');
            }

            $html[] = $line.'</tr>';
        }

        if ($rows === []) {
            $html[] = '<tr><td colspan="99" class="muted">No differing rows.</td></tr>';
        }

        $html[] = '</tbody></table>';

        return implode("\n", $html);
    }

    private function cell(string $value, int $index, ComparisonRow $row, int $baselineIndex): string
    {
        $classes = [];

        if (in_array($value, [Cells::ABSENT, Cells::NULL_TEXT, Cells::NONE, EffectiveParameterResolver::NO_PROFILE], true) || str_starts_with($value, EffectiveParameterResolver::DEFAULT_PREFIX)) {
            $classes[] = 'muted';
        }

        if ($row->differsFromBaseline($index, $baselineIndex)) {
            $classes[] = 'diff';
        }

        $attribute = $classes === [] ? '' : ' class="'.implode(' ', $classes).'"';

        return '<td'.$attribute.'>'.$this->e($value).'</td>';
    }

    private function extraCell(string $header, string $text): string
    {
        if ($header === 'Definition') {
            if (str_starts_with($text, '[Inferred]')) {
                return '<td class="muted">'.$this->e($text).'</td>';
            }

            if ($text === '') {
                return '<td class="muted">(no definition yet)</td>';
            }
        }

        return '<td>'.$this->e($text).'</td>';
    }

    private function tableCountsSection(ComparisonResult $result): string
    {
        $html = ['<h2>Table row counts</h2><details><summary>All tables</summary><table class="sortable"><thead><tr><th>Table</th>'];

        foreach ($result->environmentNames() as $name) {
            $html[] = '<th>'.$this->e($name).'</th>';
        }

        $html[] = '<th>Scope</th></tr></thead><tbody>';

        foreach ($result->tableCounts as $row) {
            $line = '<tr><td>'.$this->e($row->table).'</td>';

            foreach ($row->counts as $count) {
                $line .= '<td>'.$count.'</td>';
            }

            $html[] = $line.'<td>'.$this->e($row->scope).'</td></tr>';
        }

        $html[] = '</tbody></table></details>';

        return implode("\n", $html);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
