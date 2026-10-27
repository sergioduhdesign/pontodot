<?php
// =====================================================================
// Pontodot — ESTÚDIO (lado do servidor). Equipe/admin.
// POST (sessão): listar · abrir · salvar · arquivar · exportar · galeria · subir · fontes
// GET  (assinado): ?img=<id do Drive>&k=<assinatura>&w=<largura>  → imagem da galeria do cliente (cópia local em cache)
//
// Regras da casa: toda peça é de um cliente (só modelo pode ficar sem). O acervo é o Drive:
//   cliente › MÍDIA     — galeria do cliente (fotos, vídeos, marcas; upload em lote cai aqui)
//   cliente › ESTÚDIO › <peça>  — exportações (todos os recortes)
// Exportações também ficam no bucket público "pecas" (miniaturas e cópia rápida).
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
@set_time_limit(170); @ini_set('memory_limit', '768M');

const ES_CACHE = __DIR__ . '/.cache-midia';
function esAssina($id) { return substr(hash_hmac('sha256', 'midia:' . $id, SD_SECRET_KEY), 0, 24); }
function esPonteOk() { return function_exists('ponte') && defined('SD_PONTE_URL') && defined('SD_PONTE_KEY') && SD_PONTE_KEY; }

// ---------------------------------------------------------------- GET: imagem da galeria (sem sessão; assinatura HMAC)
if (!empty($_GET['img'])) {
    $id = preg_replace('/[^\w\-]/', '', (string)$_GET['img']); $k = preg_replace('/[^a-f0-9]/', '', (string)($_GET['k'] ?? ''));
    $w = (int)($_GET['w'] ?? 0); $w = $w > 0 ? min(4000, max(100, $w)) : 0;
    if (!$id || !hash_equals(esAssina($id), $k)) { http_response_code(403); exit('endereço inválido'); }
    if (!is_dir(ES_CACHE)) { @mkdir(ES_CACHE, 0755, true); @file_put_contents(ES_CACHE . '/.htaccess', "Deny from all\n"); }
    $orig = ES_CACHE . '/' . $id; $tipo = $orig . '.tipo';
    if (!is_file($orig) || filesize($orig) === 0) {
        if (!esPonteOk()) { http_response_code(500); exit('ponte do Drive não configurada'); }
        try { $r = ponte('baixar', ['fileId' => $id]); } catch (Exception $e) { http_response_code(502); exit('não consegui buscar no Drive: ' . $e->getMessage()); }
        $bytes = base64_decode((string)($r['b64'] ?? ''), true); if (!$bytes) { http_response_code(502); exit('arquivo vazio'); }
        @file_put_contents($orig, $bytes); @file_put_contents($tipo, (string)($r['mime'] ?? 'image/jpeg'));
    }
    $mime = is_file($tipo) ? trim((string)file_get_contents($tipo)) : 'image/jpeg';
    $arq = $orig;
    if ($w && stripos($mime, 'image/') === 0 && stripos($mime, 'svg') === false && function_exists('imagecreatefromstring')) {
        $red = $orig . '-' . $w;
        if (!is_file($red)) { $im = @imagecreatefromstring((string)file_get_contents($orig));
            if ($im) { $ow = imagesx($im); $oh = imagesy($im); if ($ow > $w) { $nh = (int)round($oh * $w / $ow); $n = imagecreatetruecolor($w, $nh); imagealphablending($n, false); imagesavealpha($n, true); imagecopyresampled($n, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
                    if ($mime === 'image/png') imagepng($n, $red, 6); else imagejpeg($n, $red, 86); imagedestroy($n); } else { copy($orig, $red); } imagedestroy($im); } }
        if (is_file($red)) $arq = $red;
    }
    header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($arq)); header('Cache-Control: private, max-age=604800'); readfile($arq); exit;
}

// ---------------------------------------------------------------- POST: equipe
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'listar');

function esSobe($path, $bytes, $mime) {   // bucket público "pecas"
    $ch = curl_init(SD_SUPABASE_URL . '/storage/v1/object/pecas/' . implode('/', array_map('rawurlencode', explode('/', $path))));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY, 'Content-Type: ' . $mime, 'x-upsert: true']]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 300) throw new Exception('Não consegui guardar o arquivo (bucket "pecas" existe? rode o SQL v116). HTTP ' . $code);
    return SD_SUPABASE_URL . '/storage/v1/object/public/pecas/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}
function esB64($s) { $s = (string)$s; if (strpos($s, ',') !== false) $s = substr($s, strpos($s, ',') + 1); $r = base64_decode($s, true); if ($r === false || $r === '') throw new Exception('Arquivo inválido.'); return $r; }
function esCliente($clientId) { if (!$clientId) sd_fail('Escolha o cliente.'); $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=id,name,drive_id,drive_url')['body'][0] ?? null; if (!$c) sd_fail('Cliente não encontrado.', 404); return $c; }
function esPastaCliente($c) {   // pasta-raiz do cliente no Drive (cria dentro da pasta mestre, se precisar)
    if (!esPonteOk()) throw new Exception('Ponte do Drive não configurada.');
    $id = $c['drive_id'] ?? null;
    if (!$id) { if (!defined('SD_DRIVE_CLIENTES') || !SD_DRIVE_CLIENTES) throw new Exception('Cliente sem pasta no Drive e sem pasta mestre (SD_DRIVE_CLIENTES). Conecte a pasta do cliente em Clientes.');
        $p = ponte('pasta_garantir', ['pai_id' => SD_DRIVE_CLIENTES, 'nome' => $c['name'], 'criar' => true]); $id = $p['id'] ?? null; if (!$id) throw new Exception('Não consegui criar a pasta do cliente.');
        sd_supabase('/rest/v1/clients?id=eq.' . urlencode($c['id']), 'PATCH', ['drive_id' => $id, 'drive_url' => 'https://drive.google.com/drive/folders/' . $id]); }
    return $id;
}
function esSub($paiId, $nome) { $p = ponte('pasta_garantir', ['pai_id' => $paiId, 'nome' => $nome, 'criar' => true]); if (empty($p['id'])) throw new Exception('Não consegui abrir a pasta ' . $nome . ' no Drive.'); return $p['id']; }
function esNomeSeguro($s) { $s = preg_replace('/[\/\\\\:*?"<>|]+/u', '-', trim((string)$s)); return mb_substr($s, 0, 90) ?: 'peca'; }
$SEL = 'id,client_id,task_id,nome,formato,thumb_url,modelo,arquivada,criado_por,criado_em,mudado_em,exportada,pauta_id,papel,derivada_tipo,ordem,clients(name)';

if ($acao === 'listar') {
    $q = '/rest/v1/pd_pecas?arquivada=eq.false&select=' . $SEL . '&order=mudado_em.desc&limit=120';
    if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']);
    if (!empty($b['modelos'])) $q .= '&modelo=eq.true';
    if (empty($b['todas'])) $q .= '&papel=neq.derivada';   // derivadas aparecem dentro da pauta, não na lista geral
    $r = sd_supabase($q);
    if (($r['code'] ?? 500) >= 300) { $r = sd_supabase(str_replace([',pauta_id,papel,derivada_tipo,ordem', '&papel=neq.derivada'], '', $q)); }   // antes do SQL v117
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v116 (estúdio) no Supabase.', 500);
    echo json_encode(['pecas' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'abrir') {
    $p = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)($b['id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$p) sd_fail('Peça não encontrada.', 404);
    echo json_encode(['peca' => $p], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'salvar') {
    $id = (string)($b['id'] ?? ''); $doc = is_array($b['doc'] ?? null) ? $b['doc'] : null; if (!$doc) sd_fail('Documento vazio.');
    if (empty($b['client_id']) && empty($b['modelo'])) sd_fail('Toda peça é de um cliente: escolha o cliente no topo (só modelos podem ficar sem).');
    $fmt = is_array($b['formato'] ?? null) ? ['w' => max(64, (int)$b['formato']['w']), 'h' => max(64, (int)$b['formato']['h']), 'nome' => mb_substr((string)($b['formato']['nome'] ?? ''), 0, 40), 'tipo' => mb_substr((string)($b['formato']['tipo'] ?? ''), 0, 30)] : null;
    $d = ['nome' => mb_substr(trim((string)($b['nome'] ?? '')), 0, 120) ?: 'Sem nome', 'doc' => $doc, 'mudado_em' => gmdate('c'), 'client_id' => ($b['client_id'] ?? null) ?: null, 'task_id' => ($b['task_id'] ?? null) ?: null];
    if ($fmt) $d['formato'] = $fmt; if (isset($b['modelo'])) $d['modelo'] = (bool)$b['modelo'];
    foreach (['pauta_id' => 'id', 'papel' => 'txt', 'derivada_tipo' => 'txt', 'spec' => 'json', 'roteiro' => 'json', 'ordem' => 'int'] as $k => $t) if (array_key_exists($k, $b)) $d[$k] = $t === 'json' ? (is_array($b[$k]) ? $b[$k] : null) : ($t === 'int' ? (int)$b[$k] : (($b[$k] ?? null) ?: null));
    if (!$id) { $d['criado_por'] = $u['id']; $r = sd_supabase('/rest/v1/pd_pecas', 'POST', $d, ['Prefer: return=representation']); $p = $r['body'][0] ?? null; if (!$p) sd_fail('Não consegui criar a peça: ' . ($r['raw'] ?? ''), 500); $id = $p['id']; }
    if (!empty($b['thumb'])) { try { $d['thumb_url'] = esSobe('thumb/' . $id . '.jpg', esB64($b['thumb']), 'image/jpeg') . '?v=' . time(); } catch (Exception $e) { /* miniatura é melhor esforço */ } }
    $r = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']); if (empty($r['body'][0])) sd_fail('Não consegui salvar: ' . ($r['raw'] ?? ''), 500);
    $p = $r['body'][0]; unset($p['doc']); echo json_encode(['ok' => true, 'peca' => $p], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'arquivar') {
    sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['arquivada' => !isset($b['arquivada']) || (bool)$b['arquivada'], 'mudado_em' => gmdate('c')]); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'exportar') {   // várias imagens (os recortes) → bucket "pecas" + Drive: cliente › ESTÚDIO › <peça>
    $id = (string)($b['id'] ?? ''); $p = $id ? (sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id) . '&select=id,nome,client_id,exportada')['body'][0] ?? null) : null;
    $clientId = $p['client_id'] ?? ($b['client_id'] ?? null); if (!$clientId) sd_fail('Toda peça é de um cliente.');
    $base = esNomeSeguro($b['nome'] ?? ($p['nome'] ?? 'peca')); $lista = is_array($b['arquivos'] ?? null) ? $b['arquivos'] : [];
    if (!$lista && !empty($b['imagem'])) $lista = [['sufixo' => '', 'imagem' => $b['imagem'], 'tipo' => $b['tipo'] ?? 'png']];
    if (!$lista) sd_fail('Nada para exportar.');
    $pasta = null; $aviso = null;
    try { $c = esCliente($clientId); $pasta = esSub(esSub(esPastaCliente($c), 'ESTÚDIO'), $base); } catch (Exception $e) { $aviso = 'Sem Drive: ' . $e->getMessage(); }
    $saida = []; $carimbo = date('Ymd-Hi');
    foreach (array_slice($lista, 0, 12) as $a) {
        try { $bytes = esB64($a['imagem'] ?? ''); if (strlen($bytes) > 30 * 1024 * 1024) throw new Exception('imagem grande demais');
            $ext = ($a['tipo'] ?? 'png') === 'jpg' ? 'jpg' : 'png'; $mime = $ext === 'jpg' ? 'image/jpeg' : 'image/png';
            $suf = preg_replace('/[^\w\-]+/u', '', (string)($a['sufixo'] ?? '')); $nome = $base . ($suf ? '-' . $suf : '') . '-' . $carimbo . '.' . $ext;
            $url = esSobe('export/' . ($id ?: 'avulso') . '/' . $nome, $bytes, $mime); $drive = null;
            if ($pasta) { try { $g = ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'mime' => $mime, 'b64' => base64_encode($bytes), 'substituir' => false]); $drive = $g['url'] ?? null; } catch (Exception $e) { $aviso = 'Cópia pro Drive falhou: ' . $e->getMessage(); } }
            $saida[] = ['nome' => $nome, 'sufixo' => $suf, 'url' => $url, 'drive_url' => $drive, 'em' => gmdate('c')];
            pdAcervo('exportacao', 'estudio', ['categoria' => 'banco_fotos', 'client_id' => $clientId, 'nome' => $nome, 'url' => $drive ?: $url, 'mime' => $mime, 'bytes' => strlen($bytes), 'por' => $u['id']]);
        } catch (Exception $e) { $saida[] = ['sufixo' => (string)($a['sufixo'] ?? ''), 'erro' => $e->getMessage()]; }
    }
    if ($p) { $ex = is_array($p['exportada']) ? $p['exportada'] : []; $ex = array_merge(array_values(array_filter($saida, fn($x) => empty($x['erro']))), $ex); sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id), 'PATCH', ['exportada' => array_slice($ex, 0, 40)]); }
    echo json_encode(['ok' => true, 'arquivos' => $saida, 'pasta_url' => $pasta ? 'https://drive.google.com/drive/folders/' . $pasta : null, 'aviso' => $aviso], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'galeria') {   // tudo que está em cliente › MÍDIA (e subpastas): imagens, vídeos, marcas
    $c = esCliente((string)($b['client_id'] ?? ''));
    try { $pasta = esSub(esPastaCliente($c), 'MÍDIA'); $l = ponte('listar', ['folderId' => $pasta]); } catch (Exception $e) { sd_fail($e->getMessage(), 500); }
    $itens = [];
    foreach ((array)($l['arquivos'] ?? []) as $f) {
        $m = (string)($f['mime'] ?? ''); $ehImg = stripos($m, 'image/') === 0 && stripos($m, 'svg') === false; $ehVid = stripos($m, 'video/') === 0; $ehSvg = stripos($m, 'svg') !== false;
        if (!$ehImg && !$ehVid && !$ehSvg) continue;
        $k = esAssina($f['id']); $base = '/pd-estudio.php?img=' . rawurlencode($f['id']) . '&k=' . $k;
        $itens[] = ['id' => $f['id'], 'nome' => $f['name'], 'path' => $f['path'], 'tipo' => $ehVid ? 'video' : ($ehSvg ? 'vetor' : 'foto'), 'bytes' => (int)($f['size'] ?? 0), 'url' => $f['url'],
            'thumb' => ($ehImg || $ehSvg) ? $base . '&w=400' : null, 'cheia' => ($ehImg || $ehSvg) ? $base . '&w=2400' : null, 'modificado' => $f['modified'] ?? null];
    }
    usort($itens, fn($a, $b2) => strcmp((string)$b2['modificado'], (string)$a['modificado']));
    echo json_encode(['pasta_url' => 'https://drive.google.com/drive/folders/' . $pasta, 'itens' => $itens], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'subir') {   // upload em lote para cliente › MÍDIA (› subpasta opcional). Cada arquivo numa chamada; o navegador manda um por vez.
    $c = esCliente((string)($b['client_id'] ?? '')); $a = (array)($b['arquivo'] ?? []); if (empty($a['b64']) || empty($a['nome'])) sd_fail('Arquivo vazio.');
    $bytes = esB64($a['b64']); if (strlen($bytes) > 45 * 1024 * 1024) sd_fail('Por enquanto a ponte do Drive aceita até 45 MB por arquivo. Para maiores, suba direto na pasta MÍDIA do cliente no Drive.');
    try { $pasta = esSub(esPastaCliente($c), 'MÍDIA'); if (!empty($b['subpasta'])) $pasta = esSub($pasta, esNomeSeguro($b['subpasta']));
        $g = ponte('gravar', ['folderId' => $pasta, 'nome' => esNomeSeguro($a['nome']), 'mime' => (string)($a['mime'] ?? 'application/octet-stream'), 'b64' => base64_encode($bytes), 'substituir' => false]); }
    catch (Exception $e) { sd_fail($e->getMessage(), 500); }
    pdAcervo('upload', 'estudio', ['categoria' => stripos((string)($a['mime'] ?? ''), 'video/') === 0 ? 'videos' : 'banco_fotos', 'client_id' => $c['id'], 'nome' => esNomeSeguro($a['nome']), 'url' => (string)($g['url'] ?? ''), 'mime' => (string)($a['mime'] ?? ''), 'bytes' => strlen($bytes), 'por' => $u['id']]);
    echo json_encode(['ok' => true, 'id' => $g['id'] ?? null, 'url' => $g['url'] ?? null]); exit;
}
if ($acao === 'fontes') {   // status dos conectores de mídia (para o Estúdio avisar e para o admin ver)
    $f = ['pexels' => defined('SD_PEXELS_KEY') && SD_PEXELS_KEY, 'unsplash' => defined('SD_UNSPLASH_KEY') && SD_UNSPLASH_KEY, 'pixabay' => defined('SD_PIXABAY_KEY') && SD_PIXABAY_KEY, 'drive' => esPonteOk()];
    echo json_encode(['fontes' => $f]); exit;
}
sd_fail('Ação desconhecida.');
