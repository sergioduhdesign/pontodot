<?php
// Escola para o HUMANO (não só admin). Ações: inicio · matricular · receita_salvar
// e, só admin: curso_salvar · perm_lista · perm_salvar · resumo_admin.
// Permissões por pessoa em pd_escola_perm (matricular / acompanhar / criar); admin tem todas.
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/pd-lib.php';
$u = sd_current_user(); $b = sd_body(); $acao = (string)($b['acao'] ?? 'inicio');
@set_time_limit(60);
$ehAdmin = $u['role'] === 'admin';

function pdEscPerm($u) {
    if ($u['role'] === 'admin') return ['matricular' => true, 'acompanhar' => true, 'criar' => true, 'admin' => true];
    $r = pdAulaSup('/rest/v1/pd_escola_perm?user_id=eq.' . urlencode($u['id']) . '&select=matricular,acompanhar,criar&limit=1')['body'][0] ?? [];
    return ['matricular' => !empty($r['matricular']), 'acompanhar' => !empty($r['acompanhar']), 'criar' => !empty($r['criar']), 'admin' => false];
}
function pdEscCatalogo() {
    $j = json_decode((string)@file_get_contents(__DIR__ . '/pd/escola-cursos.json'), true); $base = $j['cursos'] ?? [];
    $extra = pdAulaSup('/rest/v1/pd_cursos?status=eq.aberto&select=*&order=criada_em.asc&limit=100');
    $out = $base;
    if (($extra['code'] ?? 500) < 300) foreach ((array)($extra['body'] ?? []) as $c) $out[] = ['slug' => $c['slug'], 'personagem' => '', 'familia' => 'Criado na plataforma', 'titulo' => $c['nome'], 'nome' => $c['nome'], 'descricao' => $c['descricao'], 'exercicio' => $c['exercicio'], 'evidencia' => '', 'transferencia' => '', 'criterios' => $c['criterios'], 'skill_ids' => $c['skill_ids'], 'status' => 'ativo', 'icone' => $c['icone'], 'responsavel' => $c['responsavel'], 'vagas' => $c['vagas']];
    return $out;
}
$perm = pdEscPerm($u);

if ($acao === 'inicio') {
    $cursos = pdEscCatalogo();
    $forms = pdAulaSup('/rest/v1/pd_formacoes?select=ia_user_id,skill_id&ativa=eq.true&limit=1000')['body'] ?? [];
    $alunos = []; foreach ($forms as $f) $alunos[$f['skill_id']][$f['ia_user_id']] = 1;
    foreach ($cursos as &$c) { $s = []; foreach ((array)($c['skill_ids'] ?? []) as $k) foreach (array_keys($alunos[$k] ?? []) as $i) $s[$i] = 1; $c['alunos'] = count($s); } unset($c);
    $skAll = pdAulaSup('/rest/v1/pd_habilidades?ativa=eq.true&select=skill_id,nome,finalidade,criterios&order=skill_id')['body'] ?? [];
    $out = ['perm' => $perm, 'cursos' => $cursos, 'habilidades' => $skAll, 'minha' => null];
    $j = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=personagem,apelido,etapa&limit=1');
    $je = $j['body'][0] ?? null;
    if ($je) { $x = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=instrucao_extra&limit=1'); $je['instrucao_extra'] = ($x['code'] ?? 500) < 300 ? ($x['body'][0]['instrucao_extra'] ?? '') : ''; }
    if (($perm['acompanhar'] || $perm['matricular']) && $je) {
        $cat = pdPersonagens(); $pc = $cat[strtolower(trim((string)$je['personagem']))] ?? null;
        $nome = trim((string)($je['apelido'] ?? '')) ?: ($pc[0] ?? $je['personagem']);
        $mine = ['ia' => $nome, 'personagem' => $je['personagem'], 'etapa' => $je['etapa'] ?? null, 'formacoes' => [], 'amostras' => [], 'marcos' => [], 'receita' => null, 'instrucao_extra' => (string)($je['instrucao_extra'] ?? '')];
        $fr = pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . urlencode($u['id']) . '&select=*&order=criada_em.asc')['body'] ?? [];
        $cfg = pdIncub() ?: []; $nomeSk = []; foreach ($skAll as $s) $nomeSk[$s['skill_id']] = $s['nome'];
        foreach ($fr as $f) { $sit = pdFormacaoSituacao($u['id'], $f['skill_id'], $cfg);
            $mine['formacoes'][] = ['skill_id' => $f['skill_id'], 'nome' => $nomeSk[$f['skill_id']] ?? $f['skill_id'], 'ativa' => (bool)$f['ativa'], 'apta_em' => $f['apta_em'], 'pasta_url' => $f['pasta_url'] ?? null,
                'feitas' => $sit['fechadas'], 'boas' => $sit['boas'], 'seguidas' => min($sit['seguidas'], $sit['boas_para_apto']), 'para_apto' => $sit['boas_para_apto'], 'meta' => (int)($f['meta_provas'] ?: ($cfg['provas_meta'] ?? 30)), 'hist' => $sit['hist']];
            $mine['marcos'][] = ['quando' => $f['criada_em'], 'texto' => 'Matriculada em ' . ($nomeSk[$f['skill_id']] ?? $f['skill_id'])];
            if (!empty($f['apta_em'])) $mine['marcos'][] = ['quando' => $f['apta_em'], 'texto' => 'Apta em ' . ($nomeSk[$f['skill_id']] ?? $f['skill_id']) . ' — entregas boas em sequência'];
        }
        $au = pdAulaSup('/rest/v1/pd_aulas?ia_user_id=eq.' . urlencode($u['id']) . '&modo=neq.teste&status=in.(validada,reprovada)&order=criada_em.desc&limit=8&select=id,skill_id,status,criada_em,validada_em,entregas,chamado')['body'] ?? [];
        foreach ($au as $a) { $e = (array)($a['entregas'] ?? []); $ult = $e ? end($e) : null;
            $mine['amostras'][] = ['id' => $a['id'], 'curso' => $nomeSk[$a['skill_id']] ?? $a['skill_id'], 'aprovada' => $a['status'] === 'validada', 'quando' => $a['validada_em'] ?: $a['criada_em'], 'svg' => count($mine['amostras']) < 6 ? ($ult['svg'] ?? null) : null, 'link' => ($a['chamado']['folderUrl'] ?? null)];
            if ($a['status'] === 'validada') $mine['marcos'][] = ['quando' => $a['validada_em'] ?: $a['criada_em'], 'texto' => 'Entrega aprovada em ' . ($nomeSk[$a['skill_id']] ?? $a['skill_id'])]; }
        $li = pdAulaSup('/rest/v1/pd_licoes?status=eq.aprovada&or=(ia_user_id.eq.' . urlencode($u['id']) . ',comum.eq.true)&select=regra,skill_id,aprovada_em,exemplo_bom,exemplo_ruim&order=aprovada_em.desc&limit=40')['body'] ?? [];
        foreach ($li as $l) $mine['marcos'][] = ['quando' => $l['aprovada_em'], 'texto' => 'Aprendeu: ' . $l['regra']];
        usort($mine['marcos'], fn($x, $y) => strcmp((string)$y['quando'], (string)$x['quando']));
        $crit = []; foreach ($fr as $f) foreach ($skAll as $s) if ($s['skill_id'] === $f['skill_id']) $crit[] = ['nome' => $s['nome'], 'finalidade' => $s['finalidade'], 'criterios' => $s['criterios']];
        $mine['receita'] = ['quem_ela_e' => $nome . ($pc ? ' — ' . ($pc[2] ?? '') : ''), 'habilidades' => $crit, 'licoes' => array_map(fn($l) => ['regra' => $l['regra'], 'exemplo_bom' => $l['exemplo_bom'], 'exemplo_ruim' => $l['exemplo_ruim']], $li)];
        $out['minha'] = $mine;
    }
    $out['guia'] = pdEscGuia();
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}
function pdEscGuia() {
    return ['Para abrir um curso, quem dá a aula precisa deixar pronto:',
        '1. Nome do curso e o que a IA passa a saber fazer (uma frase).',
        '2. Exercício: um pedido fictício, com cliente fictício e tudo o que a IA precisa para fazer (nada de regra escondida).',
        '3. Critérios de nota: de 3 a 6 critérios com peso; os pesos somam 100.',
        '4. Um exemplo BOM e um RUIM do mesmo pedido, com a diferença anotada.',
        '5. Um segundo caso (outro setor) para provar que a IA transfere a regra, não decora o exemplo.',
        '6. Quem corrige: o GPT do responsável. A decisão final é sempre humana.',
        'Nunca usar dados de cliente real. O curso nasce como rascunho e só abre quando o responsável confirmar.'];
}
if ($acao === 'matricular') {
    if (!$perm['matricular']) sd_fail('Você não tem permissão para matricular a IA. Peça ao administrador.', 403);
    $sk = (string)($b['skill_id'] ?? ''); if (!$sk) sd_fail('Escolha o curso.');
    $ok = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($sk) . '&ativa=eq.true&select=skill_id&limit=1')['body'][0] ?? null; if (!$ok) sd_fail('Este curso ainda não tem aulas abertas.');
    $je = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=personagem,apelido&limit=1')['body'][0] ?? null; if (!$je) sd_fail('Sua IA ainda não existe. Comece pela matrícula inicial.');
    $f = pdFormacaoMatricula($u['id'], (string)$je['personagem'], $sk);
    // pasta de repositório (Drive) — melhor esforço
    $pasta = null;
    if ($f && empty($f['pasta_url']) && pdPonteOk() && defined('SD_ESCOLA_DRIVE_ID') && SD_ESCOLA_DRIVE_ID) {
        try { $p = ponte('pasta_garantir', ['pai_id' => SD_ESCOLA_DRIVE_ID, 'nome' => 'IA ' . ($je['apelido'] ?: $je['personagem']) . ' · ' . substr($u['id'], 0, 6) . ' · ' . $sk, 'criar' => true]);
            if (!empty($p['url'])) { pdAulaSup('/rest/v1/pd_formacoes?id=eq.' . urlencode($f['id']), 'PATCH', ['pasta_id' => $p['id'], 'pasta_url' => $p['url']]); $pasta = $p['url']; } } catch (Throwable $e) {}
    }
    echo json_encode(['ok' => true, 'pasta_url' => $pasta]); exit;
}
if ($acao === 'receita_salvar') {
    if (!$perm['acompanhar']) sd_fail('Sem permissão para editar as instruções.', 403);
    $t = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($b['texto'] ?? ''))), 0, 2000);
    pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']), 'PATCH', ['instrucao_extra' => $t]);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'curso_salvar') {
    if (!$perm['criar']) sd_fail('Sem permissão para criar cursos.', 403);
    $nome = mb_substr(trim((string)($b['nome'] ?? '')), 0, 80); if ($nome === '') sd_fail('Dê um nome ao curso.');
    $slug = trim((string)($b['slug'] ?? '')) ?: strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)), '-'));
    $crit = []; $soma = 0; foreach ((array)($b['criterios'] ?? []) as $c) { $n = mb_substr(trim((string)($c['criterio'] ?? '')), 0, 120); $p = (int)($c['peso'] ?? 0); if ($n !== '' && $p > 0) { $crit[] = ['criterio' => $n, 'peso' => $p]; $soma += $p; } }
    $status = in_array($b['status'] ?? '', ['rascunho', 'aberto', 'arquivado'], true) ? $b['status'] : 'rascunho';
    if ($status === 'aberto' && ($soma !== 100 || count($crit) < 3)) sd_fail('Para abrir: de 3 a 6 critérios, com pesos somando 100 (agora somam ' . $soma . ').');
    if ($status === 'aberto' && trim((string)($b['exercicio'] ?? '')) === '') sd_fail('Para abrir o curso, escreva o exercício.');
    $d = ['slug' => $slug, 'nome' => $nome, 'descricao' => mb_substr((string)($b['descricao'] ?? ''), 0, 600), 'icone' => mb_substr((string)($b['icone'] ?? 'estrela'), 0, 24), 'responsavel' => mb_substr((string)($b['responsavel'] ?? ''), 0, 80),
        'vagas' => isset($b['vagas']) && $b['vagas'] !== '' ? max(1, (int)$b['vagas']) : null, 'exercicio' => mb_substr((string)($b['exercicio'] ?? ''), 0, 4000), 'criterios' => $crit,
        'guia' => ['bom' => mb_substr((string)($b['bom'] ?? ''), 0, 1500), 'ruim' => mb_substr((string)($b['ruim'] ?? ''), 0, 1500), 'transferencia' => mb_substr((string)($b['transferencia'] ?? ''), 0, 1500)], 'status' => $status, 'criado_por' => $u['id']];
    $r = pdAulaSup('/rest/v1/pd_cursos?on_conflict=slug', 'POST', $d, ['Prefer: resolution=merge-duplicates,return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v113 no Supabase.', 500);
    echo json_encode(['ok' => true, 'slug' => $slug]); exit;
}
if (!$ehAdmin) sd_fail('Só admin.', 403);
if ($acao === 'perm_lista') {
    $pf = pdAulaSup('/rest/v1/profiles?active=eq.true&client_id=is.null&select=id,name,role&order=name&limit=100')['body'] ?? [];
    $pm = pdAulaSup('/rest/v1/pd_escola_perm?select=*&limit=200'); if (($pm['code'] ?? 500) >= 300) sd_fail('Rode o SQL v113 no Supabase.', 500);
    $m = []; foreach ($pm['body'] as $x) $m[$x['user_id']] = $x;
    echo json_encode(['pessoas' => array_map(fn($p) => ['id' => $p['id'], 'nome' => $p['name'], 'role' => $p['role'], 'matricular' => !empty($m[$p['id']]['matricular']), 'acompanhar' => !empty($m[$p['id']]['acompanhar']), 'criar' => !empty($m[$p['id']]['criar'])], $pf)], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'perm_salvar') {
    $id = (string)($b['user_id'] ?? ''); if (!$id) sd_fail('Escolha a pessoa.');
    $d = ['user_id' => $id, 'atualizada_em' => gmdate('c')]; foreach (['matricular', 'acompanhar', 'criar'] as $k) if (isset($b[$k])) $d[$k] = (bool)$b[$k];
    $r = pdAulaSup('/rest/v1/pd_escola_perm?on_conflict=user_id', 'POST', $d, ['Prefer: resolution=merge-duplicates,return=minimal']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v113 no Supabase.', 500);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'ias_casa') {   // as IAs agênticas já habilitadas (formação apta), com suas qualidades — para todo mundo; o modelo por trás só para admin
    $forms = pdAulaSup('/rest/v1/pd_formacoes?ativa=eq.true&select=ia_user_id,personagem,skill_id,apta_em&limit=500')['body'] ?? [];
    $jeito = pdAulaSup('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=200')['body'] ?? []; $jm = []; foreach ($jeito as $j) $jm[$j['user_id']] = $j;
    $sk = pdAulaSup('/rest/v1/pd_habilidades?ativa=eq.true&select=skill_id,nome')['body'] ?? []; $sn = []; foreach ($sk as $x) $sn[$x['skill_id']] = $x['nome'];
    $pf = pdAulaSup('/rest/v1/profiles?select=id,name&limit=300')['body'] ?? []; $nm = []; foreach ($pf as $p) $nm[$p['id']] = $p['name'];
    $cat = pdPersonagens(); $ias = [];
    foreach ($forms as $f) { $k = $f['ia_user_id']; $j = $jm[$k] ?? []; $pers = strtolower(trim((string)($j['personagem'] ?? $f['personagem'])));
        $ias[$k] = $ias[$k] ?? ['id' => $k, 'personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers)), 'titulo' => $cat[$pers][2] ?? '', 'dona' => $nm[$k] ?? '', 'aptas' => [], 'formando' => []];
        if (!empty($f['apta_em'])) $ias[$k]['aptas'][] = $sn[$f['skill_id']] ?? $f['skill_id']; else $ias[$k]['formando'][] = $sn[$f['skill_id']] ?? $f['skill_id']; }
    $lista = array_values(array_filter($ias, fn($i) => $i['aptas']));   // só as habilitadas entram na casa
    usort($lista, fn($a, $b2) => count($b2['aptas']) <=> count($a['aptas']));
    $fixas = [['id' => 'dot', 'personagem' => 'dot', 'nome' => 'dot', 'titulo' => 'consultor externo', 'dona' => '', 'aptas' => ['transporte e produção externa'], 'fixa' => true], ['id' => 'maestro', 'personagem' => 'maestro', 'nome' => 'Maestro', 'titulo' => 'distribui, cobra e revisa', 'dona' => '', 'aptas' => ['orquestração'], 'fixa' => true]];
    $out = ['ias' => $lista, 'fixas' => $fixas]; if ($ehAdmin) $out['motores'] = ['dot' => 'GPT (projeto do Sérgio)', 'maestro' => 'Claude', 'personagens' => 'Claude (' . (defined('SD_ALUNA_MODEL') ? SD_ALUNA_MODEL : 'claude-sonnet-4-5') . ')'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'resumo_admin') {   // histórico de todas as IAs + média de aproveitamento por curso
    $forms = pdAulaSup('/rest/v1/pd_formacoes?select=ia_user_id,personagem,skill_id,ativa,apta_em,criada_em&limit=500')['body'] ?? [];
    $aulas = pdAulaSup('/rest/v1/pd_aulas?modo=neq.teste&status=in.(validada,reprovada)&select=ia_user_id,skill_id,status&limit=3000')['body'] ?? [];
    $pf = pdAulaSup('/rest/v1/profiles?select=id,name&limit=300')['body'] ?? []; $nm = []; foreach ($pf as $p) $nm[$p['id']] = $p['name'];
    $porSkill = []; $porIa = [];
    foreach ($aulas as $a) { $k = $a['skill_id']; $porSkill[$k]['f'] = ($porSkill[$k]['f'] ?? 0) + 1; if ($a['status'] === 'validada') $porSkill[$k]['b'] = ($porSkill[$k]['b'] ?? 0) + 1;
        $q = $a['ia_user_id'] . '|' . $k; $porIa[$q]['f'] = ($porIa[$q]['f'] ?? 0) + 1; if ($a['status'] === 'validada') $porIa[$q]['b'] = ($porIa[$q]['b'] ?? 0) + 1; }
    $cursos = []; foreach ($porSkill as $k => $v) $cursos[] = ['skill_id' => $k, 'provas' => $v['f'], 'aprovadas' => $v['b'] ?? 0, 'aproveitamento' => round(100 * ($v['b'] ?? 0) / max(1, $v['f']))];
    $ias = []; foreach ($forms as $f) { $v = $porIa[$f['ia_user_id'] . '|' . $f['skill_id']] ?? ['f' => 0, 'b' => 0];
        $ias[] = ['dona' => $nm[$f['ia_user_id']] ?? '', 'personagem' => $f['personagem'], 'skill_id' => $f['skill_id'], 'ativa' => (bool)$f['ativa'], 'apta' => !empty($f['apta_em']), 'provas' => $v['f'], 'aprovadas' => $v['b'] ?? 0]; }
    echo json_encode(['cursos' => $cursos, 'ias' => $ias], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
