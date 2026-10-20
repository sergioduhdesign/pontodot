<?php
// =====================================================================
// Pontodot — O WHATSAPP AVISA: entregou? leu? falhou?
//   GET  = a Meta conferindo o endereço (uma vez, na configuração)
//   POST = os status de cada mensagem enviada (sent/delivered/read/failed)
// sd-config.php:
//   define('SD_ZAP_VERIFY', 'uma-senha-que-voce-inventa');   // a mesma vai no painel da Meta
//   define('SD_ZAP_APP_SECRET', '...');   // opcional: segredo do app do WhatsApp (se não for o mesmo SD_META_APP_SECRET)
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $tok = (string)($_GET['hub_verify_token'] ?? ''); $des = (string)($_GET['hub_challenge'] ?? '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && defined('SD_ZAP_VERIFY') && SD_ZAP_VERIFY !== ''
        && hash_equals(SD_ZAP_VERIFY, $tok) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $des)) { header('Content-Type: text/plain'); echo $des; exit; }
    http_response_code(403); echo 'nao'; exit;
}

$cru = file_get_contents('php://input');
$segs = array_values(array_filter([defined('SD_ZAP_APP_SECRET') ? SD_ZAP_APP_SECRET : '', defined('SD_META_APP_SECRET') ? SD_META_APP_SECRET : '']));
$assin = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$vale = false;
foreach ($segs as $seg) if ($assin && hash_equals('sha256=' . hash_hmac('sha256', (string)$cru, $seg), $assin)) { $vale = true; break; }
if (!$vale) { error_log('wa-webhook: assinatura não confere (confira SD_ZAP_APP_SECRET = segredo do app do WhatsApp)'); http_response_code(403); echo 'assinatura'; exit; }
http_response_code(200); header('Content-Type: application/json'); echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$j = json_decode((string)$cru, true);
if (!is_array($j)) exit;
foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $c) {
    // ---- mensagens que CHEGAM (alguém respondeu o número da plataforma) ----
    $nomes = [];
    foreach ((array)($c['value']['contacts'] ?? []) as $ct) $nomes[preg_replace('/\D/', '', (string)($ct['wa_id'] ?? ''))] = $ct['profile']['name'] ?? null;
    foreach ((array)($c['value']['messages'] ?? []) as $m) {
        $id = (string)($m['id'] ?? ''); $de = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
        if ($de === '' && !empty($m['from_user_id'])) $de = (string)$m['from_user_id'];   // sem telefone (nome de usuário): guarda pelo ID e não responde
        if ($id === '' || $de === '') continue;
        $ja = sd_supabase('/rest/v1/pd_avisos_log?wamid=eq.' . rawurlencode($id) . '&select=id&limit=1');
        if (!empty($ja['body'])) continue;                                   // a Meta reenvia: não duplica
        $t = $m['type'] ?? 'text';
        $txt = $t === 'text' ? ($m['text']['body'] ?? '')
             : ($t === 'button' ? ($m['button']['text'] ?? '')
             : ($t === 'interactive' ? ($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '')) : ''));
        if ($txt === '') $txt = '[' . ($t === 'image' ? 'imagem' : ($t === 'audio' ? 'áudio' : ($t === 'document' ? 'documento' : ($t === 'video' ? 'vídeo' : $t)))) . ']' . (!empty($m[$t]['caption']) ? ' ' . $m[$t]['caption'] : '');
        $para = ctype_digit($de) ? pdZapNormaliza($de) : $de;
        // quem é? (por número: equipe ou cliente)
        $quem = $nomes[$de] ?? null; $cid = null; $membro = null;
        foreach ((array)(sd_supabase('/rest/v1/profiles?zap=not.is.null&active=eq.true&select=id,name,zap,team_key,role')['body'] ?? []) as $p) if (pdZapNormaliza(preg_replace('/\D/', '', (string)$p['zap'])) === $para) { $quem = $p['name']; $membro = $p; break; }
        if (!$cid) foreach ((array)(sd_supabase('/rest/v1/clients?zap=not.is.null&select=id,name,zap')['body'] ?? []) as $cl) if (pdZapNormaliza(preg_replace('/\D/', '', (string)$cl['zap'])) === $para) { $quem = $cl['name']; $cid = $cl['id']; break; }
        sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => 'whatsapp', 'para' => $para, 'quem' => $quem, 'corpo' => mb_substr($txt, 0, 2000),
            'status' => 'recebida', 'origem' => 'entrada', 'direcao' => 'entrada', 'wamid' => $id, 'client_id' => $cid]);
        try { pdAlarme('outro', 'WhatsApp de ' . ($quem ?: '+' . $para), mb_substr($txt, 0, 300), null, 'avisos:' . preg_replace('/\D/', '', $para), true); } catch (Throwable $e) {}
        // quem é da equipe (número cadastrado, não cliente) conversa com a IA da casa
        if ($membro && !$cid && ($membro['role'] ?? '') !== 'client') { @set_time_limit(120); try { pdIaZapResponder($membro, $para, $txt, $t === 'text' || $t === 'button' || $t === 'interactive' ? 'text' : $t); } catch (Throwable $e) { error_log('ia-zap: ' . $e->getMessage()); } }
    }
    foreach ((array)($c['value']['statuses'] ?? []) as $s) {
        $id = (string)($s['id'] ?? ''); $st = (string)($s['status'] ?? ''); if ($id === '' || $st === '') continue;
        $patch = ['entrega' => $st];
        if ($st === 'failed') {
            $er = $s['errors'][0] ?? [];
            $patch['status'] = 'erro';
            $patch['erro'] = mb_substr(trim(($er['code'] ?? '') . ' ' . ($er['title'] ?? '') . ' ' . ($er['message'] ?? ($er['error_data']['details'] ?? ''))), 0, 300);
        }
        sd_supabase('/rest/v1/pd_avisos_log?wamid=eq.' . rawurlencode($id), 'PATCH', $patch);
    }
}
