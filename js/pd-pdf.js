/* =====================================================================
   PD · MOTOR DE PDF DA CASA  (window.PDPDF.gerar)
   Um só padrão para TODO PDF da plataforma (relatórios, listas, aprovações):
     · capa preta: logo da PD em branco + logo ORIGINAL do cliente numa bolinha branca + título, descrição, data, cliente
     · páginas com cabeçalho (bolinha do cliente + título + data) e rodapé (logo da PD + página)
     · formato EM PÉ 9:16 (leitura no celular) ou WIDE 16:9 (material de tela, ex.: grade de artes)
     · NUNCA corta um bloco no meio: cada bloco é medido antes; se não cabe, vai inteiro para a página seguinte;
       título de seção (h2/h3) sempre junto do primeiro bloco dele.
   Atributos no HTML do conteúdo:
     data-pag      começa página nova (um conteúdo por página)
     data-imh="N"  se o bloco não couber, reduz a variável --imh (altura máx. da imagem) de N até caber
     data-fs="N"   (wide) bloco de altura fixa: reduz --fs (fonte do [data-tx]) até o texto caber
   gerar({ titulo, sub, clienteNome, logoUrl, agNome, corpo, opts }) -> Promise<{ blob, nome }>
   opts: { wide:true (em pé mais largo) | formato:'wide' (16:9), css, rotulo, nota, extraCapa }
   ===================================================================== */
(function(){
'use strict';
const LIBS = [['https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', () => window.html2canvas],
              ['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', () => window.jspdf && window.jspdf.jsPDF]];
const carrega = (src, teste) => teste() ? Promise.resolve() : new Promise((ok, no) => { const t = document.createElement('script'); t.src = src; t.onload = ok; t.onerror = () => no(new Error('Não consegui carregar a biblioteca de PDF.')); document.head.appendChild(t); });
const esc = x => String(x == null ? '' : x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const escopa = css => String(css || '').replace(/@page\{[^}]*\}/g, '').replace(/(^|\})\s*([^{}@]+)\{/g, (m, a, sel) => a + sel.split(',').map(x => { x = x.trim(); return x === '*' ? '.pdx,.pdx *' : x === 'body' ? '.pdx' : '.pdx ' + x; }).join(',') + '{');
// o html2canvas erra SVG sem tamanho: o logo vira PNG antes
const rast = (u, h) => new Promise(ok => { const im = new Image(); im.onload = () => { try { const w = Math.round(h * (im.naturalWidth || 4) / (im.naturalHeight || 1)) || h * 4, c = document.createElement('canvas'); c.width = w * 3; c.height = h * 3; c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); ok(c.toDataURL('image/png')); } catch(e){ ok(u); } }; im.onerror = () => ok(u); im.src = u; });

const CSS = `*{box-sizing:border-box}body{font:14px/1.5 "Helvetica Neue",Helvetica,Arial,sans-serif;color:#15161A;margin:0}
  h2{font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#6B6D75;margin:22px 0 10px;font-weight:600}
  p{margin:0 0 8px}
  .kk{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:9px}.k{background:#F4F3EF;border-radius:12px;padding:11px 13px;min-width:0}.k small{display:block;color:#6B6D75;font-size:10px;text-transform:uppercase;letter-spacing:.08em;line-height:1.25}.k b{display:block;font-size:20px;font-weight:600;margin-top:3px;overflow-wrap:anywhere;line-height:1.2}
  .row{display:flex;gap:13px;align-items:flex-start;padding:14px 0;border-top:1px solid #E4E3DE}.row img.th{width:84px;height:84px;border-radius:50%;object-fit:cover;background:#EEE;flex:0 0 84px}
  .row .c{flex:1;min-width:0}.row b.n{font-size:14.5px;line-height:1.35;overflow-wrap:anywhere}.row p{margin:3px 0 0;color:#55575F}
  .row .kk{margin-top:10px;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.row .k{padding:8px 10px}.row .k small{font-size:9px;letter-spacing:.05em}.row .k b{font-size:15px}
  .cp{font-size:10.5px;padding:2px 8px;border-radius:99px;background:#15161A;color:#fff;margin-left:6px;vertical-align:2px;white-space:nowrap}.cp.p{background:#E4E3DE;color:#15161A}
  table{width:100%;border-collapse:collapse}td{padding:7px 4px;border-top:1px solid #ECEBE6;vertical-align:top;overflow-wrap:anywhere}td:last-child{text-align:right}
  .rod{margin-top:26px;padding-top:12px;border-top:1px solid #E4E3DE;color:#6B6D75;font-size:11px;line-height:1.5}.rod b{color:#15161A;font-weight:600}.rod .leg{display:block;margin-top:5px;color:#8A8C94;font-size:9.5px}`;

async function gerar(cfg){
  cfg = cfg || {}; const o = cfg.opts || {}, wide16 = o.formato === 'wide';
  const agNome = cfg.agNome || 'SD Studio Design', clienteNome = cfg.clienteNome || '', titulo = cfg.titulo || 'Relatório', sub = cfg.sub || '';
  const agora = new Date(), dataLonga = agora.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' }), hora = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  const W = wide16 ? 960 : (o.wide ? 720 : 540), H = wide16 ? 540 : Math.round(W * 16 / 9), PAD = wide16 ? 40 : Math.round(W * 0.055), TOPO = wide16 ? 54 : 64, RODA = wide16 ? 42 : 54;
  const maxH = H - TOPO - RODA - 8, CW = W - 2 * PAD;
  const ini = esc((clienteNome || 'PD').replace(/[^\p{L}\p{N} ]/gu, '').split(/\s+/).filter(Boolean).slice(0, 2).map(s => s[0]).join('').toUpperCase() || 'PD');
  const logo = cfg.logoUrl ? `<img crossorigin="anonymous" src="${esc(cfg.logoUrl)}" alt="">` : `<span>${ini}</span>`;
  const sz = f => Math.round(W * f);
  const BASE = `.pdx{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif}
  .pdx .pp{position:relative;width:${W}px;height:${H}px;overflow:hidden;background:#fff;color:#15161A}
  .pdx .pp-h{position:absolute;left:${PAD}px;right:${PAD}px;top:0;height:${TOPO}px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #E4E3DE;font-size:11px;color:#6B6D75}
  .pdx .pp-h .mini{width:28px;height:28px;border-radius:50%;background:#fff;border:1px solid #E4E3DE;display:grid;place-items:center;overflow:hidden;flex:0 0 28px}.pdx .pp-h .mini img{width:100%;height:100%;object-fit:contain;padding:3px}.pdx .pp-h .mini span{font-size:9px;font-weight:700;color:#15161A}
  .pdx .pp-h b{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#15161A;font-weight:600;font-size:12px}
  .pdx .pp-c{position:absolute;left:${PAD}px;right:${PAD}px;top:${TOPO + 4}px;bottom:${RODA}px;overflow:hidden}
  .pdx .pp-f{position:absolute;left:${PAD}px;right:${PAD}px;bottom:0;height:${RODA}px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #E4E3DE;font-size:10.5px;color:#8A8C94}.pdx .pp-f img{height:13px;width:53px;opacity:.55;display:block}
  .pdx .capa{background:#0B0C0D;color:#fff;padding:${PAD + 8}px ${PAD + 6}px}
  .pdx .capa .pl{height:${sz(wide16 ? 0.035 : 0.07)}px;width:${Math.round(sz(wide16 ? 0.035 : 0.07) * 4.058)}px;display:block}
  .pdx .capa .mid{position:absolute;left:${PAD + 6}px;right:${PAD + 6}px;top:50%;transform:translateY(-52%)}
  .pdx .capa .av{width:${wide16 ? 96 : sz(0.27)}px;height:${wide16 ? 96 : sz(0.27)}px;border-radius:50%;background:#fff;display:grid;place-items:center;overflow:hidden;box-shadow:0 0 0 7px rgba(255,255,255,.07),0 0 0 8px rgba(255,255,255,.18)}
  .pdx .capa .av.canto{position:absolute;right:${PAD + 14}px;top:${PAD + 10}px}.pdx .capa .av.meio{margin-bottom:${sz(0.09)}px}
  .pdx .capa .av img{width:100%;height:100%;object-fit:contain;padding:${wide16 ? 12 : sz(0.04)}px}.pdx .capa .av span{font-size:${wide16 ? 30 : sz(0.08)}px;font-weight:600;color:#0B0C0D;letter-spacing:.02em}
  .pdx .capa .rot{font-size:11px;letter-spacing:.24em;text-transform:uppercase;color:rgba(255,255,255,.55);margin-bottom:14px}
  .pdx .capa h1{font-size:${wide16 ? 44 : sz(0.078)}px;line-height:1.1;font-weight:300;letter-spacing:-.02em;margin:0;color:#fff;word-break:break-word;${wide16 ? 'max-width:760px' : ''}}
  .pdx .capa .sub{margin-top:16px;font-size:${wide16 ? 15 : sz(0.03)}px;line-height:1.5;color:rgba(255,255,255,.62);${wide16 ? 'max-width:720px' : ''}}
  .pdx .capa .fim{position:absolute;left:${PAD + 6}px;right:${PAD + 6}px;bottom:${PAD + 4}px;border-top:1px solid rgba(255,255,255,.18);padding-top:16px;display:flex;justify-content:space-between;gap:14px;font-size:11.5px;line-height:1.5;color:rgba(255,255,255,.62)}
  .pdx .capa .fim b{display:block;color:#fff;font-weight:500;font-size:12.5px}`;
  const host = document.createElement('div'); host.style.cssText = 'position:fixed;left:-99999px;top:0;background:#fff';
  const rodape = o.semRodape ? '' : `<div class="rod"><b>Gerado pela pontodot para a ${esc(agNome)}${clienteNome ? ' · Cliente ' + esc(clienteNome) : ''}</b><br>${esc(dataLonga)}, ${esc(hora)}<span class="leg">${o.nota || 'Dados obtidos da Meta (Facebook, Instagram e Meta Ads) por conexão autorizada pelo titular das contas, por meio das APIs oficiais da Meta, apenas para leitura e somente para as contas vinculadas a este cliente. Informações confidenciais, de uso restrito à agência e ao cliente indicados; a plataforma não publica, altera nem compartilha esses dados com terceiros. Números sujeitos à atualização e à apuração da própria Meta.'}</span></div>`;
  host.innerHTML = `<style>${escopa(CSS)}${escopa(o.css || '')}${BASE}</style><div class="pdx"><div class="pp"><div class="pp-c" id="pdMede" style="position:static;width:${CW}px;overflow:visible;margin:0 ${PAD}px">${cfg.corpo || ''}${rodape}</div></div></div>`;
  document.body.appendChild(host);
  try {
    await Promise.all(LIBS.map(l => carrega(l[0], l[1])));
    const [lgBranco, lgPreto] = await Promise.all([rast(location.origin + '/assets/logo-pontodot-white.svg', 100), rast(location.origin + '/assets/logo-pontodot.svg', 100)]);
    const aguardaImgs = raiz => Promise.all([...raiz.querySelectorAll('img')].map(im => im.complete ? 0 : new Promise(r => { im.onload = im.onerror = r; setTimeout(r, 6000); })));
    await aguardaImgs(host);
    const mede = host.querySelector('#pdMede');
    const hOf = e => { const cs = getComputedStyle(e), r = e.getBoundingClientRect(); return r.height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0); };
    // ajustes automáticos antes de medir: imagem menor / fonte menor até caber numa página
    [...mede.children].forEach(u => {
      if (u.hasAttribute('data-fs')){ u.style.height = maxH + 'px'; let f = Number(u.dataset.fs) || 12; const tx = u.querySelector('[data-tx]'); u.style.setProperty('--fs', f + 'px');
        while (tx && tx.scrollHeight > tx.clientHeight + 1 && f > 8.5){ f -= .5; u.style.setProperty('--fs', f + 'px'); } }
      else if (u.hasAttribute('data-imh')){ let v = Number(u.dataset.imh) || 420; u.style.setProperty('--imh', v + 'px'); while (hOf(u) > maxH && v > 150){ v -= 30; u.style.setProperty('--imh', v + 'px'); } if (hOf(u) > maxH) u.style.setProperty('--imh', Math.min(Number(u.dataset.imh) || 420, 360) + 'px'); }
    });
    // blocos que nunca são cortados
    const quebra = new WeakSet();
    const unidades = (pai, out) => { [...pai.children].forEach(n => {
      if (hOf(n) <= maxH || !n.children.length){ out.push(n); return; }
      const fim = out.length;
      if (n.tagName === 'TABLE'){ [...n.querySelectorAll('tr')].forEach(tr => { const t = n.cloneNode(false), b = document.createElement('tbody'); b.appendChild(tr.cloneNode(true)); t.appendChild(b); mede.appendChild(t); out.push(t); }); }
      else unidades(n, out);
      if (n.hasAttribute('data-pag') && out[fim]) quebra.add(out[fim]); }); return out; };
    const un = [];   // data-pag direto no filho do corpo; os demais seguem o fluxo
    [...mede.children].forEach(n => { const antes = un.length; unidades({ children: [n] }, un); if (n.hasAttribute('data-pag') && un[antes]) quebra.add(un[antes]); });
    un.forEach(e => { if (hOf(e) > maxH){ e.style.maxHeight = (maxH - 4) + 'px'; e.style.overflow = 'hidden'; } if (getComputedStyle(e).display === 'inline') e.style.display = 'block'; });
    const grupos = []; for (let i = 0; i < un.length; i++){ const e = un[i]; if (/^H[1-4]$/.test(e.tagName) && un[i + 1] && !quebra.has(un[i + 1])){ grupos.push([e, un[i + 1]]); i++; } else grupos.push([e]); }
    const paginas = []; let atual = [], uso = 0;
    grupos.forEach(g => { const h = g.reduce((s, e) => s + hOf(e), 0); if (atual.length && (uso + h > maxH || quebra.has(g[0]))){ paginas.push(atual); atual = []; uso = 0; } atual.push(...g); uso += h; });
    if (atual.length) paginas.push(atual);
    const total = paginas.length + 1;
    const capa = document.createElement('div'); capa.className = 'pp capa';
    capa.innerHTML = `<img class="pl" src="${lgBranco}" alt="pontodot">${wide16 ? `<div class="av canto">${logo}</div>` : ''}
      <div class="mid">${wide16 ? '' : `<div class="av meio">${logo}</div>`}<div class="rot">${esc(o.rotulo || 'Relatório')}</div><h1>${esc(titulo)}</h1><div class="sub">${sub}</div>${o.extraCapa || ''}</div>
      <div class="fim"><div>${clienteNome ? '<b>' + esc(clienteNome) + '</b>' : ''}Cliente</div><div style="text-align:right"><b>${esc(dataLonga)}</b>${esc(agNome)}</div></div>`;
    const pags = [capa];
    paginas.forEach((us, i) => { const p = document.createElement('div'); p.className = 'pp';
      p.innerHTML = `<div class="pp-h"><span class="mini">${logo}</span><b>${esc(titulo)}</b><span>${esc(dataLonga)}</span></div><div class="pp-c"></div><div class="pp-f"><img src="${lgPreto}" alt="pontodot"><span>${i + 2} / ${total}</span></div>`;
      const c = p.querySelector('.pp-c'); us.forEach(e => c.appendChild(e));
      const f = c.firstElementChild; if (f){ if (f.classList.contains('row')) f.style.borderTop = '0'; if (/^H[1-4]$/.test(f.tagName)) f.style.marginTop = '4px'; }
      pags.push(p); });
    const raiz = host.querySelector('.pdx'); raiz.innerHTML = ''; pags.forEach(p => { p.style.marginBottom = '8px'; raiz.appendChild(p); });
    await aguardaImgs(host);
    const mm = W * 0.2, hh = H * 0.2; let pdf = null;
    for (let i = 0; i < pags.length; i++){
      const cv = await window.html2canvas(pags[i], { scale: 2, useCORS: true, backgroundColor: i === 0 ? '#0B0C0D' : '#ffffff', width: W, height: H, windowWidth: W, logging: false });
      const ori = wide16 ? 'landscape' : 'portrait';
      if (!pdf) pdf = new window.jspdf.jsPDF({ unit: 'mm', format: [mm, hh], orientation: ori, compress: true }); else pdf.addPage([mm, hh], ori);
      pdf.addImage(cv.toDataURL('image/jpeg', .92), 'JPEG', 0, 0, mm, hh, undefined, 'FAST');
    }
    pdf.setProperties({ title: titulo + (clienteNome ? ' — ' + clienteNome : ''), author: agNome, creator: 'pontodot' });
    return { blob: pdf.output('blob'), nome: (titulo + (clienteNome ? ' — ' + clienteNome : '') + ' — ' + agora.toLocaleDateString('pt-BR')).replace(/[\/:*?"<>|]/g, '-') + '.pdf' };
  } finally { host.remove(); }
}
window.PDPDF = { gerar };
})();
