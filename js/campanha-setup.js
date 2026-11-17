/* Excel de SETUP DE CAMPANHA — lê o modelo (CAMPANHA, PERSONA, PUBLICOS, ANUNCIOS) e vira o plano da campanha.
   Usado pelo upload da tarefa (novo-lote.html) e pela janela Campanhas (index.html): um formato só, um jeito só.
   Precisa da biblioteca SheetJS (XLSX) carregada na página. */
window.CampSetup = (function () {
  const sem = s => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
  const chave = s => sem(s).toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, '');
  const txt = v => String(v == null ? '' : v).trim();
  const lista = v => txt(v).split(/[;\n]/).map(x => x.trim()).filter(Boolean);
  const ehExemplo = v => /^EXEMPLO/i.test(sem(v));
  const OBJ = { LEADS: 'OUTCOME_LEADS', TRAFEGO: 'OUTCOME_TRAFFIC', ENGAJAMENTO: 'OUTCOME_ENGAGEMENT', RECONHECIMENTO: 'OUTCOME_AWARENESS', VENDAS: 'OUTCOME_SALES' };
  const OTM = { LEADS: 'LEAD_GENERATION', CLIQUES: 'LINK_CLICKS', ALCANCE: 'REACH', CONVERSAS: 'CONVERSATIONS' };
  const CTA = { SAIBA_MAIS: 'LEARN_MORE', CADASTRE_SE: 'SIGN_UP', WHATSAPP: 'WHATSAPP_MESSAGE', PEDIR_COTACAO: 'GET_QUOTE', FALE_CONOSCO: 'CONTACT_US', AGENDAR: 'BOOK_NOW', INSCREVA_SE: 'SUBSCRIBE' };
  const DIAS = { DOM: 0, SEG: 1, TER: 2, QUA: 3, QUI: 4, SEX: 5, SAB: 6 };

  function achaAba(wb, nome) { const n = wb.SheetNames.find(x => chave(x) === nome); return n ? wb.Sheets[n] : null; }
  function tabela(ws) {   // linhas -> objetos, com o título da coluna normalizado
    if (!ws) return [];
    const m = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false });
    if (!m.length) return [];
    const cab = m[0].map(chave);
    return m.slice(1).map(l => { const o = {}; cab.forEach((c, i) => { if (c) o[c] = l[i]; }); return o; })
      .filter(o => Object.values(o).some(v => txt(v) !== '') && !ehExemplo(Object.values(o)[0]));
  }
  function chaveValor(ws) {
    const o = {}; tabela(ws).forEach(r => { const k = chave(r.CAMPO); if (k) o[k] = txt(r.VALOR); }); return o;
  }
  function horarios(s) {   // "Seg-Sex 08:00-22:00; Sab-Dom 09:00-20:00"
    return lista(s).map(f => {
      const m = sem(f).toLowerCase().match(/^([a-z]{3})[a-z]*\s*(?:-|a|ate)?\s*([a-z]{3})?[a-z]*\s+(\d{1,2}[:h]?\d{0,2})\s*(?:-|as|a|ate)\s*(\d{1,2}[:h]?\d{0,2})$/);
      if (!m) return null;
      const ini = DIAS[m[1].toUpperCase()], fim = m[2] ? DIAS[m[2].toUpperCase()] : ini; if (ini == null || fim == null) return null;
      const dias = []; for (let d = ini, n = 0; n < 8; n++, d = (d + 1) % 7) { dias.push(d); if (d === fim) break; }
      const hh = x => { const t = x.replace('h', ':'); const [h, mi] = t.split(':'); return String(h).padStart(2, '0') + ':' + String(mi || '00').padEnd(2, '0').slice(0, 2); };
      return { dias, de: hh(m[3]), ate: hh(m[4]) };
    }).filter(Boolean);
  }

  /** O arquivo é um setup de campanha? (tem as abas CAMPANHA e ANUNCIOS) */
  function ehSetup(wb) { return !!(achaAba(wb, 'CAMPANHA') && achaAba(wb, 'ANUNCIOS')); }

  function ler(buf) {
    const wb = XLSX.read(buf, { type: 'array' });
    if (!ehSetup(wb)) throw new Error('Este arquivo não é o modelo de setup de campanha (faltam as abas CAMPANHA e ANUNCIOS).');
    const c = chaveValor(achaAba(wb, 'CAMPANHA')), p = chaveValor(achaAba(wb, 'PERSONA'));
    const publicos = tabela(achaAba(wb, 'PUBLICOS')).map(r => ({
      conjunto: txt(r.CONJUNTO), persona: txt(r.PERSONA), cidades: lista(r.CIDADES), raio: +r.RAIO_KM || 25, interesses: lista(r.INTERESSES),
      verba: parseFloat(String(r.VERBA_DIA).replace(/[^\d,.-]/g, '').replace(',', '.')) || 50, otimizacao: txt(r.OTIMIZACAO), horarios: horarios(r.HORARIOS) }));
    const anuncios = tabela(achaAba(wb, 'ANUNCIOS')).map(r => ({
      anuncio: txt(r.ANUNCIO), conjunto: txt(r.CONJUNTO), peca: txt(r.PECA), titulo: txt(r.TITULO), texto: txt(r.TEXTO), descricao: txt(r.DESCRICAO), botao: txt(r.BOTAO) }));
    const avisos = [];
    if (!txt(c.NOME_DA_CAMPANHA)) avisos.push('Falta o nome da campanha.');
    if (!publicos.length) avisos.push('A aba PUBLICOS está vazia.');
    if (!anuncios.length) avisos.push('A aba ANUNCIOS está vazia.');
    anuncios.forEach(a => { if (!a.peca) avisos.push('O anúncio "' + (a.anuncio || '?') + '" não diz qual peça usar (coluna PECA).'); });
    return { campanha: { nome: txt(c.NOME_DA_CAMPANHA), objetivo: txt(c.OBJETIVO) || 'Leads', inicio: txt(c.INICIO), fim: txt(c.FIM), formulario: txt(c.FORMULARIO_DE_LEADS), whatsapp: txt(c.WHATSAPP).replace(/\D/g, ''), link: txt(c.LINK_DE_DESTINO) },
      persona: { nome: txt(p.NOME_DA_PERSONA), quem: txt(p.QUEM_E), busca: txt(p.O_QUE_BUSCA), dores: txt(p.DORES_E_OBJECOES), gatilhos: txt(p.GATILHOS_DE_DECISAO), tom: txt(p.TOM_DE_VOZ), referencias: txt(p.REFERENCIAS_DE_PERFIL) },
      publicos, anuncios, avisos };
  }

  const norm = s => sem(s).toLowerCase().replace(/\.[a-z0-9]{2,4}$/, '').replace(/[^a-z0-9]+/g, ' ').trim();
  /** dados do setup + peças aprovadas do cliente -> plano no formato da janela Campanhas */
  function paraPlano(d, pecas) {
    const conjuntos = d.publicos.map((u, i) => ({ nome: u.conjunto || 'Conjunto ' + (i + 1), orcamento_diario: u.verba, otimizacao: OTM[chave(u.otimizacao)] || undefined,
      publico: { cidades: u.cidades.map(n => ({ nome: n })), interesses: u.interesses.map(n => ({ nome: n })), raio_km: u.raio }, horarios: u.horarios }));
    const achaConj = nome => { const k = conjuntos.findIndex(c => norm(c.nome) === norm(nome)); return k >= 0 ? k : 0; };
    const sobra = [];
    const anuncios = d.anuncios.map((a, i) => {
      const alvo = norm(a.peca); const p = alvo && (pecas || []).find(x => { const t = norm(x.titulo); return t === alvo || t.includes(alvo) || alvo.includes(t); });
      if (!p) sobra.push(a.anuncio || 'Anúncio ' + (i + 1));
      return { nome: a.anuncio || 'Anúncio ' + (i + 1), conjunto: achaConj(a.conjunto), peca_id: p ? p.id : '', titulo: a.titulo, texto: a.texto, descricao: a.descricao, cta: CTA[chave(a.botao)] || undefined };
    });
    return { plano: { campanha: { nome: d.campanha.nome, objetivo: OBJ[chave(d.campanha.objetivo)] || 'OUTCOME_LEADS', inicio: d.campanha.inicio, fim: d.campanha.fim }, persona: d.persona, conjuntos, anuncios },
      extras: { formulario: d.campanha.formulario, whatsapp: d.campanha.whatsapp, link: d.campanha.link }, semPeca: sobra };
  }
  return { ler, paraPlano, ehSetup, horarios };
})();
