<?php
// =====================================================================
// Pontodot — O WHATSAPP AVISA: entregou? leu? falhou?
//   GET  = a Meta conferindo o endereço (uma vez, na configuração)
//   POST = os status de cada mensagem enviada (sent/delivered/read/failed)
// sd-config.php:
//   define('SD_ZAP_VERIFY', 'uma-senha-que-voce-inventa');   // a mesma vai no painel da Meta
//   define('SD_ZAP_APP_SECRET', '...');   // opcional: segredo do app do WhatsApp (se não for o mesmo SD_META_APP_SECRET)
// =====================================================================
require_once __DIR__ . '/sd-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $tok = (string)($_GET['hub_verify_token'] ?? ''); $des = (string)($_GET['hub_challenge'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && defined('SD_ZAP_VERIFY') && SD_ZAP_VERIFY !== ''
        && hash_equals(SD_ZAP_VERIFY, $tok) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $des)) { header('Content-Type: text/plain'); echo $des; exit; }
    http_response_code(403); echo 'nao'; exit;
}

$cru = file_get_contents('php://input');
$seg = defined('SD_ZAP_APP_SECRET') ? SD_ZAP_APP_SECRET : (defined('SD_META_APP_SECRET') ? SD_META_APP_SECRET : '');
$assin = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($seg === '' || !$assin || !hash_equals('sha256=' . hash_hmac('sha256', (string)$cru, $seg), $assin)) { http_response_code(403); echo 'assinatura'; exit; }
http_response_code(200); header('Content-Type: application/json'); echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$j = json_decode((string)$cru, true);
if (!is_array($j)) exit;
foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $c) {
    foreach ((array)($c['value']['statuses'] ?? []) as $s) {
        $id = (string)($s['id'] ?? ''); $st = (string)($s['status'] ?? ''); if ($id === '' || $st === '') continue;
        $patch = ['entrega' => $st];
        if ($st === 'failed') {
            $er = $s['errors'][0] ?? [];
            $patch['status'] = 'erro';
            $patch['erro'] = mb_substr(trim(($er['code'] ?? '') . ' ' . ($er['title'] ?? '') . ' ' . ($er['message'] ?? ($er['error_data']['details'] ?? ''))), 0, 300);
        }
        sd_supabase('/rest/v1/pd_avisos_log?wamid=eq.' . rawurlencode($id), 'PATCH', $patch);
    }
}
