// CORTES INTELIGENTES — executores da Oficina: cortes_analise (baixa, transcreve) e cortes_render (corta, enquadra 9:16, legenda palavra por palavra).
const fs = require('fs'); const path = require('path'); const { execFile } = require('child_process'); const { promisify } = require('util'); const run = promisify(execFile);
const { Readable } = require('stream'); const { pipeline } = require('stream/promises');
module.exports = (H) => {
  const { PD, KEY, pd, log, subir, nomeLimpo } = H;
  const ff = (args) => run('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', ...args], { maxBuffer: 50e6, timeout: 20 * 60e3 });
  const dur = async (f) => parseFloat((await run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f])).stdout) || 0;
  const dimsDe = async (f) => { const [w, h] = (await run('ffprobe', ['-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', f])).stdout.trim().split('x').map(Number); return { w, h }; };
  async function api(acao, dados) {   // pd-cortes.php (lado da oficina)
    const r = await fetch(PD + '/pd-cortes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ acao, key: KEY, ...dados }) });
    const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.error || ('pd-cortes respondeu ' + r.status)); return j;
  }
  async function fonte(t, e, dir) {   // devolve o arquivo local do vídeo, venha de upload, link direto ou Drive
    const f = e.fonte || {}; const dest = path.join(dir, 'fonte.bin');
    if (f.tipo === 'drive') {
      const id = f.file_id; let pos = 0, total = Infinity; const fd = fs.openSync(dest, 'w');
      while (pos < total) { const r = await api('drive_parte', { file_id: id, inicio: pos }); const buf = Buffer.from(r.b64 || '', 'base64'); if (!buf.length) break; fs.writeSync(fd, buf); pos += buf.length; total = r.total || pos; if (r.fim) break; await log(t, `Baixando do Drive… ${Math.round(pos / 1048576)} MB`); }
      fs.closeSync(fd); if (pos < 1000) throw new Error('Não consegui ler o arquivo do Drive (permissão do link?).'); return dest;
    }
    const url = f.url; if (!/^https:\/\//i.test(url || '')) throw new Error('Link inválido (só https).');
    const r = await fetch(url, { redirect: 'follow' }); if (!r.ok) throw new Error('Download falhou (' + r.status + ').');
    const ct = r.headers.get('content-type') || ''; if (/text\/html/.test(ct)) throw new Error('Esse link é uma página, não um arquivo de vídeo. Use upload ou um link direto/Drive.');
    await pipeline(Readable.fromWeb(r.body), fs.createWriteStream(dest)); return dest;
  }
  async function analise(t, dir) {
    const e = t.entrada || {}; await log(t, 'Baixando o vídeo…'); const src = await fonte(t, e, dir);
    const d = await dur(src); if (!d) throw new Error('Não consegui ler esse vídeo.'); await log(t, `Vídeo com ${Math.round(d / 60 * 10) / 10} min. Extraindo o áudio…`);
    const PARTE = 600; const palavras = []; let idioma = null;
    for (let ini = 0, i = 0; ini < d; ini += PARTE, i++) {
      const mp3 = path.join(dir, `a${i}.mp3`); await ff(['-ss', String(ini), '-t', String(PARTE), '-i', src, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '32k', mp3]);
      if (fs.statSync(mp3).size < 2000) continue;
      await log(t, `Transcrevendo (${Math.min(i + 1, Math.ceil(d / PARTE))}/${Math.ceil(d / PARTE)})…`);
      let r; for (let k = 0; k < 2; k++) { try { r = await api('transcrever_parte', { b64: fs.readFileSync(mp3).toString('base64'), idioma: (e.opcoes || {}).idioma }); break; } catch (er) { if (k) throw er; } }
      idioma = idioma || r.idioma; for (const w of r.palavras || []) palavras.push([w[0], Math.round((w[1] + ini) * 100) / 100, Math.round((w[2] + ini) * 100) / 100]);
    }
    if (palavras.length < 10) throw new Error('Quase não achei fala nesse vídeo.');
    await log(t, `${palavras.length} palavras transcritas. Preparando a prévia…`);
    const kbps = Math.max(150, Math.min(900, Math.floor(38 * 8192 / d) - 64)); const prox = path.join(dir, 'proxy.mp4'); const { w, h } = await dimsDe(src);
    await ff(['-i', src, '-vf', h > w ? 'scale=-2:480' : 'scale=-2:360', '-c:v', 'libx264', '-preset', 'veryfast', '-b:v', kbps + 'k', '-maxrate', Math.round(kbps * 1.4) + 'k', '-bufsize', kbps * 2 + 'k', '-c:a', 'aac', '-b:a', '48k', '-ac', '1', '-movflags', '+faststart', prox]);
    return { arquivos: [await subir(t, prox, 'proxy.mp4', 'video/mp4')], resultado: { palavras, duracao: Math.round(d * 10) / 10, idioma, largura: w, altura: h } };
  }
  // ---- legenda ASS: grupos de até 3 palavras, a palavra falada em destaque
  const hex2ass = (c, a = 0) => { c = String(c || '#FFFFFF').replace('#', ''); if (c.length !== 6) c = 'FFFFFF'; return `&H${a.toString(16).padStart(2, '0').toUpperCase()}${c.slice(4, 6)}${c.slice(2, 4)}${c.slice(0, 2)}`.toUpperCase(); };
  const tAss = (s) => { s = Math.max(0, s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return `${h}:${String(m).padStart(2, '0')}:${x.toFixed(2).padStart(5, '0')}`; };
  function ass(palavras, o) {
    const cor = hex2ass(o.cor || '#FFFFFF'), dest = hex2ass(o.cor_destaque || '#FFD400'); const estilo = o.estilo || 'destaque'; const maius = o.maiusculas !== false;
    const pos = o.posicao === 'alto' ? 5 : o.posicao === 'meio' ? 5 : 2; const mv = o.posicao === 'alto' ? 380 : o.posicao === 'meio' ? 0 : 430; const al = o.posicao === 'alto' ? 8 : o.posicao === 'meio' ? 5 : 2;
    let s = `[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name,Fontname,Fontsize,PrimaryColour,SecondaryColour,OutlineColour,BackColour,Bold,Italic,Underline,StrikeOut,ScaleX,ScaleY,Spacing,Angle,BorderStyle,Outline,Shadow,Alignment,MarginL,MarginR,MarginV,Encoding\n`;
    s += `Style: L,${o.fonte || 'Montserrat'},${o.tamanho || 84},${cor},${cor},&H00000000,&H80000000,1,0,0,0,100,100,0,0,${estilo === 'caixa' ? 3 : 1},${estilo === 'limpo' ? 5 : 6},${estilo === 'caixa' ? 0 : 2},${al},70,70,${mv},1\n\n[Events]\nFormat: Layer,Start,End,Style,Name,MarginL,MarginR,MarginV,Effect,Text\n`;
    const grupos = []; let g = []; for (const w of palavras) { g.push(w); if (g.length >= 3 || /[.!?,]$/.test(w[0])) { grupos.push(g); g = []; } } if (g.length) grupos.push(g);
    const T = (x) => { x = String(x).replace(/[{}\\]/g, ''); return maius ? x.toUpperCase() : x; };
    for (const gr of grupos) for (let i = 0; i < gr.length; i++) {
      const ini = gr[i][1], fim = i + 1 < gr.length ? gr[i + 1][1] : Math.max(gr[i][2], ini + 0.25); if (fim <= ini) continue;
      const txt = gr.map((w, k) => estilo === 'limpo' ? T(w[0]) : (k === i ? `{\\c${dest}\\fscx112\\fscy112}${T(w[0])}{\\c${cor}\\fscx100\\fscy100}` : T(w[0]))).join(' ');
      s += `Dialogue: 0,${tAss(ini)},${tAss(fim)},L,,0,0,0,,${txt}\n`;
    } return s;
  }
  async function render(t, dir) {
    const e = t.entrada || {}; const o = e.opcoes || {}; const cortes = (e.cortes || []).filter(c => c.fim > c.ini);
    if (!cortes.length) throw new Error('Nenhum corte selecionado.'); await log(t, 'Baixando o vídeo original…'); const src = await fonte(t, e, dir); const { w, h } = await dimsDe(src);
    const arquivos = []; let n = 0;
    for (const c of cortes) {
      n++; await log(t, `Corte ${n}/${cortes.length}: ${c.titulo || ''}`);
      let cx = 0.5; if (o.enquadramento === 'rosto' && w / h > 9 / 16) { try { cx = parseFloat((await run('python3', [path.join(__dirname, 'rosto.py'), src, String(c.ini), String(c.fim)], { timeout: 180e3 })).stdout) || 0.5; } catch (_) { await log(t, 'Sem detector de rosto, usando o centro.'); } }
      const rel = (c.palavras || []).map(p => [p[0], Math.max(0, p[1] - c.ini), Math.max(0, p[2] - c.ini)]); const a = path.join(dir, `l${n}.ass`); fs.writeFileSync(a, ass(rel, o));
      const vf = (w / h > 9 / 16 ? `crop=floor(ih*9/16/2)*2:ih:max(0\\,min(iw-floor(ih*9/16/2)*2\\,${cx}*iw-floor(ih*9/16/2))):0,scale=1080:1920` : `scale=1080:-2,pad=1080:1920:0:(1920-ih)/2:black`) + `,ass=${a.replace(/\\/g, '/').replace(/:/g, '\\:')}:fontsdir=/usr/share/fonts`;
      const nome = nomeLimpo(`${String(n).padStart(2, '0')}-${(c.titulo || 'corte').slice(0, 50)}.mp4`); const out = path.join(dir, nome);
      await ff(['-ss', String(c.ini), '-to', String(c.fim), '-i', src, '-vf', vf, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', out]);
      arquivos.push(await subir(t, out, nome, 'video/mp4'));
    }
    return { arquivos, resultado: { cortes: cortes.length, enquadramento: o.enquadramento || 'centro', estilo: o.estilo || 'destaque' } };
  }
  return { analise, render, ass };
};
