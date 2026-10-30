<?php
// Hora de Catum — leitura do termômetro e motivo das correções. Equipe/admin.
// POST { acao: 'ler' } | { acao: 'motivo', item_id, motivo: erro_ia|mudanca_direcao|briefing_ambiguo, humano_min? }
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-catum-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'ler');

if ($acao === 'motivo') {
    $iid = (string)($b['item_id'] ?? ''); $mo = (string)($b['motivo'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $iid)) sd_fail('Peça inválida.');
    if (!in_array($mo, ['erro_ia', 'mudanca_direcao', 'briefing_ambiguo'], true)) sd_fail('Motivo inválido.');
    $hm = isset($b['humano_min']) && $b['humano_min'] !== '' && is_numeric($b['humano_min']) ? max(0, min(2000, (float)$b['humano_min'])) : null;
    $r = sd_supabase('/rest/v1/pd_catum_motivos', 'POST', ['item_id' => $iid, 'motivo' => $mo, 'humano_min' => $hm, 'por' => $u['id'], 'em' => gmdate('c')], ['Prefer: resolution=merge-duplicates,return=minimal']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v134 no Supabase.', 500);
    echo json_encode(['ok' => true]); exit;
}

// ler
$copys = sd_supabase('/rest/v1/pd_copys?set_id=not.is.null&select=id,nome,briefing,variantes,set_id,em&order=em.desc&limit=600')['body'] ?? [];
if (!is_array($copys)) $copys = [];
$sets = array_values(array_unique(array_filter(array_map(fn($c) => $c['set_id'] ?? null, $copys))));
$itens = [];
foreach (array_chunk($sets, 40) as $ch) {
    $r = sd_supabase('/rest/v1/approval_items?set_id=in.(' . implode(',', array_map('rawurlencode', $ch)) . ')&select=id,set_id,position,version,interno_ok,title&limit=3000')['body'] ?? [];
    if (is_array($r)) $itens = array_merge($itens, $r);
}
$motivos = [];
$rm = sd_supabase('/rest/v1/pd_catum_motivos?select=item_id,motivo,humano_min&limit=5000');
if (($rm['code'] ?? 500) < 300 && is_array($rm['body'])) foreach ($rm['body'] as $m) $motivos[$m['item_id']] = ['motivo' => $m['motivo'], 'humano_min' => $m['humano_min'] === null ? null : (float)$m['humano_min']];
$ref = 60.0; $refOrigem = 'padrão (tabela ainda não criada)';
$rr = sd_supabase('/rest/v1/pd_ref_tempos?codigo=eq.TX01&ativo=is.true&select=medio_min,versao,tarefa&order=versao.desc&limit=1');
if (($rr['code'] ?? 500) < 300 && !empty($rr['body'][0]['medio_min'])) { $ref = (float)$rr['body'][0]['medio_min']; $refOrigem = 'tabela de referência, versão ' . $rr['body'][0]['versao']; }
$mestre = strtolower((string)(pdQuemFazArea('copys')['personagem'] ?? 'sumi'));
$res = pdCatumCalcular($copys, $itens, $motivos, $ref, $mestre);
echo json_encode(['ok' => true, 'tarefa' => ['codigo' => 'TX01', 'nome' => 'Copy / texto publicitário', 'unidade' => '1 headline, texto até 150 palavras e CTA, oferta definida', 'ref_min' => $ref, 'ref_origem' => $refOrigem], 'tabela_motivos' => ($rm['code'] ?? 500) < 300] + $res, JSON_UNESCAPED_UNICODE);
