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

if ($acao === 'quem') { echo json_encode(['ok' => true] + pdSeoQuem(), $J); exit; }
if ($acao === 'plugin') { echo json_encode(['ok' => true, 'nome' => 'pontodot-seo.php', 'fonte' => pdSeoPluginFonte()], $J); exit; }

if ($acao === 'agenda_ler') { echo json_encode(['ok' => true, 'agenda' => pdSeoAgenda()], $J); exit; }
if ($acao === 'agenda_gravar') {
    $dias = min(90, max(1, (int)($d['dias'] ?? 7))); $ativo = !empty($d['ativo']);
    $r = sd_supabase('/rest/v1/app_settings?id=eq.1', 'PATCH', ['seo_agenda' => ['ativo' => $ativo, 'dias' => $dias]]);
    if (($r['code'] ?? 500) >= 300) sd_fail('Não gravou a agenda (rode a migration v178).', 500);
    echo json_encode(['ok' => true, 'agenda' => ['ativo' => $ativo, 'dias' => $dias]], $J); exit;
}

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
    if ($acao === 'plugin_status') {   // o plugin de SEO está no ar neste site? (pergunta de fora, como o Google)
        $site = pdSiteCfg($cid); $base = rtrim((string)($site['url'] ?? ''), '/'); $ok = null;
        if ($base !== '') foreach (['/wp-json/pontodot/v1/ping', '/?rest_route=/pontodot/v1/ping'] as $rota) {
            $ch = curl_init($base . $rota); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Pontodot-SEO']);
            $b = curl_exec($ch); curl_close($ch); $j = json_decode((string)$b, true);
            if (is_array($j) && !empty($j['ok'])) { $ok = $j; break; }
        }
        echo json_encode(['ok' => true, 'ativo' => (bool)$ok, 'versao' => $ok['versao'] ?? null], $J); exit;
    }
    if ($acao === 'instalar_plugin') { echo json_encode(['ok' => true] + pdCPInstalarSeo($cid), $J); exit; }
    if ($acao === 'testar_hostgator') { echo json_encode(['ok' => true] + pdCPTestar($cid), $J); exit; }
    if ($acao === 'manual') {
        $x = pdSeoManual($cid, (string)($d['tipo'] ?? 'post'), (int)($d['wp_id'] ?? 0), (string)($d['url'] ?? ''), (string)($d['campo'] ?? ''), isset($d['alvo']) ? (string)$d['alvo'] : null, (string)($d['antes'] ?? ''), (string)($d['depois'] ?? ''), $me['id'] ?? null);
        echo json_encode(['ok' => true, 'proposta' => $x], $J); exit;
    }
    if ($acao === 'previa') {
        $url = (string)($d['url'] ?? ''); if (!preg_match('#^https?://#', $url)) sd_fail('Endereço inválido.', 400);
        $alts = []; foreach ((array)($d['alts'] ?? []) as $k => $v) $alts[(string)(int)$k] = (string)$v;
        echo json_encode(['ok' => true, 'html' => pdSeoPrevia($url, trim((string)($d['titulo_seo'] ?? '')), trim((string)($d['meta'] ?? '')), $alts)], $J); exit;
    }
    if ($acao === 'ultima') {
        $a = sd_supabase('/rest/v1/pd_seo_auditorias?client_id=eq.' . urlencode($cid) . '&select=id,nota,plugin,resumo,itens,criado_em&order=criado_em.desc&limit=1')['body'][0] ?? null;
        echo json_encode(['ok' => true, 'auditoria' => $a], $J); exit;
    }
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
