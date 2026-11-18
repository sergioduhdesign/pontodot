/* pd-leve · plataforma leve: relógios curtos não rodam com a aba escondida.
   Só vale para intervalos < 20 s (os que repintam tela ou consultam a toda hora).
   Fica de fora quem precisa rodar sempre: o relógio de expediente/ponto e os intervalos longos. */
(function(){
  if (window.__pdLeve) return; window.__pdLeve = true;
  var orig = window.setInterval.bind(window);
  window.setInterval = function(fn, ms){
    var rest = Array.prototype.slice.call(arguments, 2);
    if (typeof fn !== 'function' || !(ms < 20000)) return orig.apply(null, [fn, ms].concat(rest));
    var sempre = false; try { sempre = /trackerCheckExpediente|trackerSessions/.test(Function.prototype.toString.call(fn)); } catch(e){}
    if (sempre) return orig.apply(null, [fn, ms].concat(rest));
    return orig.apply(null, [function(){ if (document.hidden) return; return fn.apply(this, arguments); }, ms].concat(rest));
  };
})();

/* pd-leve · desfoque de vidro (backdrop-filter) é o que mais pesa na placa de vídeo, sobretudo em tela Retina
   e com a tela sendo capturada (Meet/Zoom). Medido na home: 41 quadros/s com os vidros, 60 sem.
   1) Sempre: tira o desfoque de onde ele não aparece (véu do fundo, painel de histórico já opaco, botões pequenos do topo).
   2) Modo leve (html.leve): tira todo o desfoque. Liga sozinho quando a tela fica lenta; vale até fechar a aba.
      Forçar: localStorage pd_leve = '1' (sempre leve) ou '0' (nunca). */
(function(){
  if (window.__pdLeve2) return; window.__pdLeve2 = true;
  var NB = '-webkit-backdrop-filter:none!important;backdrop-filter:none!important';
  var css = '.fundo .veu,html.emChat .frente .fundo .veu,.historico,#histPainel{' + NB + '}'
    + '.fundo .veu::after{background:rgba(6,7,6,.94)}'
    + '#clInput,.cl-b,.aba-hist-frente,.hist-handle{' + NB + ';background-image:linear-gradient(rgba(128,128,128,.2),rgba(128,128,128,.2))}'
    + 'html.leve *,html.leve *::before,html.leve *::after{' + NB + '}'
    + 'html.leve .gad-ico{background-image:linear-gradient(rgba(128,128,128,.24),rgba(128,128,128,.24))}';
  var st = document.createElement('style'); st.id = 'pd-leve-css'; st.textContent = css; (document.head || document.documentElement).appendChild(st);
  var H = document.documentElement, pref = null; try { pref = localStorage.getItem('pd_leve'); } catch(e){}
  function liga(){ if (H.classList.contains('leve')) return; H.classList.add('leve'); try { sessionStorage.setItem('pd_leve_s', '1'); } catch(e){} }
  window.pdLeve = function(v){ try { if (v == null) localStorage.removeItem('pd_leve'); else localStorage.setItem('pd_leve', v ? '1' : '0'); } catch(e){} H.classList.toggle('leve', !!v); return H.classList.contains('leve'); };
  var herdou = false; try { herdou = window.parent !== window && window.parent.document.documentElement.classList.contains('leve'); } catch(e){}
  var ja = false; try { ja = sessionStorage.getItem('pd_leve_s') === '1'; } catch(e){}
  if (pref === '1' || herdou || ja) { H.classList.add('leve'); if (pref === '1' || ja) return; }
  if (pref === '0') return;
  // sonda: 1,5 s contando quadros; duas leituras ruins seguidas ligam o modo leve
  var ruins = 0;
  function sonda(){
    if (H.classList.contains('leve')) return;
    if (document.hidden || !document.hasFocus()) return setTimeout(sonda, 15000);
    var n = 0, t0 = performance.now();
    (function f(t){ n++; if (t - t0 < 1500) return requestAnimationFrame(f);
      var fps = n / ((t - t0) / 1000);
      if (fps < 47) { if (++ruins >= 2) return liga(); return setTimeout(sonda, 2500); }
      ruins = 0; setTimeout(sonda, 20000); })(t0);
  }
  setTimeout(sonda, 5000);
})();
