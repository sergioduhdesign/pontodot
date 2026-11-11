/* GEO2030 · aplica o conteúdo publicado (conteudo/compromissos.json) sobre os dados de base.
   Roda ANTES do desenho do relógio. Campo ausente no conteúdo = não aparece no site. */
(function(){
  var G=window.GEO_CONTENT; if(!G||!G.compromissos||typeof C==="undefined") return;
  var esc=function(s){return String(s==null?"":s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");};
  var rich=function(s){s=esc(s).trim();if(!s)return "";return s.replace(/\*\*(.+?)\*\*/g,"<b>$1</b>").replace(/\n/g,"<br>");};
  window.geoRich=rich; window.geoEsc=esc;
  C.forEach(function(c){
    var r=G.compromissos[String(c.n)]||{};
    if(r.name) c.name=String(r.name).trim();
    c.status=rich(r.status);
    var p=parseFloat(r.pct); c.pct=isFinite(p)&&p>0?Math.min(1,p>1?p/100:p):0;
    c.ver=rich(r.ver); c.pulse=rich(r.pulse); c.terr=rich(r.terr);
    c.why=rich(r.why); c.resp=rich(r.resp); c.deadline=rich(r.deadline);
    c.now=rich(r.now); c.next=rich(r.next); c.method=rich(r.method);
    c.who=(r.who||[]).map(rich).filter(Boolean);
    c.tl=(r.tl||[]).map(function(t){return {d:rich(t.d),t:rich(t.t),soft:t.soft?1:0};}).filter(function(t){return t.t||t.d;});
    c.ev=(r.ev||[]).map(function(e){return {b:rich(e.b),t:rich(e.t),l:rich(e.l)};}).filter(function(e){return e.b||e.t;});
    c.fotos=(r.fotos||[]).filter(function(f){return f&&f.src;});
    c.gal=(r.gal||[]).filter(function(f){return f&&f.src;});
    c.figs=[];
  });
})();
