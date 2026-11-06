/* PD · Cesta — um enviador de arquivos que é um joguinho de basquete (quebra-gelo).
   pdCesta.monta(zona) troca o visual da área de soltar; pdCesta.arremessa(arquivos, aoPontuar) joga a bola e só então entrega os arquivos.
   Sem biblioteca: física própria (gravidade, aro, tabela), canvas 2D. Respeita prefers-reduced-motion e pode ser desligada. */
function pdCestaInstancia(){
  const G = 1500, R = 15;
  const REDUZ = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const ON = () => { try { return localStorage.getItem('pd_cesta') !== 'off'; } catch(e){ return true; } };
  const css = n => getComputedStyle(document.body).getPropertyValue(n).trim();
  let cv, cx, W = 0, H = 190, dpr = 1, zona = null, raf = 0, t0 = 0;
  const S = { bola: null, conf: [], rede: 0, placar: 0, msg: '', msgT: 0, tiro: null, hover: false, rotulo: '' };

  // ---- som sintetizado (sem arquivos): quique, aro, rede, torcida e festa
  const SOM_ON = () => { try { return localStorage.getItem('pd_cesta_som') !== 'off'; } catch(e){ return true; } };
  let AC = null, ruidoBuf = null;
  const ac = () => { if (!SOM_ON()) return null; try { AC = AC || new (window.AudioContext || window.webkitAudioContext)(); if (AC.state === 'suspended') AC.resume(); if (!ruidoBuf){ ruidoBuf = AC.createBuffer(1, AC.sampleRate * 2, AC.sampleRate); const d = ruidoBuf.getChannelData(0); for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1; } return AC; } catch(e){ return null; } };
  const env = (g, t, a, pico, d) => { g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(pico, t + a); g.gain.exponentialRampToValueAtTime(0.0001, t + a + d); };
  function tom(f0, f1, dur, tipo, vol, atraso){ const a = ac(); if (!a) return; const t = a.currentTime + (atraso || 0), o = a.createOscillator(), g = a.createGain(); o.type = tipo || 'sine'; o.frequency.setValueAtTime(f0, t); o.frequency.exponentialRampToValueAtTime(Math.max(20, f1), t + dur); env(g, t, .008, vol, dur); o.connect(g).connect(a.destination); o.start(t); o.stop(t + dur + .05); }
  function ruido(dur, f0, f1, q, vol, atraso, tipo){ const a = ac(); if (!a) return; const t = a.currentTime + (atraso || 0), s = a.createBufferSource(), fl = a.createBiquadFilter(), g = a.createGain(); s.buffer = ruidoBuf; s.loop = true; fl.type = tipo || 'bandpass'; fl.Q.value = q; fl.frequency.setValueAtTime(f0, t); fl.frequency.exponentialRampToValueAtTime(f1, t + dur); env(g, t, Math.min(.12, dur / 3), vol, dur); s.connect(fl).connect(g).connect(a.destination); s.start(t, Math.random()); s.stop(t + dur + .05); }
  const SOM = {
    quica: v => tom(150, 55, .16, 'sine', Math.min(.5, .12 + v / 1400)),
    aro: () => { tom(880, 700, .5, 'triangle', .16); tom(1320, 1180, .4, 'triangle', .1); ruido(.06, 3000, 2000, 1, .08, 0, 'highpass'); },
    rede: () => ruido(.28, 2500, 6500, 1.2, .22),
    uh: () => { tom(330, 170, .55, 'sawtooth', .07); tom(247, 130, .55, 'sawtooth', .05); ruido(.7, 600, 350, .8, .08); },
    festa: () => {
      ruido(1.9, 900, 1400, .6, .28);                                   // torcida em ondas
      ruido(1.4, 400, 700, .5, .16, .1);
      for (let i = 0; i < 26; i++) ruido(.05 + Math.random() * .05, 1800 + Math.random() * 2500, 2600, 1.5, .1 + Math.random() * .08, .15 + Math.random() * 1.6, 'bandpass');   // palmas
      [523, 659, 784, 1047].forEach((f, i) => { tom(f, f * 1.003, .22, 'square', .07, .05 + i * .09); tom(f / 2, f / 2, .22, 'triangle', .06, .05 + i * .09); });   // fanfarra
      tom(740, 760, .55, 'sawtooth', .06, .45); tom(740 * 1.5, 760 * 1.5, .55, 'sawtooth', .04, .45);                                                          // corneta
    }
  };
  const pos = () => ({ start: { x: 54, y: H - 30 }, aro: { l: W - 118, r: W - 70, y: 74 }, tab: W - 44 });

  function monta(z){
    if (!z || z.dataset.cesta) return; zona = z; z.dataset.cesta = '1';
    z.classList.add('cesta'); const ic = z.querySelector('.ic'); if (ic) ic.remove();
    const b = z.querySelector('b'), sm = z.querySelector('small'); z._b0 = b ? b.textContent : '';
    const box = document.createElement('div'); box.className = 'cesta-box'; box.innerHTML = '<canvas></canvas>';
    z.prepend(box); cv = box.querySelector('canvas'); cx = cv.getContext('2d');
    const off = document.createElement('a'); off.href = '#'; off.className = 'cesta-off'; off.textContent = 'desligar a cesta';
    off.onclick = e => { e.preventDefault(); e.stopPropagation(); try { localStorage.setItem('pd_cesta', 'off'); } catch(_){} desmonta(); };
    z.appendChild(off); const sm2 = document.createElement('a'); sm2.href = '#'; sm2.className = 'cesta-off'; sm2.style.cssText = 'display:inline-block;margin-left:12px'; const rot = () => { sm2.textContent = SOM_ON() ? 'som: ligado' : 'som: desligado'; }; rot(); sm2.onclick = e => { e.preventDefault(); e.stopPropagation(); try { localStorage.setItem('pd_cesta_som', SOM_ON() ? 'off' : 'on'); } catch(_){} rot(); }; off.style.display = 'inline-block'; z.appendChild(sm2); z._b = b; z._sm = sm;
    if (!document.getElementById('cestaCss')){ const est = document.createElement('style'); est.id = 'cestaCss'; est.textContent = `.nl-drop.cesta{padding:10px 14px 12px}.cesta-box{width:100%;height:190px;position:relative;margin-bottom:6px}.cesta-box canvas{width:100%;height:100%;display:block;pointer-events:none}.cesta-off{display:block;margin-top:6px;font-size:11px;color:var(--text3);text-decoration:underline}`; document.head.appendChild(est); }
    redim(); addEventListener('resize', redim);
    z.addEventListener('dragenter', () => { S.hover = true; }); z.addEventListener('dragleave', () => { S.hover = false; }); z.addEventListener('drop', () => { S.hover = false; });
    if (b) b.textContent = /zip/i.test(z.id) ? 'Jogue a pasta (ZIP) na cesta' : 'Jogue o arquivo na cesta';
    if (sm) sm.innerHTML = 'Arraste aqui ou toque para escolher · ' + sm.innerHTML;
    S.bola = { x: pos().start.x, y: pos().start.y, vx: 0, vy: 0, rot: 0, parada: true };
    laco();
  }
  function desmonta(){ cancelAnimationFrame(raf); removeEventListener('resize', redim); if (!zona) return; const b = zona.querySelector('.cesta-box'); b && b.remove(); zona.querySelectorAll('.cesta-off').forEach(x => x.remove()); zona.classList.remove('cesta'); delete zona.dataset.cesta; if (!document.querySelector('.nl-drop.cesta')) document.getElementById('cestaCss')?.remove(); if (zona._b) zona._b.textContent = zona._b0 || ''; zona = null; cv = null; }
  function redim(){ if (!cv) return; dpr = Math.min(2, devicePixelRatio || 1); W = cv.parentElement.clientWidth || 400; cv.width = W * dpr; cv.height = H * dpr; if (S.bola && S.bola.parada){ S.bola.x = pos().start.x; S.bola.y = pos().start.y; } }

  // ---- o arremesso: resolve a parábola que passa pelo centro do aro, descendo
  function solve(de, alvo, ruido){
    const pico = Math.min(de.y, alvo.y) - 70 - Math.random() * 18;
    const t1 = Math.sqrt(2 * (de.y - pico) / G), t2 = Math.sqrt(2 * (alvo.y - pico) / G), T = t1 + t2;
    return { vx: (alvo.x + ruido - de.x) / T, vy: -G * t1 };
  }
  function arremessa(arquivos, aoPontuar){
    const n = arquivos.length;
    if (!cv || REDUZ || !ON()){ aoPontuar(); return; }
    S.rotulo = n > 1 ? String(n) : '';
    const p = pos(); let tentativa = 0; S.placar = 0;
    const lanca = () => { const ruido = tentativa >= 1 ? 0 : (Math.random() * 14 - 7); const v = solve(p.start, { x: (p.aro.l + p.aro.r) / 2, y: p.aro.y }, ruido);
      S.bola = { x: p.start.x, y: p.start.y, vx: v.vx, vy: v.vy, rot: 0, parada: false, dentro: false, passou: false }; S.tiro = { aoPontuar, n, tentativa }; };
    S.pend = () => { if (tentativa < 2){ tentativa++; SOM.uh(); S.msg = 'quase…'; S.msgT = 0.9; lanca(); } };
    ac(); tom(520, 780, .08, 'sine', .05); lanca();
  }
  function pontua(){
    const t = S.tiro; if (!t || t.feito) return; t.feito = true; SOM.rede(); setTimeout(SOM.festa, 120); S.rede = 1; S.msg = t.n > 1 ? t.n + ' arquivos na cesta!' : 'Na cesta!'; S.msgT = 1.6; S.placar++;
    const p = pos(); for (let i = 0; i < 46; i++){ const a = Math.random() * Math.PI * 2, v = 120 + Math.random() * 260; S.conf.push({ x: (p.aro.l + p.aro.r) / 2, y: p.aro.y + 14, vx: Math.cos(a) * v, vy: Math.sin(a) * v - 160, c: ['#ff6a3d', '#ffc83d', '#3dd6a0', '#4aa3ff', '#ff5d9e'][i % 5], vida: 1 + Math.random() * .7 }); }
    setTimeout(() => { try { t.aoPontuar(); } catch(e){} }, 650);
  }
  function passo(dt){
    const b = S.bola, p = pos(); if (!b || b.parada) return;
    const ant = { x: b.x, y: b.y };
    b.vy += G * dt; b.x += b.vx * dt; b.y += b.vy * dt; b.rot += b.vx * dt / R;
    // tabela
    if (b.x + R > p.tab){ b.x = p.tab - R; b.vx = -Math.abs(b.vx) * .55; }
    // pontas do aro (colisão círculo-círculo)
    [p.aro.l, p.aro.r].forEach(rx => { const dx = b.x - rx, dy = b.y - p.aro.y, d = Math.hypot(dx, dy), m = R + 3; if (d < m && d > 0){ const nx = dx / d, ny = dy / d; b.x = rx + nx * m; b.y = p.aro.y + ny * m; const vn = b.vx * nx + b.vy * ny; if (vn < 0){ b.vx -= 1.55 * vn * nx; b.vy -= 1.55 * vn * ny; S.rede = Math.max(S.rede, .35); if (!b.sr || performance.now() - b.sr > 120){ SOM.aro(); b.sr = performance.now(); } } } });
    // atravessou o aro por cima, descendo, dentro do vão = pontos
    if (ant.y < p.aro.y && b.y >= p.aro.y && b.x > p.aro.l && b.x < p.aro.r && b.vy > 0) { b.dentro = true; pontua(); }
    if (b.dentro){ b.vx *= .92; b.x += ((p.aro.l + p.aro.r) / 2 - b.x) * Math.min(1, dt * 6); }
    if (!b.dentro && S.tiro && !S.tiro.feito && b.vy > 0 && b.y > p.aro.y + 70 && S.pend){ S.pend(); return; }
    // chão
    const chao = H - 14 - R; if (b.y > chao){ if (Math.abs(b.vy) > 90) SOM.quica(Math.abs(b.vy)); b.y = chao; b.vy *= -.45; b.vx *= .7; if (Math.abs(b.vy) < 60) b.vy = 0; }
    if (b.y >= chao - 1 && Math.abs(b.vx) < 8 && Math.abs(b.vy) < 8 || b.x < -40 || b.y > H + 60){ if (!b.dentro && S.tiro && !S.tiro.feito && S.pend){ S.pend(); } else if (b.dentro || (S.tiro && S.tiro.feito)){ setTimeout(volta, 500); b.parada = true; } }
  }
  function volta(){ const p = pos(); S.bola = { x: p.start.x, y: p.start.y, vx: 0, vy: 0, rot: 0, parada: true }; S.tiro = null; S.rotulo = ''; }
  function desenha(tt){
    const p = pos(), ink = css('--text') || '#111', mut = css('--text3') || '#888'; cx.setTransform(dpr, 0, 0, dpr, 0, 0); cx.clearRect(0, 0, W, H);
    // chão
    cx.strokeStyle = mut; cx.globalAlpha = .35; cx.lineWidth = 1; cx.beginPath(); cx.moveTo(10, H - 14); cx.lineTo(W - 10, H - 14); cx.stroke(); cx.globalAlpha = 1;
    // poste e tabela
    cx.fillStyle = mut; cx.globalAlpha = .5; cx.fillRect(p.tab + 8, p.aro.y - 48, 6, H - 14 - (p.aro.y - 48)); cx.globalAlpha = 1;
    cx.fillStyle = S.hover ? '#ffc83d' : ink; cx.globalAlpha = S.hover ? .9 : .85; cx.fillRect(p.tab, p.aro.y - 48, 8, 66); cx.globalAlpha = 1;
    // rede (balança quando pontua)
    const bal = Math.sin(tt * 18) * 5 * S.rede, topo = p.aro.y, base = topo + 34;
    cx.strokeStyle = ink; cx.globalAlpha = .55; cx.lineWidth = 1.2;
    for (let i = 0; i <= 6; i++){ const f = i / 6, x1 = p.aro.l + (p.aro.r - p.aro.l) * f, x2 = (p.aro.l + 8) + (p.aro.r - p.aro.l - 16) * f + bal; cx.beginPath(); cx.moveTo(x1, topo); cx.lineTo(x2, base); cx.stroke(); }
    for (let k = 1; k <= 3; k++){ const y = topo + k * 9, ins = k * 2.7; cx.beginPath(); cx.moveTo(p.aro.l + ins + bal * k / 3, y); cx.lineTo(p.aro.r - ins + bal * k / 3, y); cx.stroke(); } cx.globalAlpha = 1;
    // aro
    cx.strokeStyle = S.hover ? '#ffc83d' : '#ff5a36'; cx.lineWidth = 4; cx.lineCap = 'round'; cx.beginPath(); cx.moveTo(p.aro.l, p.aro.y); cx.lineTo(p.aro.r + 22, p.aro.y); cx.stroke();
    // bola
    const b = S.bola; if (b){ const idle = b.parada ? Math.abs(Math.sin(tt * 3)) * 5 : 0; cx.save(); cx.translate(b.x, b.y - idle); cx.rotate(b.rot);
      cx.fillStyle = '#f08a3c'; cx.beginPath(); cx.arc(0, 0, R, 0, 7); cx.fill(); cx.strokeStyle = '#6b3410'; cx.lineWidth = 1.4; cx.beginPath(); cx.arc(0, 0, R, 0, 7); cx.moveTo(-R, 0); cx.lineTo(R, 0); cx.moveTo(0, -R); cx.lineTo(0, R); cx.moveTo(-R * .7, -R * .7); cx.quadraticCurveTo(0, -2, -R * .7, R * .7); cx.moveTo(R * .7, -R * .7); cx.quadraticCurveTo(0, 2, R * .7, R * .7); cx.stroke();
      if (S.rotulo){ cx.rotate(-b.rot); cx.fillStyle = '#fff'; cx.font = '700 12px system-ui'; cx.textAlign = 'center'; cx.textBaseline = 'middle'; cx.fillText(S.rotulo, 0, 1); } cx.restore(); }
    // confete
    S.conf.forEach(c => { cx.globalAlpha = Math.max(0, c.vida); cx.fillStyle = c.c; cx.fillRect(c.x, c.y, 5, 5); }); cx.globalAlpha = 1;
    // mensagem
    if (S.msgT > 0){ cx.globalAlpha = Math.min(1, S.msgT * 2); cx.fillStyle = ink; cx.font = '600 15px system-ui'; cx.textAlign = 'center'; cx.fillText(S.msg, W / 2 - 20, 34); cx.globalAlpha = 1; }
    else if (!S.tiro){ cx.fillStyle = mut; cx.font = '12px system-ui'; cx.textAlign = 'left'; cx.fillText(S.hover ? 'solta!' : 'arraste, solte e confira a mira', 14, 26); }
  }
  function laco(){ t0 = performance.now(); let last = t0; const f = now => { if (!cv) return; if (cv.parentElement.clientWidth && Math.abs(cv.parentElement.clientWidth - W) > 2) redim(); const dt = Math.min(.033, (now - last) / 1000); last = now;
      for (let k = 0; k < 2; k++) passo(dt / 2);
      S.rede = Math.max(0, S.rede - dt * .8); S.msgT = Math.max(0, S.msgT - dt); S.conf = S.conf.filter(c => (c.vida -= dt) > 0); S.conf.forEach(c => { c.vy += 700 * dt; c.x += c.vx * dt; c.y += c.vy * dt; });
      desenha((now - t0) / 1000); raf = requestAnimationFrame(f); }; raf = requestAnimationFrame(f); }
  return { monta: z => { if (ON()) monta(z); }, arremessa };
}
window.pdCesta = {
  monta: z => { if (!z || z._cesta) return; const i = pdCestaInstancia(); z._cesta = i; i.monta(z); },
  arremessa: (f, cb, z) => (z && z._cesta) ? z._cesta.arremessa(f, cb) : cb()
};
