/* Arquivos do site (HostGator pelo cPanel) — só admin. Caixa branca central, seta de voltar; backup antes de cada gravação, com desfazer. */
window.pdArquivosAbrir = function (cid) {
  if (!cid) return;
  const old = document.getElementById('arqVeu'); if (old) old.remove();
  const e = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const api = b => sdFetch('/pd-arquivos.php', Object.assign({ client_id: cid, escopo }, b));
  let rel = '', aberto = null, orig = '', escopo = 'conta';
  const v = document.createElement('div'); v.id = 'arqVeu';
  v.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(10,10,12,.55);backdrop-filter:blur(8px);overflow:auto;padding:24px 16px';
  v.innerHTML = `<div style="max-width:1100px;margin:0 auto;background:#fff;color:#15161A;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.35);overflow:hidden;font:14px/1.45 -apple-system,Helvetica,Arial,sans-serif">
    <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #E4E3DE;position:sticky;top:0;background:#fff;z-index:2">
      <button id="aV" title="Voltar" style="width:36px;height:36px;border:1px solid #D9D8D2;border-radius:10px;background:#fff;cursor:pointer;font-size:18px">‹</button>
      <b style="font-size:16px">Arquivos do site</b><span id="aSt" style="font-size:12px;color:#6B6D75"></span><span style="flex:1"></span>
      <button id="aE" title="Alterna entre a raiz da conta (todos os sites) e só a pasta do site deste cliente" style="border:1px solid #D9D8D2;background:#fff;border-radius:10px;padding:7px 12px;cursor:pointer"></button>
      <button id="aH" style="border:1px solid #D9D8D2;background:#fff;border-radius:10px;padding:7px 12px;cursor:pointer">Histórico</button>
      <button id="aS" style="border:0;background:#15161A;color:#fff;border-radius:10px;padding:8px 16px;cursor:pointer;display:none">Gravar no site</button></div>
    <div id="aC" style="padding:16px"></div></div>`;
  document.body.appendChild(v);
  const $ = s => v.querySelector(s), st = t => $('#aSt').textContent = t || '';
  const fecha = () => { if (aberto && $('#aT') && $('#aT').value !== orig && !confirm('Há mudanças não gravadas. Sair mesmo?')) return; v.remove(); document.removeEventListener('keydown', esc); };
  const esc = ev => { if (ev.key === 'Escape') fecha(); }; document.addEventListener('keydown', esc);
  $('#aV').onclick = () => { if (aberto) { aberto = null; lista(rel); } else fecha(); };
  async function lista(p) {
    rel = p || ''; aberto = null; $('#aS').style.display = 'none'; st('lendo a pasta…');
    try {
      const r = await api({ acao: 'listar', caminho: rel }); st('');
      const partes = rel ? rel.split('/') : [];
      const mig = '<a href="#" data-p="" style="color:#1a73e8">site</a>' + partes.map((x, i) => ' / <a href="#" data-p="' + e(partes.slice(0, i + 1).join('/')) + '" style="color:#1a73e8">' + e(x) + '</a>').join('');
      $('#aC').innerHTML = '<div style="margin-bottom:10px;font-size:13px">' + mig + ' <span style="color:#6B6D75">· ' + e(r.raiz) + '</span></div>' +
        '<div style="border:1px solid #E4E3DE;border-radius:12px;overflow:hidden">' + (r.itens.map(x => '<div class="aL" data-n="' + e(x.nome) + '" data-d="' + (x.pasta ? 1 : 0) + '" data-ed="' + (x.editavel ? 1 : 0) + '" style="display:flex;gap:10px;padding:9px 14px;border-bottom:1px solid #F0EFEA;cursor:' + (x.pasta || x.editavel ? 'pointer' : 'default') + ';opacity:' + (x.pasta || x.editavel ? 1 : .45) + '"><span>' + (x.pasta ? '📁' : '📄') + '</span><span style="flex:1">' + e(x.nome) + '</span><span style="color:#6B6D75;font-size:12px">' + (x.pasta ? '' : (x.tam > 1024 ? Math.round(x.tam / 1024) + ' KB' : x.tam + ' B')) + '</span></div>').join('') || '<div style="padding:14px;color:#6B6D75">Pasta vazia.</div>') + '</div>' +
        '<div style="margin-top:10px;font-size:12px;color:#6B6D75">Abre só texto (html, css, js, php, txt…) até 1 MB. Imagens, zips, wp-config e senhas ficam fechados. Cada gravação guarda o antes e dá para desfazer.</div>';
      v.querySelectorAll('[data-p]').forEach(a => a.onclick = ev => { ev.preventDefault(); lista(a.dataset.p); });
      v.querySelectorAll('.aL').forEach(l => l.onclick = () => { const n = l.dataset.n, c = (rel ? rel + '/' : '') + n; if (l.dataset.d === '1') lista(c); else if (l.dataset.ed === '1') abre(c); });
    } catch (er) { st(''); $('#aC').innerHTML = '<div style="color:#b42318">' + e(er.message) + '</div>'; }
  }
  async function abre(c) {
    st('abrindo…');
    try {
      const r = await api({ acao: 'ler', caminho: c }); st(''); aberto = c; orig = r.conteudo; $('#aS').style.display = '';
      $('#aC').innerHTML = '<div style="margin-bottom:8px;font-size:13px"><b>' + e(c) + '</b></div><textarea id="aT" spellcheck="false" style="width:100%;height:62vh;font:12.5px/1.5 ui-monospace,Menlo,monospace;border:1px solid #D9D8D2;border-radius:10px;padding:12px;box-sizing:border-box;resize:vertical;color:#15161A;background:#FAFAF8"></textarea>';
      $('#aT').value = r.conteudo;
    } catch (er) { st(er.message); }
  }
  $('#aS').onclick = async () => {
    if (!aberto) return; const nv = $('#aT').value;
    if (nv === orig) return st('Nada mudou.');
    if (!confirm('Gravar ' + aberto + ' no site? O antes fica guardado e dá para desfazer.')) return;
    $('#aS').disabled = true; st('gravando…');
    try { await api({ acao: 'gravar', caminho: aberto, conteudo: nv }); orig = nv; st('✓ gravado (antes guardado no histórico)'); }
    catch (er) { st('✗ ' + er.message); } finally { $('#aS').disabled = false; }
  };
  $('#aH').onclick = async () => {
    aberto = null; $('#aS').style.display = 'none'; st('lendo o histórico…');
    try {
      const r = await api({ acao: 'historico' }); st('');
      $('#aC').innerHTML = '<div style="font-weight:600;margin-bottom:8px">Últimas gravações</div>' + (r.itens.map(h => '<div style="display:flex;gap:10px;align-items:center;padding:9px 12px;border-bottom:1px solid #F0EFEA"><span style="flex:1">' + e(h.caminho) + (h.existia ? '' : ' <i style="color:#6B6D75">(novo)</i>') + '</span><span style="font-size:12px;color:#6B6D75">' + new Date(h.criado_em).toLocaleString('pt-BR') + '</span>' + (h.desfeito ? '<span style="font-size:12px;color:#6B6D75">desfeito</span>' : (h.existia ? '<button data-u="' + h.id + '" style="border:1px solid #D9D8D2;background:#fff;border-radius:8px;padding:5px 10px;cursor:pointer">Desfazer</button>' : '')) + '</div>').join('') || '<div style="color:#6B6D75">Nada gravado ainda.</div>');
      v.querySelectorAll('[data-u]').forEach(b => b.onclick = async () => { if (!confirm('Voltar este arquivo para o texto de antes?')) return; try { await api({ acao: 'desfazer', id: b.dataset.u }); st('✓ desfeito'); $('#aH').click(); } catch (er) { st('✗ ' + er.message); } });
    } catch (er) { st(''); $('#aC').innerHTML = '<div style="color:#b42318">' + e(er.message) + '</div>'; }
  };
  const rot = () => $('#aE').textContent = escopo === 'conta' ? 'Raiz da conta ▾' : 'Só o site ▾';
  $('#aE').onclick = () => { if (aberto && $('#aT').value !== orig && !confirm('Há mudanças não gravadas. Trocar mesmo?')) return; escopo = escopo === 'conta' ? 'site' : 'conta'; rot(); lista(''); };
  rot(); lista('');
};
