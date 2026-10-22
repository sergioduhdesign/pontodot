<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// Pontodot — SITES DE CLIENTES (o que o painel chama)
//
//   POST {acao:"testar",   client_id}  -> confere a conexão com o WP
//   POST {acao:"publicar", client_id, item_id} -> empurra UMA peça de
//        blog aprovada agora, sem esperar a volta do relógio
//   GET  ?feed=<client_id>&k=<assinatura>  -> capa + últimos posts
//        (é o que o "bloco embutido" de um site HTML qualquer consome)
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-site-lib.php';

// --------------------------------------------------- feed público (bloco)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !empty($_GET['feed'])) {
    $cid = preg_replace('/[^0-9a-fA-F-]/', '', (string)$_GET['feed']);
    $k = (string)($_GET['k'] ?? '');
    $esperada = substr(hash_hmac('sha256', 'feed:' . $cid, SD_SECRET_KEY), 0, 24);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=600');
    if (!hash_equals($esperada, $k)) { http_response_code(403); echo '{"erro":"assinatura"}'; exit; }
    $r = sd_supabase('/rest/v1/pd_site_posts?client_id=eq.' . urlencode($cid)
        . '&status=eq.publicado&select=item_id,url,publicado_em&order=publicado_em.desc&limit=12');
    $itens = [];
    $ids = array_map(fn($x) => $x['item_id'], (array)($r['body'] ?? []));
    if ($ids) {
        $it = sd_supabase('/rest/v1/approval_items?id=in.(' . implode(',', $ids) . ')&select=id,kind,title,caption,media');
        $porId = [];
        foreach ((array)($it['body'] ?? []) as $x) $porId[$x['id']] = $x;
        foreach ((array)$r['body'] as $p) {
            $x = $porId[$p['item_id']] ?? null;
            if (!$x) continue;
            $media = is_array($x['media'] ?? null) ? $x['media'] : json_decode((string)($x['media'] ?? ''), true);
            $img = null;
            foreach ((array)$media as $m) if (is_array($m) && !empty($m['thumb'] ?? $m['url'])) { $img = $m['thumb'] ?? $m['url']; break; }
            if ($img && $img[0] === '/') $img = sd_base() . $img;
            $itens[] = ['titulo' => $x['title'], 'resumo' => mb_substr(trim((string)($x['caption'] ?? '')), 0, 200),
                'imagem' => $img, 'url' => $p['url'], 'em' => $p['publicado_em'], 'capa' => ($x['kind'] ?? '') === 'capa_site'];
        }
    }
    echo json_encode(['posts' => $itens], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------- ações da equipe
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (!in_array($me['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$d = sd_body();
$cid = (string)($d['client_id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $cid)) sd_fail('Cliente inválido.', 400);
header('Content-Type: application/json; charset=utf-8');

try {
    if (($d['acao'] ?? '') === 'testar') {
        $r = pdWPTestar($cid);
        // de brinde: o endereço do feed assinado pro "bloco embutido"
        $r['feed'] = '/pd-sites.php?feed=' . $cid . '&k=' . substr(hash_hmac('sha256', 'feed:' . $cid, SD_SECRET_KEY), 0, 24);
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($d['acao'] ?? '') === 'publicar') {
        $itemId = (string)($d['item_id'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $itemId)) sd_fail('Peça inválida.', 400);
        $it = sd_supabase('/rest/v1/approval_items?id=eq.' . urlencode($itemId) . '&select=id,status,title,caption,hashtags,media');
        $x = $it['body'][0] ?? null;
        if (!$x || $x['status'] !== 'aprovado') sd_fail('Só peça APROVADA vai pro site.', 400);
        $media = is_array($x['media'] ?? null) ? $x['media'] : json_decode((string)($x['media'] ?? ''), true);
        $img = null;
        foreach ((array)$media as $m) if (is_array($m) && !empty($m['url'])) { $img = $m['url']; break; }
        $r = pdWPPublicar($cid, ['titulo' => $x['title'] ?: 'Post', 'slug' => null,
            'html' => pdTextoHtml(trim(($x['caption'] ?? '') . "\n\n" . ($x['hashtags'] ?? ''))),
            'resumo' => mb_substr(trim((string)($x['caption'] ?? '')), 0, 180), 'imagem_url' => $img]);
        sd_supabase('/rest/v1/pd_site_posts?on_conflict=item_id', 'POST', ['item_id' => $itemId, 'client_id' => $cid,
            'destino' => 'wp', 'external_id' => $r['id'], 'url' => $r['url'], 'status' => 'publicado',
            'erro' => null, 'publicado_em' => date('c')], ['Prefer: resolution=merge-duplicates']);
        echo json_encode(['ok' => true, 'url' => $r['url']], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    sd_fail($e->getMessage(), 502);
}
sd_fail('Ação desconhecida.', 400);
