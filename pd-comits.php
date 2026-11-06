<?php
// COMITS — POST { acao: pessoas | listar | criar | ver | enviar | add | fechar | reabrir | publico | buscar | painel }
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-comits-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); $b = sd_body(); $acao = (string)($b['acao'] ?? 'listar');
/** manda a resposta e solta a pessoa NA HORA (Content-Length + fechar conexão); o trabalho das IAs segue por trás */
function soltaEContinua($arr) {
    ignore_user_abort(true); @set_time_limit(180);
    $o = json_encode($arr, JSON_UNESCAPED_UNICODE); header('Content-Length: ' . strlen($o)); header('Connection: close'); echo $o;
    while (ob_get_level() > 0) @ob_end_flush(); @flush();
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
}
function ok($d = []) { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
// as tabelas existem? (SQL v170) — sem isso, resposta clara em vez de erro 500
if (in_array($acao, ['listar', 'criar'], true)) { $sonda = sd_supabase('/rest/v1/pd_comits?select=id&limit=1');
if (($sonda['code'] ?? 500) >= 300) sd_fail('Falta rodar o SQL v170 (migration_v170_comits.sql) no Supabase.', 503); }
$eu = $u['id']; $nome = $u['name'] ?? ($u['email'] ?? 'Alguém'); $adm = cmtEhAdmin($u); $cliente = !empty($u['client_id']);

if ($acao === 'pessoas') { $p = cmtPessoas(); if ($cliente) $p['humanos'] = array_values(array_filter($p['humanos'], fn($x) => $x['ref'] === $eu)); ok($p + ['admin' => $adm]); }

if ($acao === 'listar') {
    $st = ($b['status'] ?? 'aberto') === 'fechado' ? 'fechado' : 'aberto';
    $meus = (array)(sd_supabase('/rest/v1/pd_comit_membros?ref=eq.' . urlencode($eu) . '&select=comit_id')['body'] ?? []);
    $ids = array_values(array_unique(array_map(fn($x) => is_array($x) ? ($x['comit_id'] ?? '') : '', $meus)));
    $q = '/rest/v1/pd_comits?status=eq.' . $st . '&select=id,titulo,assunto,status,publico,ultima_em,created_at,fechado_em&order=ultima_em.desc&limit=200';
    if (!$adm) $q .= $cliente ? '&id=in.(' . ($ids ? implode(',', $ids) : '00000000-0000-0000-0000-000000000000') . ')' : '&or=(publico.is.true' . ($ids ? ',id.in.(' . implode(',', $ids) . ')' : '') . ')';
    $L = (array)(sd_supabase($q)['body'] ?? []);
    if (isset($L['message']) || ($L && !isset($L[0]['id']))) sd_fail('Rode o SQL v170 no Supabase.', 500);
    if ($L) { $todosIds = implode(',', array_column($L, 'id')); $mm = (array)(sd_supabase('/rest/v1/pd_comit_membros?comit_id=in.(' . $todosIds . ')&select=comit_id,tipo,ref,nome')['body'] ?? []);
        $por = []; foreach ($mm as $m) $por[$m['comit_id']][] = $m;
        // UMA consulta só para as últimas mensagens (antes eram 200 idas ao banco, uma por comit)
        $ult = []; foreach ((array)(sd_supabase('/rest/v1/pd_comit_msgs?comit_id=in.(' . $todosIds . ')&select=comit_id,autor_nome,texto&order=id.desc&limit=' . min(1500, max(60, count($L) * 6)))['body'] ?? []) as $x) if (is_array($x) && isset($x['comit_id']) && !isset($ult[$x['comit_id']])) $ult[$x['comit_id']] = $x;
        foreach ($L as &$c) { $c['membros'] = $por[$c['id']] ?? []; $c['sou_membro'] = (bool)array_filter($c['membros'], fn($m) => $m['ref'] === $eu); $u2 = $ult[$c['id']] ?? null; $c['ultima'] = $u2 ? $u2['autor_nome'] . ': ' . mb_substr($u2['texto'], 0, 90) : ''; } unset($c); }
    ok(['comits' => $L, 'admin' => $adm]);
}
if ($acao === 'criar') {
    $mem = array_slice((array)($b['membros'] ?? []), 0, 30);
    if ($cliente) $mem = array_values(array_filter($mem, fn($m) => ($m['tipo'] ?? '') === 'ia' || ($m['ref'] ?? '') === $eu));
    [$c, $e] = cmtCriar($b['titulo'] ?? '', $b['assunto'] ?? '', $mem, $eu, null, !empty($b['publico']) && !$cliente, $nome);
    if (!$c) sd_fail($e);
    cmtMsg($c['id'], 'sistema', null, 'PD', $nome . ' abriu o comit.');
    soltaEContinua(['ok' => true, 'comit' => $c]);
    try { $mm = cmtMembros($c['id']); if (array_filter($mm, fn($m) => $m['tipo'] === 'ia')) cmtIAsResponderem($c, $mm, 'ABERTURA DO COMIT "' . $c['titulo'] . '"' . ($c['assunto'] ? ': ' . $c['assunto'] : ''), 0, 'humano', true, $u); } catch (Throwable $e) {}
    exit;
}
// daqui pra baixo: precisa de um comit que a pessoa possa ver
$c = null; $mem = [];
if (in_array($acao, ['ver', 'enviar', 'add', 'fechar', 'reabrir', 'publico'], true)) {
    $c = cmtComit($b['id'] ?? ''); if (!$c) sd_fail('Comit não encontrado.', 404);
    $mem = cmtMembros($c['id']); if (!cmtPode($u, $c, $mem)) sd_fail('Sem acesso a este comit.', 403);
}
if ($acao === 'ver') {
    $depois = (int)($b['depois'] ?? 0);
    $ms = (array)(sd_supabase('/rest/v1/pd_comit_msgs?comit_id=eq.' . urlencode($c['id']) . ($depois ? '&id=gt.' . $depois : '') . '&select=id,autor_tipo,autor_ref,autor_nome,texto,importante,created_at&order=id.' . ($depois ? 'asc' : 'desc') . '&limit=300')['body'] ?? []);
    if (!$depois) $ms = array_reverse($ms);
    $log = $adm && !$depois ? (array)(sd_supabase('/rest/v1/pd_comit_log?comit_id=eq.' . urlencode($c['id']) . '&select=acao,quem,detalhe,em&order=id.asc&limit=100')['body'] ?? []) : [];
    ok(['comit' => $c, 'membros' => $mem, 'msgs' => $ms, 'log' => $log, 'admin' => $adm, 'sou_membro' => (bool)array_filter($mem, fn($m) => $m['ref'] === $eu)]);
}
if ($acao === 'enviar') {
    if ($c['status'] === 'fechado') sd_fail('Este comit está fechado.');
    $t = trim((string)($b['texto'] ?? '')); if ($t === '') sd_fail('Escreva a mensagem.');
    $souMem = (bool)array_filter($mem, fn($m) => $m['ref'] === $eu); if (!$souMem) { cmtAddMembro($c['id'], 'humano', $eu, $nome); $mem = cmtMembros($c['id']); }
    $imp = !empty($b['importante']) || preg_match('/\b(urgente|importante)\b/iu', $t);
    $m = cmtMsg($c['id'], $cliente ? 'cliente' : 'humano', $eu, $nome, $t, $imp);
    // a pessoa já tem a resposta; avisos e respostas das IAs seguem depois de enviar a resposta
    soltaEContinua(['ok' => true, 'msg' => $m]);
    cmtAvisar($c, $mem, $eu, $nome, $t, $imp);
    cmtIAsResponderem($c, $mem, $t, 0, 'humano', false, $u);
    exit;
}
if ($acao === 'add') {
    if ($c['status'] === 'fechado') sd_fail('Este comit está fechado.');
    if ($cliente) sd_fail('Só a equipe adiciona participantes.', 403);
    $n = 0; foreach ((array)($b['membros'] ?? []) as $m) if (cmtAddMembro($c['id'], $m['tipo'] ?? 'humano', $m['ref'] ?? '', $nome)) $n++;
    if ($n) cmtMsg($c['id'], 'sistema', null, 'PD', $nome . ' adicionou ' . $n . ' participante(s).');
    ok(['membros' => cmtMembros($c['id'])]);
}
if ($acao === 'fechar' || $acao === 'reabrir') {
    if (!$adm) sd_fail('Só um perfil admin fecha ou reabre comits.', 403);
    if ($acao === 'fechar') { cmtFechar($c, $nome); sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode($c['id']), 'PATCH', ['fechado_por' => $eu]); }
    else { sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode($c['id']), 'PATCH', ['status' => 'aberto', 'fechado_em' => null, 'fechado_por' => null]); cmtMsg($c['id'], 'sistema', null, 'PD', $nome . ' reabriu o comit.'); cmtLog($c['id'], 'reabriu', $nome); }
    ok();
}
if ($acao === 'publico') {
    $dono = $c['criado_por'] === $eu || $adm; if (!$dono) sd_fail('Só quem criou muda isso.', 403);
    $novo = !$c['publico']; sd_supabase('/rest/v1/pd_comits?id=eq.' . urlencode($c['id']), 'PATCH', ['publico' => $novo]); cmtLog($c['id'], $novo ? 'publico' : 'privado', $nome);
    ok(['publico' => $novo]);
}
if ($acao === 'buscar') {
    $q = trim((string)($b['q'] ?? '')); if (mb_strlen($q) < 2) ok(['grupos' => []]);
    $meus = (array)(sd_supabase('/rest/v1/pd_comit_membros?ref=eq.' . urlencode($eu) . '&select=comit_id')['body'] ?? []);
    $ids = array_values(array_unique(array_map(fn($x) => is_array($x) ? ($x['comit_id'] ?? '') : '', $meus)));
    $f = '/rest/v1/pd_comit_msgs?texto=ilike.' . rawurlencode('*' . str_replace(['*', ','], ' ', $q) . '*') . '&select=id,comit_id,autor_nome,texto,created_at,pd_comits(titulo,status,publico)&order=id.desc&limit=120';
    if (!$adm) $f .= '&comit_id=in.(' . ($ids ? implode(',', $ids) : '00000000-0000-0000-0000-000000000000') . ')';
    $hits = (array)(sd_supabase($f)['body'] ?? []); $g = [];
    foreach ($hits as $h) { if (!isset($h['comit_id'])) continue; $k = $h['comit_id']; $g[$k]['id'] = $k; $g[$k]['titulo'] = $h['pd_comits']['titulo'] ?? 'Comit'; $g[$k]['status'] = $h['pd_comits']['status'] ?? ''; $g[$k]['achados'][] = ['msg' => $h['id'], 'de' => $h['autor_nome'], 'texto' => mb_substr($h['texto'], 0, 200), 'em' => $h['created_at']]; }
    ok(['grupos' => array_values($g)]);
}
if ($acao === 'painel') {
    if (!$adm) sd_fail('Só admin.', 403);
    $L = (array)(sd_supabase('/rest/v1/pd_comits?select=id,titulo,status,publico,created_at,ultima_em,fechado_em,pd_comit_membros(tipo)&order=ultima_em.desc&limit=300')['body'] ?? []);
    foreach ($L as &$c2) { $c2['n_humanos'] = count(array_filter($c2['pd_comit_membros'] ?? [], fn($m) => $m['tipo'] !== 'ia')); $c2['n_ias'] = count(array_filter($c2['pd_comit_membros'] ?? [], fn($m) => $m['tipo'] === 'ia')); unset($c2['pd_comit_membros']); } unset($c2);
    ok(['comits' => $L]);
}
sd_fail('Ação desconhecida.');
