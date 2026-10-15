<?php
// =====================================================================
// Pontodot — TESTE DA CONEXÃO COM A META (só leitura)
//
//   /meta-teste.php?key=SD_CRON_KEY
//
// Testa o token de usuário (campanhas) e o token de cada conta social
// (Instagram/Página), e diz o que a Meta respondeu. Não grava nada e
// NUNCA mostra o token.
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
if (!defined('SD_CRON_KEY') || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') { http_response_code(500); die('SD_CRON_KEY nao configurada.'); }
if (($_GET['key'] ?? '') !== SD_CRON_KEY) { http_response_code(403); die('Chave invalida.'); }
header('Content-Type: text/plain; charset=utf-8');

function g($path, $token, $params = []) {
    $params['access_token'] = $token;
    $ch = curl_init('https://graph.facebook.com/v25.0' . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$raw, true) ?: []];
}
function veredito($code, $j) {
    if ($code === 200 && empty($j['error'])) return 'OK';
    $e = $j['error'] ?? [];
    $c = $e['code'] ?? '?'; $s = $e['error_subcode'] ?? '';
    $dica = $c == 190 ? ' → token inválido/vencido: RECONECTAR' : ($c == 10 || $c == 200 ? ' → falta permissão' : ($c == 4 || $c == 17 || $c == 32 ? ' → limite de uso da Meta (espera)' : ''));
    return 'ERRO ' . $c . ($s ? '/' . $s : '') . ' · ' . ($e['message'] ?? 'sem mensagem') . $dica;
}

echo "Pontodot — teste da conexão com a Meta\n" . date('c') . "\n\n";

echo "== TOKEN DE USUÁRIO (campanhas / anúncios) ==\n";
$r = sd_supabase('/rest/v1/meta_user_tokens?select=user_id,access_token,expira_em,atualizado_em&order=atualizado_em.desc&limit=3');
$linhas = (array)($r['body'] ?? []);
if (!$linhas) echo "Nenhum token guardado. Conecte a Meta em Admin → Redes sociais.\n";
foreach ($linhas as $l) {
    echo '- guardado em ' . $l['atualizado_em'] . ' · validade: ' . ($l['expira_em'] ?: 'não informada') . "\n";
    [$c, $j] = g('/me', $l['access_token'], ['fields' => 'id,name']);
    echo '  /me → ' . veredito($c, $j) . (!empty($j['name']) ? ' (' . $j['name'] . ')' : '') . "\n";
    [$c, $j] = g('/me/permissions', $l['access_token']);
    if ($c === 200) {
        $ok = []; $no = [];
        foreach (($j['data'] ?? []) as $p) { if (($p['status'] ?? '') === 'granted') $ok[] = $p['permission']; else $no[] = $p['permission']; }
        echo '  permissões ativas: ' . ($ok ? implode(', ', $ok) : '(nenhuma)') . "\n";
        if ($no) echo '  permissões NEGADAS/removidas: ' . implode(', ', $no) . "\n";
    }
    [$c, $j] = g('/me/adaccounts', $l['access_token'], ['fields' => 'name,account_status', 'limit' => 5]);
    echo '  contas de anúncio → ' . veredito($c, $j) . ($c === 200 ? ' (' . count($j['data'] ?? []) . ' visíveis)' : '') . "\n";
}

echo "\n== CONTAS SOCIAIS (Instagram / Páginas) ==\n";
$ct = sd_supabase('/rest/v1/social_accounts?select=id,account_name,platform,external_account_id&limit=60');
foreach ((array)($ct['body'] ?? []) as $a) {
    $tk = sd_supabase('/rest/v1/social_account_tokens?social_account_id=eq.' . urlencode($a['id']) . '&select=access_token,updated_at');
    $t = $tk['body'][0] ?? null;
    if (!$t) { echo '- ' . $a['account_name'] . ' (' . $a['platform'] . '): SEM TOKEN → reconectar' . "\n"; continue; }
    [$c, $j] = g('/' . $a['external_account_id'], $t['access_token'], ['fields' => 'id,name']);
    echo '- ' . $a['account_name'] . ' (' . $a['platform'] . '), token de ' . substr((string)$t['updated_at'], 0, 10) . ': ' . veredito($c, $j) . "\n";
}
echo "\nConcluido.\n";
