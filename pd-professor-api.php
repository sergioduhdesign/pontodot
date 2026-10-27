<?php
// =====================================================================
// Pontodot — API do PROFESSOR EXTERNO (o seu GPT, com histórico próprio)
// O GPT personalizado (ChatGPT → Actions) chama estes endereços com
//   Authorization: Bearer <SD_PROFESSOR_TOKEN>      (definido no sd-config.php)
// Ações (POST JSON): pendentes · corrigir · ensinar
// Nada aqui toca cliente real: só provas simuladas da sala de aula.
// =====================================================================
require_once __DIR__ . '/pd-aula-lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$tok = defined('SD_PROFESSOR_TOKEN') ? SD_PROFESSOR_TOKEN : '';
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
if (!$tok || !preg_match('/^Bearer\s+(.+)$/i', trim($h), $m) || !hash_equals($tok, trim($m[1]))) { http_response_code(401); echo json_encode(['error' => 'token inválido ou SD_PROFESSOR_TOKEN não configurado']); exit; }
$b = json_decode(file_get_contents('php://input'), true) ?: []; $acao = (string)($b['acao'] ?? $_GET['acao'] ?? 'pendentes');
$cfg = pdIncub() ?: ['nota_minima' => 85];

if ($acao === 'pendentes') {   // provas esperando a correção do professor
    $as = pdAulaSup('/rest/v1/pd_aulas?status=eq.em_correcao&order=criada_em.asc&limit=10&select=id,personagem,skill_id,rodada,max_rodadas,exercicio,entregas,correcoes')['body'] ?? [];
    $out = [];
    foreach ($as as $a) {
        $sk = pdAulaSup('/rest/v1/pd_habilidades?skill_id=eq.' . urlencode($a['skill_id']) . '&ativa=eq.true&order=versao.desc&limit=1&select=nome,finalidade,criterios')['body'][0] ?? [];
        $ent = (array)$a['entregas']; $ult = end($ent);
        $out[] = ['aula_id' => $a['id'], 'aluna' => $a['personagem'], 'habilidade' => $sk, 'rodada' => $a['rodada'], 'ultima_rodada' => (int)$a['rodada'] >= (int)$a['max_rodadas'],
                  'exercicio' => $a['exercicio'], 'correcoes_anteriores' => $a['correcoes'], 'entrega_svg' => $ult['svg'] ?? '', 'nota_minima_para_aprovar' => (float)$cfg['nota_minima']];
    }
    echo json_encode(['pendentes' => $out], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'corrigir') {   // devolve nota + feedback (+ lição geral reutilizável)
    $a = pdAulaSup('/rest/v1/pd_aulas?id=eq.' . urlencode((string)($b['aula_id'] ?? '')) . '&select=*')['body'][0] ?? null;
    if (!$a) { http_response_code(404); echo json_encode(['error' => 'aula não encontrada']); exit; }
    if ($a['status'] !== 'em_correcao') { http_response_code(409); echo json_encode(['error' => 'esta prova não está aguardando correção (status: ' . $a['status'] . ')']); exit; }
    if (!isset($b['nota'])) { http_response_code(400); echo json_encode(['error' => 'envie "nota" (0 a 100) e "feedback"']); exit; }
    $r = pdAulaAplicarCorrecao($a, ['nota' => $b['nota'], 'feedback' => $b['feedback'] ?? '', 'pontos_fortes' => $b['pontos_fortes'] ?? '', 'licao' => $b['licao'] ?? null], 'Seu GPT (externo)', $cfg);
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'ensinar') {   // lição avulsa do GPT para uma IA (fica proposta até o gestor aprovar)
    $pers = strtolower(trim((string)($b['personagem'] ?? ''))); $regra = trim((string)($b['regra'] ?? '')); $sk = (string)($b['skill_id'] ?? '');
    if (!$regra || !$sk) { http_response_code(400); echo json_encode(['error' => 'envie "regra" e "skill_id"']); exit; }
    $ia = null; if ($pers) $ia = pdAulaSup('/rest/v1/pd_jeito?personagem=ilike.' . urlencode($pers) . '&select=user_id&limit=1')['body'][0]['user_id'] ?? null;
    pdAulaSup('/rest/v1/pd_licoes', 'POST', ['ia_user_id' => $ia, 'personagem' => $pers ?: null, 'skill_id' => $sk, 'regra' => mb_substr($regra, 0, 600), 'exemplo_bom' => mb_substr((string)($b['exemplo_bom'] ?? ''), 0, 400), 'exemplo_ruim' => mb_substr((string)($b['exemplo_ruim'] ?? ''), 0, 400)]);
    echo json_encode(['ok' => true, 'msg' => 'Lição registrada como proposta; vale depois que o gestor aprovar.']); exit;
}
http_response_code(400); echo json_encode(['error' => 'ação desconhecida']);
