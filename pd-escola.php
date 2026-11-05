<?php
// Escola para o HUMANO (não só admin). Ações: inicio · matricular · receita_salvar
// e, só admin: curso_salvar · perm_lista · perm_salvar · resumo_admin.
// Permissões por pessoa em pd_escola_perm (matricular / acompanhar / criar); admin tem todas.
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/pd-aula-doc-lib.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-elenco-lib.php';
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
// ---------- PD · Studio Design: quem são as IAs de cada área e a IA assistindo o humano trabalhar
if ($acao === 'area_quem') {   // {minha: a IA da pessoa, mestre: a IA da área, chefe: Maestro, hoje: quantas observações a minha IA fez hoje nessa área}
    $area = (string)($b['area'] ?? ''); $cat = pdPersonagens();
    $j = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=user_id,personagem,apelido')['body'][0] ?? null;
    $minha = $j ? ['id' => $j['user_id'], 'personagem' => strtolower((string)$j['personagem']), 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[strtolower((string)$j['personagem'])][0] ?? 'minha IA')] : null;
    $mestre = pdQuemFazArea($area ?: 'digital');
    $hoje = 0; try { $d0 = gmdate('Y-m-d') . 'T00:00:00Z'; $r = pdAulaSup('/rest/v1/pd_observacoes?user_id=eq.' . urlencode($u['id']) . '&em=gte.' . $d0 . ($area ? '&area=eq.' . urlencode($area) : '') . '&select=id&limit=1000'); $hoje = count($r['body'] ?? []); } catch (Throwable $e) {}
    $total = 0; try { $r = pdAulaSup('/rest/v1/pd_observacoes?user_id=eq.' . urlencode($u['id']) . ($area ? '&area=eq.' . urlencode($area) : '') . '&select=id&limit=5000'); $total = count($r['body'] ?? []); } catch (Throwable $e) {}
    $recentes = []; try { $r = pdAulaSup('/rest/v1/pd_observacoes?user_id=eq.' . urlencode($u['id']) . ($area ? '&area=eq.' . urlencode($area) : '') . '&select=evento,dados,em,client_id&order=em.desc&limit=8'); $recentes = array_map(fn($x) => ['evento' => $x['evento'], 'dados' => is_array($x['dados']) ? array_slice($x['dados'], 0, 4, true) : null, 'em' => $x['em']], $r['body'] ?? []); } catch (Throwable $e) {}
    $relogio = pdRelogioCatum($minha['id'] ?? null); $relogioMestre = pdRelogioCatum($mestre['id'] ?? null);
    $porque = 'definida no Painel › Inteligências da casa como mestre de ' . ($area ?: 'digital') . ': é ela quem assina o que sai daqui quando você não tem IA sua, e quem revisa o que a sua faz.';
    echo json_encode(['minha' => $minha, 'mestre' => $mestre + ['porque' => $porque], 'chefe' => ['personagem' => 'maestro', 'nome' => 'Maestro', 'titulo' => 'chefe da casa', 'porque' => 'distribui o trabalho entre as IAs, cobra prazo e revisa antes de ir pro cliente'], 'hoje' => $hoje, 'total' => $total, 'masters' => pdAreaMasters(), 'recentes' => $recentes, 'relogio' => $relogio, 'relogio_mestre' => $relogioMestre], JSON_UNESCAPED_UNICODE); exit;
}
// ---------------------------------------------------------------- AULA DE DOCUMENTO (job real)
if ($acao === 'aula_documento') {   // {persona, texto, client_id, task_id, doc_id, origem, afinacao, extra}
    if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
    try { $r = pdAulaDocCriar($u['id'], (string)($b['persona'] ?? ''), (string)($b['texto'] ?? ''), $b['client_id'] ?? null, $b['task_id'] ?? null, $b['doc_id'] ?? null, $b['origem'] ?? null, (string)($b['afinacao'] ?? ''), is_array($b['extra'] ?? null) ? $b['extra'] : []); }
    catch (Throwable $e) { sd_fail('Aula: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 500); }
    if (empty($r['ok'])) sd_fail($r['msg'], 500);
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'aulas_documento') {   // o box de aulas: lista + placar por persona/habilidade. Só admin e quem tem "acompanhar".
    if (!$perm['admin'] && !$perm['acompanhar']) sd_fail('Sem permissão para acompanhar as aulas.', 403);
    $as = pdAulaSup('/rest/v1/pd_aulas?or=(tipo.eq.documento,and(tipo.eq.arte,modo.eq.job))&select=id,tipo,modo,personagem,skill_id,status,rodada,max_rodadas,entregas,correcoes,nota_final,task_id,client_id,origem,prazo,entregue_em,pendencias,criada_em,parecer_humano,contexto,clients(name)&order=criada_em.desc&limit=80')['body'] ?? [];
    $lista = []; $pl = [];
    // aulas de arte: as miniaturas da PD (peças do lote de aula) e a "master" (peças aprovadas do designer humano na mesma tarefa, sem chapéu de aula)
    $arteIds = []; $arteTasks = []; foreach ((array)$as as $a) if (is_array($a) && ($a['tipo'] ?? '') === 'arte') { $arteIds[] = $a['id']; if (!empty($a['task_id'])) $arteTasks[] = $a['task_id']; }
    $pdThumbs = []; $masterThumbs = [];
    if ($arteIds) { try { $its = pdAulaSup('/rest/v1/approval_items?aula=eq.true&aula_id=in.(' . implode(',', array_map('urlencode', array_unique($arteIds))) . ')&select=aula_id,media,title,kind,status&order=position&limit=400')['body'] ?? [];
        foreach ((array)$its as $it) foreach ((array)($it['media'] ?? []) as $m) if (!empty($m['url'])) $pdThumbs[$it['aula_id']][] = ['url' => $m['thumb'] ?? $m['url'], 'titulo' => $it['title'], 'kind' => $it['kind'], 'status' => $it['status']]; } catch (Throwable $e) {} }
    if ($arteTasks) { try { $its = pdAulaSup('/rest/v1/approval_items?aula=eq.false&status=eq.aprovado&media=not.is.null&approval_sets!inner(task_id)&approval_sets.task_id=in.(' . implode(',', array_map('urlencode', array_unique($arteTasks))) . ')&select=title,kind,media,approval_sets!inner(task_id)&order=position&limit=400')['body'] ?? [];
        foreach ((array)$its as $it) { $tid = $it['approval_sets']['task_id'] ?? null; if (!$tid) continue; foreach ((array)($it['media'] ?? []) as $m) if (!empty($m['url']) || !empty($m['thumb'])) $masterThumbs[$tid][] = ['url' => $m['thumb'] ?? $m['url'], 'titulo' => $it['title'], 'kind' => $it['kind']]; } } catch (Throwable $e) {} }
    foreach ((array)$as as $a) { if (!is_array($a)) continue;
        $ent = (array)$a['entregas']; $cor = (array)$a['correcoes']; $r1 = null; $ok = null; $ehArte = ($a['tipo'] ?? 'documento') === 'arte';
        foreach ($cor as $c) { if ((int)($c['rodada'] ?? 0) === 1) $r1 = (float)($c['nota'] ?? 0); if (!empty($c['aprovado']) || (float)($c['nota'] ?? 0) >= 85) { $ok = (int)$c['rodada']; break; } }
        $k = $a['personagem'] . '|' . $a['skill_id']; $pl[$k] = $pl[$k] ?? ['personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'aulas' => 0, 'notas_r1' => [], 'rodadas_ok' => [], 'ultimas_r1' => []];
        $pl[$k]['aulas']++; if ($r1 !== null) { $pl[$k]['notas_r1'][] = $r1; } if ($ok) $pl[$k]['rodadas_ok'][] = $ok;
        $lista[] = ['id' => $a['id'], 'personagem' => $a['personagem'], 'skill_id' => $a['skill_id'], 'status' => $a['status'], 'rodada' => $a['rodada'], 'max' => $a['max_rodadas'], 'versoes' => count($ent), 'pareceres' => count($cor), 'nota_r1' => $r1, 'nota_final' => $a['nota_final'],
            'task_id' => $a['task_id'], 'cliente' => $a['clients']['name'] ?? '', 'origem' => $a['origem'], 'prazo' => $a['prazo'], 'entregue_em' => $a['entregue_em'], 'pendencias' => $a['pendencias'], 'criada_em' => $a['criada_em'], 'decisao' => $a['parecer_humano'],
            'ultima' => mb_substr((string)((end($ent) ?: [])['texto'] ?? ''), 0, 400), 'ultimo_parecer' => mb_substr((string)((end($cor) ?: [])['texto_do_professor'] ?? ''), 0, 600),
            'tipo' => $ehArte ? 'arte' : 'documento', 'set_aula_id' => $ehArte ? ($a['contexto']['set_aula_id'] ?? null) : null, 'pecas' => $ehArte ? count((array)($a['contexto']['pecas'] ?? [])) : null,
            'thumbs_pd' => $ehArte ? ($pdThumbs[$a['id']] ?? array_values(array_map(fn($pc) => ['url' => $pc['url'], 'titulo' => $pc['titulo'] ?? '', 'kind' => $pc['kind'] ?? ''], (array)((end($ent) ?: [])['pecas'] ?? [])))) : null,
            'thumbs_master' => $ehArte && !empty($a['task_id']) ? ($masterThumbs[$a['task_id']] ?? []) : null]; }
    $placar = [];
    foreach ($pl as $x) { $n = count($x['notas_r1']); $m = $n ? array_sum($x['notas_r1']) / $n : null; $rec = array_slice($x['notas_r1'], 0, 5); $ant = array_slice($x['notas_r1'], 5, 5);   // lista vem do mais novo
        $placar[] = ['personagem' => $x['personagem'], 'skill_id' => $x['skill_id'], 'aulas' => $x['aulas'], 'nota_r1_media' => $m !== null ? round($m, 1) : null, 'nota_r1_recente' => $rec ? round(array_sum($rec) / count($rec), 1) : null, 'nota_r1_anterior' => $ant ? round(array_sum($ant) / count($ant), 1) : null,
            'rodadas_ate_ok' => $x['rodadas_ok'] ? round(array_sum($x['rodadas_ok']) / count($x['rodadas_ok']), 1) : null]; }
    echo json_encode(['ok' => true, 'aulas' => $lista, 'placar' => $placar], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'escola_config') {   // a caixa de configuração da escola (ler); salvar só admin
    $c = pdIncub() ?: []; $campos = ['ativa', 'sandbox', 'nota_minima', 'teto_gasto_dia', 'gasto_hoje', 'chamados_dia', 'professor_modo', 'hora_professora', 'hora_virada', 'max_rodadas_noite', 'teto_noite', 'fins_de_semana', 'aula_arte_ligada', 'teste_confirmado_em'];
    $cfg = []; foreach ($campos as $k) $cfg[$k] = $c[$k] ?? null;
    $hoje = date('Y-m-d', time() - 3 * 3600);
    $or = pdAulaSup('/rest/v1/pd_orientacoes?ativa=eq.true&select=id,texto,escopo,client_id,de,ate,via,criado_em,clients(name),profiles:criado_por(name)&order=criado_em.desc&limit=60')['body'] ?? [];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?ativa=eq.true&select=skill_id,nome&order=skill_id')['body'] ?? [];
    echo json_encode(['ok' => true, 'config' => $cfg, 'orientacoes' => array_values(array_filter((array)$or, 'is_array')), 'habilidades' => $sk, 'hoje' => $hoje, 'admin' => $ehAdmin], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'escola_config_salvar') {
    if (!$ehAdmin) sd_fail('Só admin configura a escola.', 403);
    $d = []; foreach (['hora_professora', 'hora_virada'] as $k) if (isset($b[$k]) && preg_match('/^\d{2}:\d{2}$/', (string)$b[$k])) $d[$k] = $b[$k];
    foreach (['max_rodadas_noite', 'chamados_dia'] as $k) if (isset($b[$k])) $d[$k] = max(1, min(10, (int)$b[$k]));
    foreach (['teto_noite', 'teto_gasto_dia', 'nota_minima'] as $k) if (isset($b[$k]) && is_numeric($b[$k])) $d[$k] = (float)$b[$k];
    foreach (['fins_de_semana', 'aula_arte_ligada', 'ativa', 'sandbox'] as $k) if (array_key_exists($k, $b)) $d[$k] = !empty($b[$k]);
    if (!$d) sd_fail('Nada para salvar.', 400);
    $r = pdAulaSup('/rest/v1/pd_incubacao?id=eq.1', 'PATCH', $d); if (($r['code'] ?? 500) >= 300) sd_fail('Não salvou (o SQL v164 já rodou?).', 500);
    echo json_encode(['ok' => true]); exit;
}
if ($acao === 'orientacao_salvar') {
    if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
    $m = pdOrientar((string)($b['texto'] ?? ''), $u['id'], 'painel', $b['ate'] ?? null, (string)($b['cliente'] ?? ''), $b['de'] ?? null);
    echo json_encode(['ok' => strpos($m, '✅') === 0, 'msg' => $m], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'orientacao_apagar') {
    if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
    $id = (string)($b['id'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) sd_fail('Id inválido.', 400);
    pdAulaSup('/rest/v1/pd_orientacoes?id=eq.' . $id, 'PATCH', ['ativa' => false]); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'aula_decidir') {   // Sérgio decide a lição: aprovar | revisar | reprovar (+ parecer, + regra editada)
    if (!$ehAdmin) sd_fail('Só admin decide a aula.', 403);
    $r = pdAulaValidar((string)($b['id'] ?? ''), (string)($b['decisao'] ?? ''), (string)($b['parecer'] ?? ''), $u['id'], $b['licao'] ?? null);
    echo json_encode(['ok' => !empty($r['ok'])] + (array)$r, JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'observa') {   // lote de observações: [{area, client_id, peca_id, evento, dados}] — a IA da pessoa (ou a mestre da área) registra como aprendizado
    $lote = is_array($b['lote'] ?? null) ? array_slice($b['lote'], 0, 100) : []; if (!$lote) { echo json_encode(['ok' => true, 'n' => 0]); exit; }
    $j = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . urlencode($u['id']) . '&select=user_id,personagem')['body'][0] ?? null; $linhas = [];
    foreach ($lote as $o) { $area = mb_substr((string)($o['area'] ?? ''), 0, 20); $q = $j ?: pdQuemFazArea($area ?: 'digital'); $ia = $j ? $j['user_id'] : ($q['id'] ?? null);
        $linhas[] = ['area' => $area, 'client_id' => ($o['client_id'] ?? null) ?: null, 'peca_id' => preg_match('/^[0-9a-f-]{36}$/i', (string)($o['peca_id'] ?? '')) ? $o['peca_id'] : null, 'user_id' => $u['id'], 'ia_user_id' => $ia, 'personagem' => strtolower((string)($j['personagem'] ?? ($q['personagem'] ?? ''))), 'evento' => mb_substr((string)($o['evento'] ?? ''), 0, 60), 'dados' => is_array($o['dados'] ?? null) ? $o['dados'] : null, 'em' => !empty($o['em']) ? gmdate('c', (int)($o['em'] / 1000)) : gmdate('c')]; }
    $r = pdAulaSup('/rest/v1/pd_observacoes', 'POST', $linhas); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v128 no Supabase.', 500);
    echo json_encode(['ok' => true, 'n' => count($linhas)]); exit;
}
if ($acao === 'masters_salvar') { if (!$ehAdmin) sd_fail('Só admin.', 403); $m = is_array($b['masters'] ?? null) ? array_map(fn($v) => strtolower(preg_replace('/[^a-z]/i', '', (string)$v)), $b['masters']) : []; pdAulaSup('/rest/v1/app_settings?id=eq.1', 'PATCH', ['area_masters' => $m]); echo json_encode(['ok' => true]); exit; }
if (!$ehAdmin) sd_fail('Só admin.', 403);
if ($acao === 'arte_clientes') {   // aula de arte em segundo plano: quais clientes entram, referências e tipologia
    $r = pdAulaSup('/rest/v1/clients?select=id,name,aula_arte,aula_arte_refs,aula_arte_tipologia&order=name&limit=400');
    if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v165 no Supabase.', 500);
    $cfg = pdIncub() ?: []; echo json_encode(['ok' => true, 'ligada' => !empty($cfg['aula_arte_ligada']), 'clientes' => array_values(array_filter((array)$r['body'], 'is_array'))], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'arte_cliente_salvar') {   // {client_id, aula_arte, refs, tipologia}
    $cid = (string)($b['client_id'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) sd_fail('Cliente inválido.');
    $d = ['aula_arte' => !empty($b['aula_arte']), 'aula_arte_refs' => mb_substr(trim((string)($b['refs'] ?? '')), 0, 500) ?: null, 'aula_arte_tipologia' => mb_substr(trim((string)($b['tipologia'] ?? '')), 0, 80) ?: null];
    $r = pdAulaSup('/rest/v1/clients?id=eq.' . urlencode($cid), 'PATCH', $d, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) sd_fail('Não salvei (rode o SQL v165 no Supabase): ' . mb_substr((string)($r['raw'] ?? ''), 0, 200), 500);
    echo json_encode(['ok' => true, 'cliente' => $r['body'][0] ?? null], JSON_UNESCAPED_UNICODE); exit;
}
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
    $pf = pdAulaSup('/rest/v1/profiles?select=id,name,role,active&limit=300')['body'] ?? []; $nm = []; $eq = []; foreach ($pf as $p) { $nm[$p['id']] = $p['name']; if (in_array($p['role'] ?? '', ['admin','team'], true) && ($p['active'] ?? true) !== false) $eq[$p['id']] = true; }
    $cat = pdPersonagens(); $ias = [];
    foreach ($forms as $f) { $k = $f['ia_user_id']; $j = $jm[$k] ?? []; $pers = strtolower(trim((string)($j['personagem'] ?? $f['personagem'])));
        $ias[$k] = $ias[$k] ?? ['id' => $k, 'personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers)), 'titulo' => $cat[$pers][2] ?? '', 'dona' => $nm[$k] ?? '', 'aptas' => [], 'formando' => []];
        if (!empty($f['apta_em'])) $ias[$k]['aptas'][] = $sn[$f['skill_id']] ?? $f['skill_id']; else $ias[$k]['formando'][] = $sn[$f['skill_id']] ?? $f['skill_id']; }
    // toda a equipe que já fez o teste (tem personagem) aparece na casa; quem ainda não está apta fica como "estudando"
    foreach ($jm as $k => $j) { if (isset($ias[$k]) || empty($eq[$k]) || trim((string)($j['personagem'] ?? '')) === '') continue; $pers = strtolower(trim((string)$j['personagem']));
        $ias[$k] = ['id' => $k, 'personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers)), 'titulo' => $cat[$pers][2] ?? '', 'dona' => $nm[$k] ?? '', 'aptas' => [], 'formando' => []]; }
    $lista = array_values($ias);
    foreach ($lista as &$i) { if (($i['titulo'] ?? '') === '') { $n = strtolower(trim((string)$i['nome'])); $i['titulo'] = $cat[$n][2] ?? ($cat[strtolower((string)$i['personagem'])][2] ?? ''); } }   // sem título: busca pelo nome da IA no catálogo
    unset($i);
    foreach ($lista as &$i) $i['relogio'] = pdRelogioCatum($i['id']); unset($i);
    usort($lista, fn($a, $b2) => count($b2['aptas']) <=> count($a['aptas']));
    $fixas = [['id' => 'dot', 'personagem' => 'dot', 'nome' => 'dot', 'titulo' => 'consultor externo', 'dona' => '', 'aptas' => ['transporte e produção externa'], 'fixa' => true], ['id' => 'maestro', 'personagem' => 'maestro', 'nome' => 'Maestro', 'titulo' => 'distribui, cobra e revisa', 'dona' => '', 'aptas' => ['orquestração'], 'fixa' => true]];
    $out = ['ias' => $lista, 'fixas' => $fixas];
    if ($ehAdmin) { $ini = gmdate('Y-m-01') . 'T00:00:00Z'; $ur = pdAulaSup('/rest/v1/pd_openai_uso?criado_em=gte.' . $ini . '&select=servico,custo_usd,criado_em&limit=20000'); $uso = ['semTabela' => (($ur['code'] ?? 200) >= 300), 'servicos' => []]; $hoje = gmdate('Y-m-d');
        foreach ((array)($ur['body'] ?? []) as $x) { $sv = $x['servico'] ?? '?'; $uso['servicos'][$sv] = $uso['servicos'][$sv] ?? ['mes' => 0, 'hoje' => 0, 'n' => 0]; $uso['servicos'][$sv]['mes'] += (float)$x['custo_usd']; $uso['servicos'][$sv]['n']++; if (substr((string)$x['criado_em'], 0, 10) === $hoje) $uso['servicos'][$sv]['hoje'] += (float)$x['custo_usd']; }
        $out['uso_openai'] = $uso; }
    if ($ehAdmin) $out['motores'] = ['dot' => 'GPT (projeto do Sérgio)', 'maestro' => 'Claude', 'personagens' => 'Claude (' . (defined('SD_ALUNA_MODEL') ? SD_ALUNA_MODEL : 'claude-sonnet-4-5') . ')'];
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

if ($acao === 'curso_info') {   // {curso_id, skill?}: o que a aula ensina (skill real) e quem está estudando (IAs inscritas) — vale para todo mundo logado
    $cid = preg_replace('/[^a-z0-9_-]/i', '', (string)($b['curso_id'] ?? '')); if ($cid === '') sd_fail('Curso inválido.');
    $sk = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($b['skill'] ?? ''))) ?: ('CURSO_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cid)));
    $h = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($sk) . '&ativa=eq.true&select=skill_id,versao,nome,finalidade,metodo,criterios,limites,entradas&order=versao.desc&limit=1')['body'][0] ?? null;
    $ins = pdAulaSup('/rest/v1/pd_inscricoes?curso_id=eq.' . urlencode($cid) . '&status=eq.ativa&select=user_id,iniciada_em,termina_em&limit=200')['body'] ?? [];
    $jeito = pdAulaSup('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=300')['body'] ?? []; $jm = []; foreach ($jeito as $j) $jm[$j['user_id']] = $j;
    $pf = pdAulaSup('/rest/v1/profiles?select=id,name&limit=300')['body'] ?? []; $nm = []; foreach ($pf as $p2) $nm[$p2['id']] = $p2['name'];
    $cat = pdPersonagens(); $al = [];
    foreach ($ins as $i) { $j = $jm[$i['user_id']] ?? []; $pers = strtolower(trim((string)($j['personagem'] ?? '')));
        $al[] = ['personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers ?: 'IA')), 'dona' => $nm[$i['user_id']] ?? '', 'desde' => $i['iniciada_em'], 'ate' => $i['termina_em'], 'eu' => $i['user_id'] === $u['id']]; }
    echo json_encode(['skill' => $h, 'skill_id' => $sk, 'alunos' => $al, 'admin' => $ehAdmin], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'curriculo_pedir' || $acao === 'curriculo_lista') {   // admin: pede à professora o conteúdo de cursos/skills; lista os pedidos
    if (!$ehAdmin) sd_fail('Só admin.', 403);
    require_once __DIR__ . '/pd-curriculo-lib.php';
    if ($acao === 'curriculo_lista') { $r = pdAulaSup('/rest/v1/pd_curriculo_pedidos?select=id,curso,skill_id,titulo,status,erro,versao,criado_em,entregue_em,chamado&order=criado_em.desc&limit=200'); echo json_encode(['pedidos' => (($r['code'] ?? 500) < 300 && is_array($r['body'] ?? null)) ? $r['body'] : [], 'diretrizes' => array_keys(pdCurrDiretrizes()['cursos'])], JSON_UNESCAPED_UNICODE); exit; }
    $lista = $b['cursos'] ?? []; if ($lista === 'vazios') { $lista = []; $ja = pdAulaSup('/rest/v1/pd_habilidades?select=skill_id&limit=2000')['body'] ?? []; $tem = array_column($ja, 'skill_id');
        foreach (array_keys(pdCurrDiretrizes()['cursos']) as $c) if (!in_array(pdCurrSkillId($c), $tem, true)) $lista[] = $c; }
    $msgs = []; $ok = 0; foreach ((array)$lista as $c) { $r = pdCurrPedir((string)$c, $u['id']); if (!empty($r['ok'])) $ok++; $msgs[] = $r['msg']; }
    echo json_encode(['ok' => true, 'enviados' => $ok, 'msgs' => $msgs], JSON_UNESCAPED_UNICODE); exit;
}
// ======================= BASTIDORES DA ESCOLA (admin): skills = aulas reais =======================
function pdEscSkillId($nome) { $x = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)), '_')); return mb_substr($x ?: 'SKILL', 0, 40); }
function pdEscSkillLimpa($b) {
    $lst = function($v, $n = 12) { $o = []; foreach ((array)$v as $x) { $x = mb_substr(trim((string)(is_array($x) ? ($x['criterio'] ?? json_encode($x, JSON_UNESCAPED_UNICODE)) : $x)), 0, 300); if ($x !== '') $o[] = $x; } return array_slice($o, 0, $n); };
    return ['nome' => mb_substr(trim((string)($b['nome'] ?? '')), 0, 90), 'finalidade' => mb_substr(trim((string)($b['finalidade'] ?? '')), 0, 800), 'entradas' => $lst($b['entradas'] ?? []),
        'metodo' => mb_substr(trim((string)($b['metodo'] ?? '')), 0, 6000), 'criterios' => $lst($b['criterios'] ?? [], 15), 'limites' => mb_substr(trim((string)($b['limites'] ?? '')), 0, 1500)];
}
if (in_array($acao, ['esc_skills', 'esc_skill_salvar', 'esc_skill_pausar', 'esc_skill_apagar', 'esc_skill_doc', 'esc_aluno', 'esc_ias'], true)) {
    if (!$ehAdmin) sd_fail('Só admin.', 403);
    $enc = fn($x) => urlencode((string)$x);
    if ($acao === 'esc_skills') {
        $hs = pdAulaSup('/rest/v1/pd_habilidades?select=*&order=skill_id.asc,versao.desc&limit=2000');
        if (($hs['code'] ?? 500) >= 300) sd_fail('Rode o SQL v166 no Supabase.', 500);
        $forms = pdAulaSup('/rest/v1/pd_formacoes?select=id,ia_user_id,personagem,skill_id,ativa,apta_em,criada_em&limit=2000')['body'] ?? [];
        $jeito = pdAulaSup('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=300')['body'] ?? []; $jm = []; foreach ($jeito as $j) $jm[$j['user_id']] = $j;
        $pf = pdAulaSup('/rest/v1/profiles?select=id,name&limit=300')['body'] ?? []; $nm = []; foreach ($pf as $p) $nm[$p['id']] = $p['name'];
        $aulas = pdAulaSup('/rest/v1/pd_aulas?modo=neq.teste&select=skill_id,status&limit=5000')['body'] ?? []; $ac = [];
        foreach ($aulas as $a) { $k = $a['skill_id']; $ac[$k]['t'] = ($ac[$k]['t'] ?? 0) + 1; if ($a['status'] === 'validada') $ac[$k]['v'] = ($ac[$k]['v'] ?? 0) + 1; }
        $cat = pdPersonagens(); $por = [];
        foreach ($hs['body'] as $h) { $k = $h['skill_id']; if (!isset($por[$k])) $por[$k] = ['atual' => $h, 'versoes' => []]; $por[$k]['versoes'][] = ['versao' => $h['versao'], 'criada_em' => $h['criada_em'], 'notas' => $h['notas_versao'] ?? '', 'origem' => $h['origem'] ?? '']; }
        $out = [];
        foreach ($por as $k => $v) { $h = $v['atual']; $al = [];
            foreach ($forms as $f) if ($f['skill_id'] === $k) { $j = $jm[$f['ia_user_id']] ?? []; $pers = strtolower(trim((string)($j['personagem'] ?? $f['personagem'])));
                $al[] = ['id' => $f['id'], 'ia_user_id' => $f['ia_user_id'], 'personagem' => $pers, 'nome' => trim((string)($j['apelido'] ?? '')) ?: ($cat[$pers][0] ?? ucfirst($pers)), 'dona' => $nm[$f['ia_user_id']] ?? '', 'ativa' => (bool)$f['ativa'], 'apta' => !empty($f['apta_em'])]; }
            $out[] = ['skill_id' => $k, 'versao' => $h['versao'], 'nome' => $h['nome'], 'finalidade' => $h['finalidade'], 'entradas' => $h['entradas'], 'metodo' => $h['metodo'], 'criterios' => $h['criterios'], 'limites' => $h['limites'],
                'exemplos' => $h['exemplos'] ?? [], 'criada_em' => $h['criada_em'], 'pausada' => !empty($h['pausada']), 'origem' => $h['origem'] ?? '', 'versoes' => $v['versoes'], 'alunos' => $al, 'aulas' => $ac[$k]['t'] ?? 0, 'validadas' => $ac[$k]['v'] ?? 0]; }
        echo json_encode(['skills' => $out], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($acao === 'esc_ias') {   // todas as IAs da casa, para inscrever
        $jeito = pdAulaSup('/rest/v1/pd_jeito?select=user_id,personagem,apelido&limit=300')['body'] ?? [];
        $pf = pdAulaSup('/rest/v1/profiles?select=id,name,role,active&limit=300')['body'] ?? []; $nm = []; foreach ($pf as $p) $nm[$p['id']] = $p;
        $cat = pdPersonagens(); $o = [];
        foreach ($jeito as $j) { if (trim((string)$j['personagem']) === '') continue; $p = $nm[$j['user_id']] ?? null; if (!$p || ($p['active'] ?? true) === false) continue; $pers = strtolower(trim((string)$j['personagem']));
            $o[] = ['ia_user_id' => $j['user_id'], 'personagem' => $pers, 'nome' => trim((string)$j['apelido']) ?: ($cat[$pers][0] ?? ucfirst($pers)), 'dona' => $p['name']]; }
        echo json_encode(['ias' => $o], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($acao === 'esc_skill_salvar') {   // cria skill nova (sem skill_id) ou MELHORA uma existente (nova versão; a anterior fica no histórico)
        $d = pdEscSkillLimpa($b); if ($d['nome'] === '' || $d['finalidade'] === '' || $d['metodo'] === '') sd_fail('Preencha nome, finalidade e método.');
        if (count($d['criterios']) < 2) sd_fail('Coloque pelo menos 2 critérios observáveis.');
        $sid = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($b['skill_id'] ?? ''))); $ver = 1;
        if ($sid !== '') { $m = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&select=versao,pausada&order=versao.desc&limit=1')['body'][0] ?? null; if (!$m) sd_fail('Skill não encontrada.'); $ver = (int)$m['versao'] + 1; $pausada = !empty($m['pausada']);
            pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid), 'PATCH', ['ativa' => false]); }
        else { $sid = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($b['novo_id'] ?? ''))) ?: pdEscSkillId($d['nome']); $ja = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&select=skill_id&limit=1')['body'][0] ?? null; if ($ja) $sid .= '_' . substr(md5((string)microtime(true)), 0, 4); $pausada = false; }
        $r = pdAulaSup('/rest/v1/pd_habilidades', 'POST', ['skill_id' => $sid, 'versao' => $ver, 'nome' => $d['nome'], 'finalidade' => $d['finalidade'], 'entradas' => $d['entradas'], 'metodo' => $d['metodo'], 'criterios' => $d['criterios'], 'limites' => $d['limites'],
            'exemplos' => array_values(array_filter([trim((string)($b['exemplo_bom'] ?? '')) !== '' ? ['tipo' => 'bom', 'texto' => mb_substr(trim((string)$b['exemplo_bom']), 0, 3000)] : null, trim((string)($b['exemplo_ruim'] ?? '')) !== '' ? ['tipo' => 'ruim', 'texto' => mb_substr(trim((string)$b['exemplo_ruim']), 0, 3000)] : null])),
            'ativa' => !$pausada, 'pausada' => $pausada, 'origem' => in_array($b['origem'] ?? '', ['doc', 'manual'], true) ? $b['origem'] : 'manual', 'notas_versao' => mb_substr((string)($b['notas'] ?? ''), 0, 500), 'criada_por' => $u['id']]);
        if (($r['code'] ?? 500) >= 300) sd_fail('Não salvou a skill: ' . mb_substr(json_encode($r['body'] ?? '', JSON_UNESCAPED_UNICODE), 0, 200), 500);
        echo json_encode(['ok' => true, 'skill_id' => $sid, 'versao' => $ver]); exit;
    }
    if ($acao === 'esc_skill_pausar') {   // parar / voltar a skill inteira (nenhuma aula nova nasce enquanto pausada)
        $sid = (string)($b['skill_id'] ?? ''); $pz = !empty($b['pausada']);
        $m = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&select=versao&order=versao.desc&limit=1')['body'][0] ?? null; if (!$m) sd_fail('Skill não encontrada.');
        pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid), 'PATCH', ['ativa' => false, 'pausada' => $pz]);
        if (!$pz) pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&versao=eq.' . (int)$m['versao'], 'PATCH', ['ativa' => true]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($acao === 'esc_skill_apagar') {
        $sid = (string)($b['skill_id'] ?? ''); if ($sid === '' || empty($b['confirmo'])) sd_fail('Confirme para apagar.');
        pdAulaSup('/rest/v1/pd_formacoes?skill_id=eq.' . $enc($sid), 'DELETE');
        pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid), 'DELETE');
        echo json_encode(['ok' => true]); exit;
    }
    if ($acao === 'esc_aluno') {   // {skill_id, ia_user_id, fazer: matricular|parar|retomar|remover}
        $sid = (string)($b['skill_id'] ?? ''); $ia = (string)($b['ia_user_id'] ?? ''); $fz = (string)($b['fazer'] ?? '');
        if ($fz === 'matricular') { $ok = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&select=skill_id,pausada&order=versao.desc&limit=1')['body'][0] ?? null; if (!$ok) sd_fail('Skill não encontrada.'); if (!empty($ok['pausada'])) sd_fail('Esta skill está parada. Volte com ela antes de inscrever.');
            $je = pdAulaSup('/rest/v1/pd_jeito?user_id=eq.' . $enc($ia) . '&select=personagem&limit=1')['body'][0] ?? null; if (!$je) sd_fail('Essa IA ainda não existe.');
            pdFormacaoMatricula($ia, (string)$je['personagem'], $sid); }
        elseif ($fz === 'parar' || $fz === 'retomar') pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . $enc($ia) . '&skill_id=eq.' . $enc($sid), 'PATCH', ['ativa' => $fz === 'retomar']);
        elseif ($fz === 'remover') pdAulaSup('/rest/v1/pd_formacoes?ia_user_id=eq.' . $enc($ia) . '&skill_id=eq.' . $enc($sid), 'DELETE');
        else sd_fail('Ação inválida.');
        echo json_encode(['ok' => true]); exit;
    }
    if ($acao === 'esc_skill_doc') {   // um doc (feito com Claude/GPT) vira PROPOSTA de skill: nova, ou melhoria de uma existente. Só propõe; quem salva é esc_skill_salvar.
        $doc = trim((string)($b['texto'] ?? '')); if (mb_strlen($doc) < 80) sd_fail('O documento está vazio ou curto demais.'); $doc = mb_substr($doc, 0, 40000);
        $sid = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($b['skill_id'] ?? ''))); $atual = null;
        if ($sid !== '') { $atual = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . $enc($sid) . '&select=*&order=versao.desc&limit=1')['body'][0] ?? null; if (!$atual) sd_fail('Skill não encontrada.'); }
        $sys = "Você transforma um documento de conhecimento em uma SKILL de IA para uma agência de branding do mercado imobiliário. Responda SOMENTE um JSON: {\"nome\":\"\",\"finalidade\":\"1-2 frases\",\"entradas\":[\"o que precisa chegar\"],\"metodo\":\"passos e o que a IA decide sozinha, em texto corrido organizado\",\"criterios\":[\"critério observável de revisão\"],\"limites\":\"o que exige humano\",\"notas\":\"o que esta versão traz de novo (1 frase)\"}. Use SOMENTE o que está no documento; não invente regras. Critérios: de 3 a 8, verificáveis. Português do Brasil.";
        $usr = $atual ? "SKILL ATUAL (versão {$atual['versao']}):\n" . json_encode(['nome' => $atual['nome'], 'finalidade' => $atual['finalidade'], 'entradas' => $atual['entradas'], 'metodo' => $atual['metodo'], 'criterios' => $atual['criterios'], 'limites' => $atual['limites']], JSON_UNESCAPED_UNICODE) . "\n\nDOCUMENTO NOVO (melhore a skill com ele; devolva a skill COMPLETA já mesclada, sem perder o que continua valendo):\n" . $doc
                : "DOCUMENTO (crie uma skill nova a partir dele):\n" . $doc;
        [$t, $custo, $erro] = pdClaude($sys, $usr, 3500); pdIncubSoma((float)$custo);
        $j = $t ? pdJson($t) : null; if (!$j || empty($j['nome'])) sd_fail('Não consegui ler o documento como skill' . ($erro ? ': ' . $erro : '') . '.', 502);
        echo json_encode(['ok' => true, 'proposta' => pdEscSkillLimpa($j) + ['notas' => mb_substr((string)($j['notas'] ?? ''), 0, 500)], 'skill_id' => $sid ?: null, 'versao_atual' => $atual['versao'] ?? null], JSON_UNESCAPED_UNICODE); exit;
    }
}

sd_fail('Ação desconhecida.');
