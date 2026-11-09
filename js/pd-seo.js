/* SEO: a ferramenta mora em seo.html (caixa "SEO" na home da PD). Aqui, só o atalho do perfil do cliente. */
window.pdSeoAbrir = function (cid) {
  if (!cid) return;
  const old = document.getElementById('seoVeuApp'); if (old) old.remove();
  const v = document.createElement('div'); v.id = 'seoVeuApp';
  v.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#0B0C0B';
  const f = document.createElement('iframe');
  f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:transparent';
  f.src = '/seo.html?cid=' + encodeURIComponent(cid) + '&v=' + Date.now();
  v.appendChild(f); document.body.appendChild(v);
  const fecha = e => { if (e.data && e.data.tipo === 'seo-fechar') { v.remove(); window.removeEventListener('message', fecha); } };
  window.addEventListener('message', fecha);
};
