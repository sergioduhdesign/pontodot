<?php
// Habilitar / desabilitar o acesso de uma pessoa (admin). Não apaga nada:
// desligado = não entra e não recebe sessão nova; ligado = volta como estava.
require_once __DIR__ . '/sd-auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);
$me = sd_require_role(['admin']);
$b = sd_body(); $uid = (string)($b['user_id'] ?? ''); $ativo = !empty($b['ativo']);
if ($uid === '') sd_fail('user_id obrigatório.');
if ($uid === $me['id'] && !$ativo) sd_fail('Você não pode desligar o próprio acesso.', 403);
$r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid), 'PATCH', ['active' => $ativo]);
if (($r['code'] ?? 500) >= 300) sd_fail('Não consegui salvar (HTTP ' . ($r['code'] ?? '?') . ').', 500);
// trava (ou destrava) também no login do Supabase: sem sessão nova, sem renovar a atual
$g = sd_supabase('/auth/v1/admin/users/' . urlencode($uid), 'PUT', ['ban_duration' => $ativo ? 'none' : '876000h']);
echo json_encode(['ok' => true, 'ativo' => $ativo, 'auth' => ($g['code'] ?? 0) < 300], JSON_UNESCAPED_UNICODE);
