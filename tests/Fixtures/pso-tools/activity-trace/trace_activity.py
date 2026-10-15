"""Stand-in for pso-tools/activity-trace/trace_activity.py in tests. Same arguments and output files, no tracing.

FAKE_TRACE_MODE: ok (default) | notfound (exit 2) | fail (exit 1) | silent (exit 0, writes nothing).
FAKE_TRACE_ARGS_FILE: where to write the received argv and cwd, as JSON (optional).
"""
import json, os, sys

def arg(name):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None

mode = os.environ.get('FAKE_TRACE_MODE', 'ok')
out = arg('--out-dir') or '.'
if os.environ.get('FAKE_TRACE_ARGS_FILE'):
    with open(os.environ['FAKE_TRACE_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, fh)

if mode == 'notfound':
    sys.stderr.write('Activity 123 is not in the audit messages.\n')
    sys.exit(2)
if mode == 'fail':
    sys.stderr.write('Stopped during: reading the audits\n')
    sys.exit(1)
if mode == 'silent':
    sys.exit(0)

with open(os.path.join(out, 'trace.html'), 'w') as fh:
    fh.write('<!doctype html><html><body>Trace page</body></html>')
with open(os.path.join(out, 'trace.json'), 'w') as fh:
    json.dump({'meta': {}, 'findings': [{'severity': 'red', 'text': 'a'}, {'severity': 'amber', 'text': 'b'}, {'severity': 'amber', 'text': 'c'}, {'severity': 'green', 'text': 'd'}]}, fh)
with open(os.path.join(out, 'booking.csv'), 'w') as fh:
    fh.write('time,event\n2026-10-03,offer\n')
with open(os.path.join(out, 'history.csv'), 'w') as fh:
    fh.write('time,step\n2026-10-03,plan\n')
print('Reading audits ...')
print('warning: the LOAD has no slot usage rules')
print('summary: Booked 2026-10-03 after 2 offers; 14 scheduling events, 1 check failed')
