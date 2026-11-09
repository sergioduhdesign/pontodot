<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// Pontodot — SEO DOS SITES (o que o painel chama). Só a equipe.
//   POST {acao:"auditar",  client_id, limite?}
//   POST {acao:"sugerir",  client_id, tipo:post|page, wp_id, url}   -> propostas (nada é gravado no site)
//   POST {acao:"aplicar",  client_id, ids:[...], edicoes?:{id:texto}} -> grava no WP só o que foi aprovado
//   POST {acao:"reverter", client_id, id}
//   POST {acao:"recusar",  client_id, ids:[...]}
//   POST {acao:"historico",client_id}    -> últimas auditorias e propostas
//   POST {acao:"plugin"}                 -> fonte do plugin auxiliar (mu-plugin)
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-seo-lib.php';
require_once __DIR__ . '/pd-hostgator-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$d = sd_body();
$acao = (string)($d['acao'] ?? '');
header('Content-Type: application/json; charset=utf-8');
$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if ($acao === 'plugin') { echo json_encode(['ok' => true, 'nome' => 'pontodot-seo.php', 'fonte' => pdSeoPluginFonte()], $J); exit; }

$cid = (string)($d['client_id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $cid)) sd_fail('Cliente inválido.', 400);

try {
    if ($acao === 'auditar') { echo json_encode(['ok' => true] + pdSeoAuditar($cid, min(120, max(5, (int)($d['limite'] ?? 60)))), $J); exit; }
    if ($acao === 'sugerir') {
        $tipo = ($d['tipo'] ?? '') === 'page' || ($d['tipo'] ?? '') === 'home' ? 'page' : 'post';
        $url = (string)($d['url'] ?? ''); if (!preg_match('#^https?://#', $url)) sd_fail('Endereço inválido.', 400);
        $p = pdSeoSugerir($cid, $tipo, (int)($d['wp_id'] ?? 0), $url, $me['id'] ?? null);
        echo json_encode(['ok' => true, 'propostas' => $p], $J); exit;
    }
    if ($acao === 'aplicar') {
        $ids = array_slice((array)($d['ids'] ?? []), 0, 60);
        echo json_encode(['ok' => true, 'resultado' => pdSeoAplicar($cid, $ids, (array)($d['edicoes'] ?? []))], $J); exit;
    }
    if ($acao === 'instalar_plugin') { echo json_encode(['ok' => true] + pdCPInstalarSeo($cid), $J); exit; }
    if ($acao === 'testar_hostgator') { echo json_encode(['ok' => true] + pdCPTestar($cid), $J); exit; }
    if ($acao === 'reverter') { pdSeoReverter($cid, (string)($d['id'] ?? '')); echo json_encode(['ok' => true]); exit; }
    if ($acao === 'recusar') {
        foreach (array_slice((array)($d['ids'] ?? []), 0, 100) as $id) {
            $id = preg_replace('/[^0-9a-fA-F-]/', '', (string)$id);
            sd_supabase('/rest/v1/pd_seo_propostas?id=eq.' . urlencode($id) . '&client_id=eq.' . urlencode($cid) . '&status=eq.proposta', 'PATCH', ['status' => 'recusada']);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($acao === 'historico') {
        $a = sd_supabase('/rest/v1/pd_seo_auditorias?client_id=eq.' . urlencode($cid) . '&select=id,nota,plugin,resumo,criado_em&order=criado_em.desc&limit=10')['body'] ?? [];
        $p = sd_supabase('/rest/v1/pd_seo_propostas?client_id=eq.' . urlencode($cid) . '&select=*&order=criado_em.desc&limit=200')['body'] ?? [];
        echo json_encode(['ok' => true, 'auditorias' => $a, 'propostas' => $p], $J); exit;
    }
} catch (Exception $e) {
    sd_fail($e->getMessage(), 502);
}
sd_fail('Ação desconhecida.', 400);
