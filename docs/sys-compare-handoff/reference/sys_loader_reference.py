import xml.etree.ElementTree as ET, sys
def load(f):
    r=ET.parse(f).getroot(); rows={}
    for ch in r:
        t=ch.tag.split('}')[1]
        rows.setdefault(t,[]).append({x.tag.split('}')[1]:(x.text or '') for x in ch})
    return rows
KEYS={
 'Profile':['id'],
 'Profile_Parameter':['profile_id','parameter_id','parameter_application_type_id'],
 'Groups':['id'],
 'Group_Permission':['group_id','permission_id'],
 'Org_Schedule_Exception_Type':['profile_id','schedule_exception_type_id'],
 'Org_Schedule_Exc_Type_Data':['profile_id','schedule_exception_type_id'],
 'Organisation':['id'],
 'Organisation_List':['list_type_id','display_context_id'],
 'Organisation_Permission':['permission_id'],
 'Terminology_Organisation':None,
 'Display_Context':['id'],
 'Entry':['id'],
 'List':['id'],
}
IGN={'last_updated','status_datetime'}
def diff(a,b,table,keys,la='prod',lb='tst'):
    if keys is None: keys=list((a+b)[0].keys()) if (a+b) else []
    A={tuple(r.get(k,'') for k in keys):r for r in a}
    B={tuple(r.get(k,'') for k in keys):r for r in b}
    oa=sorted(set(A)-set(B)); ob=sorted(set(B)-set(A))
    ch=[]
    for k in sorted(set(A)&set(B)):
        d={f:(A[k].get(f),B[k].get(f)) for f in set(A[k])|set(B[k]) if f not in IGN and A[k].get(f)!=B[k].get(f)}
        if d: ch.append((k,d))
    return oa,ob,ch
if __name__=='__main__':
    p=load('prod.xml'); t=load(sys.argv[1] if len(sys.argv)>1 else 'tst.xml')
    for tb,keys in KEYS.items():
        oa,ob,ch=diff(p.get(tb,[]),t.get(tb,[]),tb,keys)
        print(f"\n=== {tb}: prod-only {len(oa)}, tst-only {len(ob)}, changed {len(ch)}")
        if tb in('Profile_Parameter','Profile','Groups','Organisation','Organisation_List','Organisation_Permission','Org_Schedule_Exc_Type_Data','Terminology_Organisation','Display_Context'):
            for k in oa: print(' prod-only',k)
            for k in ob: print(' tst-only ',k)
            for k,d in ch: print(' changed',k,d)
