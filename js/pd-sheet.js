/* PD · gavetas por baixo (celular). Painéis laterais viram folhas que sobem de uma barra de ícones; o centro fica livre.
   Marque o painel com data-sheet (e data-sheet-title se não tiver <h3>). Só age em telas estreitas. */
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
  body.sh [data-sheet]{display:none;position:fixed;top:auto;height:auto;width:auto;left:0;right:0;bottom:calc(60px + env(safe-area-inset-bottom));max-height:60vh;z-index:70;border:0!important;border-top:1px solid var(--line)!important;border-radius:20px 20px 0 0;box-shadow:0 -16px 44px rgba(0,0,0,.28);padding:0 16px 22px!important;background:var(--soft);overflow:auto;overscroll-behavior:contain}
  body.sh [data-sheet].sh-on{display:block}
  body.sh [data-sheet] h3{display:none}
  body.sh [data-sheet] .sh-fora{display:none!important}
  body.sh [data-sheet] .vazio[style*="line-height:1.4"]:not(#repDir){display:none}
  .sh-h{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:10px;padding:16px 0 10px;background:var(--soft);font-weight:600;font-size:15px}
  .sh-h::before{content:"";position:absolute;top:6px;left:50%;width:38px;height:4px;margin-left:-19px;border-radius:3px;background:var(--line)}
  .sh-h b{flex:1}.sh-h button{width:44px;height:44px;border:0;border-radius:50%;background:var(--bg);color:var(--ink);font-size:18px;display:grid;place-items:center}
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
  function fecha(){ abertas.forEach(a => { a.p.classList.remove('sh-on'); const h = a.p.querySelector(':scope > .sh-h'); if (h) h.remove(); a.p.querySelectorAll(':scope > .sh-fora').forEach(n => n.classList.remove('sh-fora')); }); dock && dock.querySelectorAll('button').forEach(b => b.classList.remove('on')); }
  function abre(g, btn){
    const jaAberta = btn.classList.contains('on'); fecha(); if (jaAberta) return;
    const p = g.p; p.classList.add('sh-on'); btn.classList.add('on');
    if (g.nos) [...p.children].forEach(n => { if (!n.classList.contains('sh-h') && !g.nos.includes(n)) n.classList.add('sh-fora'); });
    const h = document.createElement('div'); h.className = 'sh-h'; h.innerHTML = `<b>${g.t}</b><button aria-label="Fechar">✕</button>`; h.querySelector('button').onclick = fecha; p.prepend(h); p.scrollTop = 0;
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
