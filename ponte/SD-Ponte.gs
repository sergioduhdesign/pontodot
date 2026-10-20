/**
 * =====================================================================
 * SD Workflow — PONTE COM O GOOGLE DRIVE  (Google Apps Script)
 *
 * Roda dentro da conta Google da agência. É o único ponto que mexe no
 * Drive: cria as pastas da tarefa, lê o que a IA entregou, grava o
 * Excel de correções e, a cada 15 minutos, olha se alguma IA escreveu
 * TERMINEI na planilha de controle — aí avisa o SD.
 *
 * INSTALAÇÃO (uma vez, ~5 min) — veja ponte/LEIA-ME-PONTE.txt
 *   1. script.google.com → Novo projeto → cole este arquivo
 *   2. Configurações do projeto → Propriedades do script:
 *        SD_URL  = https://sd.sergioduh.com/ponte-drive.php
 *        SD_KEY  = (a mesma chave do sd-config.php → SD_PONTE_KEY)
 *        RAIZ_ID = (opcional) ID da pasta onde nascem as pastas novas
 *   3. Rode a função  instalar  (autorize o acesso ao Drive)
 *   4. Implantar → Nova implantação → App da Web
 *        Executar como: Eu · Quem pode acessar: Qualquer pessoa
 *      Copie a URL e cole no sd-config.php → SD_PONTE_URL
 * =====================================================================
 */

var STATUS_CELULA = 'B2';      // onde a IA escreve TERMINEI
var NOME_CONTROLE = 'SD_CONTROLE';
var MAX_BYTES = 30 * 1024 * 1024;

function prop_(k) { return PropertiesService.getScriptProperties().getProperty(k) || ''; }

// ---------------------------------------------------------------------
// ENTRADA DO SD (App da Web)
// ---------------------------------------------------------------------
function doPost(e) {
  var r;
  try {
    var d = JSON.parse(e.postData.contents || '{}');
    if (!prop_('SD_KEY') || d.key !== prop_('SD_KEY')) throw new Error('Chave inválida.');
    var acoes = {
      // produção (pastas de tarefa, planilha de controle, arquivos)
      ping: ping_, preparar: preparar_, status: escreverStatus_, ler_status: lerStatus_,
      listar: listar_, baixar: baixar_, gravar: gravar_, baixar_parte: baixarParte_, copiar: copiar_,
      // reuniões / atas  (sem isto a pasta ATAS nunca é preenchida)
      reunioes_listar: reunioesListar_, reuniao_ler: reuniaoLer_,
      reuniao_salvar_ata: reuniaoSalvarAta_, reunioes_buscar: reunioesBuscar_,
      // conhecimento dos clientes (pasta mestre de instruções) + pasta por cliente
      pasta_subpastas: pastaSubpastas_, pasta_garantir: pastaGarantir_,
      docs_listar: docsListar_, doc_ler: reuniaoLer_,
      // aprovações morando no Drive em vez de comer o plano do Supabase
      aprov_pasta: aprovPasta_, aprov_subir: aprovSubir_,
      aprov_puxar: aprovPuxar_, aprov_conferir: aprovConferir_
    };
    if (!acoes[d.acao]) throw new Error('Ação desconhecida: ' + d.acao);
    r = acoes[d.acao](d);
    r.ok = true;
  } catch (err) {
    r = { ok: false, erro: String(err && err.message || err) };
  }
  return ContentService.createTextOutput(JSON.stringify(r)).setMimeType(ContentService.MimeType.JSON);
}

function doGet() {
  return ContentService.createTextOutput('SD Ponte ativa — ' + Session.getEffectiveUser().getEmail());
}

function ping_() { return { conta: Session.getEffectiveUser().getEmail() }; }

// ---------------------------------------------------------------------
// PASTAS
// ---------------------------------------------------------------------
function pastaFilha_(pai, nome) {
  var it = pai.getFoldersByName(nome);
  return it.hasNext() ? it.next() : pai.createFolder(nome);
}

// Cria (ou adota) a pasta da tarefa: ENTRADA, SAIDA e a planilha de
// controle com o briefing pra IA. Usar uma pasta que já existe é
// seguro: nada que está lá dentro é apagado ou movido.
function preparar_(d) {
  var raiz;
  if (d.folderId) {
    raiz = DriveApp.getFolderById(d.folderId);
  } else {
    var pai = d.parentId ? DriveApp.getFolderById(d.parentId)
            : (prop_('RAIZ_ID') ? DriveApp.getFolderById(prop_('RAIZ_ID')) : DriveApp.getRootFolder());
    raiz = pai.createFolder(d.nome || 'Tarefa SD');
  }
  var entrada = pastaFilha_(raiz, 'ENTRADA');
  var saida = pastaFilha_(raiz, 'SAIDA');

  var ss, arqs = raiz.getFilesByName(NOME_CONTROLE);
  if (arqs.hasNext()) {
    ss = SpreadsheetApp.open(arqs.next());
  } else {
    ss = SpreadsheetApp.create(NOME_CONTROLE);
    DriveApp.getFileById(ss.getId()).moveTo(raiz);
  }
  montarControle_(ss, d.briefing || {});

  return {
    folderId: raiz.getId(), folderUrl: raiz.getUrl(), nome: raiz.getName(),
    entradaId: entrada.getId(), saidaId: saida.getId(),
    controleId: ss.getId(), controleUrl: ss.getUrl()
  };
}

function montarControle_(ss, b) {
  var sh = ss.getSheets()[0];
  sh.setName('CONTROLE');
  var atual = sh.getRange(STATUS_CELULA).getValue();
  var linhas = [
    ['SD WORKFLOW — CONTROLE DA TAREFA', ''],
    ['STATUS', atual || 'AGUARDANDO'],
    ['RODADA', sh.getRange('B3').getValue() || 0],
    ['ATUALIZADO', new Date()],
    ['', ''],
    ['TAREFA', b.tarefa || ''],
    ['CLIENTE', b.cliente || ''],
    ['PROJETO', b.projeto || ''],
    ['O QUE FAZER', b.pedido || ''],
    ['REFERÊNCIAS / DESIGN SYSTEM', b.referencia || ''],
    ['', ''],
    ['COMO TRABALHAR (para a IA)', ''],
    ['1', 'Leia esta planilha e a pasta ENTRADA (arquivos de origem e, quando houver, correcoes.xlsx).'],
    ['2', 'Salve as PEÇAS FINAIS na SAIDA, uma por arquivo, no tamanho final. Uma subpasta por post com o tipo no nome (01-carrossel-tema, 02-feed-tema, 03-stories-tema, 04-reels-tema), um arquivo por card (01.png, 02.png…). NUNCA prancha, mockup ou amostra com várias peças numa imagem. O que não é post vai em material-<nome>. Regras completas: seção COMO ENTREGAR NA SAIDA do 00_BRIEFING_IA.txt.'],
    ['3', 'Correção: mantenha o MESMO NOME de arquivo da coluna ARTES do correcoes.xlsx — é assim que o SD troca no lugar.'],
    ['4', 'Textos: legendas.xlsx na SAIDA, uma linha por post — ARQUIVO_OU_PASTA | TIPO | TITULO | LEGENDA | HASHTAGS | DATA | HORA (Brasília).'],
    ['5', 'Quando terminar, escreva  TERMINEI  na célula B2 (STATUS). É o sinal pro SD importar.'],
  ];
  sh.getRange(1, 1, linhas.length, 2).setValues(linhas);
  sh.getRange('A1').setFontWeight('bold').setFontSize(13);
  sh.getRange('A2:A20').setFontWeight('bold');
  sh.getRange('B2').setFontWeight('bold').setBackground('#fff4cc');
  sh.setColumnWidth(1, 230); sh.setColumnWidth(2, 640);
  sh.getRange('B1:B20').setWrap(true);

  var log = ss.getSheetByName('LOG') || ss.insertSheet('LOG');
  if (log.getLastRow() === 0) log.appendRow(['QUANDO', 'STATUS', 'RODADA', 'OBS']);
}

function escreverStatus_(d) {
  var ss = SpreadsheetApp.openById(d.controleId);
  if (d.briefing) montarControle_(ss, d.briefing);
  var sh = ss.getSheets()[0];
  sh.getRange(STATUS_CELULA).setValue(d.status);
  if (d.rodada != null) sh.getRange('B3').setValue(d.rodada);
  sh.getRange('B4').setValue(new Date());
  var log = ss.getSheetByName('LOG') || ss.insertSheet('LOG');
  log.appendRow([new Date(), d.status, d.rodada || '', d.obs || '']);
  return {};
}

function lerStatus_(d) {
  var sh = SpreadsheetApp.openById(d.controleId).getSheets()[0];
  return { status: String(sh.getRange(STATUS_CELULA).getValue() || '').trim(),
           rodada: sh.getRange('B3').getValue() };
}

// ---------------------------------------------------------------------
// ARQUIVOS — lista recursiva, com o caminho relativo (pasta/arquivo),
// que é o que o SD usa pra casar cada arte com a peça certa.
// ---------------------------------------------------------------------
function listar_(d) {
  var raiz = DriveApp.getFolderById(d.folderId);
  var out = [];
  (function andar(pasta, prefixo, nivel) {
    if (nivel > 6) return;
    var fs = pasta.getFiles();
    while (fs.hasNext()) {
      var f = fs.next();
      out.push({ id: f.getId(), name: f.getName(), path: prefixo + f.getName(),
                 mime: f.getMimeType(), size: f.getSize(),
                 modified: f.getLastUpdated().toISOString(), url: f.getUrl() });
    }
    var ps = pasta.getFolders();
    while (ps.hasNext()) { var p = ps.next(); andar(p, prefixo + p.getName() + '/', nivel + 1); }
  })(raiz, '', 0);
  return { arquivos: out };
}

// Apresentação (.pptx, Google Slides) e documento (Google Docs) saem
// como PDF — o SD transforma cada página numa imagem de aprovação.
var APRESENTACAO = /\.(pptx?|odp)$/i;

function token_() { return { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() }; }

function exportarPdf_(id, tipo) {
  var r = UrlFetchApp.fetch('https://docs.google.com/' + tipo + '/d/' + id + '/export/pdf',
    { headers: token_(), muteHttpExceptions: true });
  if (r.getResponseCode() >= 300) throw new Error('Não consegui exportar o PDF (' + r.getResponseCode() + ').');
  return r.getBlob();
}

// .pptx → cópia temporária em Google Slides → PDF → apaga a cópia
function pptxParaPdf_(f) {
  var r = UrlFetchApp.fetch('https://www.googleapis.com/drive/v3/files/' + f.getId() + '/copy?supportsAllDrives=true', {
    method: 'post', contentType: 'application/json', headers: token_(), muteHttpExceptions: true,
    payload: JSON.stringify({ name: f.getName() + ' (SD temporário)',
      mimeType: 'application/vnd.google-apps.presentation', parents: [DriveApp.getRootFolder().getId()] })
  });
  if (r.getResponseCode() >= 300) {
    var txt = r.getContentText();
    if (/accessNotConfigured|has not been used|disabled/i.test(txt))
      throw new Error('Ative o serviço "Drive API" no Apps Script (Serviços → + → Drive API) pra converter PPTX.');
    throw new Error('Conversão do PPTX falhou: ' + txt.slice(0, 160));
  }
  var tmp = JSON.parse(r.getContentText()).id;
  try { return exportarPdf_(tmp, 'presentation'); }
  finally { try { DriveApp.getFileById(tmp).setTrashed(true); } catch (e) {} }
}

function baixar_(d) {
  var f = DriveApp.getFileById(d.fileId);
  var mime = f.getMimeType(), nome = f.getName(), blob;
  var base = nome.replace(/\.[^.]+$/, '');
  if (mime === MimeType.GOOGLE_SLIDES || mime === MimeType.GOOGLE_DOCS) {
    blob = exportarPdf_(f.getId(), mime === MimeType.GOOGLE_SLIDES ? 'presentation' : 'document');
    return { name: base + '.pdf', mime: 'application/pdf', origem: nome, b64: Utilities.base64Encode(blob.getBytes()) };
  }
  if (APRESENTACAO.test(nome) || mime.indexOf('presentationml') > -1 || mime === 'application/vnd.ms-powerpoint') {
    blob = pptxParaPdf_(f);
    return { name: base + '.pdf', mime: 'application/pdf', origem: nome, b64: Utilities.base64Encode(blob.getBytes()) };
  }
  if (mime === MimeType.GOOGLE_SHEETS) {
    // Planilha Google → exporta como .xlsx (o SD lê o Excel)
    var url = 'https://docs.google.com/spreadsheets/d/' + f.getId() + '/export?format=xlsx';
    blob = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() } }).getBlob();
    mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if (!/\.xlsx$/i.test(nome)) nome += '.xlsx';
  } else if (mime.indexOf('application/vnd.google-apps') === 0) {
    throw new Error('Arquivo nativo do Google (' + mime + ') — exporte como imagem/PDF na SAIDA.');
  } else {
    if (f.getSize() > MAX_BYTES) throw new Error('Arquivo grande demais pra importar (' + Math.round(f.getSize()/1048576) + ' MB).');
    blob = f.getBlob();
  }
  return { name: nome, mime: mime, b64: Utilities.base64Encode(blob.getBytes()) };
}

/** Um PEDAÇO do arquivo (pra publicar vídeo inteiro na Meta). O servidor
 *  pede de 15 em 15 MB e monta o arquivo do lado de lá. Serve pra
 *  qualquer arquivo que esta conta enxerga, compartilhado ou não. */
function baixarParte_(d) {
  var f = DriveApp.getFileById(d.fileId);
  var total = f.getSize(), ini = Math.max(0, Number(d.inicio) || 0);
  var tam = Math.min(Math.max(Number(d.tamanho) || 15728640, 1048576), 20971520);
  var info = { total: total, inicio: ini, mime: f.getMimeType(), nome: f.getName() };
  if (String(info.mime).indexOf('application/vnd.google-apps') === 0) throw new Error('Arquivo nativo do Google não publica — exporte como imagem ou vídeo.');
  if (ini >= total) { info.b64 = ''; info.fim = true; return info; }
  var fimB = Math.min(total, ini + tam) - 1;
  var r = UrlFetchApp.fetch('https://www.googleapis.com/drive/v3/files/' + f.getId() + '?alt=media&supportsAllDrives=true', {
    headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken(), Range: 'bytes=' + ini + '-' + fimB },
    muteHttpExceptions: true
  });
  var code = r.getResponseCode();
  if (code !== 206 && code !== 200) throw new Error('Drive recusou o pedaço (HTTP ' + code + ').');
  var bytes = r.getContent();
  if (code === 200 && ini > 0) bytes = bytes.slice(ini, fimB + 1);   // Drive ignorou o Range
  info.b64 = Utilities.base64Encode(bytes);
  info.fim = ini + bytes.length >= total;
  return info;
}

/** Copia um arquivo do Drive pra dentro de uma pasta SEM baixar nada: serve pra
 *  qualquer tamanho (PDF de 200 MB, vídeo, apresentação). Se já existe um
 *  arquivo com o mesmo nome na pasta de destino, ele é substituído. */
function copiar_(d) {
  var f = DriveApp.getFileById(d.fileId);
  var pasta = DriveApp.getFolderById(d.folderId);
  var nome = f.getName();
  var it = pasta.getFilesByName(nome);
  while (it.hasNext()) it.next().setTrashed(true);
  var c = f.makeCopy(nome, pasta);
  return { id: c.getId(), url: c.getUrl(), nome: nome, tamanho: c.getSize(), mime: c.getMimeType() };
}

function gravar_(d) {
  var pasta = DriveApp.getFolderById(d.folderId);
  if (d.substituir !== false) {
    var it = pasta.getFilesByName(d.nome);
    while (it.hasNext()) it.next().setTrashed(true);
  }
  var blob = Utilities.newBlob(Utilities.base64Decode(d.b64), d.mime || 'application/octet-stream', d.nome);
  var f = pasta.createFile(blob);
  return { id: f.getId(), url: f.getUrl() };
}

// ---------------------------------------------------------------------
// VARREDURA — a cada 15 min: pergunta ao SD quais tarefas estão com a
// IA, lê o STATUS de cada uma e avisa quando aparecer TERMINEI.
// ---------------------------------------------------------------------
function varrer() {
  var lista = chamarSD_({ acao: 'tarefas_ativas' });
  if (!lista || !lista.tarefas) return;
  lista.tarefas.forEach(function (t) {
    try {
      var st = lerStatus_({ controleId: t.controle_id }).status.toUpperCase();
      var n = 0;
      if (st.indexOf('TERMINEI') === 0 || st === 'PRONTO' || st === 'ENTREGUE') {
        n = listar_({ folderId: t.saida_id }).arquivos.length;
        chamarSD_({ acao: 'entrega', task_id: t.task_id, arquivos: n, status_lido: st });
        escreverStatus_({ controleId: t.controle_id, status: 'RECEBIDO PELO SD', rodada: t.rodada,
                          obs: n + ' arquivo(s) na SAIDA' });
      } else {
        chamarSD_({ acao: 'check', task_id: t.task_id });
      }
    } catch (err) {
      chamarSD_({ acao: 'check', task_id: t.task_id, erro: String(err.message || err) });
    }
  });
}

function chamarSD_(corpo) {
  corpo.key = prop_('SD_KEY');
  var r = UrlFetchApp.fetch(prop_('SD_URL'), {
    method: 'post', contentType: 'application/json',
    payload: JSON.stringify(corpo), muteHttpExceptions: true
  });
  try { return JSON.parse(r.getContentText()); } catch (e) { return null; }
}

// Rode uma vez: autoriza o Drive e liga a varredura a cada 15 minutos.
function instalar() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'varrer') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('varrer').timeBased().everyMinutes(15).create();
  DriveApp.getRootFolder();            // força o pedido de permissão do Drive
  Logger.log('Ponte instalada. Conta: ' + Session.getEffectiveUser().getEmail());
}

// =====================================================================
// BLOCO DE REUNIÕES / ATAS
// A edge sd-reunioes chama estas 4 ações. Sem elas, a varredura das
// reuniões responde "Ação desconhecida" e a pasta ATAS fica vazia.
// =====================================================================

// Lista os docs da pasta do Read.ai (as notas ficam na raiz dela).
// d.pasta_id = id da pasta; d.desde_iso = só modificados depois disso (opcional).
function reunioesListar_(d) {
  var raiz = DriveApp.getFolderById(d.pasta_id);
  var desde = d.desde_iso ? new Date(d.desde_iso) : null;
  var out = [], pilha = [raiz], vistos = 0;
  // a pasta do Read.ai tem subpastas (ex.: "Alinhamento - Cliente - data"): entra em todas
  while (pilha.length && vistos < 200) {
    var pasta = pilha.pop(); vistos++;
    var it = pasta.getFiles();
    while (it.hasNext()) {
      var f = it.next();
      if (!legivel_(f.getMimeType())) continue;
      var mod = f.getLastUpdated();
      if (desde && mod < desde) continue;
      out.push({ id: f.getId(), titulo: f.getName(), modificado: mod.toISOString(), tipo: f.getMimeType(), pasta: pasta.getName() });
    }
    var sub = pasta.getFolders();
    while (sub.hasNext()) pilha.push(sub.next());
  }
  out.sort(function (a, b) { return a.modificado < b.modificado ? -1 : 1; });
  return { arquivos: out };
}

// Lê o texto de uma nota/documento (Google Doc, .txt, PDF). d.doc_id.
// Texto de um Google Doc SEM o serviço Docs (só a permissão do Drive, que já existe): exporta como texto puro.
function docTexto_(id) {
  var r = UrlFetchApp.fetch('https://www.googleapis.com/drive/v3/files/' + id + '/export?mimeType=text%2Fplain&supportsAllDrives=true',
    { headers: token_(), muteHttpExceptions: true });
  if (r.getResponseCode() >= 300) throw new Error('export do Doc falhou (' + r.getResponseCode() + '): ' + r.getContentText().slice(0, 160));
  return r.getContentText('UTF-8').replace(/^\uFEFF/, '');
}
function reuniaoLer_(d) {
  var f = DriveApp.getFileById(d.doc_id);
  var mime = f.getMimeType(), texto = '';
  if (mime === MimeType.GOOGLE_DOCS) {
    texto = docTexto_(d.doc_id);
  } else if (mime === MimeType.PDF) {
    // PDF vira Doc temporário só pra extrair o texto, e some em seguida
    var tmp = null;
    try {
      var r = UrlFetchApp.fetch('https://www.googleapis.com/drive/v3/files/' + f.getId() + '/copy?supportsAllDrives=true', {
        method: 'post', contentType: 'application/json', headers: token_(), muteHttpExceptions: true,
        payload: JSON.stringify({ name: f.getName() + ' (SD temporário)', mimeType: MimeType.GOOGLE_DOCS })
      });
      if (r.getResponseCode() < 300) {
        tmp = JSON.parse(r.getContentText()).id;
        texto = docTexto_(tmp);
      }
    } catch (e) { texto = ''; }
    finally { if (tmp) { try { DriveApp.getFileById(tmp).setTrashed(true); } catch (e2) {} } }
    if (!texto) throw new Error('Não consegui ler o PDF ' + f.getName() + ' (ative o serviço Drive API).');
  } else if (mime === MimeType.GOOGLE_SHEETS) {
    var sh = SpreadsheetApp.openById(d.doc_id).getSheets()[0];
    texto = sh.getDataRange().getValues().map(function (l) { return l.join(' | '); }).join('\n');
  } else {
    texto = f.getBlob().getDataAsString('UTF-8');
  }
  // LEITURA EM JANELAS: a nota do Read.ai (resumo + capítulos + TRANSCRIÇÃO) passa fácil de 80 mil caracteres.
  // Nada de cortar em silêncio: d.ini = onde começa, d.max = quanto devolver; 'fim' diz se acabou.
  var total = texto.length, ini = Math.max(0, Number(d.ini) || 0), max = Math.min(Number(d.max) || 150000, 250000);
  var pedaco = texto.slice(ini, ini + max);
  return { titulo: f.getName(), texto: pedaco, url: f.getUrl(), mime: mime, total: total, ini: ini, fim: ini + pedaco.length >= total,
           modificado: f.getLastUpdated().toISOString() };
}

// Salva a ata gerada como Google Doc na pasta de atas.
// d.pasta_atas_id, d.titulo, d.texto.
function reuniaoSalvarAta_(d) {
  var doc = DocumentApp.create(d.titulo);
  doc.getBody().setText(d.texto);
  doc.saveAndClose();
  var f = DriveApp.getFileById(doc.getId());
  DriveApp.getFolderById(d.pasta_atas_id).addFile(f);
  DriveApp.getRootFolder().removeFile(f);
  return { doc_id: doc.getId(), url: f.getUrl() };
}

// Pesquisa na pasta (memória de tudo que foi conversado com clientes).
// d.pasta_id, d.termo, d.limite (padrão 8). Busca em título e conteúdo.
function reunioesBuscar_(d) {
  var lim = d.limite || 8;
  var termo = String(d.termo || '').replace(/'/g, "\\'");
  var pasta = DriveApp.getFolderById(d.pasta_id);
  var it = pasta.searchFiles("fullText contains '" + termo + "' or title contains '" + termo + "'");
  var out = [];
  while (it.hasNext() && out.length < lim) {
    var f = it.next();
    out.push({ id: f.getId(), titulo: f.getName(), modificado: f.getLastUpdated().toISOString(), url: f.getUrl() });
  }
  return { resultados: out };
}

// =====================================================================
// BLOCO DE CLIENTES — a pasta mestre de instruções e a pasta de cada
// cliente. É daqui que o Claude puxa quem é o cliente antes de montar
// o prompt do trabalho pro GPT.
// =====================================================================

// Subpastas de uma pasta (cada subpasta = um cliente, na pasta mestre).
// d.pasta_id
function pastaSubpastas_(d) {
  var it = DriveApp.getFolderById(d.pasta_id).getFolders(), out = [];
  while (it.hasNext()) {
    var p = it.next();
    out.push({ id: p.getId(), nome: p.getName(), url: p.getUrl(),
               modificado: p.getLastUpdated().toISOString() });
  }
  out.sort(function (a, b) { return a.nome.toLowerCase() < b.nome.toLowerCase() ? -1 : 1; });
  return { pastas: out };
}

// Acha (ou cria) uma subpasta pelo nome — é assim que nasce a pasta de
// um cliente novo, sem ninguém abrir o Drive. d.pai_id, d.nome, d.criar.
function pastaGarantir_(d) {
  var pai = DriveApp.getFolderById(d.pai_id);
  var it = pai.getFoldersByName(d.nome), p = null;
  if (it.hasNext()) p = it.next();
  else if (d.criar !== false) p = pai.createFolder(d.nome);
  if (!p) return { achou: false };
  return { achou: true, id: p.getId(), nome: p.getName(), url: p.getUrl() };
}

// Lista documentos legíveis de uma pasta (opcionalmente entrando nas
// subpastas, 1 nível). d.pasta_id, d.desde_iso, d.recursivo, d.limite.
function legivel_(mime) {
  // markdown, txt e qualquer texto contam: a ficha do cliente costuma ser .md
  return mime === MimeType.GOOGLE_DOCS || mime === MimeType.PLAIN_TEXT ||
         mime === MimeType.PDF || mime === MimeType.GOOGLE_SHEETS ||
         String(mime).indexOf('text/') === 0;
}

function docsListar_(d) {
  var desde = d.desde_iso ? new Date(d.desde_iso) : null;
  var lim = d.limite || 60, out = [];
  function pegar(pasta, prefixo) {
    var it = pasta.getFiles();
    while (it.hasNext() && out.length < lim) {
      var f = it.next();
      if (!legivel_(f.getMimeType())) continue;
      var mod = f.getLastUpdated();
      if (desde && mod < desde) continue;
      out.push({ id: f.getId(), titulo: f.getName(), caminho: prefixo + f.getName(),
                 modificado: mod.toISOString(), tipo: f.getMimeType(), url: f.getUrl() });
    }
  }
  var raiz = DriveApp.getFolderById(d.pasta_id);
  pegar(raiz, '');
  if (d.recursivo) {
    var ps = raiz.getFolders();
    while (ps.hasNext() && out.length < lim) {
      var p = ps.next();
      pegar(p, p.getName() + '/');
      var ps2 = p.getFolders();                       // mais um nível, e para
      while (ps2.hasNext() && out.length < lim) {
        var p2 = ps2.next(); pegar(p2, p.getName() + '/' + p2.getName() + '/');
      }
    }
  }
  out.sort(function (a, b) { return a.modificado < b.modificado ? 1 : -1; });
  return { arquivos: out };
}

// =====================================================================
// APROVAÇÕES NO DRIVE  (v49)
//
// As artes de aprovação moravam no Storage do Supabase e comeram 1,39 GB
// de 1,44 GB do plano. Aqui elas passam a morar no Drive da agência, que
// tem espaço de sobra, numa árvore fixa:
//
//     APROVAÇÕES /  <CLIENTE> /  <lote>  /  arquivos
//
// A migração roda DENTRO do Apps Script: ele baixa do Supabase e grava
// no Drive sem passar byte nenhum pelo HostGator nem pelo navegador.
// =====================================================================

/** A raiz das aprovações, uma só pra casa inteira. */
function aprovRaiz_() {
  var base = prop_('RAIZ_ID') ? DriveApp.getFolderById(prop_('RAIZ_ID')) : DriveApp.getRootFolder();
  return pastaFilha_(base, 'APROVAÇÕES');
}

/** Nome de pasta sem barra, sem os caracteres que o Drive engasga. */
function limpoNome_(s) {
  return String(s || '').replace(/[\/\\:*?"<>|]/g, '-').replace(/\s+/g, ' ').trim().slice(0, 80) || 'SEM NOME';
}

/** APROVAÇÕES / CLIENTE / LOTE — cria o que faltar, adota o que existir. */
function aprovPasta_(d) {
  var cliente = pastaFilha_(aprovRaiz_(), limpoNome_(d.cliente || 'SEM CLIENTE'));
  if (!d.set_id) return { pasta_id: cliente.getId(), url: cliente.getUrl(), nivel: 'cliente' };
  var lote = pastaFilha_(cliente, limpoNome_(d.set_id));
  return { pasta_id: lote.getId(), url: lote.getUrl(), cliente_id: cliente.getId(), nivel: 'lote' };
}

/** Deixa o arquivo visível por link — é o que permite <img src> na tela
 *  de aprovação do cliente, que não tem conta Google. Mesmo grau de
 *  exposição do bucket público que a gente já usava no Supabase. */
function aprovAbrir_(arq) {
  try { arq.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW); } catch (e) {}
  return arq;
}

/** Os três endereços de uma imagem no Drive. */
function aprovUrls_(id) {
  return {
    drive_id: id,
    url:      'https://drive.google.com/thumbnail?id=' + id + '&sz=w1800',
    thumb:    'https://drive.google.com/thumbnail?id=' + id + '&sz=w600',
    original: 'https://drive.google.com/file/d/' + id + '/view'
  };
}

/** Sobe UM arquivo que veio em base64 (upload novo, pelo app). */
function aprovSubir_(d) {
  if (!d.dados) throw new Error('Veio sem o arquivo.');
  var pasta = DriveApp.getFolderById(aprovPasta_(d).pasta_id);
  var blob = Utilities.newBlob(Utilities.base64Decode(d.dados), d.mime || 'application/octet-stream',
                               limpoNome_(d.nome || 'arquivo'));
  var arq = aprovAbrir_(pasta.createFile(blob));
  var u = aprovUrls_(arq.getId());
  u.nome = arq.getName(); u.bytes = arq.getSize();
  return u;
}

/** O coração da migração: pega uma LEVA de links do Supabase e traz cada
 *  um pro Drive. Nada passa pelo seu servidor — o Google busca direto.
 *  Devolve item por item, com o erro de cada um quando houver: migração
 *  que falha em silêncio é migração que some com o trabalho da casa. */
function aprovPuxar_(d) {
  var lista = d.arquivos || [];
  if (!lista.length) throw new Error('Nada pra puxar.');
  var inicio = new Date().getTime();
  var feitos = [], pastas = {};

  for (var i = 0; i < lista.length; i++) {
    var a = lista[i];
    // O Apps Script morre em 6 minutos. Em vez de ser morto no meio de um
    // arquivo, a gente para sozinho aos 4 e devolve o que já deu certo.
    if (new Date().getTime() - inicio > 240000) {
      return { migrados: feitos, parou_em: i, faltam: lista.length - i, motivo: 'tempo' };
    }
    try {
      if (!a.url || !/^https?:\/\//i.test(a.url)) throw new Error('url inválida');
      var chave = (a.cliente || '') + '|' + (a.set_id || '');
      if (!pastas[chave]) pastas[chave] = aprovPasta_({ cliente: a.cliente, set_id: a.set_id }).pasta_id;

      var resp = UrlFetchApp.fetch(a.url, { muteHttpExceptions: true, followRedirects: true });
      if (resp.getResponseCode() !== 200) throw new Error('o Supabase respondeu ' + resp.getResponseCode());
      var blob = resp.getBlob();
      var bytes = blob.getBytes().length;
      if (!bytes) throw new Error('arquivo veio vazio');
      blob.setName(limpoNome_(a.nome || ('arte-' + (i + 1))));

      var arq = aprovAbrir_(DriveApp.getFolderById(pastas[chave]).createFile(blob));
      var u = aprovUrls_(arq.getId());
      u.ref = a.ref;                 // o que o PHP usa pra achar a linha de volta
      u.url_velha = a.url;
      u.bytes = bytes;
      feitos.push(u);
    } catch (err) {
      feitos.push({ ref: a.ref, url_velha: a.url, erro: String(err && err.message || err) });
    }
  }
  return { migrados: feitos, parou_em: lista.length, faltam: 0 };
}

/** Confere se um arquivo existe mesmo no Drive e tem tamanho. É o que o
 *  PHP pergunta ANTES de apagar qualquer coisa do Supabase. */
function aprovConferir_(d) {
  var ids = d.ids || [], out = {};
  for (var i = 0; i < ids.length; i++) {
    try {
      var f = DriveApp.getFileById(ids[i]);
      out[ids[i]] = { existe: !f.isTrashed(), bytes: f.getSize() };
    } catch (e) { out[ids[i]] = { existe: false, bytes: 0 }; }
  }
  return { conferidos: out };
}

// Serve só pra liberar as permissões (Drive, Docs...): rode UMA vez pelo editor (Executar → autorizar).
// Funções que terminam em _ são privadas e não aparecem na lista de execução; esta aparece.
function autorizar() {
  DriveApp.getRootFolder();
  DocumentApp.getActiveDocument;      // só para o Google reconhecer o uso do serviço Docs
  Logger.log('Autorizado: ' + Session.getEffectiveUser().getEmail());
}
