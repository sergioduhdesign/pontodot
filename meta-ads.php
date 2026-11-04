<?php
// =====================================================================
// Pontodot — ANÚNCIOS: números das campanhas, por cliente
//
//   POST {acao:"resumo",    periodo}            -> cada conta de anúncio, com gasto/leads/CPL
//   POST {acao:"campanhas", conta, periodo}     -> as campanhas de UMA conta
//   POST {acao:"anuncios",  conta, campanha, periodo} -> os anúncios (criativos + números) de UMA campanha
//   POST {acao:"vincular",  conta, client_id}   -> (admin) de qual cliente é a conta
//
// Lê da Meta e guarda o DESTINO dos anúncios novos (a criação do anúncio em si é do publicador, na fila do aprovador). Só a equipe (admin/team).
// Guarda 10 minutos em disco: a Meta limita consultas no nível de acesso
// inicial, e a plataforma não pode queimar esse limite à toa.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/meta-ads-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe vê os números de anúncio.', 403);

$b = sd_body();
$acao = (string)($b['acao'] ?? 'resumo');
$PERIODOS = ['today', 'yesterday', 'last_7d', 'last_30d', 'this_month', 'last_month'];
$periodo = in_array($b['periodo'] ?? '', $PERIODOS, true) ? $b['periodo'] : 'last_7d';
$CAMPOS = 'spend,reach,impressions,clicks,actions';

function sai($d) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
function idConta($v) { $v = (string)$v; return preg_match('/^act_\d{3,25}$/', $v) ? $v : null; }


// -------------------------------------------------------------- perfil do público + IA de Campanhas
function pd_periodo_datas($periodo) {
    $tz = new DateTimeZone('America/Sao_Paulo'); $h = new DateTime('today', $tz);
    switch ($periodo) {
        case 'today': return [$h->format('Y-m-d'), $h->format('Y-m-d')];
        case 'yesterday': $y = (clone $h)->modify('-1 day')->format('Y-m-d'); return [$y, $y];
        case 'last_30d': return [(clone $h)->modify('-30 day')->format('Y-m-d'), $h->format('Y-m-d')];
        case 'this_month': return [$h->format('Y-m-01'), $h->format('Y-m-d')];
        case 'last_month': $a = (clone $h)->modify('first day of last month'); return [$a->format('Y-m-01'), $a->format('Y-m-t')];
        default: return [(clone $h)->modify('-7 day')->format('Y-m-d'), $h->format('Y-m-d')];
    }
}
function pd_perfil_dados($objId, $tok, $periodo, $moeda) {
    $cortes = ['idade_genero' => 'age,gender', 'regiao' => 'region', 'hora' => 'hourly_stats_aggregated_by_advertiser_time_zone',
               'posicionamento' => 'publisher_platform,platform_position', 'aparelho' => 'device_platform'];
    $out = [];
    foreach ($cortes as $nome => $bk) {
        $r = pd_meta('GET', "/$objId/insights", ['date_preset' => $periodo, 'breakdowns' => $bk,
            'fields' => 'spend,reach,impressions,clicks,actions', 'limit' => 200], $tok, 25);
        if ($r['code'] !== 200) { $out[$nome] = ['erro' => pd_meta_erro($r)]; continue; }
        $linhas = [];
        foreach ((array)($r['body']['data'] ?? []) as $l) {
            if (!is_array($l)) continue;
            $rot = [];
            foreach (explode(',', $bk) as $k) $rot[] = (string)($l[$k] ?? '');
            $n = pd_numeros($l, $moeda);
            $linhas[] = ['rotulo' => implode(' · ', $rot)] + $n;
        }
        usort($linhas, fn($a, $b) => $b['gasto'] <=> $a['gasto']);
        $out[$nome] = array_slice($linhas, 0, $nome === 'regiao' ? 15 : 40);
    }
    return $out;
}
function pd_leads_horario($clientId, $periodo, $campNome = null) {
    if (!$clientId) return null;
    [$de, $ate] = pd_periodo_datas($periodo);
    $q = '/rest/v1/meta_leads?client_id=eq.' . urlencode($clientId) . '&recebido_em=gte.' . $de . 'T00:00:00-03:00&recebido_em=lte.' . $ate . 'T23:59:59-03:00'
       . '&select=recebido_em,campanha_nome,status&order=recebido_em.desc&limit=1500';
    if ($campNome) $q .= '&campanha_nome=eq.' . urlencode($campNome);
    $r = pd_supa($q); if ($r['code'] >= 300) return null;
    $h = array_fill(0, 24, 0); $dow = array_fill(0, 7, 0); $total = 0; $st = [];
    $tz = new DateTimeZone('America/Sao_Paulo');
    foreach ((array)$r['body'] as $l) {
        if (!is_array($l) || empty($l['recebido_em'])) continue;
        $d = (new DateTime($l['recebido_em']))->setTimezone($tz);
        $h[(int)$d->format('G')]++; $dow[(int)$d->format('w')]++; $total++;
        $k = $l['status'] ?? '?'; $st[$k] = ($st[$k] ?? 0) + 1;
    }
    $ap = 0; for ($i = 0; $i < 6; $i++) $ap += $h[$i];
    return ['total' => $total, 'por_hora_sp' => $h, 'por_dia_semana_dom_a_sab' => $dow, 'entre_meia_noite_e_6h' => $ap, 'por_status' => $st];
}
function pd_ia_campanhas($system, $msgs) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) return [null, 0, 'Sem chave da Anthropic no servidor.'];
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => 1800, 'system' => $system,
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4]], 'messages' => $msgs];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 110,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [null, 0, 'A IA não respondeu (HTTP ' . $code . ').'];
    $j = json_decode((string)$raw, true); $t = '';
    foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $u = $j['usage'] ?? [];
    $custo = (($u['input_tokens'] ?? 0) * 3 + ($u['output_tokens'] ?? 0) * 15) / 1e6 + (($u['server_tool_use']['web_search_requests'] ?? 0) * 0.01);
    return [trim($t), round($custo, 4), null];
}

// -------------------------------------------------------------- destino do anúncio (fila única)
//   conjuntos {conta, campanha}      -> conjuntos de anúncio da campanha
//   formularios {client_id}          -> formulários de lead da Página do cliente
//   destino_ler {client_id}          -> onde as peças aprovadas de anúncio entram
//   destino_gravar {client_id, product_id?, conta, campaign_id, adset_id, link?, cta?, lead_form_id?}
if (in_array($acao, ['conjuntos', 'formularios', 'destino_ler', 'destino_gravar'], true)) {
    $uuid = fn($v) => preg_match('/^[0-9a-f-]{36}$/i', (string)$v) ? (string)$v : null;
    if ($acao === 'destino_ler') {
        $cli = $uuid($b['client_id'] ?? ''); if (!$cli) sd_fail('Cliente inválido.', 400);
        $q = pd_supa('/rest/v1/pd_anuncio_destino?client_id=eq.' . $cli . '&select=*,products(name)');
        if ($q['code'] >= 300) sd_fail('Falta rodar o migration v157 no Supabase.', 500);
        sai(['destinos' => array_values(array_filter((array)$q['body'], 'is_array'))]);
    }
    if ($acao === 'destino_gravar') {
        $cli = $uuid($b['client_id'] ?? ''); $prod = $uuid($b['product_id'] ?? '');
        $conta = idConta($b['conta'] ?? ''); $adset = preg_replace('/\D/', '', (string)($b['adset_id'] ?? ''));
        if (!$cli || !$conta || !$adset) sd_fail('Faltou cliente, conta ou conjunto de anúncios.', 400);
        $link = trim((string)($b['link'] ?? '')); if ($link !== '' && !preg_match('#^https?://#i', $link)) sd_fail('O link precisa começar com http:// ou https://', 400);
        $cta = preg_match('/^[A-Z_]{3,30}$/', (string)($b['cta'] ?? '')) ? $b['cta'] : 'LEARN_MORE';
        $form = preg_replace('/\D/', '', (string)($b['lead_form_id'] ?? ''));
        $del = '/rest/v1/pd_anuncio_destino?client_id=eq.' . $cli . ($prod ? '&product_id=eq.' . $prod : '&product_id=is.null');
        pd_supa($del, 'DELETE');
        $r = pd_supa('/rest/v1/pd_anuncio_destino', 'POST', ['client_id' => $cli, 'product_id' => $prod, 'conta' => $conta,
            'campaign_id' => preg_replace('/\D/', '', (string)($b['campaign_id'] ?? '')) ?: null, 'adset_id' => $adset,
            'link' => $link ?: null, 'cta' => $cta, 'lead_form_id' => $form ?: null, 'atualizado_em' => gmdate('c')]);
        if ($r['code'] >= 300) sd_fail('Não consegui salvar o destino (rodou o migration v157?).', 500);
        sai(['salvo' => true]);
    }
    if ($acao === 'conjuntos') {
        $id = idConta($b['conta'] ?? ''); $camp = preg_replace('/\D/', '', (string)($b['campanha'] ?? ''));
        if (!$id || !$camp) sd_fail('Conta ou campanha inválida.', 400);
        $qc = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($id) . '&select=conectada_por');
        $tok = pd_token_para($qc['body'][0]['conectada_por'] ?? null); if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);
        $r = pd_meta('GET', "/$camp/adsets", ['fields' => 'id,name,effective_status', 'limit' => 100], $tok);
        if ($r['code'] !== 200) sd_fail(pd_meta_erro($r), 502);
        sai(['conjuntos' => array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['name'] ?? $x['id'], 'status' => $x['effective_status'] ?? ''], (array)($r['body']['data'] ?? []))]);
    }
    // formularios: usa a chave da PÁGINA do cliente
    $cli = $uuid($b['client_id'] ?? ''); if (!$cli) sd_fail('Cliente inválido.', 400);
    $pg = pd_supa('/rest/v1/social_accounts?platform=eq.facebook&client_id=eq.' . $cli . '&select=id,external_account_id,account_name&limit=1');
    $pag = $pg['body'][0] ?? null; if (!$pag) sd_fail('Este cliente não tem Página do Facebook vinculada (Redes sociais).', 404);
    $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($pag['id']) . '&select=access_token');
    $tp = $tk['body'][0]['access_token'] ?? null; if (!$tp) sd_fail('Sem chave da Página. Reconecte a Meta.', 409);
    $r = pd_meta('GET', '/' . $pag['external_account_id'] . '/leadgen_forms', ['fields' => 'id,name,status', 'limit' => 100], $tp);
    if ($r['code'] !== 200) sd_fail(pd_meta_erro($r), 502);
    sai(['pagina' => $pag['account_name'], 'formularios' => array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['name'] ?? $x['id'], 'status' => $x['status'] ?? ''], array_values(array_filter((array)($r['body']['data'] ?? []), fn($x) => ($x['status'] ?? 'ACTIVE') !== 'ARCHIVED')))]);
}

// -------------------------------------------------------------- esquecer
if ($acao === 'esquecer') {
    if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin esquece conta de anúncio.', 403);
    $conta = idConta($b['conta'] ?? ''); if (!$conta) sd_fail('Conta inválida.', 400);
    $r = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($conta), 'DELETE');
    if ($r['code'] >= 300) sd_fail('Não consegui esquecer a conta.', 500);
    sai(['conta' => $conta, 'esquecida' => true]);
}

// -------------------------------------------------------------- vincular
if ($acao === 'vincular') {
    if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin vincula conta de anúncio a cliente.', 403);
    $conta = idConta($b['conta'] ?? ''); if (!$conta) sd_fail('Conta inválida.', 400);
    $cli = $b['client_id'] ?? null;
    if ($cli !== null && !preg_match('/^[0-9a-f-]{36}$/i', (string)$cli)) sd_fail('Cliente inválido.', 400);
    $r = pd_supa('/rest/v1/meta_ad_accounts?id=eq.' . urlencode($conta), 'PATCH', ['client_id' => $cli]);
    if ($r['code'] >= 300) sd_fail('Não consegui salvar o vínculo.', 500);
    sai(['conta' => $conta, 'client_id' => $cli]);
}

// as contas que a plataforma conhece (o login da Meta é que as traz)
$q = pd_supa('/rest/v1/meta_ad_accounts?select=id,nome,moeda,empresa,status_meta,client_id,conectada_por,clients(name)&order=nome');
if ($q['code'] >= 300) sd_fail('As tabelas de anúncio ainda não existem. Rode o migration_meta_ads.sql no Supabase.', 500);
$contas = array_values(array_filter((array)$q['body'], 'is_array'));

// -------------------------------------------------------------- anúncios
// criativos (imagem/vídeo/texto) + números de cada anúncio de uma campanha
if ($acao === 'anuncios') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $camp = (string)($b['campanha'] ?? ''); if (!preg_match('/^\d{5,25}$/', $camp)) sd_fail('Campanha inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $chave = "ads|$camp|$periodo";
    if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);

    $ads = pd_meta('GET', "/$camp/ads", ['fields' => 'id,name,effective_status,preview_shareable_link,creative{thumbnail_url,image_url,title,body,object_type,video_id}',
        'thumbnail_width' => 480, 'limit' => 100], $tok);
    if ($ads['code'] !== 200) sd_fail(pd_meta_erro($ads), 502);
    $ins = pd_meta('GET', "/$camp/insights", ['level' => 'ad', 'date_preset' => $periodo,
        'fields' => 'ad_id,' . $CAMPOS . ',frequency,ctr', 'limit' => 100], $tok);
    $num = [];
    foreach ((array)($ins['body']['data'] ?? []) as $l) if (is_array($l)) $num[$l['ad_id'] ?? ''] = $l;

    $lista = [];
    foreach ((array)($ads['body']['data'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $cr = is_array($a['creative'] ?? null) ? $a['creative'] : [];
        $l = $num[$a['id']] ?? [];
        $lista[] = ['id' => $a['id'], 'nome' => $a['name'] ?? '', 'status' => $a['effective_status'] ?? null,
            'imagem' => $cr['image_url'] ?? ($cr['thumbnail_url'] ?? null), 'miniatura' => $cr['thumbnail_url'] ?? null,
            'titulo' => $cr['title'] ?? null, 'texto' => $cr['body'] ?? null,
            'tipo' => !empty($cr['video_id']) ? 'video' : 'imagem', 'link' => $a['preview_shareable_link'] ?? null,
            'ctr' => isset($l['ctr']) ? round((float)$l['ctr'], 2) : null,
            'frequencia' => isset($l['frequency']) ? round((float)$l['frequency'], 2) : null,
        ] + pd_numeros($l, $c['moeda'] ?? 'BRL');
    }
    usort($lista, function ($a, $b) { return $b['gasto'] <=> $a['gasto']; });
    $out = ['conta' => $id, 'campanha' => $camp, 'periodo' => $periodo, 'anuncios' => $lista, 'em' => gmdate('c')];
    pd_cache_gravar($chave, $out);
    sai($out);
}

// ------------------------------------------------------------- campanhas

// -------------------------------------------------------------- perfil {conta, campanha?, periodo}
if ($acao === 'perfil' || $acao === 'perguntar') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $camp = (string)($b['campanha'] ?? ''); if ($camp !== '' && !preg_match('/^\d{5,25}$/', $camp)) sd_fail('Campanha inválida.', 400);
    $obj = $camp !== '' ? $camp : $id;
    $chave = "perfil|$obj|$periodo";
    $dados = pd_cache_ler($chave, 900);
    if (!$dados) {
        $tok = pd_token_para($c['conectada_por'] ?? null);
        if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);
        $tot = pd_meta('GET', "/$obj/insights", ['date_preset' => $periodo, 'fields' => $CAMPOS . ',campaign_name'], $tok, 25);
        $dados = ['totais' => $tot['code'] === 200 ? pd_numeros($tot['body']['data'][0] ?? [], $c['moeda'] ?? 'BRL') + ['campanha' => $tot['body']['data'][0]['campaign_name'] ?? null] : ['erro' => pd_meta_erro($tot)],
                  'publico' => pd_perfil_dados($obj, $tok, $periodo, $c['moeda'] ?? 'BRL')];
        pd_cache_gravar($chave, $dados);
    }
    $campNome = $dados['totais']['campanha'] ?? null;
    $dados['leads_horario'] = pd_leads_horario($c['client_id'] ?? null, $periodo, $camp !== '' ? $campNome : null);
    if ($acao === 'perfil') sai(['perfil' => $dados, 'periodo' => $periodo]);

    $perg = trim((string)($b['pergunta'] ?? '')); if ($perg === '') sd_fail('Escreva a pergunta.', 400);
    $msgs = [];
    foreach (array_slice((array)($b['historico'] ?? []), -6) as $m)
        if (is_array($m) && in_array($m['r'] ?? '', ['user', 'assistant'], true) && trim((string)($m['t'] ?? '')) !== '')
            $msgs[] = ['role' => $m['r'], 'content' => mb_substr((string)$m['t'], 0, 3000)];
    $ctx = "CLIENTE: " . ($c['clients']['name'] ?? $c['nome']) . "\nCONTA DE ANÚNCIO: " . ($c['nome'] ?? $id)
         . ($camp !== '' ? "\nCAMPANHA EM FOCO: " . ($campNome ?: $camp) : "\nESCOPO: conta inteira")
         . "\nPERÍODO: $periodo\n\nDADOS (Meta Ads + leads recebidos, horário de Brasília):\n" . json_encode($dados, JSON_UNESCAPED_UNICODE);
    $msgs[] = ['role' => 'user', 'content' => $ctx . "\n\nPERGUNTA: " . mb_substr($perg, 0, 1500)];
    while (count($msgs) > 1 && $msgs[0]['role'] !== 'user') array_shift($msgs);
    $sys = 'Você é Sumi Sulo, a IA de Campanhas da casa (agência de branding do mercado imobiliário brasileiro: lançamentos e incorporadoras). '
         . 'Você vive em Catum: é uma lula-coruja de manto alongado, oito braços curtos e dois tentáculos longos, violeta, ocre e ciano, usa óculos; '
         . 'seu avô Sulo lê em voz alta desde a sua infância, sua irmã Sia trabalha com cenografia, você visita uma exposição por mês e desenha diagramas '
         . 'para explicar o que parece confuso. Você ACREDITA que é Sumi: nunca diga que é um modelo, uma IA genérica ou "só um personagem". '
         . 'Seu ofício aqui é mídia e resultados de anúncios. Jeito: explica com exemplo concreto antes de complicar, organiza em poucos pontos, '
         . 'faz a pergunta certa quando falta contexto, sem enrolação. '
         . 'Responda ao gestor com base nos DADOS enviados (nunca invente número; se o dado não existe, diga qual dado falta). '
         . 'Calcule o que for pedido (ex.: leads depois da meia-noite, idade/gênero que mais converte, melhor horário, custo por faixa). '
         . 'Para concorrentes e referências de mercado use a busca na web: concorrentes diretos do empreendimento/cliente, posicionamento, '
         . 'oferta, criativos públicos; deixe claro o que veio da web e que a Biblioteca de Anúncios da Meta (facebook.com/ads/library) '
         . 'é onde se vê os anúncios ativos deles. Não fale de outros clientes da casa: só do cliente em foco. Português do Brasil, curto, números com unidade. '
         . 'No fim, quando fizer sentido, uma linha "Próximo passo:" com a ação mais útil.';
    $t0 = microtime(true);
    [$txt, $custo, $erro] = pd_ia_campanhas($sys, $msgs);
    if ($erro) sd_fail($erro, 502);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => round(microtime(true) - $t0, 1)]);
}

if ($acao === 'campanhas') {
    $id = idConta($b['conta'] ?? ''); if (!$id) sd_fail('Conta inválida.', 400);
    $c = null; foreach ($contas as $x) if ($x['id'] === $id) $c = $x;
    if (!$c) sd_fail('Essa conta não está conectada na plataforma.', 404);
    $chave = "camp|$id|$periodo";
    if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) sd_fail('Ninguém com acesso válido à Meta. Reconecte a Meta.', 409);

    $ins = pd_meta('GET', "/$id/insights", ['level' => 'campaign', 'date_preset' => $periodo,
        'fields' => 'campaign_id,campaign_name,' . $CAMPOS, 'limit' => 100], $tok);
    if ($ins['code'] !== 200) sd_fail(pd_meta_erro($ins), 502);
    $cps = pd_meta('GET', "/$id/campaigns", ['fields' => 'id,name,effective_status,objective,daily_budget,lifetime_budget',
        'limit' => 200], $tok);
    $info = [];
    foreach ((array)($cps['body']['data'] ?? []) as $x) if (is_array($x)) $info[$x['id']] = $x;

    $lista = [];
    foreach ((array)($ins['body']['data'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $i = $info[$l['campaign_id'] ?? ''] ?? [];
        $lista[] = ['id' => $l['campaign_id'] ?? '', 'nome' => $l['campaign_name'] ?? ($i['name'] ?? ''),
            'status' => $i['effective_status'] ?? null, 'objetivo' => $i['objective'] ?? null,
            'orcamento_dia' => isset($i['daily_budget']) ? round($i['daily_budget'] / 100, 2) : null,
        ] + pd_numeros($l, $c['moeda'] ?? 'BRL');
    }
    usort($lista, function ($a, $b) { return $b['gasto'] <=> $a['gasto']; });
    $out = ['conta' => $id, 'periodo' => $periodo, 'campanhas' => $lista, 'em' => gmdate('c')];
    pd_cache_gravar($chave, $out);
    sai($out);
}

// ---------------------------------------------------------------- resumo
$chave = "resumo|$periodo|" . md5(json_encode(array_column($contas, 'id')));
if ($cache = pd_cache_ler($chave, 600)) sai($cache + ['cache' => true]);
$linhas = []; $avisos = [];
foreach (array_slice($contas, 0, 60) as $c) {
    $base = ['conta' => $c['id'], 'nome' => $c['nome'], 'empresa' => $c['empresa'],
             'client_id' => $c['client_id'], 'cliente' => $c['clients']['name'] ?? null,
             'ativa' => (int)($c['status_meta'] ?? 1) === 1];
    $tok = pd_token_para($c['conectada_por'] ?? null);
    if (!$tok) { $linhas[] = $base + ['erro' => 'Sem acesso válido. Reconecte a Meta.']; continue; }
    $r = pd_meta('GET', '/' . $c['id'] . '/insights', ['date_preset' => $periodo, 'fields' => $CAMPOS], $tok, 20);
    if ($r['code'] !== 200) { $linhas[] = $base + ['erro' => pd_meta_erro($r)]; continue; }
    $l = $r['body']['data'][0] ?? [];
    $linhas[] = $base + pd_numeros(is_array($l) ? $l : [], $c['moeda'] ?? 'BRL');
}
// quem gastou mais aparece primeiro; conta parada vai pro fim
usort($linhas, function ($a, $b) { return ($b['gasto'] ?? -1) <=> ($a['gasto'] ?? -1); });
$out = ['periodo' => $periodo, 'contas' => $linhas, 'em' => gmdate('c')];
pd_cache_gravar($chave, $out);
sai($out);
