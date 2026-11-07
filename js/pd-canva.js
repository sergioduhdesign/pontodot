/* PD · Estúdio — padrão de edição por seleção (mobile e desktop)
   Lógica: base da tela tem UM espaço com 2 estados.
   · nada selecionado = adicionar (rodapé/abas) · objeto selecionado = editar (barra contextual + ✓)
   · pílula sobre o objeto: duplicar, excluir, ⋯ · folha "⋯": copiar/colar/duplicar/excluir, selecionar vários, camada >, alinhar >
   Usa os globais do Estúdio (C, setP, copiar, colar, apaga, marca, pageX, pagDe, W, Hh, ehImp, px2pt…). */
(function(){
'use strict';
const $=s=>document.querySelector(s);
const MOBQ=()=>matchMedia('(max-width:820px)').matches;
const ic=d=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'+d+'</svg>';
const I={
 copy:'<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
 paste:'<rect x="6" y="5" width="12" height="16" rx="2"/><path d="M9 5V3h6v2"/>',
 roller:'<rect x="4" y="3" width="13" height="5" rx="1.5"/><path d="M17 5.5h2.5V11H11v3"/><rect x="9" y="14" width="4" height="7" rx="1"/>',
 dup:'<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2M14 11v6M11 14h6"/>',
 trash:'<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
 layers:'<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
 alignp:'<path d="M4 3v18"/><rect x="8" y="6" width="11" height="4" rx="1"/><rect x="8" y="13" width="7" height="4" rx="1"/>',
 lock:'<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
 multi:'<rect x="4" y="4" width="16" height="16" rx="2" stroke-dasharray="3 3"/>',
 dots:'<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
 edit:'<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h.01M11 10h.01M15 10h.01M7 14h10"/>',
 font:'<path d="M5 19 10 5l5 14M7 14h6M16 9h5M18.5 9v10"/>',
 size:'<path d="M3 19 8 6l5 13M5 15h6M15 19l3.5-9 3.5 9M16.3 16h4.4"/>',
 color:'<path d="M6 17 12 4l6 13M8.5 12h7"/><path d="M5 21h14" stroke-width="3"/>',
 fmt:'<path d="M4 6h16M4 12h10M4 18h14"/>',
 spc:'<path d="M12 4v10M8 4h8"/><path d="M5 19h14M8 16l-3 3 3 3M16 16l3 3-3 3"/>',
 opac:'<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 12h16M12 4v16"/>',
 pos:'<rect x="3" y="3" width="10" height="10" rx="1.5"/><rect x="11" y="11" width="10" height="10" rx="1.5"/>',
 crop:'<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/>',
 flip:'<path d="M12 3v18M9 7 4 12l5 5V7zM15 7l5 5-5 5V7z"/>',
 shadow:'<rect x="4" y="4" width="12" height="12" rx="2"/><path d="M8 20h12V8"/>',
 corner:'<path d="M5 20V12a7 7 0 0 1 7-7h8"/>',
 stroke:'<rect x="4" y="4" width="16" height="16" rx="3"/>',
 check:'<path d="m5 12 5 5 9-10"/>',
 chev:'<path d="m9 6 6 6-6 6"/>',back:'<path d="M19 12H5M11 6l-6 6 6 6"/>',x:'<path d="m6 6 12 12M18 6 6 18"/>',
 toF:'<path d="M5 3h14M12 21V8M6 13l6-6 6 6"/>',fw:'<path d="M12 20V6M6 12l6-6 6 6"/>',bw:'<path d="M12 4v14M6 12l6 6 6-6"/>',toB:'<path d="M5 21h14M12 3v13M6 11l6 6 6-6"/>',
 aL:'<path d="M4 3v18M8 7h10v4H8M8 13h6v4H8"/>',aC:'<path d="M12 3v18M6 7h12v4H6M8 13h8v4H8"/>',aR:'<path d="M20 3v18M6 7h10v4H6M10 13h6v4h-6"/>',
 aT:'<path d="M3 4h18M7 8v10h4V8M13 8v6h4V8"/>',aM:'<path d="M3 12h18M7 6v12h4V6M13 8v8h4V8"/>',aB:'<path d="M3 20h18M7 6v10h4V6M13 10v6h4v-6"/>',
 group:'<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
 bold:'<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',ital:'<path d="M10 5h8M6 19h8M14 5l-4 14"/>',
 eye:'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'
};
let STYLE=null,MULTI=false,FOLHA=null,PILL=null,BAR=null;

// ---------- utilidades
const ok=()=>{try{return typeof C!=='undefined'&&C&&C.getActiveObject;}catch(e){return false;}};
const ativo=()=>{try{const o=C.getActiveObject();if(!o||o.pdPage!==undefined||o.isEditing)return null;if(CROP.on||MASK.on||PENEDIT.on)return null;if(typeof FERR!=='undefined'&&FERR==='caneta')return null;return o;}catch(e){return null;}};
const bgs=()=>C.getObjects().filter(x=>x.pdPage!==undefined).length;
const membros=o=>o.type==='activeSelection'?o.getObjects():[o];
function camadaEstado(o){const L=C.getObjects(),b=bgs(),idx=membros(o).map(x=>L.indexOf(x)).filter(i=>i>=0);if(!idx.length)return{topo:true,fundo:true};
  const mx=Math.max(...idx),mn=Math.min(...idx);const acima=L.slice(mx+1).some(x=>x.pdPage===undefined&&!x.excludeFromExport);return{topo:!acima,fundo:mn<=b};}
function camada(acao){const o=ativo();if(!o)return;const b=bgs();
  if(acao==='front')C.bringToFront(o);else if(acao==='fw')C.bringForward(o);else if(acao==='bw'){membros(o).length&&C.sendBackwards(o);const L=C.getObjects();if(L.indexOf(membros(o)[0])<b)C.moveTo(o,b);}else if(acao==='back')C.moveTo(o,b);
  C.requestRenderAll();marca();try{camadas();}catch(e){}refresh();}
function alinhaPag(m){const o=ativo();if(!o)return;const ox=pageX(pagDe(o));const b=o.getBoundingRect(true,true);let dx=0,dy=0;
  if(m==='l')dx=ox-b.left;else if(m==='c')dx=ox+W()/2-(b.left+b.width/2);else if(m==='r')dx=ox+W()-(b.left+b.width);
  else if(m==='t')dy=-b.top;else if(m==='m')dy=Hh()/2-(b.top+b.height/2);else if(m==='b')dy=Hh()-(b.top+b.height);
  o.set({left:o.left+dx,top:o.top+dy});o.setCoords();C.requestRenderAll();marca();posPill();}
function duplica(){const o=ativo();if(!o)return;copiar();setTimeout(()=>{colar();setTimeout(refresh,60);},30);}
function exclui(){const o=ativo();if(!o)return;apaga();fecha();}
function copiaEstilo(){const o=ativo();if(!o)return;const k=['fill','stroke','strokeWidth','opacity','shadow','fontFamily','fontWeight','fontStyle','fontSize','textAlign','lineHeight','charSpacing','rx','ry'];
  STYLE={};const src=membros(o)[0];k.forEach(p=>{if(src[p]!==undefined)STYLE[p]=src[p];});msg('Estilo copiado. Abra ⋯ em outro objeto e toque em "Colar estilo".');}
function colaEstilo(){const o=ativo();if(!o||!STYLE)return;membros(o).forEach(x=>{const s={};Object.keys(STYLE).forEach(p=>{if(['fontFamily','fontWeight','fontStyle','fontSize','textAlign','lineHeight','charSpacing'].includes(p)&&x.type!=='textbox')return;if(['rx','ry'].includes(p)&&x.type!=='rect')return;s[p]=STYLE[p];});x.set(s);if(x.type==='textbox'&&x.initDimensions)x.initDimensions();x.setCoords();});C.requestRenderAll();marca();props();}
function selecionaVarios(){MULTI=!MULTI;msg(MULTI?'Toque nos objetos para somar à seleção.':'Seleção múltipla desligada.');if(MULTI)fecha();refresh();}
function bloqueia(){const o=ativo();if(!o)return;membros(o).forEach(x=>x.set({selectable:false,evented:false}));C.discardActiveObject();C.requestRenderAll();marca();msg('Bloqueado. Para soltar: lista de camadas → cadeado.');}
function mostraCamadas(){fecha();try{abaAbre('objeto');setTimeout(()=>{const el=$('#camadas');if(el)el.scrollIntoView({block:'center',behavior:'smooth'});},450);}catch(e){}}

// ---------- folha (bottom sheet) com subníveis
function folha(){let f=$('#pdFolha');if(f)return f;const m=$('#main');if(!m)return null;f=document.createElement('div');f.id='pdFolha';
  f.innerHTML='<div class="fa"></div><div class="fh"><button type="button" class="fb" aria-label="Voltar"></button><b class="ft"></b><button type="button" class="fx" aria-label="Fechar"></button></div><div class="fc"></div>';
  f.querySelector('.fb').innerHTML=ic(I.back);f.querySelector('.fx').innerHTML=ic(I.x);m.appendChild(f);
  f.querySelector('.fx').onclick=fecha;f.querySelector('.fb').onclick=()=>{const p=FOLHA&&FOLHA.pai;if(p)abre(p);else fecha();};
  let y0=null,dy=0;f.querySelector('.fa').addEventListener('touchstart',e=>{y0=e.touches[0].clientY;dy=0;f.style.transition='none';},{passive:true});
  f.querySelector('.fa').addEventListener('touchmove',e=>{if(y0===null)return;dy=Math.max(0,e.touches[0].clientY-y0);f.style.transform='translateY('+dy+'px)';f.style.opacity=String(Math.max(.3,1-dy/260));},{passive:true});
  f.querySelector('.fa').addEventListener('touchend',()=>{if(y0===null)return;y0=null;f.style.transition='';if(dy>80)fecha();else{f.style.transform='';f.style.opacity='';}},{passive:true});
  return f;}
function abre(def){const f=folha();if(!f)return;FOLHA=def;const c=f.querySelector('.fc');f.querySelector('.ft').textContent=def.titulo||'';f.querySelector('.fb').style.visibility=def.pai?'visible':'hidden';
  c.innerHTML='';const lin=(r)=>{
    if(r==='-'){const h=document.createElement('hr');c.appendChild(h);return;}
    if(r.cab){const t=document.createElement('div');t.className='fl';t.textContent=r.cab;c.appendChild(t);return;}
    if(r.acoes){const g=document.createElement('div');g.className='f5';r.acoes.forEach(a=>{const b=document.createElement('button');b.type='button';b.innerHTML=ic(a.i)+'<span>'+a.t+'</span>';if(a.off)b.disabled=true;b.onclick=()=>{try{a.f();}catch(e){}if(!a.fica)fecha();};g.appendChild(b);});c.appendChild(g);return;}
    if(r.slider){const d=document.createElement('div');d.className='fs';d.innerHTML='<label>'+r.slider.t+'</label><input type="range" min="'+r.slider.min+'" max="'+r.slider.max+'" step="'+r.slider.step+'" value="'+r.slider.v+'"><output>'+r.slider.fmt(r.slider.v)+'</output>';const inp=d.querySelector('input'),out=d.querySelector('output');
      inp.oninput=()=>{out.textContent=r.slider.fmt(+inp.value);r.slider.on(+inp.value);};inp.onchange=()=>{try{marca();}catch(e){}};c.appendChild(d);return;}
    if(r.cores){const d=document.createElement('div');d.className='fcor';const cs=(typeof KIT!=='undefined'&&KIT&&KIT.colors||[]).map(x=>x.hex);['#000000','#ffffff',...cs].forEach(h=>{const s=document.createElement('button');s.type='button';s.style.background=h;s.setAttribute('aria-label',h);s.onclick=()=>r.cores(h);d.appendChild(s);});
      const pk=document.createElement('input');pk.type='color';pk.value=r.atual&&/^#/.test(r.atual)?r.atual:'#000000';pk.oninput=()=>r.cores(pk.value);pk.onchange=()=>{try{marca();}catch(e){}};d.appendChild(pk);c.appendChild(d);return;}
    if(r.lista){const d=document.createElement('div');d.className='fli';r.lista.forEach(x=>{const b=document.createElement('button');b.type='button';b.textContent=x.t;if(x.css)b.style.fontFamily=x.css;if(x.on)b.classList.add('on');b.onclick=()=>{x.f();};d.appendChild(b);});c.appendChild(d);return;}
    const b=document.createElement('button');b.type='button';b.className='fr';if(r.off)b.disabled=true;b.innerHTML=(r.i?ic(r.i):'<i></i>')+'<span>'+r.t+'</span>'+(r.sub?ic(I.chev):'');
    b.onclick=()=>{if(r.off)return;if(r.sub){abre(Object.assign({pai:def},r.sub()));}else{try{r.f();}catch(e){}if(!r.fica)fecha();}};c.appendChild(b);};
  def.linhas().forEach(lin);f.classList.add('on');f.style.transform='';f.style.opacity='';}
function fecha(){const f=$('#pdFolha');if(f){f.classList.remove('on');}FOLHA=null;}
function refresh(){if(FOLHA&&$('#pdFolha')&&$('#pdFolha').classList.contains('on')){const d=FOLHA;try{abre(d);}catch(e){}}barra();posPill();}

// ---------- folhas específicas
const m_camada=()=>{const o=ativo();const e=o?camadaEstado(o):{topo:true,fundo:true};return{titulo:'Camada',linhas:()=>[
  {i:I.toF,t:'Trazer para a frente',off:e.topo,f:()=>camada('front'),fica:1},{i:I.fw,t:'Subir uma camada',off:e.topo,f:()=>camada('fw'),fica:1},
  {i:I.bw,t:'Descer uma camada',off:e.fundo,f:()=>camada('bw'),fica:1},{i:I.toB,t:'Enviar para trás',off:e.fundo,f:()=>camada('back'),fica:1},'-',{i:I.layers,t:'Mostrar camadas',f:mostraCamadas}]};};
const m_alinhar=()=>({titulo:'Alinhar à página',linhas:()=>[
  {i:I.aL,t:'À esquerda',f:()=>alinhaPag('l'),fica:1},{i:I.aC,t:'Ao centro',f:()=>alinhaPag('c'),fica:1},{i:I.aR,t:'À direita',f:()=>alinhaPag('r'),fica:1},'-',
  {i:I.aT,t:'Em cima',f:()=>alinhaPag('t'),fica:1},{i:I.aM,t:'No meio',f:()=>alinhaPag('m'),fica:1},{i:I.aB,t:'Embaixo',f:()=>alinhaPag('b'),fica:1}]});
const m_posicao=()=>{const o=ativo();const e=o?camadaEstado(o):{topo:true,fundo:true};return{titulo:'Posição',linhas:()=>[
  {cab:'Organizar'},{acoes:[{i:I.toF,t:'Frente',off:e.topo,f:()=>camada('front'),fica:1},{i:I.fw,t:'Subir',off:e.topo,f:()=>camada('fw'),fica:1},{i:I.bw,t:'Descer',off:e.fundo,f:()=>camada('bw'),fica:1},{i:I.toB,t:'Trás',off:e.fundo,f:()=>camada('back'),fica:1}]},
  {cab:'Alinhar à página'},{acoes:[{i:I.aL,t:'Esq.',f:()=>alinhaPag('l'),fica:1},{i:I.aC,t:'Centro',f:()=>alinhaPag('c'),fica:1},{i:I.aR,t:'Dir.',f:()=>alinhaPag('r'),fica:1}]},
  {acoes:[{i:I.aT,t:'Topo',f:()=>alinhaPag('t'),fica:1},{i:I.aM,t:'Meio',f:()=>alinhaPag('m'),fica:1},{i:I.aB,t:'Base',f:()=>alinhaPag('b'),fica:1}]},'-',{i:I.layers,t:'Mostrar camadas',f:mostraCamadas}]};};
const m_mais=()=>{const o=ativo();return{titulo:o?(o.type==='textbox'?'Texto':o.type==='image'?'Imagem':o.type==='activeSelection'?'Seleção':'Elemento'):'Elemento',linhas:()=>[
  {acoes:[{i:I.copy,t:'Copiar',f:()=>copiar()},{i:I.roller,t:'Copiar estilo',f:copiaEstilo},{i:I.paste,t:'Colar',f:()=>colar(),off:typeof CLIP==='undefined'||!CLIP},{i:I.dup,t:'Duplicar',f:duplica},{i:I.trash,t:'Excluir',f:exclui}]},'-',
  ...(STYLE?[{i:I.roller,t:'Colar estilo',f:colaEstilo}]:[]),
  {i:I.multi,t:MULTI?'Parar de selecionar vários':'Selecionar vários',f:selecionaVarios},
  ...(o&&o.type==='activeSelection'?[{i:I.group,t:'Agrupar',f:()=>agrupa()}]:[]),
  ...(o&&o.type==='group'&&!o.pdMask?[{i:I.group,t:'Desagrupar',f:()=>desagrupa()}]:[]),'-',
  {i:I.layers,t:'Camada',sub:m_camada},{i:I.alignp,t:'Alinhar à página',sub:m_alinhar},'-',
  {i:I.lock,t:'Bloquear',f:bloqueia}]};};
const m_opac=()=>{const o=ativo();return{titulo:'Transparência',linhas:()=>[{slider:{t:'Opacidade',min:0,max:100,step:1,v:Math.round((o?.opacity??1)*100),fmt:v=>v+'%',on:v=>{const a=ativo();if(a)setP('opacity',v/100,false);}}}]};};
const m_tam=()=>{const o=ativo();const imp=ehImp();const v=o?(imp?Math.round(px2pt(o.fontSize)*2)/2:Math.round(o.fontSize)):16;const un=imp?'pt':'px';return{titulo:'Tamanho da fonte',linhas:()=>[{slider:{t:'Tamanho',min:imp?4:8,max:imp?220:400,step:imp?.5:1,v,fmt:x=>x+' '+un,on:x=>{const a=ativo();if(a)setP('fontSize',imp?pt2px(x):x,false);}}}]};};
const m_cor=()=>{const o=ativo();const vet=o&&['group','path','polygon','polyline'].includes(o.type)&&!o.pdMask;return{titulo:'Cor',linhas:()=>[{cores:h=>{vet?corVetorSet(h):setP('fill',h);refreshCor();},atual:o&&typeof o.fill==='string'?o.fill:null}]};};
const m_cont=()=>{const o=ativo();return{titulo:'Contorno',linhas:()=>[{slider:{t:'Espessura',min:0,max:60,step:1,v:Math.round(o?.strokeWidth||0),fmt:v=>v+' px',on:v=>{const a=ativo();if(a){if(v>0&&!a.stroke)a.set('stroke','#000000');setP('strokeWidth',v,false);}}}},{cores:h=>{setP('stroke',h);},atual:o&&typeof o.stroke==='string'?o.stroke:null}]};};
const m_cantos=()=>{const o=ativo();const max=o?Math.round(Math.min(o.width,o.height)/2):100;return{titulo:'Cantos',linhas:()=>[{slider:{t:'Raio',min:0,max:Math.max(10,max),step:1,v:Math.round(o?.rx||0),fmt:v=>v+' px',on:v=>{const a=ativo();if(a&&a.type==='rect'){a.set({rx:v,ry:v});C.requestRenderAll();}}}}]};};
const m_fonte=()=>{const o=ativo();const t=document.createElement('select');try{t.innerHTML=fontesSelect(o.fontFamily);}catch(e){}
  const ops=[...t.options].map(p=>({t:p.textContent,v:p.value,on:p.value===o.fontFamily}));return{titulo:'Fonte',linhas:()=>[{lista:ops.map(p=>({t:p.t,on:p.on,css:"'"+p.v+"'",f:()=>{aplicaFonte(p.v).then(()=>refresh());}}))}]};};
const m_fmt=()=>{const o=ativo();const al=o?.textAlign;return{titulo:'Formato',linhas:()=>[
  {acoes:[{i:'<path d="M4 6h16M4 12h10M4 18h14"/>',t:'Esq.',f:()=>setP('textAlign','left'),fica:1},{i:'<path d="M4 6h16M7 12h10M5 18h14"/>',t:'Centro',f:()=>setP('textAlign','center'),fica:1},{i:'<path d="M4 6h16M10 12h10M6 18h14"/>',t:'Dir.',f:()=>setP('textAlign','right'),fica:1},{i:'<path d="M4 6h16M4 12h16M4 18h16"/>',t:'Justif.',f:()=>setP('textAlign','justify'),fica:1}]},
  {acoes:[{i:I.bold,t:'Negrito',f:()=>{const a=ativo();setP('fontWeight',(a&&(a.fontWeight==700||a.fontWeight==='bold'))?400:700);},fica:1},{i:I.ital,t:'Itálico',f:()=>{const a=ativo();setP('fontStyle',a&&a.fontStyle==='italic'?'normal':'italic');},fica:1},
    {i:'<path d="M6 4v7a6 6 0 0 0 12 0V4M5 21h14"/>',t:'Sublinhado',f:()=>{const a=ativo();setP('underline',!(a&&a.underline));},fica:1}]}]};};
const m_esp=()=>{const o=ativo();return{titulo:'Avançado',linhas:()=>[
  {slider:{t:'Entrelinha',min:.6,max:3,step:.05,v:o?.lineHeight||1.16,fmt:v=>(+v).toFixed(2),on:v=>setP('lineHeight',v,false)}},
  {slider:{t:'Espaço entre letras',min:-100,max:800,step:10,v:o?.charSpacing||0,fmt:v=>v,on:v=>setP('charSpacing',v,false)}}]};};
const m_sombra=()=>{const o=ativo();const on=!!(o&&o.shadow);return{titulo:'Sombra',linhas:()=>[
  {i:I.shadow,t:on?'Tirar sombra':'Sombra suave',f:()=>{const a=ativo();if(!a)return;a.set('shadow',on?null:new fabric.Shadow({color:'rgba(0,0,0,.35)',blur:24,offsetX:0,offsetY:10}));C.requestRenderAll();marca();}},
  {i:I.shadow,t:'Sombra marcada',f:()=>{const a=ativo();if(!a)return;a.set('shadow',new fabric.Shadow({color:'rgba(0,0,0,.6)',blur:8,offsetX:8,offsetY:8}));C.requestRenderAll();marca();}}]};};
function refreshCor(){try{props();}catch(e){}}

// ---------- barra contextual (celular)
function itens(o){const t=o.type;const mais={i:I.dots,t:'Mais',a:()=>abre(m_mais())},cam={i:I.layers,t:'Camadas',a:()=>abre(m_camada())},pos={i:I.pos,t:'Posição',a:()=>abre(m_posicao())},op={i:I.opac,t:'Transparência',a:()=>abre(m_opac())};
  if(t==='activeSelection')return[{i:I.alignp,t:'Alinhar',a:()=>abre(m_alinhar())},{i:I.group,t:'Agrupar',a:()=>agrupa()},op,cam,pos,mais];
  if(t==='textbox')return[{i:I.edit,t:'Editar',a:()=>abreObjeto(o)},{i:I.font,t:'Fonte',a:()=>abre(m_fonte())},{i:I.size,t:'Tamanho',a:()=>abre(m_tam())},{i:I.color,t:'Cor',a:()=>abre(m_cor())},{i:I.fmt,t:'Formato',a:()=>abre(m_fmt())},{i:I.spc,t:'Avançado',a:()=>abre(m_esp())},{i:I.shadow,t:'Sombra',a:()=>abre(m_sombra())},op,cam,pos,mais];
  if(t==='image')return[{i:I.crop,t:o.clipPath?'Moldura':'Cortar',a:()=>cropEntra(o)},{i:I.flip,t:'Inverter',a:()=>setP('flipX',!ativo().flipX)},{i:I.corner,t:'Filtros',a:()=>{try{abaAbre('cores');}catch(e){abreObjeto(o);}}},{i:I.shadow,t:'Sombra',a:()=>abre(m_sombra())},op,cam,pos,mais];
  const base=[{i:I.color,t:'Cor',a:()=>abre(m_cor())},{i:I.stroke,t:'Contorno',a:()=>abre(m_cont())}];if(t==='rect')base.push({i:I.corner,t:'Cantos',a:()=>abre(m_cantos())});
  return[...base,{i:I.shadow,t:'Sombra',a:()=>abre(m_sombra())},op,cam,pos,mais];}
function barra(){const m=$('#main');if(!m)return;let b=$('#pdCx');const o=MOBQ()?ativo():null;
  if(!o){if(b)b.classList.remove('on');document.documentElement.classList.remove('pdCxOn');return;}
  if(!b){b=document.createElement('div');b.id='pdCx';b.innerHTML='<div class="cs"></div><button type="button" class="ck" aria-label="Concluir"></button>';b.querySelector('.ck').innerHTML=ic(I.check);
    b.querySelector('.ck').onclick=()=>{fecha();MULTI=false;C.discardActiveObject();C.requestRenderAll();};m.appendChild(b);}
  const s=b.querySelector('.cs');const chave=o.type+(o.clipPath?'c':'');if(s._k!==chave){s._k=chave;s.innerHTML='';itens(o).forEach(it=>{const x=document.createElement('button');x.type='button';x.innerHTML=ic(it.i)+'<span>'+it.t+'</span>';x.onclick=()=>{try{it.a();}catch(e){}};s.appendChild(x);});s.scrollLeft=0;}
  b.classList.add('on');document.documentElement.classList.add('pdCxOn');}

// ---------- pílula sobre o objeto
function pill(){let p=$('#pdPill');if(p)return p;const m=$('#main');if(!m)return null;p=document.createElement('div');p.id='pdPill';
  const mk=(i,t,f)=>{const b=document.createElement('button');b.type='button';b.setAttribute('aria-label',t);b.title=t;b.innerHTML=ic(i);b.onclick=e=>{e.stopPropagation();try{f();}catch(x){}};p.appendChild(b);};
  mk(I.dup,'Duplicar',duplica);mk(I.trash,'Excluir',exclui);mk(I.dots,'Mais',()=>abre(m_mais()));m.appendChild(p);return p;}
function posPill(){const p=pill();if(!p)return;const o=ativo();if(!o||C._pdMove){p.classList.remove('on');return;}
  const m=$('#main'),pr=m.getBoundingClientRect(),cv=C.upperCanvasEl.getBoundingClientRect();let br;try{br=o.getBoundingRect();}catch(e){p.classList.remove('on');return;}
  const w=p.offsetWidth||120,h=p.offsetHeight||40;let x=cv.left-pr.left+br.left+br.width/2-w/2;let y=cv.top-pr.top+br.top-h-14;
  const limInf=pr.height-(MOBQ()?56:8)-h;if(y<6){y=cv.top-pr.top+br.top+br.height+14;}if(y>limInf)y=Math.max(6,Math.min(limInf,cv.top-pr.top+br.top+8));
  x=Math.max(8,Math.min(pr.width-w-8,x));p.style.left=x+'px';p.style.top=y+'px';p.classList.add('on');}

// ---------- ligações
function liga(){if(!ok()||!$('#main')||!C.on||C._pdCanva)return false;C._pdCanva=1;
  const atualiza=()=>{setTimeout(()=>{barra();posPill();},0);};
  C.on('selection:created',atualiza);C.on('selection:updated',atualiza);
  C.on('selection:cleared',()=>{fecha();const p=$('#pdPill');if(p)p.classList.remove('on');barra();});
  ['object:moving','object:scaling','object:rotating'].forEach(n=>C.on(n,()=>{C._pdMove=1;const p=$('#pdPill');if(p)p.classList.remove('on');}));
  C.on('mouse:up',()=>{if(C._pdMove){C._pdMove=0;setTimeout(posPill,30);}});
  C.on('object:modified',()=>setTimeout(posPill,30));
  C.on('after:render',()=>{});
  // selecionar vários: cada toque soma/tira da seleção
  const sg=C._shouldGroup;C._shouldGroup=function(e,t){if(MULTI)return !!this._activeObject&&!!t&&t.selectable&&t.pdPage===undefined;return sg.apply(this,arguments);};
  window.addEventListener('resize',()=>{barra();posPill();});
  // tocar no vazio fecha a folha (padrão de programa)
  document.addEventListener('pointerdown',e=>{if(!FOLHA)return;if(e.target.closest('#pdFolha,#pdCx,#pdPill'))return;fecha();},true);
  new ResizeObserver(()=>posPill()).observe($('#pano')||$('#main'));
  // zoom/pan do viewport muda onde o objeto está na tela
  const vp=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(C),'setViewportTransform');
  try{const o=C.setViewportTransform;C.setViewportTransform=function(){const r=o.apply(this,arguments);requestAnimationFrame(posPill);return r;};}catch(e){}
  return true;}
const css=document.createElement('style');css.textContent=`
#pdPill{position:absolute;z-index:46;display:flex;gap:0;padding:3px;border-radius:22px;background:var(--bg);border:1px solid var(--line);box-shadow:0 4px 18px rgba(0,0,0,.18);opacity:0;pointer-events:none;transition:opacity .22s}
#pdPill.on{opacity:1;pointer-events:auto}
#pdPill button{width:40px;height:38px;border:0;border-radius:19px;background:transparent;color:var(--ink);display:grid;place-items:center;padding:0;min-height:0}
#pdPill button:active{background:rgba(127,127,127,.18)}
#pdPill svg,#pdCx svg,#pdFolha svg{width:22px;height:22px;display:block}
#pdCx{display:none;position:absolute;left:0;right:0;bottom:0;height:48px;z-index:7;background:var(--bg);border-top:1px solid var(--line);align-items:stretch;opacity:0;transition:opacity .22s}
#pdCx.on{display:flex;opacity:1}
#pdCx .cs{flex:1 1 auto;min-width:0;display:flex;overflow-x:auto;overflow-y:hidden;scrollbar-width:none;-webkit-overflow-scrolling:touch}#pdCx .cs::-webkit-scrollbar{display:none}
#pdCx .cs button{flex:0 0 auto;min-width:56px;padding:3px 4px 2px;border:0;background:transparent;color:var(--ink);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;min-height:0;border-radius:4px}
#pdCx .cs button svg{width:22px;height:22px;stroke-width:1.5}#pdCx .cs button span{font-size:9.5px;line-height:1.1;letter-spacing:-.1px;white-space:nowrap}
#pdCx .cs button:active{background:rgba(127,127,127,.16)}
#pdCx .ck{flex:0 0 52px;border:0;border-left:1px solid var(--line);background:var(--bg);color:var(--ink);display:grid;place-items:center;padding:0;min-height:0}
#pdFolha{position:absolute;left:0;right:0;bottom:0;z-index:70;max-height:66%;display:flex;flex-direction:column;background:var(--bg);color:var(--ink);border-top:1px solid var(--line);border-radius:14px 14px 0 0;box-shadow:0 -8px 28px rgba(0,0,0,.16);opacity:0;transform:translateY(24px);pointer-events:none;transition:opacity .28s ease,transform .32s cubic-bezier(.2,.8,.2,1)}
#pdFolha.on{opacity:1;transform:none;pointer-events:auto}
@media(min-width:821px){#pdFolha{left:50%;right:auto;width:420px;margin-left:-210px;bottom:12px;border:1px solid var(--line);border-radius:14px}}
#pdFolha .fa{height:22px;flex:0 0 22px;position:relative;touch-action:none}#pdFolha .fa::after{content:"";position:absolute;left:50%;top:9px;width:40px;height:4px;margin-left:-20px;border-radius:2px;background:var(--line)}
#pdFolha .fh{display:flex;align-items:center;justify-content:space-between;padding:0 6px;height:40px;flex:0 0 40px}
#pdFolha .fh b{font-size:15px;font-weight:600}#pdFolha .fh button{width:40px;height:40px;border:0;background:transparent;color:var(--ink);display:grid;place-items:center;padding:0;min-height:0}
#pdFolha .fc{overflow-y:auto;padding:2px 0 12px;overscroll-behavior:contain}
#pdFolha hr{border:0;border-top:1px solid var(--line);margin:6px 0}
#pdFolha .fr{display:flex;align-items:center;gap:14px;width:100%;min-height:48px;padding:0 16px;border:0;background:transparent;color:var(--ink);font-size:15px;text-align:left;border-radius:0}
#pdFolha .fr span{flex:1}#pdFolha .fr i{width:22px;height:22px;flex:0 0 22px}#pdFolha .fr svg:last-child:not(:first-child){width:18px;height:18px;opacity:.6}
#pdFolha .fr:active:not(:disabled){background:rgba(127,127,127,.14)}
#pdFolha button:disabled{opacity:.35}
#pdFolha .fl{padding:8px 16px 2px;font-size:12px;opacity:.6}
#pdFolha .f5{display:flex;justify-content:space-around;padding:4px 8px}
#pdFolha .f5 button{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 2px;border:0;background:transparent;color:var(--ink);font-size:11px;min-height:0}
#pdFolha .f5 button:active:not(:disabled){background:rgba(127,127,127,.14)}
#pdFolha .fs{display:grid;grid-template-columns:1fr auto;gap:6px 12px;padding:10px 16px}#pdFolha .fs label{font-size:13px;opacity:.7}#pdFolha .fs output{font-size:13px;font-variant-numeric:tabular-nums}
#pdFolha .fs input{grid-column:1/3;width:100%;margin:0;height:28px}
#pdFolha .fcor{display:flex;flex-wrap:wrap;gap:10px;padding:12px 16px}#pdFolha .fcor button{width:36px;height:36px;border-radius:50%;border:1px solid var(--line);padding:0;min-height:0}
#pdFolha .fcor input{width:36px;height:36px;padding:0;border:0;background:none}
#pdFolha .fli{display:flex;flex-direction:column}#pdFolha .fli button{min-height:46px;padding:0 16px;border:0;background:transparent;color:var(--ink);font-size:17px;text-align:left;border-radius:0}#pdFolha .fli button.on{background:rgba(127,127,127,.16)}
@media(max-width:820px){html.pdCxOn #rodape{visibility:hidden}html.pdCxOn #pdJoy{bottom:60px}}
`;document.head.appendChild(css);
const iv=setInterval(()=>{try{if(liga())clearInterval(iv);}catch(e){}},400);
window.pdCanva={abreMais:()=>abre(m_mais()),camada,alinhaPag,fecha};
})();
