<?php
// =====================================================================
// SD Workflow — registrar assinatura de Web Push
// O user_id vem da SESSÃO, nunca do corpo da requisição.
// Uma pessoa pode ter VÁRIOS aparelhos (celular, notebook, desktop):
// cada assinatura é única pelo endpoint, não pelo usuário.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_current_user();

$body         = sd_body();
$subscription = $body['subscription'] ?? null;

if (!$subscription || empty($subscription['endpoint'])) {
    sd_fail('Assinatura inválida.');
}

$r = sd_supabase('/rest/v1/push_subscriptions?on_conflict=endpoint', 'POST', [
    'user_id'      => $me['id'],
    'endpoint'     => $subscription['endpoint'],
    'subscription' => json_encode($subscription),
    'updated_at'   => date('c'),
], ['Prefer: resolution=merge-duplicates']);

if ($r['code'] >= 300) {
    error_log('SD push-subscribe: ' . $r['raw']);
    sd_fail('Não foi possível salvar a assinatura (rodou a migration_push.sql?).', 502);
}

// Teste: manda um push de boas-vindas pro próprio aparelho
if (!empty($body['testar'])) {
    sd_push_usuario($me['id'], '🔔 Avisos ligados', 'Este aparelho vai receber os avisos da pontodot.', '/app.html', 'sd-teste');
}

echo json_encode(['success' => true]);
