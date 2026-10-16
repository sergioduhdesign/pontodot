<?php
// =====================================================================
// SD Workflow — OS LEMBRETES
//
// Isto é o que faltava: alguém que OLHA os prazos e CUTUCA quem precisa
// entregar. Roda de hora em hora pelo cron do HostGator.
//
//   GET  /lembretes.php?key=SUA_CHAVE          -> manda os avisos
//   GET  /lembretes.php?key=...&seco=1         -> só mostra quem receberia
//
// Regras da casa:
//   · Um aviso por pessoa, com tudo junto. Cinco tarefas não viram cinco
//     vibrações no bolso de ninguém.
//   · Dentro do horário de trabalho (8h–20h de Campinas). Prazo não
//     acorda ninguém às 3 da manhã.
//   · Uma vez por dia por pessoa e por assunto. O celular que repete
//     vira celular no silencioso.
//   · Atrasada > vence hoje > vence amanhã. Nessa ordem de aflição.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/push-lib.php';

header('Content-Type: application/json; charset=utf-8');

// --------------------------------------------------------------- portão
$chave = $_GET['key'] ?? ($_POST['key'] ?? '');
$esperada = defined('SD_CRON_KEY') ? SD_CRON_KEY : (defined('SD_PONTE_KEY') ? SD_PONTE_KEY : '');
if (!$esperada || !hash_equals((string)$esperada, (string)$chave)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'chave inválida']); exit;
}
$seco = !empty($_GET['seco']);
if (!$seco) { require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('lembretes'); }

// --------------------------------------------------------------- relógio
$tz    = new DateTimeZone('America/Sao_Paulo');
$agora = new DateTime('now', $tz);
$hora  = (int)$agora->format('G');
$hoje  = $agora->format('Y-m-d');
$amanha = (clone $agora)->modify('+1 day')->format('Y-m-d');

if (!$seco && ($hora < 8 || $hora >= 20)) {
    echo json_encode(['ok' => true, 'pulou' => 'fora do horário (8h–20h)', 'hora' => $hora]); exit;
}

// --------------------------------------------------- quem tem o que fazer
$STATUS_FECHADO = ['ENTREGUE'];
$r = sd_supabase('/rest/v1/tasks?select=id,action,status,resp,date_int,date_cli,clients(name)&limit=2000');
if ($r['code'] !== 200) { http_response_code(500); echo json_encode(['ok' => false, 'erro' => 'não li as tarefas']); exit; }

$p = sd_supabase('/rest/v1/profiles?select=id,name,team_key,role,active&limit=500');
$perfis = $p['body'] ?? [];

/** O resp da tarefa vem sem acento e em caixa alta. Casa com o perfil. */
function lb_chave($s) {
    $s = (string)$s;
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
    return mb_strtoupper(trim($s), 'UTF-8');
}
$porChave = [];
foreach ($perfis as $pf) {
    if (isset($pf['active']) && $pf['active'] === false) continue;
    if (($pf['role'] ?? '') === 'client') continue;         // cliente não recebe cobrança de prazo interno
    foreach ([$pf['team_key'] ?? '', $pf['name'] ?? ''] as $n) {
        if (!$n) continue;
        $porChave[lb_chave($n)] = $pf;
        $primeiro = explode(' ', trim((string)$n))[0];
        if ($primeiro) $porChave[lb_chave($primeiro)] ??= $pf;
    }
}

// ----------------------------------------------------- junta por pessoa
$fila = [];   // user_id => ['nome'=>, 'atrasadas'=>[], 'hoje'=>[], 'amanha'=>[]]
foreach (($r['body'] ?? []) as $t) {
    if (in_array((string)($t['status'] ?? ''), $STATUS_FECHADO, true)) continue;
    $prazo = substr((string)($t['date_int'] ?? ''), 0, 10);
    if (!$prazo) continue;

    $quando = $prazo < $hoje ? 'atrasadas' : ($prazo === $hoje ? 'hoje' : ($prazo === $amanha ? 'amanha' : null));
    if (!$quando) continue;

    $pf = $porChave[lb_chave($t['resp'] ?? '')] ?? null;
    if (!$pf) continue;                                      // sem dono conhecido, ninguém é cutucado
    $id = $pf['id'];
    $fila[$id] ??= ['nome' => $pf['name'] ?? '', 'atrasadas' => [], 'hoje' => [], 'amanha' => []];
    $fila[$id][$quando][] = [
        'id'      => $t['id'],
        'acao'    => mb_substr((string)($t['action'] ?? ''), 0, 60),
        'cliente' => $t['clients']['name'] ?? 'SD',
        'prazo'   => $prazo,
    ];
}

// -------------------------------------- já avisei essa pessoa hoje disso?
function lb_ja_avisou($userId, $assunto, $hoje) {
    $q = sd_supabase('/rest/v1/notifications?user_id=eq.' . urlencode($userId)
        . '&type=eq.' . urlencode($assunto)
        . '&created_at=gte.' . urlencode($hoje . 'T00:00:00')
        . '&select=id&limit=1');
    return !empty($q['body']);
}

// ------------------------------------------------------------- o recado
function lb_texto($grupo, $itens) {
    $n = count($itens);
    $lista = implode(' · ', array_map(fn($i) => $i['id'] . ' ' . $i['acao'], array_slice($itens, 0, 3)));
    if ($n > 3) $lista .= ' · +' . ($n - 3);
    if ($grupo === 'atrasadas') return ["$n tarefa" . ($n > 1 ? 's atrasadas' : ' atrasada'), $lista];
    if ($grupo === 'hoje')      return ["$n pra entregar hoje", $lista];
    return ["$n pra entregar amanhã", $lista];
}

// ------------------------------------------------------------- disparo
$saida = [];
foreach ($fila as $userId => $f) {
    // Uma aflição por vez: quem tem atrasada não precisa ouvir sobre amanhã.
    $grupo = count($f['atrasadas']) ? 'atrasadas' : (count($f['hoje']) ? 'hoje' : (count($f['amanha']) ? 'amanha' : null));
    if (!$grupo) continue;
    $assunto = 'lembrete_' . $grupo;

    if (!$seco && lb_ja_avisou($userId, $assunto, $hoje)) {
        $saida[] = ['quem' => $f['nome'], 'grupo' => $grupo, 'pulou' => 'já avisei hoje']; continue;
    }

    [$titulo, $corpo] = lb_texto($grupo, $f[$grupo]);
    $primeiro = explode(' ', trim((string)$f['nome']))[0];
    if ($primeiro) $titulo = $primeiro . ', ' . lcfirst($titulo);

    if ($seco) { $saida[] = ['quem' => $f['nome'], 'grupo' => $grupo, 'titulo' => $titulo, 'corpo' => $corpo, 'seco' => true]; continue; }

    $enviados = sd_push_usuario($userId, $titulo, $corpo, '/index.html', 'sd-' . $assunto);
    // O sino do app registra mesmo quando o celular não recebeu: a pessoa
    // vê o aviso quando abrir, e eu não cutuco de novo no mesmo dia.
    sd_supabase('/rest/v1/notifications', 'POST', [
        'user_id' => $userId, 'type' => $assunto, 'title' => $titulo, 'body' => $corpo, 'link' => 'tarefas',
    ]);
    $saida[] = ['quem' => $f['nome'], 'grupo' => $grupo, 'quantas' => count($f[$grupo]), 'aparelhos' => $enviados];
}

echo json_encode(['ok' => true, 'quando' => $agora->format('c'), 'seco' => $seco,
    'pessoas' => count($fila), 'avisos' => $saida], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
