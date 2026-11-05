<?php
// Currículo da escola: a professora (GPT do Sérgio) escreve/atualiza as aulas a partir do conhecimento dela.
// A PD só entrega as DIRETRIZES (o que cada curso precisa ensinar) e recebe de volta o AULA.json (contrato pd-curriculo/1).
// Transporte igual ao das aulas: pasta no Drive (ponte) + e-mail tagueado. Nada vira aula sem passar pela validação.
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/pd-lib.php';
const PD_CONTRATO_CURRICULO = 'pd-curriculo/1';

function pdCurrDiretrizes() { return json_decode((string)@file_get_contents(__DIR__ . '/pd/escola-diretrizes.json'), true) ?: ['regras' => [], 'cursos' => []]; }
function pdCurrSkillId($curso) { $m = ['nami' => 'LP_ESTRUTURA', 'kiro' => 'LP_REVISAO_ESTRUTURA']; if (isset($m[$curso])) return $m[$curso]; return 'CURSO_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $curso)); }
function pdCurrModelo($pid, $curso, $skill) {
    return ['contrato' => PD_CONTRATO_CURRICULO, 'pedido_id' => $pid, 'curso' => $curso, 'id_da_aula' => $skill,
        'origem' => ['papel' => 'professor', 'fonte' => 'chatgpt_projeto', 'projeto' => '<nome do Projeto do GPT>'],
        'aula' => ['nome' => '', 'objetivo' => 'para que serve, 1 a 2 frases', 'entradas' => ['o que precisa chegar'], 'metodo' => 'passos numerados e o que a IA decide sozinha',
            'criterios' => ['critério observável 1', 'critério observável 2'], 'limites' => 'o que exige decisão humana', 'exemplo_bom' => '', 'exemplo_ruim' => '', 'o_que_mudou' => '']];
}
function pdCurrTexto($pid, $curso, $skill, $titulo, $dir, $atual) {
    $D = pdCurrDiretrizes(); $L = [];
    $L[] = 'PEDIDO DE CURRÍCULO · ' . $pid;
    $L[] = 'Você é a PROFESSORA (o GPT do Projeto do Sérgio). O dot é só o transporte. Esta tarefa é criar ' . ($atual ? 'uma NOVA VERSÃO de' : 'o conteúdo de') . ' uma aula da Escola das IAs da Pontodot.';
    $L[] = '';
    $L[] = 'CURSO: ' . $titulo . ' (' . $curso . ') · área: ' . ($dir['area'] ?? '');
    $L[] = 'O QUE PRECISA ENSINAR: ' . ($dir['ensinar'] ?? '');
    $L[] = 'ONDE ISSO APARECE NO TRABALHO REAL: ' . ($dir['onde_aparece'] ?? '');
    $L[] = '';
    $L[] = 'REGRAS:'; foreach ((array)$D['regras'] as $r) $L[] = '- ' . $r;
    if ($atual) { $L[] = ''; $L[] = 'VERSÃO ATUAL (melhore, não perca o que continua valendo):'; $L[] = json_encode($atual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }
    $L[] = '';
    $L[] = 'COMO ENTREGAR:';
    $L[] = '1) Escreva o arquivo AULA.json na pasta SAIDA desta pasta, seguindo EXATAMENTE o formato de 00_FORMATO_AULA.json (contrato ' . PD_CONTRATO_CURRICULO . ', pedido_id ' . $pid . ').';
    $L[] = '2) Use o conhecimento do seu Projeto sobre a nossa agência. Onde não houver base, escreva [CONFIRMAR].';
    $L[] = '3) Depois, escreva TERMINEI ' . $pid . ' na célula B2 da planilha de controle (ou responda ao e-mail deste chamado com o JSON).';
    return implode("\n", $L);
}
/** Cria o pedido e avisa a professora. $curso = slug do curso (nix…muru) ou skill_id de uma skill existente. */
function pdCurrPedir($curso, $userId) {
    if (!pdPonteOk()) return ['ok' => false, 'msg' => 'A ponte do Drive não está configurada.'];
    $D = pdCurrDiretrizes(); $dir = $D['cursos'][$curso] ?? null;
    $skill = $dir ? pdCurrSkillId($curso) : strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $curso));
    if (!$dir) { $h0 = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($skill) . '&select=nome,finalidade&order=versao.desc&limit=1')['body'][0] ?? null; if (!$h0) return ['ok' => false, 'msg' => 'Curso ou skill desconhecido: ' . $curso]; $dir = ['area' => 'Skill de trabalho', 'ensinar' => 'Revisar e melhorar esta skill: ' . $h0['finalidade'], 'onde_aparece' => '']; $titulo = $h0['nome']; }
    else $titulo = $dir['titulo'];
    $aberto = pdAulaSup('/rest/v1/pd_curriculo_pedidos?skill_id=eq.' . urlencode($skill) . '&status=eq.pedido&select=id&limit=1');
    if (($aberto['code'] ?? 500) >= 300) return ['ok' => false, 'msg' => 'Rode o SQL v167 no Supabase.'];
    if (!empty($aberto['body'])) return ['ok' => false, 'msg' => 'Já existe um pedido aberto para ' . $titulo . '.'];
    $atual = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($skill) . '&ativa=eq.true&select=nome,finalidade,entradas,metodo,criterios,limites&order=versao.desc&limit=1')['body'][0] ?? null;
    $r = pdAulaSup('/rest/v1/pd_curriculo_pedidos', 'POST', ['curso' => $curso, 'skill_id' => $skill, 'titulo' => $titulo, 'criado_por' => $userId], ['Prefer: return=representation']);
    $row = $r['body'][0] ?? null; if (!$row) return ['ok' => false, 'msg' => 'Não consegui registrar o pedido.'];
    $pid = 'CURR-' . substr($row['id'], 0, 8);
    try {
        $p = ponte('preparar', ['folderId' => null, 'parentId' => (defined('SD_ESCOLA_DRIVE_ID') && SD_ESCOLA_DRIVE_ID) ? SD_ESCOLA_DRIVE_ID : null, 'nome' => mb_substr('CURRÍCULO · ' . $titulo, 0, 120),
            'briefing' => ['tarefa' => 'CURRÍCULO · ' . $skill, 'cliente' => 'Escola das IAs', 'projeto' => $titulo, 'pedido' => 'ISTO É UM PEDIDO DE CURRÍCULO, não produção. Você (dot) é só o TRANSPORTE; a professora é o GPT do Sérgio. Leia o 00_BRIEFING_IA.txt desta pasta. Ignore os passos de produção.']]);
        $texto = pdCurrTexto($pid, $curso, $skill, $titulo, $dir, $atual);
        $g = fn($pasta, $nome, $bytes) => ponte('gravar', ['folderId' => $pasta, 'nome' => $nome, 'b64' => base64_encode($bytes), 'mime' => 'text/plain']);
        $g($p['folderId'], '00_FORMATO_AULA.json', json_encode(pdCurrModelo($pid, $curso, $skill), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $g($p['folderId'], '00_BRIEFING_IA.txt', "\xEF\xBB\xBF" . $texto); $g($p['folderId'], '00_CHAMADO_IA.txt', "\xEF\xBB\xBF" . $texto);
        ponte('status', ['controleId' => $p['controleId'], 'status' => 'CURRICULO', 'rodada' => 1, 'obs' => 'pedido enviado']);
        $assunto = '[SD-IA] CURRÍCULO · ' . $titulo . ' · ' . $pid;
        $ok = @mail(emailIA(), '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto . "\n\nPasta: " . ($p['folderUrl'] ?? '') . "\nPontodot · " . sd_base(), "From: SD Workflow <workflow@sergioduh.com>\r\nReply-To: workflow@sergioduh.com\r\nX-SD-Chamado: " . $pid . "\r\nContent-Type: text/plain; charset=UTF-8");
        pdAulaSup('/rest/v1/pd_curriculo_pedidos?id=eq.' . urlencode($row['id']), 'PATCH', ['chamado' => ['pedido' => $pid, 'folderId' => $p['folderId'], 'folderUrl' => $p['folderUrl'] ?? '', 'entradaId' => $p['entradaId'] ?? null, 'saidaId' => $p['saidaId'] ?? null, 'controleId' => $p['controleId'] ?? null, 'enviado_em' => gmdate('c'), 'email' => (bool)$ok]]);
        return ['ok' => true, 'msg' => 'Pedido enviado à professora: ' . $titulo . '.'];
    } catch (Throwable $e) { pdAulaSup('/rest/v1/pd_curriculo_pedidos?id=eq.' . urlencode($row['id']), 'PATCH', ['erro' => $e->getMessage()]); return ['ok' => false, 'msg' => 'Não consegui avisar o dot: ' . $e->getMessage()]; }
}
function pdCurrValida($j, $row) {
    if (!is_array($j)) return [false, 'arquivo não é JSON válido'];
    if (($j['contrato'] ?? '') !== PD_CONTRATO_CURRICULO) return [false, 'contrato diferente de ' . PD_CONTRATO_CURRICULO];
    if (($j['pedido_id'] ?? '') !== ($row['chamado']['pedido'] ?? '')) return [false, 'pedido_id não bate'];
    if (!in_array((string)($j['origem']['fonte'] ?? ''), ['chatgpt_projeto', 'chatgpt', 'gpt'], true)) return [false, 'origem.fonte precisa ser "chatgpt_projeto"'];
    $a = (array)($j['aula'] ?? []);
    foreach (['nome', 'objetivo', 'metodo'] as $k) if (mb_strlen(trim((string)($a[$k] ?? ''))) < 8) return [false, 'campo ausente ou curto: ' . $k];
    $cr = array_values(array_filter(array_map(fn($x) => trim((string)(is_array($x) ? ($x['criterio'] ?? '') : $x)), (array)($a['criterios'] ?? []))));
    if (count($cr) < 2) return [false, 'precisa de pelo menos 2 critérios'];
    $a['criterios'] = $cr; $a['entradas'] = array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($a['entradas'] ?? []))));
    return [true, $a];
}
/** Grava como nova versão (ou v1) da skill do pedido. */
function pdCurrSalvar($row, $a, $userId) {
    $sid = $row['skill_id']; $m = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($sid) . '&select=versao,pausada&order=versao.desc&limit=1')['body'][0] ?? null;
    $ver = $m ? (int)$m['versao'] + 1 : 1; $pz = $m && !empty($m['pausada']);
    if ($m) pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($sid), 'PATCH', ['ativa' => false]);
    $ex = []; foreach (['bom' => 'exemplo_bom', 'ruim' => 'exemplo_ruim'] as $t => $k) if (trim((string)($a[$k] ?? '')) !== '') $ex[] = ['tipo' => $t, 'texto' => mb_substr(trim((string)$a[$k]), 0, 3000)];
    $r = pdAulaSup('/rest/v1/pd_habilidades', 'POST', ['skill_id' => $sid, 'versao' => $ver, 'nome' => mb_substr(trim($a['nome']), 0, 90), 'finalidade' => mb_substr(trim($a['objetivo']), 0, 800), 'entradas' => $a['entradas'], 'metodo' => mb_substr(trim($a['metodo']), 0, 6000),
        'criterios' => $a['criterios'], 'limites' => mb_substr(trim((string)($a['limites'] ?? '')), 0, 1500), 'exemplos' => $ex, 'ativa' => !$pz, 'pausada' => $pz, 'origem' => 'doc', 'notas_versao' => mb_substr((string)($a['o_que_mudou'] ?? 'escrita pela professora'), 0, 500), 'criada_por' => $userId]);
    return ($r['code'] ?? 500) < 300 ? [true, $ver] : [false, mb_substr(json_encode($r['body'] ?? '', JSON_UNESCAPED_UNICODE), 0, 200)];
}
/** Confere os pedidos abertos: se a professora já devolveu o AULA.json, valida e publica. */
function pdCurrVarrer() {
    if (!pdPonteOk()) return null;
    $rows = pdAulaSup('/rest/v1/pd_curriculo_pedidos?status=eq.pedido&select=*&limit=20')['body'] ?? []; $out = [];
    foreach ((array)$rows as $row) {
        $ch = (array)$row['chamado']; if (empty($ch['saidaId'])) continue;
        try {
            $arq = ponte('listar', ['folderId' => $ch['saidaId']])['arquivos'] ?? []; $f = null;
            foreach ($arq as $x) if (preg_match('/^aula.*\.json$/i', $x['name'])) { if (!$f || $x['modified'] > $f['modified']) $f = $x; }
            if (!$f) { // resposta por e-mail
                $em = null; try { $em = ponte('email_correcao', ['chamado' => $ch['pedido']]); } catch (Throwable $e) {}
                if ($em && !empty($em['achou'])) $j = pdJson((string)$em['json']); else continue;
            } else $j = pdJson(base64_decode((string)(ponte('baixar', ['fileId' => $f['id']])['b64'] ?? '')));
            [$ok, $a] = pdCurrValida($j, $row);
            if (!$ok) { if (($ch['erro'] ?? '') !== $a) { pdAulaSup('/rest/v1/pd_curriculo_pedidos?id=eq.' . urlencode($row['id']), 'PATCH', ['erro' => $a, 'chamado' => array_merge($ch, ['erro' => $a])]); try { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'AULA REJEITADA: ' . $a . ' — corrija o AULA.json', 'rodada' => 1]); } catch (Throwable $e) {} } $out[] = $row['titulo'] . ' rejeitada: ' . $a; continue; }
            [$s, $ver] = pdCurrSalvar($row, $a, $row['criado_por']);
            if (!$s) { $out[] = $row['titulo'] . ' não salvou: ' . $ver; continue; }
            pdAulaSup('/rest/v1/pd_curriculo_pedidos?id=eq.' . urlencode($row['id']), 'PATCH', ['status' => 'entregue', 'versao' => $ver, 'entregue_em' => gmdate('c'), 'erro' => null]);
            try { ponte('status', ['controleId' => $ch['controleId'], 'status' => 'RECEBIDO PELO SD', 'rodada' => 1, 'obs' => 'v' . $ver]); } catch (Throwable $e) {}
            if (function_exists('pdNotificarResp')) try { pdNotificarResp('SERGIO', 'escola', 'Aula nova da professora: ' . $row['titulo'], 'Versão ' . $ver . ' publicada na Escola. Confira nos bastidores.', null); } catch (Throwable $e) {}
            $out[] = $row['titulo'] . ' v' . $ver . ' publicada';
        } catch (Throwable $e) { error_log('curriculo varrer: ' . $e->getMessage()); }
    }
    return $out ? implode(' | ', $out) : null;
}
