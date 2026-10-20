<?php
// Diagnóstico/inscrição do webhook do WhatsApp (uma vez). Abra no navegador:
//   https://sd.sergioduh.com/pd-zap-inscrever.php
// Pede a senha SD_ZAP_VERIFY (a mesma do webhook). Usa o token do servidor (SD_ZAP_TOKEN):
// lê as contas inscritas na conta do WhatsApp (WABA) e, se o app não estiver, inscreve.
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: text/html; charset=utf-8');
$waba = '2626136097846397';
$ok = false; $out = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = defined('SD_ZAP_VERIFY') && SD_ZAP_VERIFY !== '' && hash_equals(trim(SD_ZAP_VERIFY), trim((string)($_POST['senha'] ?? '')));
    if (!$ok) {
        $n = defined('SD_ZAP_VERIFY') ? strlen(trim(SD_ZAP_VERIFY)) : 0;
        $out = 'Senha não confere.' . "\n" . ($n ? "No servidor a SD_ZAP_VERIFY existe e tem $n caracteres." : 'No servidor a SD_ZAP_VERIFY NÃO está definida no sd-config.')
             . "\nVocê digitou " . strlen(trim((string)($_POST['senha'] ?? ''))) . ' caracteres.';
    }
    elseif (!defined('SD_ZAP_TOKEN') || !SD_ZAP_TOKEN) { $out = 'SD_ZAP_TOKEN não configurado.'; }
    else {
        $g = function ($metodo, $path) {
            $ch = curl_init('https://graph.facebook.com/v21.0/' . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $metodo,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_ZAP_TOKEN]]);
            $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return "[$metodo $path] HTTP $c\n" . $r . "\n\n";
        };
        $out .= $g('GET', $waba . '?fields=id,name');
        $out .= $g('GET', $waba . '/phone_numbers?fields=display_phone_number,verified_name,id');
        $out .= $g('GET', $waba . '/subscribed_apps');
        if (($_POST['acao'] ?? '') === 'inscrever') $out .= $g('POST', $waba . '/subscribed_apps') . $g('GET', $waba . '/subscribed_apps');
    }
}
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<body style="font:15px system-ui;max-width:640px;margin:30px auto;padding:0 16px">
<h3>WhatsApp · inscrever webhook</h3>
<form method="post">
<p><input type="text" placeholder="senha do webhook (SD_ZAP_VERIFY)" style="width:100%;padding:8px" autocomplete="off" name="senha" data-lpignore="true"></p>
<button name="acao" value="ver" style="padding:8px 14px">Só olhar</button>
<button name="acao" value="inscrever" style="padding:8px 14px">Olhar e inscrever</button>
</form>
<pre style="white-space:pre-wrap;word-break:break-all;background:#f4f4f4;padding:12px"><?= htmlspecialchars($out) ?></pre>
