/* PD · abertura das ferramentas: logo de app no centro, versão pequena embaixo, some com fade quando a ferramenta está pronta */
(function(){
  try { if (self === top) return; } catch(e){}
  var v = ''; try { v = String(parent.SD_VERSAO || parent.parent.SD_VERSAO || '').replace(/\s*·.*$/, ''); } catch(e){}
  var dark = false; try { dark = matchMedia('(prefers-color-scheme:dark)').matches; } catch(e){}
  var o = document.createElement('div'); o.id = 'pd-abre';
  o.style.cssText = 'position:fixed;inset:0;z-index:2147483500;display:flex;flex-direction:column;align-items:center;justify-content:center;background:' + (dark ? '#0c0c0d' : '#f5f5f3') + ';transition:opacity .45s ease;opacity:1';
  o.innerHTML = '<img src="/assets/logo-pontodot-bolinha' + (dark ? '-white' : '') + '.svg" alt="PD" style="width:84px;height:84px;border-radius:22px;animation:pdAb 1.6s ease-in-out infinite">'
    + '<div style="position:absolute;bottom:calc(env(safe-area-inset-bottom,0px) + 26px);font:500 11px/1 \'Helvetica Neue\',system-ui,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:' + (dark ? 'rgba(255,255,255,.4)' : 'rgba(0,0,0,.38)') + '">' + (v || 'pontodot') + '</div>'
    + '<style>@keyframes pdAb{0%,100%{transform:scale(1);opacity:.9}50%{transform:scale(1.05);opacity:1}}</style>';
  (document.body || document.documentElement).appendChild(o);
  var t0 = Date.now(), fim = function(){ var f = function(){ o.style.opacity = 0; setTimeout(function(){ o.remove(); }, 480); }; var d = 650 - (Date.now() - t0); d > 0 ? setTimeout(f, d) : f(); };
  if (document.readyState === 'complete') setTimeout(fim, 250); else addEventListener('load', function(){ setTimeout(fim, 250); });
  setTimeout(fim, 6000);
})();
