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
const nomeLimpo = s => String(s || 'arquivo').replace(/[^\w.\-]+/g, '_').slice(0, 120);

// ---------------------------------------------------------------- executores
const EXEC = {
  async eco(t, dir) {
    let ff = 'ffmpeg ausente'; try { ff = (await run('ffmpeg', ['-version'])).stdout.split('\n')[0]; } catch {}
    const txt = `Oficina viva.\nWorker: ${WORKER}\nHora: ${new Date().toISOString()}\n${ff}\nNode ${process.version}\nEntrada: ${JSON.stringify(t.entrada)}\n`;
    const f = path.join(dir, 'eco.txt'); fs.writeFileSync(f, txt);
    return { arquivos: [await subir(t, f, 'eco.txt', 'text/plain')], resultado: { ffmpeg: ff, node: process.version } };
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
