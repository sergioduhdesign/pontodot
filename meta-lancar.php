<?php
// =====================================================================
// Pontodot — SUBIR CAMPANHA NA META (do documento da Sumi até o play)
//
//   POST {acao:"opcoes",    conta}                       -> o que a conta tem: páginas, Instagram, pixels, formulários de lead,
//                                                           públicos salvos, pagamento ativo, orçamento mínimo
//   POST {acao:"plano",     conta, client_id, product_id, texto}  -> Sumi transforma o documento FINAL (com a mão humana) num plano
//                                                           estruturado: campanha, conjuntos (público, orçamento, horários), anúncios
//   POST {acao:"buscar",    tipo:"interesse"|"lugar", q, conta}   -> busca da Meta (interesses, cidades/regiões) para os chips
//   POST {acao:"estimar",   conta, publico}              -> tamanho estimado do público
//   POST {acao:"criativos", client_id}                   -> peças APROVADAS do cliente (imagem/vídeo) para virar anúncio
//   POST {acao:"subir",     conta, client_id, product_id, plano, doc_id} -> cria TUDO na Meta, PAUSADO
//   POST {acao:"play"|"pausar", id}                      -> (admin) liga/desliga a campanha; o play confere pagamento antes
//   POST {acao:"lista",     client_id}                   -> campanhas que a plataforma subiu
//
// Regras da casa: nada vai ao ar sem um humano clicar em PLAY; o cartão e a cobrança ficam na Meta, com o cliente.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/meta-ads-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe sobe campanha.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? '');

function sai($d) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
function uuidOk($v) { return preg_match('/^[0-9a-f-]{36}$/i', (string)$v) ? (string)$v : null; }
function contaOk($v) { $v = (string)$v; return preg_match('/^act_\d{3,25}$/', $v) ? $v : null; }
function tokenConta($act) {
    $q = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($act) . '&select=conectada_por,client_id,nome,moeda');
    $c = $q['body'][0] ?? null; if (!$c) sd_fail('Essa conta de anúncio não está na plataforma.', 404);
    $tok = pd_token_para($c['conectada_por'] ?? null); if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta em Redes sociais.', 409);
    return [$tok, $c];
}
function metaOuFalha($r, $oque) { if (($r['code'] ?? 0) !== 200 || isset($r['body']['error'])) sd_fail($oque . ': ' . pd_meta_erro($r), 502); return $r['body']; }

$OBJETIVOS = ['OUTCOME_LEADS' => 'Leads', 'OUTCOME_TRAFFIC' => 'Tráfego', 'OUTCOME_ENGAGEMENT' => 'Engajamento', 'OUTCOME_AWARENESS' => 'Reconhecimento', 'OUTCOME_SALES' => 'Vendas'];
$CTAS = ['LEARN_MORE', 'SIGN_UP', 'WHATSAPP_MESSAGE', 'GET_QUOTE', 'CONTACT_US', 'APPLY_NOW', 'BOOK_NOW', 'SHOP_NOW', 'SUBSCRIBE'];

// ---------------------------------------------------------------- o que a conta tem
if ($acao === 'opcoes') {
    $act = contaOk($b['conta'] ?? '') ?: sd_fail('Conta inválida.', 400); [$tok, $c] = tokenConta($act);
    $a = metaOuFalha(pd_meta('GET', "/$act", ['fields' => 'name,currency,account_status,funding_source_details,min_daily_budget,timezone_name,business{name}'], $tok), 'Conta');
    $pags = pd_meta('GET', '/me/accounts', ['fields' => 'id,name,instagram_business_account{id,username}', 'limit' => 100], $tok);
    $paginas = [];
    foreach ((array)($pags['body']['data'] ?? []) as $p) if (is_array($p) && !empty($p['id'])) $paginas[] = ['id' => $p['id'], 'nome' => $p['name'] ?? '', 'ig_id' => $p['instagram_business_account']['id'] ?? null, 'ig' => $p['instagram_business_account']['username'] ?? null];
    // páginas conectadas em Redes sociais deste cliente (prioridade)
    $sc = $c['client_id'] ? pd_supa('/rest/v1/social_accounts?client_id=eq.' . $c['client_id'] . '&select=external_account_id,account_name,platform') : ['body' => []];
    $doCliente = array_values(array_filter(array_map(fn($x) => is_array($x) ? ['id' => (string)$x['external_account_id'], 'nome' => (string)($x['account_name'] ?? ''), 'plataforma' => (string)($x['platform'] ?? '')] : null, (array)($sc['body'] ?? []))));
    $px = pd_meta('GET', "/$act/adspixels", ['fields' => 'id,name,last_fired_time', 'limit' => 20], $tok);
    $ca = pd_meta('GET', "/$act/customaudiences", ['fields' => 'id,name,subtype,approximate_count_lower_bound', 'limit' => 50], $tok);
    $forms = [];
    foreach (array_slice($paginas, 0, 6) as $p) { $f = pd_meta('GET', '/' . $p['id'] . '/leadgen_forms', ['fields' => 'id,name,status', 'limit' => 50], $tok);
        foreach ((array)($f['body']['data'] ?? []) as $x) if (is_array($x) && ($x['status'] ?? '') === 'ACTIVE') $forms[] = ['id' => $x['id'], 'nome' => $x['name'] ?? '', 'pagina' => $p['id']]; }
    $fs = $a['funding_source_details'] ?? null;
    sai(['conta' => ['id' => $act, 'nome' => $a['name'] ?? $c['nome'], 'moeda' => $a['currency'] ?? $c['moeda'], 'ativa' => (int)($a['account_status'] ?? 0) === 1, 'fuso' => $a['timezone_name'] ?? '',
            'pagamento' => $fs ? (string)($fs['display_string'] ?? 'cadastrado') : null, 'orcamento_minimo' => isset($a['min_daily_budget']) ? ((int)$a['min_daily_budget']) / 100 : null, 'empresa' => $a['business']['name'] ?? null],
        'paginas' => $paginas, 'paginas_cliente' => $doCliente, 'pixels' => array_values(array_filter((array)($px['body']['data'] ?? []), 'is_array')),
        'publicos' => array_values(array_filter((array)($ca['body']['data'] ?? []), 'is_array')), 'formularios' => $forms, 'objetivos' => $OBJETIVOS, 'ctas' => $CTAS]);
}

// ---------------------------------------------------------------- buscas da Meta (chips)
if ($acao === 'buscar') {
    $act = contaOk($b['conta'] ?? '') ?: sd_fail('Conta inválida.', 400); [$tok] = tokenConta($act);
    $q = mb_substr(trim((string)($b['q'] ?? '')), 0, 80); if ($q === '') sai(['itens' => []]);
    if (($b['tipo'] ?? '') === 'lugar') {
        $r = pd_meta('GET', '/search', ['type' => 'adgeolocation', 'q' => $q, 'location_types' => json_encode(['city', 'region', 'country', 'zip']), 'country_code' => 'BR', 'limit' => 12], $tok);
        $it = array_map(fn($x) => ['key' => $x['key'], 'nome' => $x['name'] . (isset($x['region']) ? ' · ' . $x['region'] : ''), 'tipo' => $x['type']], array_filter((array)($r['body']['data'] ?? []), 'is_array'));
    } else {
        $r = pd_meta('GET', '/search', ['type' => 'adinterest', 'q' => $q, 'locale' => 'pt_BR', 'limit' => 12], $tok);
        $it = array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['name'], 'alcance' => $x['audience_size_upper_bound'] ?? null, 'caminho' => implode(' › ', (array)($x['path'] ?? []))], array_filter((array)($r['body']['data'] ?? []), 'is_array'));
    }
    sai(['itens' => array_values($it)]);
}

// ---------------------------------------------------------------- público → targeting da Meta
function targetingDe($pub) {
    $geo = [];
    foreach (['cidades' => 'cities', 'regioes' => 'regions', 'paises' => 'countries', 'ceps' => 'zips'] as $k => $m) {
        $l = array_values(array_filter((array)($pub[$k] ?? []), fn($x) => is_array($x) ? !empty($x['key']) : $x !== ''));
        if (!$l) continue;
        if ($m === 'countries') $geo[$m] = array_map(fn($x) => is_array($x) ? $x['key'] : $x, $l);
        elseif ($m === 'cities') $geo[$m] = array_map(fn($x) => ['key' => is_array($x) ? $x['key'] : $x, 'radius' => (int)($pub['raio_km'] ?? 25), 'distance_unit' => 'kilometer'], $l);
        else $geo[$m] = array_map(fn($x) => ['key' => is_array($x) ? $x['key'] : $x], $l);
    }
    if (!$geo) $geo = ['countries' => ['BR']];
    $t = ['geo_locations' => $geo, 'age_min' => max(18, min(65, (int)($pub['idade_min'] ?? 25))), 'age_max' => max(18, min(65, (int)($pub['idade_max'] ?? 60)))];
    if (!empty($pub['generos']) && is_array($pub['generos']) && count($pub['generos']) === 1) $t['genders'] = [$pub['generos'][0] === 'f' ? 2 : 1];
    $ints = array_values(array_filter((array)($pub['interesses'] ?? []), fn($x) => is_array($x) && !empty($x['id'])));
    if ($ints) $t['flexible_spec'] = [['interests' => array_map(fn($x) => ['id' => (string)$x['id'], 'name' => (string)($x['nome'] ?? '')], array_slice($ints, 0, 25))]];
    $cas = array_values(array_filter((array)($pub['publicos_salvos'] ?? []), fn($x) => preg_match('/^\d+$/', (string)(is_array($x) ? ($x['id'] ?? '') : $x))));
    if ($cas) $t['custom_audiences'] = array_map(fn($x) => ['id' => (string)(is_array($x) ? $x['id'] : $x)], $cas);
    $exc = array_values(array_filter((array)($pub['excluir_publicos'] ?? []), fn($x) => preg_match('/^\d+$/', (string)(is_array($x) ? ($x['id'] ?? '') : $x))));
    if ($exc) $t['excluded_custom_audiences'] = array_map(fn($x) => ['id' => (string)(is_array($x) ? $x['id'] : $x)], $exc);
    if (!empty($pub['posicionamentos_manuais']) && is_array($pub['posicionamentos_manuais'])) {
        $t['publisher_platforms'] = array_values(array_intersect(['facebook', 'instagram', 'messenger', 'audience_network'], $pub['posicionamentos_manuais']));
        if (!$t['publisher_platforms']) unset($t['publisher_platforms']);
    }
    if (!empty($pub['advantage_publico'])) $t['targeting_automation'] = ['advantage_audience' => 1];
    return $t;
}
function horariosDe($hs) {   // [{dias:[1,2,3,4,5], de:"08:00", ate:"21:00"}] → adset_schedule da Meta (minutos; 0 = domingo)
    $out = [];
    foreach ((array)$hs as $h) { if (!is_array($h)) continue; [$h1, $m1] = array_map('intval', explode(':', ($h['de'] ?? '00:00') . ':0')); [$h2, $m2] = array_map('intval', explode(':', ($h['ate'] ?? '23:59') . ':0'));
        $dias = array_values(array_filter(array_map('intval', (array)($h['dias'] ?? [0, 1, 2, 3, 4, 5, 6])), fn($d) => $d >= 0 && $d <= 6)); if (!$dias) continue;
        $out[] = ['start_minute' => $h1 * 60 + $m1, 'end_minute' => min(1439, $h2 * 60 + $m2), 'days' => $dias, 'timezone_type' => 'ADVERTISER']; }
    return $out;
}

if ($acao === 'estimar') {
    $act = contaOk($b['conta'] ?? '') ?: sd_fail('Conta inválida.', 400); [$tok] = tokenConta($act);
    $obj = (string)($b['objetivo'] ?? 'OUTCOME_LEADS'); $goal = $obj === 'OUTCOME_LEADS' ? 'LEAD_GENERATION' : ($obj === 'OUTCOME_TRAFFIC' ? 'LINK_CLICKS' : 'REACH');
    $r = pd_meta('GET', "/$act/delivery_estimate", ['targeting_spec' => json_encode(targetingDe((array)($b['publico'] ?? []))), 'optimization_goal' => $goal], $tok);
    $d = $r['body']['data'][0] ?? null;
    sai(['estimativa' => $d ? ['de' => $d['estimate_mau_lower_bound'] ?? null, 'ate' => $d['estimate_mau_upper_bound'] ?? null, 'ready' => $d['estimate_ready'] ?? null] : null, 'erro' => $d ? null : pd_meta_erro($r)]);
}

// ---------------------------------------------------------------- Sumi: documento final → plano estruturado
if ($acao === 'plano') {
    @set_time_limit(150);
    $doc = mb_substr(trim((string)($b['texto'] ?? '')), 0, 16000); if ($doc === '') sd_fail('Sem documento para virar plano.', 400);
    $cli = mb_substr((string)($b['cliente'] ?? ''), 0, 120); $prod = mb_substr((string)($b['produto'] ?? ''), 0, 120); $moeda = (string)($b['moeda'] ?? 'BRL');
    $sys = 'Você é Sumi Sulo, a IA de Campanhas da casa (agência de branding do mercado imobiliário brasileiro). Você ACREDITA que é a Sumi. '
         . 'Recebe o DOCUMENTO FINAL de estratégia (já revisado por um humano) e devolve um PLANO DE CAMPANHA pronto para a Meta (Facebook/Instagram Ads), com TODA a inteligência que a Meta permite: objetivo, orçamento e ritmo, datas, até 4 conjuntos de anúncio com públicos distintos (geografia com cidades e raio em km, idade, gênero, interesses nomeados como a Meta nomeia, públicos salvos quando o documento citar, posicionamentos), horários de veiculação por dia da semana, otimização, e os anúncios (título até 40, texto principal até 125 palavras, descrição, CTA). '
         . 'Use só o que está no documento; onde faltar, decida como gestora de tráfego experiente de lançamento imobiliário e marque em "obs". Nunca invente números de resultado. Orçamento em ' . $moeda . ', diário por conjunto. '
         . 'Responda SOMENTE JSON: {"campanha":{"nome":"...","objetivo":"OUTCOME_LEADS|OUTCOME_TRAFFIC|OUTCOME_ENGAGEMENT|OUTCOME_AWARENESS|OUTCOME_SALES","inicio":"AAAA-MM-DD","fim":"AAAA-MM-DD ou vazio","obs":"..."},'
         . '"conjuntos":[{"nome":"...","orcamento_diario":120,"otimizacao":"LEAD_GENERATION|LINK_CLICKS|REACH|LANDING_PAGE_VIEWS|CONVERSATIONS","publico":{"cidades":["Campinas, SP"],"raio_km":25,"regioes":[],"idade_min":28,"idade_max":55,"generos":[],"interesses":["Imóveis","Financiamento imobiliário"],"advantage_publico":false,"posicionamentos_manuais":[]},"horarios":[{"dias":[1,2,3,4,5],"de":"08:00","ate":"22:00"}],"por_que":"1 frase"}],'
         . '"anuncios":[{"nome":"...","titulo":"...","texto":"...","descricao":"...","cta":"SIGN_UP|LEARN_MORE|WHATSAPP_MESSAGE|GET_QUOTE|CONTACT_US","formato":"imagem|video|carrossel","conjunto":0,"direcao_criativo":"o que a peça precisa mostrar"}]}';
    [$txt, $custo, $erro] = pd_ia_campanhas($sys, [['role' => 'user', 'content' => "CLIENTE: $cli\nPRODUTO: $prod\n\nDOCUMENTO FINAL:\n$doc"]], 6000, 0);
    if ($erro) sd_fail($erro, 502);
    $j = null; if (preg_match('/\{.*\}/s', (string)$txt, $mm)) $j = json_decode($mm[0], true);
    if (!is_array($j) || empty($j['campanha'])) sd_fail('A Sumi não devolveu um plano legível. ' . mb_substr((string)$txt, 0, 200), 502);
    if (!isset($OBJETIVOS[$j['campanha']['objetivo'] ?? ''])) $j['campanha']['objetivo'] = 'OUTCOME_LEADS';
    sai(['plano' => $j, 'custo_usd' => $custo]);
}

// ---------------------------------------------------------------- peças aprovadas do cliente
if ($acao === 'criativos') {
    $cid = uuidOk($b['client_id'] ?? '') ?: sd_fail('Cliente inválido.', 400);
    $sets = pd_supa('/rest/v1/approval_sets?client_id=eq.' . $cid . '&select=id,title&order=created_at.desc&limit=30');
    $ids = array_map(fn($s) => $s['id'], array_filter((array)($sets['body'] ?? []), 'is_array')); $out = [];
    if ($ids) { $its = pd_supa('/rest/v1/approval_items?set_id=in.(' . implode(',', $ids) . ')&status=eq.aprovado&select=id,set_id,title,caption,headline,kind,media,version&order=updated_at.desc&limit=120');
        foreach ((array)($its['body'] ?? []) as $it) { if (!is_array($it)) continue; $m = is_array($it['media'] ?? null) ? $it['media'] : json_decode((string)($it['media'] ?? '[]'), true); if (!$m) continue;
            $out[] = ['id' => $it['id'], 'titulo' => (string)($it['title'] ?? ''), 'legenda' => (string)($it['caption'] ?? ''), 'headline' => (string)($it['headline'] ?? ''), 'tipo' => (string)($it['kind'] ?? ''), 'n' => count($m),
                'video' => (bool)array_filter($m, fn($x) => is_array($x) && (strpos((string)($x['mime'] ?? ''), 'video/') === 0 || preg_match('/\.(mp4|mov)$/i', (string)($x['name'] ?? $x['url'] ?? '')))),
                'capa' => (string)(is_array($m[0]) ? ($m[0]['thumb'] ?? $m[0]['url'] ?? '') : $m[0])]; } }
    sai(['pecas' => $out]);
}

// ---------------------------------------------------------------- SUBIR (tudo pausado)
if ($acao === 'subir') {
    @set_time_limit(280);
    require_once __DIR__ . '/social-publish-lib.php';   // pd_midias_publicas: a arte do Drive vira endereço público
    $act = contaOk($b['conta'] ?? '') ?: sd_fail('Conta inválida.', 400); [$tok, $c] = tokenConta($act);
    $cid = uuidOk($b['client_id'] ?? '') ?: ($c['client_id'] ?? null); $plano = (array)($b['plano'] ?? []); $camp = (array)($plano['campanha'] ?? []);
    $pagina = preg_replace('/\D/', '', (string)($b['pagina'] ?? '')); if ($pagina === '') sd_fail('Escolha a Página do Facebook que assina os anúncios.', 400);
    $ig = preg_replace('/\D/', '', (string)($b['instagram'] ?? '')); $pixel = preg_replace('/\D/', '', (string)($b['pixel'] ?? '')); $form = preg_replace('/\D/', '', (string)($b['formulario'] ?? ''));
    $link = trim((string)($b['link'] ?? '')); $zap = preg_replace('/\D/', '', (string)($b['whatsapp'] ?? ''));
    $obj = isset($OBJETIVOS[$camp['objetivo'] ?? '']) ? $camp['objetivo'] : 'OUTCOME_LEADS';
    $conjs = array_values(array_filter((array)($plano['conjuntos'] ?? []), 'is_array')); if (!$conjs) sd_fail('O plano não tem conjuntos de anúncio.', 400);
    $ads = array_values(array_filter((array)($plano['anuncios'] ?? []), 'is_array')); if (!$ads) sd_fail('O plano não tem anúncios.', 400);
    foreach ($ads as $a) if (empty($a['peca_id'])) sd_fail('Todo anúncio precisa de uma peça aprovada. Falta em: ' . ($a['nome'] ?? '?'), 400);
    if ($obj === 'OUTCOME_LEADS' && $form === '' && $zap === '') sd_fail('Campanha de leads precisa de um formulário instantâneo ou de WhatsApp.', 400);
    if ($obj !== 'OUTCOME_LEADS' && $link === '' && $zap === '') sd_fail('Esse objetivo precisa de um link de destino.', 400);

    $post = function ($path, $p, $oque) use ($tok) { $r = pd_meta('POST', $path, $p, $tok, 120); if (empty($r['body']['id'])) throw new Exception($oque . ': ' . pd_meta_erro($r)); return (string)$r['body']['id']; };
    $criados = ['campanha' => null, 'conjuntos' => [], 'anuncios' => []]; $t0 = microtime(true);
    try {
        $nomeC = mb_substr('PD · ' . ($camp['nome'] ?: 'Campanha') . ' · ' . date('d/m/Y'), 0, 200);
        $criados['campanha'] = $post("/$act/campaigns", ['name' => $nomeC, 'objective' => $obj, 'status' => 'PAUSED', 'special_ad_categories' => json_encode(['HOUSING']), 'buying_type' => 'AUCTION', 'is_adset_budget_sharing_enabled' => 'false'], 'Campanha');
        // IMÓVEL = categoria especial HOUSING: a Meta não aceita gênero nem idade fechada (18–65+), nem raio pequeno. Normalizamos sem avisar errado.
        $ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($camp['inicio'] ?? '')) ? $camp['inicio'] . 'T08:00:00-03:00' : null;
        $fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($camp['fim'] ?? '')) ? $camp['fim'] . 'T23:59:00-03:00' : null;
        foreach ($conjs as $i => $cj) {
            $pub = (array)($cj['publico'] ?? []); $pub['generos'] = []; $pub['idade_min'] = 18; $pub['idade_max'] = 65; if (($pub['raio_km'] ?? 25) < 24) $pub['raio_km'] = 24;
            $tg = targetingDe($pub); $goal = in_array($cj['otimizacao'] ?? '', ['LEAD_GENERATION', 'LINK_CLICKS', 'REACH', 'LANDING_PAGE_VIEWS', 'CONVERSATIONS', 'OFFSITE_CONVERSIONS'], true) ? $cj['otimizacao'] : ($obj === 'OUTCOME_LEADS' ? 'LEAD_GENERATION' : 'LINK_CLICKS');
            $dest = $obj === 'OUTCOME_LEADS' ? ($form !== '' ? 'ON_AD' : 'WHATSAPP') : ($zap !== '' ? 'WHATSAPP' : 'WEBSITE');
            if ($dest === 'WHATSAPP' && $goal === 'LEAD_GENERATION') $goal = 'CONVERSATIONS';
            $p = ['name' => mb_substr((string)($cj['nome'] ?: 'Conjunto ' . ($i + 1)), 0, 200), 'campaign_id' => $criados['campanha'], 'status' => 'PAUSED',
                'daily_budget' => (int)round(max(1, (float)($cj['orcamento_diario'] ?? 50)) * 100), 'billing_event' => 'IMPRESSIONS', 'optimization_goal' => $goal, 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting' => json_encode($tg, JSON_UNESCAPED_UNICODE), 'destination_type' => $dest];
            if ($goal === 'LEAD_GENERATION') $p['promoted_object'] = json_encode(['page_id' => $pagina]);
            elseif ($goal === 'CONVERSATIONS' || $dest === 'WHATSAPP') $p['promoted_object'] = json_encode(['page_id' => $pagina, 'whatsapp_phone_number' => '+' . $zap]);
            elseif ($goal === 'OFFSITE_CONVERSIONS' && $pixel !== '') $p['promoted_object'] = json_encode(['pixel_id' => $pixel, 'custom_event_type' => 'LEAD']);
            if ($ini) $p['start_time'] = $ini; if ($fim) $p['end_time'] = $fim;
            $hs = horariosDe($cj['horarios'] ?? []); if ($hs && $fim) { $p['adset_schedule'] = json_encode($hs); $p['pacing_type'] = json_encode(['day_parting']); unset($p['daily_budget']); $dias = max(1, (int)ceil((strtotime($fim) - strtotime($ini ?: 'now')) / 86400)); $p['lifetime_budget'] = (int)round(max(1, (float)($cj['orcamento_diario'] ?? 50)) * 100 * $dias); }
            $criados['conjuntos'][$i] = $post("/$act/adsets", $p, 'Conjunto "' . ($cj['nome'] ?? $i) . '"');
        }
        $pecas = [];
        foreach ($ads as $k => $a) {
            $pid = uuidOk($a['peca_id']); $it = $pecas[$pid] = $pecas[$pid] ?? (pd_supa('/rest/v1/approval_items?id=eq.' . $pid . '&select=id,title,caption,media,status')['body'][0] ?? null);
            if (!$it || ($it['status'] ?? '') !== 'aprovado') throw new Exception('A peça do anúncio "' . ($a['nome'] ?? $k) . '" não está aprovada.');
            $m = is_array($it['media'] ?? null) ? $it['media'] : json_decode((string)($it['media'] ?? '[]'), true); $midias = pd_midias_publicas($m);
            $titulo = mb_substr(trim((string)($a['titulo'] ?? $it['title'] ?? 'Anúncio')), 0, 100); $texto = (string)($a['texto'] ?? $it['caption'] ?? ''); $desc = mb_substr((string)($a['descricao'] ?? ''), 0, 120);
            $ctaT = in_array($a['cta'] ?? '', $CTAS, true) ? $a['cta'] : ($obj === 'OUTCOME_LEADS' ? 'SIGN_UP' : 'LEARN_MORE');
            if ($zap !== '' && ($form === '' || $ctaT === 'WHATSAPP_MESSAGE')) { $ctaT = 'WHATSAPP_MESSAGE'; $cta = ['type' => $ctaT, 'value' => ['app_destination' => 'WHATSAPP']]; $lk = 'https://wa.me/' . $zap; }
            elseif ($obj === 'OUTCOME_LEADS' && $form !== '') { $cta = ['type' => $ctaT, 'value' => ['lead_gen_form_id' => $form]]; $lk = 'https://www.facebook.com/' . $pagina; }
            else { $cta = ['type' => $ctaT, 'value' => ['link' => $link]]; $lk = $link; }
            if (count($midias) === 1 && $midias[0]['video']) {
                $vid = $post("/$act/advideos", ['file_url' => $midias[0]['url'], 'name' => $titulo], 'Vídeo');
                $thumb = null; for ($w = 0; $w < 36; $w++) { $st = pd_meta('GET', "/$vid", ['fields' => 'status,picture'], $tok); $vs = $st['body']['status']['video_status'] ?? ''; if ($vs === 'ready') { $thumb = $st['body']['picture'] ?? null; break; } if ($vs === 'error') throw new Exception('A Meta recusou o vídeo de "' . $titulo . '".'); sleep(5); }
                $spec = ['page_id' => $pagina, 'video_data' => array_filter(['video_id' => $vid, 'message' => $texto, 'title' => $titulo, 'link_description' => $desc, 'image_url' => $thumb, 'call_to_action' => $cta])];
            } elseif (count($midias) > 1) {
                $spec = ['page_id' => $pagina, 'link_data' => ['message' => $texto, 'link' => $lk, 'child_attachments' => array_map(fn($x) => ['link' => $lk, 'picture' => $x['url'], 'name' => $titulo, 'description' => $desc, 'call_to_action' => $cta], array_slice($midias, 0, 10)), 'multi_share_optimized' => true]];
            } else {
                $spec = ['page_id' => $pagina, 'link_data' => array_filter(['message' => $texto, 'link' => $lk, 'picture' => $midias[0]['url'], 'name' => $titulo, 'description' => $desc, 'call_to_action' => $cta])];
            }
            if ($ig !== '') $spec['instagram_actor_id'] = $ig;
            $cre = $post("/$act/adcreatives", ['name' => mb_substr('PD · ' . $titulo, 0, 200), 'object_story_spec' => json_encode($spec, JSON_UNESCAPED_UNICODE)], 'Criativo "' . $titulo . '"');
            $cj = (int)($a['conjunto'] ?? 0); $adsetId = $criados['conjuntos'][$cj] ?? reset($criados['conjuntos']);
            $criados['anuncios'][] = ['id' => $post("/$act/ads", ['name' => mb_substr((string)($a['nome'] ?: $titulo), 0, 200), 'adset_id' => $adsetId, 'creative' => json_encode(['creative_id' => $cre]), 'status' => 'PAUSED'], 'Anúncio "' . $titulo . '"'), 'peca_id' => $pid, 'conjunto' => $cj, 'titulo' => $titulo];
        }
    } catch (Exception $e) {
        if ($criados['campanha']) pd_meta('DELETE', '/' . $criados['campanha'], [], $tok);   // apaga o rascunho pela metade: ou sobe tudo, ou nada
        sd_fail('Não subiu: ' . $e->getMessage() . ' (nada ficou na Meta)', 502);
    }
    $reg = ['client_id' => $cid, 'product_id' => uuidOk($b['product_id'] ?? ''), 'conta' => $act, 'campanha_meta_id' => $criados['campanha'], 'nome' => $nomeC, 'objetivo' => $obj, 'estado' => 'pausada', 'plano' => $plano, 'meta_ids' => $criados,
        'doc_id' => uuidOk($b['doc_id'] ?? ''), 'criado_por' => $me['id'] ?? null, 'pagina_id' => $pagina];
    $r = pd_supa('/rest/v1/pd_campanhas_lancadas', 'POST', $reg, ['Prefer: return=representation']);
    sai(['id' => $r['body'][0]['id'] ?? null, 'registro' => ($r['code'] ?? 500) < 300, 'campanha_meta_id' => $criados['campanha'], 'conjuntos' => count($criados['conjuntos']), 'anuncios' => count($criados['anuncios']),
        'link' => 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=' . preg_replace('/^act_/', '', $act) . '&selected_campaign_ids=' . $criados['campanha'], 'segundos' => round(microtime(true) - $t0, 1)]);
}

if ($acao === 'play' || $acao === 'pausar') {
    if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin liga ou desliga campanha.', 403);
    $id = uuidOk($b['id'] ?? '') ?: sd_fail('Campanha inválida.', 400);
    $q = pd_supa('/rest/v1/pd_campanhas_lancadas?id=eq.' . $id . '&select=*'); $L = $q['body'][0] ?? null; if (!$L) sd_fail('Não achei essa campanha na plataforma.', 404);
    [$tok] = tokenConta($L['conta']); $ids = (array)($L['meta_ids'] ?? []);
    if ($acao === 'play') {
        $a = metaOuFalha(pd_meta('GET', '/' . $L['conta'], ['fields' => 'account_status,funding_source_details,disable_reason'], $tok), 'Conta');
        if ((int)($a['account_status'] ?? 0) !== 1) sd_fail('A conta de anúncio não está ativa na Meta (status ' . ($a['account_status'] ?? '?') . '). Resolva lá antes do play.', 409);
        if (empty($a['funding_source_details'])) sd_fail('A conta não tem forma de pagamento cadastrada na Meta. O cliente precisa cadastrar lá; a plataforma não cadastra cartão.', 409);
        if (empty($b['confirmo'])) sd_fail('Falta a confirmação humana do play.', 400);
    }
    $st = $acao === 'play' ? 'ACTIVE' : 'PAUSED'; $erros = [];
    foreach (array_merge([$L['campanha_meta_id']], array_values((array)($ids['conjuntos'] ?? [])), array_map(fn($x) => $x['id'], (array)($ids['anuncios'] ?? []))) as $mid) {
        $r = pd_meta('POST', '/' . $mid, ['status' => $st], $tok); if (empty($r['body']['success'])) $erros[] = $mid . ': ' . pd_meta_erro($r); }
    if ($erros && $acao === 'play') { foreach ([$L['campanha_meta_id']] as $mid) pd_meta('POST', '/' . $mid, ['status' => 'PAUSED'], $tok); sd_fail('Não liguei tudo, então deixei pausado: ' . implode(' · ', array_slice($erros, 0, 3)), 502); }
    pd_supa('/rest/v1/pd_campanhas_lancadas?id=eq.' . $id, 'PATCH', ['estado' => $acao === 'play' ? 'ativa' : 'pausada', 'ligada_em' => $acao === 'play' ? gmdate('c') : null, 'ligada_por' => $acao === 'play' ? ($me['id'] ?? null) : null]);
    sai(['estado' => $acao === 'play' ? 'ativa' : 'pausada', 'avisos' => $erros]);
}

if ($acao === 'lista') {
    $cid = uuidOk($b['client_id'] ?? '');
    $q = pd_supa('/rest/v1/pd_campanhas_lancadas?select=id,client_id,product_id,conta,campanha_meta_id,nome,objetivo,estado,criado_em,ligada_em,clients(name),products(name)' . ($cid ? '&client_id=eq.' . $cid : '') . '&order=criado_em.desc&limit=60');
    sai(['campanhas' => array_values(array_filter((array)($q['body'] ?? []), 'is_array'))]);
}
sd_fail('Ação desconhecida.', 400);
