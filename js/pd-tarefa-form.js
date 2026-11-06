// =====================================================================
// Pontodot — NOVA TAREFA em caixa (campos para preencher, mesmo desenho da tarefa aberta)
//   "+" → Criar tarefa → abre esta caixa. Criar fica no topo.
// =====================================================================
const NTF = { d:{}, mae:null };
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
  NTF.mae = maeId || null; const mae = maeId ? (tasksReais || []).find(t => t.id === maeId) : null;
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
    let { data, error } = await sbR.from('tasks').insert(nova).select('id').single();
    if (error && /product_id/i.test(error.message || '')){ delete nova.product_id; ({ data, error } = await sbR.from('tasks').insert(nova).select('id').single()); }
    if (error) throw error;
    const filha = document.getElementById('ntfFilha').checked;
    fecharNovaTarefa(); toast('Tarefa ' + data.id + ' criada ✅');
    try { await carregarReal(); atualizarVersoApp(); } catch(e){}
    if (nova.client_id && /copy|legenda|texto/i.test(nova.action) && typeof adsFala === 'function') adsFala({ acao:'executar_tarefa', task_id: data.id }, '/pd-copy.php').catch(() => {});
    if (filha) abrirNovaTarefa(data.id); else abrirTarefaReal(data.id);
  } catch(e){ bt.disabled = false; bt.textContent = 'Criar tarefa'; toast('Não consegui criar: ' + (e.message || e)); }
}
