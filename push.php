<?php
// =====================================================================
// SD Workflow — O APARELHO PEDE PRA SER AVISADO
//
// A criptografia do push já existia (push-lib.php). O que faltava eram
// as duas pontas: ninguém ASSINAVA, e ninguém DISPARAVA. Esta é a
// primeira — o navegador se apresenta e fica cadastrado.
//
//   POST {acao:"chave"}            -> a chave pública VAPID (e se dá pé)
//   POST {acao:"assinar", sub}     -> guarda ESTE aparelho
//   POST {acao:"sair", endpoint}   -> tira ESTE aparelho
//   POST {acao:"testar"}           -> manda um push agora, pra você mesmo
//   POST {acao:"estado"}           -> quantos aparelhos, e o que falta
// =====================================================================

// Se algo quebrar de verdade aqui dentro, quem abriu o painel recebe o
// MOTIVO em JSON — e não um "500" mudo que ninguém sabe ler.
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    $msg = preg_replace('#\s+in\s+/\S+#', '', (string)$e['message']);       // sem caminho do servidor
    echo json_encode(['error' => 'push.php quebrou: ' . substr($msg, 0, 180)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';

$me   = sd_current_user();
$body = sd_body();
$acao = $body['acao'] ?? 'estado';

function p_ok($d) { header('Content-Type: application/json'); echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
function p_configurado() { return defined('SD_VAPID_PUBLIC') && defined('SD_VAPID_PRIVATE') && SD_VAPID_PUBLIC && SD_VAPID_PRIVATE; }

// ----------------------------------------------------------------- chave
if ($acao === 'chave') {
    if (!p_configurado()) p_ok(['configurado' => false,
        'falta' => 'As chaves VAPID não estão no sd-config.php (SD_VAPID_PUBLIC e SD_VAPID_PRIVATE).']);
    p_ok(['configurado' => true, 'chave' => SD_VAPID_PUBLIC]);
}

// --------------------------------------------------------------- assinar
if ($acao === 'assinar') {
    $sub = $body['sub'] ?? null;
    if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        sd_fail('A assinatura do navegador veio incompleta.', 400);
    }
    // Um aparelho = um endpoint. O mesmo celular reassinando atualiza a linha
    // dele em vez de criar outra (o índice único no endpoint garante isso).
    $r = sd_supabase('/rest/v1/push_subscriptions', 'POST', [
        'user_id'      => $me['id'],
        'endpoint'     => $sub['endpoint'],
        'subscription' => $sub,
    ], ['Prefer: resolution=merge-duplicates,return=representation']);
    if ($r['code'] >= 300) sd_fail('Não consegui guardar a assinatura (HTTP ' . $r['code'] . '). Rode o sql/migration_push.sql.', 500);

    $q = sd_supabase('/rest/v1/push_subscriptions?user_id=eq.' . urlencode($me['id']) . '&select=id');
    p_ok(['aparelhos' => count($q['body'] ?? [])]);
}

// ------------------------------------------------------------------ sair
if ($acao === 'sair') {
    $ep = (string)($body['endpoint'] ?? '');
    if (!$ep) sd_fail('Faltou dizer qual aparelho.', 400);
    sd_supabase('/rest/v1/push_subscriptions?endpoint=eq.' . urlencode($ep) . '&user_id=eq.' . urlencode($me['id']), 'DELETE');
    p_ok(['saiu' => true]);
}

// ---------------------------------------------------------------- testar
if ($acao === 'testar') {
    if (!p_configurado()) sd_fail('As chaves VAPID não estão configuradas no servidor.', 500);
    $n = sd_push_usuario($me['id'], 'A pontodot te cutucou',
        'Se você está lendo isso no celular, o aviso funciona.', '/index.html', 'sd-teste');
    if (!$n) sd_fail('Nenhum aparelho recebeu. Assine primeiro neste navegador, ou o aparelho antigo já expirou.', 400);
    p_ok(['enviados' => $n]);
}

// ---------------------------------------------------------------- estado
// A tabela pode estar no formato antigo (sem endpoint / sem created_at).
// Antes, a resposta de erro do banco caía no array_map e derrubava o PHP
// num 500 mudo. Agora tenta do mais completo pro mais simples e, se nada
// servir, DIZ o que falta.
$q = null;
foreach (['id,endpoint,created_at', 'id,endpoint', 'id'] as $cols) {
    $q = sd_supabase('/rest/v1/push_subscriptions?user_id=eq.' . urlencode($me['id']) . '&select=' . $cols);
    if (($q['code'] ?? 500) < 300 && is_array($q['body'] ?? null) && ($q['body'] === [] || isset($q['body'][0]))) break;
    $q = null;
}
if (!$q) p_ok(['configurado' => p_configurado(), 'aparelhos' => [], 'quantos' => 0,
    'tabela' => false,
    'falta' => 'A lista de aparelhos no banco está no formato antigo. Rode o migration_push.sql no Supabase (SQL Editor) uma vez.']);
$apars = [];
foreach ($q['body'] as $r) {
    if (!is_array($r)) continue;
    $apars[] = [
        'id'   => $r['id'] ?? null,
        'onde' => preg_replace('#^https?://([^/]+).*#', '$1', (string)($r['endpoint'] ?? '')),
        'em'   => $r['created_at'] ?? null,
    ];
}
p_ok([
    'configurado' => p_configurado(),
    'tabela'      => true,
    'aparelhos'   => $apars,
    'quantos'     => count($apars),
    'beams'       => defined('SD_BEAMS_INSTANCE_ID') && defined('SD_BEAMS_SECRET'),
    'falta'       => p_configurado() ? null : 'As chaves de aviso (SD_VAPID_PUBLIC e SD_VAPID_PRIVATE) não estão no sd-config.php.',
]);
