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
const NT_PASSOS = ['acao', 'cliente', 'produto', 'resp', 'date_int', 'mae', 'filha', 'extra'];

function ntNomeIA(){ try { return (typeof nomeDaIA === 'function' ? nomeDaIA() : '') || 'sua IA'; } catch(e){ return 'sua IA'; } }
function ntThread(){ return THREADS[atual] = THREADS[atual] || []; }
function ntFala(t, acoes){ ntThread().push({ t, h: hAgora(), nt: true, acoes: acoes && acoes.length ? acoes : undefined }); render(); ntRolar(); }
function ntEu(t){ ntThread().forEach(m => { if (m.nt && m.acoes) delete m.acoes; }); ntThread().push({ eu:true, t: esc(t).replace(/\n/g, '<br>'), h: hAgora() }); }
function ntRolar(){ requestAnimationFrame(() => { ['#vcRolo', '#rolo', '.rolo'].forEach(s => { const r = document.querySelector(s); if (r) r.scrollTop = r.scrollHeight; }); }); }
function ntSemAcento(v){ return String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); }

// ---- começa: uma frase e a IA já tira o que dá dela; só pergunta o que falta, uma coisa por vez
function ntIniciar(mae){
  NT.ativa = true; NT.d = { links:[] }; if (mae && typeof mae === 'string'){ const m = (tasksReais || []).find(x => x.id === mae); if (m){ NT.d.mae = m; NT.d.client = (clientesReais || []).find(c => c.id === m.client_id) || undefined; } } NT.passo = 'acao'; NT.verso = typeof noVersoAgora === 'function' && noVersoAgora();
  if (!NT.verso && !chat) modoChat(true);
  if (atual !== 'sd') { atual = 'sd'; }
  ntFala(`O que vamos criar?`, [['Cancelar', '', 'ntCancelar()']]);
  setTimeout(() => { const c = document.querySelector(NT.verso ? '#campoVerso' : '#campo'); if (c){ c.placeholder = 'Ex.: 3 posts e 2 stories do Reserva pra sexta, Joy'; c.focus(); } }, 80);
}
function ntCancelar(){ NT.ativa = false; NT.passo = null; const c = document.querySelector('#campo'); if (c) c.placeholder = 'Fale comigo...'; ntFala('Ok, deixei pra lá.'); }

// tira da frase o que ela já diz: cliente, responsável, data
function ntInferir(t){
  const tl = ntSemAcento(t);
  const cs = (clientesReais || []).filter(c => c.active !== false).map(c => ({ c, n: ntSemAcento(c.name) })).filter(x => x.n.length >= 3 && (tl.includes(x.n) || tl.split(/\W+/).includes(x.n.split(/\s+/)[0]) && x.n.split(/\s+/)[0].length >= 4)).sort((a, b) => b.n.length - a.n.length);
  if (cs.length) NT.d.client = cs[0].c;
  const pe = (EQUIPE || []).find(k => { const p = (PERFIS || []).find(x => chaveDe(x) === k); const n = ntSemAcento(p ? p.name.split(' ')[0] : k); return n.length >= 3 && new RegExp('\\b(pra|para|com|resp|responsavel|de)\\s+(a |o )?' + n + '\\b|[,;]\\s*' + n + '\\s*$').test(tl); });
  if (pe) NT.d.resp = pe;
  const dm = tl.match(/\b(?:pra|para|ate|até|em|dia)\s+(amanha|hoje|depois de amanha|segunda|terca|quarta|quinta|sexta|sabado|domingo|\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|\d+ dias?)\b/);
  if (dm){ const d = ntData(/^\d+ dias?$/.test(dm[1]) ? 'em ' + dm[1] : dm[1]); if (d) NT.d.date_int = d; }
}
// próximo campo que ainda falta
function ntProximo(){
  const d = NT.d;
  if (d.client === undefined) return ntPergunta('cliente');
  if (d.client && d.product === undefined && ntProdutos(d.client.id).length) return ntPergunta('produto');
  if (!d.resp) return ntPergunta('resp');
  if (d.date_int === undefined) return ntPergunta('date_int');
  if (d.mae === undefined) return ntPergunta('mae');
  if (d.filha === undefined) return ntPergunta('filha');
  return ntPergunta('extra');
}
function ntResumo(){ const d = NT.d; return [d.client ? d.client.name : 'SD', d.product && d.product.name, d.resp, d.date_int && fmtD(d.date_int), d.mae && ('filha de ' + d.mae.id)].filter(Boolean).join(' · '); }

// ---- o texto que a pessoa mandou cai aqui (chamado por enviar())
function ntResponder(txt){
  ntEu(txt); const t = txt.trim(), tl = ntSemAcento(t);
  if (/^(cancela|cancelar|deixa|esquece|para)$/.test(tl)) return ntCancelar();
  const pula = /^(pula|pular|nenhum|nenhuma|nao|n|-|sem|depois|skip|nada)$/.test(tl);
  switch (NT.passo) {
    case 'acao': if (t.length < 3) return ntFala('Me dá uma frase curta do que é.');
      NT.d.action = t.slice(0, 160); ntInferir(t); return ntProximo();
    case 'cliente': { if (pula){ NT.d.client = null; return ntProximo(); }
      const m = ntAchaCliente(t); if (!m.length) return ntFala(`Não achei "${esc(t)}". Outro nome?`, ntChipsClientes(''));
      if (m.length > 1 && ntSemAcento(m[0].name) !== tl) return ntFala('Qual deles?', m.slice(0, 6).map(c => [c.name, '', `ntEscolhe('cliente','${c.id}')`]));
      return ntEscolhe('cliente', m[0].id); }
    case 'produto': { if (pula){ NT.d.product = null; return ntProximo(); }
      const m = ntProdutos(NT.d.client && NT.d.client.id).filter(p => ntSemAcento(p.name).includes(tl));
      if (!m.length) return ntFala('Esse não achei. Qual?', ntChipsProdutos());
      return ntEscolhe('produto', m[0].id); }
    case 'resp': { if (pula){ NT.d.resp = chaveDe(perfil); return ntProximo(); }
      const m = (EQUIPE || []).filter(k => { const p = (PERFIS || []).find(x => chaveDe(x) === k); return ntSemAcento(k).includes(tl) || (p && ntSemAcento(p.name).includes(tl)); });
      if (!m.length) return ntFala('Quem? Escolhe:', ntChipsEquipe());
      return ntEscolhe('resp', m[0]); }
    case 'date_int': { if (pula){ NT.d.date_int = null; return ntProximo(); } const d = ntData(t); if (!d) return ntFala('Não entendi. "amanhã", "sexta", "15/11"…'); NT.d.date_int = d; return ntProximo(); }
    case 'mae': { if (pula){ NT.d.mae = null; return ntProximo(); }
      const q = tl.replace(/^sd-?/, ''); const m = (tasksReais || []).filter(x => ntSemAcento(x.id).includes(tl) || ntSemAcento(x.action).includes(tl)).sort((a, b) => (b.client_id === (NT.d.client && NT.d.client.id)) - (a.client_id === (NT.d.client && NT.d.client.id))).slice(0, 5);
      if (!m.length) return ntFala('Não achei essa tarefa. Código ou parte do nome?', [['Não vem de outra', '', "ntEscolhe('mae',null)"]]);
      if (m.length === 1) return ntEscolhe('mae', m[0].id);
      return ntFala('Qual delas?', m.map(x => [x.id + ' · ' + String(x.action).slice(0, 40), '', `ntEscolhe('mae','${x.id}')`])); }
    case 'filha': NT.d.filha = /^(s|sim|vai|yes)$/.test(tl); return ntProximo();
    case 'extra': { if (!pula){ const ls = t.match(/https?:\/\/\S+/g) || []; if (ls.length) NT.d.links = NT.d.links.concat(ls); const resto = t.replace(/https?:\/\/\S+/g, '').trim(); if (/^(cria|criar|ok|vai|sim|manda|pode)$/.test(tl)){} else if (resto || ls.length) NT.d.obs = (NT.d.obs ? NT.d.obs + '\n' : '') + t; } return ntCriar(); }
  }
}
function ntPergunta(p){
  NT.passo = p; const c = document.querySelector(NT.verso ? '#campoVerso' : '#campo'); const ph = s => { if (c) c.placeholder = s; };
  switch (p) {
    case 'cliente': ph('Nome do cliente…'); return ntFala('Pra qual cliente?', ntChipsClientes('').concat([['SD (sem cliente)', '', "ntEscolhe('cliente',null)"]]));
    case 'produto': ph('Produto…'); return ntFala('Qual produto?', ntChipsProdutos().concat([['Cliente todo', '', "ntEscolhe('produto',null)"]]));
    case 'resp': ph('Quem cuida…'); return ntFala('Quem cuida?', ntChipsEquipe());
    case 'date_int': ph('amanhã · sexta · 15/11 · em 3 dias'); return ntFala('Pra quando?', [['Hoje', '', "ntEscolhe('date_int','hoje')"], ['Amanhã', '', "ntEscolhe('date_int','amanhã')"], ['Sexta', '', "ntEscolhe('date_int','sexta')"], ['Sem data', '', "ntEscolhe('date_int',null)"]]);
    case 'mae': ph('Código ou nome da tarefa de origem…'); return ntFala('Ela vem de outra tarefa?', [['Não', '', "ntEscolhe('mae',null)"]].concat(ntMaesProvaveis().map(x => [x.id + ' · ' + String(x.action).slice(0, 34), '', `ntEscolhe('mae','${x.id}')`])));
    case 'filha': return ntFala('Vai gerar outra tarefa depois?', [['Não', '', "ntEscolhe('filha',false)"], ['Sim', '', "ntEscolhe('filha',true)"]]);
    case 'extra': ph('Links ou detalhes — ou aperte Criar'); return ntFala(`<span class="nota">${esc(ntResumo())}</span><br>Algum link ou detalhe?`, [['Criar tarefa', 'verde', 'ntResponder("criar")'], ['Cancelar', '', 'ntCancelar()']]);
  }
}
function ntPular(){ ntResponder('pula'); }
function ntEscolhe(campo, v){
  if (campo === 'cliente'){ NT.d.client = v ? clientesReais.find(c => c.id === v) || null : null; ntEu(NT.d.client ? NT.d.client.name : 'SD'); }
  else if (campo === 'produto'){ NT.d.product = v ? ntProdutos(NT.d.client && NT.d.client.id).find(p => p.id === v) || null : null; ntEu(NT.d.product ? NT.d.product.name : 'Cliente todo'); }
  else if (campo === 'resp'){ NT.d.resp = v; const p = (PERFIS || []).find(x => chaveDe(x) === v); ntEu(p ? p.name.split(' ')[0] : v); }
  else if (campo === 'date_int'){ NT.d.date_int = v ? ntData(v) : null; ntEu(v ? fmtD(NT.d.date_int) : 'Sem data'); }
  else if (campo === 'mae'){ NT.d.mae = v ? (tasksReais || []).find(x => x.id === v) || null : null; ntEu(NT.d.mae ? NT.d.mae.id : 'Não'); }
  else if (campo === 'filha'){ NT.d.filha = !!v; ntEu(v ? 'Sim' : 'Não'); }
  return ntProximo();
}
function ntMaesProvaveis(){ const cid = NT.d.client && NT.d.client.id; return (tasksReais || []).filter(x => cid && x.client_id === cid && x.status !== 'ENTREGUE').sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')).slice(0, 3); }

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
  if (!d.project){ const pj = ntProjetos(d.client && d.client.id)[0]; d.project = (Array.isArray(pj) ? pj[0] : pj) || 'GERAL'; }
  if (!d.date_cli && d.date_int) d.date_cli = d.date_int;
  ntFala('Criando…');
  try {
    const { data: nid, error: eid } = await sbR.rpc('next_task_id'); if (eid || !nid) throw new Error(eid?.message || 'sem id novo');
    const nova = { id: nid, action: d.action.slice(0, 160), status: 'BRIEFING', resp: (d.resp || chaveDe(perfil) || 'SD').toUpperCase(), project: d.project || 'GERAL', week: 'ESTA SEMANA', created_by: perfil.id, obs: ntPreview().obs ? ntPreview().obs.slice(0, 4000) : null };
    if (d.mae) nova.tarefa_mae_id = d.mae.id; if (d.client) nova.client_id = d.client.id; if (d.product) nova.product_id = d.product.id; if (d.date_int) nova.date_int = d.date_int; if (d.date_cli) nova.date_cli = d.date_cli;
    let { data, error } = await sbR.from('tasks').insert(nova).select('id,action').single();
    if (error && /product_id/i.test(error.message || '')){ delete nova.product_id; ({ data, error } = await sbR.from('tasks').insert(nova).select('id,action').single()); }
    if (error) throw error;
    NT.ativa = false; NT.passo = null; const c = document.querySelector('#campo'); if (c) c.placeholder = 'Fale comigo...';
    try { await carregarReal(); atualizarVersoApp(); } catch(e){}
    const t = (tasksReais || []).find(x => x.id === data.id) || { ...nova, clients: d.client ? { name: d.client.name } : null, products: d.product ? { name: d.product.name } : null };
    ntFala(`Criada: <b>${data.id}</b>. Já está no board.` + ntCardHtml(t), [['Abrir a tarefa', '', `abrirTarefaReal('${data.id}')`], ['Subir material para aprovação', 'azul', `ntSubirAprovacao('${data.id}')`], ].concat(d.filha ? [['Criar a tarefa filha', 'verde', `ntIniciar('${data.id}')`]] : [['Criar outra', '', 'ntIniciar()']]));
    if (nova.client_id && /copy|legenda|texto/i.test(nova.action) && typeof adsFala === 'function') adsFala({ acao:'executar_tarefa', task_id: data.id }, '/pd-copy.php').then(j => toast('Copy da ' + data.id + ' montada no Studio › Copys (' + (j.n || 0) + ' itens).')).catch(() => {});
  } catch(e){ ntFala('Não consegui criar: ' + esc(e.message), [['Tentar de novo', 'verde', 'ntCriar()'], ['Cancelar', '', 'ntCancelar()']]); }
}
function ntSubirAprovacao(id){ popupIframe('/novo-lote.html?embed=1&task=' + id, 'Subir material — ' + id); }
