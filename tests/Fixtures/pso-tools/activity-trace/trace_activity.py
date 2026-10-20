"""Stand-in for pso-tools/activity-trace/trace_activity.py in tests. Same arguments and output files, no tracing.

FAKE_TRACE_MODE: ok (default) | notfound (exit 2) | fail (exit 1, two-line stderr) | eventlogfail (exit 1, one line)
| blank (exit 2, no stderr) | silent (exit 0, writes nothing). Every mode but blank writes PROGRESS lines first, as the
real script does with PSO_PROGRESS=lines.
FAKE_TRACE_ARGS_FILE: where to write the received argv and cwd, as JSON (optional).
"""
import json, os, sys

def arg(name):
    for item in sys.argv:
        if item.startswith(name + '='):
            return item[len(name) + 1:]
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else None

mode = os.environ.get('FAKE_TRACE_MODE', 'ok')
out = arg('--out-dir') or '.'
if os.environ.get('FAKE_TRACE_ARGS_FILE'):
    with open(os.environ['FAKE_TRACE_ARGS_FILE'], 'w') as fh:
        json.dump({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, fh)

def progress(stage, percent):
    if os.environ.get('PSO_PROGRESS') == 'lines':
        sys.stderr.write('PROGRESS %s\n' % json.dumps({'stage': stage, 'percent': percent}, separators=(',', ':')))

if mode == 'blank':
    sys.exit(2)
progress('finding', 0)
progress('finding', 100)
progress('reading', 40)

if mode == 'notfound':
    sys.stderr.write('Activity 123 is not in the audit messages.\n')
    sys.exit(2)
if mode == 'fail':
    sys.stderr.write("Stopped during: reading the audit files\nKeyError: 'Activity_Status'\n")
    sys.exit(1)
if mode == 'eventlogfail':
    sys.stderr.write('Stopped during: reading the IFS event log: The event log has no Time column.\n')
    sys.exit(1)
if mode == 'silent':
    sys.exit(0)

with open(os.path.join(out, 'trace.html'), 'w') as fh:
    fh.write('<!doctype html><html><body>Trace page</body></html>')
with open(os.path.join(out, 'trace.json'), 'w') as fh:
    json.dump({'meta': {'activity_id': arg('--activity') or os.environ.get('FAKE_TRACE_RESOLVED_ACTIVITY'), 'work_task_id': arg('--work-task')}, 'findings': [{'sev': 'RED', 'text': 'a'}, {'sev': 'AMBER', 'text': 'b'}, {'sev': 'AMBER', 'text': 'c'}, {'sev': 'GREEN', 'text': 'd'}, {'sev': 'INFO', 'text': 'e'}]}, fh)
with open(os.path.join(out, 'booking.csv'), 'w') as fh:
    fh.write('time,event\n2026-10-03,offer\n')
with open(os.path.join(out, 'history.csv'), 'w') as fh:
    fh.write('time,step\n2026-10-03,plan\n')
print('Reading audits ...')
print('warning: the LOAD has no slot usage rules')
print('summary: Booked 2026-10-03 after 2 offers; 14 scheduling events, 1 check failed')
