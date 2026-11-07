/* PD · gavetas por baixo (celular). Painéis laterais viram folhas que sobem de uma barra de ícones; o centro fica livre.
   Marque o painel com data-sheet (e data-sheet-title se não tiver <h3>). Só age em telas estreitas.
   ALÇA: toda gaveta é arrastável — puxa pra cima (meia → tela cheia), puxa pra baixo (fecha). O ✕ só existe com a gaveta cheia. */
window.pdGavetaArrasta = function(el, alca, o){
  o = o || {}; let y0 = 0, h0 = 0, y1 = 0, t1 = 0, vel = 0, drag = false, mexeu = false;
  const VH = () => window.innerHeight, meia = () => o.meia ? o.meia() : Math.round(VH() * .52), topo = () => o.topo ? o.topo() : VH();
  const def = (h, anim) => { el.style.transition = anim ? 'height .28s cubic-bezier(.22,.8,.24,1),transform .22s,visibility .22s' : 'transform .22s,visibility .22s'; el.style.height = Math.round(h) + 'px'; };
  const aplica = h => { const cheia = h >= topo() - 4; el.classList.toggle('cheia', cheia); o.aoMudar && o.aoMudar(cheia);
    if (h <= 8){ def(0, true); setTimeout(() => { el.style.height = ''; el.classList.remove('cheia'); o.fecha && o.fecha(); }, 260); return; }
    def(cheia ? (o.cheio ? o.cheio() : VH()) : h, true); };
  alca.style.touchAction = 'none';
  alca.addEventListener('pointerdown', e => { if (e.target.closest('button,input,select,textarea,a')) return; drag = true; mexeu = false; y0 = y1 = e.clientY; t1 = performance.now(); vel = 0; h0 = el.getBoundingClientRect().height; if (el.classList.contains('cheia')){ el.classList.remove('cheia'); h0 = Math.min(h0, topo()); def(h0, false); } try { alca.setPointerCapture(e.pointerId); } catch(_){} });
  alca.addEventListener('pointermove', e => { if (!drag) return; if (Math.abs(e.clientY - y0) > 3) mexeu = true; const h = Math.max(0, Math.min(topo(), h0 + (y0 - e.clientY))); def(h, false);
    const t = performance.now(); vel = vel * .5 + ((y1 - e.clientY) / Math.max(1, t - t1)) * .5; y1 = e.clientY; t1 = t; });
  const fim = () => { if (!drag) return; drag = false; const h = el.getBoundingClientRect().height; const pts = [0, meia(), topo()]; let alvo;
    if (!mexeu){ alvo = el.classList.contains('cheia') ? meia() : (h >= topo() - 4 ? meia() : topo()); }   // toque na alça: alterna meia ↔ cheia
    else if (Math.abs(vel) > .45){ alvo = vel > 0 ? (pts.find(p => p > h + 12) || topo()) : ([...pts].reverse().find(p => p < h - 12) ?? 0); }
    else alvo = pts.reduce((a, b) => Math.abs(b - h) < Math.abs(a - h) ? b : a);
    aplica(alvo); };
  alca.addEventListener('pointerup', fim); alca.addEventListener('pointercancel', fim);
  return { abre: () => { el.classList.remove('cheia'); def(o.inicio ? (o.inicio() || meia()) : meia(), false); o.aoMudar && o.aoMudar(false); }, meia: () => aplica(meia()), cheia: () => aplica(topo()), fecha: () => aplica(0) };
};
(function(){
  const MQ = matchMedia('(max-width:820px)');
  const I = {
    mic:'<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
    sliders:'<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    music:'<path d="M9 18V6l10-2v12"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="16" r="2"/>',
    img:'<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
    txt:'<path d="M5 6h14M12 6v13M9 19h6"/>',
    wand:'<path d="m4 20 11-11M14 5l1-2 1 2 2 1-2 1-1 2-1-2-2-1zM18 13l.7-1.4.7 1.4 1.4.7-1.4.7-.7 1.4-.7-1.4L16.6 14z"/>',
    pal:'<path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-1.8 0-1.6-1.4-1.4-1.4-2.7 0-1 .8-1.6 1.8-1.6H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3z"/><circle cx="8" cy="11" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16" cy="11" r="1"/>',
    doc:'<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
    clip:'<rect x="3" y="6" width="18" height="12" rx="2"/><path d="m10 9.5 5 2.5-5 2.5z"/>',
    dot:'<circle cx="12" cy="12" r="3"/>'
  };
  const icone = t => { t = (t || '').toLowerCase();
    return /voz|locu/.test(t) ? 'mic' : /dire/.test(t) ? 'sliders' : /trilha|m[uú]sica/.test(t) ? 'music' : /m[ií]dia|imagem|foto/.test(t) ? 'img' : /texto|tipolog/.test(t) ? 'txt' : /setup|ferrament/.test(t) ? 'wand' : /design|cor|marca|ds/.test(t) ? 'pal' : /brief|dados|estrat/.test(t) ? 'doc' : /clipe|objeto|ajuste|propriedad/.test(t) ? 'sliders' : 'dot'; };
  const svg = n => `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${I[n] || I.dot}</svg>`;
  const CSS = `
  body.sh{padding-bottom:calc(60px + env(safe-area-inset-bottom))}
  body.sh .meio{grid-template-columns:1fr!important}
  body.sh [data-sheet]{display:none;position:fixed;top:auto;height:auto;width:auto;left:0;right:0;bottom:calc(60px + env(safe-area-inset-bottom));max-height:none;height:var(--shh,52vh);z-index:70;border:0!important;border-top:1px solid var(--line)!important;border-radius:20px 20px 0 0;box-shadow:0 -16px 44px rgba(0,0,0,.28);padding:0 16px 22px!important;background:var(--soft);overflow:auto;overscroll-behavior:contain}
  body.sh [data-sheet].sh-on{display:block}
  body.sh [data-sheet].cheia{bottom:0!important;top:0;height:100dvh!important;border-radius:0;z-index:95;padding-top:env(safe-area-inset-top)!important}
  body.sh [data-sheet] h3{display:none}
  body.sh [data-sheet] .sh-fora{display:none!important}
  body.sh [data-sheet] .vazio[style*="line-height:1.4"]:not(#repDir){display:none}
  .sh-h{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:10px;padding:16px 0 10px;background:var(--soft);font-weight:600;font-size:15px}
  .sh-h{touch-action:none;cursor:grab;user-select:none}.sh-h::before{content:"";position:absolute;top:7px;left:50%;width:44px;height:5px;margin-left:-22px;border-radius:3px;background:rgba(127,127,127,.5)}
  .sh-h b{flex:1}.sh-h button{display:none!important}[data-sheet].cheia .sh-h button{display:inline-block!important}
  #pdDock{position:fixed;left:0;right:0;bottom:0;z-index:80;display:flex;gap:6px;align-items:stretch;overflow-x:auto;scrollbar-width:none;background:var(--bg);border-top:1px solid var(--line);padding:6px 8px calc(6px + env(safe-area-inset-bottom))}
  #pdDock::-webkit-scrollbar{display:none}
  #pdDock button{flex:1 0 auto;min-width:66px;min-height:48px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;border:0;border-radius:14px;background:transparent;color:var(--gray);font:600 10.5px/1.1 "Helvetica Neue","Inter Tight",system-ui,sans-serif;padding:4px 10px}
  #pdDock button.on{background:var(--ink);color:var(--bg)}
  body.sh .top{overflow-x:auto;flex-wrap:nowrap!important;scrollbar-width:none;height:auto!important;min-height:52px;padding:0 10px!important}
  body.sh .top::-webkit-scrollbar{display:none}body.sh .top>*{flex:0 0 auto}body.sh .top .nome{min-width:130px}body.sh .top .sep{display:none}
  body.sh .btn,body.sh select,body.sh input:not([type=range]):not([type=file]):not([type=checkbox]){min-height:44px}
  body.sh .btn.i{width:44px;height:44px}
  body.sh .so-cel{display:none!important}
  body.sh .lista,body.sh .variantes{padding:14px 12px!important}body.sh .prompt{padding:10px 12px 12px!important}
  body.sh .prompt textarea{min-height:64px!important;font-size:16px}
  body.sh .cards{grid-template-columns:1fr!important}`;
  let dock = null, st = null, abertas = [];
  function grupos(p){
    const k = [...p.children]; if (!p.querySelector(':scope > h3')) return [{ t: p.dataset.sheetTitle || 'Ajustes', nos: null, p }];
    const g = []; let cur = { t: p.dataset.sheetTitle || 'Geral', nos: [], p };
    k.forEach(n => { if (n.tagName === 'H3'){ if (cur.nos.length || g.length) g.push(cur); cur = { t: n.textContent.replace(/\s*·.*$/, '').trim(), nos: [n], p }; } else cur.nos.push(n); });
    g.push(cur); return g.filter(x => x.nos.length > 1 || x.t !== 'Geral');
  }
  function fecha(){ abertas.forEach(a => { a.p.classList.remove('sh-on', 'cheia'); a.p.style.height = ''; a.p.style.transition = ''; const h = a.p.querySelector(':scope > .sh-h'); if (h) h.remove(); a.p.querySelectorAll(':scope > .sh-fora').forEach(n => n.classList.remove('sh-fora')); }); dock && dock.querySelectorAll('button').forEach(b => b.classList.remove('on')); }
  function abre(g, btn){
    const jaAberta = btn.classList.contains('on'); fecha(); if (jaAberta) return;
    const p = g.p; p.classList.add('sh-on'); btn.classList.add('on');
    if (g.nos) [...p.children].forEach(n => { if (!n.classList.contains('sh-h') && !g.nos.includes(n)) n.classList.add('sh-fora'); });
    const h = document.createElement('div'); h.className = 'sh-h'; h.innerHTML = `<b>${g.t}</b><button aria-label="Fechar">✕</button>`; h.querySelector('button').onclick = fecha; p.prepend(h); p.scrollTop = 0;
    p.style.height = ''; p.classList.remove('cheia'); try { p._ga && (p._ga = null); p._ga = window.pdGavetaArrasta(p, h, { meia: () => Math.round(innerHeight * .52), topo: () => innerHeight - 60, cheio: () => innerHeight, fecha }); p._ga.abre(); } catch(e){}
  }
  function liga(){
    if (dock) return; const ps = [...document.querySelectorAll('[data-sheet]')]; if (!ps.length) return;
    st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st); document.body.classList.add('sh');
    dock = document.createElement('div'); dock.id = 'pdDock'; abertas = [];
    ps.forEach(p => grupos(p).forEach(g => { abertas.push(g); const b = document.createElement('button'); b.innerHTML = svg(icone(g.t)) + '<span>' + g.t.split(' ')[0] + '</span>'; b.onclick = () => abre(g, b); dock.appendChild(b); }));
    document.body.appendChild(dock);
  }
  function desliga(){ if (!dock) return; fecha(); dock.remove(); dock = null; st && st.remove(); document.body.classList.remove('sh'); abertas = []; }
  function aplica(){ MQ.matches ? liga() : desliga(); }
  window.pdSheetFechar = fecha;
  (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', aplica) : aplica());
  MQ.addEventListener && MQ.addEventListener('change', aplica);
})();

/* Padrão da plataforma para "tem coisa não salva": dois botões, Salvar / Sair. Devolve 'salvar', 'sair' ou null (tocou fora = fica). */
window.pdSalvarOuSair = function (titulo) {
  return new Promise(res => {
    const d = document.createElement('div');
    d.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.5);display:grid;place-items:center;padding:20px;font-family:inherit';
    d.innerHTML = '<div style="background:var(--bg,#fff);color:var(--ink,#111);border:1px solid var(--line,#ddd);border-radius:20px;padding:22px 20px 16px;width:min(340px,100%);box-shadow:0 20px 50px rgba(0,0,0,.35)">'
      + '<div style="font-size:17px;font-weight:600;text-align:center;margin-bottom:18px"></div>'
      + '<div style="display:flex;gap:10px"><button data-r="sair" style="flex:1;height:48px;border-radius:14px;border:1px solid var(--line,#ddd);background:transparent;color:inherit;font:600 15px inherit;cursor:pointer">Sair</button>'
      + '<button data-r="salvar" style="flex:1;height:48px;border-radius:14px;border:0;background:var(--ink,#111);color:var(--bg,#fff);font:600 15px inherit;cursor:pointer">Salvar</button></div></div>';
    d.firstChild.firstChild.textContent = titulo || 'Salvar antes de sair?';
    const fim = r => { d.remove(); res(r); };
    d.addEventListener('click', e => { const b = e.target.closest('button[data-r]'); if (b) fim(b.dataset.r); else if (e.target === d) fim(null); });
    document.body.appendChild(d);
  });
};
