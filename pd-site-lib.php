<?php
// =====================================================================
// Pontodot — CONECTOR DE SITE DO CLIENTE (lado do servidor)
//
// O blog e a capa do cliente passam pelo MESMO caminho do social:
// o GPT produz → vira peça no lote → aprova → ESTE conector publica.
//
//   wp    — WordPress do cliente, pela API oficial (senha de aplicativo
//           guardada no cofre; o navegador nunca vê).
//   bloco — um trecho que o site (qualquer site) cola uma vez e que
//           puxa capa + últimos posts do endpoint assinado.
//
// Requer: sd_supabase (sd-auth.php). As credenciais vêm de pd_credenciais.
// =====================================================================

if (!function_exists('pdSiteCfg')) {

function pdSiteCfg($clientId) {
    $r = sd_supabase('/rest/v1/pd_sites?client_id=eq.' . urlencode($clientId) . '&select=*');
    return $r['body'][0] ?? null;
}
function pdCred($clientId, $servico) {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=eq.' . urlencode($clientId)
        . '&servico=eq.' . urlencode($servico) . '&select=usuario,segredo');
    return $r['body'][0] ?? null;
}

// ---------------------------------------------------------- WordPress
function pdWP($site, $cred, $method, $path, $body = null, $arquivo = null) {
    $base = rtrim((string)($site['url'] ?? ''), '/');
    if (!preg_match('#^https?://#', $base)) throw new Exception('O site não tem endereço configurado.');
    $ch = curl_init($base . '/wp-json/wp/v2' . $path);
    $h = ['Authorization: Basic ' . base64_encode(($cred['usuario'] ?? '') . ':' . ($cred['segredo'] ?? ''))];
    $op = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($arquivo !== null) {       // subir imagem: os bytes + o nome
        $h[] = 'Content-Type: ' . ($arquivo['mime'] ?: 'image/jpeg');
        $h[] = 'Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '-', $arquivo['nome']) . '"';
        $op[CURLOPT_POSTFIELDS] = $arquivo['bytes'];
    } elseif ($body !== null) {
        $h[] = 'Content-Type: application/json';
        $op[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $op[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $op);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

function pdWPErro($r) {
    $m = $r['body']['message'] ?? ('HTTP ' . $r['code']);
    if ($r['code'] === 401) $m = 'o WordPress recusou a senha de aplicativo — confira no cofre';
    return mb_substr(strip_tags((string)$m), 0, 200);
}

/** Testa a conexão: quem sou eu no WP? */
function pdWPTestar($clientId) {
    $site = pdSiteCfg($clientId);
    $cred = pdCred($clientId, 'wordpress');
    if (!$site || ($site['tipo'] ?? '') !== 'wp') throw new Exception('Este cliente não está configurado como WordPress.');
    if (!$cred) throw new Exception('Sem credencial no cofre (serviço: wordpress).');
    $r = pdWP($site, $cred, 'GET', '/users/me?context=edit');
    if ($r['code'] !== 200) throw new Exception(pdWPErro($r));
    return ['usuario' => $r['body']['name'] ?? '?', 'papeis' => $r['body']['roles'] ?? []];
}

/** Publica (ou atualiza, casando pelo slug) um post no WP do cliente. */
function pdWPPublicar($clientId, $post) {
    $site = pdSiteCfg($clientId);
    $cred = pdCred($clientId, 'wordpress');
    if (!$site || !$cred) throw new Exception('Site ou cofre sem configuração.');

    $slug = $post['slug'] ?: pdSlug($post['titulo']);
    $dados = [
        'title' => $post['titulo'], 'slug' => $slug,
        'content' => $post['html'], 'excerpt' => (string)($post['resumo'] ?? ''),
        'status' => !empty($post['agendar_para']) ? 'future' : 'publish',
    ];
    if (!empty($post['agendar_para'])) $dados['date'] = $post['agendar_para'];

    // imagem de capa: baixa do nosso endereço e sobe pro WP
    if (!empty($post['imagem_url'])) {
        try {
            $bytes = pdBaixar($post['imagem_url']);
            $img = pdWP($site, $cred, 'POST', '/media', null,
                ['bytes' => $bytes, 'nome' => $slug . '.jpg', 'mime' => 'image/jpeg']);
            if ($img['code'] === 201 && !empty($img['body']['id'])) $dados['featured_media'] = $img['body']['id'];
        } catch (Exception $e) { /* sem capa não trava o post */ }
    }

    // já existe com este slug? atualiza em vez de duplicar
    $tem = pdWP($site, $cred, 'GET', '/posts?slug=' . rawurlencode($slug) . '&status=publish,future,draft&_fields=id');
    $id = $tem['body'][0]['id'] ?? null;
    $r = $id ? pdWP($site, $cred, 'POST', '/posts/' . (int)$id, $dados)
             : pdWP($site, $cred, 'POST', '/posts', $dados);
    if (!in_array($r['code'], [200, 201], true) || empty($r['body']['id'])) throw new Exception(pdWPErro($r));
    return ['id' => (string)$r['body']['id'], 'url' => $r['body']['link'] ?? (rtrim($site['url'], '/') . '/' . $slug)];
}

function pdSlug($t) {
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim((string)$t)));
    $t = preg_replace('/[^a-z0-9]+/', '-', (string)$t);
    return trim(mb_substr($t, 0, 60), '-') ?: 'post';
}
function pdBaixar($url) {
    if ($url !== '' && $url[0] === '/') $url = (defined('PD_BASE_URL') ? rtrim(PD_BASE_URL, '/') : 'https://sd.sergioduh.com') . $url;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'Pontodot/1.0']);
    $b = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$b) throw new Exception('não consegui baixar a imagem (' . $code . ')');
    return $b;
}

// o texto da peça (markdown leve do GPT) vira HTML honesto
function pdTextoHtml($txt) {
    $linhas = preg_split('/\r?\n/', (string)$txt);
    $out = []; $par = [];
    $fecha = function () use (&$par, &$out) {
        if ($par) { $out[] = '<p>' . implode('<br>', $par) . '</p>'; $par = []; }
    };
    foreach ($linhas as $l) {
        $l = htmlspecialchars(rtrim($l), ENT_QUOTES, 'UTF-8');
        $l = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $l);
        $l = preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $l);
        if ($l === '') { $fecha(); continue; }
        if (preg_match('/^##\s+(.*)$/', $l, $m)) { $fecha(); $out[] = '<h2>' . $m[1] . '</h2>'; continue; }
        if (preg_match('/^#\s+(.*)$/', $l, $m)) { $fecha(); $out[] = '<h2>' . $m[1] . '</h2>'; continue; }
        $par[] = $l;
    }
    $fecha();
    return implode("\n", $out);
}

}
