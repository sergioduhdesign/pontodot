/* =====================================================================
   Pontodot — painéis "Sites e LPs" e "Design System" (subir em volume)
   + súmula do cliente (aba Canais do perfil).
   Fonte única: pd_site_lista (todos os sites/LPs) e brand_kits (design system).
   pd_sites (a esteira) só espelha o site PRINCIPAL; nada é digitado duas vezes.
   Usa do app: sb, clients, profile, showToast, sdFetch, setView, openClientDetail,
   mcAba, mcArquivoSobe, pdCofreAbrir, currentClientId, XLSX.
   ===================================================================== */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const E = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const norm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const T = (m, t) => { try { showToast(m, t || 'info'); } catch (_) {} };
  const cliNome = id => { const c = (typeof clients !== 'undefined' ? clients : []).find(x => x.id === id); return c ? c.name : '—'; };

  const PAPEL = { site: 'Site', lp: 'LP', area_clientes: 'Área de clientes', blog: 'Blog' };
  const PLAT = { wp: 'WordPress', html: 'HTML', wix: 'Wix', outro: 'Outro' };
  const STAT = { no_ar: 'No ar', em_obra: 'Em obra', fora_do_ar: 'Fora do ar', desconhecido: 'Não sei' };
  const ESTADO = { pendente: 'Pendente', ok: 'Ok', nao_precisa: 'Não precisa' };
  const CMS = { pendente: 'Pendente', conectado: 'Conectado', erro: 'Erro', nao_precisa: 'Não precisa' };
  const COR = { ok: '#16a34a', conectado: '#16a34a', no_ar: '#16a34a', pendente: '#d97706', em_obra: '#d97706', erro: '#dc2626', fora_do_ar: '#dc2626', nao_precisa: '#8e8e93', desconhecido: '#8e8e93' };
  const opts = (m, v) => Object.keys(m).map(k => '<option value="' + k + '"' + (k === v ? ' selected' : '') + '>' + m[k] + '</option>').join('');
  const chip = (txt, k) => '<span style="display:inline-block;padding:1px 8px;border-radius:20px;font-size:10.5px;font-weight:700;color:' + (COR[k] || '#8e8e93') + ';border:1px solid ' + (COR[k] || '#8e8e93') + '55">' + E(txt) + '</span>';
  const semTabela = e => /pd_site_lista|relation|does not exist|schema cache/i.test((e && e.message) || '');

  // ------------------------------------------------------------ dados
  let SL = [], SITES = {}, SEL = new Set(), FCLI = '', FST = '', FALTA = false;
  async function carrega() {
    const [a, b] = await Promise.all([
      sb.from('pd_site_lista').select('*').order('atualizado_em', { ascending: false }),
      sb.from('pd_sites').select('client_id,tipo,url,ativo').then(r => r, () => ({ data: [] })),
    ]);
    FALTA = !!(a.error && semTabela(a.error));
    SL = (a.data || []).sort((x, y) => cliNome(x.client_id).localeCompare(cliNome(y.client_id), 'pt-BR') || (y.principal - x.principal) || x.nome.localeCompare(y.nome, 'pt-BR'));
    SITES = {}; ((b && b.data) || []).forEach(s => SITES[s.client_id] = s);
  }

  // o item principal é o que a esteira publica: espelha tipo/url em pd_sites (única cópia)
  async function tornaPrincipal(item) {
    await sb.from('pd_site_lista').update({ principal: false }).eq('client_id', item.client_id).neq('id', item.id);
    const r = await sb.from('pd_site_lista').update({ principal: true }).eq('id', item.id);
    if (r.error) throw r.error;
    const tipo = item.plataforma === 'wp' ? 'wp' : item.plataforma === 'html' ? 'html' : 'bloco';
    const e = await sb.from('pd_sites').upsert({ client_id: item.client_id, tipo, url: item.url || null, atualizado_por: profile.id, atualizado_em: new Date().toISOString() });
    if (e.error) throw e.error;
  }

  // ------------------------------------------------------------ painel Sites
  window.pdSitesRender = async function () {
    const box = $('view-sites'); if (!box) return;
    box.innerHTML = '<div style="padding:24px;color:var(--text3)">Carregando…</div>';
    await carrega();
    desenha();
  };

  function desenha() {
    const box = $('view-sites'); if (!box) return;
    const cl = (typeof clients !== 'undefined' ? clients : []).filter(c => c.active !== false).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
    const L = SL.filter(s => (!FCLI || s.client_id === FCLI) && (!FST || s.status === FST));
    const pend = SL.filter(s => s.cms === 'pendente' || s.backend === 'pendente' || s.area_clientes === 'pendente' || s.revisao === 'pendente').length;
    const th = 'style="text-align:left;padding:8px 10px;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);white-space:nowrap"';
    const sel = (campo, mapa, s) => '<select class="field-select" style="font-size:11.5px;padding:3px 6px;min-width:96px;color:' + (COR[s[campo]] || 'inherit') + '" onchange="pdSitesCampo(\'' + s.id + '\',\'' + campo + '\',this.value)">' + opts(mapa, s[campo]) + '</select>';
    box.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">Sites e LPs</div><div class="page-subtitle">${SL.length} no total · ${pend} com pendência · tudo o que a PD conecta e publica</div></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="pdSitesLote()">Subir em lote</button>
        <button class="btn btn-secondary" onclick="pdSiteForm()">+ Novo site</button>
        <button class="btn btn-secondary" onclick="pdSitesModelo()" title="Planilha modelo para preencher e subir">Modelo CSV</button>
      </div>
    </div>
    ${FALTA ? '<div style="margin:0 16px 12px;padding:12px 14px;border-radius:10px;background:var(--surface2);font-size:12.5px;line-height:1.5"><b>Falta rodar o SQL v175 no Supabase</b> (migration_v175_pd_site_lista.sql). Depois é só recarregar.</div>' : ''}
    <div style="display:flex;align-items:center;gap:8px;padding:0 16px 10px;flex-wrap:wrap">
      <select class="filter-sel" onchange="pdSitesFiltro('cli',this.value)"><option value="">Todos os clientes</option>${cl.map(c => '<option value="' + c.id + '"' + (c.id === FCLI ? ' selected' : '') + '>' + E(c.name) + '</option>').join('')}</select>
      <select class="filter-sel" onchange="pdSitesFiltro('st',this.value)"><option value="">Qualquer situação</option>${Object.keys(STAT).map(k => '<option value="' + k + '"' + (k === FST ? ' selected' : '') + '>' + STAT[k] + '</option>').join('')}</select>
      <span style="font-size:12px;color:var(--text3);margin-left:auto">${L.length} exibido${L.length === 1 ? '' : 's'}</span>
    </div>
    <div id="sites-bulk" style="display:${SEL.size ? 'flex' : 'none'};align-items:center;gap:8px;flex-wrap:wrap;padding:9px 16px;background:var(--surface2);border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
      <b style="font-size:12.5px">${SEL.size} selecionado${SEL.size === 1 ? '' : 's'}</b>
      ${[['cms', 'CMS', CMS], ['backend', 'Backend', ESTADO], ['area_clientes', 'Área de clientes', ESTADO], ['revisao', 'Revisão', ESTADO], ['status', 'Situação', STAT], ['plataforma', 'Plataforma', PLAT]].map(([c, n, m]) =>
        '<select class="field-select" style="font-size:11.5px;padding:3px 6px" onchange="pdSitesLoteCampo(\'' + c + '\',this.value);this.value=\'\'"><option value="">' + n + '…</option>' + opts(m, '') + '</select>').join('')}
      <button class="btn btn-secondary btn-sm" onclick="pdSitesTarefas()">Criar tarefas das pendências</button>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" onclick="pdSitesLimpaSel()">Limpar seleção</button>
    </div>
    <div style="overflow:auto;padding:0 8px 40px">
    <table class="list-tbl" style="width:100%;min-width:1100px"><thead><tr>
      <th style="width:30px"><input type="checkbox" onchange="pdSitesSelTudo(this.checked)" ${L.length && L.every(s => SEL.has(s.id)) ? 'checked' : ''}></th>
      <th ${th}>Cliente</th><th ${th}>Site / LP</th><th ${th}>Plataforma</th><th ${th}>Hospedagem</th><th ${th}>Situação</th><th ${th}>CMS</th><th ${th}>Backend</th><th ${th}>Área cliente</th><th ${th}>Revisão</th><th ${th}>Publica sozinho</th><th></th></tr></thead><tbody>
    ${L.map(s => {
      const ps = SITES[s.client_id];
      return `<tr style="border-top:1px solid var(--border)">
        <td><input type="checkbox" ${SEL.has(s.id) ? 'checked' : ''} onchange="pdSitesSel('${s.id}',this.checked)"></td>
        <td style="padding:8px 10px;font-weight:600;white-space:nowrap"><a href="#" onclick="event.preventDefault();openClientDetail('${s.client_id}')" style="color:inherit">${E(cliNome(s.client_id))}</a></td>
        <td style="padding:8px 10px"><div style="font-weight:600">${s.principal ? '★ ' : ''}${E(s.nome)} <span style="font-weight:500;color:var(--text3);font-size:11px">· ${PAPEL[s.papel] || s.papel}</span></div>
          ${s.url ? '<a href="' + E(s.url) + '" target="_blank" rel="noopener" style="font-size:11px;color:var(--text3)">' + E(s.url.replace(/^https?:\/\//, '')) + '</a>' : '<span style="font-size:11px;color:var(--text3)">sem endereço</span>'}
          ${s.responsavel || s.obs ? '<div style="font-size:11px;color:var(--text3)">' + E([s.responsavel, s.obs].filter(Boolean).join(' · ')) + '</div>' : ''}</td>
        <td style="padding:8px 10px;font-size:12px">${PLAT[s.plataforma] || s.plataforma}</td>
        <td style="padding:8px 10px;font-size:12px">${E(s.hospedagem || '—')}${s.repo ? '<div style="font-size:11px;color:var(--text3)">' + E(s.repo) + '</div>' : ''}</td>
        <td style="padding:8px 6px">${sel('status', STAT, s)}</td>
        <td style="padding:8px 6px">${sel('cms', CMS, s)}</td>
        <td style="padding:8px 6px">${sel('backend', ESTADO, s)}</td>
        <td style="padding:8px 6px">${sel('area_clientes', ESTADO, s)}</td>
        <td style="padding:8px 6px">${sel('revisao', ESTADO, s)}</td>
        <td style="padding:8px 10px;text-align:center">${s.principal ? '<input type="checkbox" ' + (ps && ps.ativo ? 'checked' : '') + ' title="A esteira publica blog e capa aprovados neste site" onchange="pdSitesAtivo(\'' + s.client_id + '\',this.checked)">' : '<button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" title="Tornar este o site que a PD publica" onclick="pdSitesPrincipal(\'' + s.id + '\')">★ principal</button>'}</td>
        <td style="padding:8px 8px;white-space:nowrap">
          ${s.principal && s.plataforma === 'wp' ? '<button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" onclick="pdSiteCofre(\'' + s.client_id + '\')"></button> ' : ''}
          ${s.principal ? '<button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" onclick="pdSiteTestar(\'' + s.client_id + '\',\'' + s.id + '\')">Testar</button> <button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" title="Trecho que se cola no site" onclick="pdSiteBloco(\'' + s.client_id + '\')">Bloco</button> ' : ''}
          <button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" onclick="pdSiteForm('${s.id}')">Editar</button>
          <button class="btn btn-secondary btn-sm" style="padding:1px 8px;font-size:10.5px" onclick="pdSiteExcluir('${s.id}')" title="Excluir">✕</button></td>
      </tr>`;
    }).join('') || '<tr><td colspan="12" style="padding:30px;text-align:center;color:var(--text3)">Nenhum site ainda. Use “Subir em lote” para trazer a lista toda de uma vez.</td></tr>'}
    </tbody></table></div>`;
  }

  window.pdSitesFiltro = (k, v) => { if (k === 'cli') FCLI = v; else FST = v; desenha(); };
  window.pdSitesSel = (id, on) => { on ? SEL.add(id) : SEL.delete(id); desenha(); };
  window.pdSitesSelTudo = on => { SL.filter(s => (!FCLI || s.client_id === FCLI) && (!FST || s.status === FST)).forEach(s => on ? SEL.add(s.id) : SEL.delete(s.id)); desenha(); };
  window.pdSitesLimpaSel = () => { SEL.clear(); desenha(); };
  window.pdSitesCampo = async (id, campo, v) => {
    const { error } = await sb.from('pd_site_lista').update({ [campo]: v }).eq('id', id);
    if (error) return T('Não salvei: ' + error.message, 'error');
    const s = SL.find(x => x.id === id); if (s) s[campo] = v; desenha();
  };
  window.pdSitesLoteCampo = async (campo, v) => {
    if (!v || !SEL.size) return;
    const { error } = await sb.from('pd_site_lista').update({ [campo]: v }).in('id', [...SEL]);
    if (error) return T('Não salvei: ' + error.message, 'error');
    SL.forEach(s => { if (SEL.has(s.id)) s[campo] = v; }); T('Atualizado em ' + SEL.size + '.', 'success'); desenha();
  };
  window.pdSitesAtivo = async (cid, on) => {
    const { error } = await sb.from('pd_sites').upsert({ client_id: cid, ativo: !!on, atualizado_por: profile.id, atualizado_em: new Date().toISOString() });
    if (error) return T('Não salvei: ' + error.message, 'error');
    SITES[cid] = Object.assign(SITES[cid] || { client_id: cid }, { ativo: !!on }); T(on ? 'A esteira passa a publicar neste site.' : 'Publicação automática desligada.', 'success');
  };
  window.pdSitesPrincipal = async id => {
    const s = SL.find(x => x.id === id); if (!s) return;
    try { await tornaPrincipal(s); await carrega(); desenha(); T('Este é o site que a PD publica.', 'success'); } catch (e) { T('Não consegui: ' + (e.message || e), 'error'); }
  };
  window.pdSiteExcluir = async id => {
    const s = SL.find(x => x.id === id); if (!s) return;
    if (!confirm('Tirar “' + s.nome + '” (' + cliNome(s.client_id) + ') da lista? O site em si não é afetado.')) return;
    const { error } = await sb.from('pd_site_lista').delete().eq('id', id);
    if (error) return T('Não apaguei: ' + error.message, 'error');
    SL = SL.filter(x => x.id !== id); SEL.delete(id); desenha();
  };

  // ações do conector (já existentes no servidor)
  window.pdSiteTestar = async (cid, itemId) => {
    T('Testando a conexão…');
    try {
      const r = await sdFetch('/pd-sites.php', { acao: 'testar', client_id: cid });
      T('✓ conectado como ' + (r.usuario || '?'), 'success');
      if (itemId) await window.pdSitesCampo(itemId, 'cms', 'conectado');
    } catch (e) { T('✗ ' + (e.message || e), 'error'); if (itemId) await window.pdSitesCampo(itemId, 'cms', 'erro'); }
    try { if ($('mc-sumula') && currentClientId === cid) pdSumulaCliente(cid); } catch (_) {}
  };
  window.pdSiteBloco = async cid => {
    try { const r = await sdFetch('/pd-sites.php', { acao: 'bloco', client_id: cid });
      try { await navigator.clipboard.writeText(r.snippet); T('Bloco copiado: cole onde os posts devem aparecer no site.', 'success'); } catch (_) { prompt('Copie e cole no site:', r.snippet); }
    } catch (e) { T('✗ ' + (e.message || e), 'error'); }
  };
  window.pdSiteCofre = cid => { const antes = currentClientId; currentClientId = cid; try { pdCofreAbrir('wordpress'); } finally { setTimeout(() => { currentClientId = antes; }, 0); } };

  // tarefas das pendências (uma por pendência, no projeto SITES do cliente)
  window.pdSitesTarefas = async () => {
    const itens = SL.filter(s => SEL.has(s.id)), acoes = [];
    itens.forEach(s => {
      const base = cliNome(s.client_id) + ' · ' + s.nome;
      if (s.cms === 'pendente') acoes.push([s, 'Conectar ao CMS da PD — ' + base]);
      if (s.backend === 'pendente') acoes.push([s, 'Acertar o backend — ' + base]);
      if (s.area_clientes === 'pendente') acoes.push([s, 'Acertar a área de clientes — ' + base]);
      if (s.revisao === 'pendente') acoes.push([s, 'Revisar o site — ' + base]);
      if (s.status === 'em_obra') acoes.push([s, 'Publicar — ' + base]);
    });
    if (!acoes.length) return T('Nada pendente nos selecionados.', 'info');
    if (!confirm('Criar ' + acoes.length + ' tarefa(s) no projeto “SITES” dos clientes?')) return;
    let ok = 0;
    for (const [s, action] of acoes) {
      try {
        const { data: nid } = await sb.rpc('next_task_id');
        const resp = /juan/i.test(s.responsavel || '') ? 'JUAN' : /cliente/i.test(s.responsavel || '') ? 'CLIENTE' : 'SERGIO';
        const { error } = await sb.from('tasks').insert({ id: nid, created_by: profile.id, client_id: s.client_id, project: 'SITES', action, resp, status: 'BRIEFING', week: '', obs: s.url || null });
        if (!error) ok++;
      } catch (_) {}
    }
    T(ok + ' tarefa(s) criada(s).', ok ? 'success' : 'error');
  };

  // ------------------------------------------------------------ formulário de um site
  function modal(html, larg) {
    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:99700;display:flex;align-items:center;justify-content:center;background:rgba(10,10,12,.55);backdrop-filter:blur(6px);padding:20px';
    ov.innerHTML = '<div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;max-width:' + (larg || 560) + 'px;width:100%;max-height:90vh;overflow:auto;padding:20px 22px">' + html + '</div>';
    ov.onclick = e => { if (e.target === ov) ov.remove(); };
    document.body.appendChild(ov); return ov;
  }
  const campo = (rot, h) => '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);flex:1;min-width:140px">' + rot + h + '</label>';
  const inp = (id, v, ph) => '<input id="' + id + '" class="field-input" value="' + E(v || '') + '" placeholder="' + E(ph || '') + '" style="text-transform:none;letter-spacing:0;font-weight:500;font-size:13px">';
  const slc = (id, m, v) => '<select id="' + id + '" class="field-select" style="text-transform:none;letter-spacing:0;font-weight:500;font-size:13px">' + opts(m, v) + '</select>';

  window.pdSiteForm = function (id, clientId) {
    const s = id ? SL.find(x => x.id === id) : { client_id: clientId || FCLI || '', nome: 'Site', papel: 'site', plataforma: 'html', status: 'desconhecido', cms: 'pendente', backend: 'pendente', area_clientes: 'pendente', revisao: 'pendente' };
    const cl = (typeof clients !== 'undefined' ? clients : []).filter(c => c.active !== false).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
    const ov = modal(`<div style="font-weight:700;font-size:15px;margin-bottom:14px">${id ? 'Editar' : 'Novo'} site</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        ${campo('Cliente', '<select id="sf-cli" class="field-select" style="text-transform:none;letter-spacing:0;font-weight:500;font-size:13px"><option value="">Escolha…</option>' + cl.map(c => '<option value="' + c.id + '"' + (c.id === s.client_id ? ' selected' : '') + '>' + E(c.name) + '</option>').join('') + '</select>')}
        ${campo('Nome', inp('sf-nome', s.nome, 'Site, LP Residencial X…'))}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        ${campo('Papel', slc('sf-papel', PAPEL, s.papel))}${campo('Plataforma', slc('sf-plat', PLAT, s.plataforma))}${campo('Situação', slc('sf-st', STAT, s.status))}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        ${campo('Endereço', inp('sf-url', s.url, 'https://…'))}${campo('Hospedagem', inp('sf-host', s.hospedagem, 'hostgator, wix, desconhecida…'))}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        ${campo('Repositório GitHub', inp('sf-repo', s.repo, 'sergioduhdesign/nome'))}${campo('Quem cuida', inp('sf-resp', s.responsavel, 'Juan, cliente…'))}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        ${campo('CMS da PD', slc('sf-cms', CMS, s.cms))}${campo('Backend', slc('sf-be', ESTADO, s.backend))}${campo('Área de clientes', slc('sf-ac', ESTADO, s.area_clientes))}${campo('Revisão', slc('sf-rev', ESTADO, s.revisao))}</div>
      ${campo('Observações', '<textarea id="sf-obs" class="field-input" style="min-height:60px;text-transform:none;letter-spacing:0;font-weight:500;font-size:13px">' + E(s.obs || '') + '</textarea>')}
      <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-top:12px"><input type="checkbox" id="sf-prin" ${s.principal ? 'checked' : ''}> É o site que a PD publica (blog e capa)</label>
      <div style="display:flex;gap:8px;margin-top:16px"><button class="btn btn-primary" id="sf-ok" style="flex:1">Salvar</button><button class="btn btn-secondary" id="sf-no">Cancelar</button></div>`, 640);
    ov.querySelector('#sf-no').onclick = () => ov.remove();
    ov.querySelector('#sf-ok').onclick = async () => {
      const v = i => ov.querySelector('#' + i).value.trim();
      const d = { client_id: v('sf-cli'), nome: v('sf-nome') || 'Site', papel: v('sf-papel'), plataforma: v('sf-plat'), status: v('sf-st'), url: v('sf-url') || null, hospedagem: v('sf-host') || null, repo: v('sf-repo') || null, responsavel: v('sf-resp') || null,
        cms: v('sf-cms'), backend: v('sf-be'), area_clientes: v('sf-ac'), revisao: v('sf-rev'), obs: v('sf-obs') || null };
      if (!d.client_id) return T('Escolha o cliente.', 'warn');
      const querPrin = ov.querySelector('#sf-prin').checked;
      let r;
      if (id) r = await sb.from('pd_site_lista').update(d).eq('id', id).select().single();
      else r = await sb.from('pd_site_lista').insert(d).select().single();
      if (r.error) return T('Não salvei: ' + r.error.message, 'error');
      try { if (querPrin && !r.data.principal) await tornaPrincipal(r.data); else if (r.data.principal && !querPrin) await sb.from('pd_site_lista').update({ principal: false }).eq('id', r.data.id); else if (r.data.principal) await tornaPrincipal(r.data); } catch (e) { T('Salvo, mas o principal não: ' + (e.message || e), 'warn'); }
      ov.remove(); await carrega(); desenha(); try { if ($('mc-sumula') && currentClientId) pdSumulaCliente(currentClientId); } catch (_) {}
      T('Site salvo.', 'success');
    };
  };

  // ------------------------------------------------------------ subir em lote
  const SINON = { cliente: 'cliente', client: 'cliente', nome: 'nome', site: 'nome', titulo: 'nome', papel: 'papel', funcao: 'papel', plataforma: 'plataforma', tecnologia: 'plataforma', cms: 'plataforma', url: 'url', endereco: 'url', link: 'url', hospedagem: 'hospedagem', host: 'hospedagem', repo: 'repo', repositorio: 'repo', github: 'repo', status: 'status', situacao: 'status', backend: 'backend', area_clientes: 'area_clientes', area: 'area_clientes', 'area de clientes': 'area_clientes', revisao: 'revisao', responsavel: 'responsavel', quem: 'responsavel', obs: 'obs', observacao: 'obs', observacoes: 'obs', principal: 'principal', 'cms da pd': 'cmspd' };
  const nPapel = v => { v = norm(v); return /lp|landing/.test(v) ? 'lp' : /area|cliente/.test(v) ? 'area_clientes' : /blog/.test(v) ? 'blog' : 'site'; };
  const nPlat = v => { v = norm(v); return /wp|wordpress/.test(v) ? 'wp' : /wix/.test(v) ? 'wix' : /html|estatic|php|codigo/.test(v) ? 'html' : v ? 'outro' : 'html'; };
  const nStat = v => { v = norm(v); return /no ar|^on$|ativo|publicado|online/.test(v) ? 'no_ar' : /obra|desenvolv|andamento|novo/.test(v) ? 'em_obra' : /fora|^off|offline/.test(v) ? 'fora_do_ar' : 'desconhecido'; };
  const nEst = v => { v = norm(v); return /nao precisa|^na$|n a|dispensa/.test(v) ? 'nao_precisa' : /^ok$|sim|feito|pronto|conclu/.test(v) ? 'ok' : 'pendente'; };
  const nCms = v => { v = norm(v); return /conect/.test(v) ? 'conectado' : /erro/.test(v) ? 'erro' : /nao precisa/.test(v) ? 'nao_precisa' : 'pendente'; };
  const nBool = v => /^(sim|s|1|true|x|principal)$/i.test(String(v || '').trim());

  function linhas(txt, buf) {
    const wb = buf ? XLSX.read(buf, { type: 'array' }) : XLSX.read(txt, { type: 'string' });
    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { defval: '' });
    return rows.map(r => {
      const o = {}; Object.keys(r).forEach(k => { const m = SINON[norm(k)] || SINON[norm(k).replace(/ /g, '_')]; if (m) o[m] = String(r[k]).trim(); });
      return o;
    }).filter(o => o.cliente || o.nome || o.url);
  }
  function acha(nome) {
    const cl = (typeof clients !== 'undefined' ? clients : []).filter(c => c.active !== false), n = norm(nome); if (!n) return null;
    let m = cl.filter(c => norm(c.name) === n); if (m.length === 1) return m[0];
    m = cl.filter(c => norm(c.name).startsWith(n) || n.startsWith(norm(c.name))); if (m.length === 1) return m[0];
    m = cl.filter(c => norm(c.name).includes(n) || n.includes(norm(c.name))); return m.length === 1 ? m[0] : null;
  }
  function paraItem(o, cid) {
    return { client_id: cid, nome: o.nome || 'Site', papel: nPapel(o.papel), plataforma: nPlat(o.plataforma), url: o.url || null, hospedagem: o.hospedagem || null, repo: o.repo || null,
      status: nStat(o.status), cms: nCms(o.cmspd || ''), backend: nEst(o.backend), area_clientes: nEst(o.area_clientes), revisao: nEst(o.revisao), responsavel: o.responsavel || null, obs: o.obs || null, _principal: nBool(o.principal) };
  }

  // a lista inicial do Sérgio (09/11): o que ele citou como pendente fica pendente; o que não citou, "não precisa"
  const NP = 'nao precisa';
  const INICIAL = [
    { cliente: 'SD', nome: 'Pontodot (pontodot.ai)', plataforma: 'html', url: 'https://pontodot.ai', hospedagem: 'hostgator', status: 'no ar', backend: NP, area_clientes: NP, revisao: NP, obs: 'já está no ar' },
    { cliente: 'SD', nome: 'Site SD', plataforma: 'html', url: 'https://sergioduh.com', hospedagem: 'hostgator', status: 'no ar', backend: NP, area_clientes: NP, revisao: NP, obs: 'acertar conexão com o CMS da PD' },
    { cliente: 'Biosphere', nome: 'Site', plataforma: 'html', hospedagem: 'hostgator', status: 'em obra', backend: 'pendente', area_clientes: NP, revisao: NP, obs: 'publicar · acertar backend · conectar ao CMS' },
    { cliente: 'Datastore', nome: 'Site', plataforma: 'html', hospedagem: 'hostgator', status: 'no ar', backend: 'pendente', area_clientes: 'pendente', revisao: NP, obs: 'backend e área de clientes' },
    { cliente: 'ImovelBooks', nome: 'Site', plataforma: 'html', hospedagem: 'hostgator', status: 'no ar', backend: 'pendente', area_clientes: NP, revisao: NP, obs: 'acertar backend' },
    { cliente: 'Grupo Taiwan', nome: 'Site', plataforma: 'outro', hospedagem: 'hostgator', status: 'no ar', backend: NP, area_clientes: NP, revisao: NP, obs: 'só a conexão com o CMS' },
    { cliente: 'Residencial Varanda', nome: 'Site', plataforma: 'outro', hospedagem: 'hostgator', status: 'no ar', backend: NP, area_clientes: NP, revisao: NP, obs: 'só a conexão com o CMS' },
    { cliente: 'TAMO', nome: 'Site', plataforma: 'html', hospedagem: 'hostgator', status: 'no ar', backend: 'pendente', area_clientes: 'pendente', revisao: NP },
    { cliente: 'Mega Contábil', nome: 'Site novo', plataforma: 'html', hospedagem: 'hostgator', status: 'em obra', backend: 'pendente', area_clientes: 'pendente', revisao: NP },
    { cliente: 'Stratesign', nome: 'Site novo', plataforma: 'html', hospedagem: 'hostgator', status: 'em obra', backend: 'pendente', area_clientes: 'pendente', revisao: NP },
    { cliente: 'CS', nome: 'Site', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: NP, responsavel: 'Juan / cliente', obs: 'descobrir onde está' },
    { cliente: '278', nome: 'Site', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: 'pendente', responsavel: 'Juan / cliente', obs: 'descobrir onde está · revisão' },
    { cliente: '278', nome: 'LP', papel: 'lp', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: 'pendente', responsavel: 'Juan / cliente' },
    { cliente: 'RHLA', nome: 'Site', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: 'pendente', responsavel: 'Juan / cliente', obs: 'descobrir onde está · revisão' },
    { cliente: 'RHLA', nome: 'LP', papel: 'lp', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: 'pendente', responsavel: 'Juan / cliente' },
    { cliente: 'Geoville', nome: 'Site', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: NP, responsavel: 'Juan / cliente' },
    { cliente: 'Geoville', nome: 'LPs de produtos', papel: 'lp', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: NP, responsavel: 'Juan / cliente' },
    { cliente: 'VF', nome: 'Site', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: NP, responsavel: 'Juan / cliente' },
    { cliente: 'VF', nome: 'LPs de produtos', papel: 'lp', plataforma: 'outro', hospedagem: 'desconhecida', status: 'nao sei', backend: NP, area_clientes: NP, revisao: NP, responsavel: 'Juan / cliente' },
    { cliente: "L'Atelier", nome: 'Site', plataforma: 'wix', hospedagem: 'wix', status: 'no ar', backend: NP, area_clientes: NP, revisao: NP, obs: 'conectar ao CMS da PD pelo bloco' },
  ];

  let LOTE = [];
  window.pdSitesLote = function () {
    const ov = modal(`<div style="font-weight:700;font-size:15px;margin-bottom:6px">Subir sites em lote</div>
      <div style="font-size:12.5px;color:var(--text3);line-height:1.5;margin-bottom:12px">Uma linha por site ou LP. Colunas: <b>cliente, nome, papel, plataforma, url, hospedagem, repo, status, backend, área de clientes, revisão, responsável, obs, principal</b>. Só <b>cliente</b> é obrigatória. Cole direto da planilha ou suba o arquivo; antes de gravar você confere tudo.</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <label class="btn btn-secondary">Escolher arquivo (.xlsx, .csv)<input type="file" id="sl-arq" accept=".xlsx,.xls,.csv,.txt" style="display:none"></label>
        <button class="btn btn-secondary" id="sl-ini">Carregar minha lista inicial (${INICIAL.length} linhas)</button></div>
      <textarea id="sl-txt" class="field-input" style="width:100%;min-height:90px;font-size:12px" placeholder="…ou cole aqui as linhas da planilha (com o cabeçalho)"></textarea>
      <div id="sl-prev" style="margin-top:12px"></div>
      <div style="display:flex;gap:8px;margin-top:14px"><button class="btn btn-primary" id="sl-ok" style="flex:1" disabled>Gravar</button><button class="btn btn-secondary" id="sl-no">Fechar</button></div>`, 900);
    const prev = ov.querySelector('#sl-prev'), ok = ov.querySelector('#sl-ok');
    const mostra = rows => {
      LOTE = rows.map(o => ({ o, c: acha(o.cliente) }));
      prev.innerHTML = LOTE.length ? '<table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr style="text-align:left;color:var(--text3)"><th>Cliente</th><th>Nome</th><th>Plataforma</th><th>Situação</th><th>Pendências</th></tr></thead><tbody>' + LOTE.map((x, i) => {
        const it = paraItem(x.o, x.c ? x.c.id : '');
        const pend = [it.backend, it.area_clientes, it.revisao].filter(v => v === 'pendente').length + 1;
        return '<tr style="border-top:1px solid var(--border)"><td style="padding:5px 4px">' + (x.c ? '✓ ' + E(x.c.name) : '<select data-i="' + i + '" class="field-select" style="font-size:11.5px"><option value="">⚠ “' + E(x.o.cliente) + '” — escolher…</option><option value="-">pular</option>' + (clients || []).filter(c => c.active !== false).map(c => '<option value="' + c.id + '">' + E(c.name) + '</option>').join('') + '</select>') + '</td><td>' + E(it.nome) + ' <span style="color:var(--text3)">· ' + PAPEL[it.papel] + '</span></td><td>' + PLAT[it.plataforma] + '</td><td>' + STAT[it.status] + '</td><td>' + (pend - 1 ? (pend - 1) + ' + CMS' : 'CMS') + '</td></tr>';
      }).join('') + '</tbody></table>' : '<div style="color:var(--text3);font-size:12.5px">Nenhuma linha reconhecida.</div>';
      prev.querySelectorAll('select[data-i]').forEach(s => s.onchange = () => { const x = LOTE[+s.dataset.i]; x.c = s.value === '-' ? false : (clients.find(c => c.id === s.value) || null); conta(); });
      conta();
    };
    const conta = () => { const n = LOTE.filter(x => x.c).length; ok.disabled = !n; ok.textContent = n ? 'Gravar ' + n + ' site(s)' : 'Gravar'; };
    ov.querySelector('#sl-no').onclick = () => ov.remove();
    ov.querySelector('#sl-ini').onclick = () => mostra(INICIAL.map(o => Object.assign({}, o)));
    ov.querySelector('#sl-txt').oninput = e => { try { mostra(linhas(e.target.value)); } catch (_) {} };
    ov.querySelector('#sl-arq').onchange = async e => { const f = e.target.files[0]; if (!f) return; try { mostra(linhas(null, await f.arrayBuffer())); } catch (er) { T('Não consegui ler o arquivo: ' + er.message, 'error'); } };
    ok.onclick = async () => {
      ok.disabled = true; ok.textContent = 'Gravando…';
      let novos = 0, atual = 0;
      try {
        for (const x of LOTE) {
          if (!x.c) continue;
          const it = paraItem(x.o, x.c.id), querPrin = it._principal; delete it._principal;
          const ja = SL.find(s => s.client_id === it.client_id && ((it.url && norm(s.url) === norm(it.url)) || norm(s.nome) === norm(it.nome)));
          let r;
          if (ja) { const up = Object.assign({}, it); Object.keys(up).forEach(k => { if (up[k] == null || up[k] === '') delete up[k]; }); r = await sb.from('pd_site_lista').update(up).eq('id', ja.id).select().single(); atual++; }
          else { r = await sb.from('pd_site_lista').insert(it).select().single(); novos++; }
          if (r.error) throw r.error;
          if (querPrin) await tornaPrincipal(r.data);
        }
        ov.remove(); await carrega(); desenha(); T(novos + ' novo(s), ' + atual + ' atualizado(s).', 'success');
      } catch (er) { T('Parei no meio: ' + (er.message || er) + (semTabela(er) ? ' — rode o SQL v175.' : ''), 'error'); ok.disabled = false; ok.textContent = 'Tentar de novo'; }
    };
  };

  window.pdSitesModelo = function () {
    const cab = ['cliente', 'nome', 'papel', 'plataforma', 'url', 'hospedagem', 'repo', 'status', 'backend', 'area de clientes', 'revisao', 'responsavel', 'obs', 'principal'];
    const ex = [['Nome do cliente', 'Site', 'site', 'wordpress', 'https://cliente.com.br', 'hostgator', 'sergioduhdesign/cliente', 'no ar', 'ok', 'nao precisa', 'pendente', 'Juan', '', 'sim'], ['Nome do cliente', 'LP Produto X', 'lp', 'html', 'https://lp.cliente.com.br', 'hostgator', '', 'em obra', 'nao precisa', 'nao precisa', 'pendente', '', '', '']];
    const csv = [cab, ...ex].map(l => l.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = 'sites_modelo.csv'; a.click();
  };

  // ------------------------------------------------------------ súmula no perfil do cliente (só lê as fontes únicas)
  window.pdSumulaCliente = async function (cid) {
    const box = $('mc-sumula'); if (!box) return;
    box.innerHTML = '<span style="color:var(--text3);font-size:12px">carregando…</span>';
    const [a, k] = await Promise.all([
      sb.from('pd_site_lista').select('*').eq('client_id', cid).order('principal', { ascending: false }),
      sb.from('brand_kits').select('colors,fonts,logos,elements').eq('client_id', cid).maybeSingle().then(r => r, () => ({ data: null })),
    ]);
    const L = a.data || [], kit = k.data;
    const sitesH = a.error ? '<div style="font-size:12px;color:var(--text3)">' + (semTabela(a.error) ? 'Falta rodar o SQL v175.' : E(a.error.message)) + '</div>' :
      L.map(s => `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 10px;background:var(--surface2);border-radius:8px;font-size:12.5px">
        <b>${s.principal ? '★ ' : ''}${E(s.nome)}</b><span style="color:var(--text3)">${PAPEL[s.papel]} · ${PLAT[s.plataforma]}${s.url ? ' · <a href="' + E(s.url) + '" target="_blank" rel="noopener" style="color:inherit">' + E(s.url.replace(/^https?:\/\//, '')) + '</a>' : ''}</span>
        <span style="margin-left:auto;display:flex;gap:4px;flex-wrap:wrap">${chip(STAT[s.status], s.status)}${chip('CMS ' + CMS[s.cms].toLowerCase(), s.cms)}${s.backend !== 'nao_precisa' ? chip('backend ' + ESTADO[s.backend].toLowerCase(), s.backend) : ''}${s.area_clientes !== 'nao_precisa' ? chip('área ' + ESTADO[s.area_clientes].toLowerCase(), s.area_clientes) : ''}</span>
        ${s.principal ? '<span style="display:flex;gap:4px;width:100%;justify-content:flex-end">' + (s.plataforma === 'wp' ? '<button class="btn btn-secondary btn-sm" onclick="pdSiteCofre(\'' + cid + '\')">Senha do WordPress</button>' : '') + '<button class="btn btn-secondary btn-sm" onclick="pdSiteTestar(\'' + cid + '\',\'' + s.id + '\')">Testar conexão</button><button class="btn btn-secondary btn-sm" onclick="pdSiteBloco(\'' + cid + '\')">Copiar bloco</button></span>' : ''}
      </div>`).join('') || '<div style="font-size:12px;color:var(--text3)">Nenhum site cadastrado.</div>';
    const nC = kit ? (kit.colors || []).filter(c => c && c.hex).length : 0, nF = kit ? (kit.fonts || []).filter(f => f && f.name).length : 0, nL = kit ? Object.values(kit.logos || {}).filter(l => l && l.file_url).length : 0, nE = kit ? (kit.elements || []).length : 0;
    const dsOk = nC >= 2 && nF >= 1 && nL >= 1;
    box.innerHTML = `
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><div class="field-label" style="margin:0">Sites e LPs (${L.length})</div>
        <button class="btn btn-secondary btn-sm" style="margin-left:auto" onclick="pdSiteForm(null,'${cid}')">+ Novo</button>
        <button class="btn btn-secondary btn-sm" onclick="closeModal('modal-client');setView('sites');">Painel de Sites</button></div>
      <div style="display:flex;flex-direction:column;gap:6px">${sitesH}</div>
      <div style="display:flex;align-items:center;gap:8px;margin:16px 0 8px"><div class="field-label" style="margin:0">Design system</div>${chip(dsOk ? 'completo' : 'incompleto', dsOk ? 'ok' : 'pendente')}
        <span style="font-size:12px;color:var(--text3)">${nC} cores · ${nF} fontes · ${nL} logos · ${nE} elementos</span>
        <button class="btn btn-secondary btn-sm" style="margin-left:auto" onclick="mcAba('marca')">Abrir Marca</button>
        <button class="btn btn-secondary btn-sm" onclick="closeModal('modal-client');setView('ds');">Painel de DS</button></div>`;
  };

  // ------------------------------------------------------------ painel Design System
  let DSK = {};
  window.pdDsRender = async function () {
    const box = $('view-ds'); if (!box) return;
    box.innerHTML = '<div style="padding:24px;color:var(--text3)">Carregando…</div>';
    const { data, error } = await sb.from('brand_kits').select('client_id,colors,fonts,logos,elements,grids,notes,updated_at');
    DSK = {}; (data || []).forEach(k => DSK[k.client_id] = k);
    const cl = (typeof clients !== 'undefined' ? clients : []).filter(c => c.active !== false).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
    const th = 'style="text-align:left;padding:8px 10px;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text3)"';
    const linhaCli = c => {
      const k = DSK[c.id] || {}, cores = (k.colors || []).filter(x => x && x.hex), fontes = (k.fonts || []).filter(f => f && f.name), logos = Object.entries(k.logos || {}).filter(([, l]) => l && l.file_url), els = k.elements || [];
      const ok = cores.length >= 2 && fontes.length >= 1 && logos.length >= 1;
      return `<tr style="border-top:1px solid var(--border)"><td style="padding:8px 10px;font-weight:600;white-space:nowrap">${E(c.name)}</td>
        <td style="padding:8px 10px"><div style="display:flex;gap:3px;flex-wrap:wrap;max-width:200px">${cores.slice(0, 10).map(x => '<span title="' + E(x.name || x.hex) + '" style="width:18px;height:18px;border-radius:50%;background:' + E(x.hex) + ';border:1px solid var(--border)"></span>').join('') || '<span style="color:var(--text3);font-size:12px">—</span>'}</div></td>
        <td style="padding:8px 10px;font-size:12px">${fontes.map(f => E(f.name)).join(', ') || '<span style="color:var(--text3)">—</span>'}</td>
        <td style="padding:8px 10px"><div style="display:flex;gap:4px">${logos.map(([n, l]) => '<img title="' + E(n) + '" src="' + E(l.file_url) + '" style="height:24px;max-width:56px;object-fit:contain;background:#fff;border-radius:4px;padding:2px">').join('') || '<span style="color:var(--text3);font-size:12px">—</span>'}</div></td>
        <td style="padding:8px 10px;font-size:12px">${els.length || '—'}</td>
        <td style="padding:8px 10px">${chip(ok ? 'completo' : 'incompleto', ok ? 'ok' : 'pendente')}</td>
        <td style="padding:8px 10px"><button class="btn btn-secondary btn-sm" onclick="openClientDetail('${c.id}');mcAba('marca')">Abrir</button></td></tr>`;
    };
    const completos = cl.filter(c => { const k = DSK[c.id] || {}; return (k.colors || []).filter(x => x && x.hex).length >= 2 && (k.fonts || []).filter(f => f && f.name).length >= 1 && Object.values(k.logos || {}).some(l => l && l.file_url); }).length;
    box.innerHTML = `
      <div class="page-header"><div><div class="page-title">Design System</div><div class="page-subtitle">${completos} de ${cl.length} clientes com o design system completo · cores, fontes, logos e elementos</div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" onclick="pdDsLote()">Subir em lote</button></div></div>
      ${error ? '<div style="margin:0 16px 12px;padding:12px;border-radius:10px;background:var(--surface2);font-size:12.5px">' + E(error.message) + '</div>' : ''}
      <div style="overflow:auto;padding:0 8px 40px"><table class="list-tbl" style="width:100%;min-width:900px"><thead><tr><th ${th}>Cliente</th><th ${th}>Cores</th><th ${th}>Fontes</th><th ${th}>Logos</th><th ${th}>Elementos</th><th ${th}>Situação</th><th></th></tr></thead><tbody>${cl.map(linhaCli).join('')}</tbody></table></div>`;
  };

  // lote de DS: arraste a pasta "Cliente/…" (ou arquivos "Cliente__logo-principal.svg")
  const FONTE_EXT = /\.(ttf|otf|woff2?)$/i, IMG_EXT = /\.(svg|png|jpe?g|webp)$/i;
  function classifica(rel) {
    const partes = rel.split('/').filter(Boolean); let cli, resto;
    if (partes.length > 1) { cli = partes[0]; resto = partes.slice(1); }
    else { const m = partes[0].match(/^(.+?)__(.+)$/); if (!m) return null; cli = m[1]; resto = [m[2]]; }
    const arq = resto[resto.length - 1], pasta = norm(resto.slice(0, -1).join(' ')), nome = norm(arq);
    if (/^(cores|colors|paleta)\.(csv|txt|json)$/i.test(arq)) return { cli, tipo: 'cores', arq };
    if (/^(notas|notes|manual)\.(txt|md)$/i.test(arq)) return { cli, tipo: 'notas', arq };
    if (FONTE_EXT.test(arq)) return { cli, tipo: 'fonte', arq };
    if (IMG_EXT.test(arq)) {
      if (/element|grafism|padrao|pattern/.test(pasta + ' ' + nome)) return { cli, tipo: 'elemento', arq };
      if (/logo/.test(pasta + ' ' + nome) || /simbolo|negativ|horizontal|principal/.test(nome)) {
        const slot = /horizontal/.test(nome + pasta) ? 'horizontal' : /simbolo|symbol|icon/.test(nome + pasta) ? 'simbolo' : /negativ|branc|white|reverse/.test(nome + pasta) ? 'negativo' : 'principal';
        return { cli, tipo: 'logo', slot, arq };
      }
    }
    return { cli, tipo: 'ignorado', arq };
  }
  function lerCores(txt) {
    const out = [];
    try { const j = JSON.parse(txt); (Array.isArray(j) ? j : Object.entries(j).map(([name, hex]) => ({ name, hex }))).forEach(c => { const h = String(c.hex || c.color || '').match(/#?([0-9a-f]{6})\b/i); if (h) out.push({ name: c.name || '#' + h[1].toUpperCase(), hex: '#' + h[1].toUpperCase(), cmyk: c.cmyk || undefined }); }); return out; } catch (_) {}
    String(txt).split(/\r?\n/).forEach(l => { const h = l.match(/#?([0-9a-f]{6})\b/i); if (!h) return; const nome = l.split(/[;,\t]/)[0].replace(/#?[0-9a-f]{6}\b/i, '').trim(); const cm = l.match(/(\d{1,3})[ ,]+(\d{1,3})[ ,]+(\d{1,3})[ ,]+(\d{1,3})\s*$/); out.push({ name: nome || '#' + h[1].toUpperCase(), hex: '#' + h[1].toUpperCase(), cmyk: cm ? cm.slice(1).join(' ') : undefined }); });
    return out;
  }

  let DSL = {};
  window.pdDsLote = function () {
    const ov = modal(`<div style="font-weight:700;font-size:15px;margin-bottom:6px">Subir Design System em lote</div>
      <div style="font-size:12.5px;color:var(--text3);line-height:1.6;margin-bottom:12px">Escolha a <b>pasta com uma subpasta por cliente</b>, assim:<br>
      <code style="font-size:11.5px">Cliente/logos/principal.svg · horizontal.svg · simbolo.svg · negativo.svg</code><br>
      <code style="font-size:11.5px">Cliente/fontes/*.ttf .otf .woff</code> · <code style="font-size:11.5px">Cliente/elementos/*.svg .png</code><br>
      <code style="font-size:11.5px">Cliente/cores.csv</code> (nome;#hex;C M Y K, um por linha) · <code style="font-size:11.5px">Cliente/notas.txt</code><br>
      Também vale solto, com o cliente no nome: <code style="font-size:11.5px">Cliente__logo-principal.svg</code>. Antes de gravar você confere o que entra em cada cliente.</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <label class="btn btn-secondary">Escolher pasta<input type="file" id="ds-dir" webkitdirectory multiple style="display:none"></label>
        <label class="btn btn-secondary">Escolher arquivos<input type="file" id="ds-arq" multiple style="display:none"></label></div>
      <div id="ds-prev"></div>
      <div style="display:flex;gap:8px;margin-top:14px"><button class="btn btn-primary" id="ds-ok" style="flex:1" disabled>Gravar</button><button class="btn btn-secondary" id="ds-no">Fechar</button></div>`, 860);
    const prev = ov.querySelector('#ds-prev'), ok = ov.querySelector('#ds-ok'); DSL = {};
    const conta = () => { const n = Object.values(DSL).filter(g => g.c).length; ok.disabled = !n; ok.textContent = n ? 'Gravar ' + n + ' cliente(s)' : 'Gravar'; };
    const mostra = files => {
      DSL = {};
      files.forEach(f => {
        const k = classifica(f.webkitRelativePath || f.name); if (!k) return;
        const g = DSL[norm(k.cli)] = DSL[norm(k.cli)] || { nome: k.cli, c: acha(k.cli), fontes: [], logos: {}, elems: [], cores: null, notas: null, ign: 0 };
        k.f = f;
        if (k.tipo === 'fonte') g.fontes.push(k); else if (k.tipo === 'logo') g.logos[k.slot] = k; else if (k.tipo === 'elemento') g.elems.push(k); else if (k.tipo === 'cores') g.cores = k; else if (k.tipo === 'notas') g.notas = k; else g.ign++;
      });
      const gs = Object.entries(DSL);
      prev.innerHTML = gs.length ? '<table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr style="text-align:left;color:var(--text3)"><th>Cliente</th><th>Logos</th><th>Fontes</th><th>Elementos</th><th>Cores</th><th>Notas</th><th>Ignorados</th></tr></thead><tbody>' + gs.map(([key, g]) =>
        '<tr style="border-top:1px solid var(--border)"><td style="padding:5px 4px">' + (g.c ? '✓ ' + E(g.c.name) : '<select data-k="' + key + '" class="field-select" style="font-size:11.5px"><option value="">⚠ “' + E(g.nome) + '” — escolher…</option><option value="-">pular</option>' + clients.filter(c => c.active !== false).map(c => '<option value="' + c.id + '">' + E(c.name) + '</option>').join('') + '</select>') + '</td>' +
        '<td>' + (Object.keys(g.logos).join(', ') || '—') + '</td><td>' + (g.fontes.length || '—') + '</td><td>' + (g.elems.length || '—') + '</td><td>' + (g.cores ? '✓' : '—') + '</td><td>' + (g.notas ? '✓' : '—') + '</td><td style="color:var(--text3)">' + (g.ign || '') + '</td></tr>').join('') + '</tbody></table>' : '<div style="color:var(--text3);font-size:12.5px">Nenhum arquivo reconhecido. Confira a estrutura de pastas.</div>';
      prev.querySelectorAll('select[data-k]').forEach(s => s.onchange = () => { DSL[s.dataset.k].c = s.value === '-' ? false : (clients.find(c => c.id === s.value) || null); conta(); });
      conta();
    };
    ov.querySelector('#ds-no').onclick = () => ov.remove();
    ov.querySelector('#ds-dir').onchange = e => mostra([...e.target.files]);
    ov.querySelector('#ds-arq').onchange = e => mostra([...e.target.files]);
    ok.onclick = async () => {
      ok.disabled = true; const antes = currentClientId; let feitos = 0;
      try {
        for (const g of Object.values(DSL)) {
          if (!g.c) continue;
          ok.textContent = 'Subindo ' + g.c.name + '…';
          currentClientId = g.c.id;      // mcArquivoSobe guarda em brand-assets/<pasta>/<cliente>/…
          const { data: ex } = await sb.from('brand_kits').select('*').eq('client_id', g.c.id).maybeSingle();
          const kit = ex || { client_id: g.c.id, colors: [], fonts: [], logos: {}, elements: [], grids: [], notes: '' };
          kit.logos = kit.logos || {}; kit.fonts = kit.fonts || []; kit.elements = kit.elements || []; kit.colors = kit.colors || [];
          for (const [slot, k] of Object.entries(g.logos)) { const url = await mcArquivoSobe(k.f, 'logos'); kit.logos[slot] = { file_url: url, file_name: k.f.name }; }
          for (const k of g.fontes) { if (kit.fonts.some(f => f.file_name === k.f.name)) continue; const url = await mcArquivoSobe(k.f, 'fonts'); kit.fonts.push({ name: k.f.name.replace(FONTE_EXT, '').replace(/[-_ ](regular|bold|black|light|italic|medium|semibold|extrabold|thin)/ig, '').replace(/[-_]+/g, ' ').trim(), file_url: url, file_name: k.f.name }); }
          for (const k of g.elems) { if (kit.elements.some(e => e.file_name === k.f.name)) continue; const url = await mcArquivoSobe(k.f, 'elements'); kit.elements.push({ file_url: url, file_name: k.f.name }); }
          if (g.cores) lerCores(await g.cores.f.text()).forEach(c => { const i = kit.colors.findIndex(x => (x.hex || '').toUpperCase() === c.hex); if (i >= 0) kit.colors[i] = Object.assign({}, kit.colors[i], c); else kit.colors.push(c); });
          if (g.notas) { const t = (await g.notas.f.text()).trim(); if (t && !String(kit.notes || '').includes(t)) kit.notes = (kit.notes ? kit.notes + '\n\n' : '') + t; }
          const { error } = await sb.from('brand_kits').upsert({ client_id: g.c.id, colors: kit.colors, fonts: kit.fonts, logos: kit.logos, elements: kit.elements, grids: kit.grids || [], notes: kit.notes || '', updated_at: new Date().toISOString() }, { onConflict: 'client_id' });
          if (error) throw error; feitos++;
        }
        ov.remove(); T('Design system gravado em ' + feitos + ' cliente(s).', 'success'); pdDsRender();
      } catch (e) { T('Parei no meio: ' + (e.message || e), 'error'); ok.disabled = false; ok.textContent = 'Tentar de novo'; }
      finally { currentClientId = antes; }
    };
  };
})();
