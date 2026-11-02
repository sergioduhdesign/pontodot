<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — criação de usuário (convite por link mágico)
//
// Só ADMIN pode chamar. Corrige o bug histórico da linha 75:
// /auth/v1/admin/generate_link devolve o usuário no NÍVEL DE CIMA
// ($data['id']), não em $data['user']['id'] — por isso o profile
// nunca era criado.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Método não permitido', 405);

$me = sd_require_role(['admin']);

require_once __DIR__ . '/pd-usuarios-lib.php';
try { $r = pdUsuarioCriar(sd_body(), $me); } catch (Exception $e) { sd_fail($e->getMessage(), 400); }
$newUserId = $r['user_id']; $link = $r['link'];
echo json_encode(['success' => true, 'link' => $link, 'user_id' => $newUserId, 'permissoes' => $r['permissoes'] ?? ''], JSON_UNESCAPED_UNICODE);
