<?php
require_once __DIR__ . '/sd-base.php';
// Wix na PD — só admin. {acao:"sites"} lista os sites da conta (chave da casa); {acao:"testar", client_id} testa o cliente.
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-site-lib.php';
require_once __DIR__ . '/pd-wix-lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin.', 403);
$d = sd_body(); header('Content-Type: application/json; charset=utf-8');
try {
    if (($d['acao'] ?? '') === 'sites') { echo json_encode(['ok' => true, 'sites' => pdWixSites()], JSON_UNESCAPED_UNICODE); exit; }
    if (($d['acao'] ?? '') === 'testar') {
        $cid = (string)($d['client_id'] ?? ''); if (!preg_match('/^[0-9a-fA-F-]{36}$/', $cid)) sd_fail('Cliente inválido.', 400);
        echo json_encode(['ok' => true] + pdWixTestar($cid), JSON_UNESCAPED_UNICODE); exit;
    }
} catch (Throwable $e) { sd_fail($e->getMessage(), 400); }
sd_fail('Ação desconhecida.', 400);
