// Junta as cenas com transições reais (xfade/acrossfade) sem mudar o tempo total: cada transição de d segundos
// ocupa d/2 antes e d/2 depois da emenda (a ponta do clipe que sai e o começo do que entra são estendidos com o quadro parado).
const { execFile } = require('child_process'); const { promisify } = require('util'); const run = promisify(execFile);
const NOMES = new Set(['fade', 'wipeleft', 'wiperight', 'wipeup', 'wipedown', 'slideleft', 'slideright', 'slideup', 'slidedown', 'circleopen', 'circleclose', 'zoomin', 'pixelize', 'hblur', 'radial', 'dissolve', 'fadeblack', 'fadewhite']);
module.exports = async function juntar(arquivos, durs, trans, saida) {
  // trans[i] = { nome, d } para a emenda entre i e i+1, ou null (corte seco)
  const n = arquivos.length, args = ['-y', '-hide_banner', '-loglevel', 'error']; arquivos.forEach(f => args.push('-i', f));
  const fc = []; const hs = i => (i > 0 && trans[i - 1]) ? trans[i - 1].d / 2 : 0, ts = i => (i < n - 1 && trans[i]) ? trans[i].d / 2 : 0;
  for (let i = 0; i < n; i++) {
    const h = hs(i), t = ts(i);
    fc.push(`[${i}:v]${h || t ? `tpad=start_mode=clone:start_duration=${h.toFixed(3)}:stop_mode=clone:stop_duration=${t.toFixed(3)},` : ''}format=yuv420p,setsar=1[pv${i}]`);
    fc.push(`[${i}:a]aresample=48000,aformat=channel_layouts=stereo${h ? `,adelay=${Math.round(h * 1000)}|${Math.round(h * 1000)}` : ''}${t ? `,apad=pad_dur=${t.toFixed(3)}` : ''}[pa${i}]`);
  }
  let rv = 'pv0', ra = 'pa0', B = durs[0];
  for (let i = 0; i < n - 1; i++) {
    const x = trans[i], nv = `rv${i}`, na = `ra${i}`;
    if (x) { const nome = NOMES.has(x.nome) ? x.nome : 'fade'; fc.push(`[${rv}][pv${i + 1}]xfade=transition=${nome}:duration=${x.d.toFixed(3)}:offset=${Math.max(0, B - x.d / 2).toFixed(3)}[${nv}]`); fc.push(`[${ra}][pa${i + 1}]acrossfade=d=${x.d.toFixed(3)}:c1=tri:c2=tri[${na}]`); }
    else { fc.push(`[${rv}][${ra}][pv${i + 1}][pa${i + 1}]concat=n=2:v=1:a=1[${nv}][${na}]`); }
    rv = nv; ra = na; B += durs[i + 1];
  }
  args.push('-filter_complex', fc.join(';'), '-map', `[${rv}]`, '-map', `[${ra}]`, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', saida);
  await run('ffmpeg', args, { maxBuffer: 50e6, timeout: 20 * 60e3 });
};
