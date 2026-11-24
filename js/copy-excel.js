// =====================================================================
// Pontodot — LEITOR DE PLANILHA DE COPY (o documento da copy é a linha guia)
//
// Lê o Excel como a copy escreveu, sem exigir um modelo único:
//   · várias abas (um perfil por aba, uma semana por aba…) — cada aba com cabeçalho próprio
//   · colunas reconhecidas por nome (Texto no criativo / na arte / cards / roteiro, Direção de arte,
//     Legenda, Interação / CTA, Fonte, Cuidados…), na ordem que vierem
//   · abas de detalhe ("Carrosséis card a card", "Reels cena a cena") entram DENTRO da peça certa
//   · abas de estratégia, fontes e visão geral são reconhecidas e deixadas de fora
//   · o modelo padrão SD (ARQUIVO_OU_PASTA) continua valendo
// Devolve as peças + um resumo do que foi reconhecido (para a pessoa confirmar).
// =====================================================================
(function (root) {
  const SA = s => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
  const SEM_LEG = /^\s*\[?\s*sem\s+legenda[^\]]*\]?\s*$/i;

  // campo → como o cabeçalho pode se chamar (comparado sem acento, minúsculo)
  const CAMPOS = [
    ['data',      h => h === 'data'],
    ['formato',   h => /^formato\b/.test(h) || h === 'tipo' || h === 'tipo de peca'],
    ['numero',    h => h === 'numero' || h === 'n' || h === 'card'],
    ['cena',      h => h === 'cena'],
    ['criativo',  h => /^texto (no|na|da) (criativo|arte|peca)|texto completo do card|texto do card|^cards? ?(\/|e) ?cenas|marcacao de tempo|sugestao de arte|^texto da arte|^roteiro/.test(h)],
    ['arte',      h => /direcao (de arte|visual)|ideia de imagem|captacao$/.test(h) && !/^formato/.test(h)],
    ['legenda',   h => /^legenda/.test(h)],
    ['cta',       h => /^interacao|^cta\b|^cta \//.test(h)],
    ['hashtags',  h => /^hashtag/.test(h)],
    ['fonte',     h => /^fonte|^referencia/.test(h)],
    ['cuidado',   h => /validacao|^revisoes|^revisao|^cuidado/.test(h)],
    ['linha',     h => /^pilar|^linha editorial|^conexao|^perfil sugerido/.test(h)],
    ['produto',   h => /^produto/.test(h)],
    ['prioridade', h => /^prioridade$/.test(h)],
    ['titulo',    h => /^pauta|^titulo|^tema|^conceito|^data comemorativa|^data \/ ocasiao|^carrossel$|^objetivo$/.test(h)],
  ];
  // ordem de preferência quando mais de uma coluna serve de título
  const ORDEM_TITULO = [/^pauta \/ titulo/, /^tema \/ pauta/, /^pauta/, /^titulo/, /^data comemorativa/, /^data \/ ocasiao/, /^carrossel$/, /^tema/, /^conceito/];

  function mapaColunas(linha) {
    const m = {}; const hs = linha.map(SA);
    hs.forEach((h, i) => {
      if (!h) return;
      for (const [campo, fn] of CAMPOS) { if (fn(h)) { if (campo === 'titulo') { const p = ORDEM_TITULO.findIndex(r => r.test(h)); if (m.titulo === undefined || (p >= 0 && p < (m._tp ?? 99))) { m.titulo = i; m._tp = p >= 0 ? p : 99; } } else if (m[campo] === undefined) m[campo] = i; break; } }
    });
    delete m._tp; return m;
  }
  const celula = v => v == null ? '' : (v instanceof Date ? '' : String(v)).replace(/\r/g, '').trim();
  function dataDe(v) {
    if (v instanceof Date && !isNaN(v)) { const d = new Date(v.getTime() + 12 * 3600e3); return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), 12)); }
    const t = String(v == null ? '' : v).trim(); let m = t.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/); if (m) return new Date(Date.UTC(+m[3], +m[2] - 1, +m[1], 12));
    m = t.match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12)) : null;
  }
  function tipoDe(f) {
    const t = SA(f); if (!t) return '';
    if (/stor/.test(t)) return 'stories'; if (/reel|video/.test(t)) return 'reels'; if (/carross/.test(t)) return 'carrossel';
    if (/blog/.test(t)) return 'blog'; if (/capa/.test(t)) return 'capa_site';
    if (/estatic|post|feed|imagem/.test(t)) return 'feed'; return 'feed';
  }
  // aba reconhecida como calendário? precisa de cabeçalho com (data|formato) e (criativo|legenda|título)
  function achaCabecalho(raw) {
    for (let i = 0; i < Math.min(raw.length, 14); i++) {
      const m = mapaColunas(raw[i].map(c => celula(c)));
      const n = Object.keys(m).length;
      if (n >= 3 && (m.data !== undefined || m.formato !== undefined) && (m.criativo !== undefined || m.legenda !== undefined || m.titulo !== undefined)) return { i, m };
    }
    return null;
  }
  const nomePerfil = nome => /^sem\b|^extra|semana|fechamento|quinzena|\b\d{1,2} ?a ?\d{1,2}\b|^\d/i.test(nome) ? '' : nome;

  function ler(XLSX, buf) {
    const wb = XLSX.read(new Uint8Array(buf), { type: 'array', cellDates: true });
    // 1) modelo padrão SD
    const nPad = wb.SheetNames.includes('IMPORTACAO') ? 'IMPORTACAO' : null;
    if (nPad) {
      const r = lerPadrao(XLSX, wb.Sheets[nPad]); if (r) return r;
    }
    const itens = [], detalhes = [], resumo = { abas: [], ignoradas: [] };
    wb.SheetNames.forEach(nome => {
      const raw = XLSX.utils.sheet_to_json(wb.Sheets[nome], { header: 1, defval: '', raw: true });
      const cab = achaCabecalho(raw);
      if (!cab) { resumo.ignoradas.push(nome); return; }
      const { i: h, m } = cab, g = (l, c) => m[c] === undefined ? '' : celula(l[m[c]]);
      const ehCards = m.numero !== undefined && m.criativo !== undefined && /carross/.test(SA(raw[h][m.titulo] || '')) || (m.numero !== undefined && m.formato === undefined && m.criativo !== undefined);
      const ehCenas = m.cena !== undefined; const nSA = SA(nome);
      const ehCompleto = !ehCards && !ehCenas && /card|cena/.test(nSA) && m.formato !== undefined && m.criativo !== undefined;
      const opcional = /data|comemor|opciona|aprovar$/.test(SA(nome)) && /comemor|opciona|datas/.test(SA(nome));
      let n = 0;
      raw.slice(h + 1).forEach(l => {
        const dt = dataDe(l[m.data]); const tit = g(l, 'titulo'), cri = g(l, 'criativo'), leg = g(l, 'legenda');
        if (!dt && !tit && !cri && !leg) return;
        if (ehCompleto) { detalhes.push({ aba: nome, completo: true, tipo: tipoDe(g(l, 'formato')) || 'carrossel', data: dt, titulo: tit, texto: cri, desc: g(l, 'arte'), legenda: leg, cta: g(l, 'cta'), cuidado: g(l, 'cuidado') }); n++; return; }
        if (ehCards || ehCenas) { detalhes.push({ aba: nome, tipo: ehCenas || (/reel/.test(nSA) && !/carross/.test(nSA)) ? 'reels' : 'carrossel', data: dt, titulo: tit, n: g(l, 'numero') || g(l, 'cena'), texto: cri, desc: g(l, 'arte'), legenda: leg, cta: g(l, 'cta'), cuidado: g(l, 'cuidado') }); n++; return; }
        let tipo = tipoDe(g(l, 'formato')); if (!tipo) tipo = 'feed';
        if (!cri && !leg && !tit) return;
        itens.push({ tipo, titulo: tit || (cri.split('\n')[0] || '').slice(0, 90), texto: SEM_LEG.test(leg) ? '' : leg, criativo: cri, visual: g(l, 'arte'), cta: g(l, 'cta'), hashtags: g(l, 'hashtags'),
          fonte: g(l, 'fonte'), cuidado: g(l, 'cuidado'), linha: [g(l, 'linha'), g(l, 'produto')].filter(Boolean).join(' · '), perfil: opcional ? 'Datas comemorativas' : nomePerfil(nome), opcional: opcional || /opcional/.test(SA(g(l, 'prioridade'))), _data: dt, _aba: nome }); n++;
      });
      resumo.abas.push({ nome, n, detalhe: ehCompleto ? 'completo' : ehCards ? 'cards' : ehCenas ? 'cenas' : '' });
    });
    // 2) detalhes (card a card, cena a cena) entram na peça da mesma data e tipo
    const dKey = d => d ? d.toISOString().slice(0, 10) : '';
    const grupos = {};
    detalhes.forEach(d => { const k = dKey(d.data) + '|' + d.tipo + '|' + SA(d.titulo).slice(0, 40); (grupos[k] = grupos[k] || []).push(d); });
    Object.values(grupos).forEach(gr => {
      const d0 = gr[0]; const alvo = itens.find(it => it.tipo === d0.tipo && dKey(it._data) === dKey(d0.data) && (!d0.titulo || SA(it.titulo).includes(SA(d0.titulo).slice(0, 18)) || SA(d0.titulo).includes(SA(it.titulo).slice(0, 18)))) || itens.find(it => it.tipo === d0.tipo && dKey(it._data) === dKey(d0.data));
      const telas = gr.map(d => ({ n: d.n, texto: d.texto, desc: d.desc }));
      if (d0.completo) { if (alvo) { if (d0.texto) alvo.criativo = d0.texto; if (!alvo.visual && d0.desc) alvo.visual = d0.desc; if (!alvo.texto && d0.legenda) alvo.texto = d0.legenda; if (!alvo.cta && d0.cta) alvo.cta = d0.cta; if (d0.cuidado && !alvo.cuidado) alvo.cuidado = d0.cuidado; alvo.completo = true; } else itens.push({ tipo: d0.tipo, titulo: d0.titulo || 'Peça', texto: d0.legenda || '', criativo: d0.texto, visual: d0.desc, cta: d0.cta, hashtags: '', fonte: '', cuidado: d0.cuidado, linha: '', perfil: '', opcional: false, _data: d0.data, _aba: d0.aba }); return; }
      if (alvo) { alvo.telas = telas; if (!alvo.texto && d0.legenda) alvo.texto = d0.legenda; if (!alvo.cta && d0.cta) alvo.cta = d0.cta; if (d0.cuidado && !alvo.cuidado) alvo.cuidado = d0.cuidado; }
      else itens.push({ tipo: d0.tipo, titulo: d0.titulo || (d0.tipo === 'reels' ? 'Reels' : 'Carrossel'), texto: d0.legenda || '', criativo: '', telas, visual: '', cta: d0.cta, hashtags: '', fonte: '', cuidado: d0.cuidado, linha: '', perfil: '', opcional: false, _data: d0.data, _aba: d0.aba });
    });
    return fecha(itens, resumo);
  }

  function lerPadrao(XLSX, ws) {
    const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: true });
    let h = -1; for (let i = 0; i < raw.length; i++) if (raw[i].map(c => SA(c)).includes('arquivo_ou_pasta')) { h = i; break; }
    if (h < 0) return null;
    const head = raw[h].map(x => SA(x).toUpperCase()), col = n => head.indexOf(n); const itens = [];
    raw.slice(h + 1).forEach(l => { const g = n => { const i = col(n); return i >= 0 ? celula(l[i]) : ''; };
      const chave = g('ARQUIVO_OU_PASTA'), leg0 = g('LEGENDA'), tit = g('TITULO'); if (!chave && !leg0 && !tit) return;
      const t = tipoDe(g('TIPO')); itens.push({ tipo: t || 'feed', titulo: tit || chave, texto: SEM_LEG.test(leg0) ? '' : leg0, hashtags: g('HASHTAGS'), visual: g('DIRECAO_VISUAL') || g('DIRECAO_ARTE'), cta: g('CTA'), criativo: g('TEXTO_NO_CRIATIVO'), _data: dataDe(l[col('DATA')]) }); });
    if (!itens.length) return null;
    return fecha(itens, { abas: [{ nome: 'IMPORTACAO', n: itens.length, detalhe: '' }], ignoradas: [], padrao: true });
  }

  function fecha(itens, resumo) {
    const datas = itens.map(i => i._data).filter(Boolean); const cal = datas.length === itens.length && itens.length > 0; let ini = null;
    if (cal) {
      ini = new Date(Math.min(...datas.map(d => +d)));
      itens.forEach((i, k) => { i.dia = Math.round((i._data - ini) / 864e5) + 1; i._k = k; });
      itens.sort((a, b) => a.dia - b.dia || a._k - b._k); itens.forEach(i => delete i._k);
    }
    itens.forEach(i => { i.data = i._data ? i._data.toISOString().slice(0, 10) : ''; delete i._data; delete i._aba; });
    const por = {}; itens.forEach(i => { por[i.tipo] = (por[i.tipo] || 0) + 1; });
    const perfis = [...new Set(itens.filter(i => !i.opcional).map(i => i.perfil).filter(Boolean))];
    const comCriativo = itens.filter(i => i.criativo || (i.telas && i.telas.length)).length;
    resumo.total = itens.length; resumo.porTipo = por; resumo.perfis = perfis; resumo.comCriativo = comCriativo;
    resumo.comTelas = itens.filter(i => (i.telas && i.telas.length) || i.completo).length; itens.forEach(i => delete i.completo); resumo.opcionais = itens.filter(i => i.opcional).length;
    return { itens, calendario: cal, inicio: ini, resumo };
  }

  // frase para a pessoa confirmar
  function frase(r) {
    const R = r.resumo, rot = { feed: 'estáticos', carrossel: 'carrosséis', stories: 'stories', reels: 'reels', blog: 'blog', capa_site: 'capas' };
    const t = Object.entries(R.porTipo).map(([k, v]) => v + ' ' + (rot[k] || k)).join(', ');
    return 'Li ' + R.total + ' peças (' + t + ')' + (R.perfis.length > 1 ? ' em ' + R.perfis.length + ' perfis: ' + R.perfis.join(', ') : '') + '. '
      + (R.comCriativo ? R.comCriativo + ' com texto de criativo' + (R.comTelas ? ' (' + R.comTelas + ' card a card / cena a cena)' : '') + '. ' : 'Nenhuma com texto de criativo separado. ')
      + (R.opcionais ? R.opcionais + ' datas comemorativas opcionais. ' : '') + (R.ignoradas && R.ignoradas.length ? 'Deixei de fora: ' + R.ignoradas.join(', ') + '. ' : '') + 'Confere?';
  }


  // ---------------------------------------------------------------------------------
  // LEITURA GENÉRICA (qualquer planilha: livro, apresentação, papelaria, press…)
  // A IA olha uma amostra de cada aba e devolve só o MAPA (qual linha é cabeçalho, qual coluna é o quê).
  // O texto em si nunca passa pela IA: as peças são montadas daqui, célula a célula, sem reescrever nada.
  // ---------------------------------------------------------------------------------
  const COL = n => { let s = ''; n++; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
  const IDX = L => { let n = 0; String(L || '').toUpperCase().replace(/[^A-Z]/g, '').split('').forEach(c => { n = n * 26 + c.charCodeAt(0) - 64; }); return n - 1; };
  const cel2 = v => v == null ? '' : (v instanceof Date ? v.toISOString().slice(0, 10) : String(v)).replace(/\s+/g, ' ').trim();

  function abas(XLSX, buf) {
    const wb = XLSX.read(buf, { type: 'array', cellDates: true });
    return wb.SheetNames.map(nome => ({ nome, linhas: XLSX.utils.sheet_to_json(wb.Sheets[nome], { header: 1, raw: true, defval: '', blankrows: true }) }));
  }

  // amostra compacta para a IA: nome da aba, tamanho e as primeiras linhas com letras de coluna
  function amostra(XLSX, buf) {
    return abas(XLSX, buf).map(a => {
      const L = a.linhas, tot = L.filter(r => r.some(c => cel2(c))).length; let out = '### ABA "' + a.nome + '" (' + L.length + ' linhas, ~' + tot + ' preenchidas)\n', n = 0;
      for (let i = 0; i < L.length && n < 14; i++) {
        const cs = L[i].map((c, k) => [COL(k), cel2(c)]).filter(x => x[1]).map(x => x[0] + '=' + (x[1].length > 90 ? x[1].slice(0, 90) + '…' : x[1]));
        if (cs.length) { out += 'L' + (i + 1) + ': ' + cs.join(' | ') + '\n'; n++; }
      }
      return out;
    }).join('\n').slice(0, 38000);
  }

  // monta as peças a partir do mapa que a IA devolveu
  function aplicarMapa(XLSX, buf, mapa) {
    const todas = abas(XLSX, buf), itens = [], ignoradas = [], usadas = [];
    (mapa.abas || []).forEach(m => {
      const a = todas.find(x => x.nome === m.nome); if (!a) return;
      if (m.usar === false) { ignoradas.push(m.nome); return; }
      const c = m.colunas || {}, ix = {}; Object.keys(c).forEach(k => { if (c[k]) ix[k] = IDX(c[k]); });
      const ini = Math.max(0, (parseInt(m.cabecalho, 10) || 0)); const cab = ini ? a.linhas[ini - 1] || [] : [];
      const uni = (m.unidade || mapa.unidade || 'Peça').trim();
      const extras = (m.extras || []).map(l => ({ i: IDX(l), h: cel2(cab[IDX(l)]) }));
      let n = 0;
      for (let r = ini; r < a.linhas.length; r++) {
        const l = a.linhas[r], g = k => ix[k] === undefined ? '' : String(l[ix[k]] == null ? '' : (l[ix[k]] instanceof Date ? l[ix[k]].toISOString().slice(0, 10) : l[ix[k]])).replace(/\r/g, '').trim();
        const tit = g('titulo'), cri = g('criativo'), txt = g('texto'), vis = g('visual');
        if (!tit && !cri && !txt && !vis) continue;
        n++;
        const ex = extras.map(e => { const v = cel2(l[e.i]); return v ? (e.h ? e.h + ': ' : '') + v : ''; }).filter(Boolean).join('\n');
        const tp = SA(g('tipo')), kindSite = ['feed', 'carrossel', 'stories', 'reels', 'blog'].find(k => tp.indexOf(k) === 0 || (k === 'feed' && /estatic/.test(tp)));
        const numero = g('numero');
        itens.push({
          tipo: kindSite || 'material', unidade: kindSite ? '' : (g('tipo') || uni),
          titulo: tit || (cri ? cri.split('\n')[0].slice(0, 70) : uni + ' ' + (numero || n)),
          texto: [txt, ex].filter(Boolean).join('\n\n'), criativo: cri, visual: vis, cta: g('cta'), hashtags: g('hashtags'),
          fonte: g('fonte'), cuidado: g('cuidado'), perfil: g('perfil') || (m.usar_como_perfil ? m.nome : ''), opcional: false, data: g('data'), ordem: numero
        });
      }
      if (n) usadas.push(m.nome + ' (' + n + ')');
    });
    const por = {}; itens.forEach(i => { const k = i.unidade || i.tipo; por[k] = (por[k] || 0) + 1; });
    const perfis = [...new Set(itens.map(i => i.perfil).filter(Boolean))];
    const resumo = { total: itens.length, porTipo: por, perfis, comCriativo: itens.filter(i => i.criativo).length, comTelas: 0, opcionais: 0, ignoradas, abas: usadas, generico: true, documento: mapa.documento || '', leitura: mapa.resumo || '' };
    return { itens, calendario: false, inicio: null, resumo };
  }

  function fraseGen(r) {
    const R = r.resumo, t = Object.entries(R.porTipo).map(([k, v]) => v + ' ' + k.toLowerCase() + (v > 1 ? 's' : '')).join(', ');
    return (R.documento ? 'Entendi: ' + R.documento + '. ' : '') + (R.leitura ? R.leitura + ' ' : '') + 'Montei ' + R.total + ' peça' + (R.total > 1 ? 's' : '') + ' (' + t + ')'
      + (R.comCriativo ? ', ' + R.comCriativo + ' com texto para a arte' : '') + '.' + (R.ignoradas.length ? ' Deixei de fora: ' + R.ignoradas.join(', ') + '.' : '') + ' Confere?';
  }

  const API = { ler, frase, SEM_LEG, amostra, aplicarMapa, fraseGen };
  if (typeof module !== 'undefined' && module.exports) module.exports = API; else root.CopyExcel = API;
})(typeof window !== 'undefined' ? window : globalThis);
