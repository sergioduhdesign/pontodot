// Pontodot — tipologia dos 4 nomes (Monema · Amae · Yorrum · Catum).
// Em TÍTULO (h1–h4 e títulos de caixa) o nome nunca vai escrito: entra o vetor oficial,
// na cor do texto do título (branco no fundo preto, preto no fundo branco — vem do currentColor).
(function () {
  if (window.__pdNomes) return; window.__pdNomes = true;
  var R = { catum: 4.1, yorrum: 3.93, monema: 8.44, amae: 5.56 };
  var RX = /\b(catum|yorrum|monema|amae)\b/gi;
  var SEL = 'h1,h2,h3,h4,.pd-titulo,.pop-titulo,.esc-h3,.esc-h3-cursos,.modal-title,.esc-cur-pf small,.esc-cidade-nome,.esc-postal-vazio,.mt-tit small';
  var css = document.createElement('style');
  css.textContent = 'pd-nome.pd-nome{display:inline-block;font-style:normal;font-weight:400;opacity:1;width:calc(.82em*var(--r));max-width:100%;height:auto;aspect-ratio:var(--r);vertical-align:-.03em;background:currentColor;' +
    '-webkit-mask:var(--m) center/contain no-repeat;mask:var(--m) center/contain no-repeat;overflow:hidden;text-indent:-9999px;white-space:nowrap;font-size:inherit;letter-spacing:0;text-transform:none}' +
    'pd-nome.pd-nome+pd-nome.pd-nome{margin-left:.28em}';
  document.head.appendChild(css);
  function span(n) {
    var k = n.toLowerCase(), s = document.createElement('pd-nome');
    s.className = 'pd-nome'; s.setAttribute('role', 'img'); s.setAttribute('aria-label', n.charAt(0).toUpperCase() + k.slice(1));
    s.style.setProperty('--r', R[k]); s.style.setProperty('--m', 'url(/assets/nomes/' + k + '.svg)'); s.textContent = n; return s;
  }
  function trata(el) {
    var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
      acceptNode: function (t) {
        var p = t.parentNode; if (!p || /^(SCRIPT|STYLE|TEXTAREA|INPUT|OPTION)$/.test(p.nodeName) || (p.closest && p.closest('.pd-nome'))) return NodeFilter.FILTER_REJECT;
        RX.lastIndex = 0; return RX.test(t.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    }), L = [], t; while ((t = w.nextNode())) L.push(t);
    L.forEach(function (t) {
      var f = document.createDocumentFragment(), txt = t.nodeValue, i = 0, m; RX.lastIndex = 0;
      while ((m = RX.exec(txt))) { if (m.index > i) f.appendChild(document.createTextNode(txt.slice(i, m.index))); f.appendChild(span(m[0])); i = m.index + m[0].length; }
      if (i < txt.length) f.appendChild(document.createTextNode(txt.slice(i)));
      t.parentNode.replaceChild(f, t);
    });
  }
  function varre(raiz) {
    try { (raiz || document).querySelectorAll(SEL).forEach(trata); if (raiz && raiz.matches && raiz.matches(SEL)) trata(raiz); } catch (e) {}
  }
  var pend = false;
  function agenda() { if (pend) return; pend = true; requestAnimationFrame(function () { pend = false; varre(document); }); }
  function ini() { varre(document); new MutationObserver(agenda).observe(document.body, { childList: true, subtree: true, characterData: true }); }
  if (document.body) ini(); else document.addEventListener('DOMContentLoaded', ini);
})();
