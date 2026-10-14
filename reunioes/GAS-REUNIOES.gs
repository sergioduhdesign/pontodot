// =====================================================================
// SD-Ponte — BLOCO DE REUNIÕES (v1)
// Cole este bloco INTEIRO no final do SD-Ponte.gs (Apps Script).
// Depois, lá em cima no doPost, acrescente estas 4 ações no mapa `acoes`:
//
//   var acoes = { ping: ping_, preparar: preparar_, status: escreverStatus_, ler_status: lerStatus_,
//                 listar: listar_, baixar: baixar_, gravar: gravar_,
//                 reunioes_listar: reunioesListar_, reuniao_ler: reuniaoLer_,
//                 reuniao_salvar_ata: reuniaoSalvarAta_, reunioes_buscar: reunioesBuscar_ };
//
// E publique uma NOVA VERSÃO da implantação (Implantar → Gerenciar
// implantações → lápis → Versão: Nova versão → Implantar).
// =====================================================================

// Lista os docs da pasta do Read.ai (as notas ficam na raiz dela).
// d.pasta_id = id da pasta; d.desde_iso = só arquivos modificados depois disso (opcional).
function reunioesListar_(d) {
  var pasta = DriveApp.getFolderById(d.pasta_id);
  var desde = d.desde_iso ? new Date(d.desde_iso) : null;
  var out = [], it = pasta.getFiles();
  while (it.hasNext()) {
    var f = it.next();
    if (f.getMimeType() !== MimeType.GOOGLE_DOCS && f.getMimeType() !== MimeType.PLAIN_TEXT) continue;
    var mod = f.getLastUpdated();
    if (desde && mod < desde) continue;
    out.push({ id: f.getId(), titulo: f.getName(), modificado: mod.toISOString(), tipo: f.getMimeType() });
  }
  out.sort(function (a, b) { return a.modificado < b.modificado ? -1 : 1; });
  return { arquivos: out };
}

// Lê o texto de uma nota (Google Doc ou .txt). d.doc_id.
function reuniaoLer_(d) {
  var f = DriveApp.getFileById(d.doc_id);
  var texto;
  if (f.getMimeType() === MimeType.GOOGLE_DOCS) {
    texto = DocumentApp.openById(d.doc_id).getBody().getText();
  } else {
    texto = f.getBlob().getDataAsString('UTF-8');
  }
  // Notas gigantes: a IA não precisa de mais que ~60k caracteres.
  if (texto.length > 60000) texto = texto.slice(0, 60000) + '\n\n[... transcrição cortada aqui — use o resumo e os action items acima ...]';
  return { titulo: f.getName(), texto: texto, url: f.getUrl() };
}

// Salva a ata gerada como Google Doc na pasta de atas.
// d.pasta_atas_id, d.titulo, d.texto (markdown simples vira texto).
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
