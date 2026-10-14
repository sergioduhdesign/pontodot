// =====================================================================
// SD Workflow — BRIEFING PRA IA (o prompt de produção)
//
// Monta o briefing completo que vai pra IA (ChatGPT, Claude, Gemini…):
// contexto do job + especificação técnica do TIPO de entrega + regras
// universais de produção via IA + regras do mercado imobiliário +
// rodada de correção + checklist de entrega.
//
// É o MESMO texto que: (1) o "Copiar pedido pra IA" copia e (2) fica
// gravado na pasta da tarefa como 00_BRIEFING_IA.txt.
//
// TIPOS_ENTREGA é a base de conhecimento — editar aqui muda todos os
// briefings daquele tipo.
// =====================================================================
(function(){
'use strict';

const TIPOS = {
  feed: {
    nome: 'Post de feed (Instagram/Facebook)',
    formato: [
      'Formato: 1080 × 1350 px (4:5, vertical). Se o pedido disser quadrado: 1080 × 1080 px.',
      'Margem de segurança: 64 px em todos os lados — nenhum texto ou logo fora dela.',
      'Uma ideia por peça: 1 título forte (até ~8 palavras) + no máximo 1 linha de apoio. O resto vai na legenda.',
      'Texto na arte ocupa no máximo ~20% da área. Tamanho mínimo de texto: 28 px.',
      'Logo do cliente/empreendimento sempre presente, no canto definido pelo design system, sem distorcer.',
    ],
    entrega: 'Uma imagem por post (PNG ou JPG) OU um .pptx/Google Slides com 1 slide = 1 post, no tamanho certo.'
  },
  carrossel: {
    nome: 'Carrossel (Instagram)',
    formato: [
      'Cada card: 1080 × 1350 px (4:5). De 3 a 10 cards.',
      'Card 1 = gancho (para o scroll). Último card = chamada para ação (CTA) + logo.',
      'Continuidade visual entre os cards (mesmo grid, mesma margem, mesma família tipográfica). Elementos podem "atravessar" de um card pro outro.',
      'Numere os arquivos na ordem: 01, 02, 03… dentro de UMA pasta com o nome do carrossel.',
      'Margem de segurança: 64 px; nada importante nas bordas laterais (corte do app).',
    ],
    entrega: 'Uma pasta por carrossel com os cards 01, 02, 03… OU um .pptx com os cards em ordem (nome do arquivo com "carrossel").'
  },
  stories: {
    nome: 'Stories',
    formato: [
      'Formato: 1080 × 1920 px (9:16).',
      'Zona segura: deixe livres 250 px no topo e 340 px na base (interface do app). Texto e logo dentro da faixa central.',
      'Leitura em 3 segundos: frase curta, letra grande (mín. 44 px), alto contraste.',
      'Sequência de stories do mesmo dia = uma pasta (stories-<dia>) com 01, 02, 03…',
    ],
    entrega: 'Uma imagem por story, agrupadas por pasta do dia, OU um .pptx 9:16 com "stories" no nome.'
  },
  reels: {
    nome: 'Reels / vídeo curto',
    formato: [
      'Formato 1080 × 1920 px (9:16), 15 a 45 s. Capa separada em 1080 × 1920 (com área central 1080 × 1350 legível no grid).',
      'Gancho nos 2 primeiros segundos. Legenda queimada (texto na tela) em todas as falas.',
      'Se não for possível editar o vídeo, entregue: capa + roteiro cena a cena (tempo | imagem | texto na tela | locução) num Excel ou Doc.',
    ],
    entrega: 'MP4 (H.264) e/ou capa + roteiro.'
  },
  press: {
    nome: 'Press / anúncio de jornal ou revista',
    formato: [
      'Respeite o FORMATO DO VEÍCULO informado no pedido (em mm ou módulos). Se não vier, pergunte em DUVIDAS e use proporção do formato mais provável, identificado no nome do arquivo.',
      'Revista: inclua sangria de 5 mm e mantenha texto/logo 10 mm pra dentro do corte. Jornal: sem sangria, respeite a área útil.',
      'Hierarquia de anúncio: chamada (título) → imagem principal → argumentos (até 3) → assinatura (logo do empreendimento + incorporadora + contato) → rodapé legal.',
      'Texto legal obrigatório no rodapé, legível (mín. 6 pt no impresso): veja REGRAS DO MERCADO IMOBILIÁRIO.',
      'Qualidade de impressão: imagens em alta resolução (mín. 300 dpi no tamanho final). Nunca use imagem pequena ampliada.',
    ],
    entrega: '.pptx/Google Slides no formato do veículo (1 slide = 1 anúncio) e, se possível, PDF em alta.'
  },
  ooh: {
    nome: 'Mídia exterior (outdoor, front/backlight, empena, tapume, busdoor)',
    formato: [
      'Use a proporção do suporte (ex.: outdoor 9 × 3 m = 3:1; frontlight conforme medida informada).',
      'Leitura em 3 segundos a distância: no máximo 7 palavras na chamada, letra enorme, 1 imagem, logo grande.',
      'Contraste máximo. Nada de texto pequeno além do legal obrigatório.',
    ],
    entrega: '.pptx/Google Slides na proporção do suporte + PDF.'
  },
  folheto: {
    nome: 'Folheto / folder / flyer',
    formato: [
      'Formato informado no pedido (A4, A5, 15 × 21 cm…). Frente e verso = 2 slides. Folder com dobra: indique as dobras e respeite os painéis.',
      'Sangria de 3 mm e margem de segurança de 5 mm.',
      'Frente vende (chamada + imagem), verso explica (diferenciais, planta, mapa, contato, legal).',
    ],
    entrega: '.pptx/Google Slides no formato + PDF.'
  },
  apresentacao: {
    nome: 'Apresentação / book / lâmina de vendas',
    formato: [
      'Formato 16:9 (1920 × 1080) salvo pedido diferente.',
      'Um assunto por slide; título + no máximo 3 blocos de informação. Imagem real do empreendimento sempre que houver.',
      'Mantenha a sequência e a estrutura do arquivo-base, se ele existir na ENTRADA.',
    ],
    entrega: '.pptx/Google Slides.'
  },
  email: {
    nome: 'E-mail marketing',
    formato: [
      'Largura 600 px; altura livre. Topo (logo) → hero → texto curto → CTA em botão → rodapé legal.',
      'Texto importante em texto, não só dentro de imagem.',
    ],
    entrega: 'Imagem(ns) 600 px de largura + texto do e-mail num Doc/Excel.'
  },
  adaptacao: {
    nome: 'Adaptação / desdobramento em massa (troca de logo, assinatura, data, formato…)',
    formato: [
      'Altere SOMENTE o que o pedido manda. Todo o resto fica idêntico ao original: posição, tamanho, cor, fonte, imagem.',
      'Mesmo NOME de arquivo do original (é assim que o SD substitui cada peça no lugar).',
      'Confira peça por peça — em lote é comum uma passar sem a troca. Liste em DUVIDAS qualquer peça que não pôde ser adaptada.',
    ],
    entrega: 'O mesmo conjunto de arquivos, com os mesmos nomes e a mesma estrutura de pastas.'
  },
  papelaria: {
    nome: 'Papelaria / peças institucionais',
    formato: [
      'Use as medidas informadas (cartão 9 × 5 cm, papel timbrado A4, assinatura de e-mail 600 × 150 px…).',
      'Rigor total com o manual de marca: área de proteção do logo, cores oficiais, tipografia.',
    ],
    entrega: '.pptx/Google Slides nas medidas + PDF.'
  },
  generico: {
    nome: 'Peça de comunicação',
    formato: [
      'Use as medidas informadas no pedido. Se não houver, pergunte em DUVIDAS e use o formato mais provável.',
    ],
    entrega: '.pptx/Google Slides, imagem ou PDF.'
  }
};

// Palavras que indicam o tipo (ordem importa: o mais específico primeiro)
const DETECTA = [
  ['adaptacao',    /(adapta|desdobra|troca(r)? (de )?(logo|assinatura|data)|em massa|replicar)/i],
  ['press',        /\b(press|imprensa|an[uú]ncio|jornal|revista|p[aá]gina dupla|rodap[eé] de jornal)\b/i],
  ['ooh',          /\b(outdoor|front ?light|backlight|empena|tapume|busdoor|mupi|ooh|painel|totem|placa)\b/i],
  ['folheto',      /\b(folheto|folder|flyer|panfleto|l[aâ]mina impressa)\b/i],
  ['apresentacao', /\b(apresenta[cç][aã]o|book|deck|l[aâ]mina de vendas|pitch)\b/i],
  ['email',        /\b(e-?mail ?(mkt|marketing)?|newsletter)\b/i],
  ['papelaria',    /\b(papelaria|cart[aã]o de visita|timbrado|assinatura de e-?mail)\b/i],
  ['reels',        /\breels?\b|\bv[ií]deo\b/i],
  ['stories',      /\bstor(y|ies)\b/i],
  ['carrossel',    /\b(carross|carousel)/i],
  ['feed',         /\b(post|feed|social|instagram|insta|conte[uú]do)\b/i],
];

const SOCIAL_FORTE = /\b(posts?|feed|carross\w*|carousel|stor(y|ies)|reels?|instagram|insta|redes( sociais)?|social)\b/i;
function detectarTipo(texto){
  const t = String(texto || '');
  // "Revista SD — site e redes" é conteúdo de rede, não anúncio de revista
  if (SOCIAL_FORTE.test(t)) for (const k of ['reels', 'stories', 'carrossel', 'feed']) {
    const re = (DETECTA.find(x => x[0] === k) || [])[1];
    if (re && re.test(t)) return k;
  }
  if (SOCIAL_FORTE.test(t)) return 'feed';
  for (const [k, re] of DETECTA) if (re.test(t)) return k;
  return 'generico';
}

const REGRAS_IA = [
  'IMAGENS: use SOMENTE imagens reais que estão nos arquivos e pastas da tarefa (ENTRADA, referências, design system). NÃO gere, não invente e não "melhore" imagem com IA. Não use banco de imagem sem autorização. Se faltar imagem, deixe um espaço marcado "[IMAGEM PENDENTE: descrição]" e registre em DUVIDAS.',
  'LOGOS: use o arquivo original do logo (PNG/SVG da pasta). Nunca redesenhe, recorte, recolora ou distorça. Respeite a área de proteção.',
  'PROPORÇÃO: nunca estique imagem. Para preencher, recorte (crop) mantendo a proporção e o assunto principal visível.',
  'FONTES: use as fontes do design system. Se não estiverem disponíveis no Google (Slides/Docs), use a equivalente do Google Fonts mais próxima (ex.: Helvetica/Arial → Inter ou Roboto; Futura → Jost; Gotham → Montserrat; Garamond → EB Garamond; Didot/Bodoni → Bodoni Moda; Avenir → Nunito Sans) e anote a substituição em DUVIDAS. Nunca deixe fonte faltando (vira fonte padrão e quebra o layout).',
  'CAIXAS DE TEXTO (pptx/Slides): crie caixas mais largas e altas do que o texto precisa, com quebra automática ligada e "não ajustar automaticamente o tamanho da fonte". O texto nunca pode sair da caixa, ser cortado, nem quebrar palavra no meio. Uma caixa por bloco de texto (título, apoio, legal) — não junte tudo numa caixa só.',
  'GRID: mantenha margens, alinhamentos e espaçamentos consistentes em todas as peças do lote. Se houver arquivo-base/master na pasta, DUPLIQUE ele e troque o conteúdo — não recrie do zero.',
  'CORES: use apenas as cores do design system (anote os HEX usados). Garanta contraste de leitura (texto claro em fundo escuro ou vice-versa; sobre foto, use véu/degradê).',
  'TEXTO: português do Brasil, revisado (ortografia, acentos, crase). Não invente dado nenhum: preço, metragem, número de dormitórios, datas, prêmios, endereço, telefone — só o que estiver no briefing ou nos arquivos. Na dúvida, "[CONFIRMAR: …]" e registre em DUVIDAS.',
  'CONSISTÊNCIA DO LOTE: todas as peças do mesmo pedido seguem o mesmo padrão visual. Faça a primeira peça, valide contra o design system, e replique o padrão.',
  'ARQUIVOS: não apague os arquivos originais da ENTRADA. Se for trabalhar num master/arquivo-base, faça uma CÓPIA dele na SAIDA e edite a cópia. Salve o resultado na pasta SAIDA. Nome de arquivo sem acento e sem espaço (use hífen), descritivo: <data>-<tema>-<formato> (ex.: 2026-10-05-lancamento-feed.png).',
  'DÚVIDAS: o que faltar ou estiver ambíguo NÃO trava a entrega — produza com a melhor hipótese e liste tudo num arquivo DUVIDAS.txt na SAIDA (uma linha por dúvida: peça → pergunta → o que você assumiu).',
];

const REGRAS_IMOBILIARIO = [
  'Material de empreendimento imobiliário precisa de rodapé legal. Use o texto legal fornecido pelo cliente (procure na pasta/briefings). Se não houver, deixe o espaço do rodapé com "[TEXTO LEGAL: incorporação registrada sob R-__ da matrícula __ do __ Cartório de Registro de Imóveis de __ · CRECI __ · Imagens meramente ilustrativas]" e registre em DUVIDAS.',
  'Imagens 3D/perspectivas: sinalize "Perspectiva ilustrativa" (ou "Imagem ilustrativa") na própria imagem, discreto, quando for render.',
  'Não prometa valorização, rentabilidade ou retorno garantido. Não use "o melhor", "único", "garantido" sem comprovação. Preço e condição só se vierem do cliente, sempre com "condições sujeitas a alteração" no legal.',
  'Respeite a hierarquia de marcas: empreendimento em destaque; incorporadora/construtora/imobiliária na assinatura, conforme o design system do cliente.',
];

// ---------------------------------------------------------------------
// o: { tarefa:{id,action,project,obs,prazo}, cliente, produto, tipo,
//      instrucoes, drive:{folder_url,referencia_url,rodada}, correcao,
//      briefings:[{file_name,file_url}] }
// ---------------------------------------------------------------------
function montar(o){
  const t = o.tarefa || {};
  const tipoK = TIPOS[o.tipo] ? o.tipo : detectarTipo([t.action, t.project, t.obs, o.instrucoes].join(' '));
  const tipo = TIPOS[tipoK];
  const d = o.drive || {};
  const imob = o.imobiliario !== false;
  const L = [];
  const sec = (titulo) => { L.push('', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', titulo, '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'); };

  L.push(`BRIEFING DE PRODUÇÃO — ${t.id || ''} · ${t.action || ''}`);
  L.push(`Pontodot · gerado em ${new Date().toLocaleString('pt-BR')}`);

  sec('1. SEU PAPEL');
  L.push('Você é o designer/redator de produção da agência Pontodot. Seu trabalho é produzir as peças abaixo com qualidade de agência, prontas pra aprovação do cliente, SEM precisar de idas e vindas. Siga este briefing à risca; quando algo faltar, use a melhor hipótese e registre em DUVIDAS.txt.');

  sec('2. O JOB');
  if (o.cliente) L.push('Cliente: ' + o.cliente);
  if (o.produto) L.push('Produto / empreendimento: ' + o.produto);
  if (t.project) L.push('Projeto: ' + t.project);
  L.push('Tarefa: ' + (t.id || '') + ' — ' + (t.action || ''));
  L.push('Tipo de entrega: ' + tipo.nome);
  if (t.prazo) L.push('Prazo: ' + t.prazo);
  if (d.rodada) L.push('Rodada: ' + d.rodada);

  sec('3. O PEDIDO');
  const pedido = [t.obs, o.instrucoes].filter(Boolean).join('\n\n').trim();
  L.push(pedido || '(Sem descrição detalhada na tarefa. Use os arquivos da ENTRADA e as referências como base e registre as dúvidas.)');

  sec('4. ONDE ESTÁ TUDO');
  if (d.folder_url) L.push('Pasta da tarefa (Google Drive): ' + d.folder_url);
  L.push('• ENTRADA — arquivos de origem, masters, textos e, nas correções, o correcoes.xlsx. Leia TUDO antes de começar.');
  L.push('• SAIDA — onde você salva o que produzir.');
  L.push('• SD_CONTROLE — planilha da tarefa; a célula B2 (STATUS) é onde você escreve TERMINEI no fim.');
  if (d.referencia_url) L.push('• Referências / design system: ' + d.referencia_url + ' — leia o manual/guia de marca ANTES de produzir: logos, cores (HEX), tipografia, grid, exemplos aprovados.');
  (o.briefings || []).forEach(b => L.push('• Briefing do cliente: ' + b.file_name + ' — ' + b.file_url));

  sec('5. ESPECIFICAÇÃO — ' + tipo.nome.toUpperCase());
  tipo.formato.forEach(x => L.push('• ' + x));
  L.push('• Como entregar: ' + tipo.entrega);

  sec('6. REGRAS DE PRODUÇÃO (valem sempre)');
  REGRAS_IA.forEach((x, i) => L.push((i + 1) + '. ' + x));

  if (imob) {
    sec('7. REGRAS DO MERCADO IMOBILIÁRIO');
    REGRAS_IMOBILIARIO.forEach((x, i) => L.push((i + 1) + '. ' + x));
  }

  if (o.correcao) {
    sec((imob ? '8' : '7') + '. RODADA DE CORREÇÃO');
    L.push('Esta é uma rodada de CORREÇÃO. Abra ENTRADA/correcoes.xlsx:');
    L.push('• Corrija SÓ as linhas com algo em CORRECAO_CRIATIVO (arte) ou CORRECAO_COPY (texto) ou OBS_CLIENTE. STATUS = APROVADO não se mexe.');
    L.push('• A peça corrigida mantém EXATAMENTE o mesmo nome de arquivo da coluna ARTES (a extensão pode mudar). Carrossel: entregue só o card corrigido, com o mesmo nome e na mesma pasta.');
    L.push('• Correção de texto: reescreva a coluna LEGENDA (e HASHTAGS) e salve o Excel na SAIDA. Se o texto está dentro da arte, corrija também a arte.');
    L.push('• Não mude nada que não foi pedido. Não "aproveite" pra melhorar outras coisas.');
  }

  sec('CHECKLIST ANTES DE ENTREGAR');
  [
    'Formato e medidas certos em todas as peças.',
    'Nenhum texto cortado, saindo da caixa ou fora da margem de segurança.',
    'Logo original, sem distorção; cores e fontes do design system (substituições anotadas).',
    'Só imagens reais da pasta; nada gerado por IA; nada esticado.',
    'Ortografia revisada; nenhum dado inventado; pendências marcadas com [CONFIRMAR].',
    imob ? 'Rodapé legal presente (ou marcado como pendente).' : null,
    'Arquivos salvos na SAIDA com nomes certos; DUVIDAS.txt criado se houve dúvida.',
    'Por último: escreva TERMINEI na célula B2 da planilha SD_CONTROLE.',
  ].filter(Boolean).forEach(x => L.push('☐ ' + x));

  return L.join('\n');
}

window.BriefingIA = { TIPOS, detectarTipo, montar };
})();
