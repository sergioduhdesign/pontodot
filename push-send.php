<?php
// =====================================================================
// SD Workflow — envio de Push (chamado pelo app)
// Exige sessão válida. Envia pra TODOS os aparelhos da pessoa
// (Web Push RFC 8291 + Beams) — a lógica mora em push-lib.php.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();

$body     = sd_body();
$toUserId = $body['to_user_id'] ?? '';
$title    = mb_substr(trim($body['title'] ?? 'Studio Design'), 0, 120);
$message  = mb_substr(trim($body['body'] ?? ''), 0, 300);
$url      = $body['url'] ?? '/app.html';

if (!$toUserId || !$message) sd_fail('Dados invalidos.');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $toUserId)) sd_fail('Destinatario invalido.');
if (!str_starts_with($url, '/')) $url = '/app.html';

$sent = sd_push_usuario($toUserId, $title, $message, $url, 'sd-msg');
echo json_encode(['success' => true, 'sent' => $sent]);
