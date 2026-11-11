<?php
/* BIOSPHERE · estrutura de conteúdo (alimenta o site e o painel). Tipos: text, area, img, video, link */
$AV = 'Uso ilustrativo. Consulte os materiais oficiais do empreendimento.';
function F($label, $type = 'text', $def = '') { return ['label' => $label, 'type' => $type, 'def' => $def]; }
/* bloco editorial: tag, título, texto, imagem, legenda, crédito */
function BL($tag, $tit, $txt, $img, $leg = '', $cred = '') {
  return ['tag' => F('Etiqueta', 'text', $tag), 'titulo' => F('Título', 'area', $tit), 'texto' => F('Texto (parágrafo em branco separa)', 'area', $txt), 'img' => F('Imagem', 'img', $img), 'legenda' => F('Legenda', 'text', $leg), 'credito' => F('Crédito da imagem', 'text', $cred)];
}
function DUO($tag, $tit, $txt, $i1, $l1, $c1, $i2, $l2, $c2) {
  return ['tag' => F('Etiqueta', 'text', $tag), 'titulo' => F('Título', 'area', $tit), 'texto' => F('Texto de apoio', 'area', $txt),
    'img' => F('Imagem 1', 'img', $i1), 'legenda' => F('Legenda 1', 'text', $l1), 'credito' => F('Crédito 1', 'text', $c1),
    'img2' => F('Imagem 2', 'img', $i2), 'legenda2' => F('Legenda 2', 'text', $l2), 'credito2' => F('Crédito 2', 'text', $c2)];
}
$CE = 'Imagem da campanha BIOSPHERE ESSENCE BY C. REYMOND.';
$CC = 'Imagem da campanha BIOSPHERE COCOON MILAGRES.';
$CV = 'Imagem da campanha VISTAS JOANÓPOLIS - BIOSPHERE ESTILO DE MORAR BY C. REYMOND.';

/* tipo de cada bloco: split (texto + imagem), wide (imagem larga + título), duo (duas imagens), dark (split escuro) */
$BLOCOS = [
  'h_conceito' => 'split', 'h_esferas' => 'wide',
  'c_assinatura' => 'split', 'c_natureza' => 'wide', 'c_movimento' => 'duo', 'c_tempo' => 'split', 'c_pessoas' => 'split', 'c_tecnologia' => 'dark',
  'e_rotina' => 'split', 'e_ritmo' => 'duo',
  'k_caminho' => 'duo', 'k_paisagem' => 'wide', 'k_lugar' => 'split', 'k_movimento' => 'duo', 'k_essencial' => 'split',
  'v_terra' => 'wide', 'v_esporte' => 'duo', 'v_represa' => 'duo', 'v_cuidado' => 'wide', 'v_encontros' => 'split', 'v_tempo' => 'split', 'v_habitat' => 'wide',
];

$S = [];

$S['global'] = ['titulo' => 'Dados gerais do site', 'campos' => [
  'logo' => F('Logotipo (marrom, fundo claro)', 'img', 'assets/logo.png'),
  'logo_branco' => F('Logotipo (branco, fundo escuro)', 'img', 'assets/logo-branco.png'),
  'simbolo' => F('Símbolo (favicon)', 'img', 'assets/simbolo.png'),
  'instagram' => F('Instagram da marca (@)', 'text', '@biosphere.by.caua.reymond'),
  'instagram_novos' => F('Instagram para novos empreendimentos (@)', 'text', '@marcusarauj0'),
  'email' => F('E-mail que recebe os contatos do formulário', 'text', 'contato@biospherebycreymond.com.br'),
  'aviso' => F('Aviso sob as imagens', 'text', $AV),
  'rodape' => F('Texto legal do rodapé', 'area', "Este site apresenta a marca Biosphere e referências de seu portfólio. As imagens pertencem às campanhas dos empreendimentos identificados e têm uso ilustrativo.\n\nCaracterísticas, áreas, equipamentos, serviços e condições dependem do projeto e dos documentos oficiais de cada empreendimento. Imagens de campanha não representam, necessariamente, instalações ou condições de entrega.\n\nCada empreendimento é desenvolvido e comercializado pelas respectivas empresas responsáveis. Consulte os materiais oficiais e a equipe comercial de cada projeto."),
  'lema' => F('Lema', 'text', 'Mais que morar. Viver por inteiro.'),
]];

$S['home'] = ['titulo' => 'Home · abertura', 'campos' => [
  'hero_titulo' => F('Título', 'area', "Mais que morar.\nViver por inteiro."),
  'hero_sub' => F('Subtítulo', 'area', 'Uma assinatura imobiliária inspirada em um modo mais pleno de viver.'),
  'hero_tag' => F('Etiqueta', 'text', 'Biosphere by C. Reymond'),
  'hero_img' => F('Imagem de abertura', 'img', 'img/hero-agua.jpg'),
  'hero_video' => F('Vídeo de abertura (opcional: envie MP4 ou cole link do YouTube/Vimeo)', 'video', ''),
  'hero_credito' => F('Crédito da imagem', 'text', $CC),
  'hero_cta' => F('Texto do botão', 'text', 'Conheça o conceito'),
  'hero_cta_link' => F('Link do botão', 'link', 'conceito'),
]];
$S['h_conceito'] = ['titulo' => 'Home · O conceito', 'campos' => BL('O conceito Biosphere', 'Um modo de viver transformado em lugares', "A visão de vida de Cauã Reymond encontra a curadoria de Marcus Araujo e o conhecimento das incorporadoras parceiras.\n\nDesse encontro nascem empreendimentos conectados ao bem-estar e à vida real.", 'img/conceito-caua.jpg', 'Cauã Reymond na campanha do Biosphere Essence.', $CE)];
$S['h_esferas'] = ['titulo' => 'Home · Todas as esferas', 'campos' => BL('', 'A vida acontece em todas as esferas', 'Corpo e mente. Movimento e pausa. Cidade e natureza. Espaço para viver cada dimensão.', 'img/esferas.jpg', '', $CC)];

$pil = [['Bem-estar', 'Cuidado com o corpo e a mente.'], ['Tempo e praticidade', 'Mais liberdade no cotidiano.'], ['Natureza', 'Paisagem presente na experiência.'], ['Convivência', 'Espaço para relações e encontros.'], ['Movimento', 'O esporte integrado à rotina.'], ['Tecnologia', 'Soluções para necessidades reais.'], ['Sustentabilidade', 'Uso consciente dos recursos.'], ['Dados e futuro', 'Pesquisa orientando as decisões.']];
$c = ['pil_tag' => F('Etiqueta', 'text', 'O que orienta cada projeto'), 'pil_titulo' => F('Título', 'area', "Uma essência.\nMuitas formas de viver.")];
foreach ($pil as $i => $p) { $n = $i + 1; $c["pil{$n}_t"] = F("Pilar $n · nome", 'text', $p[0]); $c["pil{$n}_d"] = F("Pilar $n · frase", 'text', $p[1]); }
$S['pilares'] = ['titulo' => 'Os 8 pilares (Home e Conceito)', 'campos' => $c];

$S['h_portfolio'] = ['titulo' => 'Home · chamadas (portfólio, pessoas e incorporadoras)', 'campos' => [
  'port_tag' => F('Portfólio · etiqueta', 'text', 'Portfólio em destaque'), 'port_titulo' => F('Portfólio · título', 'text', 'Três paisagens. Uma essência.'),
  'pess_tag' => F('Pessoas · etiqueta', 'text', 'Quem faz a Biosphere'), 'pess_titulo' => F('Pessoas · título', 'text', 'Visão, dados e conexão.'),
  'cta_tag' => F('Chamada final · etiqueta', 'text', 'Novos lugares. A mesma essência.'),
  'cta_titulo' => F('Chamada final · título', 'area', 'Leve a Biosphere para o seu próximo empreendimento.'),
  'cta_texto' => F('Chamada final · texto', 'area', "Uma cidade. Uma oportunidade.\nUma nova forma de viver."),
  'cta_botao' => F('Chamada final · botão', 'text', 'Fale com a Biosphere'),
]];

$S['conceito'] = ['titulo' => 'Conceito · abertura', 'campos' => [
  'tag' => F('Etiqueta', 'text', 'O conceito Biosphere'), 'titulo' => F('Título', 'area', 'Um modo de viver transformado em lugares'),
  'texto' => F('Texto', 'area', "A visão de vida de Cauã Reymond encontra a curadoria de Marcus Araujo e o conhecimento das incorporadoras parceiras.\n\nDesse encontro nascem empreendimentos conectados ao bem-estar e à vida real."),
  'img' => F('Imagem', 'img', 'img/conceito-caua.jpg'), 'credito' => F('Crédito', 'text', $CE), 'legenda' => F('Legenda', 'text', 'Cauã Reymond na campanha do Biosphere Essence.')]];
$S['c_assinatura'] = ['titulo' => 'Conceito · Uma assinatura da vida real', 'campos' => BL('Cauã Reymond / Visão e autoria', 'Uma assinatura que nasce da vida real', "Família, esporte, saúde e natureza fazem parte do repertório que orienta a Biosphere.\n\nCauã participa da construção do conceito e dos momentos estratégicos dos projetos, em diálogo com os parceiros.", 'img/caua-visao.jpg', 'Escolhas pessoais que se transformam em uma visão de morar.', 'Imagem da campanha VISTAS JOANÓPOLIS - BIOSPHERE ESTILO DE MORAR BY C. REYMOND.')];
$S['c_natureza'] = ['titulo' => 'Conceito · Natureza', 'campos' => BL('', 'A natureza participa do cotidiano', 'Luz, ventilação, paisagem e áreas verdes orientam a relação entre as pessoas e os espaços.', 'img/natureza.jpg', '', $CC)];
$S['c_movimento'] = ['titulo' => 'Conceito · Movimento', 'campos' => DUO('Movimento', 'O corpo também habita', 'Estruturas esportivas e espaços de saúde ganham sentido quando criam oportunidades reais de uso.', 'img/movimento-1.jpg', 'Movimento que acompanha a vida.', $CE, 'img/movimento-2.jpg', 'O cuidado com o corpo faz parte do lugar.', $CV)];
$S['c_tempo'] = ['titulo' => 'Conceito · Tempo e praticidade', 'campos' => BL('Tempo e praticidade', "Luxo também\né ter tempo", "Localizações conectadas, serviços próximos e soluções que simplificam o dia.\n\nMorar bem pode devolver horas para a família, o descanso e tudo o que faz a vida valer a pena.", 'img/tempo.jpg', 'O valor de um momento sem pressa.', $CV)];
$S['c_pessoas'] = ['titulo' => 'Conceito · Pessoas e famílias', 'campos' => BL('Pessoas, famílias e encontros', "Espaço para\na vida como ela é", "A casa acompanha diferentes famílias, novas rotinas e outras formas de trabalhar.\n\nConvivência e privacidade precisam coexistir. Os animais também fazem parte desse cotidiano.", 'img/pessoas.jpg', 'Ambientes que acolhem diferentes maneiras de viver.', $CV)];
$S['c_tecnologia'] = ['titulo' => 'Conceito · Tecnologia com propósito', 'campos' => BL('Tecnologia com propósito', "Tudo funciona melhor quando faz sentido", "Segurança, comodidade e uso consciente dos recursos orientam a escolha das soluções.\n\nA curadoria avalia o que cada contexto pede para transformar conceito em experiência.", 'img/tecnologia.jpg', 'A experiência de morar começa nas decisões de projeto.', 'Imagem da campanha BIOSPHERE ESSENCE BY C. REYMOND.')];

$pas = [['Leitura do contexto', 'Cidade, paisagem, público e vocação do empreendimento.'], ['Curadoria e pesquisa', 'Comportamento, mudanças demográficas e futuro do morar.'], ['Construção do conceito', 'Pilares da marca interpretados para cada mercado.'], ['Experiência de morar', 'Ambientes, serviços e usos pensados em conjunto.'], ['Participação autoral', 'Coerência entre a visão da marca e cada projeto.'], ['Realização local', 'Arquitetura, engenharia e construção pelas parceiras.'], ['Comunicação e valor', 'Uma narrativa consistente para o empreendimento.']];
$c = ['tag' => F('Etiqueta', 'text', 'Como nasce uma assinatura Biosphere'), 'titulo' => F('Título', 'area', 'Cada lugar começa com boas perguntas'), 'texto' => F('Texto', 'area', 'A visão de Cauã encontra a inteligência imobiliária e o conhecimento local da incorporadora.'),
  'titulo2' => F('Segundo título', 'text', 'Uma construção conjunta'), 'texto2' => F('Segundo texto', 'area', "A essência é compartilhada.\nA interpretação pertence a cada lugar.")];
foreach ($pas as $i => $p) { $n = $i + 1; $c["p{$n}_t"] = F("Passo $n · título", 'text', $p[0]); $c["p{$n}_d"] = F("Passo $n · texto", 'text', $p[1]); }
$S['c_processo'] = ['titulo' => 'Conceito · Como nasce uma assinatura (7 passos)', 'campos' => $c];

/* pessoas */
function PES($tag, $nome, $sub, $txt, $img, $cred) { return ['tag' => F('Etiqueta', 'text', $tag), 'nome' => F('Nome', 'text', $nome), 'sub' => F('Frase de destaque', 'area', $sub), 'texto' => F('Texto', 'area', $txt), 'img' => F('Retrato', 'img', $img), 'credito' => F('Crédito', 'text', $cred)]; }
$S['p_caua'] = ['titulo' => 'Quem faz · Cauã Reymond', 'campos' => PES('Ator, criador, produtor e empresário', 'Cauã Reymond', 'Uma trajetória que atravessa moda, televisão, cinema e criação de negócios.', 'A experiência internacional e a atuação como produtor ampliam seu olhar sobre estética, comportamento e qualidade de vida. Na Biosphere, esse repertório se traduz em uma proposta autoral para o morar.', 'img/caua.jpg', 'Retrato institucional de Cauã Reymond. Acervo do site Biosphere.')];
$S['p_marcus'] = ['titulo' => 'Quem faz · Marcus Araujo', 'campos' => PES('Curadoria oficial e inteligência imobiliária', 'Marcus Araujo', 'Dados também constroem lugares.', 'Estatístico, pesquisador do comportamento do consumidor imobiliário e fundador da Datastore. Sua curadoria conecta a visão da marca às mudanças nas famílias, no trabalho e na forma de viver as cidades.', 'img/marcus.jpg', 'Retrato institucional de Marcus Araujo. Acervo do site Biosphere.')];
$S['p_luciano'] = ['titulo' => 'Quem faz · Luciano Ribeiro', 'campos' => PES('Estratégia e desenvolvimento de negócios', 'Luciano Ribeiro', 'A conexão entre a marca, o mercado e seus parceiros.', 'Com experiência em jornalismo, mídia, comunicação e gestão de marcas, participa das relações institucionais e comerciais que apoiam o desenvolvimento da Biosphere e de seus projetos.', 'img/luciano.jpg', 'Retrato institucional de Luciano Ribeiro. Acervo do site Biosphere.')];
$c = ['tag' => F('Etiqueta', 'text', 'Na mídia'), 'titulo' => F('Título', 'text', 'E o que está acontecendo nesse momento?')];
$mid = [['Jogada de Risco', 'Cauã cria e protagoniza um recorde: a série original de ficção de maior alcance do Globoplay nos primeiros sete dias.'], ['Tudo é Justo | All\'s Fair', 'Cauã ganha os holofotes internacionais ao integrar a segunda temporada da série de Ryan Murphy, com Kim Kardashian e Glenn Close.'], ['Avenida Brasil 2', 'Um fenômeno ganha novo capítulo. Cauã retorna ao elenco da continuação de Avenida Brasil, prevista para janeiro de 2027.']];
foreach ($mid as $i => $m) { $n = $i + 1; $c["m{$n}_t"] = F("Destaque $n · título", 'text', $m[0]); $c["m{$n}_d"] = F("Destaque $n · texto", 'area', $m[1]); $c["m{$n}_img"] = F("Destaque $n · imagem (opcional)", 'img', ''); $c["m{$n}_link"] = F("Destaque $n · link da matéria (opcional)", 'link', ''); }
$S['p_midia'] = ['titulo' => 'Quem faz · Na mídia', 'campos' => $c];

/* projetos */
function PROJ($nome, $tit, $local, $real, $txt, $card, $img, $cred) { return ['nome' => F('Nome do projeto', 'text', $nome), 'titulo' => F('Título', 'area', $tit), 'local' => F('Local', 'text', $local), 'real' => F('Realização', 'text', $real), 'texto' => F('Texto', 'area', $txt), 'card' => F('Imagem do cartão', 'img', $card), 'img' => F('Imagem de abertura', 'img', $img), 'credito' => F('Crédito', 'text', $cred)]; }
$S['j_essence'] = ['titulo' => 'Projeto · Biosphere Essence', 'campos' => PROJ('Biosphere Essence by C. Reymond', "Viver a cidade.\nPreservar a essência.", 'Salvador, Bahia', 'Alia Empreendimentos', 'Mobilidade, lazer e praticidade aproximam a proposta Biosphere do ritmo urbano.', 'img/card-essence.jpg', 'img/essence-1.jpg', $CE)];
$S['j_cocoon'] = ['titulo' => 'Projeto · Biosphere Cocoon Milagres', 'campos' => PROJ('Biosphere Cocoon Milagres', "Desconectar para se reconectar", 'São Miguel dos Milagres, Alagoas', 'Mascon Incorporadora e parceiros', 'Natureza, hospitalidade e bem-estar se encontram em uma experiência de home resort.', 'img/card-cocoon.jpg', 'img/cocoon-1.jpg', $CC)];
$S['j_vistas'] = ['titulo' => 'Projeto · Vistas Joanópolis', 'campos' => PROJ('Vistas Joanópolis · Biosphere Estilo de Morar by C. Reymond', 'A vida pede paisagem', 'Joanópolis, São Paulo', '', 'Uma interpretação da essência Biosphere em contato com a represa Jaguari e a paisagem do interior paulista.', 'img/card-vistas.jpg', 'img/vistas-1.jpg', $CV)];
$S['e_rotina'] = ['titulo' => 'Essence · A rotina urbana', 'campos' => BL('Biosphere Essence by C. Reymond', "A rotina urbana com mais possibilidades", "Na região do Imbuí, o empreendimento aproxima os moradores do metrô, de serviços e das conexões da cidade.\n\nEspaços para trabalhar, treinar, conviver e cuidar dos animais complementam a experiência de morar.", 'img/essence-2.jpg', 'Cena da campanha oficial. Uso conceitual e ilustrativo.', $CE)];
$S['e_ritmo'] = ['titulo' => 'Essence · Liberdade para viver seu ritmo', 'campos' => DUO('Biosphere Essence by C. Reymond', 'Liberdade para viver seu ritmo', 'A vida ativa e a busca por equilíbrio fazem parte do conceito que orienta o empreendimento.', 'img/essence-3.jpg', 'Tempo para o que faz bem.', $CE, 'img/essence-4.jpg', 'O conforto e a praticidade inspiram a assinatura.', $CE)];
$S['k_caminho'] = ['titulo' => 'Cocoon · O caminho', 'campos' => DUO('Biosphere Cocoon Milagres', 'O caminho já faz parte da experiência', '', 'img/cocoon-2.jpg', 'Paisagem que convida a diminuir o ritmo.', $CC, 'img/cocoon-3.jpg', 'A liberdade como ponto de partida.', $CC)];
$S['k_paisagem'] = ['titulo' => 'Cocoon · A paisagem muda o tempo', 'campos' => BL('', 'A paisagem muda o tempo', 'O mar e o contato com a natureza inspiram uma experiência de reconexão e bem-estar.', 'img/cocoon-4.jpg', '', $CC)];
$S['k_lugar'] = ['titulo' => 'Cocoon · Um lugar para ficar', 'campos' => BL('Biosphere Cocoon Milagres', 'Um lugar para ficar. E querer voltar.', "Diferentes tipologias e uma estrutura de lazer e serviços para uso pessoal e locação.\n\nRestaurante, piscina, academia, espaços esportivos e cinema ao ar livre compõem a proposta apresentada para o projeto.", 'img/cocoon-5.jpg', 'Hospitalidade, paisagem e tempo de qualidade.', $CC)];
$S['k_movimento'] = ['titulo' => 'Cocoon · Movimento em estado natural', 'campos' => DUO('Biosphere Cocoon Milagres', 'Movimento em estado natural', 'O conceito Biosphere acompanha os diferentes ritmos de quem busca viver o litoral.', 'img/cocoon-6.jpg', 'A praia como inspiração para uma vida ativa.', $CC, 'img/cocoon-7.jpg', 'Corpo, água e liberdade.', $CC)];
$S['k_essencial'] = ['titulo' => 'Cocoon · O essencial fica mais perto', 'campos' => BL('Biosphere Cocoon Milagres', "O essencial fica mais perto", "Descansar, estar presente e aproveitar o lugar.\n\nA experiência de home resort conecta a vocação do destino ao lifestyle que deu origem à Biosphere.", 'img/cocoon-8.jpg', 'Cauã Reymond na campanha oficial do Cocoon Milagres.', $CC)];
$S['v_terra'] = ['titulo' => 'Vistas · Entre a terra e a água', 'campos' => BL('', 'Entre a terra e a água', 'Lotes de 1.000 a 3.000 m² e paisagismo de Alexandre Furcolin integram a proposta do residencial.', 'img/vistas-2.jpg', '', $CV)];
$S['v_esporte'] = ['titulo' => 'Vistas · Esporte, equilíbrio e horizonte', 'campos' => DUO('Vistas Joanópolis', 'Esporte, equilíbrio e horizonte', 'O site do projeto apresenta quadras de tênis e beach tennis como parte das experiências esportivas.', 'img/vistas-3.jpg', 'O esporte faz parte do estilo de morar.', $CV, 'img/vistas-4.jpg', 'Movimento em conexão com a água.', $CV)];
$S['v_represa'] = ['titulo' => 'Vistas · Relação com a represa', 'campos' => DUO('Vistas Joanópolis', 'Uma relação mais próxima com a represa', 'Clube náutico, marina e píer flutuante integram a proposta de acesso à represa.', 'img/vistas-5.jpg', 'A água amplia as possibilidades de viver o lugar.', $CV, 'img/vistas-6.jpg', 'A paisagem acompanha cada experiência.', $CV)];
$S['v_cuidado'] = ['titulo' => 'Vistas · O cuidado também tem lugar', 'campos' => BL('', 'O cuidado também tem lugar', 'Academia, spa e sauna integram a proposta de wellness. Tempo para recuperar o corpo e desacelerar.', 'img/vistas-7.jpg', '', $CV)];
$S['v_encontros'] = ['titulo' => 'Vistas · Encontros que ficam', 'campos' => BL('Vistas Joanópolis', "Encontros\nque ficam", "Espaço gourmet, quiosques, fireplace, área kids e sala de jogos fazem parte da proposta de convivência.\n\nA experiência do lugar valoriza o tempo compartilhado.", 'img/vistas-8.jpg', 'Uma paisagem para compartilhar.', $CV)];
$S['v_tempo'] = ['titulo' => 'Vistas · Tempo para simplesmente estar', 'campos' => BL('Vistas Joanópolis', "Tempo para simplesmente estar", "Um refúgio que aproxima a vida da paisagem.\n\nA assinatura Biosphere Estilo de Morar by C. Reymond orienta uma experiência ligada ao movimento, à contemplação e ao bem-estar.", 'img/vistas-9.jpg', 'Presença, silêncio e uma outra relação com o tempo.', $CV)];
$S['v_habitat'] = ['titulo' => 'Vistas · Mais que moradia, seu habitat', 'campos' => BL('', 'Mais que moradia, seu habitat', 'Cada projeto interpreta a visão Biosphere a partir da identidade e da vocação de seu lugar.', 'img/vistas-10.jpg', '', $CV)];

$c = ['tag' => F('Etiqueta', 'text', 'Outras expressões da mesma visão'), 'titulo' => F('Título', 'area', "Biosphere\npelo Brasil"), 'sub' => F('Frase de destaque', 'text', 'Cidades diferentes pedem respostas diferentes.'), 'texto' => F('Texto', 'area', 'A marca interpreta a realidade de cada região, preservando uma visão comum de qualidade de vida.')];
$br = [['Biosphere Conceito', 'Fortaleza, Ceará', 'Realização: Mota Machado. Vida urbana, bem-estar e sustentabilidade na Aldeota.'], ['Biosphere Horizon', 'Luís Eduardo Magalhães, Bahia', 'Realização: Alia Empreendimentos. Funcionalidade e conveniência no oeste baiano.'], ['Biosphere Verano', 'Itapirubá, Laguna, Santa Catarina', 'Uma proposta ligada ao litoral, à natureza e a um ritmo de vida mais tranquilo.']];
foreach ($br as $i => $b) { $n = $i + 1; $c["b{$n}_t"] = F("Projeto $n · nome", 'text', $b[0]); $c["b{$n}_l"] = F("Projeto $n · local", 'text', $b[1]); $c["b{$n}_d"] = F("Projeto $n · texto", 'area', $b[2]); }
$S['j_brasil'] = ['titulo' => 'Projetos · Biosphere pelo Brasil', 'campos' => $c];

$S['incorporadoras'] = ['titulo' => 'Incorporadoras', 'campos' => [
  'tag' => F('Etiqueta', 'text', 'Para incorporadoras e novos projetos'), 'titulo' => F('Título', 'area', "Uma visão reconhecível.\nUma interpretação própria."),
  'i1_t' => F('Pilar 1 · nome', 'text', 'Identidade'), 'i1_d' => F('Pilar 1 · texto', 'area', 'Uma assinatura autoral que organiza a proposta de valor do empreendimento.'),
  'i2_t' => F('Pilar 2 · nome', 'text', 'Inteligência'), 'i2_d' => F('Pilar 2 · texto', 'area', 'Curadoria para conectar público, cidade, comportamento e experiência.'),
  'i3_t' => F('Pilar 3 · nome', 'text', 'Realização'), 'i3_d' => F('Pilar 3 · texto', 'area', 'O conhecimento regional e a capacidade de execução de cada incorporadora.'),
  'form_titulo' => F('Formulário · título', 'text', 'Conte sobre o seu próximo empreendimento'),
]];
$S['contato'] = ['titulo' => 'Contato', 'campos' => [
  'titulo' => F('Título', 'area', 'Vamos conversar'), 'texto' => F('Texto', 'area', 'Incorporadoras, imprensa e interessados nos empreendimentos: escreva por aqui.'),
  'ok' => F('Mensagem de sucesso', 'text', 'Mensagem enviada. Retornaremos em breve.'),
]];
$S['blog'] = ['titulo' => 'Blog · página de abertura', 'campos' => [
  'tag' => F('Etiqueta', 'text', 'Blog'), 'titulo' => F('Título', 'area', 'Ideias para morar melhor'), 'texto' => F('Texto', 'area', 'Tendências, comportamento e o futuro do morar, pela visão Biosphere.'),
]];

$SCHEMA = $S;

/* SEO padrão por página */
$SEO_PAGINAS = [
  'home' => ['Home', 'Biosphere by C. Reymond · Mais que morar. Viver por inteiro.', 'Biosphere by C. Reymond: uma assinatura imobiliária inspirada em um modo mais pleno de viver. Conheça o conceito, os projetos e a curadoria.'],
  'conceito' => ['Conceito', 'O conceito Biosphere · Biosphere by C. Reymond', 'Bem-estar, tempo, natureza, convivência, movimento, tecnologia, sustentabilidade e dados: os pilares que orientam cada projeto Biosphere.'],
  'quem-faz' => ['Quem faz', 'Quem faz a Biosphere · Cauã Reymond, Marcus Araujo e Luciano Ribeiro', 'Conheça a visão autoral de Cauã Reymond, a curadoria de Marcus Araujo e a estratégia de Luciano Ribeiro por trás da Biosphere.'],
  'projetos' => ['Projetos', 'Projetos Biosphere · Salvador, Milagres, Joanópolis e mais', 'Biosphere Essence, Cocoon Milagres, Vistas Joanópolis, Conceito, Horizon e Verano: três paisagens, uma essência.'],
  'incorporadoras' => ['Incorporadoras', 'Leve a Biosphere para o seu empreendimento · Para incorporadoras', 'Identidade, inteligência e realização: como a Biosphere by C. Reymond constrói assinaturas para novos empreendimentos.'],
  'blog' => ['Blog', 'Blog Biosphere · Ideias para morar melhor', 'Artigos sobre comportamento, tendências e o futuro do morar, pela visão Biosphere by C. Reymond.'],
  'contato' => ['Contato', 'Contato · Biosphere by C. Reymond', 'Fale com a Biosphere: incorporadoras, imprensa e interessados nos empreendimentos.'],
  'projeto-essence' => ['Projeto Essence', 'Biosphere Essence by C. Reymond · Salvador, Bahia', 'Viver a cidade e preservar a essência: Biosphere Essence no Imbuí, em Salvador, com realização da Alia Empreendimentos.'],
  'projeto-cocoon' => ['Projeto Cocoon', 'Biosphere Cocoon Milagres · São Miguel dos Milagres, Alagoas', 'Home resort em São Miguel dos Milagres: natureza, hospitalidade e bem-estar. Realização Mascon Incorporadora e parceiros.'],
  'projeto-vistas' => ['Projeto Vistas', 'Vistas Joanópolis · Biosphere Estilo de Morar by C. Reymond', 'Residencial com lotes de 1.000 a 3.000 m² em contato com a represa Jaguari, em Joanópolis (SP).'],
];
