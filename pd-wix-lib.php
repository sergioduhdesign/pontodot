<?php
// =====================================================================
// Pontodot — WIX (API REST). Chave da CASA (uma vez, sem cliente) + ID do site por cliente.
//   cofre da casa:   servico=wix, client_id nulo,  usuario = ID da conta Wix, segredo = chave de API
//   cofre do cliente: servico=wix, usuario = ID do site, segredo = chave própria OU "@casa" (usa a da casa)
// =====================================================================
function pdWixCasa() {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=is.null&servico=eq.wix&select=usuario,segredo');
    $c = $r['body'][0] ?? null;
    if (!$c || empty($c['segredo'])) throw new Exception('Falta a chave do Wix da casa (Painel → HostGator/Wix da casa → Wix).');
    return ['conta' => (string)($c['usuario'] ?? ''), 'chave' => $c['segredo']];
}
/** Credencial do Wix de um cliente: ['chave','site','conta']. */
function pdWixCred($clientId) {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=eq.' . urlencode($clientId) . '&servico=eq.wix&select=usuario,segredo');
    $c = $r['body'][0] ?? null;
    if (!$c || empty($c['usuario'])) throw new Exception('Este cliente não tem o ID do site Wix no cofre (Canais → Chave do Wix).');
    $casa = null; $chave = $c['segredo'] ?? '';
    if ($chave === '' || $chave === '@casa') { $casa = pdWixCasa(); $chave = $casa['chave']; }
    return ['chave' => $chave, 'site' => trim($c['usuario']), 'conta' => $casa['conta'] ?? ''];
}
function pdWix($chave, $hdr, $metodo, $caminho, $corpo = null) {
    $ch = curl_init('https://www.wixapis.com' . $caminho);
    $h = ['Authorization: ' . $chave, 'Content-Type: application/json'];
    foreach ($hdr as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $h]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($corpo) ? $corpo : json_encode($corpo));
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$out, true) ?: []];
}
function pdWixErro($r) {
    $m = $r['body']['message'] ?? ($r['body']['details']['applicationError']['description'] ?? '');
    return 'Wix respondeu ' . $r['code'] . ($m ? ': ' . mb_substr((string)$m, 0, 200) : '') . ($r['code'] === 401 || $r['code'] === 403 ? ' (confira a chave e as permissões dela)' : '');
}
/** Todos os sites da conta Wix (chave da casa). */
function pdWixSites() {
    $c = pdWixCasa(); if ($c['conta'] === '') throw new Exception('Falta o ID da conta Wix no cofre da casa.');
    $r = pdWix($c['chave'], ['wix-account-id' => $c['conta']], 'POST', '/site-list/v2/sites/query', ['query' => ['paging' => ['limit' => 100]]]);
    if ($r['code'] !== 200) throw new Exception(pdWixErro($r));
    return array_map(fn($s) => ['id' => $s['id'] ?? '', 'nome' => $s['displayName'] ?? '', 'url' => $s['viewUrl'] ?? ($s['editUrl'] ?? '')], (array)($r['body']['sites'] ?? []));
}
function pdWixTestar($clientId) {
    $c = pdWixCred($clientId);
    $r = pdWix($c['chave'], ['wix-site-id' => $c['site']], 'GET', '/site-properties/v4/properties');
    if ($r['code'] !== 200) throw new Exception(pdWixErro($r));
    $p = $r['body']['properties'] ?? [];
    return ['usuario' => 'Wix · ' . ($p['siteDisplayName'] ?? $c['site'])];
}
