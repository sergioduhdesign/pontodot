/* =====================================================================
   Pontodot — NOVA TAREFA GUIADA + RASCUNHO ao vivo
   A tarefa nova entra por perguntas simples (cliente → peça → assunto → layout →
   cores → fontes → prazo). A cada resposta, uma telinha "Rascunho" monta um
   mockup isométrico em linhas (traço branco no preto, ou o inverso). Não é IA
   desenhando: é MONTAGEM com o que a PD já sabe (kit de marca do cliente, histórico
   de tarefas, catálogo). A IA só propõe os assuntos (pd-rascunho.php).
   No fim, preenche o formulário de verdade e chama saveTask().
   O formulário clássico continua a um clique ("Formulário").
   ===================================================================== */
(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const norm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  const pref = { get() { try { return localStorage.getItem('pd_nova_tarefa') || 'guiado'; } catch (e) { return 'guiado'; } }, set(v) { try { localStorage.setItem('pd_nova_tarefa', v); } catch (e) {} } };

  // ----------------------------------------------------------- o que cada peça é
  const TIPOS = [
    { id: 'post', nome: 'Post de feed', fmt: '1080×1080', w: 1, h: 1, kw: ['post', 'feed'] },
    { id: 'carrossel', nome: 'Carrossel', fmt: '1080×1350', w: .8, h: 1, n: 3, kw: ['carrossel'] },
    { id: 'story', nome: 'Story', fmt: '1080×1920', w: .56, h: 1, kw: ['stor'] },
    { id: 'video', nome: 'Reels / vídeo', fmt: '1080×1920', w: .56, h: 1, kw: ['reels', 'video'] },
    { id: 'anuncio', nome: 'Anúncio', fmt: '1200×628', w: 1, h: .52, kw: ['anuncio', 'campanha'] },
    { id: 'lp', nome: 'Landing page', fmt: 'desktop + mobile', w: 1, h: .72, browser: true, kw: ['landing'] },
    { id: 'site', nome: 'Site', fmt: 'desktop + mobile', w: 1, h: .68, browser: true, kw: ['site'] },
    { id: 'impresso', nome: 'Material impresso', fmt: 'conforme gráfica', w: .9, h: .64, flat: true, kw: ['impresso', 'folder', 'panfleto', 'banner', 'cartaz'] },
    { id: 'logo', nome: 'Identidade / logo', fmt: 'vetor', w: .8, h: .8, kw: ['logo', 'identidade', 'marca'] },
    { id: 'apresentacao', nome: 'Apresentação / book', fmt: '1920×1080', w: 1.2, h: .68, kw: ['apresenta', 'book', 'deck'] },
    { id: 'outro', nome: 'Outro', fmt: '', w: 1, h: .8, kw: [] }
  ];
  const LAYOUTS = [
    { id: 'foto', nome: 'Foto cheia + título' }, { id: 'tipo', nome: 'Tipográfico' }, { id: 'split', nome: 'Foto e texto lado a lado' },
    { id: 'grid', nome: 'Grade de imagens' }, { id: 'quote', nome: 'Frase / depoimento' }, { id: 'numero', nome: 'Número em destaque' }
  ];
  const NEUTRAS = [{ name: 'Preto', hex: '#0b0c0b' }, { name: 'Off-white', hex: '#f4f4f0' }, { name: 'Dourado', hex: '#c8a45a' }, { name: 'Grafite', hex: '#3a3b3d' }, { name: 'Azul', hex: '#2f5bd8' }];

  // ----------------------------------------------------------- estado
  let S, PASSO, KIT = null, KITFEITO = false, SUG = [], SUGLOADING = false, DESENHADO = {};
  const novoEstado = () => ({ cli: null, prod: '', tipo: null, assunto: '', titulo: '', layout: null, bg: null, acc: null, fT: '', fC: '', resp: '', prazo: null, projeto: '', inv: false, grande: false });

  // ----------------------------------------------------------- dados da PD
  const clientes = () => (window.clients || []).filter(c => c.active !== false);
  const tarefasDe = id => (window.tasks || []).filter(t => t.client_id === id);
  function prodsDe(id) { return (window.products || []).filter(p => p.client_id === id); }
  async function carregaKit(id) {
    KIT = null; KITFEITO = false;
    try { const { data } = await sb.from('brand_kits').select('colors,fonts,grids,notes').eq('client_id', id).maybeSingle(); KIT = data || null; } catch (e) {}
    KITFEITO = true;
    for (const f of (KIT && KIT.fonts) || []) { try { if (f.file_url && !document.fonts.check('12px "' + f.name + '"')) { const ff = new FontFace(f.name, 'url(' + f.file_url + ')'); await ff.load(); document.fonts.add(ff); } } catch (e) {} }
    if (S && S.cli && S.cli.id === id) { const c = cores(); if (!S.bg && c.length) { S.bg = c[0].hex; S.acc = (c[1] || c[0]).hex; } desenha(); }
  }
  const cores = () => ((KIT && KIT.colors) || []).filter(c => c && c.hex);
  const fontes = () => ((KIT && KIT.fonts) || []).filter(f => f && f.name);
  function formatoDa(t) { const g = ((KIT && KIT.grids) || []).find(x => x.name && t.kw.some(k => norm(x.name).includes(k))); return g ? g.w + '×' + g.h : t.fmt; }
  function respUsual(id) { const m = {}; tarefasDe(id).forEach(t => { if (t.resp && t.resp !== 'CLIENTE') m[t.resp] = (m[t.resp] || 0) + 1; }); const k = Object.keys(m).sort((a, b) => m[b] - m[a])[0]; return k || ''; }
  function projetosDe(id) { const m = {}; tarefasDe(id).forEach(t => { if (t.project) m[t.project] = (m[t.project] || 0) + 1; }); return Object.keys(m).sort((a, b) => m[b] - m[a]).slice(0, 5); }
  function diasUteis(n) { const d = new Date(); let i = 0; while (i < n) { d.setDate(d.getDate() + 1); if (d.getDay() % 6) i++; } return d; }
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

  // ----------------------------------------------------------- o RASCUNHO (SVG isométrico em linhas)
  const SC = 92, OX = 160;
  function desenhaSVG() {
    const t = S.tipo, inv = S.inv;
    const L = inv ? '#0b0c0b' : '#f4f4f0', BG = inv ? '#f4f4f0' : '#0b0c0b', DIM = inv ? 'rgba(0,0,0,.18)' : 'rgba(255,255,255,.16)';
    const w = t ? t.w : 1, h = t ? t.h : 1, flat = !!(t && t.flat);
    const oy = 138 + (flat ? 0 : h * SC / 2);
    const x0 = -w / 2, th = .05;
    const P = (x, y, z) => [OX + (x - y) * .866 * SC, oy + (x + y) * .5 * SC - z * SC];
    const F = (u, v, dy = 0) => flat ? P(x0 + u * w, v * h, 0) : P(x0 + u * w, dy, v * h);
    const pt = a => a[0].toFixed(1) + ',' + a[1].toFixed(1);
    const sig = {}, out = [];
    const el = (layer, s, d) => { (sig[layer] = sig[layer] || []).push(d); out.push([layer, s]); };
    const path = (layer, pts, o = {}) => el(layer, `<path pathLength="1" d="M${pts.map(pt).join(' L')}${o.z ? ' Z' : ''}" fill="${o.fill || 'none'}" stroke="${o.c || L}" stroke-width="${o.w || 1.3}" stroke-linejoin="round" stroke-linecap="round" opacity="${o.o == null ? 1 : o.o}" data-l="${layer}"/>`, pts.join());
    const uv = (layer, pts, o) => path(layer, pts.map(p => F(p[0], p[1], o && o.dy || 0)), o);
    const accent = S.acc || L;

    // chão: grade em losango
    for (let i = -3; i <= 3; i++) { path('grid', [P(-1.7, i * .42 + .5, 0), P(1.7, i * .42 + .5, 0)], { c: DIM, w: .7 }); path('grid', [P(i * .5, -.6, 0), P(i * .5, 1.7, 0)], { c: DIM, w: .7 }); }
    if (!t) { return finaliza(sig, out, BG, L); }

    // a peça
    const unid = t.n ? [...Array(t.n).keys()].reverse() : [0];
    unid.forEach(k => {
      const dy = flat ? 0 : k * .32, dx = t.n ? (k - 1) * -.18 : 0;
      const Fk = (u, v) => flat ? P(x0 + u * w, v * h, 0) : P(x0 + u * w + dx, dy, v * h);
      const U = (pts, o) => path('peca', pts.map(p => Fk(p[0], p[1])), o);
      const tema = S.bg ? S.bg : null;
      U([[0, 0], [1, 0], [1, 1], [0, 1]], { z: 1, fill: tema ? tema : 'none', o: 1, w: 1.5 });
      if (tema) out[out.length - 1][1] = out[out.length - 1][1].replace('fill="' + tema + '"', 'fill="' + tema + '" fill-opacity=".38"');
      // espessura (lado direito e topo)
      if (!flat) { const a = Fk(1, 0), b = Fk(1, 1), c = Fk(0, 1); path('peca', [a, [a[0] + th * .866 * SC, a[1] - th * .5 * SC], [b[0] + th * .866 * SC, b[1] - th * .5 * SC], b, a], { w: 1 }); path('peca', [b, [b[0] + th * .866 * SC, b[1] - th * .5 * SC], [c[0] + th * .866 * SC, c[1] - th * .5 * SC]], { w: 1 }); }
      if (t.browser && k === 0) { U([[0, .9], [1, .9]], { w: 1 }); [.04, .08, .12].forEach(u => U([[u, .95], [u + .005, .95]], { w: 2.4 })); U([[.3, .95], [.8, .95]], { w: 1, o: .6 }); }
      if (t.id === 'video' && k === 0) U([[.4, .4], [.4, .6], [.62, .5]], { z: 1, w: 1.2, o: .8 });
    });

    const iy0 = t.browser ? 0 : 0, ih = t.browser ? .88 : 1;      // área útil da tela
    const V = v => iy0 + v * ih;
    const R = (a, b, c, d, o) => uv('layout', [[a, V(b)], [c, V(b)], [c, V(d)], [a, V(d)]], Object.assign({ z: 1, w: 1.1 }, o));
    const X = (a, b, c, d) => { uv('layout', [[a, V(b)], [c, V(d)]], { w: .8, o: .5 }); uv('layout', [[c, V(b)], [a, V(d)]], { w: .8, o: .5 }); };
    const LN = (a, b, c, d, o) => uv('layout', [[a, V(b)], [c, V(d)]], Object.assign({ w: 1.1 }, o));
    const TXT = (u, v, wd, n, gap, o) => { for (let i = 0; i < n; i++) LN(u, v - i * gap, u + wd * (i === n - 1 && n > 1 ? .62 : 1), v - i * gap, o); };
    const CTA = (u, v, wd) => R(u, v, u + wd, v + .09, { c: accent, w: 1.6 });
    const ly = S.layout;
    if (ly === 'foto') { R(.06, .06, .94, .94); X(.06, .06, .94, .94); TXT(.12, .34, .62, 2, .08, { w: 2.2 }); LN(.12, .2, .46, .2, { c: accent, w: 2 }); }
    if (ly === 'tipo') { TXT(.1, .86, .78, 3, .13, { w: 2.6 }); LN(.1, .44, .34, .44, { c: accent, w: 2.4 }); TXT(.1, .34, .7, 3, .06, { w: 1, o: .7 }); CTA(.1, .08, .3); }
    if (ly === 'split') { R(.06, .06, .5, .94); X(.06, .06, .5, .94); TXT(.58, .78, .34, 3, .1, { w: 2.2 }); TXT(.58, .46, .32, 3, .06, { w: 1, o: .7 }); CTA(.58, .1, .3); }
    if (ly === 'grid') { [[.06, .5], [.52, .5], [.06, .06], [.52, .06]].forEach(p => { R(p[0], p[1], p[0] + .42, p[1] + .38); X(p[0], p[1], p[0] + .42, p[1] + .38); }); }
    if (ly === 'quote') { LN(.14, .8, .14, .64, { c: accent, w: 3 }); TXT(.22, .78, .64, 4, .1, { w: 1.8 }); LN(.22, .22, .5, .22, { w: 1, o: .7 }); }
    if (ly === 'numero') { R(.1, .42, .62, .88, { w: 2 }); LN(.16, .78, .56, .52, { c: accent, w: 2.4 }); TXT(.1, .3, .76, 2, .08, { w: 1.3 }); CTA(.1, .08, .28); }
    if (t.id === 'lp') { [1, 2].forEach(k => uv('peca', [[.1, 0 - k * .0], [.9, 0]].map(p => [p[0], p[1]]), { o: 0 })); }

    // assunto (sempre aparece na peça como linhas de título enquanto não há layout)
    if (!ly && (S.assunto || S.titulo)) { TXT(.1, .56, .74, 2, .1, { w: 2 }); }

    // cores e fontes: chips + amostra
    const base = flat ? P(x0 + w + .15, 0, 0) : P(x0 + w + .22, 0, 0);
    if (S.bg || S.acc) { [S.bg, S.acc].filter(Boolean).forEach((c, i) => out.push(['cores', `<circle cx="${(26 + i * 24)}" cy="238" r="9" fill="${c}" stroke="${L}" stroke-width="1.2" data-l="cores"/>`])); (sig.cores = sig.cores || []).push([S.bg, S.acc].join()); }
    if (S.fT) { out.push(['fonte', `<text x="294" y="246" text-anchor="end" font-family="'${esc(S.fT)}',serif" font-size="26" fill="${L}" data-l="fonte">Aa</text>`]); (sig.fonte = sig.fonte || []).push(S.fT); }
    const rot = [S.cli && S.cli.name, t.nome, (S.assunto || '').slice(0, 16)].filter(Boolean).join(' · ');
    out.push(['rot', `<text x="160" y="16" text-anchor="middle" font-size="9" letter-spacing=".04em" fill="${L}" opacity=".7" data-l="rot">${esc(rot.toUpperCase())}</text>`]); (sig.rot = sig.rot || []).push(rot);
    return finaliza(sig, out, BG, L);
  }
  function finaliza(sig, out, BG, L) {
    const body = out.map(([layer, s]) => {
      const nova = JSON.stringify(sig[layer]) !== DESENHADO[layer];
      return nova ? s.replace('data-l="' + layer + '"', 'data-l="' + layer + '" class="rsc-n"') : s;
    }).join('');
    Object.keys(sig).forEach(k => DESENHADO[k] = JSON.stringify(sig[k]));
    return `<svg viewBox="0 0 320 260" xmlns="http://www.w3.org/2000/svg" style="background:${BG}">${body}</svg>`;
  }
  function desenha() { const p = $('#rscSvg'); if (!p) return; p.innerHTML = desenhaSVG(); const c = $('#rscCap'); if (c) c.textContent = S.tipo ? (S.tipo.nome + (formatoDa(S.tipo) ? ' · ' + formatoDa(S.tipo) : '')) : 'o desenho nasce conforme você responde'; }

  // ----------------------------------------------------------- passos
  const chip = (txt, on, js, extra) => `<button type="button" class="rsc-c${on ? ' on' : ''}" onclick="${js}">${extra || ''}${esc(txt)}</button>`;
  const PASSOS = [
    { id: 'cli', q: 'Para qual cliente?', dica: 'Digite para filtrar.', ok: () => !!S.cli, render() {
      const ordem = clientes().map(c => ({ c, n: tarefasDe(c.id).length })).sort((a, b) => b.n - a.n).map(x => x.c);
      return `<input class="rsc-in" id="rscBusca" placeholder="Buscar cliente…" autocomplete="off" oninput="PDRsc.filtra(this.value)"><div class="rsc-ch" id="rscLista">${lista(ordem, '')}</div>`; } },
    { id: 'prod', q: 'Qual produto?', dica: 'O empreendimento ou linha deste cliente.', pula: () => !prodsDe(S.cli.id).length, ok: () => true, render() {
      return `<div class="rsc-ch">${prodsDe(S.cli.id).map(p => chip(p.name, S.prod === p.id, `PDRsc.set('prod','${p.id}')`)).join('')}${chip('Nenhum específico', !S.prod, `PDRsc.set('prod','')`)}</div>`; } },
    { id: 'tipo', q: 'O que vai ser feito?', dica: 'A peça aparece no rascunho.', ok: () => !!S.tipo, render() {
      const usados = {}; tarefasDe(S.cli.id).forEach(t => { const k = TIPOS.find(x => x.kw.some(w => norm(t.action).includes(w))); if (k) usados[k.id] = (usados[k.id] || 0) + 1; });
      const ord = TIPOS.slice().sort((a, b) => (usados[b.id] || 0) - (usados[a.id] || 0));
      return `<div class="rsc-ch">${ord.map((t, i) => chip(t.nome, S.tipo && S.tipo.id === t.id, `PDRsc.tipo('${t.id}')`, usados[t.id] && i < 2 ? '<em>mais usado</em> ' : '')).join('')}</div>`; } },
    { id: 'assunto', q: 'Sobre o quê?', dica: 'Escreva do seu jeito — o rascunho acompanha. As sugestões saem do histórico do cliente.', ok: () => !!S.assunto.trim(), render() {
      const loc = tarefasDe(S.cli.id).slice(0, 3).map(t => ({ assunto: t.action, titulo: '' }));
      queueMicrotask(sugere);
      return `<input class="rsc-in" id="rscAss" placeholder="Ex.: Lançamento da torre, últimas unidades…" value="${esc(S.assunto)}" oninput="PDRsc.ass(this.value)"><div class="rsc-sub">A IA propõe</div><div class="rsc-ch" id="rscSug">${sugHtml(SUG.length ? SUG : loc, !SUG.length)}</div>`; } },
    { id: 'layout', q: 'Qual o jeito da composição?', dica: 'Veja o traço mudar no rascunho.', ok: () => !!S.layout, render() { return `<div class="rsc-ch">${LAYOUTS.map(l => chip(l.nome, S.layout === l.id, `PDRsc.set('layout','${l.id}')`)).join('')}</div>`; } },
    { id: 'cores', q: 'Quais cores?', dica: '', ok: () => !!S.bg, render() {
      const k = cores(), lista = k.length ? k : NEUTRAS;
      const sw = (c, campo) => `<button type="button" class="rsc-sw${S[campo] === c.hex ? ' on' : ''}" style="background:${esc(c.hex)}" title="${esc(c.name || c.hex)}" onclick="PDRsc.set('${campo}','${esc(c.hex)}')"></button>`;
      return `${k.length ? '' : '<div class="rsc-sub">Este cliente ainda não tem kit de marca — paleta neutra de sugestão. Cadastre o kit para vir com as cores reais.</div>'}
        <div class="rsc-sub">Fundo</div><div class="rsc-ch">${lista.map(c => sw(c, 'bg')).join('')}</div>
        <div class="rsc-sub">Destaque</div><div class="rsc-ch">${lista.map(c => sw(c, 'acc')).join('')}</div>`; } },
    { id: 'fonte', q: 'Qual fonte?', dica: '', pula: () => !fontes().length, ok: () => true, render() {
      const f = fontes();
      return `<div class="rsc-sub">Títulos</div><div class="rsc-ch">${f.map(x => `<button type="button" class="rsc-c${S.fT === x.name ? ' on' : ''}" style="font-family:'${esc(x.name)}'" onclick="PDRsc.set('fT','${esc(x.name)}')">${esc(x.name)}</button>`).join('')}</div>
        <div class="rsc-sub">Texto</div><div class="rsc-ch">${f.map(x => `<button type="button" class="rsc-c${S.fC === x.name ? ' on' : ''}" style="font-family:'${esc(x.name)}'" onclick="PDRsc.set('fC','${esc(x.name)}')">${esc(x.name)}</button>`).join('')}</div>`; } },
    { id: 'prazo', q: 'Prazo, responsável e projeto', dica: 'Sugeridos pelo que o cliente costuma ter.', ok: () => !!S.resp, render() {
      const pz = [['Amanhã', 1], ['2 dias úteis', 2], ['3 dias úteis', 3], ['5 dias úteis', 5], ['Sem data', 0]];
      const us = respUsual(S.cli.id), pj = projetosDe(S.cli.id);
      return `<div class="rsc-sub">Entrega ao cliente</div><div class="rsc-ch">${pz.map(([n, d]) => chip(n, S.prazo === d, `PDRsc.set('prazo',${d})`)).join('')}</div>
        <div class="rsc-sub">Quem faz</div><div class="rsc-ch">${['SERGIO', 'JOY', 'LUANA', 'JUAN'].map(r => chip(((window.RESP_CFG && RESP_CFG[r] && RESP_CFG[r].label) || r), S.resp === r, `PDRsc.set('resp','${r}')`, r === us ? '<em>costuma ser</em> ' : '')).join('')}</div>
        <div class="rsc-sub">Projeto / job</div><div class="rsc-ch">${pj.map(p => chip(p, S.projeto === p, `PDRsc.set('projeto','${esc(p).replace(/'/g, '&#39;')}')`)).join('')}${chip('GERAL', S.projeto === 'GERAL', `PDRsc.set('projeto','GERAL')`)}</div>`; } },
    { id: 'rev', q: 'Tudo certo?', dica: 'Confira o rascunho e crie.', ok: () => true, render() {
      const l = (a, b) => b ? `<div class="rsc-rv"><span>${a}</span><b>${esc(b)}</b></div>` : '';
      return l('Cliente', S.cli.name) + l('Peça', S.tipo.nome + ' · ' + formatoDa(S.tipo)) + l('Assunto', S.assunto) + l('Composição', (LAYOUTS.find(x => x.id === S.layout) || {}).nome) + l('Cores', [S.bg, S.acc].filter(Boolean).join(' · ')) + l('Fontes', [S.fT, S.fC].filter(Boolean).join(' / ')) +
        l('Prazo', S.prazo ? iso(diasUteis(S.prazo)).split('-').reverse().join('/') : 'sem data') + l('Responsável', S.resp) + l('Projeto', S.projeto || 'GERAL'); } }
  ];
  function lista(arr, q) { const n = norm(q); return arr.filter(c => !n || norm(c.name).includes(n)).slice(0, 14).map(c => chip(c.name, S.cli && S.cli.id === c.id, `PDRsc.cli('${c.id}')`)).join('') || '<span class="rsc-sub">Nenhum cliente com esse nome.</span>'; }
  function sugHtml(arr, local) { return arr.map((s, i) => `<button type="button" class="rsc-c" onclick="PDRsc.sug(${i},${local ? 1 : 0})"><span>${esc(s.assunto)}</span>${s.titulo ? `<small>${esc(s.titulo)}</small>` : ''}</button>`).join('') + (SUGLOADING ? '<span class="rsc-sub">pensando…</span>' : ''); }
  async function sugere() {
    if (SUG.length || SUGLOADING || !S.cli) return;
    SUGLOADING = true; const marca = S.cli.id;
    try {
      const { data: { session } } = await sb.auth.getSession();
      const r = await fetch('/pd-rascunho.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session.access_token }, body: JSON.stringify({ client_id: marca, tipo: S.tipo ? S.tipo.nome : '' }) });
      const j = await r.json(); if (r.ok && j.sugestoes && S.cli && S.cli.id === marca) SUG = j.sugestoes;
    } catch (e) {}
    SUGLOADING = false; const el = $('#rscSug'); if (el && PASSOS[PASSO].id === 'assunto') el.innerHTML = sugHtml(SUG.length ? SUG : tarefasDe(S.cli.id).slice(0, 3).map(t => ({ assunto: t.action, titulo: '' })), !SUG.length);
  }

  // ----------------------------------------------------------- interface
  const CSS = `#rsc{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px}#rsc.open{display:flex}
  #rsc .rsc-box{position:relative;width:min(900px,100%);max-height:92vh;background:var(--surface,#fff);color:var(--text,#111);border:1px solid var(--border,#ddd);border-radius:18px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.45)}
  .rsc-top{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border,#ddd)}.rsc-top b{font-size:15px}.rsc-top .sp{flex:1}.rsc-top button{background:none;border:0;color:var(--text3,#777);font:inherit;font-size:13px;cursor:pointer;padding:6px 8px}
  .rsc-bar{height:3px;background:var(--surface2,#eee)}.rsc-bar i{display:block;height:100%;width:0;background:var(--accent,#000);transition:width .3s}
  .rsc-corpo{display:flex;gap:18px;padding:20px 18px;overflow:auto;flex:1;min-height:0}
  .rsc-q{flex:1;min-width:0}.rsc-q h2{margin:0 0 4px;font-size:24px;font-weight:600;letter-spacing:-.02em;line-height:1.15}.rsc-dica{color:var(--text3,#777);font-size:13px;margin-bottom:16px}
  .rsc-in{width:100%;font:inherit;font-size:16px;padding:12px 14px;border-radius:12px;border:1px solid var(--border,#ddd);background:var(--bg,#fff);color:inherit;margin-bottom:12px}
  .rsc-ch{display:flex;flex-wrap:wrap;gap:8px}.rsc-c{font:inherit;font-size:14px;padding:10px 14px;border-radius:999px;border:1px solid var(--border,#ddd);background:var(--surface2,#f4f4f4);color:inherit;cursor:pointer;text-align:left}
  .rsc-c small{display:block;color:var(--text3,#777);font-size:12px}.rsc-c em{font-style:normal;font-size:10.5px;color:var(--text3,#777);margin-right:4px;text-transform:uppercase;letter-spacing:.04em}
  .rsc-c.on{background:var(--accent,#000);color:var(--accent-fg,#fff);border-color:var(--accent,#000)}.rsc-c.on small,.rsc-c.on em{color:inherit;opacity:.7}
  .rsc-sub{font-size:12px;color:var(--text3,#777);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.05em}.rsc-sub:first-child{margin-top:0}
  .rsc-sw{width:38px;height:38px;border-radius:50%;border:2px solid var(--border,#ccc);cursor:pointer}.rsc-sw.on{outline:3px solid var(--accent,#000);outline-offset:2px}
  .rsc-rv{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid var(--border,#eee);font-size:14px}.rsc-rv span{color:var(--text3,#777)}.rsc-rv b{font-weight:500;text-align:right}
  .rsc-nav{display:flex;gap:10px;padding:14px 18px;border-top:1px solid var(--border,#ddd);align-items:center}.rsc-nav .sp{flex:1}
  .rsc-b{font:inherit;font-size:14px;font-weight:500;padding:12px 22px;border-radius:999px;border:1px solid var(--border,#ddd);background:transparent;color:inherit;cursor:pointer}.rsc-b.p{background:var(--accent,#000);color:var(--accent-fg,#fff);border-color:var(--accent,#000)}.rsc-b:disabled{opacity:.35}
  .rsc-pad{flex:none;width:300px;align-self:flex-start;position:sticky;top:0;border:1px solid var(--border,#ddd);border-radius:14px;overflow:hidden;background:#0b0c0b}
  .rsc-pad.g{width:min(520px,100%)}
  .rsc-ph{display:flex;align-items:center;gap:6px;padding:7px 10px;background:var(--surface2,#f4f4f4);font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text3,#777)}.rsc-ph span{flex:1}.rsc-ph button{background:none;border:0;color:inherit;cursor:pointer;font-size:14px;padding:2px 6px}
  #rscSvg svg{display:block;width:100%;height:auto}#rscCap{padding:7px 10px;font-size:11.5px;color:var(--text3,#777);background:var(--surface2,#f4f4f4);border-top:1px solid var(--border,#ddd)}
  .rsc-n{stroke-dasharray:1;stroke-dashoffset:1;animation:rscd .9s ease forwards}text.rsc-n,circle.rsc-n{stroke-dasharray:none;stroke-dashoffset:0;animation:rscf .7s ease both}
  @keyframes rscd{to{stroke-dashoffset:0}}@keyframes rscf{from{opacity:0}to{opacity:1}}
  @media(max-width:720px){#rsc{padding:0;align-items:stretch}#rsc .rsc-box{max-height:none;height:100%;border-radius:0}.rsc-corpo{flex-direction:column-reverse;gap:14px;padding:12px 14px}.rsc-pad,.rsc-pad.g{width:100%;position:static}#rscSvg svg{max-height:34vh}.rsc-q h2{font-size:21px}}`;

  function monta() {
    if ($('#rsc')) return;
    const st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
    const d = document.createElement('div'); d.id = 'rsc';
    d.innerHTML = `<div class="rsc-box"><div class="rsc-top"><b>Nova tarefa</b><span class="sp"></span><button onclick="PDRsc.classico()">Formulário</button><button onclick="PDRsc.fecha()" aria-label="Fechar">✕</button></div>
      <div class="rsc-bar"><i id="rscProg"></i></div>
      <div class="rsc-corpo"><div class="rsc-q"><h2 id="rscQ"></h2><div class="rsc-dica" id="rscD"></div><div id="rscR"></div></div>
        <div class="rsc-pad" id="rscPad"><div class="rsc-ph"><span>Rascunho</span><button onclick="PDRsc.inverte()" title="Inverter preto/branco">◐</button><button onclick="PDRsc.grande()" title="Ampliar">⤢</button></div><div id="rscSvg"></div><div id="rscCap"></div></div></div>
      <div class="rsc-nav"><button class="rsc-b" id="rscV" onclick="PDRsc.volta()">Voltar</button><span class="sp"></span><button class="rsc-b" id="rscP" onclick="PDRsc.pula()">Pular</button><button class="rsc-b p" id="rscN" onclick="PDRsc.prox()">Continuar</button></div></div>`;
    d.addEventListener('click', e => { if (e.target === d) fecha(); });
    document.body.appendChild(d);
  }
  function mostra() {
    const p = PASSOS[PASSO];
    $('#rscQ').textContent = p.q; $('#rscD').textContent = p.dica || ''; $('#rscR').innerHTML = p.render();
    $('#rscProg').style.width = ((PASSO + 1) / PASSOS.length * 100) + '%';
    $('#rscV').style.visibility = PASSO ? 'visible' : 'hidden';
    const fim = p.id === 'rev'; $('#rscN').textContent = fim ? 'Criar tarefa' : 'Continuar';
    $('#rscP').style.display = (!fim && p.pula === undefined && ['prod', 'fonte'].includes(p.id)) ? '' : 'none';
    atualizaBotao(); desenha();
    const i = $('#rscBusca,#rscAss'); if (i && window.innerWidth > 720) i.focus();
  }
  function atualizaBotao() { const b = $('#rscN'); if (b) b.disabled = !PASSOS[PASSO].ok(); }
  function proximoIdx(d) { let i = PASSO + d; while (PASSOS[i] && PASSOS[i].pula && PASSOS[i].pula()) i += d; return i; }
  function refaz() { const p = PASSOS[PASSO]; if (['cores', 'fonte', 'prazo', 'layout', 'tipo', 'prod'].includes(p.id)) $('#rscR').innerHTML = p.render(); atualizaBotao(); desenha(); }

  function abre() {
    monta(); S = novoEstado(); PASSO = 0; KIT = null; SUG = []; DESENHADO = {};
    // se a tela já estava filtrada num cliente, a tarefa nasce com ele
    const cf = $('#t-client') && $('#t-client').value; if (cf) { const c = clientes().find(x => x.id === cf); if (c) { S.cli = c; carregaKit(c.id); S.resp = respUsual(c.id); } }
    $('#rsc').classList.add('open'); if (S.cli) PASSO = 1; if (S.cli && PASSOS[1].pula()) PASSO = 2; mostra();
  }
  function fecha() { const d = $('#rsc'); if (d) d.classList.remove('open'); }
  function preenche() {
    const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v; };
    set('t-client', S.cli.id); try { onTaskClientChange(S.cli.id, S.prod || null); } catch (e) {}
    if (S.prod) set('t-product', S.prod);
    set('t-action', (S.tipo.nome + ' — ' + S.assunto.trim()).slice(0, 140));
    set('t-project', S.projeto || 'GERAL');
    set('t-resp', S.resp || 'SERGIO');
    const lay = (LAYOUTS.find(x => x.id === S.layout) || {}).nome;
    set('t-obs', ['Peça: ' + S.tipo.nome + (formatoDa(S.tipo) ? ' (' + formatoDa(S.tipo) + ')' : ''), 'Assunto: ' + S.assunto.trim(), S.titulo ? 'Título sugerido: ' + S.titulo : '', lay ? 'Composição: ' + lay : '',
      (S.bg || S.acc) ? 'Cores: fundo ' + (S.bg || '—') + ' · destaque ' + (S.acc || '—') : '', (S.fT || S.fC) ? 'Fontes: títulos ' + (S.fT || '—') + ' · texto ' + (S.fC || '—') : '', '', '(montado no Rascunho guiado)'].filter(x => x !== '' || true).join('\n').replace(/\n{3,}/g, '\n\n'));
    if (S.prazo) { const d = diasUteis(S.prazo), i = diasUteis(Math.max(S.prazo - 1, 0)); set('t-date-cli', iso(d)); set('t-date-int', S.prazo > 1 ? iso(i) : iso(d)); set('t-week', S.prazo <= 5 ? 'ESTA SEMANA' : 'PRÓXIMA SEMANA'); }
    else { set('t-date-cli', ''); set('t-date-int', ''); set('t-week', 'SEM DATA'); }
    set('t-status', 'EM ANDAMENTO');
    try { const cat = (window.CATALOGO_SERVICOS || []).find(c => S.tipo.kw.some(k => norm(c.tipo_tarefa).includes(k)) && (!S.cli || true)); if (cat && typeof escolherCatalogo === 'function') escolherCatalogo(cat.task_id); } catch (e) {}
  }

  const API = window.PDRsc = {
    abrir: abre, fecha,
    filtra(q) { const ordem = clientes().map(c => ({ c, n: tarefasDe(c.id).length })).sort((a, b) => b.n - a.n).map(x => x.c); $('#rscLista').innerHTML = lista(ordem, q); },
    cli(id) { S.cli = clientes().find(c => c.id === id); S.prod = ''; SUG = []; S.bg = S.acc = null; S.fT = S.fC = ''; S.resp = respUsual(id); S.projeto = projetosDe(id)[0] || ''; carregaKit(id); API.prox(); },
    set(k, v) { S[k] = v; if (k === 'prod') { refaz(); } else refaz(); },
    tipo(id) { S.tipo = TIPOS.find(t => t.id === id); SUG = []; refaz(); },
    ass(v) { S.assunto = v; atualizaBotao(); desenha(); },
    sug(i, loc) { const s = (SUG.length && !loc ? SUG : tarefasDe(S.cli.id).slice(0, 3).map(t => ({ assunto: t.action, titulo: '' })))[i]; if (!s) return; S.assunto = s.assunto; S.titulo = s.titulo || ''; const e = $('#rscAss'); if (e) e.value = S.assunto; atualizaBotao(); desenha(); },
    inverte() { S.inv = !S.inv; DESENHADO = {}; desenha(); },
    grande() { S.grande = !S.grande; $('#rscPad').classList.toggle('g', S.grande); },
    volta() { const i = proximoIdx(-1); if (i >= 0) { PASSO = i; mostra(); } },
    pula() { API.prox(); },
    async prox() {
      const p = PASSOS[PASSO]; if (!p.ok()) return;
      if (p.id === 'rev') {
        preenche(); window.__rscCriando = true; const b = $('#rscN'); b.disabled = true; b.textContent = 'Criando…';
        try { await saveTask(); } catch (e) { showToast('Não consegui criar: ' + e.message, 'error'); }
        window.__rscCriando = false; b.disabled = false; b.textContent = 'Criar tarefa'; return;
      }
      const i = proximoIdx(1); PASSO = Math.min(i, PASSOS.length - 1);
      if (p.id === 'cores' && !S.bg) { const c = cores()[0] || NEUTRAS[0]; S.bg = c.hex; }
      mostra();
    },
    classico() { pref.set('form'); fecha(); window.__rscQuer = false; window.__rscClassico = true; try { openNewTaskModal(); } finally { window.__rscClassico = false; } }
  };

  // ----------------------------------------------------------- ganchos no app existente
  function instala() {
    if (window.__rscOk || typeof window.openNewTaskModal !== 'function' || typeof window.openModal !== 'function') return;
    window.__rscOk = true;
    const om = window.openModal, cm = window.closeModal, nt = window.openNewTaskModal;
    window.openModal = function (id) { if (id === 'modal-task' && window.__rscQuer) { window.__rscQuer = false; return; } return om.apply(this, arguments); };
    window.closeModal = function (id) { if (id === 'modal-task' && window.__rscCriando) { fecha(); } return cm.apply(this, arguments); };
    window.openNewTaskModal = function () {
      const isCliente = window.profile && window.profile.role === 'client';
      const guiado = !window.__rscClassico && !isCliente && pref.get() !== 'form';
      if (guiado) window.__rscQuer = true;
      nt.apply(this, arguments);
      window.__rscQuer = false;
      if (guiado) abre();
    };
    // volta ao guiado: o botão "Guiado" no formulário clássico
    const cab = document.querySelector('#modal-task .modal-head');
    if (cab && !$('#rscVoltaGuiado')) { const b = document.createElement('button'); b.id = 'rscVoltaGuiado'; b.type = 'button'; b.textContent = '✦ Guiado'; b.className = 'btn btn-secondary btn-sm'; b.style.cssText = 'margin-left:auto;margin-right:8px';
      b.onclick = () => { pref.set('guiado'); closeModal('modal-task'); setTimeout(() => window.openNewTaskModal(), 50); }; cab.insertBefore(b, cab.querySelector('.modal-close')); }
  }
  window.addEventListener('load', () => { instala(); setTimeout(instala, 1500); setTimeout(instala, 4000); });
})();
