<?php
// =====================================================================
// Pontodot — OFICINA (fila de trabalhos) + MÓDULOS (capacidades da casa)
//
// Dois lados chegam aqui:
//  1) O WORKER (GitHub Actions ou servidor próprio), com a chave SD_OFICINA_KEY:
//       pegar · assinar_upload · log · concluir · falhar · pulso
//  2) A EQUIPE logada (sessão):
//       inicio · pedir · ok · cancelar · ver · modulo_salvar (admin) · modulo_ligar (admin)
//
// Arquivo gerado: o worker sobe no Storage (bucket "oficina"); ao concluir, a PD copia
// pro Drive (pasta do cliente › OFICINA, ou SD_DRIVE_OFICINA) — o Drive é o acervo.
//
// sd-config.php:
//   SD_OFICINA_KEY   — chave que o worker usa (obrigatória para a oficina rodar)
//   SD_GITHUB_TOKEN  — (opcional) acorda a oficina na hora, via repository_dispatch
//   SD_GITHUB_REPO   — (opcional) padrão sergioduhdesign/pontodot
//   SD_DRIVE_OFICINA — (opcional) pasta do Drive para trabalhos sem cliente
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
@set_time_limit(120);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'inicio');

function ofSup($path, $m = 'GET', $body = null, $h = []) { return sd_supabase($path, $m, $body, $h); }
function ofLog($trab, $modulo, $evento, $detalhe = '', $dados = []) {
    ofSup('/rest/v1/pd_oficina_log', 'POST', ['trabalho_id' => $trab, 'modulo' => $modulo, 'evento' => $evento, 'detalhe' => mb_substr((string)$detalhe, 0, 2000), 'dados' => $dados]);
}
function ofModulos() { $r = ofSup('/rest/v1/pd_modulos?select=*&order=tipo,nome'); if (($r['code'] ?? 500) >= 300) sd_fail('Rode o SQL v115 (fundação) no Supabase.', 500); return $r['body'] ?? []; }
function ofPodeVer($m, $u) {
    if ($u['role'] === 'admin') return true;
    if (!$m['ativo'] || $m['quarentena']) return false;
    foreach ((array)$m['visivel'] as $v) if ($v === $u['role'] || $v === $u['id']) return true;
    return false;
}
function ofAcorda() {   // acorda o worker no GitHub agora (sem token, a oficina roda no relógio dela)
    if (!defined('SD_GITHUB_TOKEN') || !SD_GITHUB_TOKEN) return 'relogio';
    $repo = defined('SD_GITHUB_REPO') && SD_GITHUB_REPO ? SD_GITHUB_REPO : 'sergioduhdesign/pontodot';
    $ch = curl_init('https://api.github.com/repos/' . $repo . '/dispatches');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_POSTFIELDS => json_encode(['event_type' => 'oficina']),
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . SD_GITHUB_TOKEN, 'User-Agent: pontodot-oficina', 'Content-Type: application/json']]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 204 ? 'agora' : ('github respondeu ' . $code);
}
// copia um arquivo do Storage pro Drive (acervo). Melhor esforço: se falhar, o link do Storage continua valendo.
function ofDrive($trab, $arq) {
    if (!function_exists('ponte') || !defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) return null;
    if ((int)($arq['bytes'] ?? 0) > 40 * 1024 * 1024) return null;   // a ponte não aguenta mais que isso num só pedido
    $pasta = null;
    if (!empty($trab['client_id'])) {
        $c = ofSup('/rest/v1/clients?id=eq.' . urlencode($trab['client_id']) . '&select=name,drive_id')['body'][0] ?? null;
        $id = $c['drive_id'] ?? null;
        if (!$id && $c && pdDriveClientes()) { $p = ponte('pasta_garantir', ['pai_id' => pdDriveClientes(), 'nome' => $c['name'], 'criar' => true]); $id = $p['id'] ?? null; }
        if ($id) { $sub = trim((string)(($trab['entrada']['drive_subpasta'] ?? '')), '/'); $pasta = $id;
            foreach ($sub ? explode('/', $sub) : ['OFICINA'] as $n) { $p = ponte('pasta_garantir', ['pai_id' => $pasta, 'nome' => mb_substr($n, 0, 90), 'criar' => true]); $pasta = $p['id'] ?? null; if (!$pasta) break; } }
    }
    if (!$pasta && defined('SD_DRIVE_OFICINA') && SD_DRIVE_OFICINA) $pasta = SD_DRIVE_OFICINA;
    if (!$pasta) return null;
    $ch = curl_init(SD_SUPABASE_URL . '/storage/v1/object/oficina/' . implode('/', array_map('rawurlencode', explode('/', $arq['path']))));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY]]);
    $bytes = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || $bytes === false || $bytes === '') return null;
    $g = ponte('gravar', ['folderId' => $pasta, 'nome' => $arq['nome'], 'mime' => $arq['mime'] ?? 'application/octet-stream', 'b64' => base64_encode($bytes), 'substituir' => false]);
    return ['drive_id' => $g['id'] ?? null, 'drive_url' => $g['url'] ?? null];
}
function ofLinkStorage($path) {   // link assinado (7 dias) do arquivo no Storage
    $r = ofSup('/storage/v1/object/sign/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))), 'POST', ['expiresIn' => 604800], ['Authorization: Bearer ' . SD_SECRET_KEY]);
    return !empty($r['body']['signedURL']) ? SD_SUPABASE_URL . '/storage/v1' . $r['body']['signedURL'] : null;
}

// ---------------------------------------------------------------------
// LADO DO WORKER
// ---------------------------------------------------------------------
$WORKER = ['pegar', 'assinar_upload', 'log', 'concluir', 'falhar', 'pulso'];
if (in_array($acao, $WORKER, true)) {
    if (!defined('SD_OFICINA_KEY') || !SD_OFICINA_KEY) sd_fail('Oficina sem chave: defina SD_OFICINA_KEY no sd-config.php.', 500);
    if (!hash_equals(SD_OFICINA_KEY, (string)($b['key'] ?? ''))) sd_fail('Chave da oficina inválida.', 403);
    $worker = mb_substr((string)($b['worker'] ?? 'worker'), 0, 60);
    if ($acao === 'pulso') { require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('oficina', ['worker' => $worker]); echo json_encode(['ok' => true]); exit; }
    if ($acao === 'pegar') {
        // pega o próximo da fila: só módulos ativos; "executar_ok" precisa de ok_em
        $ativos = array_column(array_filter(ofModulos(), fn($m) => $m['ativo']), 'slug'); if (!$ativos) { echo json_encode(['trabalho' => null]); exit; }
        $r = ofSup('/rest/v1/pd_trabalhos?status=eq.fila&modulo=in.(' . implode(',', array_map('rawurlencode', $ativos)) . ')&tentativas=lt.3&select=*&order=prioridade.asc,criado_em.asc&limit=1');
        $t = $r['body'][0] ?? null; if (!$t) { echo json_encode(['trabalho' => null]); exit; }
        // trava otimista: só vence quem mudar o status de fila → rodando
        $p = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($t['id']) . '&status=eq.fila', 'PATCH', ['status' => 'rodando', 'worker' => $worker, 'inicio_em' => gmdate('c'), 'tentativas' => (int)$t['tentativas'] + 1], ['Prefer: return=representation']);
        if (empty($p['body'][0])) { echo json_encode(['trabalho' => null, 'de_novo' => true]); exit; }
        $t = $p['body'][0]; ofLog($t['id'], $t['modulo'], 'pegou', 'Worker ' . $worker . ' pegou o trabalho.');
        $m = ofSup('/rest/v1/pd_modulos?slug=eq.' . urlencode($t['modulo']) . '&select=config')['body'][0] ?? [];
        echo json_encode(['trabalho' => $t, 'config' => $m['config'] ?? new stdClass()], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($acao === 'assinar_upload') {   // o worker pede uma URL assinada para subir um arquivo no bucket "oficina"
        $tid = (string)($b['trabalho_id'] ?? ''); $nome = preg_replace('/[^\w.\-]+/u', '_', (string)($b['nome'] ?? 'arquivo')); if (!$tid) sd_fail('Falta o trabalho.');
        $path = $tid . '/' . $nome;
        $r = ofSup('/storage/v1/object/upload/sign/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))), 'POST', new stdClass(), ['Authorization: Bearer ' . SD_SECRET_KEY]);
        if (empty($r['body']['url'])) sd_fail('Não consegui assinar o upload (bucket "oficina" existe? rode o SQL v115).', 500);
        echo json_encode(['url' => SD_SUPABASE_URL . '/storage/v1' . $r['body']['url'], 'path' => $path]); exit;
    }
    if ($acao === 'log') { ofLog((string)($b['trabalho_id'] ?? ''), (string)($b['modulo'] ?? ''), 'passo', (string)($b['detalhe'] ?? ''), (array)($b['dados'] ?? [])); echo json_encode(['ok' => true]); exit; }
    if ($acao === 'concluir') {
        $tid = (string)($b['trabalho_id'] ?? ''); $t = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&select=*')['body'][0] ?? null; if (!$t) sd_fail('Trabalho não encontrado.', 404);
        $saida = [];
        foreach ((array)($b['arquivos'] ?? []) as $a) {
            if (empty($a['path'])) continue;
            $arq = ['nome' => (string)($a['nome'] ?? basename($a['path'])), 'path' => (string)$a['path'], 'bytes' => (int)($a['bytes'] ?? 0), 'mime' => (string)($a['mime'] ?? ''), 'url' => ofLinkStorage((string)$a['path'])];
            try { $d = ofDrive($t, $arq); if ($d) $arq = array_merge($arq, $d); } catch (Exception $e) { ofLog($tid, $t['modulo'], 'passo', 'Cópia pro Drive falhou: ' . $e->getMessage()); }
            $saida[] = $arq;
        }
        $extra = (array)($b['resultado'] ?? []);
        ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid), 'PATCH', ['status' => 'pronto', 'fim_em' => gmdate('c'), 'saida' => ['arquivos' => $saida, 'resultado' => $extra]]);
        ofLog($tid, $t['modulo'], 'pronto', count($saida) . ' arquivo(s).', ['arquivos' => array_column($saida, 'nome')]);
        if (!empty($t['task_id'])) pdEvento($t['task_id'], 'oficina', 'Oficina entregou: ' . ($t['titulo'] ?: $t['modulo']), count($saida) . ' arquivo(s) prontos' . (array_filter(array_column($saida, 'drive_url')) ? ', com cópia no Drive.' : '.'), 'Oficina', 'robo');
        echo json_encode(['ok' => true, 'saida' => $saida], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($acao === 'falhar') {
        $tid = (string)($b['trabalho_id'] ?? ''); $t = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&select=tentativas,modulo')['body'][0] ?? null; if (!$t) sd_fail('Trabalho não encontrado.', 404);
        $msg = mb_substr((string)($b['erro'] ?? 'erro'), 0, 2000); $volta = (int)$t['tentativas'] < 3 && empty($b['definitivo']);
        ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid), 'PATCH', ['status' => $volta ? 'fila' : 'erro', 'erro' => $msg, 'fim_em' => $volta ? null : gmdate('c')]);
        ofLog($tid, $t['modulo'], 'erro', $msg . ($volta ? ' (volta pra fila)' : ' (desistiu)')); echo json_encode(['ok' => true]); exit;
    }
}

// ---------------------------------------------------------------------
// LADO DA EQUIPE
// ---------------------------------------------------------------------
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$admin = $u['role'] === 'admin';

if ($acao === 'anexar') {   // a equipe sobe um arquivo (quadro, clipe, áudio) pro bucket "oficina" e recebe o link assinado para a receita
    $a = (array)($b['arquivo'] ?? []); if (empty($a['b64']) || empty($a['nome'])) sd_fail('Arquivo vazio.');
    $raw = (string)$a['b64']; if (strpos($raw, ',') !== false) $raw = substr($raw, strpos($raw, ',') + 1); $bytes = base64_decode($raw, true); if (!$bytes) sd_fail('Arquivo inválido.');
    if (strlen($bytes) > 120 * 1024 * 1024) sd_fail('Arquivo maior que 120 MB: suba no Drive do cliente (MÍDIA) e use pela galeria.');
    $path = 'anexos/' . date('Ymd') . '/' . bin2hex(random_bytes(6)) . '-' . preg_replace('/[^\w.\-]+/u', '_', (string)$a['nome']);
    $ch = curl_init(SD_SUPABASE_URL . '/storage/v1/object/oficina/' . implode('/', array_map('rawurlencode', explode('/', $path))));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_POSTFIELDS => $bytes, CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY, 'Authorization: Bearer ' . SD_SECRET_KEY, 'Content-Type: ' . ((string)($a['mime'] ?? '') ?: 'application/octet-stream'), 'x-upsert: true']]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($code >= 300) sd_fail('Não consegui guardar o anexo (HTTP ' . $code . ').', 500);
    $url = ofLinkStorage($path); if (!$url) sd_fail('Não consegui assinar o anexo.', 500);
    echo json_encode(['ok' => true, 'url' => $url, 'path' => $path, 'bytes' => strlen($bytes)]); exit;
}
if ($acao === 'inicio') {
    $mods = array_values(array_filter(ofModulos(), fn($m) => ofPodeVer($m, $u)));
    $tr = ofSup('/rest/v1/pd_trabalhos?select=id,modulo,titulo,status,prioridade,erro,saida,client_id,task_id,pedido_por,pedido_via,criado_em,inicio_em,fim_em,clients(name),profiles!pd_trabalhos_pedido_por_fkey(name)&order=criado_em.desc&limit=60')['body'] ?? [];
    $cfg = ['worker_chave' => defined('SD_OFICINA_KEY') && SD_OFICINA_KEY, 'acorda_github' => defined('SD_GITHUB_TOKEN') && SD_GITHUB_TOKEN, 'drive' => (bool)pdDriveClientes()];
    $pulso = ofSup('/rest/v1/pd_batimento?chave=eq.oficina&select=ultima_em,detalhe')['body'][0] ?? null;
    echo json_encode(['admin' => $admin, 'modulos' => $mods, 'trabalhos' => $tr, 'cfg' => $cfg, 'pulso' => $pulso], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'pedir') {
    $slug = (string)($b['modulo'] ?? ''); $m = ofSup('/rest/v1/pd_modulos?slug=eq.' . urlencode($slug) . '&select=*')['body'][0] ?? null;
    if (!$m || !ofPodeVer($m, $u)) sd_fail('Módulo não disponível para você.', 403);
    if (!$m['ativo']) sd_fail('Este módulo está desligado.');
    if ($m['poder'] === 'ler') sd_fail('Este módulo só consulta; não gera trabalho.');
    if (empty($b['client_id'])) sd_fail('Todo trabalho é de um cliente: escolha o cliente.');
    $entrada = is_array($b['entrada'] ?? null) ? $b['entrada'] : []; $tit = mb_substr(trim((string)($b['titulo'] ?? '')), 0, 140) ?: $m['nome'];
    $precisaOk = $m['poder'] === 'propor' || ($m['poder'] === 'executar_ok' && empty($b['ok_agora']));
    $d = ['modulo' => $slug, 'titulo' => $tit, 'entrada' => $entrada, 'status' => $precisaOk ? 'aguardando_ok' : 'fila', 'prioridade' => max(1, min(9, (int)($b['prioridade'] ?? 5))),
        'client_id' => (string)$b['client_id'], 'task_id' => ($b['task_id'] ?? null) ?: null, 'pedido_por' => $u['id'], 'pedido_via' => in_array($b['via'] ?? '', ['ia', 'cron'], true) ? $b['via'] : 'painel'];
    if (!$precisaOk && $m['poder'] === 'executar_ok') { $d['ok_por'] = $u['id']; $d['ok_em'] = gmdate('c'); }
    $r = ofSup('/rest/v1/pd_trabalhos', 'POST', $d, ['Prefer: return=representation']); $t = $r['body'][0] ?? null; if (!$t) sd_fail('Não consegui criar o trabalho: ' . ($r['raw'] ?? ''), 500);
    ofLog($t['id'], $slug, 'pediu', $u['name'] . ' pediu' . ($precisaOk ? ' (aguardando OK)' : '') . '.');
    $acordou = $precisaOk ? null : ofAcorda();
    echo json_encode(['ok' => true, 'trabalho' => $t, 'acordou' => $acordou], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'ok') {
    $tid = (string)($b['id'] ?? ''); $r = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&status=eq.aguardando_ok', 'PATCH', ['status' => 'fila', 'ok_por' => $u['id'], 'ok_em' => gmdate('c')], ['Prefer: return=representation']);
    if (empty($r['body'][0])) sd_fail('Esse trabalho não está aguardando OK.'); ofLog($tid, $r['body'][0]['modulo'], 'ok', $u['name'] . ' deu OK.');
    echo json_encode(['ok' => true, 'acordou' => ofAcorda()]); exit;
}
if ($acao === 'cancelar') {
    $tid = (string)($b['id'] ?? ''); $r = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&status=in.(fila,aguardando_ok)', 'PATCH', ['status' => 'cancelado', 'fim_em' => gmdate('c')], ['Prefer: return=representation']);
    if (empty($r['body'][0])) sd_fail('Só dá para cancelar o que ainda não começou.'); ofLog($tid, $r['body'][0]['modulo'], 'cancelou', $u['name'] . ' cancelou.'); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'apagar') {   // tira da lista um trabalho terminado (erro, cancelado, pronto); os arquivos no Drive ficam
    $tid = (string)($b['id'] ?? ''); $r = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&status=in.(erro,cancelado,pronto)', 'DELETE', null, ['Prefer: return=representation']);
    if (empty($r['body'][0])) sd_fail('Só dá para apagar o que já terminou.'); echo json_encode(['ok' => true]); exit;
}
if ($acao === 'ver') {
    $tid = (string)($b['id'] ?? ''); $t = ofSup('/rest/v1/pd_trabalhos?id=eq.' . urlencode($tid) . '&select=*')['body'][0] ?? null; if (!$t) sd_fail('Trabalho não encontrado.', 404);
    $log = ofSup('/rest/v1/pd_oficina_log?trabalho_id=eq.' . urlencode($tid) . '&select=evento,detalhe,dados,em&order=em.asc&limit=200')['body'] ?? [];
    echo json_encode(['trabalho' => $t, 'log' => $log], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'modulo_ligar') {
    if (!$admin) sd_fail('Só admin.', 403); $slug = (string)($b['slug'] ?? ''); $d = ['mudado_em' => gmdate('c')];
    foreach (['ativo' => 'bool', 'quarentena' => 'bool', 'poder' => 'poder', 'visivel' => 'lista'] as $k => $t) if (array_key_exists($k, $b)) {
        if ($t === 'bool') $d[$k] = (bool)$b[$k]; elseif ($t === 'poder') { if (!in_array($b[$k], ['ler', 'propor', 'executar_ok', 'executar'], true)) sd_fail('Poder inválido.'); $d[$k] = $b[$k]; }
        else $d[$k] = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), (array)$b[$k]))); }
    $r = ofSup('/rest/v1/pd_modulos?slug=eq.' . urlencode($slug), 'PATCH', $d, ['Prefer: return=representation']); if (empty($r['body'][0])) sd_fail('Módulo não encontrado.', 404);
    echo json_encode(['ok' => true, 'modulo' => $r['body'][0]], JSON_UNESCAPED_UNICODE); exit;
}
if ($acao === 'modulo_salvar') {   // cria ou edita um módulo (nasce desligado e em quarentena)
    if (!$admin) sd_fail('Só admin.', 403);
    $slug = strtolower(preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)($b['slug'] ?? ''))))); $nome = mb_substr(trim((string)($b['nome'] ?? '')), 0, 80);
    if (strlen($slug) < 3 || !$nome) sd_fail('Dê um identificador (letras, números, _) e um nome.');
    $tipo = in_array($b['tipo'] ?? '', ['editor', 'pipeline', 'integracao', 'conhecimento', 'agente'], true) ? $b['tipo'] : 'pipeline';
    $onde = in_array($b['onde'] ?? '', ['oficina', 'servidor', 'navegador', 'externo'], true) ? $b['onde'] : 'oficina';
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : []; array_walk_recursive($cfg, function ($v, $k) { if (preg_match('/key|token|senha|secret|password/i', (string)$k)) sd_fail('Chaves e senhas não entram na config do módulo: vão no sd-config.php do servidor.'); });
    $d = ['slug' => $slug, 'nome' => $nome, 'tipo' => $tipo, 'onde' => $onde, 'descricao' => mb_substr((string)($b['descricao'] ?? ''), 0, 600), 'ficha' => mb_substr((string)($b['ficha'] ?? ''), 0, 20000), 'config' => $cfg, 'mudado_em' => gmdate('c')];
    $ex = ofSup('/rest/v1/pd_modulos?slug=eq.' . urlencode($slug) . '&select=slug')['body'][0] ?? null;
    if ($ex) $r = ofSup('/rest/v1/pd_modulos?slug=eq.' . urlencode($slug), 'PATCH', $d, ['Prefer: return=representation']);
    else { $d['criado_por'] = $u['id']; $d['ativo'] = false; $d['quarentena'] = true; $d['poder'] = 'propor'; $r = ofSup('/rest/v1/pd_modulos', 'POST', $d, ['Prefer: return=representation']); }
    if (empty($r['body'][0])) sd_fail('Não consegui salvar: ' . ($r['raw'] ?? ''), 500);
    echo json_encode(['ok' => true, 'modulo' => $r['body'][0], 'novo' => !$ex], JSON_UNESCAPED_UNICODE); exit;
}
sd_fail('Ação desconhecida.');
