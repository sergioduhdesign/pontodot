<?php
// =====================================================================
// Pontodot — AULA DE DOCUMENTO (a aula nasce de um job real)
//
//   O documento que a Sumi/Muru escreveu para uma tarefa real (estratégia
//   de campanha, de social, copy) vira uma aula: a professora (o GPT do
//   Sérgio) corrige pelo contrato pd-correcao/2, a aluna refaz, até 3
//   rodadas na noite. Na virada de dia a última versão volta para a tarefa
//   e os responsáveis recebem o aviso. Quem decide a lição é o Sérgio.
//
//   Papéis iguais aos da aula de arte (pd-aula-lib.php). O que muda aqui:
//   · a entrega é texto (entrega-R<n>.md), não SVG;
//   · o cliente é REAL e a pasta leva o pacote de contexto (ficha,
//     produtos, briefing da tarefa, afinação do dono da tarefa);
//   · esgotar as rodadas não reprova: devolve com pendências.
// =====================================================================
require_once __DIR__ . '/pd-aula-lib.php';
require_once __DIR__ . '/meta-ads-lib.php';

const PD_AULA_DOC_PERSONAS = ['sumi' => 'ESTRATEGIA_CAMPANHA', 'muru' => 'ESTRATEGIA_SOCIAL', 'copy' => 'COPY'];

function pdAulaDocEhDoc($a) { return (($a['tipo'] ?? 'arte') === 'documento'); }
function pdAulaDocPersonaSistema($personagem) {
    if ($personagem === 'muru') return sdp_muru_sistema();
    if ($personagem === 'sumi') return pd_sumi_sistema();
    return 'Você é a IA de copy da casa (agência de branding do mercado imobiliário brasileiro). Escreve títulos, textos e legendas no tom da marca, com promessa sustentada pela ficha do cliente, sem adjetivo vazio, com CTA claro. Responda em markdown simples, pt-BR.';
}
/** Pacote de contexto que vai na pasta e no prompt: ficha, produtos, briefing da tarefa, afinação. */
function pdAulaDocContexto($clientId, $taskId, $afinacao, $extra = []) {
    $ctx = ['cliente' => '', 'ficha' => '', 'produtos' => [], 'tarefa' => null, 'afinacao' => trim((string)$afinacao)];
    if ($clientId && preg_match('/^[0-9a-f-]{36}$/i', $clientId)) {
        $c = pd_ctx_cliente($clientId); if ($c) { $ctx['cliente'] = $c['cliente']; $ctx['ficha'] = mb_substr((string)($c['ficha'] ?? ''), 0, 6000); $ctx['produtos'] = $c['produtos']; }
    }
    if ($taskId) { $t = pdAulaSup('/rest/v1/tasks?id=eq.' . urlencode($taskId) . '&select=id,action,obs,resp,project,date_int,clients(name)')['body'][0] ?? null;
        if ($t) { $ctx['tarefa'] = ['id' => $t['id'], 'acao' => $t['action'], 'briefing' => (string)($t['obs'] ?? ''), 'resp' => $t['resp'], 'prazo' => $t['date_int'] ?? null]; if ($ctx['cliente'] === '') $ctx['cliente'] = (string)($t['clients']['name'] ?? ''); } }
    return $ctx + $extra;
}
function pdAulaDocContextoTexto($ctx) {
    $L = ['CLIENTE: ' . ($ctx['cliente'] ?: '(sem cliente)')];
    if (!empty($ctx['produtos'])) $L[] = 'PRODUTOS: ' . implode(', ', (array)$ctx['produtos']);
    if (!empty($ctx['tarefa'])) $L[] = 'TAREFA REAL: ' . $ctx['tarefa']['id'] . ' — ' . $ctx['tarefa']['acao'] . "\nBRIEFING DA TAREFA:\n" . ($ctx['tarefa']['briefing'] ?: '(sem briefing)');
    if (!empty($ctx['afinacao'])) $L[] = "AFINAÇÃO DO DONO DA TAREFA (prioridade sobre tudo):\n" . $ctx['afinacao'];
    if (!empty($ctx['concorrentes'])) $L[] = "CONCORRENTES (pesquisa do Tumi):\n" . mb_substr(json_encode($ctx['concorrentes'], JSON_UNESCAPED_UNICODE), 0, 6000);
    if (!empty($ctx['ficha'])) $L[] = "FICHA DO CLIENTE NA PLATAFORMA:\n" . $ctx['ficha'];
    return implode("\n\n", $L);
}
/** A virada de dia: amanhã às 07:00 (America/Sao_Paulo); se já passou das 07:00 de hoje e ainda é madrugada, hoje mesmo. */
function pdAulaDocCfg() { $c = pdIncub() ?: []; return ['hora_professora' => substr((string)($c['hora_professora'] ?? '20:00'), 0, 5), 'hora_virada' => substr((string)($c['hora_virada'] ?? '07:00'), 0, 5), 'max' => max(1, (int)($c['max_rodadas_noite'] ?? 3)), 'teto_noite' => (float)($c['teto_noite'] ?? 10), 'fds' => !array_key_exists('fins_de_semana', $c) || !empty($c['fins_de_semana'])]; }
function pdAulaDocPrazo() {   // a virada de dia: a próxima hora_virada depois da hora_professora
    $cfg = pdAulaDocCfg(); [$hv, $mv] = array_map('intval', explode(':', $cfg['hora_virada'] . ':0'));
    $tz = new DateTimeZone('America/Sao_Paulo'); $d = new DateTime('now', $tz);
    if ((int)$d->format('G') * 60 + (int)$d->format('i') >= $hv * 60 + $mv) $d->modify('+1 day'); $d->setTime($hv, $mv, 0);
    if (!$cfg['fds']) while (in_array((int)$d->format('N'), [6, 7], true)) $d->modify('+1 day');
    $d->setTimezone(new DateTimeZone('UTC')); return $d->format('c');
}
function pdAulaDocInicio() {   // quando a PD manda a aula à professora: a próxima hora_professora (hoje, se ainda não passou)
    $cfg = pdAulaDocCfg(); [$hp, $mp] = array_map('intval', explode(':', $cfg['hora_professora'] . ':0'));
    $tz = new DateTimeZone('America/Sao_Paulo'); $d = new DateTime('now', $tz);
    if ((int)$d->format('G') * 60 + (int)$d->format('i') >= $hp * 60 + $mp) return gmdate('c');   // já é noite: vai agora
    $d->setTime($hp, $mp, 0); $d->setTimezone(new DateTimeZone('UTC')); return $d->format('c');
}

/** Cria a aula a partir do documento e já manda o aviso à professora. */
function pdAulaDocCriar($criadoPor, $personagem, $texto, $clientId = null, $taskId = null, $docId = null, $origem = null, $afinacao = '', $extra = []) {
    $personagem = strtolower(trim((string)$personagem)); $skill = PD_AULA_DOC_PERSONAS[$personagem] ?? null;
    if (!$skill) return ['ok' => false, 'msg' => 'persona sem habilidade de documento (' . $personagem . ')'];
    $texto = trim((string)$texto); if (mb_strlen($texto) < 80) return ['ok' => false, 'msg' => 'documento curto demais para virar aula'];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($skill) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade ' . $skill . ' não cadastrada (rode o SQL v163)'];
    $ctx = pdAulaDocContexto($clientId, $taskId, $afinacao, $extra);
    $ex = ['autor' => 'job real', 'cliente_real' => $ctx['cliente'], 'brief' => $ctx['tarefa']['briefing'] ?? '', 'tarefa' => $ctx['tarefa']['id'] ?? null, 'criterios' => $sk['criterios'] ?? [], 'skill_versao' => $sk['versao'] ?? 1];
    $inicio = pdAulaDocInicio(); $agora = $inicio <= gmdate('c');
    $r = pdAulaSup('/rest/v1/pd_aulas', 'POST', ['ia_user_id' => $criadoPor, 'personagem' => $personagem, 'skill_id' => $skill, 'modo' => 'job', 'tipo' => 'documento', 'status' => 'em_correcao', 'rodada' => 1, 'max_rodadas' => pdAulaDocCfg()['max'],
        'exercicio' => $ex, 'entregas' => [['rodada' => 1, 'texto' => $texto, 'em' => gmdate('c'), 'por' => 'documento original']], 'task_id' => $taskId, 'client_id' => preg_match('/^[0-9a-f-]{36}$/i', (string)$clientId) ? $clientId : null,
        'doc_id' => preg_match('/^[0-9a-f-]{36}$/i', (string)$docId) ? $docId : null, 'origem' => $origem, 'contexto' => $ctx + ['inicio' => $inicio], 'prazo' => pdAulaDocPrazo(), 'criada_por' => $criadoPor, 'dia' => date('Y-m-d', time() - 3 * 3600)], ['Prefer: return=representation']);
    $a = $r['body'][0] ?? null; if (!$a) return ['ok' => false, 'msg' => 'não consegui gravar a aula (' . ($r['code'] ?? '?') . '). O SQL v163 já rodou?'];
    if ($taskId) { if (!function_exists('pdEvento')) require_once __DIR__ . '/pd-lib.php'; pdEvento($taskId, 'aula', 'Documento em aula com a professora', 'Até 3 rodadas esta noite. De manhã a versão final volta para esta tarefa.', 'Escola', 'robo', ['aula_id' => $a['id']]); }
    if (!$agora) return ['ok' => true, 'aula_id' => $a['id'], 'prazo' => $a['prazo'], 'inicio' => $inicio, 'msg' => 'Aula criada; a professora recebe o aviso às ' . pdAulaDocCfg()['hora_professora'] . '.', 'aviso' => true];
    $e = pdProfessorEnviar($a, $sk, pdIncub() ?: ['nota_minima' => 85], true);
    return ['ok' => true, 'aula_id' => $a['id'], 'prazo' => $a['prazo'], 'inicio' => $inicio, 'msg' => 'Aula criada. ' . ($e['msg'] ?? ''), 'aviso' => !empty($e['ok'])];
}

/** A aluna refaz o documento a partir do parecer (e da afinação). Devolve [texto, erro]. */
function pdAulaDocRefazer($a, $feedback) {
    $ent = (array)$a['entregas']; $ult = end($ent); $ctx = (array)($a['contexto'] ?? []);
    $sys = pdAulaDocPersonaSistema($a['personagem']) . "\n\n" . pdLicoesTexto($a['ia_user_id'], $a['skill_id'])
         . "\nVocê está numa AULA da escola da casa: a professora corrigiu a sua versão. Refaça o DOCUMENTO INTEIRO, no mesmo formato e seções, aplicando cada correção; mantenha o que não foi apontado. No fim, acrescente a seção '## O que mudei e por quê' (3 a 8 linhas, uma por correção aplicada; se discordar de algo, diga o motivo em vez de aplicar).";
    $msg = pdAulaDocContextoTexto($ctx) . "\n\n=== SUA VERSÃO ATUAL (rodada " . (int)($ult['rodada'] ?? 1) . ") ===\n" . mb_substr((string)($ult['texto'] ?? ''), 0, 24000) . "\n\n=== CORREÇÃO DA PROFESSORA ===\n" . mb_substr((string)$feedback, 0, 8000);
    [$t, $custo, $erro] = pd_ia_campanhas($sys, [['role' => 'user', 'content' => $msg]], 6000, 1);
    pdIncubSoma((float)$custo);
    if ($erro || trim((string)$t) === '') return [null, $erro ?: 'a aluna não devolveu texto'];
    return [trim((string)$t), null];
}

/** Um passo da aula de documento (chamado pelo relógio). */
function pdAulaDocPasso($a) {
    $id = $a['id']; $patch = fn($d) => pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($id), 'PATCH', $d);
    $cfg = pdIncub() ?: ['nota_minima' => 85, 'professor_modo' => 'projeto_gpt'];
    $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=*')['body'][0] ?? null;
    if (!$sk) return ['ok' => false, 'msg' => 'habilidade não cadastrada (SQL v163)'];
    if ($a['status'] === 'em_exercicio') {       // a aluna refaz com o último parecer
        $ent = (array)$a['entregas']; if (count($ent) >= (int)$a['rodada']) { $patch(['status' => 'em_correcao']); return ['ok' => true, 'msg' => 'rodada ' . $a['rodada'] . ' já tinha versão']; }
        if (!pdIncubTemTeto()) return ['ok' => false, 'msg' => 'teto de gasto do dia'];
        $c0 = pdIncub() ?: []; if ((float)($c0['gasto_hoje'] ?? 0) >= pdAulaDocCfg()['teto_noite']) return ['ok' => false, 'msg' => 'teto da noite atingido (US$ ' . pdAulaDocCfg()['teto_noite'] . ')'];
        $cor = (array)$a['correcoes']; $uc = $cor ? end($cor) : null; $fb = $uc ? (($uc['texto_do_professor'] ?: $uc['feedback']) . (!empty($uc['humano']) ? "\n\nCOMENTÁRIO DO SÉRGIO (prioridade máxima):\n" . $uc['humano'] : '')) : 'Revise com rigor pelos critérios.';
        [$txt, $err] = pdAulaDocRefazer($a, $fb); if (!$txt) return ['ok' => false, 'msg' => 'a aluna não refez: ' . $err];
        $ent[] = ['rodada' => (int)$a['rodada'], 'texto' => $txt, 'em' => gmdate('c'), 'por' => $a['personagem']];
        $patch(['entregas' => $ent, 'status' => 'em_correcao']);
        if (!empty($a['doc_id'])) pdAulaSup('/rest/v1/pd_docs_ia?id=eq.' . urlencode($a['doc_id']), 'PATCH', ['texto_final' => $txt, 'editado_em' => gmdate('c')]);
        $a['entregas'] = $ent; $a['status'] = 'em_correcao';
        $e = pdProfessorEnviar($a, $sk, $cfg, true); return ['ok' => true, 'msg' => 'rodada ' . $a['rodada'] . ': a aluna refez; ' . ($e['msg'] ?? '')];
    }
    if ($a['status'] === 'em_correcao') return pdProfessorPasso($a, $sk, $cfg);   // envia o aviso (se faltou) ou lê o CORRECAO.json
    return ['ok' => false, 'msg' => 'nada a fazer em "' . $a['status'] . '"'];
}

/** Virada de dia: a última versão volta para a tarefa e os responsáveis recebem o aviso. */
function pdAulaDocEntregar($a, $motivo) {
    if (!function_exists('pdNotificarResp')) require_once __DIR__ . '/pd-lib.php';
    $ent = (array)$a['entregas']; $ult = end($ent); $cor = (array)$a['correcoes']; $uc = $cor ? end($cor) : null;
    $pend = []; if ($uc && (float)($uc['nota'] ?? 0) >= PD_NOTA_RESSALVA && (float)($uc['nota'] ?? 0) < PD_NOTA_APROVA) $pend[] = 'LIBERADA COM RESSALVA (nota ' . round((float)$uc['nota']) . '): ' . mb_substr((string)($uc['feedback'] ?? ''), 0, 600);
    elseif ($uc && empty($uc['aprovado']) && (float)($uc['nota'] ?? 0) < PD_NOTA_RESSALVA) $pend[] = 'Professora ainda não aprovou (nota ' . round((float)($uc['nota'] ?? 0)) . '): ' . mb_substr((string)($uc['feedback'] ?? ''), 0, 600);
    if (!$cor) $pend[] = 'A professora não respondeu esta noite; a versão é a original.';
    $txt = (string)($ult['texto'] ?? ''); $pendTxt = $pend ? implode("\n", $pend) : '';
    if (!empty($a['doc_id'])) pdAulaSup('/rest/v1/pd_docs_ia?id=eq.' . urlencode($a['doc_id']), 'PATCH', ['texto_final' => $txt, 'editado_em' => gmdate('c')]);
    $resumo = 'Aula da ' . ucfirst($a['personagem']) . ': ' . count($cor) . ' parecer' . (count($cor) === 1 ? '' : 'es') . ', ' . count($ent) . ' vers' . (count($ent) === 1 ? 'ão' : 'ões') . '. ' . $motivo . ($pendTxt ? "\nPENDÊNCIAS:\n" . $pendTxt : '');
    if (!empty($a['task_id'])) {
        $corpo = "✎ Versão da noite (R" . (int)($ult['rodada'] ?? 1) . "):\n" . mb_substr($txt, 0, 1700) . (mb_strlen($txt) > 1700 ? "\n(…continua no documento)" : '') . "\n\n" . $resumo;
        $linha = ['task_id' => $a['task_id'], 'user_id' => $a['criada_por'] ?: $a['ia_user_id'], 'body' => $corpo, 'meta' => ['aula_id' => $a['id'], 'tipo' => 'aula_documento', 'personagem' => $a['personagem']]];
        $r = sd_supabase('/rest/v1/comments', 'POST', $linha); if (($r['code'] ?? 500) >= 300) { unset($linha['meta']); sd_supabase('/rest/v1/comments', 'POST', $linha); }
        pdEvento($a['task_id'], 'aula', 'A professora devolveu o documento', $resumo, 'Escola', 'robo', ['aula_id' => $a['id']]);
        $t = pdAulaSup('/rest/v1/tasks?id=eq.' . urlencode($a['task_id']) . '&select=resp,action,clients(name)')['body'][0] ?? null;
        if ($t && !empty($t['resp'])) pdNotificarResp($t['resp'], 'aula', '☀ ' . ($t['clients']['name'] ?? 'Cliente') . ' · ' . mb_substr((string)$t['action'], 0, 60), 'A versão da noite está na tarefa ' . $a['task_id'] . '. Faça a última checagem antes de mandar para produção.' . ($pendTxt ? "\n" . mb_substr($pendTxt, 0, 300) : ''), 'task:' . $a['task_id'], null, $a['task_id']);
    }
    pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']), 'PATCH', ['entregue_em' => gmdate('c'), 'pendencias' => $pendTxt ?: null, 'status' => $cor ? 'validacao_humana' : 'reprovada', 'nota_final' => $uc['nota'] ?? null]);
    return 'entregue: ' . $a['id'];
}

/** Relógio das aulas de documento: roda no pd-worker (a cada 5 min), independente da rotina de provas. */
function pdAulaDocRelogio() {
    if (!pdPonteOk()) return 'ponte do Drive não configurada';
    // RECUPERAÇÃO: aula entregue na virada com parecer abaixo da regra e rodadas sobrando, sem versão nova → reabre a MESMA aula (sem criar outra)
    try { foreach ((array)(pdAulaSup('/rest/v1/pd_aulas?tipo=eq.documento&entregue_em=not.is.null&status=eq.validacao_humana&modo=eq.job&select=id,correcoes,entregas,max_rodadas,entregue_em&order=criada_em.desc&limit=30')['body'] ?? []) as $x) {
        $cx = (array)$x['correcoes']; $uc = $cx ? end($cx) : null; if (!$uc || (float)$uc['nota'] >= PD_NOTA_RESSALVA || count((array)$x['entregas']) > count($cx) || count($cx) >= (int)$x['max_rodadas']) continue;
        if (!empty($uc['humano'])) continue;   // o responsável já mexeu: ele decide
        if (strtotime((string)$x['entregue_em']) < time() - 36 * 3600) continue;   // só aulas recentes
        pdAulaReabrir($x['id']); } } catch (Throwable $e) {}
    $as = pdAulaSup('/rest/v1/pd_aulas?tipo=eq.documento&entregue_em=is.null&status=in.(em_exercicio,em_correcao,validacao_humana)&select=*&order=criada_em&limit=20')['body'] ?? [];
    $msg = []; $agora = gmdate('c');
    foreach ((array)$as as $a) {
        try {
            $vencida = !empty($a['prazo']) && $a['prazo'] <= $agora;
            if (!$vencida && !empty($a['contexto']['inicio']) && $a['contexto']['inicio'] > $agora && $a['status'] !== 'validacao_humana') continue;   // ainda não é hora da professora
            if ($vencida || $a['status'] === 'validacao_humana') { $msg[] = pdAulaDocEntregar($a, $vencida ? 'Virada de dia.' : 'A professora aprovou.'); continue; }
            for ($i = 0; $i < 2; $i++) { $r = pdAulaDocPasso($a); if (!empty($r['msg'])) $msg[] = substr($a['id'], 0, 8) . ': ' . $r['msg'];
                $falha = empty($r['ok']) && preg_match('/não refez|teto|Falha|rejeit|não configurada|não cadastrada/u', (string)($r['msg'] ?? '')); pdAulaSincroniza($a['id'], null, $falha ? mb_substr((string)$r['msg'], 0, 300) : null);
                if (empty($r['ok'])) break;
                $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode($a['id']) . '&select=*')['body'][0] ?? $a; if (!empty($a['entregue_em']) || $a['status'] === 'validacao_humana') break; }
        } catch (Throwable $e) { $msg[] = substr($a['id'], 0, 8) . ': ' . $e->getMessage(); }
    }
    return $msg ? implode(' | ', $msg) : 'nada novo';
}
