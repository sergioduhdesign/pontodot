/* GEO2030 · acabamento (carrega depois do script principal) */
(function(){
"use strict";
var NS="http://www.w3.org/2000/svg", isMobile=function(){return innerWidth<760;};
var pillsG=null, pills=[];

/* ============ 1. FAIXAS NOS ANÉIS: nome + porcentagem, na cor da linha ============ */
function pillText(c){return Math.round(c.pct*100)+"%";}
function buildPills(){
  var svg=document.getElementById("clockSvg"); if(!svg||pillsG) return;
  pillsG=document.createElementNS(NS,"g"); pillsG.id="pills";
  svg.insertBefore(pillsG,document.getElementById("liftG"));
  RINGS.forEach(function(r,i){
    var c=byRing[i]; if(!c||!(c.pct>0)) return;
    var g=document.createElementNS(NS,"g"); g.setAttribute("class","pill"); g.setAttribute("tabindex","0"); g.setAttribute("role","link");
    g.setAttribute("aria-label",c.name+(c.pct>0?", "+Math.round(c.pct*100)+"% verificado":"")); g.dataset.i=i;
    var rc=document.createElementNS(NS,"rect"); rc.setAttribute("fill",r.color); rc.setAttribute("rx","3.5"); rc.setAttribute("height","17");
    var tx=document.createElementNS(NS,"text"); tx.setAttribute("fill",c.on||"#fff");
    var t1=document.createElementNS(NS,"tspan"); t1.textContent=c.name;
    var t2=document.createElementNS(NS,"tspan"); t2.setAttribute("class","pc"); t2.setAttribute("dx","8"); t2.textContent=pillText(c);
    tx.appendChild(t1); tx.appendChild(t2); g.appendChild(rc); g.appendChild(tx); pillsG.appendChild(g);
    g.addEventListener("pointerenter",function(e){if(e.pointerType==="mouse")wantHover(i);});
    g.addEventListener("pointerleave",function(e){if(e.pointerType==="mouse")wantLeave();});
    g.addEventListener("focus",function(){hover(i);});
    g.addEventListener("blur",function(){if(opened===null)unhover();});
    g.addEventListener("click",function(){location.hash="#/compromissos/"+c.slug;});
    g.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" "){e.preventDefault();location.hash="#/compromissos/"+c.slug;}});
    pills[i]={g:g,rc:rc,tx:tx,r:r,c:c};
  });
}
var placeRaf=0;
function placePills(){
  if(!pillsG||isMobile()) return;
  var k=G.k,cx=G.cx,cy=G.cy,placed=[],R0=330*0.1*k;
  RINGS.forEach(function(r,i){
    var p=pills[i]; if(!p) return;
    var bb=p.tx.getBBox(), w=Math.ceil(bb.width)+22, h=17;
    p.rc.setAttribute("width",w); p.rc.setAttribute("x",-w/2); p.rc.setAttribute("y",-h/2);
    p.tx.setAttribute("x",-w/2+11); p.tx.setAttribute("y",0.5);
    var path=document.getElementById("ring"+i), L=r.L, base=r.hours[p.c.n%12]||0, step=L*0.012, best=null;
    for(var n=0;n<40&&!best;n++){
      var off=(n%2?1:-1)*Math.ceil(n/2)*step, s=((base+off)%L+L)%L, pt=path.getPointAtLength(s);
      var x=cx+pt.x*k, y=cy+pt.y*k, box={x:x-w/2-4,y:y-h/2-3,w:w+8,h:h+6}, ok=true;
      if(Math.hypot(x-cx,y-cy)<R0+w/2*0.2) ok=false;
      for(var j=0;ok&&j<placed.length;j++){var q=placed[j];if(box.x<q.x+q.w&&box.x+box.w>q.x&&box.y<q.y+q.h&&box.y+box.h>q.y)ok=false;}
      if(ok) best={x:x,y:y,box:box};
    }
    if(!best){var pt0=path.getPointAtLength(base);best={x:cx+pt0.x*k,y:cy+pt0.y*k,box:{x:0,y:0,w:0,h:0}};}
    placed.push(best.box); p.g.setAttribute("transform","translate("+best.x.toFixed(1)+" "+best.y.toFixed(1)+")");
  });
}
function schedulePills(){cancelAnimationFrame(placeRaf);placeRaf=requestAnimationFrame(placePills);}

/* ============ 2. ZOOM: trava as outras faixas até a ativa pousar de volta ============ */
var settleT=0, lastPt={x:-1,y:-1};
document.addEventListener("pointermove",function(e){lastPt.x=e.clientX;lastPt.y=e.clientY;},{passive:true});
function tMid(){var v=parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--t-mid"));return (isFinite(v)?v:1150)+90;}
var hitEls=[];
function tagHits(){document.querySelectorAll("#clock .hit").forEach(function(e,i){e.dataset.i=i;hitEls[i]=e;});}

window.wantHover=function(i){
  clearTimeout(leaveTimer); clearTimeout(hoverTimer);
  if(opened!==null||hovered===i) return;
  if(hovered>=0) return;           /* há uma faixa ativa ou voltando: nenhuma outra responde */
  hover(i);
};
window.wantLeave=function(){clearTimeout(leaveTimer);leaveTimer=setTimeout(function(){unhover();},260);};

function labelHtml(c,open){
  var parts=open?[c.status,c.ver&&c.ver.toLowerCase()]:[c.status,c.pct>0?Math.round(c.pct*100)+"% verificado":"",c.pulse?"pulso de "+c.pulse.toLowerCase():"",isMobile()?"toque para abrir":""];
  parts=parts.filter(Boolean);
  return c.name+(parts.length?'<span class="st">'+parts.join(" · ")+"</span>":"");
}
window.hover=function(i){
  if(opened!==null) return;
  clearTimeout(settleT);
  var c=byRing[i],r=RINGS[i];
  clock.querySelectorAll(".on").forEach(function(x){x.classList.remove("on");});
  var a=document.getElementById("ring"+i); if(a)a.classList.add("on"); var pg=document.getElementById("prog"+i); if(pg)pg.classList.add("on");
  hovered=i;
  lift.setAttribute("d",r.d);lift.setAttribute("stroke",r.color);label.style.setProperty("--lc",r.color);
  var hit=document.getElementById("liftHit");
  lift.classList.remove("up");hit.classList.remove("up");lift.style.transition=hit.style.transition="none";applyLift();
  lift.classList.add("show");liftedAt=performance.now();
  requestAnimationFrame(function(){requestAnimationFrame(function(){
    if(hovered!==i) return;
    lift.style.transition=hit.style.transition="";lift.classList.add("up");applyLift();
  });});
  body.classList.add("dim","lock");
  label.innerHTML=labelHtml(c,false);label.classList.add("show");placeLabel();
};
window.unhover=function(){
  if(opened!==null||hovered<0) return;
  var i=hovered, hit=document.getElementById("liftHit");
  body.classList.remove("dim");                       /* o fundo volta enquanto a faixa desce */
  label.classList.remove("show");
  lift.classList.remove("up");hit.classList.remove("up");
  applyLift();                                        /* mesmo movimento, no sentido contrário */
  clearTimeout(settleT);
  settleT=setTimeout(function(){
    if(hovered!==i||opened!==null) return;
    hovered=-1;lift.classList.remove("show");
    clock.querySelectorAll(".on").forEach(function(x){x.classList.remove("on");});
    body.classList.remove("lock");
    retargetPointer();
  },tMid());
};
/* depois que a faixa pousa, se o mouse já está sobre outra, ela passa a valer */
function retargetPointer(){
  if(lastPt.x<0||isMobile()) return;
  var el=document.elementFromPoint(lastPt.x,lastPt.y); if(!el) return;
  var h=el.closest&&el.closest(".pill");
  if(h&&h.dataset.i!==undefined) wantHover(+h.dataset.i);
}

/* ============ 3. CONTEÚDO: fotos distribuídas, galeria, tudo condicional ============ */
var ic={p:'<svg viewBox="0 0 16 16"><path d="M10 3L5 8l5 5"/></svg>',n:'<svg viewBox="0 0 16 16"><path d="M6 3l5 5-5 5"/></svg>'};
var lbList=[],lbI=0,lb=null;
function figHtml(f,list,idx,cls){
  return '<figure class="'+(cls||"")+'" data-l="'+list+'" data-i="'+idx+'"><img src="'+geoEsc(f.src)+'" alt="'+geoEsc(f.cap||"")+'" loading="lazy" decoding="async">'+(f.cap?"<figcaption>"+geoRich(f.cap)+"</figcaption>":"")+"</figure>";
}
function galHtml(g){
  return '<h3>Galeria</h3><div class="gal"><div class="tr">'+g.map(function(f,i){return figHtml(f,"g",i);}).join("")+'</div><div class="ct"><span class="no">1 / '+g.length+'</span><div class="bt"><button type="button" class="gp" aria-label="Anterior">'+ic.p+'</button><button type="button" class="gn" aria-label="Próxima">'+ic.n+"</button></div></div></div>";
}
window.renderCommit=function(c){
  var i=ordered.findIndex(function(x){return x.slug===c.slug;}),prev=ordered[(i+11)%12],next=ordered[(i+1)%12];
  var ms=MISSIONS.filter(function(m){return (c.mis||[]).indexOf(m.id)>=0;});
  var foot=document.getElementById("foot"); foot.hidden=false;
  foot.innerHTML='<div>Estado<span>'+(c.status||"—")+'</span></div><div>Verificado<span>'+(c.pct?Math.round(c.pct*100)+"%":"—")+'</span></div><div>Último pulso<span>'+((c.pulse||"—").replace(" de "," "))+"</span></div>";
  var col=document.getElementById("commitCol"); col.scrollTop=0;
  var blocks=[];
  if(c.resp) blocks.push("<h3>O que a Geoville assumiu</h3><p>"+c.resp+(c.deadline?" <b>"+c.deadline+(/[.!?]$/.test(c.deadline)?"":".")+"</b>":"")+"</p>");
  if(c.now) blocks.push("<h3>O que está sendo feito agora</h3><p>"+c.now+"</p>");
  if(c.tl&&c.tl.length) blocks.push('<h3>Linha do tempo</h3><ul class="tl">'+c.tl.map(function(t){return '<li class="'+(t.soft?"soft":"")+'">'+(t.d?"<time>"+t.d+"</time>":"")+t.t+"</li>";}).join("")+"</ul>");
  if(c.ev&&c.ev.length) blocks.push('<h3>Evidências públicas</h3><div class="ev">'+c.ev.map(function(e){return "<div><b>"+e.b+"</b>"+e.t+(e.l?"<small>"+e.l+"</small>":"")+"</div>";}).join("")+"</div>");
  if(c.who&&c.who.length) blocks.push("<h3>Quem participa</h3><p>"+c.who.join(" · ")+"</p>");
  if(ms.length) blocks.push('<h3>Missões abertas</h3><div class="mis">'+ms.map(function(m){return '<article><div><h4>'+m.t+'</h4><p>'+m.p+'</p></div><a class="btn" href="#/missoes/'+m.id+'">'+(m.st==="Aberta"?"Quero participar":m.st)+'</a><div class="meta"><span>'+m.who+"</span><span>"+m.fmt+"</span><span>"+m.when+"</span><span>"+m.vagas+"</span></div></article>";}).join("")+"</div>");
  /* fotos: distribuídas de forma uniforme ao longo dos blocos de texto */
  var F=c.fotos||[], B=blocks.length, slots={};
  F.forEach(function(f,j){var p=B?Math.min(B-1,Math.max(0,Math.round((j+1)*Math.min(B,5)/(F.length+1))-1)):-1;(slots[p]=slots[p]||[]).push(j);});
  var out=[];
  if(c.why) out.push('<p class="why">'+c.why+"</p>");
  if(c.terr) out.push('<p class="terr-l" style="font-size:10.5px;letter-spacing:.24em;text-transform:uppercase;opacity:.85;margin:-14px 0 28px">'+c.terr+"</p>");
  if(!B&&F.length) slots[-1]=F.map(function(_,j){return j;});
  var put=function(arr){if(!arr)return;out.push('<div class="figs'+(arr.length===1?" one":"")+'">'+arr.map(function(j){return figHtml(F[j],"f",j);}).join("")+"</div>");};
  put(slots[-1]);
  blocks.forEach(function(b,bi){out.push(b);put(slots[bi]);});
  if(c.gal&&c.gal.length) out.push(galHtml(c.gal));
  if(c.next) out.push('<h3>Próximo passo</h3><p class="next">'+c.next+"</p>");
  out.push('<p style="margin-top:26px"><button class="btn" onclick="share(\''+c.name.replace(/'/g,"\\'")+' · GEO2030\')">Compartilhar este compromisso</button></p>');
  if(c.method) out.push('<h3>Metodologia e limites</h3><div class="method">'+c.method+"</div>");
  out.push('<div class="pn"><a href="#/compromissos/'+prev.slug+'">Anterior<small>'+prev.name+'</small></a><a href="#/compromissos/'+next.slug+'">Próximo<small>'+next.name+"</small></a></div>");
  col.innerHTML=out.join("");
  col._f=F; col._g=c.gal||[];
  var tr=col.querySelector(".gal .tr");
  if(tr){
    var no=col.querySelector(".gal .no"),gp=col.querySelector(".gp"),gn=col.querySelector(".gn");
    var step=function(d){var f=tr.querySelector("figure");var w=f?f.getBoundingClientRect().width+12:300;tr.scrollBy({left:d*w});};
    var upd=function(){var f=tr.querySelector("figure"),w=f?f.getBoundingClientRect().width+12:300;var n=Math.round(tr.scrollLeft/w);no.textContent=(n+1)+" / "+col._g.length;gp.disabled=tr.scrollLeft<4;gn.disabled=tr.scrollLeft+tr.clientWidth>=tr.scrollWidth-4;};
    gp.onclick=function(){step(-1);};gn.onclick=function(){step(1);};tr.addEventListener("scroll",function(){requestAnimationFrame(upd);},{passive:true});upd();
  }
};
var _open=window.open;
window.open=function(slug){_open(slug);var c=bySlug[slug];if(c&&opened===slug){label.innerHTML=labelHtml(c,true);}};
document.addEventListener("click",function(e){
  var f=e.target.closest&&e.target.closest("#commitCol figure[data-l]"); if(!f) return;
  var col=document.getElementById("commitCol"),list=f.dataset.l==="g"?col._g:col._f;
  lightbox(list,+f.dataset.i);
});

/* ============ 4. LIGHTBOX ============ */
function lightbox(list,i){
  if(!list||!list.length) return;
  if(!lb){
    lb=document.createElement("div");lb.id="lb";lb.setAttribute("role","dialog");lb.setAttribute("aria-modal","true");
    lb.innerHTML='<div class="top"><span class="no"></span><button type="button" class="x">Fechar</button></div><button class="nv p" type="button" aria-label="Anterior">'+ic.p+'</button><img alt=""><div class="cap"></div><button class="nv n" type="button" aria-label="Próxima">'+ic.n+"</button>";
    document.body.appendChild(lb);
    lb.querySelector(".x").onclick=closeLb;lb.querySelector(".p").onclick=function(e){e.stopPropagation();go(-1);};lb.querySelector(".n").onclick=function(e){e.stopPropagation();go(1);};
    lb.addEventListener("click",function(e){if(e.target===lb)closeLb();});
    var x0=null;lb.addEventListener("touchstart",function(e){x0=e.touches[0].clientX;},{passive:true});
    lb.addEventListener("touchend",function(e){if(x0==null)return;var dx=e.changedTouches[0].clientX-x0;x0=null;if(Math.abs(dx)>50)go(dx<0?1:-1);});
  }
  lbList=list;lbI=i;lb.classList.toggle("solo",list.length<2);show();
  lb.classList.add("on");requestAnimationFrame(function(){lb.classList.add("in");});
}
function show(){
  var f=lbList[lbI],im=lb.querySelector("img");im.src=f.big||f.src;im.alt=f.cap||"";
  lb.querySelector(".cap").innerHTML=f.cap?geoRich(f.cap):"";lb.querySelector(".no").textContent=lbList.length>1?(lbI+1)+" / "+lbList.length:"";
  var nx=lbList[(lbI+1)%lbList.length];if(nx&&nx.big){var pre=new Image();pre.src=nx.big;}
}
function go(d){lbI=(lbI+d+lbList.length)%lbList.length;show();}
function closeLb(){if(!lb)return;lb.classList.remove("in");setTimeout(function(){lb.classList.remove("on");},350);}
document.addEventListener("keydown",function(e){
  if(!lb||!lb.classList.contains("on"))return;
  if(e.key==="Escape"){e.stopPropagation();closeLb();}else if(e.key==="ArrowRight")go(1);else if(e.key==="ArrowLeft")go(-1);
},true);

/* ============ início ============ */
function init(){
  tagHits();buildPills();
  var run=function(){placePills();};
  run();
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(run);
  addEventListener("resize",schedulePills);
  label.addEventListener("pointerenter",function(){clearTimeout(leaveTimer);});
  label.addEventListener("pointerleave",function(e){if(e.pointerType==="mouse"&&hovered>=0&&opened===null)wantLeave();});
  /* link direto para um compromisso: o script principal já abriu com o desenho antigo, refaz com o novo */
  if(opened!==null&&bySlug[opened]){var c=bySlug[opened];renderCommit(c);label.innerHTML=labelHtml(c,true);}
}
if(typeof byRing!=="undefined")init();
})();
