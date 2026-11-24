// =====================================================================
// Pontodot — NOVA TAREFA em caixa (campos para preencher, mesmo desenho da tarefa aberta)
//   "+" → Criar tarefa → abre esta caixa. Criar fica no topo.
// =====================================================================
const NTF = { d:{}, mae:null, cat:null, tipo:null, dias:[] };
// ---- recorrência + tipo de peça (banco de tarefas = service_catalog, o mesmo do app)
async function ntfCatalogo(){ if (NTF.cat) return NTF.cat; try { const { data } = await sbR.from('service_catalog').select('task_id,categoria,tipo_tarefa').order('categoria').order('tipo_tarefa'); NTF.cat = data || []; } catch(e){ NTF.cat = []; } return NTF.cat; }
function ntfSA(v){ return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
async function ntfBuscaTipo(v){
  await ntfCatalogo(); const q = ntfSA(v), pal = q.split(/\s+/).filter(Boolean); const box = document.getElementById('ntfTipoL'); if (!box) return;
  NTF.tipo = null; const base = !q ? NTF.cat.filter(x => /social|calend|copy/i.test(x.tipo_tarefa + ' ' + x.categoria)).slice(0, 8) :
    NTF.cat.map(x => { const n = ntfSA(x.tipo_tarefa), c = ntfSA(x.categoria); const sc = n === q ? 100 : n.startsWith(q) ? 80 : n.includes(q) ? 60 : c.includes(q) ? 40 : (pal.length > 1 && pal.every(w => n.includes(w))) ? 30 : 0; return { x, sc }; }).filter(r => r.sc > 0).sort((a, b) => b.sc - a.sc).slice(0, 8).map(r => r.x);
  box.innerHTML = base.length ? base.map(x => `<div class="ntf-opt" onclick="ntfEscolheTipo('${x.task_id}')"><span>${esc(x.tipo_tarefa)}</span><small>${esc(x.categoria || '')}</small></div>`).join('') : '<div class="ntf-opt" style="opacity:.6">Nada parecido no banco de tarefas.</div>';
  box.style.display = 'block';
}
function ntfEscolheTipo(id){ const x = (NTF.cat || []).find(c => c.task_id === id); if (!x) return; NTF.tipo = x; const i = document.getElementById('ntfTipo'); if (i) i.value = x.tipo_tarefa; const b = document.getElementById('ntfTipoL'); if (b) b.style.display = 'none';
  const n = document.getElementById('ntfNome'); if (n && !n.value.trim()) n.value = x.tipo_tarefa; }
function ntfToggleRec(){ const on = document.getElementById('ntfRec').checked; document.getElementById('ntfRecP').style.display = on ? 'grid' : 'none'; if (on){ ntfBuscaTipo(''); ntfRecUI(); } }
const NTF_DN = { SEG:'segunda', TER:'terça', QUA:'quarta', QUI:'quinta', SEX:'sexta', SAB:'sábado', DOM:'domingo' };
function ntfRecUI(){
  const v = id => (document.getElementById(id) || {}).value || '', fr = v('ntfFreq') || 's1', mensal = fr[0] === 'm', n = parseInt(fr.slice(1)) || 1, fim = v('ntfFim') || 'n';
  const show = (id, on) => { const e = document.getElementById(id); if (e) e.style.display = on ? '' : 'none'; };
  show('ntfDomW', mensal); const dias = document.querySelector('#ntfRecP .ntf-dias'); if (dias && dias.parentElement) dias.parentElement.style.display = mensal ? 'none' : '';
  show('ntfFimN', fim === 'n'); show('ntfFimD', fim === 'd');
  const un = document.getElementById('ntfUnit'); if (un && mensal && un.value === 'semanas' && !un._tocou) un.value = 'meses';
  const quando = mensal ? (n === 1 ? 'Todo mês' : 'A cada ' + n + ' meses') + ', no dia ' + (v('ntfDom') || 1) : (n === 1 ? 'Toda semana' : 'A cada ' + n + ' semanas') + (NTF.dias.length ? ', ' + NTF.dias.map(d => NTF_DN[d]).join(', ') : ' (marque os dias)');
  const ate = fim === 'n' ? ', por ' + (v('ntfCount') || 4) + ' ' + v('ntfUnit') : fim === 'd' ? (v('ntfAte') ? ', até ' + v('ntfAte').split('-').reverse().join('/') : ', até a data que você escolher') : ', sem data para acabar';
  const r = document.getElementById('ntfRecRes'); if (r) r.textContent = quando + ate + '.';
}
function ntfDia(b){ const d = b.dataset.d; NTF.dias = NTF.dias.includes(d) ? NTF.dias.filter(x => x !== d) : NTF.dias.concat(d); b.classList.toggle('on', NTF.dias.includes(d)); ntfRecUI(); }
function ntfAnoISO(d){ return d ? String(d).slice(0, 10) : ''; }
function ntfProdutosDe(cid){
  try { const base = (window.ADS && ADS.prods) || (window.SOC && SOC.prods) || window.PRODUTOS || []; return base.filter(p => p.client_id === cid); } catch(e){ return []; }
}
async function ntfCarregaProdutos(cid){
  const sel = document.getElementById('ntfProd'); if (!sel) return;
  let ps = ntfProdutosDe(cid);
  if (!ps.length && cid && window.REAL){ try { const { data } = await sbR.from('products').select('id,name,client_id').eq('client_id', cid).order('name'); ps = data || []; } catch(e){} }
  sel.innerHTML = '<option value="">' + (ps.length ? 'Cliente todo' : '—') + '</option>' + ps.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
  sel.disabled = !ps.length;
}
function ntfProjetosDe(cid){ const c = {}; (tasksReais || []).forEach(t => { if ((!cid || t.client_id === cid) && t.project) c[t.project] = (c[t.project] || 0) + 1; }); return Object.entries(c).sort((a, b) => b[1] - a[1]).map(x => x[0]).slice(0, 12); }
function ntfMudouCliente(){
  const cid = document.getElementById('ntfCli').value; ntfCarregaProdutos(cid);
  const dl = document.getElementById('ntfProjs'); if (dl) dl.innerHTML = ntfProjetosDe(cid).map(x => `<option value="${esc(x)}">`).join('');
  const m = document.getElementById('ntfMae'); if (m) m.innerHTML = ntfOpcoesMae(cid);
}
function ntfOpcoesMae(cid){
  const l = (tasksReais || []).filter(t => cid ? t.client_id === cid : true).sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')).slice(0, 40);
  return '<option value="">Não — é uma tarefa nova</option>' + l.map(t => `<option value="${t.id}" ${NTF.mae === t.id ? 'selected' : ''}>${esc(t.id)} · ${esc(String(t.action).slice(0, 60))}</option>`).join('');
}
function ntfEscolheResp(k){ NTF.resp = k; document.querySelectorAll('#ntfOv .ntf-av').forEach(b => b.classList.toggle('on', b.dataset.k === k)); }
function abrirNovaTarefa(maeId){
  fecharNovaTarefa();
  NTF.mae = maeId || null; NTF.tipo = null; NTF.dias = []; const mae = maeId ? (tasksReais || []).find(t => t.id === maeId) : null;
  NTF.resp = (typeof chaveDe === 'function' && perfil) ? chaveDe(perfil) : '';
  const eu = NTF.resp, cid0 = mae ? mae.client_id : '';
  const avatares = (PERFIS || []).filter(p => p.name && (typeof chaveDe === 'function' ? chaveDe(p) : p.name)).map(p => { const k = typeof chaveDe === 'function' ? chaveDe(p) : p.name.split(' ')[0].toUpperCase();
    const ini = p.name.split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase();
    return `<button type="button" class="ntf-av ${k === eu ? 'on' : ''}" data-k="${esc(k)}" onclick="ntfEscolheResp('${esc(k)}')" title="${esc(p.name)}">${p.avatar_url ? `<img src="${esc(p.avatar_url)}" alt="">` : `<span>${esc(ini)}</span>`}<small>${esc(p.name.split(' ')[0])}</small></button>`; }).join('');
  const ov = document.createElement('div'); ov.id = 'ntfOv'; ov.className = 'sem-ajuda';
  ov.onclick = e => { if (e.target === ov) fecharNovaTarefa(); };
  ov.innerHTML = `<style>
    #ntfOv{position:fixed;inset:0;z-index:9500;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px}
    #ntfOv .ntf{background:#F4F3EF;color:#15161A;width:min(720px,100%);max-height:94vh;overflow-y:auto;border-radius:20px;padding:20px 26px 26px;font-weight:300;position:relative}
    #ntfOv .ntf-top{display:flex;align-items:center;gap:10px;margin-bottom:14px}#ntfOv .ntf-top b{flex:1;font-weight:400;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.55}
    #ntfOv .ntf-ok{background:#15161A;color:#fff;border:0;border-radius:999px;padding:10px 20px;font:500 13px inherit;cursor:pointer}#ntfOv .ntf-ok:disabled{opacity:.5}
    #ntfOv .ntf-x{background:transparent;border:0;font-size:18px;cursor:pointer;color:#15161A;padding:6px 10px}
    #ntfOv input,#ntfOv select,#ntfOv textarea{width:100%;font:inherit;color:#15161A;background:#fff;border:.5px solid rgba(0,0,0,.18);border-radius:10px;padding:10px 12px}
    #ntfOv .ntf-nome{font-size:22px;font-weight:300;border:0;border-bottom:.5px solid rgba(0,0,0,.25);border-radius:0;background:transparent;padding:6px 2px;margin-bottom:6px}
    #ntfOv .ntf-g{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px;margin-top:16px}@media(max-width:600px){#ntfOv .ntf-g{grid-template-columns:1fr}}
    #ntfOv label{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;opacity:.55;margin-bottom:5px}
    #ntfOv .ntf-full{grid-column:1/-1}
    #ntfOv .ntf-avs{display:flex;gap:8px;flex-wrap:wrap}
    #ntfOv .ntf-av{background:transparent;border:1.5px solid transparent;border-radius:14px;padding:4px 6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;color:#15161A;opacity:.55}
    #ntfOv .ntf-av img,#ntfOv .ntf-av span{width:40px;height:40px;border-radius:50%;object-fit:cover;background:rgba(0,0,0,.08);display:flex;align-items:center;justify-content:center;font-size:13px}
    #ntfOv .ntf-av small{font-size:10.5px}#ntfOv .ntf-av.on{opacity:1;border-color:#15161A}
    #ntfOv .ntf-rec{grid-column:1/-1;display:none;grid-template-columns:1fr 1fr;gap:12px 16px;padding:14px;border:.5px solid rgba(0,0,0,.18);border-radius:12px;background:rgba(255,255,255,.6)}
    #ntfOv .ntf-lst{position:relative}#ntfOv .ntf-opts{display:none;position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:.5px solid rgba(0,0,0,.18);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);z-index:5;overflow:hidden}
    #ntfOv .ntf-opt{display:flex;justify-content:space-between;gap:10px;padding:9px 12px;cursor:pointer;font-size:13px}#ntfOv .ntf-opt:hover{background:#F4F3EF}#ntfOv .ntf-opt small{opacity:.55;font-size:10.5px}
    #ntfOv .ntf-dias{display:flex;gap:6px;flex-wrap:wrap}#ntfOv .ntf-dia{border:.5px solid rgba(0,0,0,.25);background:#fff;border-radius:999px;padding:7px 12px;font:500 12px inherit;cursor:pointer;color:#15161A}#ntfOv .ntf-dia.on{background:#15161A;color:#fff;border-color:#15161A}
    #ntfOv .ntf-chk{display:flex;align-items:center;gap:8px;font-size:13px}#ntfOv .ntf-chk input{width:auto}
  </style>
  <div class="ntf"><div class="ntf-top"><b>${mae ? 'Tarefa filha de ' + esc(mae.id) : 'Nova tarefa'}</b><button class="ntf-ok" id="ntfOk" onclick="ntfCriar()">Criar tarefa</button><button class="ntf-x" onclick="fecharNovaTarefa()" aria-label="Fechar">✕</button></div>
    <input class="ntf-nome" id="ntfNome" placeholder="Nome da tarefa" maxlength="160" autocomplete="off">
    <div class="ntf-g">
      <div><label>Cliente</label><select id="ntfCli" onchange="ntfMudouCliente()"><option value="">SD (sem cliente)</option>${(clientesReais || []).filter(c => c.active !== false).sort((a, b) => a.name.localeCompare(b.name)).map(c => `<option value="${c.id}" ${c.id === cid0 ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
      <div><label>Produto</label><select id="ntfProd"><option value="">—</option></select></div>
      <div class="ntf-full"><label>Quem cuida</label><div class="ntf-avs">${avatares}</div></div>
      <div><label>Entrega · interna</label><input type="date" id="ntfDi"></div>
      <div><label>Entrega · cliente</label><input type="date" id="ntfDc"></div>
      <div><label>Projeto</label><input id="ntfProj" list="ntfProjs" placeholder="GERAL" autocomplete="off"><datalist id="ntfProjs"></datalist></div>
      <div><label>Vem de outra tarefa?</label><select id="ntfMae">${ntfOpcoesMae(cid0)}</select></div>
      <div class="ntf-full"><label>Briefing</label><textarea id="ntfObs" rows="5" placeholder="O que é, para quem, o que não pode faltar, links…"></textarea></div>
      <div class="ntf-full ntf-chk"><input type="checkbox" id="ntfFilha"><label style="margin:0;opacity:.8;text-transform:none;letter-spacing:0;font-size:13px" for="ntfFilha">Esta tarefa vai gerar outra depois (abre a próxima ao criar)</label></div>
      <div class="ntf-full ntf-chk"><input type="checkbox" id="ntfRec" onchange="ntfToggleRec()"><label style="margin:0;opacity:.8;text-transform:none;letter-spacing:0;font-size:13px" for="ntfRec">É recorrente (definir quando repete)</label></div>
      <div class="ntf-rec" id="ntfRecP">
        <div class="ntf-full ntf-lst"><label>Que tipo de peça repete?</label><input id="ntfTipo" placeholder="Comece a digitar: social, calendário de, copy…" autocomplete="off" oninput="ntfBuscaTipo(this.value)" onfocus="ntfBuscaTipo(this.value)"><div class="ntf-opts" id="ntfTipoL"></div></div>
        <div class="ntf-full"><label>Em quais dias da semana entrega?</label><div class="ntf-dias">${[['SEG','Seg'],['TER','Ter'],['QUA','Qua'],['QUI','Qui'],['SEX','Sex'],['SAB','Sáb'],['DOM','Dom']].map(d => `<button type="button" class="ntf-dia" data-d="${d[0]}" onclick="ntfDia(this)">${d[1]}</button>`).join('')}</div></div>
        <div class="ntf-full"><label>Com que frequência?</label><select id="ntfFreq" onchange="ntfRecUI()"><option value="s1">Toda semana</option><option value="s2">A cada 2 semanas</option><option value="s3">A cada 3 semanas</option><option value="m1">Todo mês, num dia fixo</option><option value="m2">A cada 2 meses, num dia fixo</option></select></div>
        <div class="ntf-full" id="ntfDomW" style="display:none"><label>Em que dia do mês?</label><input type="number" min="1" max="31" id="ntfDom" value="1"></div>
        <div><label>Até quando?</label><select id="ntfFim" onchange="ntfRecUI()"><option value="n">Depois de um número de vezes</option><option value="d">Até uma data</option><option value="x">Sem data para acabar</option></select></div>
        <div id="ntfFimN"><label>Quantas vezes</label><div style="display:flex;gap:6px"><input type="number" min="1" id="ntfCount" value="4" oninput="ntfRecUI()" style="width:80px"><select id="ntfUnit" onchange="ntfRecUI()"><option value="semanas">semanas</option><option value="meses">meses</option></select></div></div>
        <div id="ntfFimD" style="display:none"><label>Última entrega</label><input type="date" id="ntfAte" onchange="ntfRecUI()"></div>
        <div class="ntf-full" id="ntfRecRes" style="font-size:12.5px;opacity:.75"></div>
      </div>
    </div></div>`;
  document.body.appendChild(ov);
  ntfMudouCliente();
  setTimeout(() => { const n = document.getElementById('ntfNome'); if (n) n.focus(); }, 60);
  document.addEventListener('keydown', ntfEsc);
}
function ntfEsc(e){ if (e.key === 'Escape') fecharNovaTarefa(); }
function fecharNovaTarefa(){ const o = document.getElementById('ntfOv'); if (o) o.remove(); document.removeEventListener('keydown', ntfEsc); }
async function ntfCriar(){
  const g = id => (document.getElementById(id) || {}).value || '';
  const nome = g('ntfNome').trim(); if (nome.length < 3){ toast('Dê um nome para a tarefa.'); document.getElementById('ntfNome').focus(); return; }
  const bt = document.getElementById('ntfOk'); bt.disabled = true; bt.textContent = 'Criando…';
  try {
    const { data: nid, error: eid } = await sbR.rpc('next_task_id'); if (eid || !nid) throw new Error(eid?.message || 'sem id novo');
    const nova = { id: nid, action: nome.slice(0, 160), status: 'BRIEFING', resp: (NTF.resp || chaveDe(perfil) || 'SD').toUpperCase(), project: (g('ntfProj').trim() || 'GERAL').toUpperCase().slice(0, 40), week: 'ESTA SEMANA', created_by: perfil.id };
    if (g('ntfCli')) nova.client_id = g('ntfCli'); if (g('ntfProd')) nova.product_id = g('ntfProd');
    if (g('ntfDi')) nova.date_int = g('ntfDi'); const dc = g('ntfDc') || g('ntfDi'); if (dc) nova.date_cli = dc;
    if (g('ntfObs').trim()) nova.obs = g('ntfObs').trim(); if (g('ntfMae')) nova.tarefa_mae_id = g('ntfMae');
    if (document.getElementById('ntfRec').checked){
      const fr = g('ntfFreq') || 's1', mensal = fr[0] === 'm', fim = g('ntfFim') || 'n';
      if (!mensal && !NTF.dias.length){ bt.disabled = false; bt.textContent = 'Criar tarefa'; toast('Marque os dias da semana da recorrência.'); return; }
      if (fim === 'd' && !g('ntfAte')){ bt.disabled = false; bt.textContent = 'Criar tarefa'; toast('Escolha a data da última entrega.'); return; }
      nova.recurrence = { enabled: true, mode: mensal ? 'mensal' : 'semanal', every: parseInt(fr.slice(1)) || 1, days: mensal ? [] : NTF.dias.slice(), dom: mensal ? Math.max(1, Math.min(31, parseInt(g('ntfDom')) || 1)) : null,
        end: fim, count: fim === 'n' ? Math.max(1, parseInt(g('ntfCount')) || 4) : null, unit: g('ntfUnit') || (mensal ? 'meses' : 'semanas'), until: fim === 'd' ? g('ntfAte') : null };
      if (NTF.tipo) nova.service_task_id = NTF.tipo.task_id;
    }
    let { data, error } = await sbR.from('tasks').insert(nova).select('id').single();
    if (error && /recurrence|service_task_id/i.test(error.message || '')){ delete nova.recurrence; delete nova.service_task_id; ({ data, error } = await sbR.from('tasks').insert(nova).select('id').single()); }
    if (error && /product_id/i.test(error.message || '')){ delete nova.product_id; ({ data, error } = await sbR.from('tasks').insert(nova).select('id').single()); }
    if (error) throw error;
    const filha = document.getElementById('ntfFilha').checked;
    fecharNovaTarefa(); toast('Tarefa ' + data.id + ' criada ✅');
    try { await carregarReal(); atualizarVersoApp(); } catch(e){}
    if (nova.client_id && /copy|legenda|texto/i.test(nova.action) && typeof adsFala === 'function') adsFala({ acao:'executar_tarefa', task_id: data.id }, '/pd-copy.php').catch(() => {});
    if (filha) abrirNovaTarefa(data.id); else abrirTarefaReal(data.id);
  } catch(e){ bt.disabled = false; bt.textContent = 'Criar tarefa'; toast('Não consegui criar: ' + (e.message || e)); }
}
