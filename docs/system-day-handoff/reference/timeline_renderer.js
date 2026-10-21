// Timeline renderer used by the system-day page. Plain SVG, no library. Lanes are plug-ins: each gives a title,
// a height and draw(g, x, laneTop, laneHeight, domain). mount() adds the Window / pan / zoom row, drag-to-zoom,
// wheel zoom (one redraw per animation frame), double-click reset, and delegated tooltips (one listener per chart,
// marks carry data-tip). T0/T1 are the day's bounds in epoch ms; css(--var) reads the page's colour tokens.
const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const hhmmss = t => new Date(t).toISOString().substr(11,8);
const hhmm = t => new Date(t).toISOString().substr(11,5);
const tip = document.getElementById('tip');
function moveTip(e){ const x=Math.min(e.clientX+14, innerWidth-380), y=Math.min(e.clientY+14, innerHeight-120); tip.style.left=x+'px'; tip.style.top=y+'px'; }
function hideTip(){ tip.style.display='none'; }
const NS='http://www.w3.org/2000/svg';
function el(n,a,p){ const e=document.createElementNS(NS,n); for(const k in a) e.setAttribute(k,a[k]); if(p) p.appendChild(e); return e; }
function txt(p,x,y,s,cls,a){ const t=el('text',Object.assign({x,y,class:cls||''},a||{}),p); t.textContent=s; return t; }

// ---------- generic lane chart ----------
function chart(svg, dom, lanes, opts){
  opts=opts||{}; svg.innerHTML='';
  const W = svg.parentElement.clientWidth-28, L=150, R=34, top=18; window.__W=W;
  const x = t => L + (t-dom[0])/(dom[1]-dom[0])*(W-L-R);
  let y=top; const laneY=[];
  for(const ln of lanes){ laneY.push(y); y+=ln.h; }
  const H=y+28; svg.setAttribute('viewBox',`0 0 ${W} ${H}`); svg.setAttribute('height',H);
  // grid / axis
  const span=dom[1]-dom[0]; const steps=[1e3,2e3,5e3,10e3,15e3,30e3,60e3,2*60e3,5*60e3,10*60e3,15*60e3,30*60e3,3600e3]; const pxPer=s=>(W-L-R)*s/span; const step=steps.find(s=>pxPer(s)>=72)||3600e3;
  const g=el('g',{class:'axis'},svg);
  for(let t=Math.ceil(dom[0]/step)*step;t<=dom[1];t+=step){ el('line',{x1:x(t),x2:x(t),y1:top,y2:y,class:'gridline'},g); txt(g,x(t),y+14,step>=60e3?hhmm(t):hhmmss(t),'',{'text-anchor':'middle'}); }
  lanes.forEach((ln,i)=>{ const ly=laneY[i]; el('line',{x1:0,x2:W,y1:ly,y2:ly,class:'sep'},svg);
    txt(svg,4,ly+14,ln.title,'lane-title'); if(ln.sub) txt(svg,4,ly+27,ln.sub,'lane-sub');
    const grp=el('g',{},svg); ln.draw(grp, x, ly, ln.h, dom); });
  // crosshair + interactions (listeners registered once per svg; state lives on the element)
  const st=svg.__st=(svg.__st||{}); st.dom=dom; st.W=W; st.L=L; st.R=R; st.top=top; st.bottom=y; st.onZoom=opts.onZoom;
  st.ch=el('line',{y1:top,y2:y,stroke:css('--ink3'),'stroke-dasharray':'3 3',style:'display:none'},svg);
  st.chT=txt(svg,0,top-4,'','axis'); st.chT.setAttribute('fill',css('--ink2')); st.chT.setAttribute('font-size','11');
  st.rect=el('rect',{y:top,height:y-top,fill:css('--ink3'),'fill-opacity':.15,style:'display:none'},svg);
  if(!st.bound){ st.bound=true;
    const pxOf=e=>{const r=svg.getBoundingClientRect(); return (e.clientX-r.left)*(st.W/r.width);};
    const toT=px=>st.dom[0]+(px-st.L)/(st.W-st.L-st.R)*(st.dom[1]-st.dom[0]);
    svg.addEventListener('mousemove',e=>{ const px=pxOf(e); if(px<st.L||px>st.W-st.R){st.ch.style.display='none';st.chT.textContent='';} else { st.ch.style.display='';st.ch.setAttribute('x1',px);st.ch.setAttribute('x2',px); st.chT.textContent=hhmmss(toT(px))+' UTC'; st.chT.setAttribute('x',Math.min(px+4,st.W-80)); }
      const tgt=e.target.closest&&e.target.closest('[data-tip]'); if(tgt){ if(tip.__for!==tgt){ tip.__for=tgt; tip.textContent=tgt.getAttribute('data-tip'); tip.style.display='block'; } moveTip(e); } else if(tip.__for){ tip.__for=null; hideTip(); } });
    svg.addEventListener('mouseleave',()=>{st.ch.style.display='none';st.chT.textContent=''; tip.__for=null; hideTip();});
    let sel=null;
    svg.addEventListener('mousedown',e=>{ if(!st.onZoom||e.button!==0) return; sel=[pxOf(e),pxOf(e)]; st.rect.style.display=''; st.rect.setAttribute('x',sel[0]); st.rect.setAttribute('width',0); e.preventDefault(); });
    addEventListener('mousemove',e=>{ if(!sel) return; sel[1]=pxOf(e); const a=Math.min(...sel),b=Math.max(...sel); st.rect.setAttribute('x',a); st.rect.setAttribute('width',b-a); });
    addEventListener('mouseup',e=>{ if(!sel) return; const a=Math.max(st.L,Math.min(...sel)),b=Math.min(st.W-st.R,Math.max(...sel)); sel=null; st.rect.style.display='none'; if(b-a>6) st.onZoom([toT(a),toT(b)]); });
    svg.addEventListener('dblclick',()=>{ if(st.onZoom) st.onZoom(null); });
    let wheelPending=null;
    svg.addEventListener('wheel',e=>{ if(!st.onZoom) return; e.preventDefault(); const px=pxOf(e); if(px<st.L||px>st.W-st.R) return; const tc=toT(px); const f=e.deltaY>0?1.25:0.8; wheelPending=[tc-(tc-st.dom[0])*f, tc+(st.dom[1]-tc)*f]; if(!st.raf){ st.raf=requestAnimationFrame(()=>{ st.raf=null; const d=wheelPending; wheelPending=null; st.onZoom(d); }); } },{passive:false}); }
}
function mark(g,x,y,r,fill,stroke,t,ttxt){ const c=el('circle',{cx:x,cy:y,r,fill:fill||'none',stroke:stroke||'none','stroke-width':2},g); el('circle',{cx:x,cy:y,r:Math.max(r+4,7),fill:'transparent','data-tip':ttxt},g); return c; }
function inDom(t,dom){ return t>=dom[0]&&t<=dom[1]; }
function minuteSeries(list, key, dom){ // list of {m:'HH:MM', n}
  return list.filter(r=>key(r)).map(r=>({t:Date.UTC(2026,9,20,+r.m.slice(0,2),+r.m.slice(3,5)),n:r.n})).filter(r=>inDom(r.t,dom)||inDom(r.t+60e3,dom));
}
function bars(g,x,ly,h,rows,maxN,color,label){ const base=ly+h-6; const sc=(h-30)/Math.max(1,maxN); rows.forEach(r=>{ const x0=Math.max(150,x(r.t)), x1=Math.min(window.__W-34,x(r.t+60e3)); const w=Math.max(1,x1-x0-1); if(x1<=x0) return; const bh=r.n*sc; el('rect',{x:x0,y:base-bh,width:w,height:bh,fill:color,rx:1,'data-tip':`${hhmm(r.t)}  ${label}: ${r.n}`},g); }); txt(g,window.__W-30,ly+h-6,'0','ylab',{'text-anchor':'start'}); txt(g,window.__W-30,ly+26,String(maxN),'ylab',{'text-anchor':'start'}); }


const MINSPAN=30e3; const charts=[];
function mount(id, lanes, defDom, extra){
  const svg=document.getElementById(id); const card=svg.parentElement; const st={dom:defDom.slice(),def:defDom.slice(),lanes,svg};
  const row=document.createElement('div'); row.className='row';
  row.innerHTML='<span class="meta">Window:</span>'+[['1 min',60e3],['5 min',300e3],['15 min',900e3],['30 min',1800e3],['1 h',3600e3]].map(([l,w])=>`<button data-w="${w}">${l}</button>`).join('')+'<button data-a="panL">◀ pan</button><button data-a="panR">pan ▶</button><button data-a="zin">zoom in</button><button data-a="zout">zoom out</button><button data-a="reset">reset</button><button data-a="day">full day</button><span class="meta" data-a="label"></span>';
  card.insertBefore(row,svg);
  const label=row.querySelector('[data-a=label]');
  st.set=d=>{ if(!d){ st.dom=st.def.slice(); } else { let a=d[0], b=d[1]; const w=Math.min(b-a,T1-T0); if(a<T0){a=T0;b=T0+w;} if(b>T1){b=T1;a=T1-w;} if(b-a<MINSPAN){ const c=(a+b)/2; a=c-MINSPAN/2; b=c+MINSPAN/2; } st.dom=[a,b]; } st.draw(); };
  st.draw=()=>{ chart(svg,st.dom,st.lanes,{onZoom:st.set}); const sp=st.dom[1]-st.dom[0]; label.textContent=`${hhmmss(st.dom[0])} – ${hhmmss(st.dom[1])} UTC (${sp>=3600e3?(sp/3600e3).toFixed(1)+' h':(sp/60e3).toFixed(1)+' min'})`; if(extra) extra(st); };
  st.act=a=>{ const c=(st.dom[0]+st.dom[1])/2, w=st.dom[1]-st.dom[0]; if(a==='panL') st.set([st.dom[0]-w/2,st.dom[1]-w/2]); else if(a==='panR') st.set([st.dom[0]+w/2,st.dom[1]+w/2]); else if(a==='zin') st.set([c-w/4,c+w/4]); else if(a==='zout') st.set([c-w,c+w]); else if(a==='reset') st.set(null); else if(a==='day') st.set([T0,T1]); };
  row.addEventListener('click',e=>{ const b=e.target.closest('button'); if(!b) return; if(b.dataset.w){ const c=(st.dom[0]+st.dom[1])/2, w=+b.dataset.w; st.set([c-w/2,c+w/2]); } else st.act(b.dataset.a); });
  svg.addEventListener('mouseenter',()=>{ window.__hot=st; }); svg.addEventListener('mouseleave',()=>{ if(window.__hot===st) window.__hot=null; });
  st.draw(); charts.push(st); return st;
}
addEventListener('keydown',e=>{ const st=window.__hot; if(!st||e.target.tagName==='INPUT') return; if(e.key==='ArrowLeft') st.act('panL'); else if(e.key==='ArrowRight') st.act('panR'); else if(e.key==='+'||e.key==='=') st.act('zin'); else if(e.key==='-') st.act('zout'); else return; e.preventDefault(); });
