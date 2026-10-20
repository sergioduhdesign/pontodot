<?php
// =====================================================================
// Pontodot — ENVIO IMEDIATO de WhatsApp (fura a fila)
//   POST /pd-zap-agora.php   (Authorization: Bearer <sessão>)
//   { "texto": "...", "user_id": "..." | "client_id": "..." | "para": "5511..." , "task_id": "..." }
// Só equipe (não cliente). Manda na hora, registra no histórico de avisos e,
// se for pra alguém da equipe, também por e-mail (regra da casa).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');

$u = sd_current_user();
if (($u['role'] ?? '') === 'client') sd_fail('Sem permissão.', 403);
if (!(defined('SD_ZAP_TOKEN') && SD_ZAP_TOKEN && defined('SD_ZAP_PHONE_ID') && SD_ZAP_PHONE_ID)) sd_fail('WhatsApp não configurado no servidor.', 500);

$b = sd_body();
$texto = trim((string)($b['texto'] ?? ''));
if ($texto === '') sd_fail('Escreva a mensagem.');
$texto = mb_substr($texto, 0, 900);

// limite de segurança: 60 envios manuais por hora no total
$h = sd_supabase('/rest/v1/pd_avisos_log?origem=eq.manual&criado_em=gte.' . rawurlencode(gmdate('c', time() - 3600)) . '&select=id');
if (($h['code'] ?? 500) < 300 && count((array)$h['body']) >= 60) sd_fail('Muitos envios na última hora. Tente mais tarde.', 429);

$para = ''; $quem = null; $clientId = null;
if (!empty($b['user_id'])) {
    $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($b['user_id']) . '&select=name,zap');
    $para = preg_replace('/\D/', '', (string)($r['body'][0]['zap'] ?? '')); $quem = $r['body'][0]['name'] ?? null;
    if ($para === '') sd_fail('Essa pessoa não tem número de WhatsApp no perfil.');
} elseif (!empty($b['client_id'])) {
    $r = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($b['client_id']) . '&select=name,zap');
    $para = preg_replace('/\D/', '', (string)($r['body'][0]['zap'] ?? '')); $quem = $r['body'][0]['name'] ?? null; $clientId = $b['client_id'];
    if ($para === '') sd_fail('Esse cliente não tem número de WhatsApp cadastrado.');
} else {
    $para = preg_replace('/\D/', '', (string)($b['para'] ?? ''));
    if (strlen($para) < 10) sd_fail('Número inválido.');
}

// se a pessoa escreveu nas últimas 23 h, a janela do WhatsApp está aberta: vai texto livre (sem template)
$livre = false;
$in = sd_supabase('/rest/v1/pd_avisos_log?direcao=eq.entrada&para=eq.' . rawurlencode(pdZapNormaliza($para)) . '&criado_em=gte.' . rawurlencode(gmdate('c', time() - 23 * 3600)) . '&select=id&limit=1');
if (!empty($in['body'])) $livre = true;
[$code, $raw] = pdZapEnviar($para, $texto, $livre);
$ok = $code === 200;
$ref = 'm' . bin2hex(random_bytes(5));
$erro = $ok ? null : 'HTTP ' . $code . ' ' . mb_substr((string)$raw, 0, 200);
sd_supabase('/rest/v1/pd_zap', 'POST', ['para' => $para, 'quem' => $quem, 'corpo' => $texto, 'client_id' => $clientId,
    'task_id' => !empty($b['task_id']) ? $b['task_id'] : null, 'status' => $ok ? 'enviado' : 'erro',
    'enviado_em' => $ok ? date('c') : null, 'erro' => $erro, 'tentativas' => 1]);
pdAvisoLog('whatsapp', $para, $quem, null, $texto, $ok ? 'enviado' : 'erro', $erro, 'manual', $ref, $clientId, $ok ? pdWamid($raw) : null);
if (!$clientId) pdEmailEspelho($para, $texto, $ref);   // regra: zap da equipe também vai por e-mail
if ($ok && !empty($b['task_id'])) pdEvento($b['task_id'], 'zap', 'Zap enviado agora', ($quem ?: $para) . ': ' . mb_substr($texto, 0, 120), $u['name'] ?? 'Equipe', 'pessoa');
echo json_encode(['ok' => $ok, 'erro' => $erro, 'para' => $para, 'quem' => $quem]);
