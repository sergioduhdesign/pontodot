<?php
// =====================================================================
// Pontodot — ESTÚDIO (lado do servidor). Equipe/admin.
// Ações: listar · abrir · salvar · arquivar · exportar (PNG/JPG → bucket "pecas" + cópia no Drive: pasta do cliente › ESTÚDIO)
// Nada se apaga: peça some da lista quando arquivada.
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
@set_time_limit(120); @ini_set('memory_limit', '512M');
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
function esB64($s) { $s = (string)$s; if (strpos($s, ',') !== false) $s = substr($s, strpos($s, ',') + 1); $r = base64_decode($s, true); if ($r === false || $r === '') throw new Exception('Imagem inválida.'); return $r; }
function esPastaDrive($clientId) {   // pasta do cliente › ESTÚDIO (ou SD_DRIVE_OFICINA)
    if (!function_exists('ponte') || !defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) return null;
    $pai = null;
    if ($clientId) { $c = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($clientId) . '&select=name,drive_id')['body'][0] ?? null; $pai = $c['drive_id'] ?? null;
        if (!$pai && $c && defined('SD_DRIVE_CLIENTES') && SD_DRIVE_CLIENTES) { $p = ponte('pasta_garantir', ['pai_id' => SD_DRIVE_CLIENTES, 'nome' => $c['name'], 'criar' => true]); $pai = $p['id'] ?? null; } }
    if (!$pai && defined('SD_DRIVE_OFICINA') && SD_DRIVE_OFICINA) $pai = SD_DRIVE_OFICINA;
    if (!$pai) return null;
    $p = ponte('pasta_garantir', ['pai_id' => $pai, 'nome' => 'ESTÚDIO', 'criar' => true]); return $p['id'] ?? null;
}
$SEL = 'id,client_id,task_id,nome,formato,thumb_url,modelo,arquivada,criado_por,criado_em,mudado_em,exportada,clients(name)';

if ($acao === 'listar') {
    $q = '/rest/v1/pd_pecas?arquivada=eq.false&select=' . $SEL . '&order=mudado_em.desc&limit=120';
    if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']);
    if (!empty($b['modelos'])) $q .= '&modelo=eq.true';
    $r = sd_supabase($q); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v116 (estúdio) no Supabase.', 500);
    echo json_encode(['pecas' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'abrir') {
    $p = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)($b['id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$p) sd_fail('Peça não encontrada.', 404);
    echo json_encode(['peca' => $p], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'salvar') {
    $id = (string)($b['id'] ?? ''); $doc = is_array($b['doc'] ?? null) ? $b['doc'] : null; if (!$doc) sd_fail('Documento vazio.');
    if (empty($b['client_id']) && empty($b['modelo'])) sd_fail('Toda peça é de um cliente: escolha o cliente no topo (só modelos podem ficar sem).');
    $fmt = is_array($b['formato'] ?? null) ? ['w' => max(64, (int)$b['formato']['w']), 'h' => max(64, (int)$b['formato']['h']), 'nome' => mb_substr((string)($b['formato']['nome'] ?? ''), 0, 40)] : null;
    $d = ['nome' => mb_substr(trim((string)($b['nome'] ?? '')), 0, 120) ?: 'Sem nome', 'doc' => $doc, 'mudado_em' => gmdate('c'), 'client_id' => ($b['client_id'] ?? null) ?: null, 'task_id' => ($b['task_id'] ?? null) ?: null];
    if ($fmt) $d['formato'] = $fmt; if (isset($b['modelo'])) $d['modelo'] = (bool)$b['modelo'];
    if (!$id) { $d['criado_por'] = $u['id']; $r = sd_supabase('/rest/v1/pd_pecas', 'POST', $d, ['Prefer: return=representation']); $p = $r['body'][0] ?? null; if (!$p) sd_fail('Não consegui criar a peça: ' . ($r['raw'] ?? ''), 500); $id = $p['id']; }
    if (!empty($b['thumb'])) { try { $d2 = ['thumb_url' => esSobe('thumb/' . $id . '.jpg', esB64($b['thumb']), 'image/jpeg') . '?v=' . time()]; $d = array_merge($d, $d2); } catch (Exception $e) { /* miniatura é melhor esforço */ } }
    $r = sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id), 'PATCH', $d, ['Prefer: return=representation']); if (empty($r['body'][0])) sd_fail('Não consegui salvar: ' . ($r['raw'] ?? ''), 500);
    $p = $r['body'][0]; unset($p['doc']); echo json_encode(['ok' => true, 'peca' => $p], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'arquivar') {
    sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', ['arquivada' => !isset($b['arquivada']) || (bool)$b['arquivada'], 'mudado_em' => gmdate('c')]); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'exportar') {   // imagem final → bucket "pecas" + cópia no Drive
    $id = (string)($b['id'] ?? ''); $p = $id ? (sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id) . '&select=id,nome,client_id,exportada')['body'][0] ?? null) : null;
    $bytes = esB64($b['imagem'] ?? ''); if (strlen($bytes) > 25 * 1024 * 1024) sd_fail('Imagem grande demais (25 MB).');
    $ext = ($b['tipo'] ?? 'png') === 'jpg' ? 'jpg' : 'png'; $mime = $ext === 'jpg' ? 'image/jpeg' : 'image/png';
    $nome = preg_replace('/[^\w.\-]+/u', '_', mb_substr(trim((string)($b['nome'] ?? ($p['nome'] ?? 'peca'))), 0, 80)) . '-' . date('Ymd-Hi') . '.' . $ext;
    $url = esSobe('export/' . ($id ?: 'avulso') . '/' . $nome, $bytes, $mime);
    $drive = null; $aviso = null;
    try { $pasta = esPastaDrive($p['client_id'] ?? ($b['client_id'] ?? null));
        if ($pasta) { $g = ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'mime' => $mime, 'b64' => base64_encode($bytes), 'substituir' => false]); $drive = $g['url'] ?? null; }
        else $aviso = 'Sem pasta do Drive para este cliente (conecte a pasta do cliente ou defina SD_DRIVE_OFICINA).';
    } catch (Exception $e) { $aviso = 'Cópia pro Drive falhou: ' . $e->getMessage(); }
    if ($p) { $ex = is_array($p['exportada']) ? $p['exportada'] : []; array_unshift($ex, ['nome' => $nome, 'url' => $url, 'drive_url' => $drive, 'em' => gmdate('c')]);
        sd_supabase('/rest/v1/pd_pecas?id=eq.' . urlencode($id), 'PATCH', ['exportada' => array_slice($ex, 0, 20)]); }
    echo json_encode(['ok' => true, 'nome' => $nome, 'url' => $url, 'drive_url' => $drive, 'aviso' => $aviso], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
