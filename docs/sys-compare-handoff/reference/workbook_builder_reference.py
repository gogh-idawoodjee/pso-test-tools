import sys; sys.path.insert(0,'/home/claude')
from cmp import load
from collections import defaultdict
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.formatting.rule import FormulaRule
from openpyxl.utils import get_column_letter as L

ENVS=['prod','acc','stg','tst']
LBL={'prod':'PROD','acc':'ACC (UAT)','stg':'STG','tst':'TST'}
E={n:load(f'/mnt/user-data/uploads/{n}.xml') for n in ENVS}
ABS='(absent)'; NUL='(null)'

def vis(s):
    if s is None: return NUL
    if s=='': return NUL
    lead=len(s)-len(s.lstrip(' ')); trail=len(s)-len(s.rstrip(' '))
    return '␣'*lead+s.strip(' ')+'␣'*trail if (lead or trail) else s

FONT='Arial'
f_norm=Font(name=FONT,size=10); f_bold=Font(name=FONT,size=10,bold=True)
f_hdr=Font(name=FONT,size=10,bold=True,color='FFFFFF'); f_title=Font(name=FONT,size=14,bold=True)
fill_hdr=PatternFill('solid',fgColor='1F3864'); fill_sub=PatternFill('solid',fgColor='D9E1F2')
fill_diff=PatternFill('solid',fgColor='FFE699'); fill_status=PatternFill('solid',fgColor='F4B084')
thin=Side(style='thin',color='BFBFBF'); border=Border(left=thin,right=thin,top=thin,bottom=thin)
wrap=Alignment(wrap_text=True,vertical='top')

wb=Workbook()

def matrix_sheet(name,keycols,rows,widths,note=None):
    """rows: list of (key tuple..., note, [prod,acc,stg,tst]) -> columns A..C = keycols(3), D..G envs, H status"""
    ws=wb.create_sheet(name)
    hdr=keycols+[LBL[e] for e in ENVS]+['Status']
    for i,h in enumerate(hdr,1):
        c=ws.cell(1,i,h); c.font=f_hdr; c.fill=fill_hdr; c.alignment=Alignment(wrap_text=True,vertical='center'); c.border=border
    for r,row in enumerate(rows,2):
        keys,vals=row[:3],row[3]
        for i,v in enumerate(list(keys)+list(vals),1):
            c=ws.cell(r,i,v); c.font=f_norm; c.border=border; c.alignment=Alignment(vertical='top',wrap_text=True)
        ws.cell(r,8,f'=IF(AND(EXACT(D{r},E{r}),EXACT(E{r},F{r}),EXACT(F{r},G{r})),"Same","DIFF")').font=f_bold
        ws.cell(r,8).border=border
    n=len(rows)+1
    for i,w in enumerate(widths,1): ws.column_dimensions[L(i)].width=w
    ws.freeze_panes='D2'; ws.auto_filter.ref=f'A1:H{n}'
    ws.row_dimensions[1].height=30
    # highlight env cells that deviate from PROD, status column, absent text
    ws.conditional_formatting.add(f'E2:G{n}',FormulaRule(formula=['NOT(EXACT(E2,$D2))'],fill=fill_diff))
    ws.conditional_formatting.add(f'H2:H{n}',FormulaRule(formula=['$H2="DIFF"'],fill=fill_status,font=Font(name=FONT,bold=True)))
    ws.conditional_formatting.add(f'D2:G{n}',FormulaRule(formula=['OR(D2="'+ABS+'",D2="'+NUL+'")'],font=Font(name=FONT,color='7F7F7F',italic=True)))
    return ws,n

# ---------- Parameters ----------
def pp(e):
    return {(r['profile_id'],r['parameter_id'],r.get('parameter_application_type_id','')):r.get('parameter_value') for r in e['Profile_Parameter']}
P={n:pp(E[n]) for n in ENVS}
keys=sorted(set().union(*[set(v) for v in P.values()]),key=lambda k:({'DEFAULT':0}.get(k[0],1),k[0],k[1]))
apikeys=set()
rows=[]
for k in keys:
    vals=[]
    for n in ENVS:
        if k not in P[n]: vals.append(ABS)
        else:
            v=P[n][k]
            if 'key' in k[1].lower() and v: apikeys.add(v); v='[API key set]'
            vals.append(vis(v))
    rows.append((k[0],k[1],k[2],vals))
ws_par,n_par=matrix_sheet('Parameters',['Profile','Parameter','App type'],rows,[26,40,10,34,34,34,34,10])
apikey_same=len(apikeys)==1

KB='KB'; RN='Release notes'; INF='Inference (not in KB)'
DEFS={
 'ActivityText':("Text shown on each activity bar on the Gantt. Built from an expression such as {Table.Field}; fields starting with # are derived values.",KB),
 'ActivitySubtext':("Secondary line of text shown under the main activity text. Same {Table.Field} expression syntax.",INF),
 'ActivityBaseLabel':("Label format for activities, apparently the base/default label the other activity label settings build on. Confirm on the Parameters screen.",INF),
 'PrivateActivityText':("Text shown on private activities (unavailability blocks such as holiday, sickness, training) on the Gantt.",INF),
 'PrivateActivityLabel':("Label shown for private activities outside the Gantt, by analogy with ActivityLabel.",INF),
 'ResourceLabel':("How a resource is named outside the Gantt, e.g. {First name} {Surname} [{Id}] shows “Charles Ollivon [T1001]”. Anything outside { } (spaces, brackets) is shown as typed, so a trailing space is cosmetic only.",KB),
 'ResourceText':("Main text shown for each resource row on the Gantt, by analogy with ActivityText.",INF),
 'ResourceSubtext':("Secondary line shown under the resource name (here the employer).",INF),
 'ResourceDescriptionFormat':("Format of the resource’s description string, e.g. in lists and tooltips.",INF),
 'ARPResourceLabelFormat':("How a resource is labelled in the Advanced Resource Planner (ARP).",INF),
 'AllowSplitTravel':("By name, lets the scheduler split a travel leg (for example around a break or a shift boundary). NOT documented in the KB, and the KB currently says there is no separate travel-splitting toggle (it follows split_allowed on the destination activity type), so this needs confirming with IFS. Present only in PROD.",INF),
 'CommitToAllocatedShift':("When someone manually commits an activity, keep it in the shift it is already allocated to. If it is on together with CommitToAvailabilityWindow, whichever has the later start wins.",KB),
 'CommittedActivitiesConstraintsOption':("1 = committed activities (status 30–40) have their time constraints obeyed, so the start may be pushed to a valid time; 0 = constraints are not enforced. Field reality: only Activity_Availabilities are enforced, not SLA windows. No effect once the resource is Travelling (50).",KB),
 'CommittedActivitiesProfileId':("Points the DEFAULT profile at the profile to use for committed (imminent) activities. This is step 2 of the real-time travel setup; without it the committed-activities profile is not picked up.",KB),
 'TravelCalculationOption':("How travel time is worked out: HierarchicalTravelMatrix (the HTM travel database), StraightLine (as the crow flies, with a speed factor), or RealTimeTravel (live routing service, meant for committed/imminent activities and layered on top of HTM or straight line).",KB),
 'TravelTimeProfileId':("Which travel-time profile (time-of-day and area weightings, barriers) adjusts journey times. It is the last fallback after Shift, Resource and Resource Type. The system ships with an empty DEFAULT profile.",KB),
 'HierarchicalDatabaseMatrixId':("Which HTM (travel matrix) to use. Set per profile, this is how multiple HTM connections are supported.",KB),
 'RealTimeTravelProvider':("Which online routing service supplies real-time travel: NONE or TOMTOM.",KB),
 'RoutingApiKey':("API key for the online routing service; required for real-time travel. (Masked in this workbook.)",KB),
 'IsochroneCompareStraight':("Used with the Travel Analyser isochrone preview to compare against a straight-line disc when the preview looks very different from the real isochrone.",KB),
 'ImplicitBreaksOnOffEventsRequired':("Controls when PSO assumes an implicit break has been taken: whether it waits for the resource’s break on/off events or assumes the break happens as planned. Added in 6.5.0.25; a fix in 6.16.0.79 made it apply during appointment booking. The True/False meaning is inferred from the name.",RN),
 'MaxDisplaceableActivityPriority':("Appointment booking: low-priority activities up to this priority are ignored when building an offer (no attempt to reallocate them). -1 = off; e.g. 2 ignores priority 1–2. Never applies to committed-or-later or fully fixed activities.",KB),
 'SortValuePrecedenceMaximumStatus':("Order of committed activities. Default 30 sorts by status first (Accepted before Downloaded before Sent before Committed), then commit_sort_value. Setting 40 makes commit_sort_value the main key with status as tie-breaker. Each activity needs a unique status + sort value + date_time_status combination.",KB),
 'StandardSendScheduleExceptionAccepts':("When true, schedule-exception acknowledgements are handled in the standard way and the separate Schedule_Exception_Response broadcast is bypassed.",KB),
 'OpenIdAuthority':("Issuer URL of the identity provider used for single sign-on (OpenID Connect). Expected to differ per environment. If it is wrong, logins fail; the packaged “Reset OpenIdAuthority.xml” restores password login.",KB),
 'MaxUserSessions':("By name, the cap on concurrent sessions per user; -1 presumably means unlimited.",INF),
 'GpsFrequency':("By name, how often GPS positions are expected or used (PT5M = every 5 minutes). Set only in ACC.",INF),
 'SLAActivityAgeingFactor':("By name, a multiplier on how SLA-related activity age is calculated or displayed. Confirm before relying on it.",INF),
 'SchedulingWindowLength':("By name, how far ahead the scheduler plans (P2D = 2 days). Related to the dataset’s Scheduling Work Days, but the parameter itself is not documented in the KB.",INF),
}
ws_par.cell(1,9,'What it does (plain English)')
h=ws_par.cell(1,9); h.font=f_hdr; h.fill=fill_hdr; h.border=border; h.alignment=Alignment(wrap_text=True,vertical='center')
for r in range(2,n_par+1):
    d=DEFS.get(ws_par.cell(r,2).value)
    txt=''
    if d: txt=('[Inferred] ' if d[1].startswith('Inference') else '')+d[0]
    x=ws_par.cell(r,9,txt if txt else None); x.font=f_norm; x.border=border; x.alignment=Alignment(vertical='top',wrap_text=True)
ws_par.column_dimensions['I'].width=90
ws_par.auto_filter.ref=f'A1:I{n_par}'
ws_par.conditional_formatting.add(f'I2:I{n_par}',FormulaRule(formula=['LEFT($I2,10)="[Inferred]"'],font=Font(name=FONT,italic=True,color='7F7F7F')))

# ---------- Exception types ----------
def ex(e):
    d={}
    for r in e['Org_Schedule_Exception_Type']:
        d[(r['profile_id'],r['schedule_exception_type_id'])]=(r.get('active'),r.get('attention_value'),r.get('activation_setting'),r.get('description'))
    return d
X={n:ex(E[n]) for n in ENVS}
kx=sorted(set().union(*[set(v) for v in X.values()]),key=lambda k:({'DEFAULT':0,'CONTRACTORS':1}.get(k[0],2),int(k[1])))
rows=[]
for k in kx:
    vals=[];desc=''
    for n in ENVS:
        if k not in X[n]: vals.append(ABS)
        else:
            a,at,ac,d=X[n][k]; desc=desc or (d or '')
            vals.append(f"{'on' if a=='true' else 'off'} / attn {at} / act {ac if ac else '-'}")
    rows.append((k[0],k[1],desc,vals))
ws_ex,n_ex=matrix_sheet('Exception Types',['Profile','Type ID','Description'],rows,[26,9,34,26,26,26,26,10])

# ---------- Group permissions ----------
def canon(g): return g.lower()
spell=defaultdict(dict)
for n in ENVS:
    for r in E[n]['Groups']: spell[canon(r['id'])][n]=r['id']
disp={}
for c,d in spell.items():
    disp[c]=d.get('prod') or next(iter(d.values()))
def gp(e):
    d={}
    for r in e['Group_Permission']:
        d[(canon(r['group_id']),r['permission_id'])]=f"{'T' if r.get('allow')=='true' else 'F'} / {'T' if r.get('allow_edit')=='true' else 'F'}"
    return d
G={n:gp(E[n]) for n in ENVS}
kg=sorted(set().union(*[set(v) for v in G.values()]),key=lambda k:(disp.get(k[0],k[0]).lower(),k[1]))
rows=[]
for k in kg:
    names=set(spell.get(k[0],{}).values())
    note='spelling differs across envs' if len(names)>1 else ''
    rows.append((disp.get(k[0],k[0]),k[1],note,[G[n].get(k,ABS) for n in ENVS]))
ws_gp,n_gp=matrix_sheet('Group Permissions',['Group','Permission','Note'],rows,[24,44,26,12,12,12,12,10])
ws_gp['D1'].value='PROD  (allow / allow_edit)'
for c in 'DEFG': ws_gp.column_dimensions[c].width=15
ws_gp['D1'].value=LBL['prod']+' (allow/edit)'; ws_gp['E1'].value=LBL['acc']+' (allow/edit)'; ws_gp['F1'].value=LBL['stg']+' (allow/edit)'; ws_gp['G1'].value=LBL['tst']+' (allow/edit)'

# ---------- Groups ----------
rows=[]
gorder=sorted(spell,key=lambda c:disp[c].lower())
for c in gorder:
    d=spell[c]; g=disp[c]
    rows.append((g,'Present as','',[d.get(n,ABS) for n in ENVS]))
    gm={n:{canon(r['id']):r for r in E[n]['Groups']} for n in ENVS}
    rows.append((g,'Parent group','',[vis(gm[n][c].get('group_id')) if c in gm[n] else ABS for n in ENVS]))
    rows.append((g,'Description','',[vis(gm[n][c].get('description')) if c in gm[n] else ABS for n in ENVS]))
    rows.append((g,'Permission rows, allow + deny (count)','',['__CNT__']*4))
ws_g,n_g=matrix_sheet('Groups',['Group','Attribute',''],rows,[24,28,4,30,30,30,30,10])
for r in range(2,n_g+1):
    if ws_g.cell(r,4).value=='__CNT__':
        for i,col in enumerate('DEFG'):
            ws_g.cell(r,4+i).value=f"=IF({ 'ISNUMBER(0)' },0,0)"  # placeholder replaced below
            gp_col='DEFG'[i]
            ws_g.cell(r,4+i).value=f"=COUNTIFS('Group Permissions'!$A$2:$A${n_gp},$A{r},'Group Permissions'!{gp_col}$2:{gp_col}${n_gp},\"<>{ABS}\")"
# group present-as rows: absent groups -> count 0 is fine

# ---------- Org permissions ----------
def op(e): return {r['permission_id']:f"{'T' if r.get('allow')=='true' else 'F'} / {'T' if r.get('allow_edit')=='true' else 'F'}" for r in e['Organisation_Permission']}
O={n:op(E[n]) for n in ENVS}
ko=sorted(set().union(*[set(v) for v in O.values()]))
rows=[(k,'','',[O[n].get(k,ABS) for n in ENVS]) for k in ko]
ws_op,n_op=matrix_sheet('Org Permissions',['Permission','',''],rows,[44,4,4,15,15,15,15,10])
for i,n in enumerate(ENVS): ws_op.cell(1,4+i).value=LBL[n]+' (allow/edit)'

# ---------- Lists (org default layouts) ----------
def orglists(e):
    ent={r['id']:r for r in e['Entry']}
    le=defaultdict(list)
    for r in e['List_Entry']: le[r['list_id']].append(r)
    out={}
    for o in e['Organisation_List']:
        rows_=sorted(le[o['list_id']],key=lambda r:int(r.get('sequence') or 0))
        out[o['list_type_id']]=[ent.get(r.get('entry_id'),{}).get('label') for r in rows_]
    return out
OL={n:orglists(E[n]) for n in ENVS}
rows=[]
for lt in sorted(set().union(*[set(v) for v in OL.values()]),key=int):
    m=max(len(OL[n].get(lt,[])) for n in ENVS)
    for i in range(m):
        vals=[]
        for n in ENVS:
            l=OL[n].get(lt,[])
            vals.append(vis(l[i]) if i<len(l) else '(none)')
        rows.append((f'List type {lt}',i+1,'',vals))
ws_l,n_l=matrix_sheet('Lists',['Org-default list','Position',''],rows,[18,9,4,26,26,26,26,10])

# ---------- Travel ----------
def trav(e,n):
    d={}
    pm={(r['profile_id'],r['parameter_id']):r.get('parameter_value') for r in e['Profile_Parameter']}
    for prof,par in [('DEFAULT','TravelCalculationOption'),('CommittedActivitiesProfile','TravelCalculationOption'),('STRAIGHTLINE','TravelCalculationOption'),('DEFAULT','RealTimeTravelProvider'),('CommittedActivitiesProfile','RealTimeTravelProvider'),('DEFAULT','TravelTimeProfileId'),('CommittedActivitiesProfile','TravelTimeProfileId'),('STRAIGHTLINE','TravelTimeProfileId'),('DEFAULT','AllowSplitTravel'),('DEFAULT','HierarchicalDatabaseMatrixId')]:
        d[(f'Param: {prof} / {par}')]=vis(pm[(prof,par)]) if (prof,par) in pm else ABS
    ttp=e.get('Travel_Time_Profile',[]); tw=e.get('Travel_Time_Weighting',[]); tpo=e.get('Travel_Time_Polygon',[]); poly=e.get('Polygon',[])
    d['Travel time profile IDs']=', '.join(sorted(r['id'] for r in ttp)) or '(none)'
    d['Weighting rows']=str(len(tw))
    d['Weighting time zone(s)']=', '.join(sorted({r.get('time_zone') or '(none)' for r in tw})) or '(none)'
    d['Polygons defined']=str(len(poly))
    d['Polygon-weighting links']=str(len(tpo))
    ws_=[float(r['weighting']) for r in tpo if r.get('weighting')]
    d['Polygon weighting range']=(f'{min(ws_):g} – {max(ws_):g}' if ws_ else '(none)')
    d['Polygon IDs (sample)']=', '.join(sorted(r['id'] for r in poly)[:2]) + (' …' if len(poly)>2 else '') if poly else '(none)'
    return d
T={n:trav(E[n],n) for n in ENVS}
items=list(T['prod'].keys())
rows=[(k,'','',[T[n][k] for n in ENVS]) for k in items]
ws_t,n_t=matrix_sheet('Travel',['Item','',''],rows,[52,4,4,28,28,28,28,10])

# ---------- Profiles & Other ----------
rows=[]
for pid in sorted(set().union(*[{r['id'] for r in E[n]['Profile']} for n in ENVS])):
    vals=[]
    for n in ENVS:
        m={r['id']:r for r in E[n]['Profile']}
        vals.append(f"{m[pid].get('profile_type')}" if pid in m else ABS)
    desc=''
    for n in ENVS:
        for r in E[n]['Profile']:
            if r['id']==pid and r.get('description'): desc=desc or r['description']
    rows.append(('Profile',pid,desc,vals))
for term in sorted(set().union(*[{(r['term']) for r in E[n].get('Terminology_Organisation',[])} for n in ENVS])):
    vals=[]
    for n in ENVS:
        m={r['term']:r for r in E[n].get('Terminology_Organisation',[])}
        vals.append(f"{m[term].get('alias_cap_singular')}" if term in m else ABS)
    rows.append(('Terminology',term,'alias (capitalised singular)',vals))
sets=sorted(set().union(*[{(r['profile_id'],r['schedule_exception_type_id'],r['sequence']) for r in E[n].get('Org_Schedule_Exc_Type_Data',[])} for n in ENVS]))
for s in sets:
    vals=[]
    for n in ENVS:
        m={(r['profile_id'],r['schedule_exception_type_id'],r['sequence']):r for r in E[n].get('Org_Schedule_Exc_Type_Data',[])}
        vals.append(f"active={m[s]['active']}" if s in m else ABS)
    rows.append(('Exc type data',f'{s[0]} / type {s[1]} / seq {s[2]}','',vals))
for fld in ['account_id','name','status','organisation_id']:
    rows.append(('Organisation',fld,'',[E[n]['Organisation'][0].get(fld,ABS) for n in ENVS]))
ws_o,n_o=matrix_sheet('Profiles & Other',['Item','Key','Notes'],rows,[16,44,34,20,20,20,20,10])

# ---------- Table counts ----------
ws_c=wb.create_sheet('Table Counts')
hdr=['Table']+[LBL[e] for e in ENVS]+['Scope']
for i,h in enumerate(hdr,1):
    c=ws_c.cell(1,i,h); c.font=f_hdr; c.fill=fill_hdr; c.border=border
scope={'Users':'Not compared - user accounts (excluded per request)','System_Version':'Not compared - version history (excluded per request)','Application_Data':'Not compared - per-user saved filters and screen settings','User_Application_Data':'Not compared - links users to their saved filters and settings','User_Custom_List':'Not compared - per-user custom list layouts','User_External_Task':'Not compared - external tasks linked to individual users','User_Group':'Not compared - which users belong to which groups','User_List':'Not compared - per-user list/column layouts','User_Object':'Not compared - objects linked to individual users','User_Parameter':'Not compared - per-user parameter values','User_Permission':'Not compared - per-user permission overrides'}
comp={'Profile_Parameter':'Parameters','Org_Schedule_Exception_Type':'Exception Types','Group_Permission':'Group Permissions','Groups':'Groups','Organisation_Permission':'Org Permissions','Organisation_List':'Lists','List':'Lists (org defaults only)','List_Entry':'Lists (org defaults only)','Entry':'Lists (org defaults only)','Travel_Time_Profile':'Travel','Travel_Time_Weighting':'Travel','Travel_Time_Polygon':'Travel','Polygon':'Travel','Profile':'Profiles & Other','Terminology_Organisation':'Profiles & Other','Org_Schedule_Exc_Type_Data':'Profiles & Other','Organisation':'Profiles & Other'}
tabs=sorted(set().union(*[set(e) for e in E.values()]))
for r,t in enumerate(tabs,2):
    ws_c.cell(r,1,t).font=f_norm
    for i,n in enumerate(ENVS): 
        c=ws_c.cell(r,2+i,len(E[n].get(t,[]))); c.font=f_norm; c.number_format='#,##0'
    s=scope.get(t) or ('Compared – see tab: '+comp[t] if t in comp else 'Not compared - nothing meaningful to compare')
    ws_c.cell(r,6,s).font=f_norm
    for i in range(1,7): ws_c.cell(r,i).border=border
for i,w in enumerate([32,12,12,12,12,44],1): ws_c.column_dimensions[L(i)].width=w
ws_c.freeze_panes='B2'
n_c=len(tabs)+1

# ---------- Versions ----------
import datetime as _dt
def _parse(s): return _dt.datetime.fromisoformat(s).astimezone(_dt.timezone.utc).replace(tzinfo=None)
def _vt(v):
    p=[int(x) for x in v.split('.')]; return tuple(p+[0]*(4-len(p)))
VI={}
for n in ENVS:
    recs=[dict(v=r['version_id'],t=r.get('version_type',''),u=r.get('version_user',''),s=_parse(r['version_stamp'])) for r in E[n].get('System_Version',[])]
    cur=max(recs,key=lambda r:_vt(r['v']))
    ups=sorted([r for r in recs if r['t'].startswith('Upgrade from')],key=lambda r:r['s'])
    pts=sorted([r for r in recs if r['t'].startswith('Update')],key=lambda r:r['s'])
    cr=[r for r in recs if r['t']=='Creation']
    VI[n]=dict(cur=cur['v'],up=(ups[-1] if ups else None),patch=(pts[-1] if pts else None),created=(cr[0]['s'] if cr else None),hist=sorted(recs,key=lambda r:r['s']))

wv=wb.create_sheet('Versions')
wv['A1']='PSO version by environment'; wv['A1'].font=f_title
wv['A2']='From the System_Version table; all times UTC. Current version = highest version recorded. Last upgrade = most recent "Upgrade from" record. Last patch = most recent "Update" record.'
wv['A2'].font=Font(name=FONT,size=9,italic=True,color='595959')
F0=6; F1=F0+len(ENVS)-1
wv['A3']=(f'=IF(COUNTA(B{F0}:B{F1})<2,"Not enough data to compare",IF(COUNTIF(D{F0}:D{F1},"BEHIND")=0,'
          f'"All "&COUNTA(B{F0}:B{F1})&" environments are on PSO "&INDEX(B{F0}:B{F1},MATCH(MAX(Q{F0}:Q{F1}),Q{F0}:Q{F1},0)),'
          f'"VERSION MISMATCH - the environments are NOT all on the same PSO version"))')
wv['A3'].font=Font(name=FONT,size=14,bold=True); wv['A3'].alignment=Alignment(vertical='center')
wv.merge_cells('A3:K3'); wv.row_dimensions[3].height=32
wv['A4']=(f'=IF(COUNTIF(D{F0}:D{F1},"BEHIND")=0,"",IF(COUNTIF(C{F0}:C{F1},C{F0})<COUNTA(C{F0}:C{F1}),'
          f'"Different RELEASES across environments","Same release ("&C{F0}&"), different patch builds"))')
wv['A4'].font=Font(name=FONT,size=11,bold=True,color='C00000'); wv.merge_cells('A4:K4')
hdrs=['Environment','Current version','Release','Status','Last upgrade (UTC)','Upgraded from','Upgraded to','Last patch version','Last patch (UTC)','Days since upgrade','Created (UTC)']
for i,h in enumerate(hdrs,1):
    c=wv.cell(F0-1,i,h); c.font=f_hdr; c.fill=fill_hdr; c.border=border; c.alignment=Alignment(wrap_text=True,vertical='center')
for i,h in enumerate(['major','minor','patch','build','sort key'],13):
    c=wv.cell(F0-1,i,h); c.font=Font(name=FONT,size=9,color='7F7F7F')
DT='yyyy-mm-dd hh:mm'
for k,n in enumerate(ENVS):
    r=F0+k; v=VI[n]
    vals=[LBL[n],v['cur'],None,None,(v['up']['s'] if v['up'] else None),(v['up']['t'].replace('Upgrade from','').strip() if v['up'] else ''),(v['up']['v'] if v['up'] else ''),(v['patch']['v'] if v['patch'] else ''),(v['patch']['s'] if v['patch'] else None),None,v['created']]
    for i,x in enumerate(vals,1):
        c=wv.cell(r,i,x); c.font=f_norm; c.border=border; c.alignment=Alignment(vertical='top')
        if i in (5,9,11): c.number_format=DT
    wv.cell(r,3,f'=M{r}&"."&N{r}'); wv.cell(r,4,f'=IF(B{r}="","UNKNOWN",IF(Q{r}=MAX($Q${F0}:$Q${F1}),"LATEST","BEHIND"))')
    wv.cell(r,10,f'=IF(E{r}="","",INT(TODAY()-E{r}))')
    for i,startpos in zip(range(13,17),(1,21,41,61)):
        wv.cell(r,i,f'=IFERROR(VALUE(TRIM(MID(SUBSTITUTE($B{r},".",REPT(" ",20)),{startpos},20))),0)').font=Font(name=FONT,size=9,color='7F7F7F')
    wv.cell(r,17,f'=M{r}*1000000000+N{r}*1000000+O{r}*1000+P{r}').font=Font(name=FONT,size=9,color='7F7F7F')
    for i in (3,4,10):
        wv.cell(r,i).font=f_bold if i==4 else f_norm; wv.cell(r,i).border=border; wv.cell(r,i).alignment=Alignment(vertical='top')
green=PatternFill('solid',fgColor='2E7D32'); red=PatternFill('solid',fgColor='C00000')
wv.conditional_formatting.add('A3:K3',FormulaRule(formula=['LEFT($A$3,3)="All"'],fill=green,font=Font(name=FONT,bold=True,color='FFFFFF')))
wv.conditional_formatting.add('A3:K3',FormulaRule(formula=['LEFT($A$3,7)="VERSION"'],fill=red,font=Font(name=FONT,bold=True,color='FFFFFF')))
wv.conditional_formatting.add('A3:K3',FormulaRule(formula=['LEFT($A$3,3)="Not"'],fill=fill_diff))
wv.conditional_formatting.add(f'B{F0}:B{F1}',FormulaRule(formula=[f'$D{F0}="BEHIND"'],fill=PatternFill('solid',fgColor='F4B6B6'),font=Font(name=FONT,bold=True,color='9C0006')))
wv.conditional_formatting.add(f'D{F0}:D{F1}',FormulaRule(formula=[f'D{F0}="BEHIND"'],fill=PatternFill('solid',fgColor='F4B6B6'),font=Font(name=FONT,bold=True,color='9C0006')))
wv.conditional_formatting.add(f'D{F0}:D{F1}',FormulaRule(formula=[f'D{F0}="LATEST"'],fill=PatternFill('solid',fgColor='C6EFCE'),font=Font(name=FONT,bold=True,color='006100')))
notes=['Status compares each environment with the highest version in the table (numeric comparison, so 6.13.0.9 is older than 6.13.0.67).',
       'The vendor-stamped SYSTEM_USER patch records carry the same timestamp in every environment, so they say nothing about when an environment was upgraded; they are ignored for the upgrade and patch dates.',
       '"Days since upgrade" is live (TODAY) and will change each time the workbook is opened. Columns M:Q are helper columns for the Status formula.']
for i,t in enumerate(notes):
    c=wv.cell(F1+2+i,1,t); c.font=Font(name=FONT,size=9,italic=True,color='595959'); wv.merge_cells(start_row=F1+2+i,start_column=1,end_row=F1+2+i,end_column=11)
for i,w in enumerate([14,16,10,11,19,14,14,17,19,11,19],1): wv.column_dimensions[L(i)].width=w
wv.column_dimensions.group('M','Q',hidden=True)
wv.sheet_view.showGridLines=False; wv.freeze_panes=f'A{F0}'

wh=wb.create_sheet('Version History')
for i,h in enumerate(['Environment','When (UTC)','Version','Type','User'],1):
    c=wh.cell(1,i,h); c.font=f_hdr; c.fill=fill_hdr; c.border=border
rr=2
for n in ENVS:
    for x in VI[n]['hist']:
        for i,val in enumerate([LBL[n],x['s'],x['v'],x['t'],x['u']],1):
            c=wh.cell(rr,i,val); c.font=f_norm; c.border=border
            if i==2: c.number_format='yyyy-mm-dd hh:mm:ss'
        rr+=1
for i,w in enumerate([14,20,14,22,16],1): wh.column_dimensions[L(i)].width=w
wh.freeze_panes='A2'; wh.auto_filter.ref=f'A1:E{rr-1}'

# ---------- Summary ----------
ws=wb['Sheet']; ws.title='Summary'; wb.move_sheet('Summary',offset=-(len(wb.sheetnames)-1))
wb.move_sheet('Versions',offset=-(wb.sheetnames.index('Versions')-1))
ws.column_dimensions['A'].width=40; 
for c in 'BCDE': ws.column_dimensions[c].width=16
ws.column_dimensions['F'].width=90
def put(r,c,v,font=f_norm,fill=None,al=None):
    x=ws.cell(r,c,v); x.font=font
    if fill: x.fill=fill
    if al: x.alignment=al
    return x
put(1,1,'PSO system data comparison – PROD / ACC (UAT) / STG / TST',f_title)
put(2,1,'Source: DsSystemData exports (sys files), one per environment. Generated 28 Sep 2026.',Font(name=FONT,size=9,italic=True,color='595959'))
r=4
put(r,1,'Scope',f_bold,fill_sub); 
for c in range(2,7): ws.cell(r,c).fill=fill_sub
scope_lines=[
 'Compared: profile parameters, schedule exception types, groups and group permissions, organisation permissions, org-default list layouts, travel-time setup, profiles, terminology.',
 'Not compared: Users and System_Version (as requested), and all per-user tables. Per-user tables hold data tied to individual user accounts: each person’s saved filters, screen settings and list layouts, plus which groups, permissions and parameters are assigned to each user. They are left out because the set of users differs between environments, so comparing them would mostly show noise. This also means group membership (who is in which group) is not compared, only what each group is allowed to do.',
 'List, entry and polygon IDs are GUIDs that differ per environment, so lists are matched on content and travel polygons are summarised by count.',
 'ST_Ops_Mgr / ST_Ops_MGR are treated as the same group (case differs by environment); the Groups tab flags the spelling difference.',
 'AST environment: not available (credentials failed), so not included.',
]
if apikey_same: scope_lines.append('Routing API key values are masked as [API key set]; the key value is identical everywhere it is set.')
else: scope_lines.append('Routing API key values are masked as [API key set]; the key VALUES differ between environments.')
scope_lines.append('Parameter definitions come from the project knowledge base; ones marked [Inferred] rest only on the parameter name and general PSO knowledge, so treat them as unverified.')
scope_lines.append('Legend: amber cell = differs from PROD; “DIFF” = not identical across all four; ␣ marks a leading/trailing space in a value; permissions show allow / allow_edit (T/F).')
for t in scope_lines:
    r+=1; put(r,1,t,al=wrap); ws.merge_cells(start_row=r,start_column=1,end_row=r,end_column=6); ws.row_dimensions[r].height=28
r+=2
put(r,1,'Rows that differ from PROD',f_bold,fill_sub)
for c,h in zip(range(2,6),['Rows compared','ACC (UAT)','STG','TST']): put(r,c,h,f_bold,fill_sub,Alignment(horizontal='center'))
ws.cell(r,6).fill=fill_sub
hdr_row=r
tabs_info=[('Parameters',n_par),('Exception Types',n_ex),('Group Permissions',n_gp),('Groups',n_g),('Org Permissions',n_op),('Lists',n_l),('Travel',n_t),('Profiles & Other',n_o)]
first=r+1
for name,n in tabs_info:
    r+=1; put(r,1,name)
    q=f"'{name}'"
    put(r,2,f"=ROWS({q}!$D$2:$D${n})").alignment=Alignment(horizontal='center')
    for c,col in zip(range(3,6),'EFG'):
        put(r,c,f"=SUMPRODUCT(--NOT(EXACT({q}!${col}$2:${col}${n},{q}!$D$2:$D${n})))").alignment=Alignment(horizontal='center')
last=r
r+=1; put(r,1,'Total',f_bold)
for c in range(2,6):
    put(r,c,f"=SUM({L(c)}{first}:{L(c)}{last})",f_bold).alignment=Alignment(horizontal='center')
for rr in range(hdr_row,r+1):
    for cc in range(1,6): ws.cell(rr,cc).border=border
tot_row=r
r+=1; put(r,1,'Share of rows differing from PROD',f_norm)
for c in range(3,6): 
    x=put(r,c,f"=IF($B{tot_row}=0,0,{L(c)}{tot_row}/$B{tot_row})"); x.number_format='0%'; x.alignment=Alignment(horizontal='center')
r+=1; put(r,1,'Rows not identical across all four',f_norm)
diffs="+".join([f"COUNTIF('{nm}'!$H$2:$H${n},\"DIFF\")" for nm,n in tabs_info])
put(r,2,f"={diffs}").alignment=Alignment(horizontal='center')
r+=1; put(r,1,'PSO version',f_norm); put(r,2,'=Versions!A3',f_bold); ws.merge_cells(start_row=r,start_column=2,end_row=r,end_column=6)
ws.conditional_formatting.add(f'B{r}:F{r}',FormulaRule(formula=[f'LEFT($B{r},3)="All"'],fill=PatternFill('solid',fgColor='C6EFCE'),font=Font(name=FONT,bold=True,color='006100')))
ws.conditional_formatting.add(f'B{r}:F{r}',FormulaRule(formula=[f'LEFT($B{r},7)="VERSION"'],fill=PatternFill('solid',fgColor='C00000'),font=Font(name=FONT,bold=True,color='FFFFFF')))

r+=2; put(r,1,'Key findings',f_bold,fill_sub)
for c in range(2,7): ws.cell(r,c).fill=fill_sub
findings=[
 ('Travel: committed activities','CommittedActivitiesProfile is set to RealTimeTravel (TomTom) in PROD and STG, and to HierarchicalTravelMatrix in ACC and TST. But the profile is only used if DEFAULT.CommittedActivitiesProfileId points at it (a required setup step). That is set in ACC and STG and empty in PROD and TST. So STG is the only environment where real-time travel is fully wired; in PROD the profile exists but DEFAULT does not reference it. Verify: dataset-level parameter overrides are not part of this export and could change that.'),
 ('Travel: default calculation','TST uses StraightLine on DEFAULT; PROD, ACC and STG use HierarchicalTravelMatrix. RealTimeTravelProvider=NONE is set on DEFAULT in PROD and STG only.'),
 ('Travel-time weighting','Only ACC has a full travel-time weighting set (“Default”, America/Regina, 343 polygons, weightings 0.12–2.84). PROD has two Lucky Lake polygons (0.5 each) under DEFAULT plus a “TEST” profile; ACC has none of the Lucky Lake polygons; STG and TST have no travel-time data at all. TST references TravelTimeProfileId values (DEFAULT, STRAIGHTLINE) that do not exist in its own file. ID casing also differs (“Default” in ACC, “DEFAULT” in PROD/TST).'),
 ('AllowSplitTravel','Set (True) only in PROD; absent in ACC, STG and TST. Not documented in the KB, and the KB says implicit-break travel splitting follows split_allowed on the destination activity type with no separate toggle, so this parameter is worth raising with IFS.'),
 ('Committed / shift behaviour','CommitToAllocatedShift is False in PROD and STG, True in ACC and TST. ImplicitBreaksOnOffEventsRequired: True in PROD, False in TST, absent in ACC/STG. SortValuePrecedenceMaximumStatus=40 in PROD and STG only.'),
 ('Exception types','TST has 35 CommittedActivitiesProfile rows and has 110/130/250/280 active in DEFAULT (inactive elsewhere). STG has five DEFAULT types (2000, 2001, 2005, 2500, 3001) and CONTRACTORS 450 that exist nowhere else; the DEFAULT ones are described “Test”, “test 1”, “Test Case”, “Test Case 25” and “test123”, so they look like leftover test data. ACC has an active type 1002 described “nonsense” (TST has it inactive; PROD and STG don’t have it). DEFAULT type 1000 exists only in TST. Type 90 attention is 10 in PROD/STG and 2 in ACC/TST.'),
 ('Admin groups','PROD uses SAST_SYSTEM_ADMIN (24 permissions). ACC/STG/TST use GOGH_ADMIN_PERMISSION (no direct permissions, inherits FNDSCH_ADMIN); ACC and STG also have GOGHSUPERUSER; ACC alone has REG; TST alone has an unnamed GUID group.'),
 ('Group naming','ST_Ops_Mgr in PROD/ACC but ST_Ops_MGR in STG/TST. STG group descriptions say “Sasktel” instead of “SaskTel”.'),
 ('Role permissions','Most group permission rows are explicit DENIES (241 of 341 in PROD), so row counts (e.g. ST_Planner 78 / 91 / 33 / 34) mostly measure how many deny rows an environment carries, not what a role can do. Effectively ALLOWED permissions are close: ST_Planner 13 / 11 / 10 / 13, ST_Scheduler 8 / 6 / 7 / 8, ST_Dispatch 9 / 7 / 9 / 11, ST_WFM_MGR 10 / 14 / 5 / 7 (PROD / ACC / STG / TST). Real differences: ManualChanges is allowed for Planner, Scheduler and Dispatch in STG/TST (Dispatch also in ACC) but not in PROD, where only ST_WFM_MGR has it; ACC ST_WFM_MGR is allowed Administrator, AdministrationWorkspace and AdminTravelProfilesView, which PROD ST_WFM_MGR is not; ViewAllDatasets is allowed for every ST_ role in PROD but denied for some roles in STG/TST. A permission with no row falls back to its default (not visible in the sys file), so confirm in the Workbench.'),
 ('NORTH / SOUTH','Child groups of Administrator in ACC, STG and TST; no parent in PROD. Their permission rows (0 in PROD, 1 in ACC, 8 / 7 in STG and TST) are all explicit denies, so none of them grants anything directly.'),
 ('Org permissions','ViewAllDatasets: allowed in PROD and STG, denied in ACC. STG alone allows AdministrationWorkspace and DataCaptureView at org level; TST adds Administrator and three Gateway auth denials.'),
 ('Lists and clean-up','Org-default column layouts differ (lists 101 and 11 differ in all four). PROD’s Entry table contains junk labels from testing (e.g. dcvdc, edefd, efef). ACC has trailing spaces in ResourceLabel/ResourceText/ResourceSubtext; TST has one in ResourceLabel.'),
 ('Closest to PROD','By total rows, ACC is closest to PROD (about 26% of rows differ), then STG (about 36%), then TST (about 46%). Read the permission part with care: those row counts are inflated by explicit deny rows. On parameters STG is closest (11 rows differ, vs 21 for ACC and 28 for TST) because it shares PROD’s CommitToAllocatedShift, SortValuePrecedenceMaximumStatus, RealTimeTravelProvider and committed-profile travel option. See the counts table above.'),
]
def _d(x): return x['s'].strftime('%Y-%m-%d')
_cur={VI[n]['cur'] for n in ENVS}
_txt=('All four environments are on PSO '+next(iter(_cur))+' (see the Versions tab). ' if len(_cur)==1 else 'The environments are NOT all on the same PSO version (see the Versions tab). ')
_txt+='Last upgrades: '+', '.join(f"{LBL[n].split(' ')[0]} {_d(VI[n]['up'])} ({VI[n]['up']['t'].replace('Upgrade from','').strip()} to {VI[n]['up']['v']})" for n in sorted(ENVS,key=lambda n:VI[n]['up']['s']))+'.'
_p=VI['prod']['up']['s']; _a=VI['acc']['up']['s']
_txt+=f" PROD was upgraded {(_p-_a).days} days after ACC."
findings.insert(len(findings)-1,('PSO versions',_txt))
for k,v in findings:
    r+=1; put(r,1,k,f_bold,al=wrap); put(r,2,v,al=wrap); ws.merge_cells(start_row=r,start_column=2,end_row=r,end_column=6)
    ws.row_dimensions[r].height=max(30,15*(len(v)//115+1))
    ws.cell(r,1).alignment=wrap
r+=2; put(r,1,'Tabs',f_bold,fill_sub)
for c in range(2,7): ws.cell(r,c).fill=fill_sub
tabtxt=[('Versions','Current PSO version, last upgrade and last patch per environment, with a red/green banner if they are not all on the same version.'),('Version History','Every System_Version record per environment, oldest first.'),('Parameters','Profile parameter values for every profile (API key masked), with a plain-English definition (marked [Inferred] where the KB is silent).'),('Exception Types','Active / attention / activation per exception type and profile.'),('Group Permissions','Every permission per group, by environment.'),('Groups','Group presence, parent, description and permission counts.'),('Org Permissions','Organisation-level permissions.'),('Lists','Org-default list column layouts, position by position.'),('Travel','Travel calc settings and travel-time profile/polygon inventory.'),('Profiles & Other','Profiles, terminology, exception type data, organisation record.'),('Table Counts','Row counts for every table with scope notes.')]
for k,v in tabtxt:
    r+=1; put(r,1,k); put(r,2,v); ws.merge_cells(start_row=r,start_column=2,end_row=r,end_column=6)
ws.sheet_view.showGridLines=False
from openpyxl.worksheet.properties import PageSetupProperties
for w in wb.worksheets:
    w.sheet_properties.pageSetUpPr=PageSetupProperties(fitToPage=True)
    w.page_setup.orientation='landscape'; w.page_setup.fitToWidth=1; w.page_setup.fitToHeight=0
    if w.title!='Summary': w.print_title_rows='1:1'
wb.save('/mnt/user-data/outputs/PSO_environment_comparison.xlsx')
print("saved",{'par':n_par,'ex':n_ex,'gp':n_gp,'g':n_g,'op':n_op,'l':n_l,'t':n_t,'o':n_o},"apikey_same",apikey_same)
