<?php
// Sala de aula — API do painel (só admin). Ações: painel · criar · passo · ver · validar · incubacao
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/pd-lib.php';
$u = sd_current_user(); if ($u['role'] !== 'admin') sd_fail('Só admin.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'painel');
@set_time_limit(180);

if ($acao === 'painel') {
    $cat = pdPersonagens(); $jeito = pdAulaSup('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=100')['body'] ?? [];
    $pf = pdAulaSup('/rest/v1/profiles?active=eq.true&client_id=is.null&role=in.(admin,team)&select=id,name&limit=60')['body'] ?? [];
    $nomeDe = []; foreach ($pf as $p) $nomeDe[$p['id']] = $p['name'];
    $ias = []; foreach ((array)$jeito as $j) { $pc = $cat[strtolower(trim((string)$j['personagem']))] ?? null;
        $ias[] = ['id' => $j['user_id'], 'personagem' => $j['personagem'], 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($pc[0] ?? $j['personagem']), 'dona' => $nomeDe[$j['user_id']] ?? '']; }
    $sk = pdAulaSup('/rest/v1/pd_habilidades?ativa=eq.true&select=skill_id,nome,finalidade,criterios&order=skill_id')['body'] ?? [];
    $aulas = pdAulaSup('/rest/v1/pd_aulas?select=id,ia_user_id,personagem,skill_id,modo,status,rodada,max_rodadas,nota_final,criada_em&order=criada_em.desc&limit=40');
    if (($aulas['code'] ?? 500) >= 300) sd_fail('Rode o SQL v94 (aulas) e o v111 no Supabase.', 500);
    $lic = pdAulaSup('/rest/v1/pd_licoes?select=id,personagem,skill_id,regra,exemplo_bom,exemplo_ruim,status,aula_id,criada_em&order=criada_em.desc&limit=60')['body'] ?? [];
    $notas = pdAulaSup('/rest/v1/pd_notas?select=ia_user_id,skill_id,nota,fonte,criada_em&order=criada_em.desc&limit=400')['body'] ?? [];
    $cfg = pdIncub();
    echo json_encode(['ias' => $ias, 'habilidades' => $sk, 'aulas' => $aulas['body'], 'licoes' => $lic, 'notas' => $notas, 'incubacao' => $cfg,
        'professor' => (defined('SD_OPENAI_KEY') && SD_OPENAI_KEY) ? 'GPT' : 'Claude (falta SD_OPENAI_KEY no sd-config.php para o GPT ser o professor)', 'imagick' => class_exists('Imagick')], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'criar') {
    $ia = (string)($b['ia_user_id'] ?? ''); $skill = (string)($b['skill_id'] ?? ''); $pers = (string)($b['personagem'] ?? '');
    if (!$ia || !$skill) sd_fail('Escolha a IA e a habilidade.');
    $a = pdAulaCriar($ia, $pers, $skill); if (!$a) sd_fail('Não consegui criar a aula (rode o SQL v94).', 500);
    echo json_encode(['ok' => true, 'id' => $a['id']]); exit;
}
if ($acao === 'passo') { echo json_encode(pdAulaPasso((string)($b['aula_id'] ?? '')), JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'ver') {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode((string)($b['aula_id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$a) sd_fail('Aula não encontrada.', 404);
    $l = pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($a['id']) . '&select=id,regra,exemplo_bom,exemplo_ruim,status')['body'] ?? [];
    echo json_encode(['aula' => $a, 'licoes' => $l], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'validar') { pdAulaValidar((string)($b['aula_id'] ?? ''), !empty($b['aprovar']), (string)($b['parecer'] ?? ''), $u['id']); echo json_encode(['ok' => true]); exit; }
if ($acao === 'licao') {   // o gestor aprova/recusa/aposenta uma lição avulsa
    $st = (string)($b['status'] ?? ''); if (!in_array($st, ['aprovada', 'recusada', 'aposentada'], true)) sd_fail('Status inválido.');
    $d = ['status' => $st]; if ($st === 'aprovada') { $d['aprovada_por'] = $u['id']; $d['aprovada_em'] = gmdate('c'); }
    if (isset($b['comum'])) $d['comum'] = (bool)$b['comum'];
    pdAulaSup('/rest/v1/pd_licoes?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', $d); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'incubacao') {
    $d = []; foreach (['ativa' => 'bool', 'sandbox' => 'bool', 'intervalo_horas' => 'int', 'teto_gasto_dia' => 'num', 'nota_minima' => 'num'] as $k => $t) if (isset($b[$k])) $d[$k] = $t === 'bool' ? (bool)$b[$k] : ($t === 'int' ? max(1, (int)$b[$k]) : (float)$b[$k]);
    if ($d) pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', $d); echo json_encode(['ok' => true, 'incubacao' => pdIncub()]); exit;
}
sd_fail('Ação desconhecida.');
