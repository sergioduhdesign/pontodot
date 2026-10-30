// =====================================================================
// Pontodot — WORKER DA OFICINA
// Roda fora do HostGator (GitHub Actions hoje; um servidor próprio amanhã — é o mesmo arquivo).
// Ciclo: pegar → executar → subir arquivos no Storage → concluir. Repete até a fila esvaziar.
//
// Ambiente:  PD_URL (ex.: https://pd.sergioduh.com)   SD_OFICINA_KEY   WORKER_NOME (opcional)
// Módulos executados aqui: eco · ffmpeg · pptx · pdf   (os outros ainda não têm executor: voltam pra fila com erro claro)
// =====================================================================
const fs = require('fs'); const path = require('path'); const os = require('os');
const { execFile } = require('child_process'); const { promisify } = require('util'); const run = promisify(execFile);

const PD = (process.env.PD_URL || '').replace(/\/$/, ''); const KEY = process.env.SD_OFICINA_KEY || '';
const WORKER = process.env.WORKER_NOME || ('gha-' + (process.env.GITHUB_RUN_ID || process.pid));
if (!PD || !KEY) { console.error('Faltam PD_URL e SD_OFICINA_KEY.'); process.exit(1); }
const LIMITE_MIN = Number(process.env.OFICINA_MINUTOS || 25); const inicio = Date.now();

async function pd(acao, dados = {}) {
  const r = await fetch(PD + '/pd-oficina.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ acao, key: KEY, worker: WORKER, ...dados }) });
  const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.error || ('PD respondeu ' + r.status)); return j;
}
const log = (t, detalhe, dados) => pd('log', { trabalho_id: t.id, modulo: t.modulo, detalhe, dados }).catch(() => {});

async function baixar(url, destino) {
  if (!/^https:\/\//i.test(url)) throw new Error('Só baixo por https: ' + url);
  const r = await fetch(url); if (!r.ok) throw new Error('Download falhou (' + r.status + '): ' + url);
  fs.writeFileSync(destino, Buffer.from(await r.arrayBuffer())); return destino;
}
async function subir(t, arquivoLocal, nome, mime) {
  const { url, path: p } = await pd('assinar_upload', { trabalho_id: t.id, nome });
  const bytes = fs.readFileSync(arquivoLocal);
  const r = await fetch(url, { method: 'PUT', headers: { 'Content-Type': mime || 'application/octet-stream', 'x-upsert': 'true' }, body: bytes });
  if (!r.ok) throw new Error('Upload falhou (' + r.status + '): ' + (await r.text()).slice(0, 200));
  return { nome, path: p, bytes: bytes.length, mime };
}
// Reescreve os content streams do PDF (RGB do Chromium) trocando cores de marcação por CMYK real.
// Tabela fixa (barra de cores, marcas, preto) + mapa do kit do cliente {'#hex':[c,m,y,k] em 0..1}.
const CMYK_FIXO = { '#000000':[0,0,0,1], '#010101':[1,1,1,1], '#00aeef':[1,0,0,0], '#ec008c':[0,1,0,0], '#fff100':[0,0,1,0], '#2e3192':[1,1,0,0], '#ed1c24':[0,1,1,0], '#00a651':[1,0,1,0],
  '#6dcff6':[.5,0,0,0], '#f49ac1':[0,.5,0,0], '#fff799':[0,0,.5,0], '#575757':[0,0,0,.8], '#9d9d9d':[0,0,0,.5], '#d9d9d9':[0,0,0,.2] };
async function cmykExato(arq, mapaKit) {
  const tab = []; const hex2 = h => { const m = /^#?([0-9a-f]{6})$/i.exec(String(h).trim()); if (!m) return null; const n = parseInt(m[1], 16); return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255]; };
  for (const [h, k] of Object.entries(Object.assign({}, mapaKit, CMYK_FIXO))) { const rgb = hex2(h); if (rgb && Array.isArray(k) && k.length === 4) tab.push({ rgb, k: k.map(v => Math.max(0, Math.min(1, +v || 0))) }); }
  const qdf = arq + '.qdf'; await run('qpdf', ['--qdf', '--object-streams=disable', '--stream-data=uncompress', arq, qdf], { maxBuffer: 200e6 });
  let txt = fs.readFileSync(qdf, 'latin1'); let trocas = 0;
  const f4 = v => (Math.round(v * 1000) / 1000).toString();
  const acha = (r, g, b) => { let best = null, bd = 1; for (const e of tab) { const d = Math.abs(e.rgb[0] - r) + Math.abs(e.rgb[1] - g) + Math.abs(e.rgb[2] - b); if (d < bd) { bd = d; best = e; } } return bd <= 0.02 ? best : null; };
  const num = '(-?\\d*\\.?\\d+)';
  txt = txt.replace(/(\d+ 0 obj\s*<<[\s\S]*?>>\s*stream\r?\n)([\s\S]*?)(\r?\nendstream)/g, (m, cab, corpo, fim) => {
    if (/\/Subtype\s*\/Image|\/FontFile|\/Length1|\/XObject\s*$/.test(cab) && !/\/Subtype\s*\/Form/.test(cab)) return m;
    if (/\/Subtype\s*\/Image/.test(cab)) return m;
    let c = corpo.replace(new RegExp(`(^|\\s)${num}\\s+${num}\\s+${num}\\s+(rg|RG)(?=\\s|$)`, 'g'), (mm, pre, r, g, b, op) => { const e = acha(+r, +g, +b); if (!e) return mm; trocas++; return `${pre}${e.k.map(f4).join(' ')} ${op === 'rg' ? 'k' : 'K'}`; });
    c = c.replace(new RegExp(`(^|\\s)${num}\\s+(g|G)(?=\\s|$)`, 'g'), (mm, pre, v, op) => { const e = acha(+v, +v, +v); if (!e) return mm; trocas++; return `${pre}${e.k.map(f4).join(' ')} ${op === 'g' ? 'k' : 'K'}`; });
    // cor via colorspace ICC/sRGB (/CsN cs r g b sc|scn): mesma troca
    c = c.replace(new RegExp(`(^|\\s)${num}\\s+${num}\\s+${num}\\s+(sc|scn|SC|SCN)(?=\\s|$)`, 'g'), (mm, pre, r, g, b, op) => { const e = acha(+r, +g, +b); if (!e) return mm; trocas++; return `${pre}${e.k.map(f4).join(' ')} ${/^s/.test(op) ? 'k' : 'K'}`; });
    return cab + c + fim; });
  fs.writeFileSync(qdf, txt, 'latin1');
  const { stdout } = await run('fix-qdf', [qdf], { maxBuffer: 400e6, encoding: 'latin1' }); fs.writeFileSync(arq, stdout, 'latin1'); fs.unlinkSync(qdf);
  return trocas;
}
const nomeLimpo = s => String(s || 'arquivo').replace(/[^\w.\-]+/g, '_').slice(0, 120);

// ---------------------------------------------------------------- executores
const EXEC = {
  async eco(t, dir) {
    let ff = 'ffmpeg ausente'; try { ff = (await run('ffmpeg', ['-version'])).stdout.split('\n')[0]; } catch {}
    const txt = `Oficina viva.\nWorker: ${WORKER}\nHora: ${new Date().toISOString()}\n${ff}\nNode ${process.version}\nEntrada: ${JSON.stringify(t.entrada)}\n`;
    const f = path.join(dir, 'eco.txt'); fs.writeFileSync(f, txt);
    return { arquivos: [await subir(t, f, 'eco.txt', 'text/plain')], resultado: { ffmpeg: ff, node: process.version } };
  },
  // entrada: { texto, voz: pf_dora|pm_alex|pm_santa, velocidade: 1.0, nome } → locução MP3 (Kokoro-82M, português do Brasil, roda em CPU)
  async voz(t, dir) {
    const e = t.entrada || {}; const texto = String(e.texto || '').trim(); if (!texto) throw new Error('Sem texto para a locução.');
    const voz = /^p[fm]_[a-z]+$/.test(String(e.voz || '')) ? e.voz : 'pf_dora'; const vel = Math.max(.6, Math.min(1.6, Number(e.velocidade || 1)));
    await log(t, 'Preparando a voz (Kokoro, ' + voz + ')…');
    try { await run('python3', ['-c', 'import kokoro'], { timeout: 60e3 }); } catch { await log(t, 'Instalando o motor de voz (1ª vez na máquina: ~2 min)…'); await run('pip', ['install', '-q', 'kokoro>=0.9.4', 'soundfile', 'misaki[pt]'], { maxBuffer: 50e6, timeout: 15 * 60e3 }); }
    const py = path.join(dir, 'voz.py'); const txt = path.join(dir, 'texto.txt'); fs.writeFileSync(txt, texto);
    fs.writeFileSync(py, `import sys, soundfile as sf, numpy as np\nfrom kokoro import KPipeline\ntexto = open(sys.argv[1], encoding='utf-8').read()\npipe = KPipeline(lang_code='p')\npartes = []\nfor _, _, audio in pipe(texto, voice=sys.argv[2], speed=float(sys.argv[3]), split_pattern=r'\\n+'):\n    partes.append(np.asarray(audio)); partes.append(np.zeros(int(24000*0.35)))\nsf.write(sys.argv[4], np.concatenate(partes), 24000)\n`);
    const wav = path.join(dir, 'voz.wav'); await run('python3', [py, txt, voz, String(vel), wav], { maxBuffer: 50e6, timeout: 20 * 60e3 });
    const nome = nomeLimpo((e.nome || 'locucao') + '.mp3'); const mp3 = path.join(dir, nome);
    await run('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-i', wav, '-codec:a', 'libmp3lame', '-b:a', '192k', '-ar', '44100', mp3]);
    const dur = parseFloat((await run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp3])).stdout) || 0;
    await log(t, 'Locução pronta: ' + dur.toFixed(1) + ' s.');
    return { arquivos: [await subir(t, mp3, nome, 'audio/mpeg')], resultado: { voz, velocidade: vel, duracao_s: Math.round(dur * 10) / 10, caracteres: texto.length } };
  },
  // entrada: { arquivos:[{nome,url}], receita:[ ["-i","{in:base.mp4}","-i","{in:trilha.mp3}","-map","0:v","-map","1:a","-shortest","{out:final.mp4}"], ... ] }
  async ffmpeg(t, dir) {
    const e = t.entrada || {}; const ins = {};
    for (const a of (e.arquivos || [])) { const n = nomeLimpo(a.nome); ins[n] = await baixar(a.url, path.join(dir, 'in_' + n)); }
    const receitas = Array.isArray(e.receita) && Array.isArray(e.receita[0]) ? e.receita : [e.receita || []];
    if (!receitas[0].length) throw new Error('Receita do ffmpeg vazia.');
    const saidas = [];
    for (let i = 0; i < receitas.length; i++) {
      let out = null;
      const args = receitas[i].map(a => String(a).replace(/\{in:([^}]+)\}/g, (_, n) => { const k = nomeLimpo(n); if (!ins[k]) throw new Error('Arquivo de entrada não enviado: ' + n); return ins[k]; })
        .replace(/\{out:([^}]+)\}/g, (_, n) => { out = path.join(dir, 'out_' + nomeLimpo(n)); return out; }));
      for (const a of args) if (/^(https?|ftp|file|concat|data):/i.test(a) || a.includes('..')) throw new Error('Argumento não permitido: ' + a);
      if (!out) throw new Error('Receita ' + (i + 1) + ' sem {out:nome}.');
      await log(t, 'ffmpeg ' + (i + 1) + '/' + receitas.length);
      await run('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', ...args], { maxBuffer: 50e6, timeout: 20 * 60e3 });
      saidas.push(await subir(t, out, path.basename(out).replace(/^out_/, ''), 'video/mp4'));
    }
    return { arquivos: saidas };
  },
  // entrada: { titulo, tema:{fundo,cor,fonte}, slides:[{titulo, texto, bullets:[], imagem:url, notas}] }
  async pptx(t, dir) {
    const Pptx = require('pptxgenjs'); const e = t.entrada || {}; const tema = e.tema || {};
    const p = new Pptx(); p.layout = 'LAYOUT_WIDE'; const fonte = tema.fonte || 'Calibri'; const cor = (tema.cor || '1A1A1A').replace('#', ''); const fundo = (tema.fundo || 'FFFFFF').replace('#', '');
    for (const [i, s] of (e.slides || []).entries()) {
      const sl = p.addSlide(); sl.background = { color: fundo };
      if (s.titulo) sl.addText(String(s.titulo), { x: 0.6, y: 0.4, w: 12.1, h: 1.0, fontFace: fonte, fontSize: 30, bold: true, color: cor });
      const temImg = !!s.imagem; const wTxt = temImg ? 6.0 : 12.1;
      if (s.bullets && s.bullets.length) sl.addText(s.bullets.map(b => ({ text: String(b), options: { bullet: true, breakLine: true } })), { x: 0.6, y: 1.6, w: wTxt, h: 5.3, fontFace: fonte, fontSize: 18, color: cor, valign: 'top' });
      else if (s.texto) sl.addText(String(s.texto), { x: 0.6, y: 1.6, w: wTxt, h: 5.3, fontFace: fonte, fontSize: 18, color: cor, valign: 'top' });
      if (temImg) { try { const f = await baixar(s.imagem, path.join(dir, 'img_' + i)); sl.addImage({ path: f, x: 7.0, y: 1.6, w: 5.7, h: 5.3, sizing: { type: 'contain', w: 5.7, h: 5.3 } }); } catch (err) { await log(t, 'Imagem do slide ' + (i + 1) + ' falhou: ' + err.message); } }
      if (s.notas) sl.addNotes(String(s.notas));
    }
    const nome = nomeLimpo((e.titulo || 'apresentacao') + '.pptx'); const f = path.join(dir, nome); await p.writeFile({ fileName: f });
    return { arquivos: [await subir(t, f, nome, 'application/vnd.openxmlformats-officedocument.presentationml.presentation')] };
  },
  // VÍDEO v1 — entrada: { nome, w, h, fps, cenas:[{dur, trans:'corte'|'fade', fundo_url (PNG das camadas abaixo do clipe), topo_url (PNG transparente das camadas acima), clipes:[{url, ini, fim, x, y, w, h, vol, mudo}]}],
  //                       trilha:{url, vol, ini}, locucao:{url, vol, ini} }  → MP4 H.264/AAC
  async video(t, dir) {
    const tp = (t.entrada || {}).tipo; if (tp === 'cortes_analise') return CORTES.analise(t, dir); if (tp === 'cortes_render') return CORTES.render(t, dir);
    const e = t.entrada || {}; const W = +e.w || 1080, H = +e.h || 1920, fps = +e.fps || 30; const cenas = Array.isArray(e.cenas) ? e.cenas : [];
    if (!cenas.length) throw new Error('Vídeo sem cenas.');
    const esc = v => String(v).replace(/'/g, "\\'");
    const lista = [];
    for (let i = 0; i < cenas.length; i++) { const c = cenas[i]; const dur = Math.max(0.5, +c.dur || 4); await log(t, `Cena ${i + 1}/${cenas.length} (${dur}s)`);
      const args = ['-y', '-hide_banner', '-loglevel', 'error']; const fc = []; let idx = 0; const ins = [];
      const bg = c.fundo_url ? await baixar(c.fundo_url, path.join(dir, `bg${i}.png`)) : null;
      if (bg) { args.push('-loop', '1', '-t', String(dur), '-i', bg); ins.push('bg'); } else { args.push('-f', 'lavfi', '-t', String(dur), '-i', `color=c=${esc(c.cor || '#000000')}:s=${W}x${H}:r=${fps}`); ins.push('bg'); }
      const nf = Math.round(dur * fps); let v;
      if (bg && c.movimento === 'zoom') v = `[0:v]scale=${W * 2}:${H * 2},zoompan=z='1+0.12*min(on/${nf},1)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=${W}x${H}:fps=${fps},format=rgba[v0]`;
      else if (bg && c.movimento === 'zoom-out') v = `[0:v]scale=${W * 2}:${H * 2},zoompan=z='1.12-0.12*min(on/${nf},1)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=${W}x${H}:fps=${fps},format=rgba[v0]`;
      else if (bg && c.movimento === 'pan') v = `[0:v]scale=${W * 2}:${H * 2},zoompan=z='1.1':d=1:x='(iw-iw/zoom)*min(on/${nf},1)':y='ih/2-(ih/zoom/2)':s=${W}x${H}:fps=${fps},format=rgba[v0]`;
      else v = '[0:v]scale=' + W + ':' + H + ',format=rgba[v0]';
      fc.push(v); let cur = 'v0'; let audioClip = null;
      // PACKSHOT / ASSINATURA: fundo na cor do cliente + logo animado + tagline (PNG feito no Studio com a fonte do kit)
      if (c.packshot && c.packshot.logo_url) { const pk = c.packshot; const lg = await baixar(pk.logo_url, path.join(dir, `logo${i}.png`)); args.push('-loop', '1', '-framerate', String(fps), '-t', String(dur), '-i', lg); const n = ++idx;
        const lw = Math.max(2, Math.round(+pk.w || W * .4)); let lh = lw; try { const pr = await run('ffprobe', ['-v', 'error', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', lg]); const [pw, ph] = pr.stdout.trim().split(',').map(Number); if (pw && ph) lh = Math.round(lw * ph / pw); } catch {} const cx = Math.round((W - lw) / 2), cy = Math.round(H * (+pk.y || .42) - lh / 2); const ease = `(1-pow(1-min(t/0.9,1),3))`; const anim = String(pk.anim || 'fade');
        let pre = `[${n}:v]scale=${lw}:-1,format=rgba`; let ox = String(cx), oy = String(cy);
        if (anim === 'fade') pre += `,fade=t=in:st=0.15:d=0.8:alpha=1`;
        else if (anim === 'zoom') pre = `[${n}:v]scale=${lw * 2}:-1,zoompan=z='1.6-0.6*min(on/${Math.round(fps * .9)},1)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=${lw}x${lh}:fps=${fps},format=rgba,fade=t=in:st=0:d=0.4:alpha=1`;
        else if (anim === 'zoom-out') pre = `[${n}:v]scale=${lw * 2}:-1,zoompan=z='1+0.5*(1-min(on/${Math.round(fps * .9)},1))':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=${lw}x${lh}:fps=${fps},format=rgba,fade=t=in:st=0:d=0.3:alpha=1`;
        else if (anim === 'slide-esq') ox = `${-lw}+(${cx}+${lw})*${ease}`;
        else if (anim === 'slide-dir') ox = `${W}-(${W}-${cx})*${ease}`;
        else if (anim === 'slide-cima') oy = `${-Math.round(H * .3)}+(${cy}+${Math.round(H * .3)})*${ease}`;
        else if (anim === 'slide-baixo') oy = `${H}-(${H}-${cy})*${ease}`;
        else if (anim === 'queda') oy = `${-Math.round(H * .3)}+(${cy}+${Math.round(H * .3)})*(1-pow(1-min(t/1.1,1),2))+if(lt(t,1.1),0,-40*sin(min((t-1.1)*6,3.14159)))`;
        else if (anim === 'wipe-h') pre += `,crop=w='max(2,min(iw,iw*min(t/0.9,1)))':h=ih:x=0:y=0`;
        else if (anim === 'wipe-v') pre += `,crop=w=iw:h='max(2,min(ih,ih*min(t/0.9,1)))':x=0:y=0`;
        else if (anim === 'pulso') pre += `,fade=t=in:st=0:d=0.3:alpha=1`;
        fc.push(anim === 'cor-corta' && pk.fundo2 ? `${pre},split[lg][lg2]` : `${pre}[lg]`); fc.push(`[${cur}][lg]overlay=x='${ox}':y='${oy}':eval=frame:shortest=0[v${n}]`); cur = `v${n}`;
        if (anim === 'pulso') { fc.push(`[${cur}]scale=w='iw*(1+0.02*sin(t*4))':h='ih*(1+0.02*sin(t*4))':eval=frame,crop=${W}:${H}:(iw-${W})/2:(ih-${H})/2[v${n}p]`); cur = `v${n}p`; }
        if (anim === 'cor-corta' && pk.fundo2) { fc.push(`[${cur}]drawbox=c=${esc(pk.fundo2)}:t=fill:enable='gte(t,${(dur / 2).toFixed(2)})'[v${n}c]`); cur = `v${n}c`; fc.push(`[${cur}][lg2]overlay=${cx}:${cy}:enable='gte(t,${(dur / 2).toFixed(2)})'[v${n}d]`); cur = `v${n}d`; }
        if (pk.tag_url) { const tg = await baixar(pk.tag_url, path.join(dir, `tag${i}.png`)); args.push('-loop', '1', '-framerate', String(fps), '-t', String(dur), '-i', tg); const m = ++idx; fc.push(`[${m}:v]scale=${W}:${H},format=rgba,fade=t=in:st=${(dur * .4).toFixed(2)}:d=0.6:alpha=1[tg]`); fc.push(`[${cur}][tg]overlay=0:0[v${m}]`); cur = `v${m}`; } }
      for (const [k, cl] of (c.clipes || []).entries()) { const f = await baixar(cl.url, path.join(dir, `clip${i}_${k}.mp4`)); const ini = Math.max(0, +cl.ini || 0); const fim = cl.fim ? Math.max(ini + .1, +cl.fim) : null;
        args.push('-stream_loop', '-1', '-ss', String(ini), ...(fim ? ['-to', String(fim)] : []), '-i', f); const n = ++idx; const cw = Math.max(2, Math.round(+cl.w || W)), ch = Math.max(2, Math.round(+cl.h || H));
        fc.push(`[${n}:v]scale=${cw}:${ch}:force_original_aspect_ratio=increase,crop=${cw}:${ch},setpts=PTS-STARTPTS,format=rgba[c${k}]`); fc.push(`[${cur}][c${k}]overlay=${Math.round(+cl.x || 0)}:${Math.round(+cl.y || 0)}:shortest=0[v${n}]`); cur = `v${n}`;
        const temAudio = ((await run('ffprobe', ['-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', f]).catch(() => ({ stdout: '' }))).stdout || '').trim() !== '';   // clipe de drone (DJI) vem sem faixa de áudio
        if (!cl.mudo && temAudio && audioClip === null) audioClip = { n, vol: cl.vol == null ? 1 : +cl.vol }; }
      if (c.topo_url) { const tp = await baixar(c.topo_url, path.join(dir, `top${i}.png`)); args.push('-loop', '1', '-t', String(dur), '-i', tp); const n = ++idx; fc.push(`[${n}:v]scale=${W}:${H},format=rgba[tp]`); fc.push(`[${cur}][tp]overlay=0:0[v${n}]`); cur = `v${n}`; }
      const fadeD = ['fade', 'preto', 'branco'].includes(c.trans) ? 0.5 : 0; const fc_ = c.trans === 'branco' ? ':c=white' : ''; const vfade = fadeD ? `,fade=t=in:st=0:d=${fadeD}${fc_},fade=t=out:st=${Math.max(0, dur - fadeD)}:d=${fadeD}${fc_}` : '';
      fc.push(`[${cur}]format=yuv420p,fps=${fps}${vfade}[vout]`);
      if (audioClip) fc.push(`[${audioClip.n}:a]volume=${audioClip.vol},apad,atrim=0:${dur},asetpts=PTS-STARTPTS[aout]`); else { args.push('-f', 'lavfi', '-t', String(dur), '-i', 'anullsrc=r=48000:cl=stereo'); fc.push(`[${++idx}:a]anull[aout]`); }
      const out = path.join(dir, `cena${i}.mp4`);
      args.push('-filter_complex', fc.join(';'), '-map', '[vout]', '-map', '[aout]', '-t', String(dur), '-r', String(fps), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-shortest', out);
      await run('ffmpeg', args, { maxBuffer: 50e6, timeout: 20 * 60e3 }); lista.push(out); }
    const txt = path.join(dir, 'lista.txt'); fs.writeFileSync(txt, lista.map(f => `file '${f.replace(/'/g, "'\\''")}'`).join('\n'));
    const junto = path.join(dir, 'junto.mp4'); await run('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', txt, '-c', 'copy', junto], { maxBuffer: 50e6, timeout: 10 * 60e3 });
    const total = cenas.reduce((n, c) => n + Math.max(0.5, +c.dur || 4), 0); const nome = nomeLimpo((e.nome || 'video') + '.mp4'); const fim = path.join(dir, nome);
    const fx = []; const args2 = ['-y', '-hide_banner', '-loglevel', 'error', '-i', junto]; let n = 0; const mix = ['[0:a]'];
    for (const [k, tr] of [['trilha', e.trilha], ['locucao', e.locucao]]) { if (!tr || !tr.url) continue; const ext = (() => { try { return path.extname(new URL(tr.url).pathname); } catch (e) { return path.extname(tr.url); } })() || '.m4a'; const f = await baixar(tr.url, path.join(dir, k + ext)); args2.push('-i', f); n++; const d = Math.round(Math.max(0, +tr.ini || 0) * 1000);
      const de = Math.max(0, +tr.de || 0); const corte = (de || tr.len != null) ? `atrim=start=${de}${tr.len != null ? ':end=' + (de + Math.max(.1, +tr.len)) : ''},asetpts=PTS-STARTPTS,` : '';
      fx.push(`[${n}:a]${corte}adelay=${d}|${d},volume=${tr.vol == null ? (k === 'trilha' ? .35 : 1) : +tr.vol},apad[a${n}]`); mix.push(`[a${n}]`); }
    if (n) { fx.push(`${mix.join('')}amix=inputs=${n + 1}:duration=first:normalize=0,atrim=0:${total}[am]`); args2.push('-filter_complex', fx.join(';'), '-map', '0:v', '-map', '[am]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-shortest', fim); await run('ffmpeg', args2, { maxBuffer: 50e6, timeout: 10 * 60e3 }); }
    else fs.copyFileSync(junto, fim);
    return { arquivos: [await subir(t, fim, nome, 'video/mp4')], resultado: { cenas: cenas.length, segundos: total, w: W, h: H } };
  },
  // entrada: { nome, html | html_url, formato:'A4'|'A5'|'Letter', largura_mm, altura_mm, margem_mm, paisagem,
  //            impresso:true → PDF para gráfica: CMYK (Ghostscript), texto em curvas, imagens sem reamostragem (300 dpi), sangria já incluída na medida }
  async pdf(t, dir) {
    const { chromium } = require('playwright'); const e = t.entrada || {};
    let html = e.html || ''; if (!html && e.html_url) html = fs.readFileSync(await baixar(e.html_url, path.join(dir, 'in.html')), 'utf8');
    if (!html) throw new Error('Sem HTML para virar PDF.');
    const nome = nomeLimpo((e.nome || 'documento') + '.pdf'); const f = path.join(dir, nome); const bruto = path.join(dir, 'rgb-' + nome);
    const br = await chromium.launch(); const pg = await br.newPage(); await pg.setContent(html, { waitUntil: 'networkidle' }); await pg.evaluate(() => document.fonts.ready); await pg.waitForTimeout(400);
    const opt = { path: e.impresso ? bruto : f, printBackground: true, preferCSSPageSize: true, margin: { top: (e.margem_mm ?? 0) + 'mm', right: (e.margem_mm ?? 0) + 'mm', bottom: (e.margem_mm ?? 0) + 'mm', left: (e.margem_mm ?? 0) + 'mm' } };
    if (e.largura_mm && e.altura_mm) { opt.width = e.largura_mm + 'mm'; opt.height = e.altura_mm + 'mm'; } else { opt.format = e.formato || 'A4'; opt.landscape = !!e.paisagem; }
    await pg.pdf(opt); await br.close();
    if (e.impresso) {
      // 1) cores exatas: preto → K 100; barra de cores, marcas e cores do kit com CMYK definido → valores reais (DeviceCMYK, o Ghostscript não mexe)
      try { await cmykExato(bruto, e.cmyk_map || {}); await log(t, 'Cores exatas aplicadas: preto 100% K, barra e marcas em CMYK real' + (Object.keys(e.cmyk_map || {}).length ? ', ' + Object.keys(e.cmyk_map).length + ' cor(es) do kit' : '') + '.'); }
      catch (err) { await log(t, 'Aviso: não consegui aplicar cores exatas (' + err.message + '); seguindo com conversão ICC.'); }
      await log(t, 'Convertendo para CMYK e curvas (Ghostscript)…');
      const icc = fs.existsSync(path.join(__dirname, 'icc', 'saida.icc')) ? path.join(__dirname, 'icc', 'saida.icc') : null;   // opcional: FOGRA39 / ISO Coated v2 colocado em oficina/icc/saida.icc
      const args = ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.6', '-dNoOutputFonts', '-sColorConversionStrategy=CMYK', '-dProcessColorModel=/DeviceCMYK', '-dOverrideICC=true',
        '-dDownsampleColorImages=false', '-dDownsampleGrayImages=false', '-dDownsampleMonoImages=false', '-dAutoFilterColorImages=false', '-dColorImageFilter=/FlateEncode', '-dEncodeColorImages=true', ...(icc ? ['-sOutputICCProfile=' + icc] : []), '-o', f, bruto];
      await run('gs', args, { maxBuffer: 50e6, timeout: 10 * 60e3 });
      const sang = Number(e.sangria_mm || 0); if (sang && e.largura_mm && e.altura_mm) await log(t, `Sangria de ${sang} mm incluída na página (${e.largura_mm}×${e.altura_mm} mm); corte final ${e.largura_mm - 2 * sang}×${e.altura_mm - 2 * sang} mm.`);
    }
    return { arquivos: [await subir(t, f, nome, 'application/pdf')], resultado: { impresso: !!e.impresso, cmyk: !!e.impresso, curvas: !!e.impresso } };
  }
};

const CORTES = require('./cortes.js')({ PD, KEY, pd, log, subir, nomeLimpo });
// ---------------------------------------------------------------- ciclo
(async () => {
  await pd('pulso').catch(() => {});
  let feitos = 0;
  while ((Date.now() - inicio) / 60e3 < LIMITE_MIN && feitos < 30) {
    const { trabalho: t, de_novo } = await pd('pegar');
    if (!t) { if (de_novo) continue; break; }
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pd-'));
    console.log('→', t.modulo, t.titulo || t.id);
    try {
      const ex = EXEC[t.modulo]; if (!ex) throw new Error('A oficina ainda não tem executor para o módulo "' + t.modulo + '".');
      const r = await ex(t, dir);
      await pd('concluir', { trabalho_id: t.id, arquivos: r.arquivos || [], resultado: r.resultado || {} }); console.log('  pronto'); feitos++;
    } catch (err) {
      console.error('  erro:', err.message);
      await pd('falhar', { trabalho_id: t.id, erro: err.message, definitivo: /não tem executor|não permitido|vazia|Sem HTML/.test(err.message) }).catch(() => {});
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }
  }
  console.log('Fim. Trabalhos feitos:', feitos);
})().catch(e => { console.error(e); process.exit(1); });
