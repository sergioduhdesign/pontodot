/* PAINEL DA CAMPANHA — mostra o setup (campanha, persona, públicos, anúncios) no topo do aprovador.
   Usado no link do cliente (ver.html) e na tela da equipe (aprovacao.html): um desenho só.
   O cliente pode SUGERIR ajuste por seção; a equipe vê e marca como resolvido.
   Formato dos dados = o do Excel de setup (js/campanha-setup.js): { campanha, persona, publicos, anuncios, avisos }. */
window.CampPainel = (function () {
  const T = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const SECOES = { campanha: 'Campanha', persona: 'Persona', publicos: 'Públicos', anuncios: 'Anúncios' };
  const OBJ = { OUTCOME_LEADS: 'Leads', OUTCOME_TRAFFIC: 'Tráfego', OUTCOME_ENGAGEMENT: 'Engajamento', OUTCOME_AWARENESS: 'Reconhecimento', OUTCOME_SALES: 'Vendas' };
  let css = false;
  function estilo() {
    if (css) return; css = true;
    const s = document.createElement('style');
    s.textContent = `
.cpn{max-width:1180px;margin:14px auto 0;padding:0 clamp(12px,3vw,28px)}
.cpn>details{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.cpn>details>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 18px}
.cpn>details>summary::-webkit-details-marker{display:none}
.cpn .cpn-k{font-size:10.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--accent)}
.cpn .cpn-n{font-size:16px;font-weight:800;letter-spacing:-.01em;flex:1;min-width:160px}
.cpn .cpn-chip{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);color:var(--text2)}
.cpn .cpn-seta{font-size:12px;color:var(--text3);margin-left:auto}
.cpn>details[open] .cpn-seta::after{content:'Recolher'}
.cpn>details:not([open]) .cpn-seta::after{content:'Ver detalhes'}
.cpn-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);border-top:1px solid var(--border)}
.cpn-sec{background:var(--surface);padding:14px 18px 12px;min-width:0}
.cpn-sec.larga{grid-column:1/-1}
.cpn-sec h4{margin:0 0 8px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--text3)}
.cpn-l{font-size:13px;line-height:1.5;margin:0 0 4px;color:var(--text)}
.cpn-l b{font-weight:700;color:var(--text2)}
.cpn-pub{padding:8px 0;border-top:1px solid var(--border);font-size:13px;line-height:1.45}
.cpn-pub:first-of-type{border-top:0;padding-top:0}
.cpn-an{padding:9px 0;border-top:1px solid var(--border);font-size:12.5px;line-height:1.45}
.cpn-an:first-of-type{border-top:0}
.cpn-an .t{font-weight:700}
.cpn-an .x{color:var(--text2);white-space:pre-line}
.cpn-mais summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text2);padding:4px 0}
.cpn-aj{margin-top:10px;padding-top:9px;border-top:1px dashed var(--border)}
.cpn-ajit{font-size:12px;line-height:1.45;padding:7px 10px;border-radius:8px;background:var(--surface2);margin-bottom:6px}
.cpn-ajit.ok{opacity:.55}
.cpn-ajit small{display:block;color:var(--text3);margin-top:2px}
.cpn-btn{font:inherit;font-size:12px;font-weight:700;padding:6px 12px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer}
.cpn-btn.p{background:var(--accent);color:var(--accent-fg);border-color:transparent}
.cpn-form{margin-top:8px;display:none}
.cpn-form.on{display:block}
.cpn-form textarea{width:100%;min-height:70px;box-sizing:border-box;padding:9px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;resize:vertical}
.cpn-form .acoes{display:flex;gap:8px;margin-top:6px}
@media (max-width:760px){.cpn-grid{grid-template-columns:1fr}}`;
    document.head.appendChild(s);
  }
  const lin = (r, v) => v ? `<p class="cpn-l"><b>${r}:</b> ${T(v)}</p>` : '';

  function secaoHtml(chave, corpo, ajustes, o) {
    const meus = (ajustes || []).filter(a => a.secao === chave);
    const lista = meus.map(a => `<div class="cpn-ajit ${a.resolvido ? 'ok' : ''}">${T(a.texto)}<small>${T(a.por || 'Cliente')}${a.em ? ' · ' + new Date(a.em).toLocaleDateString('pt-BR') : ''}${a.resolvido ? ' · resolvido' : ' · enviado para a equipe'}${o.podeResolver && !a.resolvido ? ` · <a href="#" data-resolver="${T(a.id)}" style="color:var(--accent);font-weight:700">marcar como resolvido</a>` : ''}</small></div>`).join('');
    const botao = o.podeSugerir ? `<button class="cpn-btn" data-abrir="${chave}">Sugerir ajuste</button>
      <div class="cpn-form" data-form="${chave}"><textarea placeholder="O que você quer mudar em ${SECOES[chave].toLowerCase()}?"></textarea><div class="acoes"><button class="cpn-btn p" data-enviar="${chave}">Enviar para a equipe</button><button class="cpn-btn" data-fechar="${chave}">Cancelar</button></div></div>` : '';
    return `<div class="cpn-sec ${chave === 'anuncios' || chave === 'publicos' ? 'larga' : ''}"><h4>${SECOES[chave]}</h4>${corpo}${(lista || botao) ? `<div class="cpn-aj">${lista}${botao}</div>` : ''}</div>`;
  }

  /** o = { dados, ajustes, podeSugerir, onSugerir(secao, texto), podeResolver, onResolver(id) } */
  function montar(alvo, o) {
    estilo();
    const d = o.dados;
    if (!d || !d.campanha) { alvo.innerHTML = ''; return; }
    const c = d.campanha, p = d.persona || {}, pubs = d.publicos || [], ans = d.anuncios || [];
    const br = v => String(v || '').replace(/^(\d{4})-(\d{2})-(\d{2}).*$/, '$3/$2/$1');
    const periodo = [c.inicio, c.fim].filter(Boolean).map(br).join(' a ');
    const temPersona = p.nome || p.quem || p.busca || p.dores || p.gatilhos || p.tom || p.referencias;
    const pend = (o.ajustes || []).filter(a => !a.resolvido).length;
    const sCamp = secaoHtml('campanha', `${lin('Objetivo', OBJ[c.objetivo] || c.objetivo)}${lin('Período', periodo)}${lin('Formulário de leads', c.formulario)}${lin('WhatsApp', c.whatsapp)}${lin('Link de destino', c.link)}${!(c.objetivo || periodo || c.formulario || c.whatsapp || c.link) ? '<p class="cpn-l" style="color:var(--text3)">Sem detalhes informados.</p>' : ''}`, o.ajustes, o);
    const sPers = secaoHtml('persona', temPersona ? `${lin('Nome', p.nome)}${lin('Quem é', p.quem)}${lin('O que busca', p.busca)}${lin('Dores e objeções', p.dores)}${lin('Gatilhos de decisão', p.gatilhos)}${lin('Tom de voz', p.tom)}${lin('Referências', p.referencias)}` : '<p class="cpn-l" style="color:var(--text3)">Sem persona informada.</p>', o.ajustes, o);
    const sPubs = secaoHtml('publicos', pubs.map(u => `<div class="cpn-pub"><b>${T(u.conjunto)}</b>${u.persona ? ' · ' + T(u.persona) : ''}<br><span style="color:var(--text2)">${[u.cidades && u.cidades.length ? T(u.cidades.join(', ')) + (u.raio ? ' (raio ' + T(u.raio) + ' km)' : '') : '', u.interesses && u.interesses.length ? 'Interesses: ' + T(u.interesses.join(', ')) : '', 'R$ ' + T(u.verba) + '/dia', u.horarios && u.horarios.length ? u.horarios.length + ' faixa(s) de horário' : ''].filter(Boolean).join(' · ')}</span></div>`).join('') || '<p class="cpn-l" style="color:var(--text3)">Sem públicos informados.</p>', o.ajustes, o);
    const linAn = a => `<div class="cpn-an"><span class="t">${T(a.anuncio)}</span> <span style="color:var(--text3)">· ${T(a.conjunto)}${a.botao ? ' · botão: ' + T(a.botao) : ''}</span>${a.titulo ? `<div><b>${T(a.titulo)}</b></div>` : ''}${a.texto ? `<div class="x">${T(a.texto)}</div>` : ''}</div>`;
    const sAns = secaoHtml('anuncios', ans.length > 6 ? `<details class="cpn-mais"><summary>Ver os ${ans.length} anúncios (texto, título e botão)</summary>${ans.map(linAn).join('')}</details>` : (ans.map(linAn).join('') || '<p class="cpn-l" style="color:var(--text3)">Sem anúncios informados.</p>'), o.ajustes, o);
    alvo.innerHTML = `<div class="cpn"><details open><summary><span class="cpn-k">Campanha</span><span class="cpn-n">${T(c.nome || 'Sem nome')}</span>
      ${c.objetivo ? `<span class="cpn-chip">${T(OBJ[c.objetivo] || c.objetivo)}</span>` : ''}<span class="cpn-chip">${pubs.length} público(s)</span><span class="cpn-chip">${ans.length} anúncio(s)</span>${pend ? `<span class="cpn-chip" style="background:var(--accent);color:var(--accent-fg);border-color:transparent">${pend} ajuste(s) sugerido(s)</span>` : ''}<span class="cpn-seta"></span></summary>
      <div class="cpn-grid">${sCamp}${sPers}${sPubs}${sAns}</div></details></div>`;
    alvo.querySelectorAll('[data-abrir]').forEach(b => b.onclick = () => { alvo.querySelector(`[data-form="${b.dataset.abrir}"]`).classList.add('on'); b.style.display = 'none'; });
    alvo.querySelectorAll('[data-fechar]').forEach(b => b.onclick = () => { const f = alvo.querySelector(`[data-form="${b.dataset.fechar}"]`); f.classList.remove('on'); alvo.querySelector(`[data-abrir="${b.dataset.fechar}"]`).style.display = ''; });
    alvo.querySelectorAll('[data-enviar]').forEach(b => b.onclick = async () => {
      const f = alvo.querySelector(`[data-form="${b.dataset.enviar}"]`), txt = f.querySelector('textarea').value.trim(); if (!txt) return;
      b.disabled = true; try { await o.onSugerir(b.dataset.enviar, txt); } catch (e) { b.disabled = false; alert('Não consegui enviar: ' + (e.message || e)); }
    });
    alvo.querySelectorAll('[data-resolver]').forEach(a => a.onclick = async ev => { ev.preventDefault(); try { await o.onResolver(a.dataset.resolver); } catch (e) { alert(e.message || e); } });
  }
  return { montar };
})();
