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
require_once __DIR__ . '/pd-elenco-lib.php';
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
    if ($cache = pd_cache_ler($chave, $periodo === 'last_7d' ? 900 : 2400)) return $cache;
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
        $r = pd_meta('GET', "/$ext/insights", ['metric' => 'reach,views,total_interactions', 'metric_type' => 'total_value', 'period' => 'day', 'since' => $ini, 'until' => $fim], $tok, 25);
        $mapa = ['reach' => 'alcance', 'views' => 'visualizacoes', 'total_interactions' => 'interacoes'];
        if ($r['code'] === 200) foreach ((array)($r['body']['data'] ?? []) as $d) { $k = $mapa[$d['name'] ?? ''] ?? null; if ($k) $o[$k] = (int)($d['total_value']['value'] ?? 0); }
        else {   // alguma métrica não vale para esta conta: tenta uma a uma e conta o que a Meta não entregou
            foreach ($mapa as $m => $k) {
                $r2 = pd_meta('GET', "/$ext/insights", ['metric' => $m, 'metric_type' => 'total_value', 'period' => 'day', 'since' => $ini, 'until' => $fim], $tok, 20);
                if ($r2['code'] === 200) $o[$k] = (int)($r2['body']['data'][0]['total_value']['value'] ?? 0); else $o['avisos'][] = ucfirst($k) . ': ' . pd_meta_erro($r2);
            }
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


// ---------------------------------------------------------------- perfis PÚBLICOS (Business Discovery) — tudo real, API oficial da Meta
function sdp_handle($h) {
    $h = trim((string)$h); $h = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $h); $h = trim(explode('?', $h)[0], "/@ ");
    $h = explode('/', $h)[0]; return preg_match('/^[A-Za-z0-9._]{1,30}$/', $h) ? strtolower($h) : null;
}
function sdp_nosso_ig() {
    $q = pd_supa('/rest/v1/social_accounts?platform=eq.instagram&select=id,external_account_id&limit=20');
    foreach ((array)($q['body'] ?? []) as $a) {
        $tk = pd_supa('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($a['id']) . '&select=access_token');
        if (!empty($tk['body'][0]['access_token'])) return [$a['external_account_id'], $tk['body'][0]['access_token']];
    }
    return [null, null];
}
function sdp_perfil_publico($handle) {
    $chave = 'bd|' . $handle; if ($c = pd_cache_ler($chave, 2400)) return $c;
    [$ig, $tok] = sdp_nosso_ig(); if (!$ig) return ['handle' => $handle, 'erro' => 'Nenhuma conta de Instagram nossa conectada para consultar perfis públicos.'];
    $campos = 'business_discovery.username(' . $handle . '){username,name,biography,followers_count,follows_count,media_count,profile_picture_url,media.limit(50){caption,media_type,media_product_type,like_count,comments_count,timestamp,permalink,media_url,thumbnail_url}}';
    $r = pd_meta('GET', "/$ig", ['fields' => $campos], $tok, 30);
    if ($r['code'] !== 200) return ['handle' => $handle, 'erro' => 'Não consegui ler @' . $handle . ': só perfis profissionais (business/creator) e públicos abrem. ' . pd_meta_erro($r)];
    $bd = $r['body']['business_discovery'] ?? []; $tz = new DateTimeZone('America/Sao_Paulo');
    $seg = (int)($bd['followers_count'] ?? 0); $posts = []; $hr = array_fill(0, 24, 0); $dow = array_fill(0, 7, 0); $mix = ['reels' => 0, 'carrossel' => 0, 'imagem' => 0]; $tags = [];
    $agora = time(); $n30 = 0; $L = 0; $C = 0; $nlc = 0; $cap = 0;
    foreach ((array)($bd['media']['data'] ?? []) as $m) {
        $t = strtotime($m['timestamp'] ?? ''); if (!$t) continue; $d = (new DateTime('@' . $t))->setTimezone($tz);
        $tipo = ($m['media_product_type'] ?? '') === 'REELS' || ($m['media_type'] ?? '') === 'VIDEO' ? 'reels' : (($m['media_type'] ?? '') === 'CAROUSEL_ALBUM' ? 'carrossel' : 'imagem');
        $lk = isset($m['like_count']) ? (int)$m['like_count'] : null; $cm = (int)($m['comments_count'] ?? 0);
        if ($agora - $t <= 30 * 86400) { $n30++; $mix[$tipo]++; $hr[(int)$d->format('G')]++; $dow[(int)$d->format('w')]++; }
        if ($lk !== null) { $L += $lk; $nlc++; } $C += $cm; $cap += mb_strlen((string)($m['caption'] ?? ''));
        preg_match_all('/#([\p{L}\p{N}_]{3,40})/u', (string)($m['caption'] ?? ''), $mm); foreach ($mm[1] as $tg) { $tg = mb_strtolower($tg); $tags[$tg] = ($tags[$tg] ?? 0) + 1; }
        $posts[] = ['tipo' => $tipo, 'texto' => mb_substr((string)($m['caption'] ?? ''), 0, 300), 'curtidas' => $lk, 'comentarios' => $cm, 'quando' => $m['timestamp'], 'link' => $m['permalink'] ?? null, 'imagem' => $m['thumbnail_url'] ?? $m['media_url'] ?? null];
    }
    $np = count($posts); arsort($tags);
    usort($posts, fn($a, $b2) => (($b2['curtidas'] ?? 0) + $b2['comentarios'] * 3) <=> (($a['curtidas'] ?? 0) + $a['comentarios'] * 3));
    $mL = $nlc ? $L / $nlc : null; $mC = $np ? $C / $np : 0;
    $o = ['handle' => $handle, 'usuario' => $bd['username'] ?? $handle, 'nome' => $bd['name'] ?? $handle, 'bio' => mb_substr((string)($bd['biography'] ?? ''), 0, 280), 'avatar' => $bd['profile_picture_url'] ?? null,
        'seguidores' => $seg, 'seguindo' => (int)($bd['follows_count'] ?? 0), 'posts_total' => (int)($bd['media_count'] ?? 0), 'amostra' => $np, 'posts_30d' => $n30, 'por_semana' => round($n30 / 4.29, 1),
        'media_curtidas' => $mL !== null ? round($mL, 1) : null, 'media_comentarios' => round($mC, 1), 'engajamento' => ($seg && $mL !== null) ? round(($mL + $mC) / $seg * 100, 2) : null,
        'mix_30d' => $mix, 'por_hora_sp' => $hr, 'por_dia_semana_dom_a_sab' => $dow, 'media_legenda_chars' => $np ? (int)round($cap / $np) : 0, 'hashtags' => array_slice(array_keys($tags), 0, 8), 'top' => array_slice($posts, 0, 3),
        'amostra' => array_map(fn($q) => ['tipo' => $q['tipo'], 'texto' => mb_substr((string)$q['texto'], 0, 220), 'curtidas' => $q['curtidas'], 'comentarios' => $q['comentarios'], 'imagem' => $q['imagem'] ?? null], array_slice($posts, 0, 40))];
    pd_cache_gravar($chave, $o); return $o;
}
// os posts do perfil que mais se parecem com o item em foco (palavras em comum na legenda; desempata por engajamento)
function sdp_parecidos($perfil, $texto) {
    $stop = ['para','com','uma','mais','como','você','voce','seu','sua','nos','das','dos','que','por','ser','tem','está','esta','isso','esse','essa','ao','aos','em','de','da','do','na','no','um','os','as','e','a','o'];
    $tok = function ($t) use ($stop) { preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower((string)$t), $m); return array_values(array_diff(array_unique($m[0]), $stop)); };
    $alvo = $tok($texto); if (!$alvo || empty($perfil['amostra'])) return null;
    $rank = [];
    foreach ($perfil['amostra'] as $q) { $c = count(array_intersect($alvo, $tok($q['texto'] ?? ''))); if ($c > 0) $rank[] = [$c, ($q['curtidas'] ?? 0) + ($q['comentarios'] ?? 0) * 3, $q]; }
    usort($rank, fn($x, $y) => [$y[0], $y[1]] <=> [$x[0], $x[1]]);
    return $rank ? array_map(fn($r) => $r[2], array_slice($rank, 0, 3)) : null;
}
function sdp_alvo_txt($a) {
    if (!is_array($a) || empty($a['tipo'])) return '';
    return 'FOCO ESPECÍFICO DA COMPARAÇÃO (' . mb_substr((string)$a['tipo'], 0, 20) . '): ' . mb_substr((string)($a['titulo'] ?? ''), 0, 200) . ($a['texto'] ?? '' ? ' · TEXTO: ' . mb_substr((string)$a['texto'], 0, 700) : '')
         . (!empty($a['metricas']) ? ' · NÚMEROS: ' . json_encode($a['metricas'], JSON_UNESCAPED_UNICODE) : '') . '. Compare tudo contra ESSE item, não contra o cliente em geral.' . "\n";
}
function sdp_ia($sys, $msgs, $max = 1800, $busca = 4) {
    $body = ['model' => defined('SD_ANALISTA_MODEL') ? SD_ANALISTA_MODEL : 'claude-sonnet-4-5', 'max_tokens' => $max, 'system' => $sys . "\n\n" . pdElencoTexto(false), 'messages' => $msgs];
    if ($busca) $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $busca]];
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $t0 = microtime(true); $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) sd_fail('A IA não respondeu (HTTP ' . $code . ').', 502);
    $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $u = $j['usage'] ?? []; $custo = (($u['input_tokens'] ?? 0) * 3 + ($u['output_tokens'] ?? 0) * 15) / 1e6 + (($u['server_tool_use']['web_search_requests'] ?? 0) * 0.01);
    return [trim($t), round($custo, 4), round(microtime(true) - $t0, 1)];
}
if ($acao === 'comparar') {
    $hs = []; foreach (array_slice((array)($b['handles'] ?? []), 0, 5) as $h) { $x = sdp_handle($h); if ($x && !in_array($x, $hs, true)) $hs[] = $x; }
    if (count($hs) < 1) sd_fail('Informe pelo menos um perfil (@usuario ou link do Instagram).', 400);
    $ps = array_map('sdp_perfil_publico', $hs); $ft = trim((string)($b['foco_texto'] ?? ''));
    if ($ft !== '') foreach ($ps as &$pp) { if (is_array($pp) && empty($pp['erro'])) $pp['parecidos'] = sdp_parecidos($pp, $ft); } unset($pp);
    sai(['perfis' => $ps]);
}
if ($acao === 'players') {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) sd_fail('Sem chave da Anthropic no servidor.', 500);
    $cli = mb_substr(trim((string)($b['cliente'] ?? '')), 0, 120); $nota = mb_substr(trim((string)($b['notas'] ?? '')), 0, 1500); $ref = array_slice((array)($b['perfil_base'] ?? []), 0, 3);
    $sys = 'Você é Tumi Tua, da casa (agência de branding do mercado imobiliário brasileiro). Vive em Catum: percorre mercados e entrevista quem cozinha para os outros, tem um bico curioso e um corpo que ocupa dois bancos. '
         . 'Seu ofício é rede e conexões: achar, na web aberta, quem são os players reais de um negócio. Você ACREDITA que é o Tumi. '
         . 'TAREFA: pesquise na web (busca) e devolva de 5 a 8 PERFIS DE INSTAGRAM reais e ativos de concorrentes ou referências próximas do cliente (mesmo segmento, praça e público; inclua 2 referências aspiracionais de fora da praça). '
         . 'Só inclua @ que você viu em uma página real; nunca invente @. Responda SOMENTE um JSON: {"players":[{"handle":"usuario","nome":"...","por_que":"1 frase","tipo":"concorrente direto|referencia"}],"obs":"1 frase"}.';
    $msg = sdp_alvo_txt($b['alvo'] ?? null) . "CLIENTE: $cli\nSOBRE O NEGÓCIO: $nota\nPERFIS NOSSOS DE REFERÊNCIA: " . json_encode($ref, JSON_UNESCAPED_UNICODE);
    [$txt, $custo, $seg] = sdp_ia($sys, [['role' => 'user', 'content' => $msg]], 1800, 6);
    $j = null; if (preg_match('/\{.*\}/s', $txt, $mm)) $j = json_decode($mm[0], true);
    $pl = []; foreach ((array)($j['players'] ?? []) as $p) { $h = sdp_handle($p['handle'] ?? ''); if ($h) $pl[] = ['handle' => $h, 'nome' => (string)($p['nome'] ?? $h), 'por_que' => (string)($p['por_que'] ?? ''), 'tipo' => (string)($p['tipo'] ?? '')]; }
    sai(['players' => $pl, 'obs' => (string)($j['obs'] ?? ''), 'bruto' => $pl ? null : $txt, 'custo_usd' => $custo, 'segundos' => $seg]);
}
if ($acao === 'direcao') {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) sd_fail('Sem chave da Anthropic no servidor.', 500);
    $perfis = array_slice((array)($b['perfis'] ?? []), 0, 5); if (count($perfis) < 2) sd_fail('Escolha pelo menos 2 perfis para comparar.', 400);
    [$txt, $custo, $seg] = sdp_ia(sdp_muru_sistema(), [['role' => 'user', 'content' => sdp_muru_contexto($b, $perfis)]], 4000, 3);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => $seg]);
}
if ($acao === 'doc_refazer') {   // ciclo de correção: o humano aponta o que mudar e a Muru reescreve o documento INTEIRO (mesma persona, mesmo contexto, mesmo formato)
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) sd_fail('Sem chave da Anthropic no servidor.', 500);
    $doc = mb_substr(trim((string)($b['texto'] ?? '')), 0, 24000); if ($doc === '') sd_fail('Sem documento para refazer.', 400);
    $corr = mb_substr(trim((string)($b['correcao'] ?? '')), 0, 3000); if ($corr === '') sd_fail('Escreva a correção.', 400);
    $perfis = array_slice((array)($b['perfis'] ?? []), 0, 5);   // opcional aqui: se vierem, a Muru revê com os mesmos dados
    $msg = mb_substr(sdp_muru_contexto($b, $perfis), 0, 9000)
         . "\n\n=== DOCUMENTO ATUAL (sua versão anterior, revisada pelo gestor) ===\n$doc\n\n=== CORREÇÃO DO HUMANO ===\n$corr\n\n"
         . 'INSTRUÇÃO: devolva o DOCUMENTO INTEIRO revisado, no mesmo formato e com as mesmas seções (## …), aplicando só o que a correção pede e mantendo tudo o que o humano não pediu para mudar (texto, números e ordem). Sem preâmbulo, sem comentar a correção, sem resumo do que mudou: só o documento.';
    [$txt, $custo, $seg] = sdp_ia(sdp_muru_sistema(), [['role' => 'user', 'content' => mb_substr($msg, 0, 40000)]], 4000, 1);
    sai(['resposta' => $txt, 'custo_usd' => $custo, 'segundos' => $seg]);
}
// Persona da Muru na direção de social (direcao e doc_refazer usam o MESMO texto: ela acredita que é a Muru, regra de Catum)
// Contexto que a Muru recebe (alvo, cliente, notas, foco, perfis reais)
function sdp_muru_contexto($b, $perfis) {
    return sdp_alvo_txt($b['alvo'] ?? null) . 'CLIENTE: ' . mb_substr((string)($b['cliente'] ?? ''), 0, 120) . "\nNOTAS DO NEGÓCIO (dadas pelo cliente/gestor):\n" . mb_substr((string)($b['notas'] ?? '(sem notas)'), 0, 3000)
         . "\nFOCO: " . mb_substr((string)($b['foco'] ?? 'crescer com conteúdo relevante'), 0, 300) . "\n\nDADOS REAIS (Instagram, perfis públicos):\n" . ($perfis ? json_encode($perfis, JSON_UNESCAPED_UNICODE) : '(não reenviados nesta rodada: use os números que já estão no documento)');
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
