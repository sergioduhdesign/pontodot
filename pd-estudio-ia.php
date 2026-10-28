<?php
// =====================================================================
// Pontodot — PAUTA: o ping-pongue entre o humano e a IA no Estúdio.
//
// 1) O humano traz texto + briefing (prompt livre) + pasta de imagens e faz a PEÇA PRINCIPAL (feed).
// 2) "derivar": a IA olha a peça (imagem + camadas), o kit, o texto, o briefing, as imagens e as lições
//    do cliente, e devolve um PLANO: carrossel, stories, roteiro de vídeo com boards. Tudo vira peça editável.
// 3) O humano edita, escreve feedback, manda de volta (nova rodada). Feedback vira lição.
// 4) "aprovar": specs aprovados viram exemplo da próxima pauta do cliente.
//
// Ações: pauta_listar · pauta_salvar · pauta_abrir · derivar · aprovar · licoes · licao_salvar · kit_status
// Precisa de SD_ANTHROPIC_KEY (modelo: SD_ESTUDIO_MODEL, padrão claude-sonnet-5-5).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
@set_time_limit(280); @ini_set('memory_limit', '768M');
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'pauta_listar');
const ES_CACHE = __DIR__ . '/.cache-midia';
function paPonteOk() { return function_exists('ponte') && defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY; }
function paAssina($id) { return substr(hash_hmac('sha256', 'midia:' . $id, SD_SECRET_KEY), 0, 24); }

// --------------------------------------------------------------- kit: está completo?
function paKit($clientId) {
    $k = sd_supabase('/rest/v1/brand_kits?client_id=eq.' . urlencode($clientId) . '&select=colors,fonts,logos,elements,notes')['body'][0] ?? ['colors' => [], 'fonts' => [], 'logos' => [], 'elements' => []];
    $faltam = [];
    if (count(array_filter((array)$k['colors'], fn($c) => !empty($c['hex']))) < 2) $faltam[] = 'cores (mínimo 2)';
    if (count(array_filter((array)$k['fonts'], fn($f) => !empty($f['name']))) < 1) $faltam[] = 'fonte principal';
    if (count(array_filter((array)($k['logos'] ?? []), fn($l) => !empty($l['file_url']))) < 1) $faltam[] = 'logo';
    return [$k, ['completo' => !$faltam, 'faltam' => $faltam]];
}
// --------------------------------------------------------------- miniatura (b64) de um arquivo do Drive, pelo cache do Estúdio
function paThumbB64($id, $w = 480) {
    if (!is_dir(ES_CACHE)) { @mkdir(ES_CACHE, 0755, true); @file_put_contents(ES_CACHE . '/.htaccess', "Deny from all\n"); }
    $orig = ES_CACHE . '/' . $id; $red = $orig . '-' . $w;
    if (!is_file($red)) {
        if (!is_file($orig)) { if (!paPonteOk()) return null; try { $r = ponte('baixar', ['fileId' => $id]); } catch (Exception $e) { return null; } $by = base64_decode((string)($r['b64'] ?? ''), true); if (!$by) return null; @file_put_contents($orig, $by); @file_put_contents($orig . '.tipo', (string)($r['mime'] ?? 'image/jpeg')); }
        if (!function_exists('imagecreatefromstring')) return null; $im = @imagecreatefromstring((string)file_get_contents($orig)); if (!$im) return null;
        $ow = imagesx($im); $oh = imagesy($im); $nw = min($w, $ow); $nh = (int)round($oh * $nw / $ow); $n = imagecreatetruecolor($nw, $nh); imagecopyresampled($n, $im, 0, 0, 0, 0, $nw, $nh, $ow, $oh); imagejpeg($n, $red, 80); imagedestroy($n); imagedestroy($im);
    }
    return ['b64' => base64_encode((string)file_get_contents($red)), 'mime' => 'image/jpeg'];
}
// --------------------------------------------------------------- resumo das camadas da peça (o que a IA "lê" além da imagem)
function paCamadas($doc, $w, $h, $pagina = 0, $prefixo = 'principal') {
    $pg = isset($doc['paginas']) ? ($doc['paginas'][$pagina] ?? []) : $doc; $out = []; $imgs = [];
    foreach ((array)($pg['objects'] ?? []) as $i => $o) {
        $t = $o['type'] ?? ''; $sx = (float)($o['scaleX'] ?? 1); $sy = (float)($o['scaleY'] ?? 1);
        $ww = round(((float)($o['width'] ?? 0)) * $sx / $w * 100); $hh = round(((float)($o['height'] ?? 0)) * $sy / $h * 100);
        $x = round(((float)($o['left'] ?? 0)) / $w * 100); $y = round(((float)($o['top'] ?? 0)) / $h * 100);
        $fill = is_string($o['fill'] ?? null) ? $o['fill'] : 'gradiente'; $uso = trim((string)($o['pdTag'] ?? '')); $usoTxt = $uso ? ' · USO: ' . $uso : '';
        if ($t === 'textbox') $out[] = sprintf('texto "%s" · %s %s · %dpx · cor %s · x%d y%d w%d · alinhado %s%s', mb_substr((string)($o['text'] ?? ''), 0, 80), $o['fontFamily'] ?? '', $o['fontWeight'] ?? '', (int)($o['fontSize'] ?? 0), $fill, $x, $y, $ww, $o['textAlign'] ?? 'left', $usoTxt);
        elseif ($t === 'image') { $n = (string)($o['name'] ?? ('imagem ' . ($i + 1))); $ref = $prefixo . ':p' . ($pagina + 1) . ':' . $i; $imgs[] = ['ref' => $ref, 'nome' => $n, 'src' => (string)($o['pdFonte'] ?: ($o['src'] ?? ''))]; $out[] = sprintf('imagem "%s" (ref %s) · x%d y%d w%d h%d%s', $n, $ref, $x, $y, $ww, $hh, $usoTxt); }
        else { $n = trim((string)($o['name'] ?? '')) ?: ('elemento ' . ($i + 1)); $ref = $prefixo . ':p' . ($pagina + 1) . ':' . $i; $grad = !is_string($o['fill'] ?? null) && !empty($o['fill']); $vet = in_array($t, ['path', 'group', 'polygon', 'polyline', 'ellipse', 'triangle', 'line'], true) || $grad || !empty($o['pdPontos']);
            $out[] = sprintf('%s "%s" (ref %s%s) · cor %s · x%d y%d w%d h%d · opacidade %s%s', $vet ? 'ELEMENTO GRÁFICO VETORIAL' : $t, $n, $ref, $vet ? ' · reutilize com tipo elemento' : '', $grad ? 'degradê' : $fill, $x, $y, $ww, $hh, (string)($o['opacity'] ?? 1), (!empty($o['angle']) ? ' · giro ' . round((float)$o['angle']) . '°' : '') . ($usoTxt ?: ($vet ? ' · USO: grafismo' : ''))); }
    }
    return [$out, $imgs, is_string($pg['background'] ?? null) ? $pg['background'] : '#ffffff'];
}
// --------------------------------------------------------------- referências por link: o que dá para "ver" de cada URL
function paRedimensiona($bytes, $w = 480) {
    if (!function_exists('imagecreatefromstring')) return null; $im = @imagecreatefromstring($bytes); if (!$im) return null;
    $ow = imagesx($im); $oh = imagesy($im); if ($ow < 120 || $oh < 120) { imagedestroy($im); return null; }   // ícones e espaçadores fora
    $nw = min($w, $ow); $nh = (int)round($oh * $nw / $ow); $n = imagecreatetruecolor($nw, $nh); imagecopyresampled($n, $im, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    ob_start(); imagejpeg($n, null, 78); $out = ob_get_clean(); imagedestroy($n); imagedestroy($im); return ['b64' => base64_encode($out), 'mime' => 'image/jpeg'];
}
function paBaixaUrl($url, $max = 6000000) {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36 Pontodot/1.0', CURLOPT_HTTPHEADER => ['Accept: text/html,image/*,*/*;q=0.8', 'Accept-Language: pt-BR,pt;q=0.9,en;q=0.7']]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $fim = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
    if ($code >= 400 || $raw === false || strlen($raw) > $max) return [null, $mime, $code, $fim]; return [$raw, $mime, $code, $fim];
}
function paAbs($base, $src) { $src = html_entity_decode(trim($src)); if (preg_match('~^https?://~i', $src)) return $src; if (strpos($src, '//') === 0) return 'https:' . $src; $u = parse_url($base); $raiz = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? ''); if (strpos($src, '/') === 0) return $raiz . $src; $dir = preg_replace('~/[^/]*$~', '/', $u['path'] ?? '/'); return $raiz . $dir . $src; }
/** Para cada link: pasta do Drive → imagens; arquivo do Drive → imagem; site → og:image + imagens grandes da página. Devolve [texto, [imagens]]. */
function paReferencias($links, $maxImgs = 10) {
    // cache de 24 h por link: a segunda rodada não baixa tudo de novo
    if (!is_dir(ES_CACHE)) { @mkdir(ES_CACHE, 0755, true); @file_put_contents(ES_CACHE . '/.htaccess', "Deny from all\n"); }
    $ck = ES_CACHE . '/refs-' . md5(json_encode(array_values((array)$links))) . '.json';
    if (is_file($ck) && time() - filemtime($ck) < 86400) { $c = json_decode((string)file_get_contents($ck), true); if (is_array($c) && isset($c[0], $c[1])) return $c; }
    $r = paReferencias2($links, $maxImgs); @file_put_contents($ck, json_encode($r)); return $r;
}
function paReferencias2($links, $maxImgs = 10) {
    $txt = []; $imgs = [];
    foreach ((array)$links as $n => $url) { if (count($imgs) >= $maxImgs) break; $rot = 'REF ' . ($n + 1);
        if (preg_match('~drive\.google\.com/drive/(?:u/\d+/)?folders/([\w\-]+)~', $url, $m)) {
            try { $l = ponte('listar', ['folderId' => $m[1]]); $k = 0; foreach ((array)($l['arquivos'] ?? []) as $f) { if (count($imgs) >= $maxImgs || $k >= 6) break; if (stripos((string)$f['mime'], 'image/') !== 0) continue; $t = paThumbB64($f['id'], 480); if ($t) { $imgs[] = ['rot' => $rot . ' · pasta do Drive · ' . $f['name'], 'b64' => $t['b64'], 'mime' => $t['mime']]; $k++; } } $txt[] = "$rot: pasta do Drive com " . count((array)($l['arquivos'] ?? [])) . ' arquivo(s): ' . $url; } catch (Exception $e) { $txt[] = "$rot: pasta do Drive que não consegui ler ($url)"; }
            continue; }
        if (preg_match('~drive\.google\.com/file/d/([\w\-]+)~', $url, $m)) { $t = paThumbB64($m[1], 480); if ($t) $imgs[] = ['rot' => $rot . ' · arquivo do Drive', 'b64' => $t['b64'], 'mime' => $t['mime']]; $txt[] = "$rot: arquivo do Drive $url"; continue; }
        [$raw, $mime, $code, $fim] = paBaixaUrl($url);
        if ($raw === null) { $txt[] = "$rot: $url (o site não deixou ler: HTTP $code)"; continue; }
        if (stripos($mime, 'image/') === 0) { $r = paRedimensiona($raw); if ($r) $imgs[] = ['rot' => $rot . ' · imagem', 'b64' => $r['b64'], 'mime' => 'image/jpeg']; $txt[] = "$rot: imagem $url"; continue; }
        $html = $raw; $titulo = preg_match('~<title[^>]*>(.*?)</title>~si', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : ''; $desc = preg_match('~<meta[^>]+(?:name|property)=["\'](?:description|og:description)["\'][^>]+content=["\']([^"\']*)~si', $html, $m) ? trim(html_entity_decode($m[1])) : '';
        $cands = []; if (preg_match_all('~<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)~si', $html, $mm)) foreach ($mm[1] as $c) $cands[] = paAbs($fim ?: $url, $c);
        if (preg_match_all('~<img[^>]+(?:src|data-src)=["\']([^"\']+)~si', $html, $mm)) foreach ($mm[1] as $c) { if (preg_match('~\.svg|data:|sprite|icon|logo|avatar|pixel|1x1~i', $c)) continue; $cands[] = paAbs($fim ?: $url, $c); }
        $cands = array_values(array_unique($cands)); $k = 0;
        foreach ($cands as $c) { if (count($imgs) >= $maxImgs || $k >= 5) break; [$ib, $im] = paBaixaUrl($c, 4000000); if ($ib === null || stripos((string)$im, 'image/') !== 0) continue; $r = paRedimensiona($ib); if (!$r) continue; $imgs[] = ['rot' => $rot . ' · ' . ($titulo ?: parse_url($url, PHP_URL_HOST)), 'b64' => $r['b64'], 'mime' => 'image/jpeg']; $k++; }
        $txt[] = "$rot: " . ($titulo ?: $url) . ($desc ? ' — ' . mb_substr($desc, 0, 300) : '') . " ($url)" . ($k ? " · $k imagem(ns) anexada(s)" : ' · sem imagem legível (site fechado ou carregado por script)');
    }
    return [$txt, $imgs];
}
function paClaude($system, $content, $max = 16000) {
    if (!defined('SD_ANTHROPIC_KEY') || !SD_ANTHROPIC_KEY) throw new Exception('Sem SD_ANTHROPIC_KEY no servidor: a IA do Estúdio não tem chave.');
    $model = defined('SD_ESTUDIO_MODEL') ? SD_ESTUDIO_MODEL : 'claude-sonnet-5-5';
    $base = defined('PD_ANTHROPIC_BASE') ? rtrim(PD_ANTHROPIC_BASE, '/') : 'https://api.anthropic.com';
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 260,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . SD_ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]])]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code !== 200) throw new Exception('A IA respondeu HTTP ' . $code . ($err ? ' · ' . $err : '') . ' · ' . mb_substr((string)$raw, 0, 300));
    $j = json_decode((string)$raw, true); $t = ''; foreach ((array)($j['content'] ?? []) as $x) if (($x['type'] ?? '') === 'text') $t .= $x['text'];
    $us = $j['usage'] ?? []; $custo = (($us['input_tokens'] ?? 0) * 3 + ($us['output_tokens'] ?? 0) * 15) / 1e6; $us['stop_reason'] = $j['stop_reason'] ?? '';
    return [trim($t), $custo, $us];
}
function paJson($t) { $t = trim($t); if (preg_match('/```(?:json)?\s*(.*?)```/s', $t, $m)) $t = $m[1]; $a = strpos($t, '{'); if ($a === false) return null; $t = substr($t, $a);
    $z = strrpos($t, '}'); $j = $z !== false ? json_decode(substr($t, 0, $z + 1), true) : null; if (is_array($j)) return $j;
    // limpeza: aspas tipográficas, vírgulas sobrando, caracteres de controle, quebras de linha e aspas cruas DENTRO das strings
    $c = str_replace(['“', '”', '„'], '"', $t); $c = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $c); $c = paJsonStrings($c); $c = preg_replace('/,\s*([}\]])/', '$1', $c);
    $z = strrpos($c, '}'); $j = $z !== false ? json_decode(substr($c, 0, $z + 1), true) : null; if (is_array($j)) return $j;
    // resposta cortada no meio: fecha no último item completo da lista de derivações e fecha o JSON
    return paJsonConserta($c);
}
function paJsonStrings($t) {   // dentro de strings: \n cru vira \\n, aspas cruas viram \" (quando não fecham a string)
    $n = strlen($t); $o = ''; $str = false; $esc = false;
    for ($i = 0; $i < $n; $i++) { $ch = $t[$i];
        if ($str) { if ($esc) { $o .= $ch; $esc = false; continue; } if ($ch === '\\') { $o .= $ch; $esc = true; continue; }
            if ($ch === "\n") { $o .= '\\n'; continue; } if ($ch === "\r") continue; if ($ch === "\t") { $o .= '\\t'; continue; }
            if ($ch === '"') { $j = $i + 1; while ($j < $n && ($t[$j] === ' ' || $t[$j] === "\n" || $t[$j] === "\r" || $t[$j] === "\t")) $j++; $prox = $j < $n ? $t[$j] : '';
                if ($prox === ',' || $prox === '}' || $prox === ']' || $prox === ':') { $str = false; $o .= $ch; } else $o .= '\\"'; continue; }
            $o .= $ch; continue; }
        if ($ch === '"') $str = true; $o .= $ch; }
    return $o;
}
function paJsonConserta($t) {
    $n = strlen($t); $str = false; $esc = false; $pilha = []; $ultimoFechaItem = -1;   // posição depois de um '}' que fecha um item de nível 2 (dentro de "derivacoes")
    for ($i = 0; $i < $n; $i++) { $ch = $t[$i];
        if ($str) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $str = false; continue; }
        if ($ch === '"') { $str = true; continue; }
        if ($ch === '{' || $ch === '[') $pilha[] = $ch;
        elseif ($ch === '}' || $ch === ']') { array_pop($pilha); if (count($pilha) === 2 && $ch === '}') $ultimoFechaItem = $i; }
    }
    if ($ultimoFechaItem < 0) return null;
    $corte = substr($t, 0, $ultimoFechaItem + 1) . ']}';
    $j = json_decode($corte, true); if (!is_array($j)) return null;
    $j['raciocinio'] = ($j['raciocinio'] ?? '') . ' [resposta cortada pelo limite: vieram ' . count($j['derivacoes'] ?? []) . ' derivações completas; peça "continuar" para as que faltam]';
    return $j;
}

// =============================================================== ações
if ($acao === 'kit_status') { [, $st] = paKit((string)($b['client_id'] ?? '')); echo json_encode($st, JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'pauta_listar') {
    $q = '/rest/v1/pd_pautas?select=id,client_id,nome,status,rodada,kit_status,principal_id,familia,qtd,mudado_em,clients(name)&order=mudado_em.desc&limit=60'; if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']);
    $r = sd_supabase($q); if (($r['code'] ?? 500) >= 300) $r = sd_supabase(str_replace(',familia,qtd', '', $q)); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v117 (pauta) no Supabase.', 500); echo json_encode(['pautas' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'refs_listar') {   // referências guardadas: deste cliente + das outras (a casa inteira), mais recentes primeiro
    $cid = (string)($b['client_id'] ?? ''); $r = sd_supabase('/rest/v1/pd_acervo?categoria=eq.referencia&select=id,client_id,nome,url,nota,em,clients(name)&order=em.desc&limit=80')['body'] ?? [];
    usort($r, fn($a, $b2) => (($b2['client_id'] === $cid) <=> ($a['client_id'] === $cid)) ?: strcmp((string)$b2['em'], (string)$a['em']));
    echo json_encode(['refs' => $r], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'pauta_salvar') {
    $id = (string)($b['id'] ?? ''); if (empty($b['client_id']) && !$id) sd_fail('Toda pauta é de um cliente.');
    $d = ['mudado_em' => gmdate('c')]; foreach (['nome' => 120, 'texto' => 20000, 'briefing' => 6000] as $k => $n) if (isset($b[$k])) $d[$k] = mb_substr((string)$b[$k], 0, $n);
    if (array_key_exists('pasta_drive', $b)) { $p = (string)$b['pasta_drive']; if (preg_match('~folders/([\w\-]+)~', $p, $m)) $p = $m[1]; $d['pasta_drive'] = $p ?: null; }
    if (array_key_exists('refs', $b)) { $lst = is_array($b['refs']) ? $b['refs'] : preg_split('/[\s,]+/', (string)$b['refs']); $d['refs'] = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 600), $lst), fn($x) => preg_match('~^https?://~i', $x))); $d['refs'] = array_slice($d['refs'], 0, 12); }
    if (array_key_exists('texto_link', $b)) { $t = trim((string)$b['texto_link']); $d['texto_link'] = $t ? mb_substr($t, 0, 500) : null; }
    if (array_key_exists('site_link', $b)) { $t = trim((string)$b['site_link']); $d['site_link'] = $t ? mb_substr($t, 0, 500) : null; }
    if (array_key_exists('design_system', $b)) $d['design_system'] = $b['design_system'] === null || $b['design_system'] === '' ? null : (bool)$b['design_system'];
    if (array_key_exists('principal_id', $b)) $d['principal_id'] = $b['principal_id'] ?: null;
    if (!empty($b['familia']) && in_array($b['familia'], ['social', 'papelaria', 'apresentacao', 'book'], true)) $d['familia'] = $b['familia'];
    if (isset($b['qtd'])) $d['qtd'] = max(0, min(200, (int)$b['qtd']));
    if (!$id) { $d['client_id'] = (string)$b['client_id']; $d['criado_por'] = $u['id']; $d['nome'] = $d['nome'] ?? 'Pauta'; $r = sd_supabase('/rest/v1/pd_pautas', 'POST', $d, ['Prefer: return=representation']); }
    else $r = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']);
    if (empty($r['body'][0])) sd_fail('Não consegui salvar a pauta: ' . ($r['raw'] ?? ''), 500);
    // texto longo (mais de 4000 caracteres) vira documento no Drive: cliente › ESTÚDIO › TEXTOS
    $pa = $r['body'][0]; $aviso = null;
    if (!empty($d['texto']) && mb_strlen($d['texto']) > 4000 && empty($pa['texto_link']) && paPonteOk()) {
        try { $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($pa['client_id']) . '&select=id,name,drive_id')['body'][0] ?? null; $raiz = $c['drive_id'] ?? null;
            if (!$raiz && $c && pdDriveClientes()) { $g0 = ponte('pasta_garantir', ['pai_id' => pdDriveClientes(), 'nome' => $c['name'], 'criar' => true]); $raiz = $g0['id'] ?? null; }
            if ($raiz) { $e = ponte('pasta_garantir', ['pai_id' => $raiz, 'nome' => 'ESTÚDIO', 'criar' => true]); $tx = ponte('pasta_garantir', ['pai_id' => $e['id'], 'nome' => 'TEXTOS', 'criar' => true]);
                $g = ponte('gravar', ['folderId' => $tx['id'], 'nome' => preg_replace('/[\/\\:*?"<>|]+/u', '-', $pa['nome']) . '.md', 'mime' => 'text/markdown', 'b64' => base64_encode($d['texto']), 'substituir' => true]);
                if (!empty($g['url'])) { sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($pa['id']), 'PATCH', ['texto_link' => $g['url']]); $pa['texto_link'] = $g['url']; $aviso = 'Texto longo virou documento no Drive (ESTÚDIO › TEXTOS).'; } }
        } catch (Exception $e) { $aviso = 'Não consegui guardar o texto no Drive: ' . $e->getMessage(); }
    }
    $r['body'][0] = $pa; if ($aviso) $r['body'][0]['_aviso'] = $aviso;
    if (!empty($b['principal_id'])) sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)$b['principal_id']), 'PATCH', ['pauta_id' => $r['body'][0]['id'], 'papel' => 'principal']);
    // toda referência usada fica guardada no acervo (categoria 'referencia'), pra reaproveitar em qualquer trabalho
    $links = array_merge((array)($d['refs'] ?? []), !empty($d['texto_link']) ? [$d['texto_link']] : [], !empty($d['site_link']) ? [$d['site_link']] : [], !empty($d['pasta_drive']) ? [$d['pasta_drive']] : []);
    foreach (array_slice(array_unique(array_filter($links)), 0, 20) as $lk) { if (!preg_match('~^https?://~i', $lk)) continue;
        $ja = sd_supabase('/rest/v1/pd_acervo?categoria=eq.referencia&url=eq.' . urlencode($lk) . '&select=id&limit=1')['body'][0] ?? null; if ($ja) continue;
        $host = preg_replace('~^www\.~', '', (string)parse_url($lk, PHP_URL_HOST)); $cat = $lk === ($d['texto_link'] ?? '') ? 'texto' : ($lk === ($d['site_link'] ?? '') ? 'site do cliente' : ($lk === ($d['pasta_drive'] ?? '') ? 'imagens' : 'visual'));
        pdAcervo('link', 'estudio', ['categoria' => 'referencia', 'client_id' => $r['body'][0]['client_id'], 'nome' => $host, 'url' => $lk, 'nota' => $cat . ' · ' . ($r['body'][0]['nome'] ?? '') . ' · ' . ($r['body'][0]['familia'] ?? ''), 'por' => $u['id']]); }
    echo json_encode(['ok' => true, 'pauta' => $r['body'][0]], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'pauta_abrir') {
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=*,clients(name)')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    $pc = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&arquivada=eq.false&select=id,nome,formato,thumb_url,papel,derivada_tipo,ordem,roteiro,avaliacao,rodada,mudado_em&order=ordem.asc,criado_em.asc')['body'] ?? [];
    $todas = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&select=id,nome,thumb_url,derivada_tipo,avaliacao,rodada,arquivada&order=rodada.asc,ordem.asc')['body'] ?? [];
    [, $st] = paKit($p['client_id']); echo json_encode(['pauta' => $p, 'pecas' => $pc, 'rodadas' => $todas, 'kit' => $st, 'quem' => paQuemFaz($u)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'licoes') { $cid = (string)($b['client_id'] ?? ''); $r = sd_supabase('/rest/v1/pd_estudio_licoes?ativa=eq.true&or=(client_id.eq.' . urlencode($cid) . ',client_id.is.null)&select=id,client_id,tipo,texto,em&order=em.desc&limit=60'); echo json_encode(['licoes' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'licao_salvar') {
    if (!empty($b['id']) && isset($b['ativa'])) { sd_supabase('/rest/v1/pd_estudio_licoes?id=eq.' . urlencode((string)$b['id']), 'PATCH', ['ativa' => (bool)$b['ativa']]); echo json_encode(['ok' => true]); exit; }
    $t = mb_substr(trim((string)($b['texto'] ?? '')), 0, 2000); if (!$t) sd_fail('Escreva a regra.');
    sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => ($b['client_id'] ?? null) ?: null, 'tipo' => 'regra', 'texto' => $t, 'por' => $u['id']]); echo json_encode(['ok' => true]); exit;
}

$FAMILIAS = [
  'social' => ['nome' => 'conteúdo social', 'papeis' => ['principal' => 'post de feed'], 'min' => ['principal']],
  'papelaria' => ['nome' => 'papelaria', 'papeis' => ['principal' => 'cartão de visita'], 'min' => ['principal']],
  'apresentacao' => ['nome' => 'apresentação', 'papeis' => ['capa' => 'capa', 'segunda capa' => 'segunda capa / abertura', 'tela clara' => 'tela de conteúdo clara', 'tela escura' => 'tela de conteúdo escura'], 'min' => ['capa', 'tela clara']],
  'book' => ['nome' => 'book / livro', 'papeis' => ['capa' => 'capa', 'pagina 1' => 'página 1 (abertura)', 'entrada de capitulo' => 'entrada de capítulo', 'texto a' => 'página de texto, modelo A', 'texto b' => 'página de texto, modelo B', 'final' => 'página final'], 'min' => ['capa', 'texto a']],
];
function paQuemFaz($u) {
    $j = sd_supabase('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=user_id,personagem,apelido')['body'][0] ?? null;
    if (!$j) { $f = sd_supabase('/rest/v1/pd_formacoes?apta_em=not.is.null&skill_id=like.ARTE*&select=ia_user_id,personagem&limit=1')['body'][0] ?? null; if ($f) $j = sd_supabase('/rest/v1/pd_jeito?user_id=eq.' . urlencode($f['ia_user_id']) . '&select=user_id,personagem,apelido')['body'][0] ?? ['user_id' => $f['ia_user_id'], 'personagem' => $f['personagem'], 'apelido' => '']; }
    if (!$j) return ['id' => null, 'personagem' => 'maestro', 'nome' => 'a IA da casa'];
    $cat = function_exists('pdPersonagens') ? pdPersonagens() : []; $pers = strtolower(trim((string)$j['personagem']));
    return ['id' => $j['user_id'], 'personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers))];
}
if ($acao === 'quem_faz') { echo json_encode(paQuemFaz($u), JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'avaliar') {   // joinha por peça
    $v = in_array($b['avaliacao'] ?? '', ['gostei', 'nao'], true) ? $b['avaliacao'] : null;
    sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['avaliacao' => $v]); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'rodada_voltar') {   // reativa as derivações de uma rodada anterior (e guarda as atuais)
    $id = (string)($b['id'] ?? ''); $r = (int)($b['rodada'] ?? 0); if (!$id || !$r) sd_fail('Qual rodada?');
    sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada', 'PATCH', ['arquivada' => true]);
    sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&rodada=eq.' . $r, 'PATCH', ['arquivada' => false]);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'parar') { $id = (string)($b['id'] ?? ''); sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&status=eq.derivando', 'PATCH', ['status' => 'revisao', 'erro' => 'Rodada interrompida por quem pediu.', 'mudado_em' => gmdate('c')]); echo json_encode(['ok' => true]); exit; }
if ($acao === 'familias') { echo json_encode(['familias' => $FAMILIAS], JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'derivar') {   // a jogada da IA — vale para qualquer família
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=*,clients(name)')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    $fam = $FAMILIAS[$p['familia'] ?? 'social'] ?? $FAMILIAS['social']; $famId = $p['familia'] ?: 'social';
    if (empty($p['principal_id'])) sd_fail('Faça e salve a peça principal antes do play.');
    $pr = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($p['principal_id']) . '&select=id,nome,formato,doc,thumb_url')['body'][0] ?? null; if (!$pr) sd_fail('Peça principal não encontrada.', 404);
    $pngs = array_values(array_filter(array_map(fn($x) => strpos((string)$x, ',') !== false ? substr($x, strpos($x, ',') + 1) : (string)$x, (array)($b['principal_pngs'] ?? ($b['principal_png'] ? [$b['principal_png']] : [])))));
    if (!$pngs) sd_fail('Mande as imagens das páginas da principal (o Estúdio faz isso sozinho).');
    $feedback = mb_substr(trim((string)($b['feedback'] ?? '')), 0, 4000);
    $textoPauta = (string)($p['texto'] ?? '');
    if (!empty($p['texto_link']) && paPonteOk()) { $lid = preg_match('~/d/([\w\-]+)|id=([\w\-]+)~', $p['texto_link'], $m) ? ($m[1] ?: $m[2]) : trim($p['texto_link']);
        try { $r0 = ponte('reuniao_ler', ['doc_id' => $lid]); $tl = trim((string)($r0['texto'] ?? '')); if ($tl === '') { $r1 = ponte('baixar', ['fileId' => $lid]); $tl = trim((string)base64_decode((string)($r1['b64'] ?? ''), true)); } if ($tl !== '') $textoPauta = mb_substr($tl, 0, 60000); }
        catch (Exception $e) { sd_fail('Não consegui ler o texto no link do Drive: ' . $e->getMessage()); } }
    [$kit, $kitSt] = paKit($p['client_id']);
    $refsTodas = array_values(array_unique(array_merge(!empty($p['site_link']) ? [$p['site_link']] : [], (array)($p['refs'] ?? []))));
    [$refTxt, $refImgs] = $refsTodas ? paReferencias($refsTodas) : [[], []];
    $rodada = (int)$p['rodada'] + 1; $hist = is_array($p['historico']) ? $p['historico'] : [];
    if ($feedback) { $hist[] = ['rodada' => $rodada, 'feedback' => $feedback, 'em' => gmdate('c'), 'por' => $u['name']]; sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => $p['client_id'], 'pauta_id' => $id, 'tipo' => 'feedback', 'texto' => '[' . $famId . '] ' . $feedback, 'por' => $u['id']]); }
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'derivando', 'rodada' => $rodada, 'historico' => $hist, 'kit_status' => $kitSt, 'erro' => null, 'mudado_em' => gmdate('c')]);
    // imagens disponíveis: pasta da pauta (ou cliente › MÍDIA)
    $imgs = []; $thumbs = [];
    try { if (paPonteOk()) { $pasta = $p['pasta_drive'] ?: null;
        if (!$pasta) { $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($p['client_id']) . '&select=name,drive_id')['body'][0] ?? []; if (!empty($c['drive_id'])) { $g = ponte('pasta_garantir', ['pai_id' => $c['drive_id'], 'nome' => 'MÍDIA', 'criar' => false]); $pasta = $g['id'] ?? null; } }
        if ($pasta) { $l = ponte('listar', ['folderId' => $pasta]); foreach ((array)($l['arquivos'] ?? []) as $f) { $m = (string)($f['mime'] ?? ''); if (stripos($m, 'image/') !== 0 || stripos($m, 'svg') !== false) continue; $imgs[] = ['id' => $f['id'], 'nome' => $f['name']]; if (count($thumbs) < 12) { $t = paThumbB64($f['id'], 420); if ($t) $thumbs[] = ['id' => $f['id'], 'nome' => $f['name']] + $t; } } } } } catch (Exception $e) { /* sem pasta: a IA usa as imagens da principal */ }
    // páginas da principal, cada uma com o papel que o humano deu
    $docP = $pr['doc']; $pags = isset($docP['paginas']) ? $docP['paginas'] : [$docP]; $papeis = (array)($docP['papeis'] ?? []); $W0 = (int)$pr['formato']['w']; $H0 = (int)$pr['formato']['h'];
    $resumoPrincipal = ''; $imgsPrincipal = [];
    foreach ($pags as $i => $pg) { [$camadas, $ip, $fundo] = paCamadas($docP, $W0, $H0, $i); $imgsPrincipal = array_merge($imgsPrincipal, $ip); $papel = trim((string)($papeis[$i] ?? '')) ?: ($famId === 'social' || $famId === 'papelaria' ? 'principal' : 'sem papel');
        $resumoPrincipal .= "\nPÁGINA " . ($i + 1) . " · papel: $papel · fundo $fundo · camadas:\n- " . implode("\n- ", $camadas ?: ['(vazia)']) . "\n"; }
    $faltam = array_values(array_filter($fam['min'], fn($r) => $r === 'principal' ? false : !in_array($r, array_map(fn($x) => strtolower(trim((string)$x)), $papeis), true)));
    if ($faltam) sd_fail('Esta família precisa das páginas-modelo: ' . implode(', ', $faltam) . '. Marque o papel de cada página na barra de pranchetas.');
    $lic = sd_supabase('/rest/v1/pd_estudio_licoes?ativa=eq.true&or=(client_id.eq.' . urlencode($p['client_id']) . ',client_id.is.null)&select=tipo,texto&order=em.desc&limit=40')['body'] ?? [];
    $ex = sd_supabase('/rest/v1/pd_pautas?client_id=eq.' . urlencode($p['client_id']) . '&familia=eq.' . urlencode($famId) . '&status=in.(aprovada,produzida)&id=neq.' . urlencode($id) . '&select=aprovado&order=mudado_em.desc&limit=1')['body'][0]['aprovado'] ?? null;
    $atuais = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&arquivada=eq.false&select=nome,derivada_tipo,formato,doc,spec&order=ordem.asc')['body'] ?? [];
    $avals = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&arquivada=eq.false&avaliacao=not.is.null&select=nome,derivada_tipo,avaliacao')['body'] ?? [];
    $resumoAtuais = []; foreach ($atuais as $a) { [$cs] = paCamadas($a['doc'], (int)$a['formato']['w'], (int)$a['formato']['h'], 0, 'derivada'); $resumoAtuais[] = ($a['derivada_tipo'] ?: 'peça') . ' "' . $a['nome'] . '" (página 1): ' . implode(' | ', array_slice($cs, 0, 14)); }
    $cores = implode(', ', array_map(fn($c) => ($c['name'] ?? '') . ' ' . ($c['hex'] ?? ''), array_filter((array)$kit['colors'], fn($c) => !empty($c['hex']))));
    $fontes = implode(', ', array_map(fn($f) => $f['name'], array_filter((array)$kit['fonts'], fn($f) => !empty($f['name']))));
    $logos = array_keys(array_filter((array)($kit['logos'] ?? []), fn($l) => !empty($l['file_url'])));
    $qtd = (int)($p['qtd'] ?: ($famId === 'apresentacao' ? 40 : ($famId === 'book' ? 24 : 0)));
    $gram = <<<TXT
Você responde SÓ com JSON válido, compacto (sem espaços e quebras de linha desnecessários), sem comentários; dentro das strings nunca use aspas duplas (use aspas simples) nem quebras de linha cruas (use \\n). Medidas em PORCENTAGEM da largura (x, w, tamanho) e da altura (y, h) da peça de destino.
PAGINA = {"fundo":"#hex","camadas":[CAMADA,...]} — camadas de baixo para cima.
   ou, quando existe uma página-modelo da principal para seguir: {"herda":"<papel da página-modelo>","fundo":"#hex opcional","substituir":[{"nome":"<nome exato da camada no modelo>","texto":"novo texto"} | {"nome":"<nome>","imagem":"ID da galeria OU ref da principal"}],"camadas":[CAMADA extra,...]} — herdar copia TODAS as camadas do modelo e troca só o que estiver em substituir; use isso sempre que puder: é o que mantém o sistema.
CAMADA = {"tipo":"texto","texto":"...","fonte":"nome","peso":"400|700|900","tamanho":7,"cor":"#hex","x":6,"y":12,"w":88,"alinhar":"left|center|right","entrelinha":1.1}
       | {"tipo":"imagem","imagem":"ID da galeria OU ref principal:pN:i","x":0,"y":0,"w":100,"h":55,"ajuste":"cobrir"}
       | {"tipo":"forma","forma":"rect|circle","cor":"#hex","x":0,"y":60,"w":100,"h":40,"opacidade":0.9,"cantos":0}
       | {"tipo":"gradiente","direcao":"baixo|cima","cor":"#000000","x":0,"y":0,"w":100,"h":100,"forca":0.75}
       | {"tipo":"logo","x":6,"y":90,"w":16,"opacidade":1,"desfoque":0,"cor":"#hex opcional (pinta o logo quando ele é vetor)"}
       | {"tipo":"elemento","de":"ref principal:pN:i de um ELEMENTO GRÁFICO VETORIAL","x":-10,"y":40,"w":120,"giro":0,"espelhar":false,"opacidade":1} — a PD copia o elemento real (caminho, degradê, grafismo) da principal, inteiro, escalado pela largura; pode sair da página (x negativo, w>100), girar e espelhar.
Imagem e logo aceitam "opacidade" (0–1) e "desfoque" (0–1): um logo grande, cinza/claro, com desfoque e opacidade baixa, serve de fundo (marca d'água). Formas finas (w ou h de 0.3–1%) servem de filetes de acabamento.
USO DAS CAMADAS (etiqueta que o humano deu a cada camada da principal; ela diz para que serve e o que fazer com ela em TODAS as peças): fundo = cor/forma de fundo, mantenha a lógica; grafismo = elemento visual do sistema, reutilize com tipo elemento em toda peça (fundos, cantos, sangrando pela borda); logotipo = tipo logo; titulo/texto = hierarquia a repetir com o texto da pauta; contato = dados de contato (nome, cargo, telefone, site, e-mail) a repetir onde couber, nunca inventar; foto = moldura de foto a trocar pela galeria; destaque = tarja/caixa de destaque; filete = linha fina de acabamento. Camada sem etiqueta: deduza pelo nome e pelo tipo.
REGRAS DA CASA (inegociáveis): os ELEMENTOS GRÁFICOS VETORIAIS da principal (grafismos, caminhos, formas com degradê) são o sistema visual: NUNCA os redesenhe nem os substitua por círculos/retângulos genéricos — reutilize-os com {"tipo":"elemento"} em todas as peças (escala, giro, espelho e recorte pela borda variam de peça para peça; a forma, as cores e o degradê não). Formas novas (rect/circle) só como fundo, tarja ou filete. Para colocar o logotipo use SEMPRE a camada {"tipo":"logo"} (a PD coloca o arquivo real do logo, inteiro); nunca desenhe o logo com texto ou formas, nunca o refaça. O logotipo nunca é esticado, girado, cortado, recolorido fora do kit nem reduzido abaixo de 8% da largura; ele vai inteiro, com área de respiro; quando herdar de um modelo, a camada do logo fica como está. Nunca mude a proporção de nenhuma imagem (só cobrir/cortar pela moldura). Use SÓ as fontes e cores do kit ou da principal; nunca deixe uma palavra sozinha na última linha de um título; margens x≥6 e w≤88 salvo imagens e formas de fundo; use só o texto da pauta e da principal (distribua, hierarquize, corte; não invente). Quando o kit estiver incompleto, siga a principal e diga no raciocinio o que ficou provisório.
TXT;
    if ($famId === 'social') $system = "Você é a diretora de arte da Pontodot. Recebe UM post de feed feito por um humano e deriva o resto do conteúdo social no mesmo sistema visual.\n$gram\nFormato da resposta: {\"raciocinio\":\"3 linhas\",\"derivacoes\":[{\"tipo\":\"carrossel\",\"titulo\":\"...\",\"paginas\":[PAGINA x4-7]},{\"tipo\":\"stories\",\"titulo\":\"...\",\"paginas\":[PAGINA]} x3,{\"tipo\":\"video\",\"titulo\":\"...\",\"roteiro\":{\"estilo\":\"frase\",\"legenda_posicao\":\"baixo-centro|centro|cima\",\"capa\":PAGINA,\"boards\":[{\"pagina\":PAGINA,\"fala\":\"locução/legenda\",\"duracao_s\":4} x4],\"encerramento\":PAGINA}}]}\nCarrossel 1080×1350 (capa forte, uma ideia por página, última com chamada); stories 1080×1920 (nada nos 13% de cima e 18% de baixo); vídeo 1080×1920. Tamanhos de texto em % da largura: título 7–10, subtítulo 4–5, corpo 2.6–3.4.";
    elseif ($famId === 'papelaria') $system = "Você é a diretora de arte da Pontodot. Recebe UM cartão de visita feito por um humano e deriva a papelaria inteira com a mesma lógica (filetes, proporções, hierarquia, cores, fontes, posição do logo).\n$gram\nFormato da resposta: {\"raciocinio\":\"3 linhas\",\"derivacoes\":[{\"tipo\":\"papelaria\",\"item\":\"timbrado\",\"titulo\":\"Papel timbrado A4\",\"formato\":{\"mm\":[210,297]},\"paginas\":[PAGINA]}, ...]}\nEntregue, nesta ordem, um item por derivação: timbrado (mm 210×297), envelope (mm 220×110), assinatura de e-mail (px 600×200: formato {\"px\":[600,200]}), pasta (mm 220×310, só a frente), cracha (mm 54×86), adesivo (mm 50×50), bloco (mm 148×210), capa de proposta (mm 210×297). Em impresso, lembre: texto de corpo entre 7 e 10 pt ≈ tamanho 3–4.5 em % da largura de um A4; o papel tem 5 mm de sangria por lado já incluídos na página (deixe 10% de respiro além do corte para o que não sangra).";
    elseif ($famId === 'apresentacao') $system = "Você é a diretora de arte da Pontodot. Recebe as páginas-modelo de uma apresentação feitas por um humano (capa, segunda capa, tela clara, tela escura) e monta o deck inteiro com $qtd telas, usando HERDA em todas as telas (cada tela herda de um modelo e troca só textos e imagens). Distribua o TEXTO DA PAUTA pelas telas: uma ideia por tela, títulos curtos, corpo enxuto; alterne clara/escura com ritmo; abra seções com uma tela que herda da segunda capa; feche com uma tela que herda da capa.\n$gram\nFormato da resposta: {\"raciocinio\":\"3 linhas\",\"derivacoes\":[{\"tipo\":\"apresentacao\",\"titulo\":\"nome do deck\",\"paginas\":[PAGINA x $qtd]}]}";
    else $system = "Você é a diretora de arte da Pontodot. Recebe as páginas-modelo de um book/livro feitas por um humano (capa, página 1, entrada de capítulo, texto A, texto B, final) e diagrama o book inteiro com $qtd páginas, usando HERDA em todas (cada página herda de um modelo e troca só textos e imagens). Distribua o TEXTO DA PAUTA em capítulos: cada capítulo abre com uma entrada de capítulo e segue alternando texto A e texto B; comece pela capa e página 1 e termine na final. Respeite o texto: não corte frases no meio; se o texto for longo demais para a quantidade de páginas, diga no raciocinio quantas páginas faltariam.\n$gram\nFormato da resposta: {\"raciocinio\":\"3 linhas\",\"derivacoes\":[{\"tipo\":\"book\",\"titulo\":\"nome do book\",\"paginas\":[PAGINA x $qtd]}]}";
    $txt = "CLIENTE: {$p['clients']['name']}\nPAUTA: {$p['nome']} · família: {$fam['nome']}\nRODADA: $rodada\n\nBRIEFING (o que o humano pediu):\n" . ($p['briefing'] ?: '(sem briefing)') . "\n\nTEXTO DA PAUTA (só este texto pode aparecer nas peças):\n" . ($textoPauta ?: '(sem texto além do que está na principal)') .
        "\n\nKIT DE MARCA: cores: " . ($cores ?: 'nenhuma') . " · fontes: " . ($fontes ?: 'nenhuma') . " · logos: " . (implode(', ', $logos) ?: 'nenhum') . ($kitSt['completo'] ? '' : ' · KIT INCOMPLETO (faltam: ' . implode(', ', $kitSt['faltam']) . ')') .
        "\n\nPEÇA PRINCIPAL \"{$pr['nome']}\" ({$W0}×{$H0}" . (!empty($pr['formato']['mm']) ? ', ' . $pr['formato']['mm'][0] . '×' . $pr['formato']['mm'][1] . ' mm + sangria' : '') . "), " . count($pags) . " página(s):\n" . $resumoPrincipal .
        "\n\nIMAGENS DISPONÍVEIS NA GALERIA (use pelo ID):\n" . ($imgs ? implode("\n", array_map(fn($i) => $i['id'] . ' · ' . $i['nome'], array_slice($imgs, 0, 60))) : '(pasta vazia: use as imagens da principal)') .
        "\n\nIMAGENS DA PRINCIPAL: " . ($imgsPrincipal ? implode('; ', array_map(fn($i) => $i['ref'] . ' = ' . $i['nome'], $imgsPrincipal)) : 'nenhuma') .
        ($lic ? "\n\nLIÇÕES DESTE CLIENTE (obrigatórias):\n- " . implode("\n- ", array_map(fn($l) => '[' . $l['tipo'] . '] ' . $l['texto'], $lic)) : '') .
        ($ex ? "\n\nEXEMPLO APROVADO NA PAUTA ANTERIOR DESTA FAMÍLIA (repita a lógica):\n" . mb_substr(json_encode($ex, JSON_UNESCAPED_UNICODE), 0, 6000) : '') .
        ($resumoAtuais ? "\n\nDERIVAÇÕES ATUAIS (o humano pode ter mexido; o que ele mudou é lei):\n- " . implode("\n- ", $resumoAtuais) : '') .
        ($avals ? "\n\nAVALIAÇÃO PEÇA A PEÇA DA RODADA ANTERIOR (👍 = manter como está, só refinar; 👎 = refazer do zero com outra solução):\n- " . implode("\n- ", array_map(fn($a) => ($a['avaliacao'] === 'gostei' ? '👍 ' : '👎 ') . ($a['derivada_tipo'] ?: '') . ' "' . $a['nome'] . '"', $avals)) : '') .
        ($feedback ? "\n\nFEEDBACK DO HUMANO NESTA RODADA (manda em tudo):\n$feedback" : '') .
        ($p['design_system'] === true ? "\n\nDESIGN SYSTEM: o cliente TEM design system (o kit): siga cores, fontes e logo à risca, sem variações." : ($p['design_system'] === false ? "\n\nDESIGN SYSTEM: o cliente NÃO tem design system. Extraia o sistema da peça principal (cores, fontes, grafismos, respiro) e do site/rede social nas referências, e seja rigorosamente consistente entre as peças — você está definindo o sistema agora." : '')) .
        ($refTxt ? "\n\nREFERÊNCIAS QUE O HUMANO MANDOU (para olhar e se inspirar: ritmo, hierarquia, acabamento — nunca copiar texto, logo ou identidade de outra marca):\n- " . implode("\n- ", $refTxt) : '') .
        "\n\nAs imagens a seguir são as páginas da principal, na ordem (PÁGINA 1, 2…). Depois delas, miniaturas da galeria, cada uma precedida do seu ID.";
    $content = [['type' => 'text', 'text' => $txt]];
    foreach ($pngs as $i => $png) { $content[] = ['type' => 'text', 'text' => 'PÁGINA ' . ($i + 1) . ' · papel: ' . (trim((string)($papeis[$i] ?? '')) ?: 'principal')]; $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $png]]; }
    foreach ($thumbs as $t) { $content[] = ['type' => 'text', 'text' => 'ID ' . $t['id'] . ' · ' . $t['nome']]; $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $t['mime'], 'data' => $t['b64']]]; }
    foreach ($refImgs as $t) { $content[] = ['type' => 'text', 'text' => 'REFERÊNCIA (só inspiração): ' . $t['rot']]; $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $t['mime'], 'data' => $t['b64']]]; }
    try { [$resp, $custo, $us] = paClaude($system, $content, 32000); $plano = paJson($resp); if (!$plano || empty($plano['derivacoes'])) throw new Exception('A IA não devolveu o plano em JSON' . (($us['stop_reason'] ?? '') === 'max_tokens' ? ' (resposta cortada pelo limite de tamanho)' : '') . '. Início da resposta: ' . mb_substr($resp, 0, 200)); }
    catch (Exception $e) { sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'erro', 'erro' => $e->getMessage(), 'plano' => ['bruto' => mb_substr((string)($resp ?? ''), 0, 60000), 'stop' => $us['stop_reason'] ?? ''], 'mudado_em' => gmdate('c')]); sd_fail($e->getMessage(), 500); }
    $atual = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=status')['body'][0]['status'] ?? ''; if ($atual !== 'derivando') sd_fail('Rodada interrompida.', 409);   // quem pediu clicou em parar
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'revisao', 'plano' => $plano, 'custo_usd' => (float)$p['custo_usd'] + $custo, 'mudado_em' => gmdate('c')]);
    sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada', 'PATCH', ['arquivada' => true]);
    $quem = paQuemFaz($u); sd_supabase('/rest/v1/pd_ia_trabalho', 'POST', ['ia_user_id' => $quem['id'], 'personagem' => $quem['personagem'], 'client_id' => $p['client_id'], 'pauta_id' => $id, 'familia' => $famId, 'rodada' => $rodada, 'resultado' => 'derivou', 'custo_usd' => $custo]);
    $galeria = []; foreach ($imgs as $i) $galeria[$i['id']] = '/pd-estudio.php?img=' . rawurlencode($i['id']) . '&k=' . paAssina($i['id']) . '&w=2400';
    echo json_encode(['ok' => true, 'rodada' => $rodada, 'familia' => $famId, 'quem' => $quem, 'plano' => $plano, 'galeria' => $galeria, 'kit' => $kitSt, 'custo' => round($custo, 4)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'aprovar') {
    $id = (string)($b['id'] ?? ''); $p = sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id) . '&select=id,client_id,plano,rodada')['body'][0] ?? null; if (!$p) sd_fail('Pauta não encontrada.', 404);
    $specs = sd_supabase('/rest/v1/pd_pecas?pauta_id=eq.' . urlencode($id) . '&papel=eq.derivada&arquivada=eq.false&select=nome,derivada_tipo,spec,roteiro&order=ordem.asc')['body'] ?? [];
    $apr = ['rodada' => (int)$p['rodada'], 'derivacoes' => array_map(fn($s) => ['tipo' => $s['derivada_tipo'], 'titulo' => $s['nome'], 'spec' => $s['spec'], 'roteiro' => $s['roteiro']], $specs), 'raciocinio' => $p['plano']['raciocinio'] ?? ''];
    sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode($id), 'PATCH', ['status' => 'aprovada', 'aprovado' => $apr, 'mudado_em' => gmdate('c')]);
    $quem = paQuemFaz($u); sd_supabase('/rest/v1/pd_ia_trabalho', 'POST', ['ia_user_id' => $quem['id'], 'personagem' => $quem['personagem'], 'client_id' => $p['client_id'], 'pauta_id' => $id, 'familia' => '', 'rodada' => (int)$p['rodada'], 'resultado' => 'aprovada']);
    sd_supabase('/rest/v1/pd_estudio_licoes', 'POST', ['client_id' => $p['client_id'], 'pauta_id' => $id, 'tipo' => 'aprovacao', 'texto' => 'Pauta aprovada na rodada ' . $p['rodada'] . ' com ' . count($specs) . ' derivações (' . implode(', ', array_unique(array_column($specs, 'derivada_tipo'))) . '). Repetir a lógica.', 'por' => $u['id']]);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'produzida') { sd_supabase('/rest/v1/pd_pautas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['status' => 'produzida', 'mudado_em' => gmdate('c')]); echo json_encode(['ok' => true]); exit; }
sd_fail('Ação desconhecida.');
