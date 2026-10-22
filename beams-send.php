<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — disparo via Pusher Beams
// Exige sessão válida. Só equipe/admin dispara para o grupo.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_current_user();

$body     = sd_body();
$interest = $body['interest'] ?? '';
$title    = mb_substr(trim($body['title'] ?? 'Pontodot'), 0, 120);
$message  = mb_substr(trim($body['body'] ?? ''), 0, 300);
$url      = $body['url'] ?? '/app.html';

if (!$interest || !$message) sd_fail('Dados inválidos.');

// Interesse só pode ser 'user-<uuid>' ou 'group-sd'
if (!preg_match('/^(user-[0-9a-fA-F-]{36}|group-sd)$/', $interest)) {
    sd_fail('Interesse inválido.');
}

// Cliente não dispara para o grupo da equipe
if ($interest === 'group-sd' && !in_array($me['role'], ['admin','team'], true)) {
    sd_fail('Sem permissão para notificar a equipe.', 403);
}

// URL de destino só dentro do próprio domínio
$deepLink = sd_base() . (str_starts_with($url, '/') ? $url : '/app.html');

$payload = json_encode([
    'interests' => [$interest],
    'web' => [
        'notification' => [
            'title'     => $title,
            'body'      => $message,
            'icon'      => sd_base() . '/assets/icon-pwa.svg',
            'deep_link' => $deepLink,
        ],
    ],
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://' . SD_BEAMS_INSTANCE_ID
                 . '.pushnotifications.pusher.com/publish_api/v1/instances/'
                 . SD_BEAMS_INSTANCE_ID . '/publishes',
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . SD_BEAMS_SECRET,
    ],
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) error_log('SD beams-send: ' . $code . ' ' . $res);

echo json_encode(['success' => $code === 200]);
