<?php
// Lembretes pela plataforma — a mesma coisa que o zap cria ("me lembra toda segunda às 9h...").
// POST { acao: listar | criar | cancelar, ... }  (cada pessoa vê e mexe só nos seus)
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); $b = sd_body(); $acao = (string)($b['acao'] ?? 'listar');
if ($acao === 'listar') {
    $r = sd_supabase('/rest/v1/pd_lembretes?user_id=eq.' . urlencode($u['id']) . '&ativo=is.true&select=id,texto,proximo,recorrencia,hora,dow,dom,task_id,via&order=proximo.asc&limit=100');
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v130 no Supabase.', 500);
    echo json_encode(['ok' => true, 'lembretes' => $r['body']], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'criar') {
    $a = ['op' => 'criar', 'texto' => $b['texto'] ?? '', 'quando' => $b['quando'] ?? '', 'recorrencia' => $b['recorrencia'] ?? 'nenhuma', 'hora' => $b['hora'] ?? '',
        'dow' => $b['dow'] ?? null, 'dom' => $b['dom'] ?? null, 'task_id' => $b['task_id'] ?? '', 'via' => 'plataforma'];
    $m = pdZapLembrete($a, $u);
    if (strpos($m, '⏰') !== 0) sd_fail(trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $m)));
    echo json_encode(['ok' => true, 'msg' => $m], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'cancelar') {
    $m = pdZapLembrete(['op' => 'cancelar', 'id' => $b['id'] ?? 0], $u);
    echo json_encode(['ok' => strpos($m, '✅') === 0, 'msg' => $m], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
