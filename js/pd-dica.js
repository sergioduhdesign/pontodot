/* PD · dica de ícone no celular: segurar um ícone do cabeçalho (~0,45 s) mostra o nome embaixo, sem executar a ação */
(function(){
  var t = null, box = null;
  function mostra(txt){
    if (!box) { box = document.createElement('div'); box.style.cssText = 'position:fixed;left:50%;bottom:calc(env(safe-area-inset-bottom,0px) + 22px);transform:translateX(-50%);max-width:82vw;padding:9px 15px;border-radius:10px;background:#f2f2f0;color:#121212;font:500 13px/1.3 "Helvetica Neue",system-ui,sans-serif;z-index:2147483000;opacity:0;transition:opacity .2s;pointer-events:none;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.35)'; document.body.appendChild(box); }
    box.textContent = txt.length > 70 ? txt.slice(0, 68) + '…' : txt; box.style.opacity = 1; clearTimeout(box._h); box._h = setTimeout(function(){ box.style.opacity = 0; }, 1700);
  }
  function alvo(e){ return e.target && e.target.closest ? e.target.closest('.top button[title],.top select[title]') : null; }
  document.addEventListener('touchstart', function(e){ var b = alvo(e); if (!b) return; clearTimeout(t); t = setTimeout(function(){ b.__lp = 1; mostra(b.getAttribute('title') || ''); }, 450); }, { passive: true });
  ['touchend', 'touchmove', 'touchcancel'].forEach(function(ev){ document.addEventListener(ev, function(){ clearTimeout(t); }, { passive: true }); });
  document.addEventListener('click', function(e){ var b = alvo(e); if (b && b.__lp) { b.__lp = 0; e.preventDefault(); e.stopPropagation(); } }, true);
  document.addEventListener('contextmenu', function(e){ if (alvo(e)) e.preventDefault(); });
})();
