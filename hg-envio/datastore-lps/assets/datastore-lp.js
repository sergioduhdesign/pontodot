/* Datastore LP system v3 — vanilla, sem dependências */
(function () {
  'use strict';
  var d = document, w = window;
  var $ = function (s, c) { return (c || d).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };
  var reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* barra de progresso de leitura */
  var bar = $('.progress-top');
  if (bar) {
    var tick = false;
    var upd = function () {
      tick = false;
      var h = d.documentElement.scrollHeight - w.innerHeight;
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, w.scrollY / h) : 0) + ')';
    };
    w.addEventListener('scroll', function () { if (!tick) { tick = true; w.requestAnimationFrame(upd); } }, { passive: true });
    upd();
  }

  /* reveal ao rolar */
  var rv = $$('.reveal');
  if ('IntersectionObserver' in w && !reduce) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    rv.forEach(function (el) { io.observe(el); });
    setTimeout(function () { rv.forEach(function (el) { el.classList.add('in'); }); }, 3500);
  } else { rv.forEach(function (el) { el.classList.add('in'); }); }

  /* CTA fixo: aparece só depois que o botão da primeira dobra sai da tela.
     Desktop: botão no topo à direita. Celular: barra no rodapé. Some enquanto um formulário está à vista. */
  var sticky = $('#sticky'), hdr = $('.hdr'), heroCta = $('[data-cta]');
  var visF = new Set();
  function syncCta() {
    var out = heroCta ? heroCta.getBoundingClientRect().bottom < 0 : w.scrollY > 500;
    var on = out && visF.size === 0;
    if (hdr) hdr.classList.toggle('cta-on', on);
    if (sticky) sticky.classList.toggle('show', on);
  }
  if ('IntersectionObserver' in w) {
    var io2 = new IntersectionObserver(function (es) {
      es.forEach(function (x) { x.isIntersecting ? visF.add(x.target) : visF.delete(x.target); });
      syncCta();
    });
    $$('.form-card').forEach(function (f) { io2.observe(f); });
  }
  w.addEventListener('scroll', syncCta, { passive: true });
  syncCta();

  /* vídeo de fundo: só carrega quando a seção se aproxima e a conexão permite */
  var vid = $('#finalVideo');
  if (vid && 'IntersectionObserver' in w) {
    var c = navigator.connection || {};
    var okNet = !c.saveData && (!c.effectiveType || c.effectiveType === '4g');
    var wide = w.matchMedia('(min-width: 760px)').matches;
    if (!reduce && okNet && (wide || c.effectiveType === '4g')) {
      var vio = new IntersectionObserver(function (es) {
        if (es[0].isIntersecting) {
          vio.disconnect();
          vid.src = vid.getAttribute('data-src');
          vid.load();
          var p = vid.play();
          vid.addEventListener('playing', function () { vid.classList.add('on'); }, { once: true });
          if (p && p.catch) p.catch(function () {});
        }
      }, { rootMargin: '600px 0px' });
      vio.observe(vid.parentNode);
    }
  }

  /* máscara de telefone BR */
  $$('input[type=tel]').forEach(function (i) {
    i.addEventListener('input', function () {
      var v = i.value.replace(/\D/g, '').slice(0, 11), o = '';
      if (v.length > 0) o = '(' + v.slice(0, 2);
      if (v.length >= 3) o += ') ' + v.slice(2, v.length > 10 ? 7 : 6);
      if (v.length > (v.length > 10 ? 7 : 6)) o += '-' + v.slice(v.length > 10 ? 7 : 6);
      i.value = o;
    });
  });

  /* formulários */
  $$('form.lead-form').forEach(function (f) {
    var card = f.closest('.form-card');
    var s1 = $('[data-step="1"]', f), s2 = $('[data-step="2"]', f);
    var bars = $$('.steps-bar i', card);
    var next = $('[data-next]', f);
    var chosen = $('[data-chosen]', f);
    var radios = $$('input[name="fase"]', f);

    function fase() { var r = $('input[name="fase"]:checked', f); return r ? r.value : ''; }
    function enableNext() { if (next) { var ok = !!fase(); next.disabled = !ok; next.setAttribute('aria-disabled', String(!ok)); } }
    function go(n) {
      if (!s1 || !s2) return;
      s1.hidden = n !== 1; s2.hidden = n !== 2;
      bars.forEach(function (b, i) { b.classList.toggle('on', i < n); });
      if (chosen) chosen.textContent = fase();
      var hid = $('input[name="fase_texto"]', f); if (hid) hid.value = fase();
      var top = card.getBoundingClientRect().top + w.scrollY - (parseInt(getComputedStyle(d.documentElement).getPropertyValue('--hdr')) || 70) - 12;
      if (card.getBoundingClientRect().top < 0) w.scrollTo({ top: top, behavior: reduce ? 'auto' : 'smooth' });
      var first = n === 2 ? $('[name="nome"]', f) : null;
      if (first && !('ontouchstart' in w)) setTimeout(function () { first.focus({ preventScroll: true }); }, 60);
    }
    radios.forEach(function (r) { r.addEventListener('change', enableNext); });
    enableNext();
    if (next) next.addEventListener('click', function () { if (fase()) go(2); });
    var back = $('[data-back]', f); if (back) back.addEventListener('click', function () { go(1); });

    /* validação (fase de captura, antes de qualquer script da plataforma) */
    f.addEventListener('submit', function (e) {
      var bad = null;
      if (!fase()) { bad = $('.opts', f); }
      $$('[required]', f).forEach(function (el) {
        var fld = el.closest('.field'); if (fld) fld.classList.remove('err');
        if (!bad && el.type !== 'radio' && el.offsetParent !== null && !el.checkValidity()) { bad = el; if (fld) fld.classList.add('err'); }
      });
      var tel = $('input[type=tel]', f);
      if (!bad && tel && tel.value.replace(/\D/g, '').length < 10) { bad = tel; tel.setCustomValidity('Informe DDD e número'); tel.reportValidity(); tel.setCustomValidity(''); e.preventDefault(); e.stopImmediatePropagation(); return; }
      if (bad) {
        e.preventDefault(); e.stopImmediatePropagation();
        if (bad.classList && bad.classList.contains('opts')) { go(1); bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        else { if (s2 && s2.hidden && s2.contains(bad)) go(2); bad.reportValidity && bad.reportValidity(); }
      }
    }, true);
  });

  /* depois de qualquer script da plataforma: garante estado de sucesso e evita recarregar a página */
  d.addEventListener('submit', function (e) {
    var f = e.target; if (!f.classList || !f.classList.contains('lead-form')) return;
    if (!e.defaultPrevented) e.preventDefault();
    var card = f.closest('.form-card'), done = $('[data-done]', card);
    var body = $('.form-body', card) || f;
    f.hidden = true; if (done) { done.hidden = false; }
    var ev; try { ev = new CustomEvent('dts:lead', { detail: { form: f.id || '' } }); d.dispatchEvent(ev); } catch (x) {}
    if (w.dataLayer) w.dataLayer.push({ event: 'generate_lead', form_id: f.id || 'lead' });
  }, false);

  /* hero: o botão abre o formulário (painel ao lado no desktop, gaveta no celular) */
  var hero = $('.hero'), cta = $('[data-cta]'), hform = $('.hero-form');
  var wide = function () { return w.matchMedia('(min-width: 960px)').matches; };
  function setForm(open, focus) {
    if (!hero || !hform) return;
    hero.classList.toggle('open', open);
    if (cta) cta.setAttribute('aria-expanded', String(open));
    if (open) { hform.removeAttribute('inert'); } else { hform.setAttribute('inert', ''); }
    if (open) {
      var tgt = wide() ? $('#topo') : hform;
      setTimeout(function () { tgt.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); }, wide() ? 0 : 120);
      if (focus) { var f1 = $('input,select', hform); if (f1 && !('ontouchstart' in w)) setTimeout(function () { f1.focus({ preventScroll: true }); }, 450); }
    } else if (cta && !wide()) { cta.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' }); }
  }
  if (hform) hform.setAttribute('inert', '');
  if (cta) cta.addEventListener('click', function () { setForm(!hero.classList.contains('open'), true); });
  $$('[data-close]').forEach(function (b) { b.addEventListener('click', function () { setForm(false); if (cta) cta.focus({ preventScroll: true }); }); });
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && hero && hero.classList.contains('open')) setForm(false); });
  d.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href="#form"]');
    if (a) { e.preventDefault(); setForm(true, true); }
  });

  /* cards de fase → marcam no formulário e abrem o formulário do topo */
  var main = $('#form');
  $$('.ph[data-phase]').forEach(function (b) {
    b.addEventListener('click', function () {
      var r = $$('input[name="fase"]', main)[+b.getAttribute('data-phase')];
      if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
      setForm(true, false);
    });
  });

  /* FAQ: abre um por vez */
  var det = $$('.faq details');
  det.forEach(function (x) { x.addEventListener('toggle', function () { if (x.open) det.forEach(function (y) { if (y !== x) y.open = false; }); }); });
})();
