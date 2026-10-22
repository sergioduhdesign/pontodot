<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — biblioteca de PUSH (servidor)
//
// Uma função só pra avisar alguém em TODOS os aparelhos dele:
//   sd_push_usuario($userId, $titulo, $corpo, $url)
//     · Web Push (VAPID) em cada assinatura salva (celular, notebook…)
//     · Pusher Beams (interest user-<id>), se configurado
//
// A criptografia segue a RFC 8291 (aes128gcm) à risca. A versão antiga
// derivava as chaves no formato do rascunho antigo: o Google/Apple
// aceitavam a mensagem, mas o aparelho não conseguia abrir — e o push
// sumia em silêncio.
//
// Requer sd-auth.php (sd_supabase) incluído antes.
// =====================================================================

if (!function_exists('sd_b64u')) {

function sd_b64u($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function sd_b64ud($d){ $p = strlen($d) % 4; if ($p) $d .= str_repeat('=', 4 - $p); return base64_decode(strtr($d, '-_', '+/')); }

// Assinatura DER (openssl) → R||S cru de 64 bytes (JWT ES256)
function sd_der2raw($der){
    $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl);
    $o = $o + 1 + $rl + 1; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

// Ponto P-256 cru (65 bytes, 0x04||X||Y) → chave pública PEM
function sd_p256_pem($raw){
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function sd_hkdf($salt, $ikm, $info, $len){
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

// RFC 8291 — devolve o corpo criptografado (aes128gcm) ou null
function sd_webpush_encrypt($payload, $p256dhB64, $authB64){
    $uaPub = sd_b64ud($p256dhB64);
    $auth  = sd_b64ud($authB64);
    if (strlen($uaPub) !== 65 || strlen($auth) < 16) return null;

    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) return null;
    $det = openssl_pkey_get_details($eph);
    $asPub = "\x04" . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $uaKey = openssl_pkey_get_public(sd_p256_pem($uaPub));
    if (!$uaKey) return null;
    $ecdh = openssl_pkey_derive($uaKey, $eph, 32);
    if ($ecdh === false) return null;

    $ikm   = sd_hkdf($auth, $ecdh, "WebPush: info\x00" . $uaPub . $asPub, 32);
    $salt  = random_bytes(16);
    $cek   = sd_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = sd_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) return null;
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

// Envia pra UMA assinatura. Devolve o código HTTP do serviço de push.
function sd_webpush_enviar($sub, $payloadJson){
    if (!defined('SD_VAPID_PUBLIC') || !defined('SD_VAPID_PRIVATE')) return 0;
    $endpoint = $sub['endpoint'] ?? '';
    $body = sd_webpush_encrypt($payloadJson, $sub['keys']['p256dh'] ?? '', $sub['keys']['auth'] ?? '');
    if (!$endpoint || !$body) return 0;

    $u = parse_url($endpoint);
    $h = sd_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = sd_b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 43200,
        'sub' => defined('SD_VAPID_SUBJECT') ? SD_VAPID_SUBJECT : 'mailto:workflow@sergioduh.com']));
    $pk = openssl_pkey_get_private(SD_VAPID_PRIVATE);
    if (!$pk || !openssl_sign("$h.$c", $sig, $pk, OPENSSL_ALGO_SHA256)) return 0;
    $jwt = "$h.$c." . sd_b64u(sd_der2raw($sig));

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . SD_VAPID_PUBLIC,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400', 'Urgency: high',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300 && $code !== 404 && $code !== 410) error_log("SD push: $code $resp");
    return $code;
}

// Avisa a pessoa em todos os aparelhos. Remove assinatura morta.
function sd_push_usuario($userId, $titulo, $corpo, $url = '/app.html', $tag = 'sd'){
    $enviados = 0;
    $payload = json_encode(['title' => mb_substr($titulo, 0, 120), 'body' => mb_substr($corpo, 0, 300),
        'url' => $url, 'tag' => $tag, 'icon' => '/assets/icon-192.png'], JSON_UNESCAPED_UNICODE);

    $r = sd_supabase('/rest/v1/push_subscriptions?user_id=eq.' . urlencode($userId) . '&select=id,subscription');
    foreach (($r['body'] ?? []) as $row) {
        $sub = is_array($row['subscription']) ? $row['subscription'] : json_decode($row['subscription'], true);
        if (!$sub) continue;
        $code = sd_webpush_enviar($sub, $payload);
        if ($code >= 200 && $code < 300) $enviados++;
        elseif ($code === 404 || $code === 410) sd_supabase('/rest/v1/push_subscriptions?id=eq.' . urlencode($row['id']), 'DELETE');
    }

    // Beams (quando configurado) — mesmo aviso pelo canal da Pusher
    if (defined('SD_BEAMS_INSTANCE_ID') && defined('SD_BEAMS_SECRET')) {
        $ch = curl_init('https://' . SD_BEAMS_INSTANCE_ID . '.pushnotifications.pusher.com/publish_api/v1/instances/'
            . SD_BEAMS_INSTANCE_ID . '/publishes');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . SD_BEAMS_SECRET],
            CURLOPT_POSTFIELDS => json_encode(['interests' => ['user-' . $userId], 'web' => ['notification' => [
                'title' => $titulo, 'body' => $corpo, 'icon' => sd_base() . '/assets/icon-192.png',
                'deep_link' => sd_base() . $url,
            ]]]),
        ]);
        curl_exec($ch); curl_close($ch);
    }
    return $enviados;
}

}
