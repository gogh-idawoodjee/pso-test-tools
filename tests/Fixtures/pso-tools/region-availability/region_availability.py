"""Stand-in for pso-tools/region-availability/region_availability.py in tests. Same arguments, same output
layout (<out-root>/<ACCOUNT>/<DATASET>/region_availability_<Monday>.html + .json), no rules.

FAKE_RA_MODE: ok (default) | fail | nopage | hang.
FAKE_RA_ARGS_FILE: where to write the received argv, cwd, env and the account file it read, as JSON (optional).
"""
import json, os, sys, time


def arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None


mode = os.environ.get('FAKE_RA_MODE', 'ok')
account = arg('--account') or 'ACME'
settings = {}
sp = os.path.join(arg('--accounts-dir') or '.', account + '.json')
if os.path.exists(sp):
    with open(sp, encoding='utf-8') as fh:
        settings = json.load(fh)

if os.environ.get('FAKE_RA_ARGS_FILE'):
    with open(os.environ['FAKE_RA_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS'),
                   'settings': settings, 'settings_file': sp}, fh)

if mode == 'hang':
    time.sleep(30)

lines = os.environ.get('PSO_PROGRESS') == 'lines'
def progress(rec):
    if lines:
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec)); sys.stderr.flush()

print('Reading load.xml ...', flush=True)
progress({'stage': 'load', 'percent': 0})
progress({'stage': 'load', 'percent': 100})
progress({'stage': 'gather'})

if mode == 'fail':
    sys.stderr.write('Traceback (most recent call last):\n  File "region_availability.py", line 1\n')
    sys.stderr.write('SystemExit: The LOAD has no Input_Reference. Is it a dsScheduleData export?\n')
    sys.exit(1)

print('WARNING: No shifts in this week.')
if mode == 'nopage':
    sys.exit(0)

week = arg('--week-of') or '2026-10-05'
out_dir = os.path.join(arg('--out-root'), account, 'NORTH')
os.makedirs(out_dir, exist_ok=True)
data = {
    'format': 1,
    'meta': {'account': account, 'dataset': 'NORTH', 'org': settings.get('display_name', account),
             'tz': arg('--time-zone'), 'week': week,
             'labels': {'district': settings.get('attributes', {}).get('district', 'District')},
             'counts': {'techs': 118, 'shifts': 500, 'techs_with_changes': 9, 'missing_availability': 0, 'ties': 0, 'unknown_regions': 0},
             'warnings': ['No shifts in this week.']},
    'regions': [], 'groups': [], 'types': [], 'techs': [],
}
base = os.path.join(out_dir, 'region_availability_' + week)
with open(base + '.json', 'w') as fh:
    json.dump(data, fh)
with open(base + '.html', 'w') as fh:
    fh.write('<!doctype html><html><body><h1>%s: regions, week of %s</h1><script>const DATA=%s</script></body></html>'
             % (data['meta']['org'], week, json.dumps(data)))
print('load.xml: 118 technicians, 500 shifts in the week of %s, 9 with changes.' % week)
