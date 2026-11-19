/* Stratesign 2026 · V3 — comportamento da home. Dados em dados/social.json e dados/artigos.json. */
(function () {
  'use strict';
  var V = '12';                 // sobe a cada troca de imagens ou dados, para furar o cache
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var estreito = function () { return window.innerWidth <= 820; };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var toca = function (v) { try { var p = v.play(); if (p && p.catch) p.catch(function () {}); } catch (_) {} };

  /* ---------- som: ligado por padrão; o navegador só libera depois do primeiro clique ou toque ---------- */
  var SOM = true, livre = false, bSom = $('#som');
  try { if (localStorage.getItem('strat_som') === '0') SOM = false; } catch (_) {}
  function ativo() { return $('.mi.on video'); }
  function pintaSom() {
    bSom.textContent = SOM ? 'Som ligado' : 'Som desligado'; bSom.setAttribute('aria-pressed', SOM ? 'true' : 'false');
    var a = ativo();
    $$('.mi video').forEach(function (v) { v.muted = !(SOM && livre && v === a); });
    $$('.mi .somd').forEach(function (d) { d.hidden = !(SOM && !livre && a && d.parentNode.contains(a)); });
    vid.muted = !(SOM && livre) || !!a; $('#somh').hidden = !(SOM && !livre) || calmo;
  }
  function libera() { if (livre) return; livre = true; pintaSom(); var a = ativo(); if (a && a.paused && !calmo) toca(a); if (!parou && !calmo) tocaVid(); }
  // se o navegador recusar tocar com som, volta a tocar mudo em vez de parar o filme
  function tocaVid() { try { var p = vid.play(); if (p && p.catch) p.catch(function () { if (!vid.muted) { vid.muted = true; livre = false; $('#somh').hidden = !SOM; toca(vid); } }); } catch (_) {} }
  ['click', 'keydown'].forEach(function (ev) { window.addEventListener(ev, libera, true); });   // só clique e tecla contam como gesto no iPhone
  bSom.addEventListener('click', function () { SOM = !SOM; try { localStorage.setItem('strat_som', SOM ? '1' : '0'); } catch (_) {} livre = true; pintaSom(); });

  /* ---------- 1. capa: o filme entra tocando ---------- */
  var abre = $('#inicio'), vid = $('#vid'), drg = $('#drg'), pausa = $('#pausa'), cta = $('.cta'), conversa = $('#conversa');
  var parou = false;
  function pinta() { pausa.textContent = parou ? 'Reproduzir' : 'Pausar'; pausa.setAttribute('aria-pressed', parou ? 'true' : 'false'); }
  function entra() { if (parou || calmo) return; if (!livre) vid.muted = true; tocaVid(); toca(drg); }
  pausa.addEventListener('click', function () { parou = !parou; if (parou) vid.pause(); else tocaVid(); pinta(); });
  if (calmo) { parou = true; pinta(); try { vid.pause(); vid.removeAttribute('autoplay'); drg.pause(); drg.removeAttribute('autoplay'); } catch (_) {} }
  else { entra(); ['loadeddata', 'canplay'].forEach(function (ev) { vid.addEventListener(ev, function () { if (vid.paused) entra(); }); }); window.addEventListener('load', entra); window.addEventListener('pageshow', entra); document.addEventListener('visibilitychange', function () { if (!document.hidden) entra(); }); }

  /* ---------- 4. manifesto: nomes gigantes correndo atrás da frase ---------- */
  var manif = $('#manifesto'), mnm = $('#mnm'), LN = [], manifVisto = false;
  function montaNomes(nomes) {
    var linhas = estreito() ? 6 : 5, por = Math.ceil(nomes.length / linhas);
    for (var i = 0; i < linhas; i++) {
      var parte = nomes.slice(i * por, (i + 1) * por); if (!parte.length) parte = nomes.slice(0, por);
      var h = parte.map(function (n) { return '<span>' + esc(n) + '</span>'; }).join('');
      var d = document.createElement('div'); d.className = 'ln'; d.innerHTML = h + h + h; mnm.appendChild(d);
      LN.push({ el: d, x: 0, dir: i % 2 ? 1 : -1, vel: 0.35 + (i * 37 % 5) * 0.09, w: 0 });
    }
    medeNomes(); if (calmo) LN.forEach(function (o, i) { o.el.style.transform = 'translate3d(' + (-(i % 3) * 9) + 'vw,0,0)'; });
  }
  function medeNomes() { LN.forEach(function (o) { o.w = o.el.scrollWidth / 3; }); }
  var impulso = 0, ultRola = window.pageYOffset, girando = false;
  function giraNomes() {
    if (!manifVisto || calmo || document.hidden) { girando = false; return; }
    impulso *= 0.92;
    LN.forEach(function (o) { if (!o.w) return; o.x += o.dir * (o.vel + impulso * (0.5 + o.vel)); if (o.x <= -o.w) o.x += o.w; if (o.x > 0) o.x -= o.w; o.el.style.transform = 'translate3d(' + o.x.toFixed(1) + 'px,0,0)'; });
    requestAnimationFrame(giraNomes);
  }
  function ligaNomes() { if (!girando && !calmo) { girando = true; requestAnimationFrame(giraNomes); } }
  window.addEventListener('resize', medeNomes);

  /* ---------- rolagem ---------- */
  var tic = false;
  function rola() {
    tic = false;
    var vh = window.innerHeight, r = abre.getBoundingClientRect();
    if (!calmo && !estreito()) { var p = Math.min(1, Math.max(0, -r.top / Math.max(1, r.height - vh))), e = Math.min(1, p / 0.78); e = e * e * (3 - 2 * e); abre.style.setProperty('--p', e.toFixed(4)); }
    else abre.style.setProperty('--p', '0');
    if (!parou) { if (r.bottom < 0) { if (!vid.paused) vid.pause(); } else if (vid.paused) tocaVid(); }
    if (!calmo) { if (r.bottom < 0) { if (!drg.paused) drg.pause(); } else if (drg.paused) toca(drg); }
    var m = manif.getBoundingClientRect(), y = window.pageYOffset;
    manifVisto = m.top < vh && m.bottom > 0;
    if (manifVisto) { impulso = Math.max(-14, Math.min(14, impulso + (y - ultRola) * 0.05)); ligaNomes(); }
    ultRola = y;
    var c = conversa.getBoundingClientRect(); cta.classList.toggle('fora', c.top < vh * 0.6);
  }
  function pede() { if (!tic) { tic = true; requestAnimationFrame(rola); } }
  window.addEventListener('scroll', pede, { passive: true }); window.addEventListener('resize', pede); rola();

  /* ---------- 2. cinco marcas: um filme ativo por vez ---------- */
  var lista = $('#lista'), itens = $$('.mi');
  function ativa(li) {
    itens.forEach(function (x) {
      var on = x === li, v = $('video', x); x.classList.toggle('on', on); $('.mb', x).setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) {
        if (!v.src && v.dataset.src) { v.src = v.dataset.src + '?v=' + V; v.load(); }
        v.muted = !(SOM && livre);
        if (!calmo || livre) { try { var p = v.play(); if (p && p.catch) p.catch(function () { v.muted = true; livre = false; pintaSom(); toca(v); }); } catch (_) {} }
      } else { try { v.pause(); v.muted = true; } catch (_) {} }
    });
    lista.classList.toggle('tem', !!li); pintaSom();
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
    desenha(); anuncia(); degrade();
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
  // degradê do preto (fim do mural) ao branco, terminando no começo dos cards
  function degrade() { var ac = $('#acervo'), t = ac.getBoundingClientRect().top, g0 = $('.h2', ac).getBoundingClientRect().bottom - t + 8, cw = els[0] ? els[0].offsetWidth : 280, g1 = giro.getBoundingClientRect().top - t + 20 + cw * 16 / 9 * 0.42; ac.style.setProperty('--g0', g0 + 'px'); ac.style.setProperty('--g1', Math.max(g1, g0 + 160) + 'px'); }
  window.addEventListener('resize', function () { if (N) desenha(); degrade(); }); window.addEventListener('load', degrade);
  fetch('dados/social.json?v=' + V).then(function (r) { return r.json(); }).then(function (d) {
    // nomes de clientes reais para o fim do manifesto (o índice inteiro)
    var vistos = {}, nomes = []; d.forEach(function (x) { var n = x.nome.replace(/\s*\(.*\)$/, ''); if (!vistos[n]) { vistos[n] = 1; nomes.push(n); } });
    mnomes.textContent = 'Marcas criadas pela Strat: ' + nomes.join(', ') + '.'; montaNomes(nomes); rola();
    L = d.filter(function (x) { return !!x.img; });                    // só entra na vitrine quem tem print verdadeiro
    N = L.length; var g = L.findIndex(function (x) { return x.arroba === 'geovilleurbanismo'; }); cur = pos = g < 0 ? 0 : g; monta();
  }).catch(function () { central.textContent = 'A vitrine não carregou. Atualize a página.'; });

  /* ---------- mural: grade de imagens com tamanhos variados ---------- */
  fetch('dados/grade.json?v=' + V).then(function (r) { return r.json(); }).then(function (G) {
    var mgr = $('#mgr');
    var io = 'IntersectionObserver' in window && !calmo ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('v'); io.unobserve(e.target); } }); }, { rootMargin: '0px 0px -8% 0px' }) : null;
    // só quadrados: blocos de 4x2 fechados (um grande + quatro pequenos, ou oito pequenos), para a grade nunca terminar com buraco
    var nb = 0, i = 0, tudo8 = (G.length % 5 === 3) ? 1 : (G.length % 5 === 0 ? 0 : -1), blocos = [];
    while (i < G.length) {
      var resta = G.length - i, oito = (nb === 5 && tudo8 === 1 && resta >= 8), n = oito ? 8 : 5;
      if (resta < n) break;                                   // sobra que não fecha bloco fica de fora
      blocos.push({ itens: G.slice(i, i + n), oito: oito }); i += n; nb++;
    }
    if (blocos.length % 2) blocos.pop();                      // número par de blocos fecha a última linha no desktop
    blocos.forEach(function (b, k) {
      var bl = document.createElement('div'); bl.className = 'bl';
      var pg = b.oito ? -1 : (k % 4 === 0 || k % 4 === 3 ? 0 : 2);   // onde entra a imagem grande: à esquerda ou à direita
      b.itens.forEach(function (g, m) {
        var d = document.createElement('figure'); d.className = 'mg' + (m === pg ? ' g' : ''); d.style.transitionDelay = ((m % 4) * 90) + 'ms';
        var im = new Image(); im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = g[1]; im.height = g[2]; im.src = 'img/gr/' + g[0] + '.webp?v=' + V; d.appendChild(im);
        bl.appendChild(d); if (io) io.observe(d); else d.classList.add('v');
      });
      mgr.appendChild(bl);
    });
  }).catch(function () {});

  /* ---------- 6. artigos: o bloco só aparece com cinco textos publicados ---------- */
  fetch('dados/artigos.json?v=' + V).then(function (r) { return r.json(); }).then(function (d) {
    var A = (d.artigos || []).filter(function (a) { return a.publicado; }); if (A.length < 5) return;
    A.sort(function (a, b) { return a.data < b.data ? 1 : -1; });
    $('#arts').innerHTML = A.map(function (a) { var dt = ''; try { dt = new Date(a.data + 'T12:00:00').toLocaleDateString('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' }); } catch (_) {} return '<a href="artigo.html?a=' + encodeURIComponent(a.slug) + '"><span>' + esc(a.autor) + '</span><b>' + esc(a.titulo) + '</b><span>' + esc(dt) + '</span></a>'; }).join('');
    $('#blog').hidden = false; $('#navBlog').hidden = false;
  }).catch(function () {});

  /* ---------- 7. conversa: diagnóstico em cinco perguntas (mesmo desenho do sergioduh.com) ---------- */
  // pesos por resposta: [inteligência de mercado, marca, campanha]
  var Q = [
    { q: 'O que você está colocando <b>no mundo?</b>', o: [['Um lançamento imobiliário', [1, 2, 3]], ['Uma incorporadora ou uma marca', [1, 3, 0]], ['Um loteamento ou bairro planejado', [2, 2, 1]], ['Ainda estou estudando o terreno', [3, 0, 0]]] },
    { q: 'Em que <b>ponto</b> está?', o: [['Só o terreno e uma ideia', [3, 1, 0]], ['Produto definido, ainda sem marca', [1, 3, 1]], ['No ar, mas sem tração', [1, 1, 3]], ['Vendendo e quero escalar', [0, 1, 3]]] },
    { q: 'O que mais <b>trava</b> hoje?', o: [['Não sei para quem vender', [3, 0, 0]], ['Não pareço com o que sou', [0, 3, 0]], ['Ninguém percebe o valor', [1, 3, 1]], ['O interesse chega e não vira visita', [1, 0, 3]]] },
    { q: 'Como você quer ser <b>lembrado?</b>', o: [['Por entender a cidade', [3, 0, 0]], ['Por uma marca forte', [0, 3, 0]], ['Por uma campanha que parou a rua', [0, 0, 3]], ['Pelos resultados', [1, 1, 1]]] },
    { q: 'Para <b>quando</b>?', o: [['Esta semana', [0, 0, 0]], ['Este mês', [0, 0, 0]], ['Este trimestre', [0, 0, 0]], ['Só explorando', [0, 0, 0]]] }
  ];
  var PIL = ['Inteligência de mercado', 'Marca', 'Campanha'];
  var TXT = [
    'Antes da marca e da mídia, falta saber quem compra, quanto paga e o que a cidade já oferece. A Strat começa pelo estudo, com os dados da Datastore, e só depois desenha o resto.',
    'O produto existe, mas ainda não tem nome, cara e história que sustentem o preço. A Strat começa pela marca: conceito, identidade e a narrativa que o corretor consegue repetir.',
    'O que falta é rua. A Strat começa pela campanha: a ideia, as peças e a mídia que levam gente ao estande, com o orçamento que o lançamento tem.'
  ];
  var bq = $('#bq'), D = { i: 0, r: [], fim: false };
  function soma() { var t = [0, 0, 0]; D.r.forEach(function (k, n) { var w = Q[n].o[k][1]; t[0] += w[0]; t[1] += w[1]; t[2] += w[2]; }); return t; }
  function barra(p) { return '<div class="bar"><i style="width:' + p + '%"></i></div>'; }
  function passo(foco) {
    var n = D.i;
    if (n < Q.length) {
      var x = Q[n];
      bq.innerHTML = '<div>' + barra(Math.round(n / Q.length * 100)) + '<div class="n">' + ('0' + (n + 1)).slice(-2) + ' / ' + ('0' + Q.length).slice(-2) + '</div><h3>' + x.q + '</h3></div>' +
        '<div><div class="opts">' + x.o.map(function (o, k) { return '<button type="button" class="opt" data-k="' + k + '"><span>' + esc(o[0]) + '</span><span aria-hidden="true">→</span></button>'; }).join('') + '</div>' +
        (n > 0 ? '<button type="button" class="bk" id="bk">← voltar</button>' : '') + '</div>';
      if (foco) { var f = $('.opt', bq); if (f) f.focus({ preventScroll: true }); }
      return;
    }
    var t = soma(), mx = Math.max(t[0], t[1], t[2], 1), top = t.indexOf(Math.max(t[0], t[1], t[2]));
    bq.innerHTML = '<div class="res">' + barra(100) + '<div class="n">Diagnóstico</div><h3>Comece por <b>' + PIL[top].toLowerCase() + '.</b></h3>' +
      '<div class="bars">' + PIL.map(function (p, k) { return '<div class="rb' + (k === top ? ' top' : '') + '"><span>' + p + '</span><i data-w="' + Math.round(t[k] / mx * 100) + '"></i></div>'; }).join('') + '</div>' +
      '<p>' + TXT[top] + '</p>' +
      '<form class="fld" id="fq" novalidate><input type="text" id="fqN" placeholder="Seu nome" maxlength="120" autocomplete="name" aria-label="Seu nome"><input type="tel" id="fqW" placeholder="WhatsApp com DDD" maxlength="24" autocomplete="tel" inputmode="tel" aria-label="WhatsApp com DDD"><input type="text" class="mel" id="fqM" name="site" tabindex="-1" autocomplete="off" aria-hidden="true"><button type="submit" class="btn" id="fqB">Enviar →</button></form>' +
      '<div class="fm" id="fqMsg" role="status"></div><button type="button" class="bk" id="bk">← voltar</button></div>';
    requestAnimationFrame(function () { requestAnimationFrame(function () { $$('.rb i', bq).forEach(function (b) { b.style.width = b.dataset.w + '%'; }); }); });
    D.top = top; D.t = t;
  }
  bq.addEventListener('click', function (e) {
    var o = e.target.closest('.opt'); if (o) { D.r[D.i] = +o.dataset.k; D.i++; return passo(true); }
    if (e.target.closest('#bk')) { D.i = Math.max(0, D.i - 1); D.r.length = D.i; passo(true); }
  });
  bq.addEventListener('submit', function (e) {
    e.preventDefault();
    var nome = $('#fqN').value.trim(), zap = $('#fqW').value.trim(), msg = $('#fqMsg'), b = $('#fqB');
    if (nome.length < 2) { msg.textContent = 'Diga seu nome.'; $('#fqN').focus(); return; }
    if (zap.replace(/\D/g, '').length < 10) { msg.textContent = 'Deixe um WhatsApp com DDD.'; $('#fqW').focus(); return; }
    b.disabled = true; msg.textContent = 'Enviando…';
    fetch('enviar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
      nome: nome, whatsapp: zap, site: $('#fqM').value,
      perguntas: Q.map(function (x) { return x.q.replace(/<[^>]+>/g, ''); }), respostas: D.r.map(function (k, n) { return Q[n].o[k][0]; }),
      resultado: PIL[D.top], pontos: { mercado: D.t[0], marca: D.t[1], campanha: D.t[2] } }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok === true, j: j }; }); })
      .then(function (x) { if (!x.ok) throw new Error((x.j && x.j.erro) || 'falha'); $('#fq').remove(); var k = $('#bk', bq); if (k) k.remove(); msg.textContent = 'Recebido. A Strat retorna no WhatsApp com o diagnóstico completo.'; })
      .catch(function (err) { b.disabled = false; msg.textContent = 'Não foi possível enviar agora' + (err && err.message && err.message !== 'falha' ? ' (' + err.message + ')' : '') + '. Tente de novo.'; });
  });
  passo(false);
  pintaSom();
})();
