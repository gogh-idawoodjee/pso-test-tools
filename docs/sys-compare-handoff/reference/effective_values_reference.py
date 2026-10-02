import csv,re,sys
sys.path.insert(0,'/home/claude')
from cmp import load
def load_catalog(path='/mnt/project/pso_parameters_reference.csv'):
    cat={}
    for r in csv.DictReader(open(path,encoding='utf-8-sig')):
        cat.setdefault(r['parameter_id'].lower(),[]).append(dict(app=r['application'],type=r['data_type'],default=r['default_value'],desc=r['description']))
    return cat
DUR=re.compile(r'^P(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$')
def vis(s):
    if s is None or s=='': return '(null)'
    lead=len(s)-len(s.lstrip(' ')); trail=len(s)-len(s.rstrip(' '))
    return ('\u2423'*lead+s.strip(' ')+'\u2423'*trail) if (lead or trail) else s
def canon(v,typ):
    if v is None: return None
    t=(typ or '').upper()
    if t=='BOOLEAN': return v.strip().lower()
    if t=='INTEGER':
        try: return str(int(v.strip()))
        except: return v
    if t=='DOUBLE':
        try: return repr(float(v.strip()))
        except: return v
    if t=='TIMESPAN':
        m=DUR.match(v.strip())
        if m and any(m.groups()):
            d,h,mi,s=[float(x) if x else 0.0 for x in m.groups()]
            return 'dur:%g'%(d*86400+h*3600+mi*60+s)
        return v
    return v
def entry(cat,pid,app):
    l=cat.get(pid.lower())
    if not l: return None
    for e in l:
        if e['app']==app: return e
    return l[0]
def resolve(env,prof,pid,app,cat,inherit=False):  # profiles do NOT inherit from each other
    app=app or ''
    """env = dict(profiles=set, params={(profile,pid):value}) -> (display, canonical)"""
    if prof not in env['profiles']: return '(no profile)','(no profile)'
    e=entry(cat,pid,app); typ=e['type'] if e else None
    k=(prof,pid,app)
    mask='key' in pid.lower()
    def show(v): return '(null)' if v in (None,'') else ('[API key set]' if mask else vis(v))
    if k in env['params']:
        v=env['params'][k]; return show(v), canon('' if v is None else v,typ)
    if inherit and prof!='DEFAULT' and ('DEFAULT',pid,app) in env['params']:
        v=env['params'][('DEFAULT',pid,app)]; return '(inherited: %s)'%show(v), canon('' if v is None else v,typ)
    if e is not None:
        d=e['default']; return '(default: %s)'%('blank' if d=='' else ('[API key set]' if mask and d else d)), canon(d,typ)
    return '(absent)','(absent)'
def env_model(rows):
    return dict(profiles={r['id'] for r in rows['Profile']},params={(r['profile_id'],r['parameter_id'],r.get('parameter_application_type_id','')):r.get('parameter_value') for r in rows['Profile_Parameter']})
