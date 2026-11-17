/* DETALHES ESTRATÉGICOS DA CAMPANHA — gaveta que desce da faixa preta do aprovador, por cima dos criativos.
   Também desenha a identidade na faixa (logo da PD + logo do cliente) e o botão que abre a gaveta.
   Usado no link do cliente (ver.html) e na tela da equipe (aprovacao.html): um desenho só.
   O cliente (e a equipe) pode SUGERIR ajuste por seção; a equipe marca como resolvido.
   Dados = formato do Excel de setup (js/campanha-setup.js): { campanha, persona, publicos, anuncios, avisos }. */
window.CampPainel = (function () {
  const T = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const OBJ = { OUTCOME_LEADS: 'Leads', OUTCOME_TRAFFIC: 'Tráfego', OUTCOME_ENGAGEMENT: 'Engajamento', OUTCOME_AWARENESS: 'Reconhecimento', OUTCOME_SALES: 'Vendas' };
  // Ícones de linha, no traço da PD (nada colorido)
  const svg = (p, t) => `<svg viewBox="0 0 24 24" width="${t || 18}" height="${t || 18}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
  const IC = {
    campanha: '<path d="M4 10v4h3l8 4V6L7 10z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
    persona: '<circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/>',
    publicos: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
    anuncios: '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M7 9h10M7 13h6"/>',
    editar: '<path d="M4 20l4-1 11-11a2.1 2.1 0 0 0-3-3L5 16z"/>',
    fechar: '<path d="M6 6l12 12M18 6L6 18"/>',
    seta: '<path d="M6 9l6 6 6-6"/>',
    estrategia: '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>'
  };
  const TIT = { campanha: 'Campanha', persona: 'Persona', publicos: 'Públicos', anuncios: 'Anúncios' };
  let cssOk = false, aberta = false;
  function estilo() {
    if (cssOk) return; cssOk = true;
    const s = document.createElement('style');
    s.textContent = `
.cpn-marca{display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap}
.cpn-marca img.pd{height:20px;width:auto;display:block}
.cpn-marca .sep{width:1px;height:22px;background:currentColor;opacity:.25}
.cpn-marca .cli{display:inline-flex;align-items:center;height:30px;padding:0 10px;border-radius:8px;background:#fff;color:#111;font-size:12px;font-weight:700;letter-spacing:-.01em}
.cpn-marca .cli img{max-height:22px;max-width:120px;width:auto;display:block;object-fit:contain}
.cpn-slot{margin-left:auto}
.cpn-btn-top{display:none;align-items:center;gap:8px;font:inherit;font-size:12.5px;font-weight:700;color:inherit;background:transparent;border:1px solid rgba(255,255,255,.4);border-radius:20px;padding:7px 14px 7px 12px;cursor:pointer}
.cpn-btn-top.on{display:inline-flex}
.cpn-btn-top:hover,.cpn-btn-top[aria-expanded="true"]{background:rgba(255,255,255,.14)}
.cpn-btn-top svg.s{transition:transform .2s}
.cpn-btn-top[aria-expanded="true"] svg.s{transform:rotate(180deg)}
.cpn-btn-top .n{background:#fff;color:#111;border-radius:10px;font-size:10.5px;padding:1px 7px}
@media (max-width:640px){.cpn-btn-top .tx-l{display:none}}
.cpn-host{height:0}
.cpn-gav{position:fixed;left:0;right:0;top:var(--cpn-top,0px);z-index:60;max-height:0;overflow:hidden;background:var(--surface);color:var(--text);
  box-shadow:0 18px 40px rgba(0,0,0,.28);border-bottom:1px solid var(--border);transition:max-height .28s ease}
.cpn-gav.on{max-height:calc(100vh - var(--cpn-top,0px) - 8px);overflow-y:auto;scrollbar-width:thin}
.cpn-slot-bar{flex:0 0 auto;display:inline-flex}
.cpn-btn-top.na-barra{height:38px;padding:0 12px;border-color:var(--border);color:var(--text2)}
.cpn-btn-top.na-barra:hover,.cpn-btn-top.na-barra[aria-expanded="true"]{background:var(--surface2)}
.cpn-btn-top.na-barra .n{background:var(--accent);color:var(--accent-fg)}
@media (max-width:640px){.cpn-btn-top.na-barra svg.s{display:none}.cpn-btn-top.na-barra{padding:0 11px;gap:5px}}
.cpn-in{max-width:1400px;margin:0 auto;padding:18px clamp(16px,4vw,44px) 8px}
.cpn-cab{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.cpn-cab .k{font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--text3)}
.cpn-cab .nm{font-size:17px;font-weight:800;letter-spacing:-.02em;flex:1;min-width:160px}
.cpn-chip{font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);color:var(--text2)}
.cpn-x{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer}
.cpn-cols{column-count:2;column-gap:36px}
.cg-sec{break-inside:avoid;margin:0 0 20px}
.cg-sec>header{display:flex;align-items:center;gap:8px;margin-bottom:8px;padding-bottom:7px;border-bottom:1px solid var(--border)}
.cg-sec>header .ic{color:var(--text2);display:inline-flex}
.cg-sec>header h4{margin:0;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);flex:1}
.cg-ed{display:inline-flex;align-items:center;gap:5px;font:inherit;font-size:11.5px;font-weight:700;color:var(--text3);background:transparent;border:0;cursor:pointer;padding:2px 4px}
.cg-ed:hover{color:var(--text)}
.cg-l{font-size:13px;line-height:1.5;margin:0 0 3px}
.cg-l b{font-weight:700;color:var(--text2)}
.cg-row{padding:7px 0;border-top:1px solid var(--border);font-size:13px;line-height:1.45}
.cg-row:first-of-type{border-top:0;padding-top:0}
.cg-row .sub{color:var(--text2);font-size:12.5px}
.cg-row .x{color:var(--text2);white-space:pre-line;font-size:12.5px}
.cg-mais>summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text2);padding:6px 0 2px;list-style:none}
.cg-mais>summary::-webkit-details-marker{display:none}
.cg-aj{font-size:12px;line-height:1.45;padding:7px 10px;border-radius:8px;background:var(--surface2);margin-top:8px}
.cg-aj.ok{opacity:.55}
.cg-aj small{display:block;color:var(--text3);margin-top:2px}
.cg-form{display:none;margin-top:8px}
.cg-form.on{display:block}
.cg-form textarea{width:100%;min-height:64px;box-sizing:border-box;padding:9px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;resize:vertical}
.cg-form .ac{display:flex;gap:8px;margin-top:6px}
.cg-b{font:inherit;font-size:12px;font-weight:700;padding:6px 12px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer}
.cg-b.p{background:var(--accent);color:var(--accent-fg);border-color:transparent}
@media (max-width:760px){.cpn-cols{column-count:1}}`;
    document.head.appendChild(s);
  }
  const lin = (r, v) => v ? `<p class="cg-l"><b>${r}:</b> ${T(v)}</p>` : '';

  /** Identidade na faixa preta: logo da PD + logo (ou nome) do cliente. Idempotente. */
  function marca(topo, o) {
    estilo(); if (!topo) return;
    let m = topo.querySelector('.cpn-marca');
    if (!m) { m = document.createElement('div'); m.className = 'cpn-marca'; topo.insertBefore(m, topo.firstChild); }
    const cli = o && o.logo ? `<img src="${T(o.logo)}" alt="${T(o.nome || '')}" onerror="this.replaceWith(document.createTextNode(this.alt))">` : T((o && o.nome) || '');
    m.innerHTML = `<img class="pd" src="/assets/logo-pontodot-white.svg" alt="pontodot">${(o && (o.logo || o.nome)) ? `<span class="sep"></span><span class="cli">${cli}</span>` : ''}<span class="cpn-slot"></span>`;
  }

  function secao(k, corpo, o) {
    if (!corpo) return '';
    const meus = (o.ajustes || []).filter(a => a.secao === k);
    const notas = meus.map(a => `<div class="cg-aj ${a.resolvido ? 'ok' : ''}">${T(a.texto)}<small>${T(a.por || 'Cliente')}${a.em ? ' · ' + new Date(a.em).toLocaleDateString('pt-BR') : ''}${a.resolvido ? ' · resolvido' : ' · enviado para a equipe'}${o.podeResolver && !a.resolvido ? ` · <a href="#" data-resolver="${T(a.id)}" style="color:var(--accent);font-weight:700">marcar como resolvido</a>` : ''}</small></div>`).join('');
    const ed = o.podeSugerir ? `<button class="cg-ed" data-abrir="${k}" type="button" title="Sugerir ajuste">${svg(IC.editar, 14)}Sugerir ajuste</button>` : '';
    const form = o.podeSugerir ? `<div class="cg-form" data-form="${k}"><textarea placeholder="O que mudar em ${TIT[k].toLowerCase()}?"></textarea><div class="ac"><button class="cg-b p" data-enviar="${k}" type="button">Enviar</button><button class="cg-b" data-fechar="${k}" type="button">Cancelar</button></div></div>` : '';
    return `<section class="cg-sec"><header><span class="ic">${svg(IC[k])}</span><h4>${TIT[k]}</h4>${ed}</header>${corpo}${notas}${form}</section>`;
  }

  // A gaveta é fixa na tela e começa logo abaixo da faixa preta (ou, dentro do app, abaixo da barra de filtros)
  function posicionar() {
    const g = document.querySelector('.cpn-gav'); if (!g) return;
    const topo = document.querySelector('.ap-top'), barra = document.querySelector('.ap-bar');
    const topoVisivel = topo && topo.offsetParent !== null;
    const y = topoVisivel ? Math.max(0, topo.getBoundingClientRect().bottom) : (barra ? Math.max(0, barra.getBoundingClientRect().bottom) : 0);
    g.style.setProperty('--cpn-top', Math.round(y) + 'px');
  }
  function alternar(abrir) {
    const g = document.querySelector('.cpn-gav'), b = document.querySelector('.cpn-btn-top'); if (!g) return;
    aberta = abrir === undefined ? !aberta : abrir;
    if (aberta) posicionar();
    g.classList.toggle('on', aberta); if (b) b.setAttribute('aria-expanded', aberta ? 'true' : 'false');
    if (!window._cpnPos) { window._cpnPos = true; ['scroll', 'resize'].forEach(ev => window.addEventListener(ev, () => { if (aberta) posicionar(); }, { passive: true })); }
  }

  /** alvo = contêiner logo abaixo da faixa; o = { topo, dados, ajustes, podeSugerir, onSugerir(secao, texto), podeResolver, onResolver(id) } */
  function montar(alvo, o) {
    estilo();
    const slot = o.topo && o.topo.querySelector('.cpn-slot');
    const d = o.dados;
    if (!d || !d.campanha) { alvo.innerHTML = ''; alvo.className = ''; if (slot) slot.innerHTML = ''; return; }
    const c = d.campanha, p = d.persona || {}, pubs = d.publicos || [], ans = d.anuncios || [];
    const br = v => String(v || '').replace(/^(\d{4})-(\d{2})-(\d{2}).*$/, '$3/$2/$1');
    const periodo = [c.inicio, c.fim].filter(Boolean).map(br).join(' a ');
    const pend = (o.ajustes || []).filter(a => !a.resolvido).length;
    const sCamp = lin('Objetivo', OBJ[c.objetivo] || c.objetivo) + lin('Período', periodo) + lin('Formulário de leads', c.formulario) + lin('WhatsApp', c.whatsapp) + lin('Link de destino', c.link);
    const sPers = lin('Nome', p.nome) + lin('Quem é', p.quem) + lin('O que busca', p.busca) + lin('Dores e objeções', p.dores) + lin('Gatilhos de decisão', p.gatilhos) + lin('Tom de voz', p.tom) + lin('Referências', p.referencias);
    const sPubs = pubs.map(u => `<div class="cg-row"><b>${T(u.conjunto)}</b>${u.persona ? ' · ' + T(u.persona) : ''}<div class="sub">${[u.cidades && u.cidades.length ? T(u.cidades.join(', ')) + (u.raio ? ' (raio ' + T(u.raio) + ' km)' : '') : '', u.interesses && u.interesses.length ? 'Interesses: ' + T(u.interesses.join(', ')) : '', u.verba ? 'R$ ' + T(u.verba) + '/dia' : '', u.horarios && u.horarios.length ? u.horarios.length + ' faixa(s) de horário' : ''].filter(Boolean).join(' · ')}</div></div>`).join('');
    const linAn = a => `<div class="cg-row"><b>${T(a.anuncio)}</b> <span class="sub">· ${T(a.conjunto)}${a.botao ? ' · botão: ' + T(a.botao) : ''}</span>${a.titulo ? `<div>${T(a.titulo)}</div>` : ''}${a.texto ? `<div class="x">${T(a.texto)}</div>` : ''}</div>`;
    const sAns = ans.length > 5 ? ans.slice(0, 5).map(linAn).join('') + `<details class="cg-mais"><summary>Ver os outros ${ans.length - 5} anúncios</summary>${ans.slice(5).map(linAn).join('')}</details>` : ans.map(linAn).join('');

    alvo.className = 'cpn-host';
    alvo.innerHTML = `<div class="cpn-gav" role="region" aria-label="Detalhes estratégicos da campanha"><div class="cpn-in">
      <div class="cpn-cab"><span class="k">Detalhes estratégicos</span><span class="nm">${T(c.nome || 'Campanha')}</span>
        ${c.objetivo ? `<span class="cpn-chip">${T(OBJ[c.objetivo] || c.objetivo)}</span>` : ''}<span class="cpn-chip">${pubs.length} público(s)</span><span class="cpn-chip">${ans.length} anúncio(s)</span>
        <button class="cpn-x" type="button" aria-label="Fechar" data-fechar-gav>${svg(IC.fechar, 14)}</button></div>
      <div class="cpn-cols">${secao('campanha', sCamp, o)}${secao('persona', sPers, o)}${secao('publicos', sPubs, o)}${secao('anuncios', sAns, o)}</div></div></div>`;

    // Botão: na faixa preta; dentro do app (faixa escondida) vai pra barra de filtros
    const topoVisivel = o.topo && o.topo.offsetParent !== null, barra = document.querySelector('.ap-bar');
    const html = (extra) => `<button class="cpn-btn-top on ${extra}" type="button" aria-expanded="${aberta ? 'true' : 'false'}" title="Ver detalhes estratégicos da campanha">${svg(IC.estrategia, 16)}<span class="tx-l">${extra ? 'Estratégia' : 'Ver detalhes estratégicos da campanha'}</span>${pend ? `<span class="n">${pend}</span>` : ''}${svg(IC.seta, 14).replace('<svg ', '<svg class="s" ')}</button>`;
    document.querySelectorAll('.cpn-slot-bar').forEach(e => e.remove());
    let btn = null;
    if (topoVisivel && slot) { slot.innerHTML = html(''); btn = slot.querySelector('.cpn-btn-top'); }
    else if (barra) {
      const w = document.createElement('span'); w.className = 'cpn-slot-bar'; w.innerHTML = html('na-barra');
      const sel = barra.querySelector('#apSel'), selVisivel = sel && getComputedStyle(sel).display !== 'none';
      if (selVisivel) sel.insertAdjacentElement('afterend', w); else barra.insertBefore(w, barra.firstChild);
      btn = w.querySelector('.cpn-btn-top');
    }
    if (btn) btn.onclick = ev => { ev.stopPropagation(); alternar(); };
    if (aberta) { posicionar(); alvo.querySelector('.cpn-gav').classList.add('on'); }
    alvo.querySelector('[data-fechar-gav]').onclick = () => alternar(false);
    if (!window._cpnEsc) { window._cpnEsc = true; document.addEventListener('keydown', e => { if (e.key === 'Escape' && aberta) alternar(false); }); }
    alvo.querySelectorAll('[data-abrir]').forEach(b => b.onclick = () => { alvo.querySelector(`[data-form="${b.dataset.abrir}"]`).classList.add('on'); alvo.querySelector(`[data-form="${b.dataset.abrir}"] textarea`).focus(); });
    alvo.querySelectorAll('[data-fechar]').forEach(b => b.onclick = () => alvo.querySelector(`[data-form="${b.dataset.fechar}"]`).classList.remove('on'));
    alvo.querySelectorAll('[data-enviar]').forEach(b => b.onclick = async () => {
      const f = alvo.querySelector(`[data-form="${b.dataset.enviar}"]`), txt = f.querySelector('textarea').value.trim(); if (!txt) return;
      b.disabled = true; try { await o.onSugerir(b.dataset.enviar, txt); } catch (e) { b.disabled = false; alert('Não consegui enviar: ' + (e.message || e)); }
    });
    alvo.querySelectorAll('[data-resolver]').forEach(a => a.onclick = async ev => { ev.preventDefault(); try { await o.onResolver(a.dataset.resolver); } catch (e) { alert(e.message || e); } });
  }
  return { montar, marca, alternar };
})();
