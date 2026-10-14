/* Pontodot — BLOCO EMBUTIDO
 * Cola uma vez em qualquer site (HTML, Wix, o que for) e os últimos
 * posts aprovados aparecem sozinhos, no tema do próprio site:
 *
 *   <div id="pd-bloco"></div>
 *   <script src="https://sd.sergioduh.com/pd-bloco.js"
 *           data-feed="/pd-sites.php?feed=...&k=..." defer></script>
 */
(function(){
  'use strict';
  var sc = document.currentScript;
  if (!sc) return;
  var feed = sc.getAttribute('data-feed');
  if (!feed) return;
  if (feed.charAt(0) === '/') feed = (sc.src.split('/').slice(0, 3).join('/')) + feed;
  var alvo = document.getElementById(sc.getAttribute('data-alvo') || 'pd-bloco');
  if (!alvo) { alvo = document.createElement('div'); sc.parentNode.insertBefore(alvo, sc); }
  var css = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;font:inherit';
  fetch(feed).then(function(r){ return r.json(); }).then(function(j){
    var posts = (j && j.posts) || [];
    if (!posts.length) return;
    alvo.setAttribute('style', css);
    alvo.innerHTML = posts.slice(0, Number(sc.getAttribute('data-max') || 6)).map(function(p){
      var img = p.imagem ? '<div style="aspect-ratio:16/10;overflow:hidden;border-radius:10px;margin-bottom:10px">' +
        '<img src="' + p.imagem + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover"></div>' : '';
      return '<a href="' + (p.url || '#') + '" style="text-decoration:none;color:inherit;display:block">' + img +
        '<div style="font-weight:600;line-height:1.3;margin-bottom:5px">' + (p.titulo || '') + '</div>' +
        '<div style="opacity:.7;font-size:.9em;line-height:1.5">' + (p.resumo || '') + '</div></a>';
    }).join('');
  }).catch(function(){});
})();
