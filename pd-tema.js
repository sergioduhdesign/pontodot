/* PD · TEMA DIA / NOITE das ferramentas (telas abertas dentro da PD).
   O botão de dia/noite da PD (index.html) é quem manda. Aqui, a ferramenta só segue:
   noite (desktop) = a tela inteira é invertida (branco vira preto, texto preto vira claro), então NUNCA sobra texto escuro em fundo escuro;
   fotos, vídeos, canvas (a prancheta do Estúdio) e miniaturas voltam à cor verdadeira.
   Marque com class="pd-keep" o que não pode ser invertido; com class="pd-noite-nao" a tela que já tem noite própria. */
(function () {
  'use strict';
  try {
    var R = document.documentElement;
    if (self === top || parent !== top) return;                 // só quem está direto dentro da PD
    var P = top.document.documentElement;
    var INV = 'invert(1) hue-rotate(180deg)';
    var st = document.createElement('style'); st.id = 'pdTemaCss';
    st.textContent =
      'html.pd-noite{filter:' + INV + ';color-scheme:light}' +
      'html.pd-noite img,html.pd-noite video,html.pd-noite canvas,html.pd-noite picture,html.pd-noite iframe,html.pd-noite embed,html.pd-noite object,' +
      'html.pd-noite [style*="background-image"],html.pd-noite [style*="background:url"],html.pd-noite [style*="background: url"],html.pd-noite .pd-keep{filter:' + INV + '}' +
      'html.pd-noite .pd-keep img,html.pd-noite .pd-keep video,html.pd-noite .pd-keep canvas{filter:none}';
    (document.head || R).appendChild(st);
    function aplica() {
      var cel = window.matchMedia && matchMedia('(max-width:999px)').matches;        // celular: já é escuro, sem botão
      var noite = !P.classList.contains('dia') && !cel && !R.classList.contains('pd-noite-nao');
      R.classList.toggle('pd-noite', noite);
    }
    aplica();
    new MutationObserver(aplica).observe(P, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', aplica);
  } catch (e) { /* fora da PD: tela normal */ }
})();
