// PD · LEGENDA CURTA — toque num botão e uma caixinha diz, em poucas palavras, o que ele faz.
// No Estúdio a caixa é a do rodapé (#rodLeg); nas outras ferramentas, uma pílula flutuante embaixo.
(function(){
  const CUT = /\s*(?:[:·—(]|\s-\s)/;
  window.pdLegCurta = function(b){
    let t = (b.dataset && b.dataset.leg) || b.getAttribute('aria-label') || b.getAttribute('title') || (b.textContent || '');
    t = String(t).replace(/\s+/g, ' ').trim();
    const m = t.search(CUT); if (m > 2) t = t.slice(0, m).trim();
    if (t.length > 24) t = t.slice(0, 23).trim() + '…';
    return t;
  };
  const SEL = 'button,.btn,[role="button"]';
  let pill = null, tm = null;
  document.addEventListener('pointerdown', e => {
    if (document.getElementById('rodLeg') || e.target.closest('#pdTF,#pdSheet,.pdTFcx')) return;
    const b = e.target.closest && e.target.closest(SEL); if (!b) return;
    const t = window.pdLegCurta(b); if (!t) return;
    if (!pill){
      const st = document.createElement('style');
      st.textContent = '#pdLegPill{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translateX(-50%);max-width:86vw;padding:9px 14px;border-radius:6px;background:rgba(18,18,18,.94);color:#fff;font:500 12.5px/1.2 "Helvetica Neue",system-ui,sans-serif;white-space:nowrap;z-index:100040;pointer-events:none;opacity:0;transition:opacity .22s ease}#pdLegPill.on{opacity:1}';
      document.head.appendChild(st); pill = document.createElement('div'); pill.id = 'pdLegPill'; document.body.appendChild(pill);
    }
    pill.textContent = t; pill.classList.add('on'); clearTimeout(tm); tm = setTimeout(() => pill.classList.remove('on'), 1800);
  }, true);
})();
