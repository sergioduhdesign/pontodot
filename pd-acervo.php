<?php
// =====================================================================
// Pontodot — ACERVO: links habilitados para clientes (bancos de fotos, elementos, vídeos, marca…) e o
// histórico de tudo que entrou na casa (uploads do Estúdio, banco de mídia, exportações). Quem, para quem, quando, link.
// Ações: listar · link_salvar (equipe/admin) · apagar (só admin; nunca apaga arquivo, só o registro de link)
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'listar');
if ($acao === 'listar') {
    $q = '/rest/v1/pd_acervo?select=id,tipo,origem,categoria,client_id,task_id,nome,url,mime,bytes,nota,em,clients(name),profiles(name)&order=em.desc&limit=' . max(10, min(300, (int)($b['limite'] ?? 80)));
    if (!empty($b['client_id'])) $q .= '&client_id=eq.' . urlencode((string)$b['client_id']); if (!empty($b['tipo'])) $q .= '&tipo=eq.' . urlencode((string)$b['tipo']);
    $r = sd_supabase($q); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v118 (acervo) no Supabase.', 500);
    echo json_encode(['itens' => $r['body'] ?? []], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'link_salvar') {   // habilita um link de conteúdo para um cliente (não sobe nada)
    $url = trim((string)($b['url'] ?? '')); if (!preg_match('~^https?://~i', $url)) sd_fail('Cole um link válido (https://…).');
    if (empty($b['client_id'])) sd_fail('Para qual cliente?');
    $cat = in_array($b['categoria'] ?? '', ['banco_fotos', 'elementos', 'videos', 'marca', 'documento', 'outro'], true) ? $b['categoria'] : 'outro';
    $d = ['tipo' => 'link', 'origem' => 'painel', 'categoria' => $cat, 'client_id' => (string)$b['client_id'], 'nome' => mb_substr(trim((string)($b['nome'] ?? '')), 0, 140) ?: $url, 'url' => mb_substr($url, 0, 1000), 'nota' => mb_substr((string)($b['nota'] ?? ''), 0, 500), 'por' => $u['id']];
    $r = sd_supabase('/rest/v1/pd_acervo', 'POST', $d, ['Prefer: return=representation']); if (empty($r['body'][0])) sd_fail('Não consegui registrar: ' . ($r['raw'] ?? ''), 500);
    echo json_encode(['ok' => true, 'item' => $r['body'][0]], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'apagar') { if ($u['role'] !== 'admin') sd_fail('Só admin.', 403); sd_supabase('/rest/v1/pd_acervo?id=eq.' . (int)($b['id'] ?? 0) . '&tipo=eq.link', 'DELETE'); echo json_encode(['ok' => true]); exit; }
sd_fail('Ação desconhecida.');
