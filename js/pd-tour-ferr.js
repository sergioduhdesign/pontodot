// ============================================================================
// PD · TOUR DAS FERRAMENTAS — "como usar", passo a passo, com o mesmo brilho do tour da casa.
// Abre sozinho na 1ª vez de cada ferramenta (por pessoa/aparelho) e fica sempre à mão:
// botão "?" no topo, ou pdTourFerr(true) (ex.: item "Como funciona" no menu de segurar).
// ============================================================================
(function(){
  const ARQ = (location.pathname.split('/').pop() || '').replace(/\.html.*$/, '');
  const MOB = () => matchMedia('(max-width:820px)').matches;
  const T = {
    estudio: { nome: 'Estúdio de arte', passos: [
      { a: ['#cli'], t: '1 · Escolha o cliente', p: 'Tudo parte dele: cores, fontes, logos e materiais da marca ficam à mão para a peça.' },
      { a: ['#fmt'], t: '2 · Defina o tipo da peça', p: 'Post, story, carrossel, impresso… o tamanho certo já vem pronto.' },
      { a: ['[data-aba="marca"]'], t: '3 · Acesse o design system', p: 'DS abre as cores, fontes, logos e fotos do cliente. Toque e aplique direto na peça.' },
      { a: () => MOB() ? ['.fxMais'] : ['#caixaF'], t: '4 · Edite textos, imagens e formas', p: () => MOB() ? 'A seta ▼ desce as ferramentas de edição (texto, formas, moldura de foto). Segure 1 segundo na seta para fixá-las na lateral.' : 'Texto, formas, moldura de foto, caneta e conta-gotas. Selecione um objeto para ajustar na gaveta.' },
      { a: ['#pano'], t: '5 · Segure para mais opções', p: () => MOB() ? 'Segure o dedo no objeto (ou no vazio): aparece copiar, duplicar, travar, centrar. Com o objeto selecionado, o direcional na lateral move com precisão.' : 'Clique com o botão direito no objeto ou no vazio para copiar, duplicar, travar e mais.' },
      { a: ['#btnTarefa'], t: '6 · Trabalho é tarefa', p: 'Ligue a peça a uma tarefa do funil (ou crie uma). Assim o prazo e a aprovação andam juntos.' },
      { a: ['#btnPrincipal'], t: '7 · Gere a saída', p: 'Quando estiver pronta, gere os arquivos finais. Eles são guardados no Drive do cliente.' } ] },
    copy: { nome: 'Copys', passos: [
      { a: ['#cli'], t: '1 · Escolha o cliente', p: 'A IA escreve no tom e com os dados da marca dele.' },
      { a: ['.lado'], t: '2 · Preencha o briefing', p: 'Briefing rápido, dados estratégicos e o estilo de escrita. Quanto mais claro, melhor a copy.' },
      { a: ['button[onclick^="gerar"]'], t: '3 · Peça as variantes', p: 'A IA entrega várias versões. Lapide direto nos cards.' },
      { a: ['#btnTarefa'], t: '4 · Trabalho é tarefa', p: 'Ligue a copy a uma tarefa. O que estiver bom segue para a revisão interna e depois para o cliente.' } ] },
    audio: { nome: 'Locução e trilha', passos: [
      { a: ['#cli'], t: '1 · Escolha o cliente', p: 'A locução fica guardada na pasta dele.' },
      { a: ['.lado'], t: '2 · Escolha a voz e a direção', p: 'Voz, tom da locução e trilha. Escreva o texto e ajuste a direção.' },
      { a: ['button[onclick^="gerar"]'], t: '3 · Gere e ouça', p: 'Ouça, refaça o que não ficou bom e guarde a melhor versão.' },
      { a: ['#btnTarefa'], t: '4 · Trabalho é tarefa', p: 'Ligue a locução a uma tarefa para o prazo e a aprovação andarem juntos.' } ] },
    filme: { nome: 'Vídeos', passos: [
      { a: ['#cli'], t: '1 · Escolha o cliente', p: 'A mídia e o design system dele entram no filme.' },
      { a: ['#fmt'], t: '2 · Escolha o formato', p: 'Vertical, horizontal ou quadrado.' },
      { a: ['.lado'], t: '3 · Monte as cenas', p: 'Mídia, texto, locução e trilha. Edite cena por cena.' },
      { a: ['#btnTarefa'], t: '4 · Trabalho é tarefa', p: 'Ligue o filme a uma tarefa do funil.' },
      { a: ['button[onclick="renderizar()"]'], t: '5 · Gere a saída', p: 'Renderiza o MP4 na oficina e guarda no Drive.' } ] },
    cortes: { nome: 'Cortes', passos: [
      { a: ['#cli'], t: '1 · Escolha o cliente', p: 'Os cortes ficam na pasta dele.' },
      { a: ['button[onclick="comLink()"]'], t: '2 · Traga o vídeo', p: 'Cole o link ou envie o arquivo. A IA sugere os melhores trechos.' },
      { a: ['#btnTarefa'], t: '3 · Trabalho é tarefa', p: 'Ligue os cortes a uma tarefa do funil.' },
      { a: ['#bExp'], t: '4 · Exporte', p: 'Corta, enquadra em 9:16 e queima a legenda.' } ] }
  };
  const cfg = T[ARQ]; if (!cfg) return;
  const chave = 'pd_tour_ferr_' + ARQ;
  let ov = null, i = 0;
  const val = v => typeof v === 'function' ? v() : v;
  function visivel(el){ if (!el) return false; const r = el.getBoundingClientRect(); return r.width > 4 && r.height > 4 && r.bottom > 0 && r.right > 0 && r.left < innerWidth && r.top < innerHeight && !!(el.offsetParent || getComputedStyle(el).position === 'fixed'); }
  function alvo(p){ for (const s of val(p.a) || []){ const el = document.querySelector(s); if (el){ try { el.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch(e){} if (visivel(el)) return el; } } return null; }
  function css(){ if (document.getElementById('pdTFcss')) return; const st = document.createElement('style'); st.id = 'pdTFcss'; st.textContent = `
  #pdTF{position:fixed;inset:0;z-index:100050}
  #pdTFoco{position:fixed;z-index:100051;pointer-events:none;border-radius:14px;box-shadow:0 0 16px 2px rgba(255,255,255,.5),0 0 44px 12px rgba(255,255,255,.2),0 0 0 9999px rgba(0,0,0,.5);transition:all .3s ease;animation:tfBrilho 2.6s ease-in-out infinite}
  @keyframes tfBrilho{0%,100%{box-shadow:0 0 14px 2px rgba(255,255,255,.45),0 0 40px 10px rgba(255,255,255,.16),0 0 0 9999px rgba(0,0,0,.5)}50%{box-shadow:0 0 22px 4px rgba(255,255,255,.75),0 0 60px 16px rgba(255,255,255,.3),0 0 0 9999px rgba(0,0,0,.5)}}
  #pdTFcx{position:fixed;z-index:100052;background:#fff;color:#121212;border-radius:14px;width:min(320px,calc(100vw - 24px));padding:16px 16px 14px;box-shadow:0 18px 50px rgba(0,0,0,.4);font:13px/1.5 'Helvetica Neue',system-ui,sans-serif;text-align:left}
  #pdTFcx .kk{font-size:10px;letter-spacing:.15em;text-transform:uppercase;opacity:.45;margin-bottom:6px}
  #pdTFcx h3{margin:0 0 6px;font-size:16px;font-weight:600;line-height:1.3}
  #pdTFcx p{margin:0 0 12px;opacity:.8}
  #pdTFcx .rd{display:flex;align-items:center;gap:8px}#pdTFcx .pt{display:flex;gap:4px;flex:1}#pdTFcx .pt i{width:5px;height:5px;border-radius:50%;background:#121212;opacity:.18}#pdTFcx .pt i.on{opacity:.9}
  #pdTFcx button{border:1px solid rgba(0,0,0,.2);background:none;color:#121212;border-radius:999px;padding:9px 16px;font:500 12px/1 inherit;font-family:inherit;cursor:pointer;min-height:38px}#pdTFcx button.p{background:#121212;color:#fff;border-color:#121212}
  .pdTFq{flex:0 0 auto}`; document.head.appendChild(st); }
  function fecha(visto){ if (ov){ ov.remove(); ov = null; } if (visto !== false){ try { localStorage.setItem(chave, '1'); } catch(e){} } }
  function passo(){
    const p = cfg.passos[i]; if (!p) return fecha();
    const el = alvo(p), r = el ? el.getBoundingClientRect() : null;
    const f = ov.querySelector('#pdTFoco'), cx = ov.querySelector('#pdTFcx');
    if (r){ const m = 6; Object.assign(f.style, { display: '', left: (r.left - m) + 'px', top: (r.top - m) + 'px', width: (r.width + 2 * m) + 'px', height: (r.height + 2 * m) + 'px' }); }
    else { Object.assign(f.style, { display: '', left: '50%', top: '50%', width: '0px', height: '0px' }); }
    const ult = i === cfg.passos.length - 1;
    cx.innerHTML = `<div class="kk">Como funciona · ${cfg.nome}</div><h3>${p.t}</h3><p>${val(p.p)}</p><div class="rd"><div class="pt">${cfg.passos.map((_, k) => `<i class="${k === i ? 'on' : ''}"></i>`).join('')}</div>${ult ? '' : '<button type="button" data-a="x">Pular</button>'}<button type="button" class="p" data-a="n">${ult ? 'Entendi' : 'Próximo'}</button></div>`;
    cx.style.left = cx.style.top = '';
    const w = cx.offsetWidth, h = cx.offsetHeight;
    let x, y;
    if (r){ x = Math.max(12, Math.min(innerWidth - w - 12, r.left + r.width / 2 - w / 2)); y = r.bottom + 16; if (y + h > innerHeight - 12) y = r.top - h - 16; if (y < 12) y = Math.max(12, Math.min(innerHeight - h - 12, innerHeight - h - 24)); }
    else { x = (innerWidth - w) / 2; y = (innerHeight - h) / 2; }
    cx.style.left = x + 'px'; cx.style.top = y + 'px';
  }
  function abre(forcar){
    if (ov) return; css(); i = 0;
    ov = document.createElement('div'); ov.id = 'pdTF';
    ov.innerHTML = '<div id="pdTFoco" style="display:none"></div><div id="pdTFcx"></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', e => { const b = e.target.closest('button[data-a]'); if (!b){ return; } if (b.dataset.a === 'x') fecha(); else { i++; if (i >= cfg.passos.length) fecha(); else passo(); } });
    passo();
  }
  window.pdTourFerr = abre;
  function botao(){
    const top = document.querySelector('.top'); if (!top || top.querySelector('.pdTFq')) return;
    const b = document.createElement('button'); b.type = 'button'; b.className = 'btn i pdTFq'; b.title = 'Ver como funciona'; b.setAttribute('aria-label', 'Ver como funciona');
    b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .8-1 1.5M12 17h.01"/></svg>';
    b.onclick = () => abre(true); top.appendChild(b);
  }
  function inicio(){ botao(); let visto = false; try { visto = !!localStorage.getItem(chave); } catch(e){} if (!visto) setTimeout(() => { if (!document.getElementById('pdAjudaOv') && !document.querySelector('.guia')) abre(); }, 1600); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', inicio); else inicio();
})();
