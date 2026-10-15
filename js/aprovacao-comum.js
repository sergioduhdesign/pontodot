// =====================================================================
// SD Workflow — APROVAÇÃO: módulo comum
//
// Uma fonte só para o que antes estava duplicado (e divergente) entre
// novo-lote.html, aprovacao.html (equipe) e ver.html (cliente):
//
//   AP.lerPacote(files, opts)   leitor de ZIP/pasta/arquivos soltos
//   AP.lerPlanilha(u8)          leitor do Excel modelo (7 colunas+)
//   AP.casarPecas(pecas, ...)   casa o que subiu com as peças do lote
//   AP.parseComentario(body)    separa ARTE / TEXTO / geral
//   AP.montarComentario(a, t)   monta o comentário a partir dos 2 campos
//   AP.falaHTML(body)           mostra o comentário com os rótulos
//   AP.gerarPDF(opts)           PDF 16:9 com cada arte na proporção real
//   AP.gerarExcelCorrecoes(o)   Excel de correções (mesmo modelo do upload)
//
// Depende de: JSZip (pacote), XLSX (planilhas), jsPDF (PDF), mammoth
// (opcional, .docx). Não depende de login: roda também no link público.
// =====================================================================
(function(){
'use strict';

const AP = {};

const IMG  = /\.(jpe?g|png|webp|gif)$/i;
const VID  = /\.(mp4|mov|webm|m4v)$/i;
const TXT  = /\.(txt|md)$/i;
const DOC  = /\.docx$/i;
const XLS  = /\.xlsx$/i;
const LIXO = /(__MACOSX|\.DS_Store|thumbs\.db|desktop\.ini|(^|\/)\._)/i;
AP.RE = { IMG, VID, TXT, DOC, XLS, LIXO };

// DATA DE PUBLICAÇÃO — sempre horário de Brasília, em qualquer navegador.
// Grava com o fuso escrito (-03:00): serve tanto se a coluna guardar fuso
// quanto se não guardar. Lê: sem fuso = Brasília.
AP.quando = function(v){
  if (!v) return null;
  if (v instanceof Date) return isNaN(v) ? null : v;
  const s = String(v).trim().replace(' ', 'T');
  const d = new Date(/(Z|[+\-]\d\d:?\d\d)$/i.test(s) ? s : s + '-03:00');
  return isNaN(d) ? null : d;
};
AP.dataBanco = function(dia, hora){
  return dia ? `${dia}T${String(hora || '09:00').slice(0,5)}:00-03:00` : null;
};
AP.instanteBanco = function(d){
  d = AP.quando(d); if (!d) return null;
  const p = new Intl.DateTimeFormat('en-CA', { timeZone:'America/Sao_Paulo', year:'numeric', month:'2-digit',
    day:'2-digit', hour:'2-digit', minute:'2-digit', hourCycle:'h23' }).formatToParts(d);
  const g = t => (p.find(x => x.type === t) || {}).value;
  return `${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}:00-03:00`;
};

AP.mime = function(nome){
  const e = String(nome||'').split('.').pop().toLowerCase();
  return ({ jpg:'image/jpeg', jpeg:'image/jpeg', png:'image/png', webp:'image/webp', gif:'image/gif',
            mp4:'video/mp4', mov:'video/quicktime', webm:'video/webm', m4v:'video/mp4',
            pdf:'application/pdf' })[e] || 'application/octet-stream';
};

AP.ehVideo = function(m){
  return ((m?.mime)||'').startsWith('video') || VID.test((m?.name || m?.url || '').split('?')[0]);
};

// ---------------------------------------------------------------------
// NORMALIZAÇÃO DE CHAVES — "01 - Carrossel Margens.jpg" e
// "carrossel_margens" precisam bater. Sem acento, sem extensão,
// separadores viram espaço.
// ---------------------------------------------------------------------
function norm(s){
  return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .toLowerCase().trim()
    .replace(/\.(jpe?g|png|webp|gif|mp4|mov|webm|m4v|pdf|txt|md|psd|ai|pptx?)$/i,'')
    .replace(/[\s_\-.]+/g,' ').trim();
}
// Tira só um número de ORDEM do início ("01 - Carrossel" → "carrossel").
// Datas como "02-10-Stories" ficam intactas: "02 10 stories" não pode
// virar "10 stories", senão o dia 02 e o dia 04 colidem.
function semNumero(s){ return norm(s).replace(/^\d+\s+(?=[^\d\s])/,'').trim(); }
function ultimo(path){ return String(path||'').split('/').filter(Boolean).pop() || ''; }
function variantes(lista){
  const out = new Set();
  lista.forEach(v => {
    if (!v) return;
    [norm(v), norm(ultimo(v)), semNumero(ultimo(v))].forEach(x => { if (x) out.add(x); });
  });
  return out;
}
AP.norm = norm;

// =====================================================================
// LEITOR DE PACOTE
// Aceita: um .zip, vários arquivos soltos, ou uma mistura (zip dentro
// da seleção é expandido). Regras (as mesmas de sempre):
//   · cada subpasta = uma peça (2+ imagens = carrossel, vídeo = reels,
//     "stor" no nome = stories)
//   · arquivos na raiz (ou numa pasta que tem subpastas) = posts soltos
//   · .xlsx no pacote manda no texto (casa por ARQUIVO_OU_PASTA / ID_PECA)
//   · .txt na pasta = legenda · .docx da semana = legenda (opcional)
// =====================================================================
async function entradas(files){
  const out = [];
  for (const f of files) {
    if (/\.zip$/i.test(f.name)) {
      const zip = await JSZip.loadAsync(f);
      Object.values(zip.files).forEach(z => {
        if (z.dir || LIXO.test(z.name)) return;
        out.push({ path: z.name, blob: () => z.async('blob'),
                   text: () => z.async('string'), buf: () => z.async('arraybuffer') });
      });
    } else if (!LIXO.test(f.name)) {
      const path = f.webkitRelativePath || f.name;
      out.push({ path, blob: async () => f, text: () => f.text(), buf: () => f.arrayBuffer() });
    }
  }
  return out;
}

function separarLegenda(txt){
  const tags = [], corpo = [];
  String(txt||'').split(/\r?\n/).forEach(l => {
    const t = l.trim();
    if (t && /^(#[^\s#]+[\s]*)+$/.test(t)) tags.push(t); else corpo.push(l);
  });
  return { legenda: corpo.join('\n').trim(), hashtags: tags.join(' ').trim() };
}
AP.separarLegenda = separarLegenda;

const DIAS_SEMANA = { segunda:1, terca:2, 'terça':2, quarta:3, quinta:4, sexta:5, sabado:6, 'sábado':6, domingo:7 };
function diaDoNome(texto){
  const t = (texto||'').toLowerCase();
  let m = t.match(/dia[\s._-]*(\d{1,2})/);
  if (m) return +m[1];
  for (const [nome, num] of Object.entries(DIAS_SEMANA)) if (t.includes(nome)) return num;
  m = t.match(/(?:^|[^\d])(\d{1,2})(?:[^\d]|$)/);
  return m ? +m[1] : null;
}

// .docx da semana: H2 = DIA N · H3 = peça (POST/STORY) · H4 = seção
async function lerDocSemana(arrayBuffer){
  const { value: html } = await mammoth.convertToHtml({ arrayBuffer });
  const body = new DOMParser().parseFromString(html, 'text/html').body;
  const dias = {};
  let dia = null, peca = null, secao = null;
  for (const elx of body.children) {
    const tag = elx.tagName, txt = (elx.textContent||'').trim();
    if (!txt || tag === 'H1') continue;
    if (tag === 'H2') {
      const m = txt.match(/dia\s*(\d{1,2})/i);
      if (m) { dia = dias[+m[1]] = { post: null, stories: [] }; peca = null; }
      continue;
    }
    if (!dia) continue;
    if (tag === 'H3') {
      if (/^(post|carrossel|reels|feed)/i.test(txt)) {
        const partes = txt.split('|');
        peca = { tipo:'post', titulo: (partes[1]||partes[0]).trim(), legenda:'' };
        dia.post = peca; secao = null;
      } else if (/^stor/i.test(txt)) {
        peca = { tipo:'story', texto:'' }; dia.stories.push(peca); secao = 'texto';
      } else { peca = null; }
      continue;
    }
    if (tag === 'H4') { secao = /legenda/i.test(txt) ? 'legenda' : null; continue; }
    if (!peca) continue;
    if (peca.tipo === 'post' && secao === 'legenda') peca.legenda += txt + '\n';
    if (peca.tipo === 'story') peca.texto += txt + '\n';
  }
  return dias;
}

// Lê o Excel — modelo novo (ARQUIVO_OU_PASTA…), o de correções (mesmas
// colunas + STATUS, CORRECAO_*, ID_PECA…) e o antigo de 12 colunas.
AP.lerPlanilha = function(u8){
  const wb = XLSX.read(u8, {type:'array'});
  const nome = wb.SheetNames.includes('IMPORTACAO') ? 'IMPORTACAO' : wb.SheetNames[0];
  const raw = XLSX.utils.sheet_to_json(wb.Sheets[nome], {header:1, defval:'', raw:false});

  let h = -1, novo = false;
  for (let i = 0; i < raw.length; i++){
    const up = raw[i].map(c => String(c).trim().toUpperCase());
    if (up.includes('ARQUIVO_OU_PASTA')) { h = i; novo = true; break; }
    if (up.includes('DIA')) { h = i; break; }
  }
  if (h < 0) throw new Error('Não achei o cabeçalho — preciso da coluna ARQUIVO_OU_PASTA (modelo novo) ou DIA (antigo).');

  const head = raw[h].map(x => String(x).trim().toUpperCase());
  const col = n => head.indexOf(n);
  const out = [];
  let temaAtual = '';

  raw.slice(h+1).forEach(l => {
    const g = nm => { const i = col(nm); return i >= 0 ? String(l[i]||'').trim() : ''; };
    const chave = novo ? g('ARQUIVO_OU_PASTA') : g('ARQUIVOS');
    if (!chave && !g('TITULO') && !g('LEGENDA') && !g('ID_PECA')) return;
    if (!novo && g('TEMA')) temaAtual = g('TEMA');

    let tipo = g('TIPO').toUpperCase();
    if (tipo === 'POST') tipo = 'FEED';

    const roteiro = novo ? [] : g('ROTEIRO').split('\n').map(x=>x.trim()).filter(Boolean)
      .map(l2 => { const p = l2.split('|').map(x => x.trim());
                   return { cena: p[0]||'', desc: p[1]||'', texto: p[2]||'' }; });

    out.push({
      id: g('ID_PECA') || null,
      dia: g('DIA') || '—',
      data: g('DATA'), hora: g('HORA'),
      tema: temaAtual,
      tipo: ['FEED','CARROSSEL','STORIES','REELS','MATERIAL'].includes(tipo) ? tipo : (novo ? '' : 'FEED'),
      titulo: g('TITULO'),
      headline: novo ? '' : g('HEADLINE'),
      arte: novo ? '' : g('DIRECAO_ARTE'),
      legenda: g('LEGENDA'), hashtags: g('HASHTAGS'),
      arquivos: chave.split(';').map(x => x.trim()).filter(Boolean),
      artes: g('ARTES').split(/[;\n]/).map(x => x.trim()).filter(Boolean),
      roteiro
    });
  });
  return out;
};

// =====================================================================
// TIPO DA PEÇA — deduzido, pra ninguém mudar 30 selects na mão.
// Ordem: o NOME do arquivo/pasta fala primeiro; depois o CONTEXTO da
// tarefa (ação, projeto, observação). Impresso/mídia = Material.
// O Excel (coluna TIPO), quando existe, ganha de tudo.
// =====================================================================
const RE_MATERIAL = /\b(press|imprensa|an[uú]ncio|anuncio|jornal|revista|folder|folheto|flyer|panfleto|outdoor|busdoor|front ?light|backlight|banner|faixa|totem|placa|empena|tapume|mupi|mobili[aá]rio|book|apresenta[cç][aã]o|catalogo|cat[aá]logo|manual|papelaria|cart[aã]o|convite|e-?mail ?mkt|email marketing|newsletter|r[aá]dio|spot|impresso|m[ií]dia off|off-?line|plotagem|adesivo|sinaliza[cç][aã]o|stand|lona|material)\b/i;
const RE_STORIES  = /stor(y|ies)/i;
const RE_REELS    = /\breels?\b/i;
const RE_CARROSSEL = /carross|carousel/i;
const RE_SOCIAL   = /\b(post|posts|feed|social|redes|insta|instagram|facebook|linkedin|tiktok|carross|carousel|stor(y|ies)|reels?)\b/i;

AP.deduzirTipo = function(nome, contexto, nArtes, temVideo){
  const n = norm(nome) + ' ' + String(nome || '').toLowerCase();
  const c = String(contexto || '').toLowerCase();
  // 1) o nome do arquivo/pasta
  if (RE_STORIES.test(n)) return 'stories';
  if (RE_REELS.test(n)) return 'reels';
  if (RE_CARROSSEL.test(n)) return 'carrossel';
  if (/\b(post|feed)\b/i.test(n)) return nArtes >= 2 ? 'carrossel' : 'feed';   // "post" no nome ganha de "revista" (nome de marca)
  if (RE_MATERIAL.test(n)) return 'material';
  // 2) o contexto da tarefa: impresso/mídia sem cara de rede social
  if (RE_MATERIAL.test(c) && !RE_SOCIAL.test(c)) return 'material';
  if (RE_STORIES.test(c) && !RE_SOCIAL.test(c.replace(/stor(y|ies)/gi, ''))) return 'stories';
  // 3) a estrutura
  if (temVideo) return 'reels';
  return nArtes >= 2 ? 'carrossel' : 'feed';
};

const KIND_XLS = { FEED:'feed', CARROSSEL:'carrossel', STORIES:'stories', REELS:'reels', MATERIAL:'material' };

// opts: { doc:true|false, onStatus:fn }
// Retorna { pecas:[...], linhas:[...] }
AP.lerPacote = async function(files, opts){
  opts = opts || {};
  const st = opts.onStatus || (()=>{});
  st('Lendo o pacote…');

  let todas = opts.entradas ? opts.entradas : await entradas([...files]);
  todas = await AP.expandirPdfs(todas, opts, st);

  const porPasta = {};
  todas.forEach(e => {
    const partes = e.path.split('/');
    const arquivo = partes.pop();
    const pasta = partes.join('/');
    (porPasta[pasta] = porPasta[pasta] || []).push({ e, nome: arquivo });
  });

  const pastas = Object.keys(porPasta).sort((a,b)=>a.localeCompare(b,'pt-BR',{numeric:true}));
  const ehMidia = a => IMG.test(a.nome) || VID.test(a.nome);
  const totalMidia = todas.filter(e => IMG.test(e.path) || VID.test(e.path)).length;
  let lidas = 0;
  const pecas = [];

  for (const pasta of pastas) {
    const arqs = porPasta[pasta];
    const midias = arqs.filter(ehMidia).sort((a,b)=>a.nome.localeCompare(b.nome,'pt-BR',{numeric:true}));
    const txts = arqs.filter(a => TXT.test(a.nome));
    if (!midias.length) continue;

    let legPasta = '', hashPasta = '';
    if (txts.length && pasta !== '') {
      const sep = separarLegenda(await txts[0].e.text());
      legPasta = sep.legenda; hashPasta = sep.hashtags;
    }

    const temSubpastaComMidia = pastas.some(p2 => p2 !== pasta && p2.startsWith(pasta + '/')
      && porPasta[p2].some(ehMidia));
    const individual = pasta === '' || temSubpastaComMidia;
    const grupos = individual
      ? midias.map(m => ({ nomeBase: m.nome.replace(/\.[^.]+$/,''), lista: [m], individual: true }))
      : [{ nomeBase: ultimo(pasta), lista: midias, individual: false }];

    for (const g of grupos) {
      let legenda = legPasta, hashtags = hashPasta;
      if (g.individual) {
        const t = txts.find(x => norm(x.nome) === norm(g.nomeBase));
        if (t) { const sep = separarLegenda(await t.e.text()); legenda = sep.legenda; hashtags = sep.hashtags; }
        else { legenda = ''; hashtags = ''; }
      }

      const fl = [];
      for (const m of g.lista) {
        const blob = await m.e.blob();
        const f2 = (blob instanceof File && blob.name === m.nome) ? blob
                 : new File([blob], m.nome, { type: AP.mime(m.nome) });
        fl.push({ file: f2, url: URL.createObjectURL(f2), nome: m.nome, path: m.e.path });
        lidas++;
        st(`Extraindo artes… ${lidas} de ${totalMidia}`);
      }

      const temVideo = fl.some(f => VID.test(f.nome));
      const kind = AP.deduzirTipo(g.nomeBase + ' ' + pasta, opts.contexto, fl.length, temVideo);

      pecas.push({
        titulo: g.nomeBase.replace(/^\d+[\s.\-_]*/,'').trim() || g.nomeBase,
        ordem: pasta + '/' + g.nomeBase,
        _pasta: pasta, _nomeBase: g.nomeBase, individual: g.individual,
        // A chave que vai pro banco (approval_items.source_key) e volta no
        // Excel como ARQUIVO_OU_PASTA: arquivo solto = nome do arquivo;
        // grupo = caminho da pasta dentro do pacote.
        chave: g.individual ? g.lista[0].nome : pasta,
        kind, files: fl, legenda, hashtags, fonteLegenda: legenda ? 'txt' : '',
        dataPub:'', horaPub:'09:00', linha: null
      });
    }
  }

  // ---- Excel no pacote: casa por chave e GANHA de .txt/.docx ----
  const linhas = [];
  for (const x of todas.filter(e => XLS.test(e.path))) {
    st('Lendo planilha ' + ultimo(x.path) + '…');
    try {
      const ls = AP.lerPlanilha(new Uint8Array(await x.buf()));
      ls.forEach(l => { l._fonte = ultimo(x.path); linhas.push(l); });
    } catch(e) { console.warn('Planilha não lida:', x.path, e.message); }
  }
  if (linhas.length) {
    const porChave = {};
    linhas.forEach(l => [...l.arquivos, ...l.artes].forEach(a => variantes([a]).forEach(k => { porChave[k] = porChave[k] || l; })));
    pecas.forEach(p => {
      const ks = [...variantes([p.chave, p._nomeBase, p._pasta]),
                  ...p.files.flatMap(f => [...variantes([f.nome, f.path])])];
      const l = ks.map(k => porChave[k]).find(Boolean);
      if (!l) return;
      p.linha = l; l._usada = true;
      if (l.legenda) { p.legenda = l.legenda; p.fonteLegenda = 'xlsx'; }
      if (l.hashtags) p.hashtags = l.hashtags;
      if (l.titulo) p.titulo = l.titulo;
      if (l.tipo && KIND_XLS[l.tipo]) p.kind = KIND_XLS[l.tipo];
      if (l.data) {
        const pp = l.data.split('/');
        if (pp.length === 3) p.dataPub = `${pp[2].length===2?'20'+pp[2]:pp[2]}-${pp[1].padStart(2,'0')}-${pp[0].padStart(2,'0')}`;
      }
      if (l.hora) p.horaPub = l.hora;
    });
  }

  // ---- .docx da semana: só preenche o que o Excel não resolveu ----
  if (opts.doc !== false && typeof mammoth !== 'undefined') {
    for (const d of todas.filter(e => DOC.test(e.path))) {
      st('Lendo textos de ' + ultimo(d.path) + '…');
      try {
        const dias = await lerDocSemana(await d.buf());
        const prefixo = d.path.split('/').slice(0,-1).join('/');
        pecas.forEach(p => {
          if (prefixo && !(p._pasta === prefixo || p._pasta.startsWith(prefixo + '/'))) return;
          const dia = diaDoNome(p._pasta.slice(prefixo.length) + ' ' + p._nomeBase);
          const info = dia != null ? dias[dia] : null;
          if (!info) return;
          if (p.kind === 'stories' && info.stories.length && !p.legenda) {
            p.legenda = info.stories.map((sx,i)=>`STORY ${i+1} — ${sx.texto.trim()}`).join('\n\n');
            p.fonteLegenda = 'doc';
          } else if (p.kind !== 'stories' && info.post) {
            if (!p.legenda && info.post.legenda.trim()) { p.legenda = info.post.legenda.trim(); p.fonteLegenda = 'doc'; }
            if (info.post.titulo) p.titulo = info.post.titulo;
          }
        });
      } catch(e) { console.warn('Doc não lido:', d.path, e.message); }
    }
  }

  pecas.sort((a,b)=>a.ordem.localeCompare(b.ordem,'pt-BR',{numeric:true}));
  return { pecas, linhas };
};

// =====================================================================
// CASAMENTO — o que subiu × as peças que já estão no lote
//
// Força da evidência (maior ganha; empate = ambíguo, a pessoa escolhe):
//   ID_PECA da planilha ........... 1000
//   source_key (chave original) .... 120 / 100
//   pasta original das artes ........ 90
//   nome do arquivo (peça de 1 arte)  70
//   título da peça ................... 50
// =====================================================================
function chavesItem(it){
  const media = Array.isArray(it.media) ? it.media : [];
  const k = { key: variantes([it.source_key]), pasta: new Set(), arquivo: new Set(), titulo: variantes([it.title]) };
  media.forEach(m => {
    if (m.path && m.path.includes('/')) variantes([m.path.split('/').slice(0,-1).join('/')]).forEach(x => k.pasta.add(x));
  });
  if (media.length === 1) variantes([media[0].name, media[0].path]).forEach(x => k.arquivo.add(x));
  return k;
}
function inter(a, b){ for (const x of a) if (b.has(x)) return true; return false; }

AP.pontuar = function(peca, it, ki){
  ki = ki || chavesItem(it);
  if (peca.linha && peca.linha.id && peca.linha.id === it.id) return 1000;
  const pk = variantes([peca.chave, peca._nomeBase, peca.individual ? '' : peca._pasta]);
  const pf = new Set(); peca.files.forEach(f => variantes([f.nome]).forEach(x => pf.add(x)));
  let s = 0;
  if (it.source_key && norm(it.source_key) === norm(peca.chave)) s = Math.max(s, 120);
  if (inter(pk, ki.key)) s = Math.max(s, 100);
  if (!peca.individual && inter(pk, ki.pasta)) s = Math.max(s, 90);
  if (peca.individual && inter(pf, ki.arquivo)) s = Math.max(s, 70);
  if (inter(pk, ki.titulo)) s = Math.max(s, 50);
  // Peça antiga (sem chave gravada): todas as artes que chegaram têm
  // nome de uma arte que já está nesta peça → é ela. Nomes genéricos
  // ("01.jpg") empatam entre peças e caem na escolha manual.
  const nomesItem = new Set();
  (it.media||[]).forEach(m => variantes([m.name, m.path]).forEach(x => nomesItem.add(norm(x))));
  if (peca.files.length && peca.files.every(f => nomesItem.has(norm(f.nome)))) s = Math.max(s, 80);
  if (peca.linha && peca.linha.titulo && inter(variantes([peca.linha.titulo]), ki.titulo)) s = Math.max(s, 50);
  return s;
};

// Devolve o id da peça do lote (ou null se não houver casamento claro)
AP.casarPeca = function(peca, items){
  let melhor = null, nota = 0, empate = false;
  items.forEach(it => {
    const s = AP.pontuar(peca, it);
    if (s > nota) { melhor = it.id; nota = s; empate = false; }
    else if (s && s === nota) empate = true;
  });
  return (nota && !empate) ? melhor : null;
};

AP.casarLinha = function(l, items){
  if (l.id && items.some(i => i.id === l.id)) return l.id;
  const ks = variantes([...l.arquivos, ...l.artes]);
  const tk = variantes([l.titulo]);
  let melhor = null, nota = 0, empate = false;
  items.forEach(it => {
    const ki = chavesItem(it);
    let s = 0;
    if (inter(ks, ki.key)) s = 100;
    else if (inter(ks, ki.pasta)) s = 90;
    else if (inter(ks, ki.arquivo)) s = 70;
    else if (inter(ks, ki.titulo) || inter(tk, ki.titulo)) s = 50;
    if (s > nota) { melhor = it.id; nota = s; empate = false; }
    else if (s && s === nota) empate = true;
  });
  return (nota && !empate) ? melhor : null;
};

// Dentro de uma peça: quais posições (slides) as artes novas substituem.
// Mesmo nome de arquivo (sem extensão) = mesma posição.
AP.casarSlots = function(media, novos){
  const mapa = {};          // índice antigo -> arquivo novo
  const sobra = [];         // novos sem par
  const usados = new Set();
  novos.forEach(f => {
    const nf = norm(f.nome);
    const i = (media||[]).findIndex((m, idx) => !usados.has(idx) &&
      (norm(m.name) === nf || norm(ultimo(m.path)) === nf));
    if (i >= 0) { mapa[i] = f; usados.add(i); } else sobra.push(f);
  });
  return { mapa, sobra };
};

// =====================================================================
// COMENTÁRIO DO CLIENTE — ARTE e TEXTO separados
// Gravado como texto simples (compatível com as funções do banco):
//   ARTE: aumentar o logo
//   TEXTO: trocar "lazer" por "bem-estar"
// =====================================================================
AP.montarComentario = function(arte, texto){
  const a = String(arte||'').trim(), t = String(texto||'').trim();
  const out = [];
  if (a) out.push('ARTE: ' + a);
  if (t) out.push('TEXTO: ' + t);
  return out.join('\n');
};

AP.parseComentario = function(body){
  const r = { arte:'', texto:'', geral:'' };
  let cur = 'geral';
  String(body||'').split(/\r?\n/).forEach(l => {
    const m = l.match(/^\s*(ARTE|CRIATIVO|TEXTO|COPY|LEGENDA)\s*:\s*(.*)$/i);
    if (m) {
      cur = /arte|criativo/i.test(m[1]) ? 'arte' : 'texto';
      r[cur] += (r[cur] ? '\n' : '') + m[2];
    } else {
      r[cur] += (r[cur] ? '\n' : '') + l;
    }
  });
  Object.keys(r).forEach(k => r[k] = r[k].trim());
  return r;
};

AP.falaHTML = function(body){
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const p = AP.parseComentario(body);
  if (!p.arte && !p.texto) return esc(body);
  const tag = (t) => `<span style="display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.06em;
    padding:1px 6px;border-radius:5px;background:#db2777;color:#fff;margin-right:5px;vertical-align:1px">${t}</span>`;
  return [p.geral && esc(p.geral),
          p.arte  && tag('ARTE')  + esc(p.arte),
          p.texto && tag('TEXTO') + esc(p.texto)].filter(Boolean).join('<br>');
};

// =====================================================================
// PDF — 16:9, uma página por peça, cada arte na PROPORÇÃO REAL.
// Stories saem 9:16, feed 4:5 ou 1:1, horizontal sai horizontal.
// A grade é escolhida para dar o maior tamanho possível às artes, e a
// coluna de texto ocupa o espaço que sobra (stories não deixam buraco).
// =====================================================================
function carregarImagem(url, maxPx){
  return new Promise(resolve => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const w0 = img.naturalWidth || img.width, h0 = img.naturalHeight || img.height;
        if (!w0 || !h0) return resolve(null);
        const k = Math.min(1, (maxPx || 1200) / Math.max(w0, h0));
        const c = document.createElement('canvas');
        c.width = Math.round(w0 * k); c.height = Math.round(h0 * k);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        resolve({ data: c.toDataURL('image/jpeg', 0.85), r: w0 / h0, w: w0, h: h0 });
      } catch(e){ resolve(null); }
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

// Primeiro quadro do vídeo (para reels/stories em vídeo aparecerem com
// a proporção certa em vez de um retângulo genérico).
function quadroVideo(url, maxPx){
  return new Promise(resolve => {
    const v = document.createElement('video');
    let feito = false;
    const fim = (x) => { if (!feito) { feito = true; v.removeAttribute('src'); resolve(x); } };
    const timer = setTimeout(() => fim(null), 8000);
    v.crossOrigin = 'anonymous'; v.muted = true; v.playsInline = true; v.preload = 'auto';
    v.onloadedmetadata = () => { try { v.currentTime = Math.min(0.5, (v.duration || 1) / 2); } catch(e){ } };
    v.onseeked = () => {
      try {
        const w0 = v.videoWidth, h0 = v.videoHeight;
        if (!w0 || !h0) { clearTimeout(timer); return fim(null); }
        const k = Math.min(1, (maxPx || 1200) / Math.max(w0, h0));
        const c = document.createElement('canvas');
        c.width = Math.round(w0 * k); c.height = Math.round(h0 * k);
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        clearTimeout(timer);
        fim({ data: c.toDataURL('image/jpeg', 0.82), r: w0 / h0, w: w0, h: h0, video: true });
      } catch(e){ clearTimeout(timer); fim(null); }
    };
    v.onerror = () => { clearTimeout(timer); fim(null); };
    v.src = url;
  });
}
AP.carregarImagem = carregarImagem;

function nomeProporcao(w, h){
  if (!w || !h) return '';
  const r = w / h;
  const conhecidas = [[9/16,'9:16'],[4/5,'4:5'],[1,'1:1'],[16/9,'16:9'],[1.91,'1.91:1'],[2/3,'2:3'],[3/4,'3:4'],[4/3,'4:3']];
  const c = conhecidas.find(([v]) => Math.abs(v - r) / v < 0.03);
  return c ? c[1] : (r < 1 ? 'vertical' : 'horizontal');
}
AP.nomeProporcao = nomeProporcao;

// Melhor grade para n artes com proporções rs dentro de W×H.
function melhorGrade(rs, W, H, gap){
  const n = rs.length;
  let best = null;
  for (let cols = 1; cols <= n; cols++) {
    const rows = Math.ceil(n / cols);
    const cw = (W - (cols-1)*gap) / cols, ch = (H - (rows-1)*gap) / rows;
    if (cw <= 0 || ch <= 0) continue;
    let area = 0;
    const fits = rs.map(r => { let w = cw, h = cw / r; if (h > ch) { h = ch; w = ch * r; } area += w*h; return { w, h }; });
    if (!best || area > best.area) best = { cols, rows, cw, ch, fits, area };
  }
  // Encolhe as células até o tamanho real da maior arte: nada de buraco
  const colW = Math.max(...best.fits.map(f => f.w));
  const rowH = Math.max(...best.fits.map(f => f.h));
  best.colW = colW; best.rowH = rowH;
  best.usedW = best.cols * colW + (best.cols-1) * gap;
  best.usedH = best.rows * rowH + (best.rows-1) * gap;
  return best;
}

AP.gerarPDF = async function(o){
  // o: { set, items, clienteNome, logoClienteUrl, onProgress, nomeArquivo }
  const { jsPDF } = window.jspdf;
  const W = 338.7, H = 190.5, M = 18;
  const pdf = new jsPDF({ orientation:'landscape', unit:'mm', format:[W, H] });
  const set = o.set || {}, items = o.items || [];
  const prog = o.onProgress || (()=>{});
  const total = items.length;
  const okN  = items.filter(i => i.status === 'aprovado').length;
  const fixN = items.filter(i => i.status === 'ajustar').length;

  const texto = (txt, x, y, larg, tam, estilo, cor, yMax) => {
    pdf.setFont('helvetica', estilo || 'normal');
    pdf.setFontSize(tam);
    if (Array.isArray(cor)) pdf.setTextColor(cor[0], cor[1], cor[2]); else pdf.setTextColor(cor ?? 20);
    let linhas = pdf.splitTextToSize(String(txt || ''), larg);
    const lh = tam * 0.42;
    if (yMax) {
      const cabe = Math.max(0, Math.floor((yMax - y) / lh));
      if (linhas.length > cabe) { linhas = linhas.slice(0, cabe); if (cabe) linhas[cabe-1] = linhas[cabe-1].replace(/\s*\S*$/, '') + ' …'; }
    }
    if (linhas.length) pdf.text(linhas, x, y);
    return y + linhas.length * lh;
  };

  let logoSD = null, logoCli = null;
  try { logoSD = await carregarImagem('/assets/logo-pontodot.svg', 600); } catch(e){}
  try { if (o.logoClienteUrl) logoCli = await carregarImagem(o.logoClienteUrl, 400); } catch(e){}

  // ---------- CAPA ----------
  pdf.setFillColor(10,10,12); pdf.rect(0, 0, W, H, 'F');
  if (logoSD) { const lh = 10; pdf.addImage(logoSD.data, 'JPEG', M, 22, lh * logoSD.r, lh); }
  else { pdf.setTextColor(255,255,255); pdf.setFont('helvetica','bold'); pdf.setFontSize(10); pdf.text('PONTODOT', M, 28); }
  if (logoCli) { const lh = 16, lw = Math.min(lh * logoCli.r, 60); pdf.addImage(logoCli.data, 'JPEG', W - M - lw, 20, lw, lw / logoCli.r); }
  pdf.setFont('helvetica','normal'); pdf.setFontSize(12); pdf.setTextColor(160,165,175);
  pdf.text(String(o.clienteNome || '').toUpperCase(), M, 78);
  pdf.setTextColor(255,255,255); pdf.setFont('helvetica','bold'); pdf.setFontSize(38);
  pdf.text(pdf.splitTextToSize(set.title || 'Aprovação', W - 2*M), M, 94);
  if (set.subtitle) {
    pdf.setFont('helvetica','normal'); pdf.setFontSize(13); pdf.setTextColor(180,183,190);
    pdf.text(pdf.splitTextToSize(set.subtitle, W - 2*M), M, 110);
  }
  pdf.setFontSize(11); pdf.setTextColor(150,154,162);
  pdf.text(`${total} peça${total!==1?'s':''}  ·  ${okN} aprovada${okN!==1?'s':''}` + (fixN ? `  ·  ${fixN} com ajuste` : ''), M, 132);
  pdf.setTextColor(115,119,128); pdf.setFontSize(9);
  pdf.text('Gerado em ' + new Date().toLocaleDateString('pt-BR', { day:'2-digit', month:'long', year:'numeric' }), M, 140);

  // ---------- UMA PÁGINA POR PEÇA ----------
  const AX = M, AY = 20, AH = H - 20 - 14, GAP = 4;
  const MAX_ARTES = 12;
  const TXT_MIN = 80;                                     // largura mínima da coluna de texto
  const AW = W - 2*M - 12 - TXT_MIN;

  for (let n = 0; n < items.length; n++) {
    const it = items[n];
    prog(n+1, total);

    pdf.addPage([W, H], 'landscape');
    pdf.setFillColor(255,255,255); pdf.rect(0, 0, W, H, 'F');
    pdf.setFillColor(10,10,12); pdf.rect(0, 0, W, 12, 'F');
    pdf.setTextColor(255,255,255); pdf.setFont('helvetica','bold'); pdf.setFontSize(8.5);
    pdf.text(('PONTODOT — ' + (set.title||'')).toUpperCase(), M, 8);

    const midias = (it.media || []).slice(0, MAX_ARTES);
    const imgs = [];
    for (const m of midias) {
      const src = m.thumb || m.url;
      imgs.push(AP.ehVideo(m) ? await quadroVideo(m.url || src, 1100) : await carregarImagem(src, 1100));
    }
    // Sem prévia: usa a proporção esperada para o tipo (stories/reels 9:16)
    const rPadrao = (it.kind === 'stories' || it.kind === 'reels') ? 9/16 : 1;
    const rs = imgs.map(im => im ? im.r : rPadrao);

    let usedW = 0;
    if (midias.length) {
      const g = melhorGrade(rs, AW, AH, GAP);
      usedW = g.usedW;
      const oy = AY + (AH - g.usedH) / 2;
      midias.forEach((m, i) => {
        const col = i % g.cols, lin = Math.floor(i / g.cols);
        const cx = AX + col * (g.colW + GAP), cy = oy + lin * (g.rowH + GAP);
        const f = g.fits[i];
        const x = cx + (g.colW - f.w)/2, y = cy + (g.rowH - f.h)/2;
        const im = imgs[i];
        if (im) {
          pdf.addImage(im.data, 'JPEG', x, y, f.w, f.h);
          pdf.setDrawColor(225,225,228); pdf.setLineWidth(0.2); pdf.rect(x, y, f.w, f.h);
          if (im.video) { pdf.setFillColor(10,10,12); pdf.circle(x + f.w/2, y + f.h/2, 5, 'F');
            pdf.setFillColor(255,255,255); pdf.triangle(x+f.w/2-1.6, y+f.h/2-2.6, x+f.w/2-1.6, y+f.h/2+2.6, x+f.w/2+2.8, y+f.h/2, 'F'); }
        } else {
          pdf.setFillColor(244,244,246); pdf.rect(x, y, f.w, f.h, 'F');
          texto(AP.ehVideo(m) ? 'vídeo — ver no link' : 'sem prévia', x + 3, y + f.h/2, f.w - 6, 7.5, 'normal', 150);
        }
        if (midias.length > 1) {
          pdf.setFillColor(10,10,12); pdf.rect(x + 1.5, y + 1.5, 6, 4.4, 'F');
          pdf.setFont('helvetica','bold'); pdf.setFontSize(6.5); pdf.setTextColor(255,255,255);
          pdf.text(String(i+1), x + 4.5, y + 4.7, { align:'center' });
        }
      });
      if ((it.media||[]).length > MAX_ARTES) {
        texto('+ ' + ((it.media||[]).length - MAX_ARTES) + ' arte(s) — ver todas no link de aprovação',
              AX, H - 9, AW, 7.5, 'normal', 130);
      }
    } else {
      usedW = 80;
      pdf.setFillColor(244,244,246); pdf.rect(AX, AY + AH/2 - 30, usedW, 60, 'F');
      texto('sem imagem', AX + 6, AY + AH/2, usedW - 12, 9, 'normal', 150);
    }

    // ----- coluna de texto -----
    const cx = AX + usedW + 12, cw = W - cx - M, yMax = H - 12;
    let cy = 26;
    const rotulo = { feed:'FEED', carrossel:'CARROSSEL', stories:'STORIES', post:'POST',
                     reels:'REELS', material:'MATERIAL', generico:'MATERIAL' }[it.kind] || 'PEÇA';
    const est  = it.status === 'aprovado' ? 'APROVADO' : it.status === 'ajustar' ? 'AJUSTE PEDIDO' : 'PENDENTE';
    const corE = it.status === 'aprovado' ? [22,163,74] : it.status === 'ajustar' ? [219,39,119] : [150,150,150];
    pdf.setFont('helvetica','bold'); pdf.setFontSize(7);
    pdf.setTextColor(255,255,255); pdf.setFillColor(10,10,12);
    pdf.rect(cx, cy - 3.4, pdf.getTextWidth(rotulo) + 6, 5, 'F'); pdf.text(rotulo, cx + 3, cy);
    let bx = cx + pdf.getTextWidth(rotulo) + 9;
    pdf.setFillColor(corE[0], corE[1], corE[2]);
    pdf.rect(bx, cy - 3.4, pdf.getTextWidth(est) + 6, 5, 'F'); pdf.text(est, bx + 3, cy);
    if (it.version && it.version > 1) {
      bx += pdf.getTextWidth(est) + 9; const v = 'V' + it.version;
      pdf.setFillColor(90,90,98); pdf.rect(bx, cy - 3.4, pdf.getTextWidth(v) + 6, 5, 'F'); pdf.text(v, bx + 3, cy);
    }
    cy += 9;

    if (it.title) cy = texto(it.title, cx, cy, cw, 13, 'bold', 20, yMax) + 3;
    if (it.publish_at) cy = texto('Publicar: ' + AP.quando(it.publish_at).toLocaleString('pt-BR',
      {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}), cx, cy, cw, 8.5, 'normal', 130, yMax) + 1.5;

    // Formato real das artes (o que o cliente está vendo)
    const dims = imgs.filter(Boolean).map(im => `${im.w}×${im.h}`);
    const unicos = [...new Set(dims)];
    const formato = unicos.length === 1 ? `${unicos[0]} (${nomeProporcao(imgs.find(Boolean).w, imgs.find(Boolean).h)})`
                  : unicos.length ? unicos.slice(0,3).join(' · ') : '';
    cy = texto(`${(it.media||[]).length} arte${(it.media||[]).length!==1?'s':''}` + (formato ? '  ·  ' + formato : ''),
               cx, cy, cw, 8, 'normal', 150, yMax) + 3;

    if (it.pitch)   cy = texto(it.pitch, cx, cy, cw, 9, 'normal', 70, yMax) + 3;
    if (it.caption) cy = texto(it.caption, cx, cy, cw, 9, 'normal', 45, yMax - 18) + 3;
    else if (it.headline) cy = texto(it.headline.replace(/\n/g,'  '), cx, cy, cw, 8.5, 'normal', 70, yMax - 18) + 3;
    if (it.hashtags) cy = texto(it.hashtags, cx, cy, cw, 8, 'normal', 130, yMax - 12) + 2;
    if (it.external_url) cy = texto('Arquivo final: ' + it.external_url, cx, cy, cw, 7.5, 'normal', 130, yMax) + 2;

    const fala = (it._talk || []).filter(c => c.is_client);
    if (fala.length && cy < yMax - 6) {
      cy += 2;
      fala.slice(-3).forEach(c => {
        const p = AP.parseComentario(c.body);
        const corpo = [p.geral, p.arte && ('ARTE: ' + p.arte), p.texto && ('TEXTO: ' + p.texto)].filter(Boolean).join('\n');
        cy = texto(c.author_name + ': "' + corpo + '"', cx, cy, cw, 8.5, 'italic', [219,39,119], yMax) + 1.5;
      });
    }
  }

  // ---------- ASSINATURA ----------
  pdf.addPage([W, H], 'landscape');
  pdf.setFillColor(10,10,12); pdf.rect(0, 0, W, H, 'F');
  if (logoSD) { const lh = 14, lw = lh * logoSD.r; pdf.addImage(logoSD.data, 'JPEG', (W - lw)/2, H/2 - 22, lw, lh); }
  else { pdf.setTextColor(255,255,255); pdf.setFont('helvetica','bold'); pdf.setFontSize(20); pdf.text('PONTODOT', W/2, H/2 - 12, { align:'center' }); }
  pdf.setFont('helvetica','normal'); pdf.setFontSize(10); pdf.setTextColor(165,170,180);
  pdf.text('sd.sergioduh.com', W/2, H/2 + 4, { align:'center' });
  pdf.setFontSize(7.5); pdf.setTextColor(110,115,125);
  pdf.text('Pontodot — documento gerado pela plataforma', W/2, H - 12, { align:'center' });

  const paginas = pdf.internal.getNumberOfPages();
  for (let p = 2; p < paginas; p++) {
    pdf.setPage(p);
    pdf.setFont('helvetica','normal'); pdf.setFontSize(7); pdf.setTextColor(150,150,150);
    pdf.text('Pontodot — ' + (set.title || ''), M, H - 6);
    pdf.text((p-1) + ' / ' + (paginas-2), W - M, H - 6, { align:'right' });
  }

  const nome = (o.nomeArquivo || set.title || 'aprovacao').normalize('NFD')
    .replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9 -]/g,'').trim().replace(/\s+/g,'-');
  if (o.retornarBlob) return pdf.output('blob');
  pdf.save(nome + '.pdf');
};

// =====================================================================
// EXCEL DE CORREÇÕES
// Mesmo modelo do upload (aba IMPORTACAO, cabeçalho na linha 4, as 7
// colunas na mesma ordem) + colunas de correção à direita. Volta pra
// plataforma no ZIP de correções e o sistema entende sozinho:
//   · ID_PECA casa a linha com a peça certa, sem ambiguidade
//   · LEGENDA / HASHTAGS / DATA / HORA alterados = texto atualizado
//   · ARTES = o nome que a arte corrigida deve ter no pacote
// =====================================================================
const TIPO_XLS = { feed:'POST', carrossel:'CARROSSEL', stories:'STORIES', reels:'REELS', material:'MATERIAL', generico:'MATERIAL' };
const STATUS_XLS = { aprovado:'APROVADO', ajustar:'AJUSTAR', pendente:'PENDENTE' };

AP.chaveExcel = function(it){
  if (it.source_key) return it.source_key;
  const media = it.media || [];
  if (media.length === 1 && media[0].name) return media[0].name;
  const pastas = [...new Set(media.map(m => (m.path||'').split('/').slice(0,-1).join('/')).filter(Boolean))];
  if (pastas.length === 1) return pastas[0];
  return it.title || ('peca-' + ((it.position ?? 0) + 1));
};

AP.gerarExcelCorrecoes = function(o){
  // o: { set, items, clienteNome, desdeVersao:{itemId: ISO} , soComAjuste:false }
  const set = o.set || {};
  const desde = o.desdeVersao || {};
  const fmtD = d => d.toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit',year:'numeric'});
  const fmtH = d => d.toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});

  const HEAD = ['ARQUIVO_OU_PASTA','LEGENDA','HASHTAGS','TITULO','TIPO','DATA','HORA',
                'STATUS','CORRECAO_CRIATIVO','CORRECAO_COPY','OBS_CLIENTE','ARTES','VERSAO','ID_PECA'];

  const linhas = [];
  let comCorrecao = 0;
  (o.items || []).forEach(it => {
    // Só o que o cliente pediu DEPOIS da última versão enviada — o que
    // já foi corrigido não volta para a fila.
    const corte = desde[it.id] ? new Date(desde[it.id]) : null;
    // Lote antigo (sem versão registrada ainda): não dá pra saber o que
    // já foi corrigido. Regra segura: só traz comentário de peça que
    // ESTÁ em ajuste agora — o resto já foi resolvido ou aprovado.
    const falas = (it._talk || []).filter(c => {
      const interno = AP.ehInterno(c.body);
      if (!c.is_client && !interno) return false;            // resposta da equipe ao cliente
      if (corte) return new Date(c.created_at) > corte;
      return interno || it.status === 'ajustar';
    }).map(c => AP.ehInterno(c.body) ? Object.assign({}, c, { body: AP.semInterno(c.body) }) : c);
    const arte = [], copy = [], geral = [];
    falas.forEach(c => {
      const p = AP.parseComentario(c.body);
      if (p.arte)  arte.push(p.arte);
      if (p.texto) copy.push(p.texto);
      if (p.geral) geral.push(p.geral);
    });
    if (o.soComAjuste && it.status !== 'ajustar') return;
    if (it.status === 'ajustar' || arte.length || copy.length) comCorrecao++;

    const media = it.media || [];
    const pastaKey = AP.chaveExcel(it);
    const artes = media.map(m => {
      const base = m.path || m.name || '';
      // Peça em pasta: "pasta/arquivo"; solta: só o arquivo
      return base.includes('/') ? base : (media.length > 1 && !it.source_key?.match(/\.[a-z0-9]{3,4}$/i)
        ? (pastaKey + '/' + base) : base);
    });
    const d = AP.quando(it.publish_at);
    const junta = a => a.length > 1 ? a.map(x => '• ' + x).join('\n') : (a[0] || '');

    linhas.push([
      pastaKey,
      it.caption || '',
      it.hashtags || '',
      it.title || '',
      TIPO_XLS[it.kind] || 'POST',
      d ? fmtD(d) : '',
      d ? fmtH(d) : '',
      STATUS_XLS[it.status] || 'PENDENTE',
      junta(arte),
      junta(copy),
      junta(geral),
      artes.join('\n'),
      it.version || 1,
      it.id
    ]);
  });

  const aoa = [
    ['PONTODOT — Correções do lote: ' + (set.title || '') + (o.clienteNome ? ' · ' + o.clienteNome : '')],
    ['Mesmo modelo do upload. Uma linha = uma peça. As colunas de correção (STATUS → ID_PECA) são só leitura: corrija as artes e a LEGENDA/HASHTAGS e devolva este arquivo dentro do zip de correções.'],
    [],
    HEAD,
    ...linhas
  ];
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  ws['!cols'] = [ {wch:28},{wch:60},{wch:28},{wch:28},{wch:12},{wch:12},{wch:8},
                  {wch:11},{wch:48},{wch:48},{wch:36},{wch:34},{wch:8},{wch:38} ];
  ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:13} }, { s:{r:1,c:0}, e:{r:1,c:13} }];
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s:{r:3,c:0}, e:{r:3 + linhas.length, c:13} }) };
  ws['!freeze'] = { xSplit: 1, ySplit: 4 };

  const guia = [
    ['Como aplicar as correções (para a equipe ou para o GPT)'],
    [],
    ['A REGRA', 'Uma linha = uma peça. Corrija só o que tem algo em CORRECAO_CRIATIVO ou CORRECAO_COPY. STATUS = APROVADO não se mexe.'],
    ['CORRECAO_CRIATIVO', 'O que o cliente pediu na ARTE. Aplique no arquivo de arte da peça (ex.: o .pptx no Drive) e exporte de novo.'],
    ['CORRECAO_COPY', 'O que o cliente pediu no TEXTO. Reescreva direto na coluna LEGENDA (e HASHTAGS, se for o caso). Se o texto estiver dentro da arte, aplique também na arte.'],
    ['OBS_CLIENTE', 'Comentário sem rótulo (arte ou texto não especificado). Leia e decida onde aplicar.'],
    ['ARTES', 'Os nomes dos arquivos atuais da peça, na ordem. A ARTE CORRIGIDA DEVE TER O MESMO NOME (a extensão pode mudar: .jpg ↔ .png). Carrossel: troque só os cards corrigidos, mantendo o nome (pasta/arquivo).'],
    ['ID_PECA / VERSAO', 'Não altere. É assim que a plataforma sabe qual peça é qual, mesmo que o nome mude.'],
    [],
    ['DEVOLVER', 'Monte um .zip só com o que mudou: as artes corrigidas (com os nomes da coluna ARTES, nas mesmas pastas) + ESTE Excel com a LEGENDA atualizada. Na plataforma: Ver peças → "Subir correções". Peças que não estiverem no zip ficam como estão.'],
    ['TROCAR TUDO', 'Para substituir o lote inteiro, suba o pacote completo e escolha "Trocar todas as artes" na conferência.'],
  ];
  const wg = XLSX.utils.aoa_to_sheet(guia);
  wg['!cols'] = [{wch:22},{wch:120}];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'IMPORTACAO');
  XLSX.utils.book_append_sheet(wb, wg, 'COMO CORRIGIR');

  const nome = 'correcoes-' + (set.title || 'lote').normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .replace(/[^a-zA-Z0-9 -]/g,'').trim().replace(/\s+/g,'-').toLowerCase() + '.xlsx';
  if (o.retornarBuffer) return XLSX.write(wb, { bookType:'xlsx', type:'array' });
  XLSX.writeFile(wb, nome);
  return { linhas: linhas.length, comCorrecao };
};

// =====================================================================
// APONTAMENTO INTERNO — nota do gestor que vai pra IA, não pro cliente.
// Gravado como comentário da equipe com a marca [INTERNO] na 1ª linha.
// =====================================================================
const MARCA_INTERNO = '[INTERNO]';
AP.ehInterno = body => String(body || '').trimStart().startsWith(MARCA_INTERNO);
AP.semInterno = body => String(body || '').trimStart().slice(MARCA_INTERNO.length).trim();
AP.montarInterno = (arte, texto) => {
  const c = AP.montarComentario(arte, texto);
  return c ? MARCA_INTERNO + '\n' + c : '';
};

// =====================================================================
// CHAMADA AOS ENDPOINTS PHP com a sessão de quem está logado
// =====================================================================
AP.sdFetch = async function(url, body){
  let { data: { session } } = await sb.auth.getSession();
  if (session && session.expires_at && (session.expires_at * 1000 - Date.now() < 60000)) {
    const { data } = await sb.auth.refreshSession();
    if (data?.session) session = data.session;
  }
  if (!session) throw new Error('Sessão expirada. Entre novamente.');
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + session.access_token },
    body: JSON.stringify(body || {})
  });
  let j = null;
  try { j = await res.json(); } catch(e) {}
  if (!res.ok || !j || j.error) throw new Error((j && j.error) || ('HTTP ' + res.status));
  return j;
};

// =====================================================================
// PONTE COM O DRIVE (navegador → ponte-drive.php → Apps Script)
// =====================================================================
AP.drive = {
  chamar: (acao, dados) => AP.sdFetch('/ponte-drive.php', Object.assign({ acao }, dados || {})),

  async obter(taskId){
    if (!taskId) return null;
    const { data, error } = await sb.from('task_drive').select('*').eq('task_id', taskId).limit(1);
    if (error) return null;               // migration ainda não rodou
    return (data || [])[0] || null;
  },

  // Lista a SAIDA e devolve "entradas virtuais" pro AP.lerPacote: o
  // mesmo leitor do zip, agora lendo a pasta do Drive. Os downloads
  // começam já, 4 de cada vez. Imagem vira JPEG leve (o original
  // continua no Drive; o link vai junto em cada arte).
  // O que a IA entregou, ONDE quer que ela tenha salvo: lê a pasta
  // inteira da tarefa e fica com o que foi criado/alterado depois do
  // marco da rodada (Play ou pedido de correção). Fora: a planilha de
  // controle, o Excel de correções que o SD gravou e cópias temporárias.
  // O caminho perde o prefixo ENTRADA/ ou SAIDA/ — assim o nome da peça
  // é o mesmo, não importa a pasta.
  async entradas(taskId, onStatus){
    const st = onStatus || (() => {});
    const drv = await AP.drive.obter(taskId);
    st('Procurando a entrega da IA na pasta da tarefa…');
    const { arquivos: todos = [] } = await AP.drive.chamar('listar', { task_id: taskId, pasta: 'raiz' });
    const doSD = a => /^SD_CONTROLE/i.test(a.name) || /\(SD temporário\)/.test(a.name) ||
      /^00_/i.test(a.name) || /^DUVIDAS/i.test(a.name) ||
      (/^ENTRADA\//i.test(a.path) && /^correcoes.*\.xlsx$/i.test(a.name));
    const candidatos = todos.filter(a => !doSD(a)).map(a => Object.assign({}, a,
      { path: a.path.replace(/^(SAIDA|ENTRADA)\//i, '') }));
    const marco = drv && drv.marco ? new Date(drv.marco).getTime() - 60000 : null;
    let arquivos = marco ? candidatos.filter(a => new Date(a.modified).getTime() >= marco) : [];
    let origem = 'novos desde o play';
    if (!arquivos.length) {
      // Sem marco (tarefa antiga) ou a IA trabalhou antes do play: usa a SAIDA;
      // se ela estiver vazia, tudo o que houver na pasta.
      const saida = todos.filter(a => /^SAIDA\//i.test(a.path) && !doSD(a))
        .map(a => Object.assign({}, a, { path: a.path.replace(/^SAIDA\//i, '') }));
      arquivos = saida.length ? saida : candidatos;
      origem = saida.length ? 'pasta SAIDA' : 'pasta inteira da tarefa';
    }
    const uteis = arquivos.filter(a => !LIXO.test(a.path) &&
      (IMG.test(a.name) || VID.test(a.name) || TXT.test(a.name) || XLS.test(a.name) || AP.ehDocumento(a) || /\.zip$/i.test(a.name)));
    const ignorados = arquivos.length - uteis.length;
    let feitos = 0;
    const total = uteis.length;

    const errosBaixar = [];
    const baixar = async (a) => {
      if (VID.test(a.name) && a.size > 45 * 1024 * 1024) {
        // Vídeo pesado: não passa pela ponte — a peça aponta pro Drive.
        const f = new File([new Blob([''])], a.name, { type: AP.mime(a.name) });
        f._drive = { id: a.id, url: a.url, soLink: true };
        return f;
      }
      let r;
      try { r = await AP.drive.chamar('baixar', { task_id: taskId, file_id: a.id }); }
      catch (err) {
        // Passou de 30 MB: monta o arquivo em pedaços (só pra imagem/PDF — o resto continua virando link)
        if (/grande demais/i.test(err.message || '') && (IMG.test(a.name) || /\.(pdf|zip)$/i.test(a.name))) {
          try {
            const partes = []; let ini = 0, fim = false;
            while (!fim) {
              const p = await AP.drive.chamar('baixar_parte', { task_id: taskId, file_id: a.id, inicio: ini, tamanho: 8 * 1024 * 1024 });
              const bin = atob(p.b64 || ''), u8 = new Uint8Array(bin.length);
              for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
              partes.push(u8); ini += u8.length; fim = !!p.fim || !u8.length;
              st(`Baixando ${a.name} do Drive… ${Math.round(ini / 1048576)} MB`);
            }
            let f = new File(partes, a.name, { type: AP.mime(a.name) });
            if (IMG.test(a.name)) f = await AP.imagemLeve(f);
            f._drive = { id: a.id, url: a.url };
            feitos++; st(`Baixando do Drive… ${feitos} de ${total}`);
            return f;
          } catch (e2) { err = e2; }
        }
        // Um arquivo com problema não derruba a leitura: vira link pro Drive.
        console.warn('Drive: não baixei', a.path, err.message);
        errosBaixar.push(a.name + ': ' + (err.message || err));
        const f = new File([new Blob([''])], a.name, { type: AP.mime(a.name) });
        f._drive = { id: a.id, url: a.url, soLink: true, erro: err.message };
        feitos++; st(`Baixando do Drive… ${feitos} de ${total}`);
        return f;
      }
      const bin = atob(r.b64), u8 = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
      let nome = r.name || a.name;
      let f = new File([u8], nome, { type: r.mime || AP.mime(nome) });
      if (IMG.test(nome)) f = await AP.imagemLeve(f);
      f._drive = { id: a.id, url: a.url };
      feitos++; st(`Baixando do Drive… ${feitos} de ${total}`);
      return f;
    };

    // Fila com 4 downloads simultâneos
    const promessas = new Array(uteis.length);
    let prox = 0;
    const trabalhador = async () => {
      while (prox < uteis.length) { const i = prox++; promessas[i] = baixar(uteis[i]); try { await promessas[i]; } catch(e){} }
    };
    const pool = Promise.all([0,1,2,3].map(trabalhador));
    await new Promise(r => setTimeout(r, 0));
    const pega = i => promessas[i] || pool.then(() => promessas[i]);

    // Planilha Google vira .xlsx; apresentação/documento/pptx vira .pdf
    // (a ponte converte) — o leitor expande cada página em imagem.
    const caminho = a => {
      if (a.mime === 'application/vnd.google-apps.spreadsheet') return /\.xlsx$/i.test(a.path) ? a.path : a.path + '.xlsx';
      if (AP.ehDocumento(a) && !/\.pdf$/i.test(a.path)) return a.path.replace(/\.(pptx?|odp|docx?)$/i, '') + '.pdf';
      return a.path;
    };
    const out = uteis.map((a, i) => ({
      path: caminho(a),
      blob: () => pega(i),
      text: async () => (await pega(i)).text(),
      buf:  async () => (await pega(i)).arrayBuffer()
    }));
    // ZIP na pasta (o "mês completo"): abre e lê por dentro, igual ao envio de pasta zipada
    for (let i = out.length - 1; i >= 0; i--) {
      if (!/\.zip$/i.test(out[i].path)) continue;
      st('Abrindo o ZIP ' + out[i].path.split('/').pop() + '…');
      try {
        const zip = await JSZip.loadAsync(await out[i].blob());
        const dentro = [];
        Object.values(zip.files).forEach(z => {
          if (z.dir || LIXO.test(z.name)) return;
          dentro.push({ path: z.name, blob: () => z.async('blob'),
                        text: () => z.async('string'), buf: () => z.async('arraybuffer') });
        });
        out.splice(i, 1, ...dentro);
      } catch (e) { errosBaixar.push(out[i].path + ': não consegui abrir o zip — ' + (e.message || e)); }
    }
    out._ignorados = ignorados;
    out._total = arquivos.length;
    out._origem = origem;
    out._erros = errosBaixar;
    out._nomes = arquivos.slice(0, 6).map(a => a.path);
    return out;
  }
};

// Imagem → JPEG (máx. 2160px, q 0.9), mantendo o NOME original (o nome
// é a chave de casamento). JPEG também é o que o Instagram aceita.
AP.imagemLeve = async function(f){
  if (/gif$/i.test(f.type)) return f;
  try {
    const bmp = await createImageBitmap(f);
    const k = Math.min(1, 2160 / Math.max(bmp.width, bmp.height));
    if (f.type === 'image/jpeg' && k === 1 && f.size < 2.5 * 1024 * 1024) return f;
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9));
    return new File([blob], f.name, { type: 'image/jpeg' });
  } catch(e) { return f; }
};

// Sobe um arquivo pro storage do lote e devolve o objeto de mídia.
// Arquivo que veio do Drive leva junto o link do original.
AP.subirMidia = async function(f, clientId, setId, extra){
  const meta = Object.assign({}, extra || {});
  if (f._drive) { meta.drive_id = f._drive.id; meta.drive_url = f._drive.url; }
  if (f._drive && f._drive.soLink) {
    return Object.assign({ name: f.name, url: f._drive.url, thumb: f._drive.url,
      mime: AP.mime(f.name), source: 'drive_link' }, meta);
  }
  // v62: ARQUIVO DE TRABALHO MORA NO SUPABASE (Pro) até o lote fechar.
  // É rápido (sem a volta pela ponte) e a Meta alcança a URL na hora de
  // publicar. Quando o lote fecha, o relógio da casa faz a CÓPIA pro
  // Drive e, na faxina do mês, o gestor libera a limpeza do que já tem
  // cópia conferida. O Drive vira acervo; o Supabase vira bancada.
  const ext = f.type === 'image/jpeg' ? 'jpg' : ((f.name.split('.').pop() || 'bin').toLowerCase());
  try {
    const caminho = `${clientId}/${setId}/${crypto.randomUUID()}.${ext}`;
    const { error } = await sb.storage.from('aprovacao').upload(caminho, f, { contentType: f.type || AP.mime(f.name) });
    if (error) throw new Error(error.message);
    const { data: pub } = sb.storage.from('aprovacao').getPublicUrl(caminho);
    return Object.assign({ name: f.name, url: pub.publicUrl, thumb: pub.publicUrl,
      mime: f.type || AP.mime(f.name), source: 'storage' }, meta);
  } catch (e) {
    // bancada fora do ar não trava ninguém: cai no Drive (o caminho antigo)
    console.warn('[SD] Supabase recusou, caindo no Drive:', e && e.message);
  }
  try {
    const nova = await AP.subirNoDrive(f, clientId, setId);
    if (nova) return Object.assign(nova, meta);
  } catch (e) {
    console.warn('[SD] Drive também recusou:', e && e.message);
    if (typeof AP.aviso === 'function') AP.aviso('Nem Supabase nem Drive aceitaram a arte — confere a conexão.');
    throw new Error('Falha ao subir ' + f.name);
  }
};

// O nome do cliente, que é o nome da pasta. Em cache: a mesma pasta vale
// pro lote inteiro e não faz sentido perguntar a cada arquivo.
AP._nomeCliente = {};
AP.nomeDoCliente = async function(clientId){
  if (AP._nomeCliente[clientId] !== undefined) return AP._nomeCliente[clientId];
  try {
    const { data } = await sb.from('clients').select('name').eq('id', clientId).single();
    return AP._nomeCliente[clientId] = (data && data.name) || 'SEM CLIENTE';
  } catch(e){ return AP._nomeCliente[clientId] = 'SEM CLIENTE'; }
};

/** Sobe pro Drive, em APROVAÇÕES / cliente / lote. Devolve o objeto de
 *  mídia pronto, ou null se a ponte não estiver de pé. */
AP.subirNoDrive = async function(f, clientId, setId){
  const { data:{ session } } = await sb.auth.getSession();
  if (!session) return null;
  const dados = await new Promise((ok, err) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).replace(/^data:[^,]+,/, ''));
    r.onerror = err; r.readAsDataURL(f);
  });
  const r = await fetch('/aprovacoes-drive.php', { method:'POST',
    headers:{ 'Content-Type':'application/json', 'Authorization':'Bearer ' + session.access_token },
    body: JSON.stringify({ acao:'subir', cliente: await AP.nomeDoCliente(clientId), set_id: setId,
                           nome: f.name, mime: f.type || AP.mime(f.name), dados }) });
  const j = await r.json().catch(()=>({}));
  if (!r.ok || !j.ok || !j.drive_id) throw new Error(j.error || j.erro || ('ponte respondeu ' + r.status));
  return { name: f.name, url: j.url, thumb: j.thumb, original: j.original,
           drive_id: j.drive_id, mime: f.type || AP.mime(f.name), source: 'drive' };
};

AP.b64 = async function(buf){
  const u8 = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
  let s = ''; const CH = 0x8000;
  for (let i = 0; i < u8.length; i += CH) s += String.fromCharCode.apply(null, u8.subarray(i, i + CH));
  return btoa(s);
};

// =====================================================================
// PDF / PPTX → IMAGENS DE APROVAÇÃO
// Cada página vira um JPEG. opts.paginas:
//   'peca'  — cada página é uma peça   (nome-01.jpg, nome-02.jpg… soltos)
//   'grupo' — o arquivo é UMA peça     (nome/01.jpg, nome/02.jpg…)
//   'auto'  — "carrossel"/"stories" no nome = grupo; senão, página = peça
// =====================================================================
const DOCS_MIME = ['application/vnd.google-apps.presentation', 'application/vnd.google-apps.document', 'application/pdf'];
AP.ehDocumento = a => /\.(pdf|pptx?|odp)$/i.test(a.name || a.path || '') || DOCS_MIME.includes(a.mime) ||
  /presentationml|ms-powerpoint/.test(a.mime || '');

let _pdfjs = null;
AP.pdfjs = function(){
  if (_pdfjs) return _pdfjs;
  _pdfjs = new Promise((ok, falha) => {
    if (window.pdfjsLib) return ok(window.pdfjsLib);
    const sc = document.createElement('script');
    sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    sc.onload = () => {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      ok(window.pdfjsLib);
    };
    sc.onerror = () => { _pdfjs = null; falha(new Error('Não consegui carregar o leitor de PDF.')); };
    document.head.appendChild(sc);
  });
  return _pdfjs;
};

AP.pdfPaginas = async function(file, onPagina){
  const lib = await AP.pdfjs();
  const pdf = await lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
  const out = [];
  const MAX = 300;
  for (let i = 1; i <= Math.min(pdf.numPages, MAX); i++) {
    const pg = await pdf.getPage(i);
    const v1 = pg.getViewport({ scale: 1 });
    const esc = Math.min(4, 1600 / Math.max(v1.width, v1.height));
    const vp = pg.getViewport({ scale: esc });
    const c = document.createElement('canvas');
    c.width = Math.round(vp.width); c.height = Math.round(vp.height);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    await pg.render({ canvasContext: ctx, viewport: vp }).promise;
    out.push(await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9)));
    if (onPagina) onPagina(i, pdf.numPages);
  }
  return { paginas: out, total: pdf.numPages };
};

AP.expandirPdfs = async function(entradas, opts, st){
  const out = [];
  for (const e of entradas) {
    if (!/\.pdf$/i.test(e.path)) { out.push(e); continue; }
    const nome = ultimo(e.path);
    st('Gerando amostras de ' + nome + '…');
    let f;
    try { f = await e.blob(); } catch(err) { console.warn('PDF:', err.message); continue; }
    if (!f || !f.size || (f._drive && f._drive.soLink)) { continue; }
    let r;
    try { r = await AP.pdfPaginas(f, (i, n) => st(`Gerando amostras de ${nome}… página ${i} de ${n}`)); }
    catch(err) { console.warn('PDF não lido:', nome, err.message); continue; }
    const dir = e.path.split('/').slice(0, -1).join('/');
    const base = nome.replace(/\.[^.]+$/, '');
    let modo = (opts && opts.paginas) || 'auto';
    if (modo === 'auto') modo = /carross|carousel|stor/i.test(base + ' ' + dir) ? 'grupo' : 'peca';
    r.paginas.forEach((blob, i) => {
      const n = String(i + 1).padStart(2, '0');
      const arq = r.paginas.length === 1 ? base + '.jpg'
                : modo === 'grupo' ? base + '/' + n + '.jpg' : base + '-' + n + '.jpg';
      const file = new File([blob], arq.split('/').pop(), { type: 'image/jpeg' });
      if (f._drive) file._drive = f._drive;          // link do original (pptx/pdf) no Drive
      const path = (dir ? dir + '/' : '') + arq;
      out.push({ path, blob: async () => file, text: async () => '', buf: async () => file.arrayBuffer() });
    });
    if (r.total > r.paginas.length) st(`${nome}: usei as primeiras ${r.paginas.length} de ${r.total} páginas.`);
  }
  return out;
};

// =====================================================================
// APROVAR TUDO (cliente) — um clique aprova o lote inteiro. A tela de
// confirmação mostra todas as peças pendentes já marcadas; tocar numa
// peça muda ela pra "corrigir" e abre o campo do ajuste (arte/texto).
//   AP.aprovarTudo(items, executar)
//   executar(id, 'aprovado'|'ajustar', comentario) → Promise
// =====================================================================
AP.aprovarTudo = function(items, executar, aoTerminar, opts){
  opts = opts || {};
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const pend = items.filter(opts.pendente || (i => i.status !== 'aprovado'));
  if (!pend.length) return;
  const corrigir = new Set();

  const ov = document.createElement('div');
  ov.style.cssText = 'position:fixed;inset:0;z-index:99600;background:rgba(10,10,12,.6);backdrop-filter:blur(6px);' +
    '-webkit-backdrop-filter:blur(6px);display:flex;align-items:flex-start;justify-content:center;padding:clamp(10px,4vh,40px) 14px;overflow-y:auto';
  ov.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;width:100%;max-width:760px;
                box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden">
      <div style="padding:20px 22px 12px">
        <div style="font-size:17px;font-weight:700;letter-spacing:-.01em;margin-bottom:5px">${opts.titulo || 'Aprovar'} ${pend.length} peça${pend.length>1?'s':''}?</div>
        <div style="font-size:12.5px;color:var(--text3);line-height:1.5">Todas estão marcadas pra aprovar. Se alguma precisa de ajuste,
          toque nela e escreva o que mudar — o resto é aprovado de uma vez.</div>
      </div>
      <div id="atGrade" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;padding:4px 22px 6px"></div>
      <div id="atAjustes" style="padding:0 22px"></div>
      <div style="padding:10px 22px 4px">
        <div style="font-size:10.5px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Comentário geral (opcional)</div>
        <textarea id="atGeral" rows="2" placeholder="Ex.: aprovado, só confirmar os horários de publicação."
          style="width:100%;border:1px solid var(--border);border-radius:9px;padding:9px 10px;font:inherit;font-size:12.5px;
                 background:var(--surface);color:var(--text);resize:vertical"></textarea>
      </div>
      <div style="display:flex;gap:8px;padding:14px 22px 20px;align-items:center;flex-wrap:wrap">
        <span id="atInfo" style="font-size:12px;color:var(--text3);flex:1;min-width:160px"></span>
        <button id="atNao" style="height:42px;padding:0 18px;border-radius:7px;border:1px solid var(--border);background:transparent;
          color:var(--text2);font:inherit;font-weight:700;cursor:pointer">Voltar</button>
        <button id="atSim" style="height:42px;padding:0 20px;border-radius:7px;border:none;background:#16a34a;color:#fff;
          font:inherit;font-weight:800;cursor:pointer"></button>
      </div>
    </div>`;
  document.body.appendChild(ov);
  document.body.style.overflow = 'hidden';
  const fechar = () => { ov.remove(); document.body.style.overflow = ''; };

  const desenhar = () => {
    ov.querySelector('#atGrade').innerHTML = pend.map(it => {
      const m = (it.media || [])[0];
      const fix = corrigir.has(it.id);
      const th = !m ? '' : AP.ehVideo(m)
        ? `<video src="${esc(m.url)}" muted preload="metadata" style="width:100%;aspect-ratio:1;object-fit:cover;display:block"></video>`
        : `<img src="${esc(m.thumb || m.url)}" style="width:100%;aspect-ratio:1;object-fit:cover;display:block">`;
      return `<button data-id="${it.id}" style="all:unset;cursor:pointer;border-radius:9px;overflow:hidden;
          border:2.5px solid ${fix ? '#db2777' : '#16a34a'};background:var(--surface2);position:relative;display:block">
        ${th || '<div style="aspect-ratio:1"></div>'}
        <span style="position:absolute;top:6px;right:6px;font-size:10px;font-weight:800;padding:2px 7px;border-radius:6px;
          background:${fix ? '#db2777' : '#16a34a'};color:#fff">${fix ? '✎ Corrigir' : '✓ Aprovar'}</span>
        <div style="font-size:11px;font-weight:600;padding:6px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)">${esc(it.title || 'Peça')}</div>
      </button>`;
    }).join('');
    ov.querySelectorAll('#atGrade [data-id]').forEach(b => b.onclick = () => {
      const id = b.dataset.id;
      corrigir.has(id) ? corrigir.delete(id) : corrigir.add(id);
      desenhar();
      if (corrigir.has(id)) setTimeout(() => ov.querySelector(`#atA_${id}`)?.focus(), 30);
    });

    // Campos de ajuste só das peças marcadas pra corrigir (mantém o que já foi escrito)
    const box = ov.querySelector('#atAjustes');
    const antes = {};
    box.querySelectorAll('textarea').forEach(t => antes[t.id] = t.value);
    box.innerHTML = [...corrigir].map(id => {
      const it = pend.find(x => x.id === id);
      return `<div style="border:1px solid rgba(219,39,119,.35);background:rgba(219,39,119,.05);border-radius:9px;padding:10px 12px;margin-top:10px">
        <div style="font-size:12.5px;font-weight:700;color:#be185d;margin-bottom:6px">✎ ${esc(it.title || 'Peça')}</div>
        <textarea id="atA_${id}" rows="2" placeholder="Ajuste na arte…" style="width:100%;border:1px solid var(--border);border-radius:8px;
          padding:8px 10px;font:inherit;font-size:12.5px;background:var(--surface);color:var(--text);margin-bottom:6px">${esc(antes['atA_'+id]||'')}</textarea>
        <textarea id="atT_${id}" rows="2" placeholder="Ajuste no texto/legenda…" style="width:100%;border:1px solid var(--border);border-radius:8px;
          padding:8px 10px;font:inherit;font-size:12.5px;background:var(--surface);color:var(--text)">${esc(antes['atT_'+id]||'')}</textarea>
      </div>`;
    }).join('');

    const nOk = pend.length - corrigir.size;
    ov.querySelector('#atSim').textContent = corrigir.size
      ? `✓ Aprovar ${nOk} · corrigir ${corrigir.size}` : `✓ Aprovar ${nOk} peça${nOk>1?'s':''}`;
    ov.querySelector('#atInfo').textContent = corrigir.size
      ? `${corrigir.size} marcada(s) pra corrigir — escreva o ajuste de cada uma.` : 'Toque numa peça pra marcar ajuste.';
  };
  desenhar();

  ov.querySelector('#atNao').onclick = fechar;
  ov.onclick = ev => { if (ev.target === ov) fechar(); };
  ov.querySelector('#atSim').onclick = async () => {
    const geral = ov.querySelector('#atGeral').value.trim();
    const ajustes = {};
    for (const id of corrigir) {
      const c = AP.montarComentario(ov.querySelector('#atA_' + id).value, ov.querySelector('#atT_' + id).value);
      if (!c && !geral) { ov.querySelector('#atA_' + id).focus(); ov.querySelector('#atInfo').textContent = 'Escreva o ajuste de cada peça marcada.'; return; }
      ajustes[id] = [c, geral].filter(Boolean).join('\n');
    }
    const btn = ov.querySelector('#atSim');
    btn.disabled = true;
    let n = 0, falhas = 0, primeiraAprov = true;
    for (const it of pend) {
      const fix = corrigir.has(it.id);
      // Comentário geral vai junto das correções; sem correção, entra na 1ª aprovação.
      const com = fix ? ajustes[it.id] : (primeiraAprov && geral && !corrigir.size ? geral : null);
      if (!fix) primeiraAprov = false;
      btn.textContent = `Salvando… ${++n} de ${pend.length}`;
      try { await executar(it.id, fix ? 'ajustar' : 'aprovado', com); } catch(e) { falhas++; console.warn(e); }
    }
    fechar();
    if (aoTerminar) aoTerminar({ aprovadas: pend.length - corrigir.size, corrigir: corrigir.size, falhas });
  };
};

// =====================================================================
// CORREÇÃO DO CLIENTE → IA, na hora. Depois que o cliente pede ajuste,
// a própria tela dele monta o Excel de correções e manda pra pasta da
// tarefa no Drive (via token do link). Lote sem Drive: não faz nada.
// =====================================================================
AP.correcaoParaIA = async function(token, set, items){
  try {
    if (!token || typeof XLSX === 'undefined') return;
    const n = items.filter(i => i.status === 'ajustar').length;
    if (!n) return;
    const buf = AP.gerarExcelCorrecoes({ set, items, retornarBuffer: true });
    const res = await fetch('/ponte-drive.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ acao: 'correcao_cliente', token, n, b64: await AP.b64(buf) })
    });
    return await res.json().catch(() => null);
  } catch(e) { console.warn('Correção pra IA:', e.message); }
};

// =====================================================================
// BRIEFING COMPLETO PRA IA (js/briefing-ia.js) — junta tarefa, cliente,
// produto, pasta, briefings do cliente, tipo de entrega e instruções.
// Grava na pasta (00_BRIEFING_IA.txt) e devolve o texto pra copiar.
// =====================================================================
AP.montarBriefing = async function(taskId, opts){
  opts = opts || {};
  if (typeof BriefingIA === 'undefined') throw new Error('briefing-ia.js não carregado');
  const { data: t } = await sb.from('tasks')
    .select('id,action,project,obs,date_cli,date_int,client_id,clients(name),products(name)').eq('id', taskId).single();
  const drv = await AP.drive.obter(taskId);
  let briefings = [];
  if (t?.client_id) {
    const { data: bf } = await sb.from('client_briefings').select('file_name,file_url').eq('client_id', t.client_id)
      .order('created_at', { ascending:false }).limit(8);
    briefings = bf || [];
  }
  const texto = BriefingIA.montar({
    tarefa: { id: t?.id || taskId, action: t?.action, project: t?.project, obs: t?.obs, prazo: t?.date_int || t?.date_cli },
    cliente: t?.clients?.name, produto: t?.products?.name,
    tipo: drv?.tipo_entrega || '', instrucoes: drv?.instrucoes_ia || '',
    drive: drv || {}, briefings,
    correcao: opts.correcao != null ? opts.correcao : (drv?.status === 'corrigir')
  });
  if (drv && opts.gravar !== false) {
    try { await AP.drive.chamar('briefing', { task_id: taskId, b64: await AP.b64(new TextEncoder().encode(texto)) }); }
    catch(e) { console.warn('Briefing não gravado na pasta:', e.message); }
  }
  return texto;
};

// Decisões do cliente no link público → UM aviso pra equipe, juntando
// os cliques (15 s sem mexer, ou quando ele fecha/troca de aba).
const _decisoes = { token: null, aprovadas: 0, ajustes: 0, timer: null };
function _enviarDecisoes(beacon){
  if (!_decisoes.token || (!_decisoes.aprovadas && !_decisoes.ajustes)) return;
  const corpo = JSON.stringify({ acao: 'cliente_decidiu', token: _decisoes.token,
    aprovadas: _decisoes.aprovadas, ajustes: _decisoes.ajustes });
  _decisoes.aprovadas = 0; _decisoes.ajustes = 0; clearTimeout(_decisoes.timer);
  if (beacon && navigator.sendBeacon) navigator.sendBeacon('/ponte-drive.php', new Blob([corpo], { type: 'application/json' }));
  else fetch('/ponte-drive.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: corpo, keepalive: true }).catch(()=>{});
}
AP.registrarDecisao = function(token, decisao, n){
  if (!token) return;
  _decisoes.token = token;
  if (decisao === 'aprovado') _decisoes.aprovadas += (n || 1); else _decisoes.ajustes += (n || 1);
  clearTimeout(_decisoes.timer);
  _decisoes.timer = setTimeout(() => _enviarDecisoes(false), 15000);
};
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') _enviarDecisoes(true); });
window.addEventListener('pagehide', () => _enviarDecisoes(true));

// Texto pronto pra colar na IA (ChatGPT, Claude, Gemini…)
AP.pedidoIA = function(t, drv, correcao){
  return [
    `Tarefa ${t.id} — ${t.action}${t.cliente ? ' (' + t.cliente + ')' : ''}.`,
    `Pasta no Drive: ${drv.folder_url}`,
    correcao
      ? 'Rodada de CORREÇÃO: leia o correcoes.xlsx na pasta ENTRADA e aplique o que estiver em CORRECAO_CRIATIVO (arte) e CORRECAO_COPY (texto). Mantenha o MESMO NOME de cada arquivo (coluna ARTES).'
      : 'Leia a planilha SD_CONTROLE (o pedido e as referências) e os arquivos da pasta ENTRADA.',
    'Salve tudo o que produzir na pasta SAIDA (subpasta = uma peça; arquivo solto = peça única; textos no Excel modelo).',
    'Quando terminar, escreva TERMINEI na célula B2 da planilha SD_CONTROLE.'
  ].join('\n');
};

window.AP = AP;
})();
