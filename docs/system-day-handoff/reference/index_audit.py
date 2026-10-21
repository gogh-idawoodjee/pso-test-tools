import os, sys, re, csv, collections
import xml.etree.ElementTree as ET

root = sys.argv[1]
out = sys.argv[2]
NS = '{http://360Scheduling.com/Schema/dsScheduleData.xsd}'
pat = re.compile(r'file(\d+)-([A-Z_]+)-(\d{4})_(\d\d)_(\d\d)_(\d\d)_(\d\d)_(\d\d)\.xml$')
rows = []
for fn in os.listdir(root):
    m = pat.match(fn)
    if not m:
        continue
    fid, typ = m.group(1), m.group(2)
    ts = f"{m.group(3)}-{m.group(4)}-{m.group(5)}T{m.group(6)}:{m.group(7)}:{m.group(8)}Z"
    path = os.path.join(root, fn)
    size = os.path.getsize(path)
    counts = collections.Counter()
    deletions = collections.Counter()
    inref = {}
    try:
        for ev, el in ET.iterparse(path, events=('end',)):
            tag = el.tag.replace(NS, '')
            # only count direct children of root: those have no NS-stripped parent tracking; approximate by known top-level names
            if tag == 'Object_Deletion':
                ot = el.findtext(NS + 'object_type_id') or ''
                deletions[ot] += 1
                counts[tag] += 1
                el.clear()
            elif tag == 'Input_Reference':
                for c in el:
                    inref[c.tag.replace(NS, '')] = (c.text or '')
                counts[tag] += 1
                el.clear()
            elif tag[0].isupper() and '_' in tag or tag in ('Activity', 'Resource', 'Location', 'Shift', 'Break', 'Plan', 'Skill', 'Region'):
                counts[tag] += 1
                el.clear()
    except ET.ParseError as e:
        counts['PARSE_ERROR'] += 1
    rows.append({
        'file_id': fid, 'type': typ, 'ts': ts, 'size': size,
        'input_id': inref.get('id', ''), 'input_type': inref.get('input_type', ''),
        'process_type': inref.get('process_type', ''), 'schedule_data': inref.get('schedule_data', ''),
        'load_status': inref.get('load_status', ''), 'input_datetime': inref.get('datetime', ''),
        'object_deletions': counts.get('Object_Deletion', 0),
        'deletion_types': ';'.join(f"{k}:{v}" for k, v in deletions.most_common()),
        'elements': ';'.join(f"{k}:{v}" for k, v in counts.most_common() if k != 'Object_Deletion'),
        'total_elements': sum(counts.values()),
    })
rows.sort(key=lambda r: r['ts'])
with open(out, 'w', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
    w.writeheader(); w.writerows(rows)
print(len(rows), 'files indexed')
