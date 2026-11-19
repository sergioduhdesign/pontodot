/* Stratesign 2026 — comportamento da home. Conteúdo do acervo em dados/social.json. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  /* ---------- 1. abertura: o filme cresce até a sangria, o título corre mais rápido ---------- */
  var abre = $('#inicio'), vid = $('#vid'), pausa = $('#pausa'), cta = $('.cta'), conversa = $('#conversa');
  var parou = false, carregou = false;
  function carregaFilme() {
    if (carregou) return; carregou = true;
    vid.src = 'media/abertura.mp4'; vid.load();
    var p = vid.play(); if (p && p.catch) p.catch(function () { parou = true; pinta(); });
  }
  function pinta() { pausa.textContent = parou ? 'Reproduzir' : 'Pausar'; pausa.setAttribute('aria-pressed', parou ? 'true' : 'false'); }
  pausa.addEventListener('click', function () {
    if (!carregou) { parou = false; carregaFilme(); pinta(); return; }
    parou = !parou; if (parou) vid.pause(); else vid.play(); pinta();
  });
  // o cartaz aparece primeiro; o filme entra depois do primeiro quadro estático
  if (calmo) { parou = true; pinta(); }
  else if (document.readyState === 'complete') setTimeout(carregaFilme, 300);
  else window.addEventListener('load', function () { setTimeout(carregaFilme, 300); });

  var imgsPx = $$('img[data-px]'), tic = false;
  function rola() {
    tic = false;
    var vh = window.innerHeight, r = abre.getBoundingClientRect(), curso = Math.max(1, r.height - vh);
    var p = Math.min(1, Math.max(0, -r.top / curso));
    if (!calmo) {
      var e = Math.min(1, p / 0.78); e = e * e * (3 - 2 * e);          // cresce, depois segura um instante
      abre.style.setProperty('--p', e.toFixed(4));
      var movel = window.innerWidth <= 820 ? 0.4 : 1;
      imgsPx.forEach(function (im) {                                      // planos de profundidade nas fotos
        var b = im.parentNode.getBoundingClientRect(); if (b.bottom < 0 || b.top > vh) return;
        var t = (b.top + b.height / 2 - vh / 2) / vh;                     // -0.5 a 0.5
        im.style.transform = 'translate3d(0,' + (-t * (+im.dataset.px) * movel - b.height * 0.09).toFixed(1) + 'px,0)';
      });
    }
    if (carregou && !parou) { if (r.bottom < 0) { if (!vid.paused) vid.pause(); } else if (vid.paused) { var q = vid.play(); if (q && q.catch) q.catch(function () {}); } }
    var c = conversa.getBoundingClientRect(); cta.classList.toggle('fora', c.top < vh * 0.6);
  }
  function pede() { if (!tic) { tic = true; requestAnimationFrame(rola); } }
  window.addEventListener('scroll', pede, { passive: true }); window.addEventListener('resize', pede); rola();

  /* ---------- 2. cinco marcas: um quadro só responde à marca ativa ---------- */
  var secM = $('#trabalhos'), bots = $$('.mb'), figs = $$('.qf'), quadro = $('#quadro');
  function ativa(i) {
    bots.forEach(function (b, k) { b.setAttribute('aria-pressed', k === i ? 'true' : 'false'); });
    figs.forEach(function (f, k) { f.classList.toggle('on', k === i); });
  }
  bots.forEach(function (b, i) {
    b.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') { ativa(i); secM.classList.add('cor'); } });
    b.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') secM.classList.remove('cor'); });
    b.addEventListener('focus', function () { ativa(i); if (b.matches(':focus-visible')) secM.classList.add('cor'); });
    b.addEventListener('blur', function () { secM.classList.remove('cor'); });
    b.addEventListener('click', function () { ativa(i); secM.classList.add('cor'); });
  });
  quadro.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') secM.classList.add('cor'); });
  quadro.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') secM.classList.remove('cor'); });
  quadro.addEventListener('click', function () { secM.classList.toggle('cor'); });

  /* ---------- 4. acervo: biblioteca de perfis em giro ---------- */
  var giro = $('#giro'), pista = $('#pista'), central = $('#central'), indice = $('#indice');
  var L = [], els = [], cur = 0, pos = 0, N = 0;
  var V = '3';                 // sobe a cada troca de imagens ou dados, para furar o cache
  var FONTE = { captura: 'Captura do perfil em novembro de 2026.', press: 'Imagem de arquivo do press da agência.' };
  function mod(a, n) { return ((a % n) + n) % n; }
  function dif(i, p) { var d = i - p; d = d - Math.round(d / N) * N; return d; }       // distância mais curta no laço
  function monta() {
    L.forEach(function (x, i) {
      var f = document.createElement('figure'); f.className = 'tl'; f.dataset.i = i;
      f.innerHTML = (x.img ? '' : '<div class="tx"><b>' + esc(x.nome) + '</b><span>@' + esc(x.arroba) + '</span></div>') +
        '<figcaption>' + esc(x.nome) + ' (@' + esc(x.arroba) + '). ' + esc(x.legenda) + '</figcaption>';
      pista.appendChild(f); els.push(f);
    });
    var gr = {}; L.forEach(function (x, i) { (gr[x.grupo] = gr[x.grupo] || []).push(i); });
    indice.innerHTML = Object.keys(gr).map(function (g) {
      return '<h4>' + esc(g) + '</h4><ul>' + gr[g].map(function (i) { return '<li><button type="button" data-i="' + i + '">' + esc(L[i].nome) + ' <span style="opacity:.5">@' + esc(L[i].arroba) + '</span></button></li>'; }).join('') + '</ul>';
    }).join('');
    indice.addEventListener('click', function (e) { var b = e.target.closest('button[data-i]'); if (!b) return; vai(+b.dataset.i); giro.scrollIntoView({ block: 'center', behavior: calmo ? 'auto' : 'smooth' }); });
    desenha(); legenda();
  }
  function desenha() {
    var cw = els[0] ? els[0].offsetWidth : 280, estreito = window.innerWidth <= 820;
    els.forEach(function (f, i) {
      var d = dif(i, pos), a = Math.abs(d), s = d < 0 ? -1 : 1;
      if (a > 3.2) { f.style.opacity = 0; f.style.pointerEvents = 'none'; f.style.transform = 'translate3d(' + (s * cw * 3) + 'px,0,-600px)'; return; }
      // carrega só o centro e os vizinhos
      if (a <= 2.5 && L[i].img && !f.querySelector('img')) { var im = new Image(); im.alt = ''; im.decoding = 'async'; im.src = L[i].img + '?v=' + V; f.insertBefore(im, f.firstChild); }
      var m = Math.min(a, 1), resto = Math.max(0, a - 1);
      var x = s * (m * cw * (estreito ? 0.93 : 1.0) + resto * cw * (estreito ? 0.5 : 0.66));
      var ang = -s * (m * 36 + Math.min(resto, 1) * 10);            // vizinhas a 36 e 46 graus
      var z = -(m * 150 + resto * 130);
      f.style.opacity = a > 2.6 ? 0 : 1; f.style.pointerEvents = a > 2.6 ? 'none' : 'auto';
      f.style.zIndex = String(10 - Math.round(a * 2));
      f.style.transform = 'translate3d(' + x.toFixed(1) + 'px,0,' + z.toFixed(1) + 'px) rotateY(' + ang.toFixed(2) + 'deg)';
      f.classList.toggle('c', a < 0.5);
    });
  }
  function legenda() {
    var x = L[cur]; if (!x) return;
    central.innerHTML = '<div class="gr">' + esc(x.grupo) + '</div><h3>' + esc(x.nome) + '</h3><div class="ar">@' + esc(x.arroba) + '</div><p>' + esc(x.legenda) + '</p>' +
      '<a class="ab" href="' + esc(x.url) + '" target="_blank" rel="noopener">Abrir Instagram</a>' + (x.fonte ? '<span class="fo">' + FONTE[x.fonte] + '</span>' : '');
  }
  function vai(i) { cur = mod(i, N); pos = cur; desenha(); legenda(); }
  $('#ant').addEventListener('click', function () { vai(cur - 1); });
  $('#pro').addEventListener('click', function () { vai(cur + 1); });
  giro.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') { e.preventDefault(); vai(cur - 1); } else if (e.key === 'ArrowRight') { e.preventDefault(); vai(cur + 1); } });
  // arraste com inércia e encaixe
  var ar = null;
  giro.addEventListener('pointerdown', function (e) {
    if (e.target.closest('.seta')) return;
    ar = { x: e.clientX, p0: pos, t: performance.now(), vx: 0, lx: e.clientX, lt: performance.now(), andou: false, alvo: e.target.closest('.tl') };
  });
  window.addEventListener('pointermove', function (e) {
    if (!ar) return; var dx = e.clientX - ar.x;
    if (!ar.andou && Math.abs(dx) > 6) { ar.andou = true; giro.classList.add('arr'); }
    if (!ar.andou) return;
    var passo = (els[0] ? els[0].offsetWidth : 280) * 0.84, agora = performance.now();
    pos = ar.p0 - dx / passo; ar.vx = (e.clientX - ar.lx) / Math.max(1, agora - ar.lt); ar.lx = e.clientX; ar.lt = agora; desenha();
  });
  function solta() {
    if (!ar) return; var a = ar; ar = null; giro.classList.remove('arr');
    if (!a.andou) { if (a.alvo) { var i = +a.alvo.dataset.i; if (i !== cur) vai(i); } return; }
    var passo = (els[0] ? els[0].offsetWidth : 280) * 0.84;
    vai(Math.round(pos - (a.vx * 180) / passo));                       // inércia: leva junto o impulso do dedo
  }
  window.addEventListener('pointerup', solta); window.addEventListener('pointercancel', solta);
  window.addEventListener('resize', function () { if (N) desenha(); });
  fetch('dados/social.json?v=' + V).then(function (r) { return r.json(); }).then(function (d) {
    L = d; N = L.length; var g = L.findIndex(function (x) { return x.arroba === 'geovilleurbanismo'; }); cur = pos = g < 0 ? 0 : g; monta();
  }).catch(function () { central.innerHTML = '<p>O acervo não carregou. Atualize a página.</p>'; });

  /* ---------- 5. conversa: uma pergunta por tela ---------- */
  var PERG = [
    'Que decisão sobre este projeto está mais difícil de tomar hoje?',
    'Quem precisa escolher esse imóvel e o que essa pessoa está comparando com ele?',
    'O que já existe no produto, no lugar ou na equipe que tornaria a promessa crível?',
    'Em que ponto a venda perde força hoje e qual evidência mostrou isso?',
    'Que resultado, em qual prazo, faria essa conversa ter valido a pena?'
  ];
  var cv = $('#cv'), corpo = $('#cvCorpo'), posEl = $('#cvPos'), bVolta = $('#cvVolta'), bVai = $('#cvVai'), msg = $('#cvMsg'), mel = $('#cvMel');
  var E = { passo: 0, r: ['', '', '', '', ''], nome: '', contato: '', empresa: '', fim: false };
  try { var g = JSON.parse(sessionStorage.getItem('strat_cv') || 'null'); if (g && g.r && g.r.length === 5) { E = g; E.fim = false; } } catch (_) {}
  function guarda() { try { sessionStorage.setItem('strat_cv', JSON.stringify(E)); } catch (_) {} }
  function le() {
    var t = $('#cvT'); if (t) E.r[E.passo] = t.value;
    var n = $('#cvN'); if (n) { E.nome = n.value; E.contato = $('#cvC').value; E.empresa = $('#cvE').value; }
    guarda();
  }
  function mostra(foco) {
    msg.textContent = '';
    if (E.fim) { posEl.textContent = ''; corpo.innerHTML = '<h3>Recebemos. Obrigado.</h3><p style="font-size:17px;max-width:48ch">Suas respostas chegaram à Strat. O retorno vem pelo contato que você deixou.</p>'; bVolta.hidden = true; bVai.hidden = true; return; }
    bVolta.hidden = E.passo === 0; bVai.hidden = false; bVai.disabled = false;
    if (E.passo < 5) {
      posEl.textContent = (E.passo + 1) + '/5';
      corpo.innerHTML = '<label class="q" for="cvT">' + esc(PERG[E.passo]) + '</label><textarea id="cvT" rows="3" maxlength="1500" placeholder="Escreva aqui"></textarea>';
      $('#cvT').value = E.r[E.passo]; bVai.textContent = 'Continuar';
    } else if (E.passo === 5) {
      posEl.textContent = 'Seu contato';
      corpo.innerHTML = '<h3>Para quem a Strat responde?</h3><div class="cps"><label>Nome<input type="text" id="cvN" maxlength="120" autocomplete="name"></label><label>E-mail ou WhatsApp<input type="text" id="cvC" maxlength="160" autocomplete="email"></label><label>Empresa (opcional)<input type="text" id="cvE" maxlength="160" autocomplete="organization"></label></div>';
      $('#cvN').value = E.nome; $('#cvC').value = E.contato; $('#cvE').value = E.empresa; bVai.textContent = 'Ver a síntese';
    } else {
      posEl.textContent = 'Síntese';
      corpo.innerHTML = '<h3>Confira antes de enviar.</h3><ol class="sin">' + PERG.map(function (q, i) { return '<li><b>' + esc(q) + '</b><span>' + (esc(E.r[i]) || '—') + '</span></li>'; }).join('') +
        '<li><b>Contato</b><span>' + esc(E.nome) + ' · ' + esc(E.contato) + (E.empresa ? ' · ' + esc(E.empresa) : '') + '</span></li></ol>';
      bVai.textContent = 'Enviar';
    }
    if (foco) { var f = $('#cvT') || $('#cvN'); if (f) f.focus({ preventScroll: true }); }
  }
  cv.addEventListener('input', le);
  bVolta.addEventListener('click', function () { le(); if (E.passo > 0) E.passo--; guarda(); mostra(true); });
  cv.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); bVai.click(); } });
  cv.addEventListener('submit', function (e) {
    e.preventDefault(); le();
    if (E.passo < 5) { if (!E.r[E.passo].trim()) { msg.textContent = 'Escreva uma resposta, mesmo curta, para seguir.'; return; } E.passo++; guarda(); return mostra(true); }
    if (E.passo === 5) {
      if (E.nome.trim().length < 2) { msg.textContent = 'Diga seu nome.'; return; }
      if (!/@.+\..+/.test(E.contato) && E.contato.replace(/\D/g, '').length < 10) { msg.textContent = 'Deixe um e-mail ou um WhatsApp com DDD.'; return; }
      E.passo = 6; guarda(); return mostra(false);
    }
    bVai.disabled = true; msg.textContent = 'Enviando…';
    fetch('enviar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ perguntas: PERG, respostas: E.r, nome: E.nome, contato: E.contato, empresa: E.empresa, site: mel.value }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok === true, j: j }; }); })
      .then(function (x) {
        if (!x.ok) throw new Error((x.j && x.j.erro) || 'falha');
        E.fim = true; try { sessionStorage.removeItem('strat_cv'); } catch (_) {} mostra(false);          // só confirma quando o destino recebeu
      })
      .catch(function (err) { bVai.disabled = false; msg.textContent = 'Não foi possível enviar agora' + (err && err.message && err.message !== 'falha' ? ' (' + err.message + ')' : '') + '. Suas respostas continuam aqui; tente de novo.'; });
  });
  mostra(false);
})();
