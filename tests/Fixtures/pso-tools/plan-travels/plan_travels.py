"""Stand-in for pso-tools/plan-travels/plan_travels.py in tests. Same commands, arguments and output files; reads no XML.

FAKE_PLAN_MODE: ok (default) | fail (exit 1 with a Stopped during line) | silent (exit 0, writes nothing); both commands.
FAKE_PLAN_LEGS: how many generated legs follow the three fixed ones (default 3).
FAKE_PLAN_ARGS_FILE: append one JSON line per call (argv, cwd, PSO_PROGRESS, and for merge-google the google.json it read).

Every leg carries route_key ('%.6f' on each coordinate, null when one is missing), as the real script does.
Fixed legs: A1 and A2 share one trip whose origin latitude has 17 digits; A3 has no start coordinates.
Generated leg i: resource R(3 + i % 5), origin (50 + i/1000, -106.0), destination (51 + i/1000, -106.1).
Progress: only build emits PROGRESS {"stage":"reading","percent":N} (0 first and 100 last); merge-google emits none,
as the real script does.
merge-google matches google.json on route_key verbatim. Flags: Review when Google is 1200 s or more over the
600 s PSO time, OK otherwise, Missing Google Time for 'No route returned', Skipped for no route_key or no result.
A leg whose route_key origin half equals its destination half is OK with difference 'Same location' and
google_min/google_sec/google_km 0, whatever google.json says (not counted as cache/google/error/skipped).
"""
import json, os, sys

LEG_KEYS = (
    'resource_id', 'resource_name', 'direction', 'previous_activity_id', 'previous_gb',
    'previous_activity_type', 'previous_activity_description', 'activity_id', 'destination_gb',
    'destination_activity_type', 'destination_activity_description', 'start_lat', 'start_lng', 'end_lat',
    'end_lng', 'distance_km', 'travel_min', 'travel_sec', 'merged_fragments', 'merge_type', 'gps_fix',
    'gps_fix_time', 'prefix_distance_km', 'prefix_travel_min', 'preshift_distance_km', 'preshift_travel_min',
    'maps_link', 'plan_id', 'call_id', 'siebel_order_number', 'start_time', 'end_time', 'shift_id',
    'route_key', 'google_min', 'google_sec', 'google_km', 'google_source', 'ratio', 'pct_divergence',
    'abs_divergence_min', 'difference', 'flag', 'weighting', 'validation_error',
)

def arg(name, default=None):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default

command = sys.argv[1] if len(sys.argv) > 1 else ''
mode = os.environ.get('FAKE_PLAN_MODE', 'ok')
out = arg('--out-dir', '.')

def progress(stage, percent):
    if os.environ.get('PSO_PROGRESS') == 'lines':
        sys.stderr.write('PROGRESS %s\n' % json.dumps({'stage': stage, 'percent': percent}, separators=(',', ':')))

def record(extra):
    path = os.environ.get('FAKE_PLAN_ARGS_FILE')
    if path:
        with open(path, 'a') as fh:
            fh.write(json.dumps(dict({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'progress_env': os.environ.get('PSO_PROGRESS')}, **extra)) + '\n')

def leg(resource, olat, olng, dlat, dlng, dest):
    coords = (olat, olng, dlat, dlng)
    row = dict.fromkeys(LEG_KEYS)
    row.update({'resource_id': resource, 'direction': 'Activity -> Activity', 'activity_id': dest,
                'start_lat': olat, 'start_lng': olng, 'end_lat': dlat, 'end_lng': dlng,
                'travel_sec': 600, 'travel_min': 10.0, 'plan_id': 'P1',
                'route_key': None if None in coords else '%.6f,%.6f|%.6f,%.6f' % coords})
    return row

def make_meta(legs, args, google=False, google_counts=None, review_minutes=20.0, review_pct=0.0):
    flagged = google
    flags = [l['flag'] for l in legs]
    ratios = [l['ratio'] for l in legs if l['ratio'] is not None]
    by_direction = {}
    for l in legs:
        by_direction[l['direction']] = by_direction.get(l['direction'], 0) + 1
    def n(flag):
        return flags.count(flag) if flagged else None
    return {
        'tool': 'plan_travels', 'schema_version': 1,
        'export_name': os.path.basename(arg('--export', '')), 'dataset_name': arg('--dataset-name'),
        'generated': '2026-10-05T00:00:00Z',
        'date_from': arg('--date'), 'date_to': arg('--date'), 'days': int(arg('--days', '1')),
        'thresholds': {'review_minutes': review_minutes, 'review_pct': review_pct},
        'google': google, 'google_counts': google_counts,
        'counts': {
            'legs': len(legs), 'resources': len({l['resource_id'] for l in legs}), 'by_direction': by_direction,
            'missing_coordinates': sum(1 for l in legs if l['route_key'] is None),
            'missing_pso_time': sum(1 for l in legs if l['travel_sec'] is None),
            'review': n('Review'), 'ok': n('OK'), 'skipped': n('Skipped'), 'missing_google_time': n('Missing Google Time'),
            'missing': (flags.count('Missing PSO Time') + flags.count('Missing Google Time')) if flagged else sum(1 for l in legs if l['travel_sec'] is None),
            'average_ratio': (round(sum(ratios) / len(ratios), 3) if ratios else None) if flagged else None,
        },
        'warnings': ['2 fragments could not be merged'] if not google else [], 'notes': [],
    }

def write(legs, meta):
    with open(os.path.join(out, 'legs.json'), 'w') as fh:
        json.dump({'meta': meta, 'legs': legs}, fh)
    with open(os.path.join(out, 'plan_travels.csv'), 'w') as fh:
        fh.write('Resource ID,Flag\n' + ''.join('%s,%s\n' % (l['resource_id'], l.get('flag') or '') for l in legs))
    with open(os.path.join(out, 'plan_travels.html'), 'w') as fh:
        fh.write('<!doctype html><html><body>Plan travels page %s</body></html>' % ('with Google' if meta['google'] else 'without Google'))
    for warning in meta['warnings']:
        sys.stderr.write('warning: %s\n' % warning)
    print('summary: %d legs' % len(legs))

if command == 'build':
    record({})
    progress('reading', 0)
    progress('reading', 50)
    if mode == 'fail':
        sys.stderr.write('Stopped during: reading the export: the root element is Foo, not dsScheduleData\n')
        sys.exit(1)
    if mode == 'silent':
        sys.exit(0)
    legs = [leg('R1', 52.123456789012345, -106.5, 52.2, -106.6, 'A1'),
            leg('R1', 52.123456789012345, -106.5, 52.2, -106.6, 'A2'),
            leg('R2', None, None, 52.3, -106.7, 'A3')]
    for i in range(int(os.environ.get('FAKE_PLAN_LEGS', '3'))):
        legs.append(leg('R%d' % (3 + i % 5), 50 + i / 1000.0, -106.0, 51 + i / 1000.0, -106.1, 'G%d' % i))
    progress('reading', 100)
    write(legs, make_meta(legs, None))
elif command == 'merge-google':
    with open(arg('--google')) as fh:
        google = json.load(fh)
    with open(arg('--legs')) as fh:
        data = json.load(fh)
    record({'google': google})
    if mode == 'fail':
        sys.stderr.write('Stopped during: merging the Google results: the Google file is not what the build expects\n')
        sys.exit(1)
    if mode == 'silent':
        sys.exit(0)
    counts = {'trips': len(google), 'cache': 0, 'google': 0, 'error': 0, 'skipped': 0}
    for l in data['legs']:
        if l['route_key'] is None:
            l['flag'] = 'Skipped'
            counts['skipped'] += 1
            continue
        origin, destination = l['route_key'].split('|')
        if origin == destination:
            l.update(flag='OK', difference='Same location', google_min=0, google_sec=0, google_km=0)
            continue
        g = google.get(l['route_key'])
        if g is None:
            l['flag'] = 'Skipped'
            counts['skipped'] += 1
        elif 'error' in g:
            l['flag'] = 'Missing Google Time' if g['error'] == 'No route returned' else 'Skipped'
            l['validation_error'] = g['error']
            counts['error'] += 1
            if l['flag'] == 'Skipped':
                counts['skipped'] += 1
        else:
            secs = g['durationSec']
            diff = secs - 600
            l.update(google_sec=secs, google_min=round(secs / 60.0, 1), google_km=g.get('distanceKm', 0),
                     google_source=g['source'], ratio=round(secs / 600.0, 3),
                     pct_divergence=round(diff / 600.0 * 100, 1), abs_divergence_min=round(abs(diff) / 60.0, 1))
            l['flag'] = 'Review' if diff >= 1200 else 'OK'
            l['difference'] = 'Google %.1f min %s than PSO' % (abs(diff) / 60.0, 'longer' if diff >= 0 else 'shorter')
            counts['cache' if g['source'] == 'Cache' else 'google'] += 1
    meta = make_meta(data['legs'], None, True, counts, float(arg('--review-minutes', '20')), float(arg('--review-pct', '0')))
    write(data['legs'], meta)
else:
    sys.stderr.write('Stopped during: starting: unknown command %s\n' % command)
    sys.exit(1)
