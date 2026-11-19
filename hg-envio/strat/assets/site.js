/* Stratesign 2026 · V2 — comportamento da home. Dados em dados/social.json e dados/artigos.json. */
(function () {
  'use strict';
  var V = '5';                 // sobe a cada troca de imagens ou dados, para furar o cache
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var estreito = function () { return window.innerWidth <= 820; };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var toca = function (v) { try { var p = v.play(); if (p && p.catch) p.catch(function () {}); } catch (_) {} };

  /* ---------- 1. capa ---------- */
  var abre = $('#inicio'), vid = $('#vid'), drg = $('#drg'), pausa = $('#pausa'), cta = $('.cta'), conversa = $('#conversa');
  var parou = false, carregou = false;
  function carregaFilme() { if (carregou) return; carregou = true; vid.src = 'media/abertura.mp4?v=' + V; vid.load(); var p = vid.play(); if (p && p.catch) p.catch(function () { parou = true; pinta(); }); }
  function pinta() { pausa.textContent = parou ? 'Reproduzir' : 'Pausar'; pausa.setAttribute('aria-pressed', parou ? 'true' : 'false'); }
  pausa.addEventListener('click', function () { if (!carregou) { parou = false; carregaFilme(); pinta(); return; } parou = !parou; if (parou) vid.pause(); else toca(vid); pinta(); });
  if (calmo) { parou = true; pinta(); try { drg.pause(); drg.removeAttribute('autoplay'); } catch (_) {} }
  else { toca(drg); if (document.readyState === 'complete') setTimeout(carregaFilme, 300); else window.addEventListener('load', function () { setTimeout(carregaFilme, 300); }); }

  /* ---------- 4. manifesto: preparo das polaroides ---------- */
  var manif = $('#manifesto'), mp = $('.mp'), mpol = $('#mpol'), POL = [];
  var ORDEM = ['02', '04', '12', '14', '19', '33', '42', '45', '48', '52', '55', '01', '07', '09', '16', '20', '23', '26', '29', '31', '36', '38', '40', '44', '47', '50', '53', '56'];
  // lugares de pouso: faixa de cima, faixa de baixo e quinas, deixando o centro legível
  var LUG = [[9, 17, -9], [91, 80, 7], [27, 12, 5], [72, 86, -6], [50, 9, -3], [10, 82, 6], [90, 16, 8], [36, 90, -8], [64, 11, 9], [4, 50, -12], [96, 52, 11],
             [18, 88, -4], [81, 10, -7], [44, 87, 4], [57, 14, -10], [26, 82, 9], [74, 18, 3], [13, 30, 12], [88, 68, -9], [50, 91, 2], [33, 17, -6], [66, 84, 7],
             [6, 66, 8], [94, 33, -5], [40, 10, 7], [60, 90, -3], [20, 14, -11], [80, 84, 10]];
  var NG = 5;                                             // grupos de chegada
  function montaPol() {
    var est = estreito();
    (est ? ORDEM.slice(0, 16) : ORDEM).forEach(function (n, i) {
      var d = document.createElement('div'); d.className = 'pl';
      var L = LUG[i % LUG.length].slice(); if (est) { L[1] = L[1] < 50 ? 5 + (L[1] / 50) * 15 : 81 + ((L[1] - 50) / 50) * 15; L[0] = 12 + L[0] * 0.76; }
      var g = i < 11 ? Math.floor(i / 4) : 3 + Math.floor((i - 11) / 9);       // os 11 primeiros abrem, em três levas
      g = Math.min(NG - 1, g);
      var im = new Image(); im.alt = ''; im.decoding = 'async'; im.loading = 'lazy'; im.src = 'img/pol/' + n + '.webp?v=' + V; d.appendChild(im);
      d.style.left = L[0] + '%'; d.style.top = L[1] + '%'; d.style.zIndex = String(1 + (i * 7) % 5);
      mpol.appendChild(d);
      POL.push({ el: d, g: g, rot: L[2], r0: L[2] + (i % 2 ? 26 : -24), esc: 0.82 + ((i * 37) % 30) / 100, atraso: ((i * 53) % 10) / 100, fica: g >= NG - 2, dx: (i % 2 ? 1 : -1) * (6 + (i * 13) % 12) });
    });
  }
  function pintaPol(p) {
    var vh = window.innerHeight;
    POL.forEach(function (o) {
      var ini = 0.05 + o.g * 0.15 + o.atraso * 0.4, t = (p - ini) / 0.17;
      if (calmo) { o.el.style.opacity = 1; o.el.style.transform = 'rotate(' + o.rot + 'deg) scale(' + o.esc + ')'; return; }
      if (t <= 0) { o.el.style.opacity = 0; return; }
      var e = Math.min(1, t); e = 1 - Math.pow(1 - e, 3);                               // cai e assenta
      var y = (1 - e) * -1.25 * vh, x = (1 - e) * o.dx * 6, r = o.r0 + (o.rot - o.r0) * e, op = 1;
      if (!o.fica) { var s = (p - (ini + 0.42)) / 0.16; if (s > 0) { var q = Math.min(1, s); q = q * q; y += q * 1.3 * vh; r += q * (o.dx > 0 ? 18 : -18); if (s >= 1) op = 0; } }   // sai de cena depois de ser vista
      o.el.style.opacity = op; o.el.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) rotate(' + r.toFixed(2) + 'deg) scale(' + o.esc + ')';
    });
  }
  montaPol();

  /* ---------- rolagem ---------- */
  var tic = false;
  function rola() {
    tic = false;
    var vh = window.innerHeight, r = abre.getBoundingClientRect();
    if (!calmo && !estreito()) { var p = Math.min(1, Math.max(0, -r.top / Math.max(1, r.height - vh))), e = Math.min(1, p / 0.78); e = e * e * (3 - 2 * e); abre.style.setProperty('--p', e.toFixed(4)); }
    else abre.style.setProperty('--p', '0');
    if (carregou && !parou) { if (r.bottom < 0) { if (!vid.paused) vid.pause(); } else if (vid.paused) toca(vid); }
    if (!calmo) { if (r.bottom < 0) { if (!drg.paused) drg.pause(); } else if (drg.paused) toca(drg); }
    var m = manif.getBoundingClientRect();
    if (m.top < vh && m.bottom > 0) { var pm = calmo ? 1 : Math.min(1, Math.max(0, -m.top / Math.max(1, m.height - vh))); mp.style.setProperty('--pm', pm.toFixed(4)); pintaPol(pm); }
    var c = conversa.getBoundingClientRect(); cta.classList.toggle('fora', c.top < vh * 0.6);
  }
  function pede() { if (!tic) { tic = true; requestAnimationFrame(rola); } }
  window.addEventListener('scroll', pede, { passive: true }); window.addEventListener('resize', pede); rola();
  if (calmo) { mp.style.setProperty('--pm', '1'); pintaPol(1); }

  /* ---------- 2. cinco marcas: um filme ativo por vez ---------- */
  var lista = $('#lista'), itens = $$('.mi');
  function ativa(li) {
    itens.forEach(function (x) {
      var on = x === li, v = $('video', x); x.classList.toggle('on', on); $('.mb', x).setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) { if (!v.src && v.dataset.src) { v.src = v.dataset.src + '?v=' + V; v.load(); } if (!calmo) toca(v); } else { try { v.pause(); } catch (_) {} }
    });
    lista.classList.toggle('tem', !!li);
  }
  itens.forEach(function (li) {
    var b = $('.mb', li);
    li.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse' && !estreito()) ativa(li); });
    b.addEventListener('focus', function () { if (b.matches(':focus-visible')) ativa(li); });
    b.addEventListener('click', function () { if (estreito() || !li.classList.contains('on')) ativa(li.classList.contains('on') && estreito() ? null : li); });
  });
  lista.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse' && !estreito()) ativa(null); });
  lista.addEventListener('keydown', function (e) { if (e.key === 'Escape') ativa(null); });

  /* ---------- 5. vitrine: Cover Flow controlado pelo visitante ---------- */
  var giro = $('#giro'), pista = $('#pista'), central = $('#central'), mnomes = $('#mnomes');
  var L = [], els = [], cur = 0, pos = 0, N = 0;
  function mod(a, n) { return ((a % n) + n) % n; }
  function dif(i, p) { var d = i - p; d = d - Math.round(d / N) * N; return d; }
  function monta() {
    L.forEach(function (x, i) {
      var f = document.createElement('figure'); f.className = 'tl'; f.dataset.i = i;
      f.innerHTML = '<div class="im"></div><figcaption><b>' + esc(x.nome) + '</b><i>@' + esc(x.arroba) + '</i><p>' + esc(x.legenda) + '</p><a href="' + esc(x.url) + '" target="_blank" rel="noopener">Abrir Instagram ↗</a></figcaption>';
      pista.appendChild(f); els.push(f);
    });
    desenha(); anuncia();
  }
  function desenha() {
    var cw = els[0] ? els[0].offsetWidth : 280, est = estreito();
    els.forEach(function (f, i) {
      var d = dif(i, pos), a = Math.abs(d), s = d < 0 ? -1 : 1;
      if (a > 3.2) { f.style.opacity = 0; f.style.pointerEvents = 'none'; f.style.transform = 'translate3d(' + (s * cw * 3) + 'px,0,-600px)'; return; }
      var cx = $('.im', f);
      if (a <= 2.5 && !cx.firstChild) { var im = new Image(); im.alt = 'Perfil de ' + L[i].nome + ' no Instagram'; im.decoding = 'async'; im.src = L[i].img + '?v=' + V; cx.appendChild(im); }
      var m = Math.min(a, 1), resto = Math.max(0, a - 1);
      var x = s * (m * cw * (est ? 0.93 : 1.0) + resto * cw * (est ? 0.5 : 0.66));
      var ang = -s * (m * 36 + Math.min(resto, 1) * 10), z = -(m * 150 + resto * 130);
      f.style.opacity = a > 2.6 ? 0 : 1; f.style.pointerEvents = a > 2.6 ? 'none' : 'auto'; f.style.zIndex = String(10 - Math.round(a * 2));
      f.style.transform = 'translate3d(' + x.toFixed(1) + 'px,0,' + z.toFixed(1) + 'px) rotateY(' + ang.toFixed(2) + 'deg)';
      f.classList.toggle('c', a < 0.5); f.classList.toggle('v', a < 1.5);
    });
  }
  function anuncia() { var x = L[cur]; if (x) central.textContent = x.nome + ', @' + x.arroba + '. ' + x.legenda; }
  function vai(i) { cur = mod(i, N); pos = cur; desenha(); anuncia(); }
  $('#ant').addEventListener('click', function () { vai(cur - 1); });
  $('#pro').addEventListener('click', function () { vai(cur + 1); });
  giro.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') { e.preventDefault(); vai(cur - 1); } else if (e.key === 'ArrowRight') { e.preventDefault(); vai(cur + 1); } });
  var ar = null;
  giro.addEventListener('pointerdown', function (e) { if (e.target.closest('.seta') || e.target.closest('a')) return; ar = { x: e.clientX, y: e.clientY, p0: pos, vx: 0, lx: e.clientX, lt: performance.now(), andou: false, alvo: e.target.closest('.tl') }; });
  window.addEventListener('pointermove', function (e) {
    if (!ar) return; var dx = e.clientX - ar.x;
    if (!ar.andou) { if (Math.abs(e.clientY - ar.y) > 12 && Math.abs(dx) < 8) { ar = null; return; } if (Math.abs(dx) > 8) { ar.andou = true; giro.classList.add('arr'); } else return; }   // preserva a rolagem vertical
    var passo = (els[0] ? els[0].offsetWidth : 280), agora = performance.now();
    pos = ar.p0 - dx / passo; ar.vx = (e.clientX - ar.lx) / Math.max(1, agora - ar.lt); ar.lx = e.clientX; ar.lt = agora; desenha();
  });
  function solta() { if (!ar) return; var a = ar; ar = null; giro.classList.remove('arr'); if (!a.andou) { if (a.alvo) { var i = +a.alvo.dataset.i; if (i !== cur) vai(i); } return; } var passo = (els[0] ? els[0].offsetWidth : 280); vai(Math.round(pos - (a.vx * 180) / passo)); }
  window.addEventListener('pointerup', solta); window.addEventListener('pointercancel', solta);
  window.addEventListener('resize', function () { if (N) desenha(); });
  fetch('dados/social.json?v=' + V).then(function (r) { return r.json(); }).then(function (d) {
    // nomes de clientes reais para o fim do manifesto (o índice inteiro)
    var vistos = {}, nomes = []; d.forEach(function (x) { var n = x.nome.replace(/\s*\(.*\)$/, ''); if (!vistos[n]) { vistos[n] = 1; nomes.push(n); } });
    mnomes.textContent = nomes.join('  ·  ');
    L = d.filter(function (x) { return !!x.img; });                    // só entra na vitrine quem tem print verdadeiro
    N = L.length; var g = L.findIndex(function (x) { return x.arroba === 'geovilleurbanismo'; }); cur = pos = g < 0 ? 0 : g; monta();
  }).catch(function () { central.textContent = 'A vitrine não carregou. Atualize a página.'; });

  /* ---------- 6. artigos: o bloco só aparece com cinco textos publicados ---------- */
  fetch('dados/artigos.json?v=' + V).then(function (r) { return r.json(); }).then(function (d) {
    var A = (d.artigos || []).filter(function (a) { return a.publicado; }); if (A.length < 5) return;
    A.sort(function (a, b) { return a.data < b.data ? 1 : -1; });
    $('#arts').innerHTML = A.map(function (a) { var dt = ''; try { dt = new Date(a.data + 'T12:00:00').toLocaleDateString('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' }); } catch (_) {} return '<a href="artigo.html?a=' + encodeURIComponent(a.slug) + '"><span>' + esc(a.autor) + '</span><b>' + esc(a.titulo) + '</b><span>' + esc(dt) + '</span></a>'; }).join('');
    $('#blog').hidden = false; $('#navBlog').hidden = false;
  }).catch(function () {});

  /* ---------- 7. conversa: uma pergunta por tela ---------- */
  var PERG = [
    'Que decisão sobre este projeto está mais difícil de tomar hoje?',
    'Quem precisa escolher esse imóvel e o que essa pessoa está comparando com ele?',
    'O que já existe no produto, no lugar ou na equipe que tornaria a promessa crível?',
    'Em que ponto a venda perde força hoje e qual evidência mostrou isso?',
    'Que resultado, em qual prazo, faria essa conversa ter valido a pena?'
  ];
  var cv = $('#cv'), corpo = $('#cvCorpo'), posEl = $('#cvPos'), bVolta = $('#cvVolta'), bVai = $('#cvVai'), msg = $('#cvMsg'), mel = $('#cvMel');
  var E = { passo: 0, r: ['', '', '', '', ''], nome: '', contato: '', empresa: '', fim: false };
  try { var g0 = JSON.parse(sessionStorage.getItem('strat_cv') || 'null'); if (g0 && g0.r && g0.r.length === 5) { E = g0; E.fim = false; } } catch (_) {}
  function guarda() { try { sessionStorage.setItem('strat_cv', JSON.stringify(E)); } catch (_) {} }
  function le() { var t = $('#cvT'); if (t) E.r[E.passo] = t.value; var n = $('#cvN'); if (n) { E.nome = n.value; E.contato = $('#cvC').value; E.empresa = $('#cvE').value; } guarda(); }
  function mostra(foco) {
    msg.textContent = '';
    if (E.fim) { posEl.textContent = ''; corpo.innerHTML = '<h3>Recebemos. Obrigado.</h3><p style="font-size:17px;max-width:48ch">Suas respostas chegaram à Strat. O retorno vem pelo contato que você deixou.</p>'; bVolta.hidden = true; bVai.hidden = true; return; }
    bVolta.hidden = E.passo === 0; bVai.hidden = false; bVai.disabled = false;
    if (E.passo < 5) { posEl.textContent = (E.passo + 1) + '/5'; corpo.innerHTML = '<label class="q" for="cvT">' + esc(PERG[E.passo]) + '</label><textarea id="cvT" rows="3" maxlength="1500" placeholder="Escreva aqui"></textarea>'; $('#cvT').value = E.r[E.passo]; bVai.textContent = 'Continuar'; }
    else if (E.passo === 5) { posEl.textContent = 'Seu contato'; corpo.innerHTML = '<h3>Para quem a Strat responde?</h3><div class="cps"><label>Nome<input type="text" id="cvN" maxlength="120" autocomplete="name"></label><label>E-mail ou WhatsApp<input type="text" id="cvC" maxlength="160" autocomplete="email"></label><label>Empresa (opcional)<input type="text" id="cvE" maxlength="160" autocomplete="organization"></label></div>'; $('#cvN').value = E.nome; $('#cvC').value = E.contato; $('#cvE').value = E.empresa; bVai.textContent = 'Ver a síntese'; }
    else { posEl.textContent = 'Síntese'; corpo.innerHTML = '<h3>Confira antes de enviar.</h3><ol class="sin">' + PERG.map(function (q, i) { return '<li><b>' + esc(q) + '</b><span>' + (esc(E.r[i]) || '—') + '</span></li>'; }).join('') + '<li><b>Contato</b><span>' + esc(E.nome) + ' · ' + esc(E.contato) + (E.empresa ? ' · ' + esc(E.empresa) : '') + '</span></li></ol>'; bVai.textContent = 'Enviar'; }
    if (foco) { var f = $('#cvT') || $('#cvN'); if (f) f.focus({ preventScroll: true }); }
  }
  cv.addEventListener('input', le);
  bVolta.addEventListener('click', function () { le(); if (E.passo > 0) E.passo--; guarda(); mostra(true); });
  cv.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); bVai.click(); } });
  cv.addEventListener('submit', function (e) {
    e.preventDefault(); le();
    if (E.passo < 5) { if (!E.r[E.passo].trim()) { msg.textContent = 'Escreva uma resposta, mesmo curta, para seguir.'; return; } E.passo++; guarda(); return mostra(true); }
    if (E.passo === 5) { if (E.nome.trim().length < 2) { msg.textContent = 'Diga seu nome.'; return; } if (!/@.+\..+/.test(E.contato) && E.contato.replace(/\D/g, '').length < 10) { msg.textContent = 'Deixe um e-mail ou um WhatsApp com DDD.'; return; } E.passo = 6; guarda(); return mostra(false); }
    bVai.disabled = true; msg.textContent = 'Enviando…';
    fetch('enviar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ perguntas: PERG, respostas: E.r, nome: E.nome, contato: E.contato, empresa: E.empresa, site: mel.value }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok === true, j: j }; }); })
      .then(function (x) { if (!x.ok) throw new Error((x.j && x.j.erro) || 'falha'); E.fim = true; try { sessionStorage.removeItem('strat_cv'); } catch (_) {} mostra(false); })
      .catch(function (err) { bVai.disabled = false; msg.textContent = 'Não foi possível enviar agora' + (err && err.message && err.message !== 'falha' ? ' (' + err.message + ')' : '') + '. Suas respostas continuam aqui; tente de novo.'; });
  });
  mostra(false);
})();
