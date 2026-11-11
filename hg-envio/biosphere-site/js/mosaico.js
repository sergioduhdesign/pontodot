/* Mosaico da home: blocos de tamanhos variados que preenchem a área de ponta a ponta (sem buracos). */
(function () {
  var box = document.querySelector('.portfolio-page > .portfolio-box');
  if (!box) return;
  var all = [].slice.call(box.querySelectorAll('.project-post'));
  var sel = '*';
  var Q = 1; // células quadradas; os blocos são retângulos de várias medidas
  var SHAPES = []; for (var _w = 1; _w <= 5; _w++) for (var _h = 1; _h <= 4; _h++) { var _a = _w * _h; if (_a >= 2 && _a <= 15) SHAPES.push([_w, _h]); }
  var TARGET = [12, 3, 4, 6, 3, 4, 8, 3, 4, 6, 3, 4, 6, 3];
  function cols() { var w = window.innerWidth; return w <= 991 ? 4 : (w <= 1300 ? 6 : 8); }

  function ratioOf(el) {
    var im = el.querySelector('img'); if (im && /\.svg(\?|$)/i.test(im.getAttribute('src') || '')) return 0;
    var r = im && im.naturalWidth && im.naturalHeight ? im.naturalWidth / im.naturalHeight : 1.5;
    return r;
  }
  function dist(sh, r) { return Math.abs(Math.log((Q * sh[0] / sh[1]) / r)); }

  function solve(n, C, R, cand, rem) {
    var occ = []; for (var r = 0; r < R; r++) occ.push(new Array(C).fill(0));
    var out = [], used = new Array(n).fill(false), steps = 0;
    function fits(r, c, w, h) { if (c + w > C || r + h > R) return false; for (var y = r; y < r + h; y++) for (var x = c; x < c + w; x++) if (occ[y][x]) return false; return true; }
    function put(r, c, w, h, v) { for (var y = r; y < r + h; y++) for (var x = c; x < c + w; x++) occ[y][x] = v; }
    function go(k) {
      if (k === n) { for (var y = 0; y < R; y++) for (var x = 0; x < C; x++) if (!occ[y][x]) return false; return true; }
      if (++steps > 60000) return false;
      var fr = -1, fc = -1;
      for (var r = 0; r < R && fr < 0; r++) for (var c = 0; c < C; c++) if (!occ[r][c]) { fr = r; fc = c; break; }
      if (fr < 0) return false;
      var tried = 0;
      for (var i = 0; i < n && tried < 7; i++) {
        if (used[i]) continue; tried++;
        for (var j = 0; j < cand[i].length; j++) {
          var sh = cand[i][j]; if (!fits(fr, fc, sh[0], sh[1])) continue;
          put(fr, fc, sh[0], sh[1], 1); used[i] = true; out[i] = [fc, fr, sh[0], sh[1]];
          if (go(k + 1)) return true;
          put(fr, fc, sh[0], sh[1], 0); used[i] = false;
        }
      }
      return false;
    }
    return go(0) ? out : null;
  }

  function plan(vis, C) {
    var n = vis.length, ratios = vis.map(ratioOf), shapes = SHAPES.filter(function (s) { return s[0] <= Math.ceil(C * 0.6); });
    var tols = [0.12, 0.2, 0.3, 0.5, 0.8, 9], t0 = Date.now();
    for (var t = 0; t < tols.length; t++) {
      var cand = ratios.map(function (r, i) {
        var tg = TARGET[i % TARGET.length] * (C / 8);
        var l = r === 0 ? shapes.filter(function (s) { return s[0] > 1 && s[1] > 1 && s[0] * s[1] <= 6; }) : shapes.filter(function (s) { return dist(s, r) <= tols[t]; });
        l.sort(function (a, b) { return ((r ? dist(a, r) * 6 : 0) + Math.abs(a[0] * a[1] - tg)) - ((r ? dist(b, r) * 6 : 0) + Math.abs(b[0] * b[1] - tg)); });
        return l.length ? l.slice(0, 10) : [[2, 2]];
      });
      var cells = cand.reduce(function (s, l) { return s + l[0][0] * l[0][1]; }, 0), R0 = Math.max(2, Math.round(cells / C));
      var tryR = []; for (var d = 0; d <= 5; d++) { tryR.push(R0 + d); if (d && R0 - d > 1) tryR.push(R0 - d); }
      for (var k = 0; k < tryR.length; k++) { var res = solve(n, C, tryR[k], cand); if (res) return { R: tryR[k], cells: res }; if (Date.now() - t0 > 1800) return null; }
    }
    return null;
  }

  function rows(n, C) { // plano B: linhas justificadas (sempre preenche)
    var R = Math.ceil(n / C), out = [], k = 0;
    for (var r = 0; r < R; r++) {
      var m = Math.min(C, n - k), base = Math.floor(C / m), rest = C - base * m, x = 0;
      for (var i = 0; i < m; i++) { var w = base + (i < rest ? 1 : 0); out.push([x, r, w, 1]); x += w; k++; }
    }
    return { R: R, cells: out };
  }

  function pack() {
    var C = cols(), vis = [];
    all.forEach(function (el) { var ok = sel === '*' || el.matches(sel); el.style.display = ok ? '' : 'none'; if (ok) vis.push(el); });
    if (!vis.length) return;
    var p = plan(vis, C) || rows(vis.length, C);
    var cw = box.clientWidth / C, uh = cw / Q;
    box.style.height = Math.round(p.R * uh) + 'px';
    vis.forEach(function (el, i) {
      var c = p.cells[i];
      el.style.left = (c[0] / C * 100) + '%'; el.style.width = (c[2] / C * 100) + '%';
      el.style.top = Math.round(c[1] * uh) + 'px'; el.style.height = (Math.round((c[1] + c[3]) * uh) - Math.round(c[1] * uh)) + 'px';
    });
  }

  box.classList.add('mosaico');
  var tm;
  function again() { clearTimeout(tm); tm = setTimeout(pack, 120); }
  pack();
  all.forEach(function (el) { var im = el.querySelector('img'); if (im && !im.complete) im.addEventListener('load', again); });
  window.addEventListener('resize', again);
  window.addEventListener('load', function () { pack(); box.classList.add('pronto'); });
  setTimeout(function () { box.classList.add('pronto'); }, 2500);
  [].slice.call(document.querySelectorAll('.filter a')).forEach(function (a) {
    a.addEventListener('click', function () { sel = a.getAttribute('data-filter') || '*'; pack(); });
  });
})();
