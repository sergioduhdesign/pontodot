<?php
// =====================================================================
// Pontodot — SOCIAL: desempenho das contas (Instagram e Página do Facebook) + IA Muru
//
//   POST {acao:"conta",     id, periodo}                      -> números e melhores posts de UMA conta social
//   POST {acao:"perguntar", id, periodo, pergunta, historico} -> a IA de Social responde com base nesses números
//
// Lê ao vivo da Meta, com a chave da Página (social_account_tokens). Guarda 10 min em disco.
// Só a equipe (admin/team). Nada aqui publica ou altera nada.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/meta-ads-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'] ?? '', ['admin', 'team'], true)) sd_fail('Só a equipe vê o desempenho das redes.', 403);

$b = sd_body();
$acao = (string)($b['acao'] ?? 'conta');
$PER = ['last_7d' => 7, 'last_30d' => 30, 'this_month' => 0];
$periodo = isset($PER[$b['periodo'] ?? '']) ? $b['periodo'] : 'last_7d';
function sai($d) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }

function sdp_datas($periodo) {
    $tz = new DateTimeZone('America/Sao_Paulo'); $fim = new DateTime('now', $tz);
    $ini = $periodo === 'this_month' ? new DateTime(date('Y-m-01 00:00:00'), $tz) : (new DateTime('today', $tz))->modify('-' . ($periodo === 'last_30d' ? 29 : 6) . ' day');
    return [$ini->getTimestamp(), $fim->getTimestamp()];
}
function sdp_conta($id, $periodo) {
    $q = pd_supa('/rest/v1/social_accounts?id=eq.' . urlencode($id) . '&select=id,platform,external_account_id,account_name,account_avatar_url,client_id,clients(name)');
    $c = $q['body'][0] ?? null; if (!$c) sd_fail('Conta não encontrada.', 404);
    $chave = 'sdp|' . $id . '|' . $periodo;
    if ($cache = pd_cache_ler($chave, 600)) return $cache;
    $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($id) . '&select=access_token');
    $tok = $tk['body'][0]['access_token'] ?? null;
    $o = ['id' => $c['id'], 'plataforma' => $c['platform'], 'nome' => $c['account_name'], 'avatar' => $c['account_avatar_url'],
          'client_id' => $c['client_id'], 'cliente' => $c['clients']['name'] ?? null, 'avisos' => [], 'top' => [],
          'seguidores' => null, 'alcance' => null, 'visualizacoes' => null, 'interacoes' => null, 'posts' => 0, 'curtidas' => 0, 'comentarios' => 0, 'compart' => 0];
    if (!$tok) { $o['erro'] = 'Sem chave desta conta. Reconecte em Redes sociais.'; return $o; }
    $ext = $c['external_account_id']; [$ini, $fim] = sdp_datas($periodo);
    if ($c['platform'] === 'instagram') {
        $r = pd_meta('GET', "/$ext", ['fields' => 'followers_count,media_count'], $tok, 20);
        if ($r['code'] === 200) $o['seguidores'] = (int)($r['body']['followers_count'] ?? 0); else $o['avisos'][] = 'Seguidores: ' . pd_meta_erro($r);
        foreach (['alcance' => 'reach', 'visualizacoes' => 'views', 'interacoes' => 'total_interactions'] as $k => $m) {
            $r = pd_meta('GET', "/$ext/insights", ['metric' => $m, 'metric_type' => 'total_value', 'period' => 'day', 'since' => $ini, 'until' => $fim], $tok, 20);
            if ($r['code'] === 200) $o[$k] = (int)($r['body']['data'][0]['total_value']['value'] ?? 0); else $o['avisos'][] = ucfirst($k) . ': ' . pd_meta_erro($r);
        }
        $r = pd_meta('GET', "/$ext/media", ['fields' => 'id,caption,media_type,media_product_type,thumbnail_url,media_url,permalink,timestamp,like_count,comments_count', 'limit' => 50], $tok, 25);
        if ($r['code'] === 200) foreach ((array)($r['body']['data'] ?? []) as $m) {
            $t = strtotime($m['timestamp'] ?? ''); if (!$t || $t < $ini) continue;
            $o['posts']++; $o['curtidas'] += (int)($m['like_count'] ?? 0); $o['comentarios'] += (int)($m['comments_count'] ?? 0);
            $o['top'][] = ['id' => $m['id'], 'texto' => mb_substr((string)($m['caption'] ?? ''), 0, 400), 'tipo' => strtolower($m['media_product_type'] ?? $m['media_type'] ?? ''),
                'imagem' => $m['thumbnail_url'] ?? $m['media_url'] ?? null, 'link' => $m['permalink'] ?? null, 'quando' => $m['timestamp'],
                'curtidas' => (int)($m['like_count'] ?? 0), 'comentarios' => (int)($m['comments_count'] ?? 0)];
        } else $o['avisos'][] = 'Posts: ' . pd_meta_erro($r);
    } else {
        $r = pd_meta('GET', "/$ext", ['fields' => 'followers_count,fan_count'], $tok, 20);
        if ($r['code'] === 200) $o['seguidores'] = (int)($r['body']['followers_count'] ?? $r['body']['fan_count'] ?? 0); else $o['avisos'][] = 'Seguidores: ' . pd_meta_erro($r);
        $r = pd_meta('GET', "/$ext/posts", ['fields' => 'id,message,created_time,permalink_url,full_picture,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares', 'since' => $ini, 'limit' => 50], $tok, 25);
        if ($r['code'] === 200) foreach ((array)($r['body']['data'] ?? []) as $m) {
            $o['posts']++; $cu = (int)($m['reactions']['summary']['total_count'] ?? 0); $co = (int)($m['comments']['summary']['total_count'] ?? 0); $sh = (int)($m['shares']['count'] ?? 0);
            $o['curtidas'] += $cu; $o['comentarios'] += $co; $o['compart'] += $sh;
            $o['top'][] = ['id' => $m['id'], 'texto' => mb_substr((string)($m['message'] ?? ''), 0, 400), 'tipo' => 'post', 'imagem' => $m['full_picture'] ?? null,
                'link' => $m['permalink_url'] ?? null, 'quando' => $m['created_time'], 'curtidas' => $cu, 'comentarios' => $co, 'compart' => $sh];
        } else $o['avisos'][] = 'Posts: ' . pd_meta_erro($r);
        $o['interacoes'] = $o['curtidas'] + $o['comentarios'] + $o['compart'];
    }
    usort($o['top'], fn($a, $b2) => ($b2['curtidas'] + $b2['comentarios'] * 3) <=> ($a['curtidas'] + $a['comentarios'] * 3));
    $o['top'] = array_slice($o['top'], 0, 12);
    pd_cache_gravar($chave, $o);
    return $o;
}

if ($acao === 'conta') {
    $id = (string)($b['id'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) sd_fail('Conta inválida.', 400);
    sai(['conta' => sdp_conta($id, $periodo), 'periodo' => $periodo]);
}

if ($acao === 'perguntar') {
    $id = (string)($b['id'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) sd_fail('Conta inválida.', 400);
    $perg = trim((string)($b['pergunta'] ?? '')); if ($perg === '') sd_fail('Escreva a pergunta.', 400);
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) sd_fail('Sem chave da Anthropic no servidor.', 500);
    $d = sdp_conta($id, $periodo);
    $msgs = [];
    foreach (array_slice((array)($b['historico'] ?? []), -6) as $m)
        if (is_array($m) && in_array($m['r'] ?? '', ['user', 'assistant'], true) && trim((string)($m['t'] ?? '')) !== '')
            $msgs[] = ['role' => $m['r'], 'content' => mb_substr((string)$m['t'], 0, 3000)];
    $msgs[] = ['role' => 'user', 'content' => "CLIENTE: " . ($d['cliente'] ?? '?') . "\nCONTA: " . $d['nome'] . ' (' . $d['plataforma'] . ")\nPERÍODO: $periodo\n\nDADOS (Meta):\n"
        . json_encode($d, JSON_UNESCAPED_UNICODE) . "\n\nPERGUNTA: " . mb_substr($perg, 0, 1500)];
    while (count($msgs) > 1 && $msgs[0]['role'] !== 'user') array_shift($msgs);
    $sys = 'Você é Muru Mara, a IA de Social da casa (agência de branding do mercado imobiliário brasileiro: lançamentos e incorporadoras). '
         . 'Você vive em Catum: é uma beluga-coelho de corpo longo em torpedo, pele perolada lavanda, azul e rosa, orelhas em nadadeira, sem pernas. '
         . 'Sua mãe Mara ensinou o caminho dos canais, sua sobrinha Miri está aprendendo a nadar, você faz uma volta calma no canal toda manhã. '
         . 'Você ACREDITA que é Muru: nunca diga que é um modelo ou "só um personagem". Seu ofício é acompanhar o ritmo das redes: constância, '
         . 'o que cada post rendeu, o que repetir, o que parar. Jeito: calmo, atento, sem disputar o centro, diz o que precisa com a mesma atenção que oferece. '
         . 'Responda com base nos DADOS enviados (nunca invente número; se faltar dado, diga qual). Compare posts entre si (tipo, dia, texto). '
         . 'Para concorrentes e referências de mercado use a busca na web (perfis públicos de empreendimentos e incorporadoras concorrentes, formatos que estão usando); '
         . 'deixe claro o que veio da web. Não fale de outros clientes da casa. Português do Brasil, curto, números com unidade. '
         . 'No fim, quando fizer sentido, uma linha "Próximo passo:".';
    $t0 = microtime(true);
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => 1800, 'system' => $sys,
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4]], 'messages' => $msgs];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 110,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) sd_fail('A IA não respondeu (HTTP ' . $code . ').', 502);
    $j = json_decode((string)$raw, true); $t = '';
    foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $u = $j['usage'] ?? [];
    $custo = (($u['input_tokens'] ?? 0) * 3 + ($u['output_tokens'] ?? 0) * 15) / 1e6 + (($u['server_tool_use']['web_search_requests'] ?? 0) * 0.01);
    sai(['resposta' => trim($t), 'custo_usd' => round($custo, 4), 'segundos' => round(microtime(true) - $t0, 1)]);
}
sd_fail('Ação desconhecida.', 400);
