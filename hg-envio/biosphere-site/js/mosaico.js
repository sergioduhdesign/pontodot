/* Mosaico da home: blocos de tamanhos variados que preenchem a área de ponta a ponta (sem buracos). */
(function () {
  var box = document.querySelector('.portfolio-page > .portfolio-box');
  if (!box) return;
  var all = [].slice.call(box.querySelectorAll('.project-post'));
  var sel = '*';
  var T = { big: [2, 2, 3], wide: [2, 1, 1], tall: [1, 2, 1] }; // [largura, altura, área extra]

  function cols() { var w = window.innerWidth; return w <= 991 ? 2 : (w <= 1300 ? 3 : 4); }

  function plan(n, C) {
    var R = Math.max(Math.ceil(n / C), Math.round(n * 1.5 / C)), extra = C * R - n;
    for (var v = 0; v < 6; v++) {
      var order = ['big', 'wide', 'tall', 'wide', 'tall', 'big', 'wide', 'tall'], cnt = { big: 0, wide: 0, tall: 0 }, e = extra, i = v;
      var guard = 0;
      while (e > 0 && guard++ < 60) {
        var t = order[i++ % order.length];
        if (C < 2) break;
        if (T[t][2] <= e && !(t !== 'tall' && C < 2) && !(t === 'big' && (C < 2 || R < 2))) { cnt[t]++; e -= T[t][2]; }
        else if (e >= 1) { var alt = (e >= 1 && (i % 2)) ? 'wide' : 'tall'; if (alt === 'tall' && R < 2) alt = 'wide'; cnt[alt]++; e -= 1; }
      }
      if (e !== 0) continue;
      var r = solve(n, C, R, cnt, v);
      if (r) return { R: R, cells: r };
    }
    return null;
  }

  function solve(n, C, R, cnt, seed) {
    var occ = []; for (var r = 0; r < R; r++) occ.push(new Array(C).fill(0));
    var out = [], steps = 0, left = { big: cnt.big, wide: cnt.wide, tall: cnt.tall, one: n - cnt.big - cnt.wide - cnt.tall };
    if (left.one < 0) return null;
    var names = ['one', 'wide', 'tall', 'big'];
    function fits(r, c, w, h) { if (c + w > C || r + h > R) return false; for (var y = r; y < r + h; y++) for (var x = c; x < c + w; x++) if (occ[y][x]) return false; return true; }
    function put(r, c, w, h, v) { for (var y = r; y < r + h; y++) for (var x = c; x < c + w; x++) occ[y][x] = v; }
    function go(k) {
      if (k === n) return true; if (++steps > 40000) return false;
      var fr = -1, fc = -1;
      for (var r = 0; r < R && fr < 0; r++) for (var c = 0; c < C; c++) if (!occ[r][c]) { fr = r; fc = c; break; }
      if (fr < 0) return false;
      for (var j = 0; j < 4; j++) {
        var nm = names[(j + k + seed) % 4]; if (!left[nm]) continue;
        var w = nm === 'one' ? 1 : T[nm][0], h = nm === 'one' ? 1 : T[nm][1];
        if (!fits(fr, fc, w, h)) continue;
        put(fr, fc, w, h, 1); left[nm]--; out[k] = [fc, fr, w, h];
        if (go(k + 1)) return true;
        put(fr, fc, w, h, 0); left[nm]++;
      }
      return false;
    }
    return go(0) ? out : null;
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
    var p = plan(vis.length, C) || rows(vis.length, C);
    var cw = box.clientWidth / C, uh = cw * 0.75;
    box.style.height = Math.round(p.R * uh) + 'px';
    vis.forEach(function (el, i) {
      var c = p.cells[i];
      el.style.left = (c[0] / C * 100) + '%'; el.style.width = (c[2] / C * 100) + '%';
      el.style.top = Math.round(c[1] * uh) + 'px'; el.style.height = (Math.round((c[1] + c[3]) * uh) - Math.round(c[1] * uh)) + 'px';
    });
  }

  box.classList.add('mosaico');
  pack();
  window.addEventListener('resize', function () { pack(); });
  window.addEventListener('load', pack);
  [].slice.call(document.querySelectorAll('.filter a')).forEach(function (a) {
    a.addEventListener('click', function () { sel = a.getAttribute('data-filter') || '*'; pack(); });
  });
})();
