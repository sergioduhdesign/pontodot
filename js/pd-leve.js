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
