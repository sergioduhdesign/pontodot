<?php
// =====================================================================
// "Esqueci a senha" — pela NOSSA caixa de saída, não pelo SMTP do Supabase
// (que devolvia "Error sending recovery email"). Público, sem sessão.
// Sempre responde ok (não revela se o e-mail existe). Gera link recovery
// e manda o e-mail no padrão do convite.
// =====================================================================
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-usuarios-lib.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);
$b = sd_body(); $email = strtolower(trim((string)($b['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sd_fail('E-mail inválido.');
// freio: no máximo 3 pedidos por e-mail a cada 15 min
$n = count((array)(sd_supabase('/rest/v1/pd_avisos_log?canal=eq.email&para=eq.' . rawurlencode($email) . '&ref=eq.senha&criado_em=gte.' . rawurlencode(gmdate('c', time() - 900)) . '&select=id')['body'] ?? []));
if ($n >= 3) { echo json_encode(['ok' => true, 'aviso' => 'Já mandei o link há pouco. Olha o spam; dá pra pedir de novo daqui a 15 min.']); exit; }
$p = sd_supabase('/rest/v1/profiles?email=eq.' . rawurlencode($email) . '&select=id,name,active&limit=1')['body'][0] ?? null;
if ($p && ($p['active'] ?? true) !== false) {
    $r = pdConviteLink($email, (string)($p['name'] ?: 'você'), 'senha');
    try { sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => 'email', 'para' => $email, 'quem' => $p['name'], 'corpo' => 'senha nova: ' . (!empty($r['link']) ? (!empty($r['email_enviado']) ? 'e-mail enviado' : 'e-mail NÃO saiu') : 'erro: ' . ($r['erro'] ?? '?')), 'status' => !empty($r['email_enviado']) ? 'enviado' : 'erro', 'origem' => 'sistema', 'direcao' => 'saida', 'ref' => 'senha']); } catch (Throwable $e) {}
}
echo json_encode(['ok' => true]);
