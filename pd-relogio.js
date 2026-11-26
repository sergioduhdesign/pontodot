/* PD · RELÓGIO DA IA — um quadradinho com a carinha da IA, ponteiros por cima e uma setinha que abre tudo que está rolando.
   Na home ele senta em cima do quadradinho da IA; ao abrir janela/ferramenta/chat (blur, virada de tela) corre suave pro canto.
   Arrastável (sem grudar em nada). Fica por cima de tudo. Lê tarefas, aprovações e novidades que a PD já carrega. */
(function () {
  'use strict';
  if (self !== top || window.pdRel) return;
  const K_POS = 'sd_rel_pos', K_MODO = 'sd_rel_modo', K_OFF = 'sd_rel_off', Z = 9700;
  let desligado = false; try { desligado = localStorage.getItem(K_OFF) === '1'; } catch (e) {}
  let modo = 'urgentes', canto = null, livre = false, aberto = false, ocupada = false, tam = 124, drag = null, alvo = null, atual = null, raf = 0, ultimaCara = '';
  try { modo = localStorage.getItem(K_MODO) === 'tudo' ? 'tudo' : 'urgentes'; canto = JSON.parse(localStorage.getItem(K_POS) || 'null'); } catch (e) {}

  const css = document.createElement('style');
  css.textContent = `
  #pdRel{position:fixed;left:0;top:0;z-index:${Z};pointer-events:none;width:var(--rl,124px);will-change:transform;transition:opacity .3s;font-family:inherit}
  #pdRel.some{opacity:0;visibility:hidden}
  #pdRel>*{pointer-events:auto}
  #pdRel .rl-face{position:relative;display:block;width:var(--rl,124px);height:var(--rl,124px);padding:0;border:.5px solid var(--linha,rgba(255,255,255,.35));border-radius:var(--r,18px);overflow:hidden;background:var(--vidro,rgba(8,10,9,.5));color:var(--branco,#fff);cursor:grab;-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);touch-action:none;-webkit-tap-highlight-color:transparent}
  #pdRel .rl-face::before{content:'';position:absolute;inset:0;z-index:1;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.1) 1px,transparent 1.4px);background-size:9px 9px}
  #pdRel.arrasta .rl-face{cursor:grabbing}
  #pdRel .rl-img{position:absolute;inset:0;display:block}
  #pdRel .rl-img .gad-cara,#pdRel .rl-img img{width:100%;height:100%;object-fit:cover;display:block;border-radius:0}
  #pdRel .rl-img .gad-ovo,#pdRel .rl-img .gad-cara-s{display:grid;place-items:center;width:100%;height:100%;color:#fff}
  #pdRel .rl-img svg{width:60%;height:60%}
  #pdRel .rl-veu{position:absolute;inset:0;background:radial-gradient(circle at 50% 50%,rgba(0,0,0,.05) 30%,rgba(0,0,0,.34) 100%)}
  #pdRel .rl-mao{position:absolute;inset:0;width:100%;height:100%;overflow:visible;filter:drop-shadow(0 1px 2px rgba(0,0,0,.85)) drop-shadow(0 0 4px rgba(0,0,0,.5))}
  #pdRel .rl-info{position:absolute;left:0;right:0;bottom:0;display:flex;align-items:flex-end;justify-content:space-between;padding:18px 10px 8px;background:linear-gradient(to top,rgba(0,0,0,.72),rgba(0,0,0,0));color:#fff;text-shadow:0 1px 3px #000;pointer-events:none}
  #pdRel .rl-dia{display:flex;align-items:baseline;gap:5px;line-height:1}#pdRel .rl-dia b{font-size:calc(var(--rl,124px)*.17);font-weight:600}#pdRel .rl-dia small{font-size:calc(var(--rl,124px)*.075);letter-spacing:.03em;line-height:1.1;max-width:34px;text-transform:uppercase;opacity:.9}
  #pdRel .rl-clima{display:flex;align-items:center;gap:3px;font-size:calc(var(--rl,124px)*.115);font-weight:500;line-height:1}#pdRel .rl-clima svg{width:calc(var(--rl,124px)*.16);height:calc(var(--rl,124px)*.16);fill:none;stroke:#fff;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 1px 2px rgba(0,0,0,.8))}
  #pdRel .rl-clima:empty{display:none}
  #pdRel .rl-clx{padding:2px 10px 8px;font-size:12.5px;opacity:.92;display:flex;align-items:center;gap:7px}#pdRel .rl-clx svg{width:20px;height:20px;fill:none;stroke:#fff;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
  #pdRel .rl-bol{position:absolute;top:-8px;right:-8px;z-index:3;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#dc2626;color:#fff;font:700 11px/18px inherit;text-align:center;box-shadow:0 0 0 1.5px rgba(255,255,255,.9),0 2px 7px rgba(0,0,0,.5);display:none;box-sizing:border-box;pointer-events:none}
  #pdRel .rl-bol.on{display:block}
  #pdRel .rl-box{position:relative;width:var(--rl,124px)}
  #pdRel .rl-seta{display:block;margin:5px auto 0;width:34px;height:20px;padding:0;border:0;background:none;color:#fff;cursor:pointer;opacity:.92;filter:drop-shadow(0 2px 3px rgba(0,0,0,.65));transition:transform .3s,opacity .2s}
  #pdRel .rl-seta:hover{opacity:1}
  #pdRel .rl-seta svg{display:block;width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  #pdRel.aberto .rl-seta{transform:rotate(180deg)}
  #pdRel .rl-lista{position:absolute;left:50%;top:calc(100% + 2px);width:min(360px,calc(100vw - 24px));max-height:min(64vh,540px);overflow-y:auto;overscroll-behavior:contain;padding:8px 6px 10px;border-radius:20px;background:rgba(10,11,10,.78);-webkit-backdrop-filter:blur(26px) saturate(.8);backdrop-filter:blur(26px) saturate(.8);box-shadow:0 18px 44px rgba(0,0,0,.5),inset 0 0 0 .5px rgba(255,255,255,.16);color:#fff;transform:translateX(-50%);display:none;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.4) transparent;text-shadow:0 1px 3px #000,0 0 9px rgba(0,0,0,.85)}
  #pdRel.aberto .rl-lista{display:block;animation:rlIn .28s cubic-bezier(.22,.8,.24,1)}
  #pdRel.acima .rl-lista{top:auto;bottom:calc(100% + 30px)}
  #pdRel.esq .rl-lista{left:0;transform:none}
  #pdRel.dir .rl-lista{left:auto;right:0;transform:none}
  @keyframes rlIn{from{opacity:0;translate:0 -6px}to{opacity:1;translate:0 0}}
  #pdRel .rl-cab{display:flex;align-items:center;gap:6px;padding:2px 8px 8px;font-size:12px;letter-spacing:.04em}
  #pdRel .rl-cab .rl-hora{flex:1;opacity:.85}
  #pdRel .rl-chip{border:0;background:none;color:#fff;font:inherit;font-size:12px;padding:3px 9px;border-radius:999px;cursor:pointer;opacity:.62;text-shadow:inherit}
  #pdRel .rl-chip.on{opacity:1;background:rgba(0,0,0,.42);box-shadow:inset 0 0 0 .5px rgba(255,255,255,.4)}
  #pdRel .rl-it{display:flex;align-items:center;gap:11px;width:100%;padding:8px 10px;border:0;border-radius:12px;background:none;color:#fff;text-align:left;cursor:pointer;font:inherit;text-shadow:inherit}
  #pdRel .rl-it:hover{background:rgba(0,0,0,.34)}
  #pdRel .rl-ic{flex:0 0 22px;width:22px;height:22px;display:grid;place-items:center;filter:drop-shadow(0 1px 2px rgba(0,0,0,.8))}
  #pdRel .rl-ic svg{width:22px;height:22px}
  #pdRel .rl-tx{min-width:0;flex:1;display:block}
  #pdRel .rl-tx b{display:block;font-weight:500;font-size:14px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #pdRel .rl-tx small{display:block;font-size:11.5px;opacity:.78;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #pdRel .rl-it.rl-u .rl-tx small{opacity:1;color:#ffd0c8}
  #pdRel .rl-vazio{padding:14px 10px;font-size:13px;opacity:.85}
  @media (max-width:999px){#pdRel{display:none}}
  `;
  document.head.appendChild(css);

  const el = document.createElement('div'); el.id = 'pdRel'; el.className = 'some';
  el.innerHTML = `<div class="rl-box"><button class="rl-face" type="button" aria-label="Relógio da PD" title="Relógio da PD"><span class="rl-img"></span><span class="rl-veu"></span>
      <svg class="rl-mao" viewBox="0 0 100 100"><g stroke="#fff" stroke-linecap="round" fill="#fff">
        <circle cx="50" cy="10" r="1.8" stroke="none" opacity=".9"/><circle cx="82" cy="42" r="1.8" stroke="none" opacity=".9"/><circle cx="50" cy="74" r="1.8" stroke="none" opacity=".9"/><circle cx="18" cy="42" r="1.8" stroke="none" opacity=".9"/>
        <line class="rl-h" x1="50" y1="42" x2="50" y2="24" stroke-width="4.2"/><line class="rl-m" x1="50" y1="42" x2="50" y2="14" stroke-width="2.8"/><circle cx="50" cy="42" r="3.2" stroke="none"/></g></svg>
      <span class="rl-info"><span class="rl-dia"><b></b><small></small></span><span class="rl-clima"></span></span>
      </button><i class="rl-bol"></i>
    <button class="rl-seta" type="button" aria-label="Ver o que está pendente" title="O que está rolando"><svg viewBox="0 0 34 20"><path d="M7 5l10 10 10-10"/></svg></button>
    <div class="rl-lista" role="list"></div></div>`;
  document.body.appendChild(el);
  const $ = s => el.querySelector(s), face = $('.rl-face'), lista = $('.rl-lista'), bol = $('.rl-bol');

  const ico = id => { try { if (typeof icoGad === 'function' && typeof ICONE_GAD !== 'undefined' && ICONE_GAD[id]) return icoGad(id); } catch (e) {}
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.4"/><path d="M12 7.6V12l3 1.8"/></svg>'; };
  const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const g = nome => { try { return (0, eval)(nome); } catch (e) { return undefined; } };   // lê as variáveis globais da PD sem quebrar se ainda não existirem

  /* ---------- ponteiros ---------- */
  function hora() {
    const d = new Date(), m = d.getMinutes() + d.getSeconds() / 60, h = (d.getHours() % 12) + m / 60;
    $('.rl-h').style.transform = `rotate(${h * 30}deg)`; $('.rl-m').style.transform = `rotate(${m * 6}deg)`;
    ['.rl-h', '.rl-m'].forEach(s => { $(s).style.transformOrigin = '50px 42px'; });
    $('.rl-dia b').textContent = d.getDate();
    $('.rl-dia small').textContent = d.toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', '') + ' ' + d.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '');
    const c = $('.rl-hora'); if (c) c.textContent = d.toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  /* ---------- clima (Open-Meteo, sem chave; Campinas) ---------- */
  const LOC = { nome: 'Campinas', lat: -22.9056, lon: -47.0608 };
  let clima = null; try { clima = JSON.parse(localStorage.getItem('sd_rel_clima') || 'null'); } catch (e) {}
  const SV = d => `<svg viewBox="0 0 24 24">${d}</svg>`;
  const ICL = { sol: SV('<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6"/>'),
    lua: SV('<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>'),
    parcial: SV('<path d="M7 18a4 4 0 0 1-.4-8A5.5 5.5 0 0 1 17 8.5a4.5 4.5 0 0 1 .5 9.5z"/>'),
    nuvem: SV('<path d="M7 19a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 9a5 5 0 0 1 0 10z"/>'),
    chuva: SV('<path d="M7 15a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 5.5a5 5 0 0 1-.5 9.5z"/><path d="M8 18.5l-1 2M12 18.5l-1 2M16 18.5l-1 2"/>'),
    raio: SV('<path d="M7 14a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 4.5a5 5 0 0 1-.5 9.5z"/><path d="M12.5 12l-2.2 4h3l-2 4"/>'),
    neblina: SV('<path d="M4 9h16M6 13h12M4 17h16"/>') };
  function leClima(c) { const k = c.code, dia = c.dia;
    if (k === 0) return ['Céu limpo', dia ? 'sol' : 'lua']; if (k <= 2) return ['Parcialmente nublado', 'parcial']; if (k === 3) return ['Nublado', 'nuvem'];
    if (k === 45 || k === 48) return ['Neblina', 'neblina']; if (k >= 51 && k <= 67) return ['Chuva', 'chuva']; if (k >= 80 && k <= 82) return ['Pancadas de chuva', 'chuva']; if (k >= 95) return ['Tempestade', 'raio']; return ['Nublado', 'nuvem']; }
  function pintaClima() {
    const box = $('.rl-clima'); if (!clima) { box.innerHTML = ''; return; }
    const [txt, ic] = leClima(clima); box.innerHTML = ICL[ic] + '<span>' + Math.round(clima.t) + '°</span>'; box.title = LOC.nome + ' · ' + txt;
  }
  function pegaClima() {
    if (clima && Date.now() - (clima.em || 0) < 20 * 60e3) return pintaClima();
    fetch(`https://api.open-meteo.com/v1/forecast?latitude=${LOC.lat}&longitude=${LOC.lon}&current=temperature_2m,weather_code,is_day&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=America%2FSao_Paulo&forecast_days=1`)
      .then(r => r.json()).then(j => { if (!j || !j.current) return; clima = { t: j.current.temperature_2m, code: j.current.weather_code, dia: j.current.is_day === 1, max: j.daily.temperature_2m_max[0], min: j.daily.temperature_2m_min[0], chuva: (j.daily.precipitation_probability_max || [null])[0], em: Date.now() };
        try { localStorage.setItem('sd_rel_clima', JSON.stringify(clima)); } catch (e) {} pintaClima(); if (aberto) pinta(); }).catch(() => {});
  }

  /* ---------- o que está pendente ---------- */
  function pendencias() {
    const out = [], hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    const T = Array.isArray(g('tasksReais')) ? g('tasksReais') : [];
    const dias = t => { const p = String(t.date_int || t.date_cli || '').slice(0, 10); if (!p) return null; return Math.round((new Date(p + 'T12:00:00') - hoje) / 864e5); };
    let eu = ''; try { eu = typeof chaveDe === 'function' ? chaveDe(g('perfil')) : ''; } catch (e) {}
    const minha = t => { if (!eu) return true; const r = typeof semAcento === 'function' ? semAcento(t.resp) : String(t.resp || '').toUpperCase(); return r === eu || r.split(/[,;/&+]+|\s+E\s+/).map(x => x.trim()).includes(eu) || r.split(/\s+/)[0] === eu; };   // só as MINHAS demandas
    T.filter(minha).forEach(t => {
      const n = dias(t), st = String(t.status || ''), cli = (t.clients && t.clients.name) || 'SD', quem = '';
      const base = { titulo: String(t.action || t.id).slice(0, 70), tarefa: t.id };
      if (st === 'ALTERAÇÃO') out.push({ ...base, p: 1, u: 1, i: 'aprovacoes', sub: cli + ' · pediu alteração', a: 'aprov' });
      else if (n !== null && n < 0) out.push({ ...base, p: 2 + Math.min(.9, 1 / (1 - n)), u: 1, i: 'tarefas', sub: cli + ' · atrasada ' + (-n) + (n === -1 ? ' dia' : ' dias') + quem, a: 'tarefa', hot: 1 });
      else if (n === 0) out.push({ ...base, p: 3, u: 1, i: 'tarefas', sub: cli + ' · entrega hoje' + quem, a: 'tarefa', hot: 1 });
      else if (st === 'ATENÇÃO') out.push({ ...base, p: 4, u: 1, i: 'aprovacoes', sub: cli + ' · aprovada, falta finalizar', a: 'aprov' });
      else if (n === 1) out.push({ ...base, p: 5, u: 0, i: 'tarefas', sub: cli + ' · entrega amanhã' + quem, a: 'tarefa' });
      else if (st === 'DELEGADA') out.push({ ...base, p: 6, u: 0, i: 'aprovacoes', sub: cli + ' · com o cliente', a: 'aprov' });
      else if (n !== null && n <= 7) out.push({ ...base, p: 7 + n / 10, u: 0, i: 'cronograma', sub: cli + ' · em ' + n + ' dias' + quem, a: 'tarefa' });
      else out.push({ ...base, p: 9, u: 0, i: 'tarefas', sub: cli + (n === null ? ' · sem prazo' : ' · em ' + n + ' dias') + quem, a: 'tarefa' });
    });
    // novidades (comentários, Escola, Oficina, Estúdio) — o que a PD já marca com bolinha
    const NV = g('NOVIDADES'), novo = g('eNovo');
    if (NV && typeof novo === 'function') {
      const rot = { tarefas: ['tarefas', n => n + (n > 1 ? ' comentários novos' : ' comentário novo'), 'tarefas'], escola: ['escola', n => 'Escola: ' + n + (n > 1 ? ' aulas para validar' : ' aula para validar'), 'escola'],
        oficina: ['oficina', n => 'Oficina: ' + n + (n > 1 ? ' materiais prontos' : ' material pronto'), 'estudio'], estudio: ['estudio', n => 'Estúdio: ' + n + (n > 1 ? ' rodadas para ver' : ' rodada para ver'), 'estudio'] };
      Object.keys(rot).forEach(k => { try { const n = (NV[k] ? NV[k]() : []).filter(x => novo(k, x.k)).length; if (n) out.push({ titulo: rot[k][1](n), sub: 'novo', p: 2.5, u: 1, i: rot[k][0], a: 'ir', ir: rot[k][2] }); } catch (e) {} });
    }
    return out.sort((a, b) => a.p - b.p);
  }

  function climaLinha() {
    if (!clima) return '';
    const [txt, ic] = leClima(clima);
    return `<div class="rl-clx">${ICL[ic]}<span>${esc(LOC.nome)} · ${Math.round(clima.t)}° · ${esc(txt)}<br><small style="opacity:.75">máx ${Math.round(clima.max)}° · mín ${Math.round(clima.min)}°${clima.chuva != null ? ' · chuva ' + clima.chuva + '%' : ''}</small></span></div>`;
  }
  function pinta() {
    hora();
    const todas = pendencias(), urg = todas.filter(x => x.u), L = modo === 'tudo' ? todas : urg;
    bol.textContent = urg.length > 99 ? '99+' : urg.length; bol.classList.toggle('on', urg.length > 0);
    if (!aberto) return;
    const topo = lista.scrollTop;
    lista.innerHTML = climaLinha() + `<div class="rl-cab"><span class="rl-hora"></span><button class="rl-chip ${modo === 'urgentes' ? 'on' : ''}" data-m="urgentes">Urgentes${urg.length ? ' ' + urg.length : ''}</button><button class="rl-chip ${modo === 'tudo' ? 'on' : ''}" data-m="tudo">Tudo ${todas.length}</button></div>`
      + (L.length ? L.slice(0, 60).map((x, i) => `<button class="rl-it ${x.hot ? 'rl-u' : ''}" role="listitem" data-i="${i}"><span class="rl-ic">${ico(x.i)}</span><span class="rl-tx"><b>${esc(x.titulo)}</b><small>${esc(x.sub)}</small></span></button>`).join('')
        : `<div class="rl-vazio">${modo === 'tudo' ? 'Nada pendente agora.' : 'Nada urgente agora.'}</div>`);
    lista._L = L; hora(); lista.scrollTop = topo;
  }

  lista.addEventListener('click', e => {
    const c = e.target.closest('.rl-chip'); if (c) { modo = c.dataset.m; try { localStorage.setItem(K_MODO, modo); } catch (er) {} pinta(); return; }
    const b = e.target.closest('.rl-it'); if (!b) return; const x = (lista._L || [])[+b.dataset.i]; if (!x) return;
    fecha();
    try {
      if (x.a === 'tarefa' && typeof abrirTarefaReal === 'function') { if (typeof visitarTarefa === 'function') visitarTarefa(x.tarefa); abrirTarefaReal(x.tarefa); }
      else if (x.a === 'aprov' && typeof abrirAprovDireto === 'function') { if (typeof visitarTarefa === 'function') visitarTarefa(x.tarefa); abrirAprovDireto(x.tarefa); }
      else if (x.a === 'ir') { if (x.ir === 'escola' && typeof abrirEscola === 'function') abrirEscola(); else if (x.ir === 'estudio' && typeof abrirEstudio === 'function') abrirEstudio(''); else if (typeof abrirTarefasTodas === 'function') abrirTarefasTodas(); }
    } catch (er) {}
  });

  function abre() { aberto = true; el.classList.add('aberto'); posiciona(); pinta(); }
  function fecha() { aberto = false; el.classList.remove('aberto'); }
  $('.rl-seta').addEventListener('click', () => aberto ? fecha() : abre());
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && aberto) fecha(); });
  document.addEventListener('pointerdown', e => { if (aberto && !el.contains(e.target)) fecha(); }, true);

  /* ---------- posição: no quadradinho da IA (home) ou no canto, arrastável ---------- */
  const lim = (x, y) => [Math.max(8, Math.min(innerWidth - tam - 8, x)), Math.max(8, Math.min(innerHeight - tam - 40, y))];
  function slotHome() {
    const b = document.querySelector('.frente .gad[data-fk="gad-minhaia"] .gad-ico'); if (!b) return null;
    const r = b.getBoundingClientRect(); if (r.width < 24 || r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) return null;
    let esq = r.left; document.querySelectorAll('.frente .gad .gad-ico').forEach(x => { const q = x.getBoundingClientRect(); if (Math.abs(q.top - r.top) < 6 && q.left < esq && q.width > 20) esq = q.left; });
    return { x: esq, y: r.bottom + 34 };   // embaixo da fileira de caixas, alinhado à primeira
  }
  function ehOcupada() {
    const H = document.documentElement.classList;
    if (H.contains('comJanela') || H.contains('noVerso') || H.contains('emChat')) return true;
    if (document.querySelector('.frente .gad:not(.fechado)')) return true;
    const j = document.getElementById('janela'); if (j && !j.classList.contains('oculto') && j.offsetParent !== null && getComputedStyle(j).display !== 'none') return true;
    const kids = document.body.children;
    for (let i = 0; i < kids.length; i++) { const k = kids[i]; if (k === el || k.tagName === 'SCRIPT' || k.tagName === 'STYLE') continue;
      const s = getComputedStyle(k); if (s.position !== 'fixed' || s.display === 'none' || s.visibility === 'hidden' || +s.opacity < .05) continue;
      const z = parseInt(s.zIndex, 10); if (!(z >= 40)) continue; const r = k.getBoundingClientRect();
      if (r.width >= innerWidth - 4 && r.height >= innerHeight - 4 && s.pointerEvents !== 'none') return true; }
    return false;
  }
  function alvoAgora() {
    const sl = !ocupada && !livre ? slotHome() : null;
    tam = 124; if (sl) return [sl.x, sl.y, true];
    const c = canto || { x: innerWidth - 124 - 18, y: 74 }; const [x, y] = lim(c.x, c.y); return [x, y, false];
  }
  function posiciona() {
    el.style.setProperty('--rl', tam + 'px');
    const [x, y] = alvoAgora(); el.style.setProperty('--rl', tam + 'px'); alvo = [x, y];
    if (!atual) atual = [x, y]; if (!raf) raf = requestAnimationFrame(passo);
    // lista pra cima se faltar espaço embaixo; pro lado se estiver colada na borda
    el.classList.toggle('acima', y + tam + 40 + 280 > innerHeight && y > innerHeight / 2);
    el.classList.toggle('esq', x < 190); el.classList.toggle('dir', x > innerWidth - 190 - tam);
  }
  function passo() {
    raf = 0; if (!alvo || !atual) return;
    const k = drag ? .32 : .16, dx = alvo[0] - atual[0], dy = alvo[1] - atual[1];
    atual[0] += dx * k; atual[1] += dy * k;
    if (Math.abs(dx) < .3 && Math.abs(dy) < .3) { atual[0] = alvo[0]; atual[1] = alvo[1]; } else raf = requestAnimationFrame(passo);
    el.style.transform = `translate3d(${atual[0]}px,${atual[1]}px,0)`;
  }

  face.addEventListener('pointerdown', e => {
    if (e.button) return; drag = { sx: e.clientX, sy: e.clientY, ox: atual ? atual[0] : 0, oy: atual ? atual[1] : 0, mexeu: false }; try { face.setPointerCapture(e.pointerId); } catch (er) {}
  });
  face.addEventListener('pointermove', e => {
    if (!drag) return; const dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
    if (!drag.mexeu && Math.hypot(dx, dy) < 6) return;
    if (!drag.mexeu) { drag.mexeu = true; el.classList.add('arrasta'); fecha(); }
    const [x, y] = lim(drag.ox + dx, drag.oy + dy); alvo = [x, y]; if (!raf) raf = requestAnimationFrame(passo);
  });
  function solta(e) {
    if (!drag) return; const d = drag; drag = null; el.classList.remove('arrasta'); try { face.releasePointerCapture(e.pointerId); } catch (er) {}
    if (d.mexeu) { canto = { x: alvo[0], y: alvo[1] }; livre = !ocupada; try { localStorage.setItem(K_POS, JSON.stringify(canto)); } catch (er) {} posiciona(); return; }
    aberto ? fecha() : abre();
  }
  face.addEventListener('pointerup', solta); face.addEventListener('pointercancel', solta);

  /* ---------- ciclo: acompanha a tela ---------- */
  function ciclo() {
    if (document.hidden) return;
    const visivel = !desligado && !!document.getElementById('telaHome') && !(typeof ehCliente === 'function' && ehCliente());
    el.classList.toggle('some', !visivel); if (!visivel) return;
    const oc = ehOcupada(); if (oc !== ocupada) { ocupada = oc; if (oc) { fecha(); } else livre = false; }
    const cara = (typeof caraDaIA === 'function') ? (() => { try { return caraDaIA(); } catch (e) { return ''; } })() : '';
    if (cara !== ultimaCara) { ultimaCara = cara; $('.rl-img').innerHTML = cara || ico('minhaia'); }
    posiciona();
  }
  addEventListener('resize', () => { if (canto) { const [x, y] = lim(canto.x, canto.y); canto = { x, y }; } posiciona(); });
  new MutationObserver(() => { if (!window._relT) window._relT = setTimeout(() => { window._relT = 0; ciclo(); }, 80); })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  new MutationObserver(() => { if (!window._relT) window._relT = setTimeout(() => { window._relT = 0; ciclo(); }, 120); })
    .observe(document.body, { childList: true });
  setInterval(ciclo, 700); setInterval(pinta, 15000); setInterval(hora, 20000);
  setTimeout(() => { ciclo(); pinta(); pegaClima(); }, 800); setInterval(pegaClima, 5 * 60e3); pintaClima();
  window.pdRel = { atualiza: () => { ciclo(); pinta(); }, abre, fecha, desligado: () => desligado,
    alterna: () => { desligado = !desligado; try { localStorage.setItem(K_OFF, desligado ? '1' : '0'); } catch (e) {} if (desligado) fecha(); ciclo(); if (!desligado) pinta(); return desligado; } };
})();
