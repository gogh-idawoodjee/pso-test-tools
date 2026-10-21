"""Stand-in for pso-tools/load-audit/audit_load.py in tests. Same arguments, same outputs, no checks.

FAKE_AUDIT_MODE: ok (default) | fail | hang | silent | split | longfail.
FAKE_AUDIT_ARGS_FILE: where to write the received argv as JSON (optional).
"""
import json, os, sys, time


def arg(name):
    for item in sys.argv:
        if item.startswith(name + '='):
            return item[len(name) + 1:]
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None


import argparse
_parser = argparse.ArgumentParser()
for _flag in ('--load', '--plan', '--sys', '--messages', '--noise-ids', '--flag-activities', '--flag-deletions',
              '--tz', '--org', '--accounts-dir', '--params', '--watchlist', '--ifs-release', '--complete-grace-hours',
              '--review', '--out', '--json', '--html'):
    _parser.add_argument(_flag)
_parser.add_argument('--events', action='append')
_parser.add_argument('--appointments', action='append')
_parser.parse_args()

mode = os.environ.get('FAKE_AUDIT_MODE', 'ok')
if os.environ.get('FAKE_AUDIT_ARGS_FILE'):
    with open(os.environ['FAKE_AUDIT_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, fh)

if mode == 'hang':
    time.sleep(30)

lines = os.environ.get('PSO_PROGRESS') == 'lines'
def progress(rec):
    if lines:
        sys.stderr.write('PROGRESS %s\n' % json.dumps(rec)); sys.stderr.flush()

if mode == 'split' and lines:
    # One progress line delivered in two flushed pieces, to prove the reader joins chunks.
    sys.stderr.write('PROGRESS {"stage":"sys","per'); sys.stderr.flush()
    time.sleep(0.4)
    sys.stderr.write('cent":50}\n'); sys.stderr.flush()
    time.sleep(0.4)

progress({'stage': 'load', 'percent': 0})
progress({'stage': 'load', 'percent': 45, 'seconds_left': 6})
sys.stderr.write('not progress, just noise\n')
progress({'stage': 'load', 'percent': 100})
progress({'stage': 'mystery'})            # unknown stage: must be ignored
sys.stderr.write('PROGRESS {not json\n')  # broken line: must be ignored
if arg('--plan'):
    progress({'stage': 'plan', 'percent': 0}); progress({'stage': 'plan', 'percent': 100})
progress({'stage': 'checks'})
if arg('--sys'):
    progress({'stage': 'sys', 'percent': 0}); progress({'stage': 'sys', 'percent': 100}); progress({'stage': 'checks'})

if mode == 'longfail':
    sys.stderr.write('E' * 1000 + '\n')
    sys.exit(1)

if mode == 'fail':
    sys.stderr.write('Traceback (most recent call last):\n  File "audit_load.py", line 1\nKeyError: '); sys.stderr.flush()
    time.sleep(0.4)
    sys.stderr.write("'dataset_id'")  # last line: split across chunks, no trailing newline
    sys.exit(1)

print('warning: no recommendation for red/amber check: Resources with no shifts')
if mode == 'silent':
    sys.exit(0)

findings = [
    {'leg': '1', 'check': 'Open activities already past their SLA', 'severity': 'AMBER', 'count': 3, 'of': 10, 'samples': ['A1'], 'note': 'n', 'recommendation': 'r'},
    {'leg': '2', 'check': 'Resources with no shifts', 'severity': 'RED', 'count': 1, 'of': 5, 'samples': ['R1'], 'note': 'n', 'recommendation': None},
    {'leg': '4', 'check': 'Plan output within the DSE window', 'severity': 'GREEN', 'count': None, 'of': None, 'samples': [], 'note': 'n', 'recommendation': None},
]
with open(arg('--json'), 'w') as fh:
    json.dump(findings, fh)
with open(arg('--html'), 'w') as fh:
    fh.write('<!doctype html><html><body><h1>%s</h1><script>const DATA={}</script></body></html>' % (arg('--org') or 'Dataset'))
with open(arg('--out'), 'w') as fh:
    fh.write('# report\n')
print('wrote', arg('--out'), '(3 findings)')
if arg('--events'):
    out_dir = os.path.dirname(arg('--html'))
    with open(os.path.join(out_dir, 'system_day.json'), 'w') as fh:
        json.dump({'day': {'tz': arg('--tz'), 'start': 0, 'end': 0, 'dataset': None}, 'lanes': [], 'findings': [], 'counts': []}, fh)
    with open(os.path.join(out_dir, 'system_day_inputs.csv'), 'w') as fh:
        fh.write('file_id,type,t,bytes\nf1,events,0,1\n')
