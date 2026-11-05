// =====================================================================
// Pontodot — CRIAR TAREFA PELO CHAT (a IA da pessoa pergunta, a tarefa se monta)
//
//   "+" ao lado do chat → Criar tarefa. A IA parceira pergunta um campo por
//   vez; tudo que já existe na casa (clientes, produtos, projetos, pessoas)
//   vira autopreenchimento: a pessoa escreve duas letras e aparece o chip.
//   A tarefa vai se montando como um card limpo dentro da conversa; no fim,
//   "Criar" grava no banco e oferece subir material para aprovação.
//   Usa os mesmos globais do index.html (THREADS, render, clientesReais…).
// =====================================================================
const NT = { ativa:false, passo:null, d:{}, verso:false };
const NT_PASSOS = ['acao', 'cliente', 'produto', 'projeto', 'resp', 'date_int', 'date_cli', 'links', 'obs', 'revisar'];

function ntNomeIA(){ try { return (typeof nomeDaIA === 'function' ? nomeDaIA() : '') || 'sua IA'; } catch(e){ return 'sua IA'; } }
function ntThread(){ return THREADS[atual] = THREADS[atual] || []; }
function ntFala(t, acoes){ ntThread().push({ t, h: hAgora(), acoes: acoes && acoes.length ? acoes : undefined }); render(); ntRolar(); }
function ntEu(t){ ntThread().push({ eu:true, t: esc(t).replace(/\n/g, '<br>'), h: hAgora() }); }
function ntRolar(){ requestAnimationFrame(() => { ['#vcRolo', '#rolo', '.rolo'].forEach(s => { const r = document.querySelector(s); if (r) r.scrollTop = r.scrollHeight; }); }); }
function ntSemAcento(v){ return String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); }

// ---- começa
function ntIniciar(){
  NT.ativa = true; NT.d = { links:[] }; NT.passo = 'acao'; NT.verso = typeof noVersoAgora === 'function' && noVersoAgora();
  if (!NT.verso && !chat) modoChat(true);
  if (atual !== 'sd') { atual = 'sd'; }
  ntFala(`Vamos criar uma tarefa. <b>O que é?</b> Uma frase basta, eu pergunto o resto.`, [['Cancelar', '', 'ntCancelar()']]);
  setTimeout(() => { const c = document.querySelector(NT.verso ? '#campoVerso' : '#campo'); if (c){ c.placeholder = 'Ex.: Criativos do lançamento Reserva, 3 posts e 2 stories'; c.focus(); } }, 80);
}
function ntCancelar(){ NT.ativa = false; NT.passo = null; const c = document.querySelector('#campo'); if (c) c.placeholder = 'Fale comigo...'; ntFala('Ok, deixei pra lá. Quando quiser, é só clicar no + de novo.'); }

// ---- o texto que a pessoa mandou cai aqui (chamado por enviar())
function ntResponder(txt){
  ntEu(txt); const t = txt.trim(), tl = ntSemAcento(t);
  if (/^(cancela|cancelar|deixa|esquece|para)$/.test(tl)) return ntCancelar();
  const pula = /^(pula|pular|nenhum|nenhuma|nao|não|n|-|sem|depois|skip)$/.test(tl);
  switch (NT.passo) {
    case 'acao': if (t.length < 3) return ntFala('Me dá pelo menos uma frase curta do que é a tarefa.'); NT.d.action = t.slice(0, 160); return ntPergunta('cliente');
    case 'cliente': { if (pula){ NT.d.client = null; return ntPergunta('projeto'); }
      const m = ntAchaCliente(t); if (!m.length) return ntFala(`Não achei cliente com "${esc(t)}". Tenta outro nome, ou escolhe:`, ntChipsClientes(''));
      if (m.length > 1 && ntSemAcento(m[0].name) !== tl) return ntFala('Qual deles?', m.slice(0, 6).map(c => [c.name, '', `ntEscolhe('cliente','${c.id}')`]));
      return ntEscolhe('cliente', m[0].id); }
    case 'produto': { if (pula){ NT.d.product = null; return ntPergunta('projeto'); }
      const ps = ntProdutos(NT.d.client && NT.d.client.id), m = ps.filter(p => ntSemAcento(p.name).includes(tl));
      if (!m.length) return ntFala('Não achei esse produto. Escolhe um, ou pula:', ntChipsProdutos());
      return ntEscolhe('produto', m[0].id); }
    case 'projeto': NT.d.project = pula ? 'GERAL' : t.toUpperCase().slice(0, 40); return ntPergunta('resp');
    case 'resp': { if (pula){ NT.d.resp = chaveDe(perfil); return ntPergunta('date_int'); }
      const m = (EQUIPE || []).filter(k => ntSemAcento(k).startsWith(tl) || ntSemAcento(k).includes(tl));
      if (!m.length) return ntFala('Não achei essa pessoa. Escolhe:', ntChipsEquipe());
      return ntEscolhe('resp', m[0]); }
    case 'date_int': { if (pula){ NT.d.date_int = null; return ntPergunta('date_cli'); } const d = ntData(t); if (!d) return ntFala('Não entendi a data. Pode ser "amanhã", "sexta", "15/11" ou "em 3 dias".'); NT.d.date_int = d; return ntPergunta('date_cli'); }
    case 'date_cli': { if (pula){ NT.d.date_cli = null; return ntPergunta('links'); } const d = ntData(t); if (!d) return ntFala('Não entendi a data. "amanhã", "sexta", "15/11"…'); NT.d.date_cli = d; return ntPergunta('links'); }
    case 'links': { if (!pula){ const ls = t.match(/https?:\/\/\S+/g) || []; if (ls.length) NT.d.links = NT.d.links.concat(ls); else NT.d.obs_extra = (NT.d.obs_extra ? NT.d.obs_extra + '\n' : '') + t; } return ntPergunta('obs'); }
    case 'obs': if (!pula) NT.d.obs = t; return ntPergunta('revisar');
    case 'revisar': { if (/^(cria|criar|ok|vai|sim|confirma|manda|pode)$/.test(tl)) return ntCriar();
      NT.d.obs = (NT.d.obs ? NT.d.obs + '\n' : '') + t; return ntPergunta('revisar'); }
  }
}
function ntPergunta(p){
  NT.passo = p; const c = document.querySelector(NT.verso ? '#campoVerso' : '#campo'); const ph = s => { if (c) c.placeholder = s; };
  const pular = ['Pular', '', 'ntPular()'];
  switch (p) {
    case 'cliente': ph('Nome do cliente…'); return ntFala('<b>De qual cliente?</b>', ntChipsClientes('').concat([['Sem cliente (SD)', '', "ntEscolhe('cliente',null)"]]));
    case 'produto': { const ps = ntProdutos(NT.d.client && NT.d.client.id); if (!ps.length){ NT.d.product = null; return ntPergunta('projeto'); } ph('Produto…'); return ntFala('<b>De qual produto?</b>', ntChipsProdutos().concat([['Cliente inteiro', '', "ntEscolhe('produto',null)"]])); }
    case 'projeto': { ph('Projeto (ex.: LANÇAMENTO, SOCIAL)…'); const recentes = ntProjetos(NT.d.client && NT.d.client.id); return ntFala('<b>Em qual projeto?</b>', recentes.map(x => [x, '', `ntEscolhe('projeto','${esc(x)}')`]).concat([['GERAL', '', "ntEscolhe('projeto','GERAL')"]])); }
    case 'resp': ph('Quem cuida…'); return ntFala('<b>Quem cuida?</b>', ntChipsEquipe());
    case 'date_int': ph('amanhã · sexta · 15/11 · em 3 dias'); return ntFala('<b>Entrega interna</b> (quando a equipe precisa ter pronto)?', [['Amanhã', '', "ntEscolhe('date_int','amanhã')"], ['Sexta', '', "ntEscolhe('date_int','sexta')"], ['Em 1 semana', '', "ntEscolhe('date_int','em 7 dias')"], pular]);
    case 'date_cli': ph('amanhã · sexta · 15/11'); return ntFala('<b>Data para o cliente</b> (quando ele recebe)?', [['Mesmo dia', '', "ntEscolhe('date_cli','igual')"], ['+2 dias', '', "ntEscolhe('date_cli','+2')"], pular]);
    case 'links': ph('Cola links, ou escreve "pula"'); return ntFala('<b>Tem arquivo ou link?</b> Cola aqui os links. Arquivos você sobe na tarefa, logo depois de criar.', [pular]);
    case 'obs': ph('Detalhes, briefing, o que não pode faltar…'); return ntFala('<b>Mais detalhes?</b> Briefing, referências, o que não pode faltar. Ou pula.', [pular]);
    case 'revisar': ph('"criar", ou escreva mais algum detalhe'); return ntFala(ntCardHtml(ntPreview()) + '<div class="nota" style="margin-top:6px">Está certo? Escreva mais detalhes se quiser; eles entram na tarefa.</div>', [['Criar tarefa', 'verde', 'ntCriar()'], ['Trocar cliente', '', "ntPergunta('cliente')"], ['Trocar responsável', '', "ntPergunta('resp')"], ['Cancelar', '', 'ntCancelar()']]);
  }
}
function ntPular(){ ntEu('pula'); const i = NT_PASSOS.indexOf(NT.passo); if (NT.passo === 'cliente') NT.d.client = null; ntPergunta(NT_PASSOS[Math.min(i + 1, NT_PASSOS.length - 1)]); }
function ntEscolhe(campo, v){
  if (campo === 'cliente'){ NT.d.client = v ? clientesReais.find(c => c.id === v) || null : null; ntEu(NT.d.client ? NT.d.client.name : 'Sem cliente'); return ntPergunta('produto'); }
  if (campo === 'produto'){ const ps = ntProdutos(NT.d.client && NT.d.client.id); NT.d.product = v ? ps.find(p => p.id === v) || null : null; ntEu(NT.d.product ? NT.d.product.name : 'Cliente inteiro'); return ntPergunta('projeto'); }
  if (campo === 'projeto'){ NT.d.project = v; ntEu(v); return ntPergunta('resp'); }
  if (campo === 'resp'){ NT.d.resp = v; ntEu(v); return ntPergunta('date_int'); }
  if (campo === 'date_int'){ NT.d.date_int = ntData(v); ntEu(fmtD(NT.d.date_int)); return ntPergunta('date_cli'); }
  if (campo === 'date_cli'){ NT.d.date_cli = v === 'igual' ? NT.d.date_int : (v === '+2' ? ntMaisDias(NT.d.date_int, 2) : ntData(v)); ntEu(fmtD(NT.d.date_cli)); return ntPergunta('links'); }
}

// ---- autopreenchimento
function ntAchaCliente(q){ const ql = ntSemAcento(q); return (clientesReais || []).filter(c => ntSemAcento(c.name).includes(ql)).sort((a, b) => ntSemAcento(a.name).startsWith(ql) ? -1 : 1); }
function ntClientesRecentes(){ const vistos = {}; (tasksReais || []).slice().sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')).forEach(t => { if (t.client_id && !vistos[t.client_id]) vistos[t.client_id] = 1; });
  const ids = Object.keys(vistos).slice(0, 6); const r = ids.map(id => (clientesReais || []).find(c => c.id === id)).filter(Boolean); return r.length ? r : (clientesReais || []).slice(0, 6); }
function ntChipsClientes(q){ const l = q ? ntAchaCliente(q).slice(0, 8) : ntClientesRecentes(); return l.map(c => [c.name, '', `ntEscolhe('cliente','${c.id}')`]); }
function ntProdutos(cid){ if (!cid) return []; const base = (window.ADS && ADS.prods) || (window.SOC && SOC.prods) || window.PRODUTOS || []; return base.filter(p => p.client_id === cid); }
function ntChipsProdutos(){ return ntProdutos(NT.d.client && NT.d.client.id).slice(0, 8).map(p => [p.name, '', `ntEscolhe('produto','${p.id}')`]); }
function ntProjetos(cid){ const c = {}; (tasksReais || []).forEach(t => { if ((!cid || t.client_id === cid) && t.project) c[t.project] = (c[t.project] || 0) + 1; }); return Object.entries(c).sort((a, b) => b[1] - a[1]).map(x => x[0]).filter(x => x !== 'GERAL').slice(0, 6); }
function ntChipsEquipe(){ return (EQUIPE || []).slice(0, 10).map(k => { const p = (PERFIS || []).find(x => chaveDe(x) === k); return [p ? p.name.split(' ')[0] : k, '', `ntEscolhe('resp','${k}')`]; }); }
function ntData(t){
  const tl = ntSemAcento(t); const hoje = new Date(); hoje.setHours(12, 0, 0, 0); const iso = d => d.toISOString().slice(0, 10);
  if (/^(hoje|agora)$/.test(tl)) return iso(hoje); if (/^amanha$/.test(tl)) return ntMaisDias(iso(hoje), 1); if (/^depois de amanha$/.test(tl)) return ntMaisDias(iso(hoje), 2);
  let m = tl.match(/^em (\d+) dias?$/) || tl.match(/^(\d+) dias?$/); if (m) return ntMaisDias(iso(hoje), +m[1]);
  m = tl.match(/^em (\d+) semanas?$/); if (m) return ntMaisDias(iso(hoje), 7 * +m[1]);
  const dias = { domingo:0, segunda:1, terca:2, quarta:3, quinta:4, sexta:5, sabado:6, dom:0, seg:1, ter:2, qua:3, qui:4, sex:5, sab:6 };
  m = tl.match(/^(proxima |proximo )?(domingo|segunda|terca|quarta|quinta|sexta|sabado|dom|seg|ter|qua|qui|sex|sab)(-feira)?$/); if (m){ const alvo = dias[m[2]]; let n = (alvo - hoje.getDay() + 7) % 7; if (n === 0) n = 7; return ntMaisDias(iso(hoje), n); }
  m = t.match(/^(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{2,4}))?$/); if (m){ const a = m[3] ? (m[3].length === 2 ? 2000 + +m[3] : +m[3]) : hoje.getFullYear(); const d = new Date(a, +m[2] - 1, +m[1], 12); if (!m[3] && d < hoje) d.setFullYear(a + 1); return iso(d); }
  m = t.match(/^(\d{4})-(\d{2})-(\d{2})$/); if (m) return t;
  return null;
}
function ntMaisDias(isoBase, n){ const d = new Date((isoBase || new Date().toISOString().slice(0, 10)) + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }

// ---- o card limpo (o mesmo desenho que a tarefa aberta usa)
function ntPreview(){ const d = NT.d; return { id:'nova', action:d.action, clients: d.client ? { name:d.client.name } : null, products: d.product ? { name:d.product.name } : null, project:d.project || 'GERAL', resp:d.resp || chaveDe(perfil), status:'BRIEFING', date_int:d.date_int, date_cli:d.date_cli, obs:[d.obs, d.obs_extra, (d.links || []).join('\n')].filter(Boolean).join('\n') }; }
function ntCardHtml(t){
  const f = (r, v) => `<div class="ntc-f"><small>${r}</small><b>${v || '—'}</b></div>`;
  return `<div class="ntc"><div class="ntc-id">${esc(t.id === 'nova' ? 'TAREFA NOVA' : t.id)}</div><div class="ntc-t">${esc(t.action || '')}</div><div class="ntc-c">${esc((t.clients && t.clients.name) || 'SD')}${t.products && t.products.name ? ' · ' + esc(t.products.name) : ''}</div>
    <div class="ntc-g">${f('Projeto', esc(t.project))}${f('Responsável', esc(t.resp))}${f('Status', esc(typeof rotStatus === 'function' ? rotStatus(t.status) : t.status))}${f('Entrega interna', fmtD(t.date_int))}${f('Para o cliente', fmtD(t.date_cli))}</div>
    ${t.obs ? `<div class="ntc-o">${esc(t.obs).replace(/(https?:\/\/\S+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>')}</div>` : ''}</div>`;
}

// ---- grava
async function ntCriar(){
  if (!NT.ativa) return; const d = NT.d; if (!d.action) return ntPergunta('acao');
  ntFala('Criando…');
  try {
    const { data: nid, error: eid } = await sbR.rpc('next_task_id'); if (eid || !nid) throw new Error(eid?.message || 'sem id novo');
    const nova = { id: nid, action: d.action.slice(0, 160), status: 'BRIEFING', resp: (d.resp || chaveDe(perfil) || 'SD').toUpperCase(), project: d.project || 'GERAL', week: 'ESTA SEMANA', created_by: perfil.id, obs: ntPreview().obs ? ntPreview().obs.slice(0, 4000) : null };
    if (d.client) nova.client_id = d.client.id; if (d.product) nova.product_id = d.product.id; if (d.date_int) nova.date_int = d.date_int; if (d.date_cli) nova.date_cli = d.date_cli;
    let { data, error } = await sbR.from('tasks').insert(nova).select('id,action').single();
    if (error && /product_id/i.test(error.message || '')){ delete nova.product_id; ({ data, error } = await sbR.from('tasks').insert(nova).select('id,action').single()); }
    if (error) throw error;
    NT.ativa = false; NT.passo = null; const c = document.querySelector('#campo'); if (c) c.placeholder = 'Fale comigo...';
    try { await carregarReal(); atualizarVersoApp(); } catch(e){}
    const t = (tasksReais || []).find(x => x.id === data.id) || { ...nova, clients: d.client ? { name: d.client.name } : null, products: d.product ? { name: d.product.name } : null };
    ntFala(`Criada: <b>${data.id}</b>. Já está no board.` + ntCardHtml(t), [['Abrir a tarefa', 'verde', `abrirTarefaReal('${data.id}')`], ['Subir material para aprovação', 'azul', `ntSubirAprovacao('${data.id}')`], ['Criar outra', '', 'ntIniciar()']]);
    if (nova.client_id && /copy|legenda|texto/i.test(nova.action) && typeof adsFala === 'function') adsFala({ acao:'executar_tarefa', task_id: data.id }, '/pd-copy.php').then(j => toast('Copy da ' + data.id + ' montada no Studio › Copys (' + (j.n || 0) + ' itens).')).catch(() => {});
  } catch(e){ ntFala('Não consegui criar: ' + esc(e.message), [['Tentar de novo', 'verde', 'ntCriar()'], ['Cancelar', '', 'ntCancelar()']]); }
}
function ntSubirAprovacao(id){ popupIframe('/novo-lote.html?embed=1&task=' + id, 'Subir material — ' + id); }
