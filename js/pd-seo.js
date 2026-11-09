/* =====================================================================
   Pontodot — SEO do site do cliente (WordPress): auditar, sugerir com IA, aprovar e aplicar, desfazer.
   Abre pelo perfil do cliente (aba Canais → "SEO") ou pelo painel de Sites.
   Servidor: pd-seo.php. Nada é gravado no site sem aprovação; tudo guarda o "antes".
   ===================================================================== */
(function () {
  'use strict';
  const E = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const T = (m, t) => { try { showToast(m, t || 'info'); } catch (_) {} };
  const COR = { alta: '#dc2626', media: '#d97706', baixa: '#8e8e93' };
  const CAMPO = { titulo_seo: 'Título SEO', meta: 'Meta description', alt: 'Texto alternativo da imagem' };
  const corNota = n => n >= 80 ? '#16a34a' : n >= 55 ? '#d97706' : '#dc2626';
  let CID = null, ov = null, AUD = null, PROPS = {}, HIST = [];

  window.pdSeoAbrir = async function (cid) {
    CID = cid; AUD = null; PROPS = {}; HIST = [];
    if (ov) ov.remove();
    ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:99700;background:rgba(10,10,12,.6);backdrop-filter:blur(6px);display:flex;align-items:flex-start;justify-content:center;padding:3vh 14px;overflow:auto';
    ov.onclick = e => { if (e.target === ov) ov.remove(); };
    document.body.appendChild(ov);
    desenha();
    try { const h = await sdFetch('/pd-seo.php', { acao: 'historico', client_id: cid }); HIST = h.propostas || []; desenha(); } catch (_) {}
  };

  function chip(t, c) { return '<span style="display:inline-block;padding:1px 8px;border-radius:20px;font-size:10.5px;font-weight:700;color:' + c + ';border:1px solid ' + c + '55;margin:2px 3px 0 0">' + E(t) + '</span>'; }

  function desenha() {
    if (!ov) return;
    const nome = (typeof clients !== 'undefined' ? (clients.find(c => c.id === CID) || {}).name : '') || '';
    const R = AUD && AUD.resumo, hist = HIST.filter(p => p.status === 'aplicada');
    let h = `<div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;max-width:980px;width:100%;padding:20px 22px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="font-weight:700;font-size:16px">SEO — ${E(nome)}</div>
        <button class="btn btn-primary btn-sm" id="seo-aud" style="margin-left:auto">${AUD ? 'Auditar de novo' : 'Auditar agora'}</button>
        <button class="btn btn-secondary btn-sm" id="seo-plug" title="Instala o plugin auxiliar direto no site pela HostGator">Instalar plugin no site</button>
        <button class="btn btn-secondary btn-sm" id="seo-x">Fechar</button></div>`;
    if (!AUD) {
      h += `<div id="seo-msg" style="font-size:13px;color:var(--text3);line-height:1.6">A auditoria lê o site como o Google lê (cada página, o robots.txt e o sitemap) e aponta título, meta description, H1, imagens sem alt, canonical, noindex, schema, conteúdo repetido e velocidade. Depois a IA propõe as correções; você vê o antes e o depois e aprova o que quiser. Nada muda no site sem a sua aprovação, e tudo pode ser desfeito.</div>`;
    } else {
      h += `<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;margin-bottom:12px">
        <div style="font-size:34px;font-weight:800;color:${corNota(R.nota)};line-height:1">${R.nota}<span style="font-size:13px;color:var(--text3);font-weight:600">/100</span></div>
        <div style="font-size:12.5px;line-height:1.7"><b>${R.paginas_lidas}</b> páginas lidas de ${R.paginas_no_site} · plugin de SEO: <b>${E({ yoast: 'Yoast', rankmath: 'Rank Math', aioseo: 'All in One SEO', nenhum: 'nenhum' }[R.plugin] || R.plugin)}</b><br>
        ${chip(R.problemas.alta + ' graves', COR.alta)}${chip(R.problemas.media + ' médios', COR.media)}${chip(R.problemas.baixa + ' leves', COR.baixa)}</div></div>`;
      if (R.site.length) h += '<div style="margin-bottom:12px;font-size:12.5px;line-height:1.6"><div class="field-label">Do site inteiro</div>' + R.site.map(s => '<div>' + chip(s[1], COR[s[1]]) + E(s[2]) + '</div>').join('') + '</div>';
      h += '<div class="field-label">Páginas (pior primeiro)</div><div style="display:flex;flex-direction:column;gap:8px">' + AUD.itens.map((it, i) => linha(it, i)).join('') + '</div>';
    }
    if (hist.length) {
      h += '<div class="field-label" style="margin-top:18px">Já aplicado (dá para desfazer)</div><div style="display:flex;flex-direction:column;gap:6px">' + hist.slice(0, 20).map(p =>
        `<div style="display:flex;gap:8px;align-items:center;font-size:12px;padding:6px 10px;background:var(--surface2);border-radius:8px"><div style="flex:1;min-width:0"><b>${CAMPO[p.campo]}</b> · <span style="color:var(--text3)">${E((p.url || '').replace(/^https?:\/\//, ''))}</span><div style="color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${E(p.depois)}</div></div><button class="btn btn-secondary btn-sm" data-undo="${p.id}">Desfazer</button></div>`).join('') + '</div>';
    }
    h += '</div>';
    ov.innerHTML = h;
    ov.querySelector('#seo-x').onclick = () => { ov.remove(); ov = null; };
    ov.querySelector('#seo-aud').onclick = auditar;
    ov.querySelector('#seo-plug').onclick = baixaPlugin;
    ov.querySelectorAll('[data-sug]').forEach(b => b.onclick = () => sugerir(+b.dataset.sug));
    ov.querySelectorAll('[data-ap]').forEach(b => b.onclick = () => aplicar(+b.dataset.ap));
    ov.querySelectorAll('[data-rec]').forEach(b => b.onclick = () => recusar(+b.dataset.rec));
    ov.querySelectorAll('[data-undo]').forEach(b => b.onclick = () => desfaz(b.dataset.undo));
  }

  function linha(it, i) {
    const corrig = it.problemas.some(p => p[3]);
    const props = PROPS[it.url];
    const titulo = it.analise && it.analise.titulo || it.titulo_wp;
    let h = `<div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div style="min-width:42px;text-align:center;font-weight:800;font-size:17px;color:${corNota(it.nota)}">${it.nota}</div>
        <div style="flex:1;min-width:180px"><div style="font-weight:600;font-size:13px">${E(titulo || '(sem título)')}</div>
          <a href="${E(it.url)}" target="_blank" rel="noopener" style="font-size:11px;color:var(--text3)">${E(it.url.replace(/^https?:\/\//, ''))}</a></div>
        ${corrig ? '<button class="btn btn-primary btn-sm" data-sug="' + i + '">Corrigir com IA</button>' : ''}</div>
      <div style="margin-top:4px">${it.problemas.map(p => chip(p[2], COR[p[1]])).join('') || '<span style="font-size:12px;color:#16a34a">Sem problemas encontrados.</span>'}</div>`;
    if (props) {
      h += props.length ? '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px">' + props.map(p =>
        `<div style="background:var(--surface2);border-radius:8px;padding:9px 11px;font-size:12px">
          <div style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-pk="${p.id}" ${p.status === 'proposta' || p.status === 'erro' ? 'checked' : 'disabled'}> <b>${CAMPO[p.campo]}</b>${p.status === 'aplicada' ? chip('aplicado', '#16a34a') : ''}${p.status === 'recusada' ? chip('recusado', '#8e8e93') : ''}${p.status === 'erro' ? chip('erro', '#dc2626') : ''}</div>
          ${p.antes ? '<div style="color:var(--text3);margin:4px 0 2px;text-decoration:line-through">' + E(p.antes) + '</div>' : '<div style="color:var(--text3);margin:4px 0 2px">(vazio)</div>'}
          <textarea data-pt="${p.id}" class="field-input" style="width:100%;min-height:${p.campo === 'meta' ? 54 : 36}px;font-size:12.5px" ${p.status === 'aplicada' ? 'disabled' : ''}>${E(p.depois)}</textarea>
          <div style="display:flex;justify-content:space-between;color:var(--text3);font-size:11px"><span>${(p.depois || '').length} caracteres${p.campo === 'titulo_seo' ? ' (ideal 40–60)' : p.campo === 'meta' ? ' (ideal 130–155)' : ''}</span><span>${E(p.motivo || '')}</span></div>
          ${p.erro ? '<div style="color:#dc2626;margin-top:4px">' + E(p.erro) + '</div>' : ''}</div>`).join('') +
        '<div style="display:flex;gap:8px"><button class="btn btn-primary btn-sm" data-ap="' + i + '">Aprovar e aplicar marcadas</button><button class="btn btn-secondary btn-sm" data-rec="' + i + '">Recusar</button></div></div>'
        : '<div style="margin-top:8px;font-size:12px;color:var(--text3)">A IA achou que não há o que mudar nesta página.</div>';
    }
    return h + '</div>';
  }

  async function auditar() {
    const b = ov.querySelector('#seo-aud'); b.disabled = true; b.textContent = 'Lendo o site… (até 1–2 min)';
    try { AUD = await sdFetch('/pd-seo.php', { acao: 'auditar', client_id: CID }); PROPS = {}; desenha(); }
    catch (e) { b.disabled = false; b.textContent = 'Auditar agora'; T('SEO: ' + (e.message || e), 'error'); }
  }
  async function sugerir(i) {
    const it = AUD.itens[i]; const b = ov.querySelector('[data-sug="' + i + '"]'); b.disabled = true; b.textContent = 'IA pensando…';
    try {
      const r = await sdFetch('/pd-seo.php', { acao: 'sugerir', client_id: CID, tipo: it.tipo, wp_id: it.wp_id, url: it.url });
      PROPS[it.url] = r.propostas || []; desenha();
    } catch (e) { b.disabled = false; b.textContent = 'Corrigir com IA'; T('SEO: ' + (e.message || e), 'error'); }
  }
  async function aplicar(i) {
    const it = AUD.itens[i], card = ov.querySelectorAll('[data-sug]')[0] ? null : null;
    const marcadas = [...ov.querySelectorAll('[data-pk]')].filter(c => c.checked && (PROPS[it.url] || []).some(p => p.id === c.dataset.pk));
    if (!marcadas.length) return T('Marque o que quer aplicar.', 'warn');
    const edicoes = {}; marcadas.forEach(c => { edicoes[c.dataset.pk] = ov.querySelector('[data-pt="' + c.dataset.pk + '"]').value; });
    if (!confirm('Gravar ' + marcadas.length + ' mudança(s) no site? Dá para desfazer depois.')) return;
    try {
      const r = await sdFetch('/pd-seo.php', { acao: 'aplicar', client_id: CID, ids: marcadas.map(c => c.dataset.pk), edicoes });
      const falhas = r.resultado.filter(x => !x.ok);
      PROPS[it.url] = PROPS[it.url].map(p => { const x = r.resultado.find(y => y.id === p.id); return x ? Object.assign({}, p, x.ok ? { status: 'aplicada', depois: edicoes[p.id] } : { status: 'erro', erro: x.erro }) : p; });
      const h = await sdFetch('/pd-seo.php', { acao: 'historico', client_id: CID }); HIST = h.propostas || [];
      desenha();
      if (falhas.length) {
        T(falhas.length + ' não gravou: ' + falhas[0].erro, 'error');
        if (/PLUGIN_AUXILIAR/.test(falhas[0].erro)) T('Baixe o “Plugin auxiliar” e coloque em wp-content/mu-plugins/ deste site.', 'warn');
      } else T('Aplicado no site.', 'success');
    } catch (e) { T('SEO: ' + (e.message || e), 'error'); }
  }
  async function recusar(i) {
    const it = AUD.itens[i], ids = (PROPS[it.url] || []).filter(p => p.status === 'proposta').map(p => p.id);
    try { if (ids.length) await sdFetch('/pd-seo.php', { acao: 'recusar', client_id: CID, ids }); } catch (_) {}
    delete PROPS[it.url]; desenha();
  }
  async function desfaz(id) {
    if (!confirm('Voltar este campo ao valor de antes?')) return;
    try { await sdFetch('/pd-seo.php', { acao: 'reverter', client_id: CID, id }); const h = await sdFetch('/pd-seo.php', { acao: 'historico', client_id: CID }); HIST = h.propostas || []; desenha(); T('Desfeito.', 'success'); }
    catch (e) { T('Não desfez: ' + (e.message || e), 'error'); }
  }
  async function baixaPlugin() {
    const b = ov.querySelector('#seo-plug'); b.disabled = true; b.textContent = 'Instalando…';
    try {
      await sdFetch('/pd-seo.php', { acao: 'instalar_plugin', client_id: CID });
      T('Plugin instalado no site pela HostGator.', 'success'); b.textContent = '✓ Plugin instalado'; return;
    } catch (e) {
      b.disabled = false; b.textContent = 'Instalar plugin no site';
      if (!/cofre|HostGator|cPanel|dom[ií]nio/i.test(e.message || '')) return T('Plugin: ' + (e.message || e), 'error');
      T((e.message || e) + ' — guarde o acesso da HostGator no cofre (perfil do cliente → Canais). Baixando o arquivo para instalar na mão.', 'warn');
    }
    try {
      const r = await sdFetch('/pd-seo.php', { acao: 'plugin' });
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([r.fonte], { type: 'text/x-php' })); a.download = r.nome; a.click();
      T('Coloque em wp-content/mu-plugins/ (crie a pasta se não existir). Faz uma vez por site.', 'info');
    } catch (e) { T('Plugin: ' + (e.message || e), 'error'); }
  }
})();
