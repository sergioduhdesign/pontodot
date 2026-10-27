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
    $aulas = pdAulaSup('/rest/v1/pd_aulas?select=id,ia_user_id,personagem,skill_id,modo,status,rodada,max_rodadas,nota_final,criada_em,chamado&order=criada_em.desc&limit=40');
    if (($aulas['code'] ?? 500) >= 300) sd_fail('Rode o SQL v94 (aulas) e o v111 no Supabase.', 500);
    $lic = pdAulaSup('/rest/v1/pd_licoes?select=id,personagem,skill_id,regra,exemplo_bom,exemplo_ruim,status,aula_id,criada_em&order=criada_em.desc&limit=60')['body'] ?? [];
    $notas = pdAulaSup('/rest/v1/pd_notas?select=ia_user_id,skill_id,nota,fonte,criada_em&order=criada_em.desc&limit=400')['body'] ?? [];
    $cfg = pdIncub();
    $fr = pdAulaSup('/rest/v1/pd_formacoes?select=*&order=criada_em.asc&limit=200'); if (($fr['code'] ?? 500) >= 300) sd_fail('Rode o SQL v112 (formação) no Supabase.', 500); $forms = $fr['body'] ?? [];
    foreach ($forms as &$f) { $f['sit'] = pdFormacaoSituacao($f['ia_user_id'], $f['skill_id'], $cfg); $f['meta'] = (int)($f['meta_provas'] ?: ($cfg['provas_meta'] ?? 30)); } unset($f);
    $gpt = (defined('SD_OPENAI_KEY') && SD_OPENAI_KEY) ? 'GPT (' . (defined('SD_PROFESSOR_MODEL') ? SD_PROFESSOR_MODEL : 'gpt-4.1') . ')' : null;
    echo json_encode(['formacoes' => $forms, 'externo_ok' => pdPonteOk(), 'ias' => $ias, 'habilidades' => $sk, 'aulas' => $aulas['body'], 'licoes' => $lic, 'notas' => $notas, 'incubacao' => $cfg,
        'professor' => ($cfg['professor_modo'] ?? 'projeto_gpt') === 'api' ? ($gpt ? 'PILOTO: ' . $gpt . ' por API (GPT cru, sem a bagagem do Sérgio)' : 'API sem chave (SD_OPENAI_KEY)') : 'GPT do Projeto “' . pdProjetoProfessor($cfg) . '” (o dot só transporta)', 'gpt_ligado' => (bool)$gpt, 'imagick' => class_exists('Imagick')], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'criar') {
    $ia = (string)($b['ia_user_id'] ?? ''); $skill = (string)($b['skill_id'] ?? ''); $pers = (string)($b['personagem'] ?? '');
    if (!$ia || !$skill) sd_fail('Escolha a IA e a habilidade.');
    $teste = !empty($b['teste']);
    if (!$teste) pdFormacaoMatricula($ia, $pers, $skill);
    $a = pdAulaCriar($ia, $pers, $skill, $teste ? 'teste' : 'prova'); if (!$a) sd_fail('Não consegui criar a aula (rode o SQL v94).', 500);
    echo json_encode(['ok' => true, 'id' => $a['id']]); exit;
}
if ($acao === 'passo') { echo json_encode(pdAulaPasso((string)($b['aula_id'] ?? '')), JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'ver') {
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode((string)($b['aula_id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$a) sd_fail('Aula não encontrada.', 404);
    $l = pdAulaSup('/rest/v1/pd_licoes?aula_id=eq.' . urlencode($a['id']) . '&select=id,regra,exemplo_bom,exemplo_ruim,status')['body'] ?? [];
    echo json_encode(['aula' => $a, 'licoes' => $l], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'validar') {
    $dec = (string)($b['decisao'] ?? (!empty($b['aprovar']) ? 'aprovar' : 'reprovar')); if (!in_array($dec, ['aprovar', 'revisar', 'reprovar'], true)) sd_fail('Decisão inválida.');
    if ($dec === 'revisar' && trim((string)($b['parecer'] ?? '')) === '') sd_fail('Escreva o comentário para a revisão.');
    pdAulaValidar((string)($b['aula_id'] ?? ''), $dec, (string)($b['parecer'] ?? ''), $u['id'], (string)($b['licao_regra'] ?? '')); echo json_encode(['ok' => true]); exit; }
if ($acao === 'reenviar') {   // reenvio MANUAL do aviso ao dot (nada é reenviado sozinho)
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode((string)($b['aula_id'] ?? '')) . '&select=*')['body'][0] ?? null; if (!$a || $a['status'] !== 'em_correcao') sd_fail('Só dá para reenviar uma aula que está com o professor.');
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? [];
    echo json_encode(pdProfessorEnviar($a, $sk, pdIncub() ?: [], true), JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'matricular') {   // liga/desliga a formação de uma IA numa habilidade
    $ia = (string)($b['ia_user_id'] ?? ''); $sk = (string)($b['skill_id'] ?? ''); if (!$ia || !$sk) sd_fail('Escolha a IA e a habilidade.');
    if (isset($b['ativa']) && !$b['ativa']) { pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . urlencode($ia) . '&skill_id=eq.' . urlencode($sk), 'PATCH', ['ativa' => false]); }
    else pdFormacaoMatricula($ia, (string)($b['personagem'] ?? ''), $sk);
    echo json_encode(['ok' => true]); exit; }
if ($acao === 'testar_gpt') {   // o GPT está mesmo ligado? responde com o motivo claro
    if (!defined('SD_OPENAI_KEY') || !SD_OPENAI_KEY) { echo json_encode(['ok' => false, 'msg' => 'Falta a chave: defina SD_OPENAI_KEY no sd-config.php do servidor.']); exit; }
    $ch = curl_init('https://api.openai.com/v1/models'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SD_OPENAI_KEY]]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $m = defined('SD_PROFESSOR_MODEL') ? SD_PROFESSOR_MODEL : 'gpt-4.1';
    if ($code === 200) { $ids = array_column((array)(json_decode((string)$raw, true)['data'] ?? []), 'id'); $tem = in_array($m, $ids, true);
        echo json_encode(['ok' => $tem, 'msg' => $tem ? 'GPT conectado. Modelo ' . $m . ' disponível — ele é o professor.' : 'Chave válida, mas o modelo ' . $m . ' não está liberado nessa conta. Defina SD_PROFESSOR_MODEL com um modelo que apareça na sua conta.']); exit; }
    echo json_encode(['ok' => false, 'msg' => $code === 401 ? 'A OpenAI recusou a chave (inválida ou revogada).' : ($code === 429 ? 'Chave válida, mas sem saldo/limite na conta da API.' : 'A OpenAI respondeu HTTP ' . $code . '.')]); exit; }
if ($acao === 'licao') {   // o gestor aprova/recusa/aposenta uma lição avulsa
    $st = (string)($b['status'] ?? ''); if (!in_array($st, ['aprovada', 'recusada', 'aposentada'], true)) sd_fail('Status inválido.');
    $d = ['status' => $st]; if ($st === 'aprovada') { $d['aprovada_por'] = $u['id']; $d['aprovada_em'] = gmdate('c'); }
    if (isset($b['comum'])) { $d['comum'] = (bool)$b['comum'];
        if ($d['comum']) { $l0 = pdAulaSup('/rest/v1/pd_licoes?id=eq.' . urlencode((string)($b['id'] ?? '')) . '&select=escopo')['body'][0] ?? []; if (($l0['escopo'] ?? '') === 'cliente') sd_fail('Lição específica de cliente não pode virar regra geral.'); } }
    pdAulaSup('/rest/v1/pd_licoes?id=eq.' . urlencode((string)($b['id'] ?? '')), 'PATCH', $d); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'incubacao') {
    if (!empty($b['ativa'])) { $c0 = pdIncub(); if (empty($c0['teste_confirmado_em'])) sd_fail('Antes de ligar a rotina: rode e confirme a rodada de teste ponta a ponta (PD → dot → Projeto do ChatGPT → dot → PD).'); }
    if (isset($b['professor_projeto'])) $d0['professor_projeto'] = mb_substr(trim((string)$b['professor_projeto']), 0, 120);
    $d = $d0 ?? []; foreach (['ativa' => 'bool', 'sandbox' => 'bool', 'boas_para_apto' => 'int', 'provas_meta' => 'int', 'chamados_dia' => 'int', 'professor_modo' => 'txt', 'intervalo_horas' => 'int', 'teto_gasto_dia' => 'num', 'nota_minima' => 'num'] as $k => $t) if (isset($b[$k])) $d[$k] = $t === 'txt' ? (in_array($b[$k], ['projeto_gpt', 'api'], true) ? $b[$k] : 'projeto_gpt') : ($t === 'bool' ? (bool)$b[$k] : ($t === 'int' ? max(1, (int)$b[$k]) : (float)$b[$k]));
    if ($d) pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', $d); echo json_encode(['ok' => true, 'incubacao' => pdIncub()]); exit;
}
sd_fail('Ação desconhecida.');
