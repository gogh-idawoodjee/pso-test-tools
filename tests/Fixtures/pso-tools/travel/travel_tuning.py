"""Stand-in for pso-tools/travel/travel_tuning.py in tests. Same arguments and output files; computes nothing.

FAKE_TUNING_MODE: ok (default) | fail (exit 1 with a Stopped during line) | silent (exit 0, writes nothing).
FAKE_TUNING_ARGS_FILE: append one JSON line per call: argv, cwd, the manifest rows as read (label, run CSV name, its
header line, polygon file name and how many <Polygon> rows it holds) and the notes text.
Numbers: the baseline has 75 flagged legs and 138.2 hours of error, the final run 10 and 98.2 hours, a run between
them 40 and 116.7; 44 fixed, 0 newly broken, 2,112 shared legs. One warning, labels and counts only, as the script's.
"""
import csv, json, os, sys


def arg(name, default=None):
    for item in sys.argv:
        if item.startswith(name + '='):
            return item[len(name) + 1:]
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


mode = os.environ.get('FAKE_TUNING_MODE', 'ok')
manifest = arg('--manifest')
base = os.path.dirname(manifest)
with open(manifest, encoding='utf-8-sig', newline='') as fh:
    rows = list(csv.DictReader(fh))
seen = []
for r in rows:
    with open(os.path.join(base, r['RunCsv']), encoding='utf-8-sig') as fh:
        header = fh.readline().strip()
    poly = (r.get('PolygonXml') or '').strip()
    entry = {'label': r['Label'], 'run_csv': r['RunCsv'], 'header': header, 'polygon_xml': poly}
    if poly:
        with open(os.path.join(base, poly), encoding='utf-8') as fh:
            entry['polygon_rows'] = fh.read().count('<Polygon>')
    seen.append(entry)
notes = arg('--notes')
record = {'argv': sys.argv[1:], 'cwd': os.getcwd(), 'rows': seen,
          'notes': open(notes, encoding='utf-8').read() if notes else None}
log = os.environ.get('FAKE_TUNING_ARGS_FILE')
if log:
    with open(log, 'a') as fh:
        fh.write(json.dumps(record) + '\n')

if mode == 'fail':
    sys.stderr.write('Stopped during: reading Run 1: Run 1 (run-2.csv) has no Google columns. Run plan travels again '
                     'for this run with the Google check on.\n')
    sys.exit(1)
if mode == 'silent':
    print('summary: nothing written')
    sys.exit(0)

labels = [r['Label'] for r in rows]
middle = len(rows) - 2
flagged = [75] + [40] * middle + [10]
total_h = [138.2] + [116.7] * middle + [98.2]
timeline = [{'label': l, 'flagged': f, 'total_h': h, 'total_min': round(h * 60)} for l, f, h in zip(labels, flagged, total_h)]
warnings = ['Legs common to every run are 55% of the smallest run.']
data = {'meta': {'title': arg('--title', 'Travel Tuning Report'), 'generated': arg('--generated'), 'shared': 2112,
                 'region': arg('--region-label', 'Region'), 'tiles': arg('--tiles', 'esri-gray'), 'warnings': warnings},
        'timeline': timeline, 'acc': {'pair': {'shared': 2112, 'fixed': 44, 'newly_broken': 0, 'still': 10}}}
with open(arg('--template'), encoding='utf-8') as fh:
    template = fh.read()
page = template.replace('__TITLE__', data['meta']['title'], 1).replace('__DATA__', json.dumps(data).replace('<', '\\u003c'), 1)
with open(arg('--out'), 'w', encoding='utf-8') as fh:
    fh.write(page)
if arg('--json'):
    with open(arg('--json'), 'w', encoding='utf-8') as fh:
        json.dump(data, fh)
for w in warnings:
    sys.stderr.write('warning: %s\n' % w)
print('summary: %s to %s: flagged 75 to 10, total error 8,292 to 5,892 min, 44 fixed, 0 newly broken' % (labels[0], labels[-1]))
