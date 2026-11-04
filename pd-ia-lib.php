<?php
require_once __DIR__ . '/pd-vendas-lib.php';   // vdPode e o motor de vendas do Tumi (catálogo de ferramentas)
// IA da casa no chat da plataforma — as FERRAMENTAS que ela executa sozinha
// (criar, alterar, excluir, comentar, produto novo, memória) e o DESFAZER.
// Tudo passa pela chave do servidor; cada ação grava antes/depois em pd_ia_acoes
// (SQL v135). Se a tabela ainda não existir, executa mesmo assim — só não dá pra desfazer.

function iaStatusValidos() { return ['BRIEFING', 'EM ANDAMENTO', 'ATENÇÃO', 'ALTERAÇÃO', 'BUCHA', 'DELEGADA', 'ENTREGUE']; }

function iaEquipe() {   // chaves válidas do enum team_member + nome de cada pessoa
    static $eq = null; if ($eq !== null) return $eq;
    $eq = [];
    foreach ((array)(sd_supabase('/rest/v1/profiles?active=eq.true&client_id=is.null&role=in.(admin,team)&select=id,name,team_key&limit=80')['body'] ?? []) as $p) {
        $k = strtoupper(trim((string)($p['team_key'] ?? ''))); if ($k === '') continue;
        $eq[$k] = ['id' => $p['id'], 'nome' => (string)$p['name'], 'key' => $k];
    }
    return $eq;
}
function iaNorm($s) { $s = mb_strtolower(trim((string)$s)); $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); return preg_replace('/[^a-z0-9 ]/', '', $t !== false ? $t : $s); }
// nome ou chave → chave válida; null se não existe na equipe
function iaResp($txt) {
    $q = iaNorm($txt); if ($q === '') return null;
    $eq = iaEquipe();
    if (isset($eq[strtoupper($q)])) return strtoupper($q);
    foreach ($eq as $k => $p) { $n = iaNorm($p['nome']); if ($n === $q || strpos($n, $q) === 0 || strpos($q, explode(' ', $n)[0]) === 0) return $k; }
    return null;
}
// chave achada → garante que o enum aceita (v136), uma vez por execução
function iaRespOk($k) { static $ok = []; if ($k && !isset($ok[$k])) { $ok[$k] = true; if (function_exists('pdTeamMemberGarante')) pdTeamMemberGarante($k); } return $k; }
function iaClientes() {
    static $c = null; if ($c !== null) return $c;
    $c = (array)(sd_supabase('/rest/v1/clients?active=eq.true&select=id,name&limit=120')['body'] ?? []);
    return $c;
}
function iaCliente($txt) {
    $q = iaNorm($txt); if ($q === '') return null;
    foreach (iaClientes() as $c) if (iaNorm($c['name']) === $q) return $c;
    foreach (iaClientes() as $c) if (strpos(iaNorm($c['name']), $q) !== false || strpos($q, iaNorm($c['name'])) !== false) return $c;
    return null;
}
function iaProduto($clientId, $txt) {
    $q = iaNorm($txt); if ($q === '' || !$clientId) return null;
    $ps = (array)(sd_supabase('/rest/v1/products?client_id=eq.' . urlencode($clientId) . '&select=id,name&limit=80')['body'] ?? []);
    foreach ($ps as $p) if (iaNorm($p['name']) === $q) return $p;
    foreach ($ps as $p) if (strpos(iaNorm($p['name']), $q) !== false || strpos($q, iaNorm($p['name'])) !== false) return $p;
    return null;
}
function iaData($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null; }
function iaBR($d) { return $d ? date('d/m', strtotime($d)) : 'sem'; }

// ---------- registro (antes/depois) ----------
function iaRegistra($lote, $u, $tipo, $alvo, $antes, $depois, $resumo) {
    try { sd_supabase('/rest/v1/pd_ia_acoes', 'POST', ['lote' => $lote, 'user_id' => $u['id'] ?? null, 'tipo' => $tipo, 'alvo' => (string)$alvo,
        'antes' => $antes, 'depois' => $depois, 'resumo' => mb_substr((string)$resumo, 0, 300)]); } catch (Throwable $e) {}
}
function iaTemRegistro() { static $t = null; if ($t === null) { $r = sd_supabase('/rest/v1/pd_ia_acoes?select=id&limit=1'); $t = ($r['code'] ?? 500) < 300; } return $t; }

// ---------- FERRAMENTAS ----------
// cada uma devolve ['texto' => resultado pra IA e pra pessoa, 'ids' => tarefas tocadas]

function iaAlterar($args, $u, $lote) {
    $muds = array_slice((array)($args['mudancas'] ?? []), 0, 60); $out = []; $ids = [];
    $nome = $u['name'] ?? 'equipe';
    foreach ($muds as $m) {
        $m = (array)$m; $tid = strtoupper(trim((string)($m['id'] ?? ''))); if ($tid === '') { $out[] = '⚠️ item sem id'; continue; }
        $antes = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid) . '&select=*&limit=1')['body'][0] ?? null;
        if (!$antes) { $out[] = "⚠️ $tid não existe"; continue; }
        $patch = []; $avisos = [];
        if (!empty($m['status'])) { $st = strtoupper(trim((string)$m['status'])); if (in_array($st, iaStatusValidos(), true)) $patch['status'] = $st; else $avisos[] = "status \"$st\" não existe"; }
        if (!empty($m['resp'])) { $k = iaRespOk(iaResp($m['resp'])); if ($k) $patch['resp'] = $k; else { $avisos[] = "\"{$m['resp']}\" não está na equipe (anotei na obs)"; $m['obs_acrescenta'] = trim((string)($m['obs_acrescenta'] ?? '') . " · responsável sugerido: {$m['resp']}"); } }
        if (array_key_exists('projeto', $m)) { $pj = strtoupper(trim((string)$m['projeto'])); $patch['project'] = $pj !== '' ? mb_substr($pj, 0, 60) : 'GERAL'; }
        if (!empty($m['cliente'])) { $c = iaCliente($m['cliente']); if ($c) $patch['client_id'] = $c['id']; else $avisos[] = "cliente \"{$m['cliente']}\" não achei"; }
        if (array_key_exists('produto', $m)) { $pv = trim((string)$m['produto']); if ($pv === '' || preg_match('/^(nenhum|-|sem)$/i', $pv)) $patch['product_id'] = null;
            else { $cid = $patch['client_id'] ?? $antes['client_id']; $p = iaProduto($cid, $pv); if ($p) $patch['product_id'] = $p['id']; else $avisos[] = "produto \"$pv\" não existe nesse cliente (crie com novo_produto)"; } }
        foreach (['date_int', 'date_cli'] as $k) if (array_key_exists($k, $m)) { $v = trim((string)$m[$k]); if ($v === '' || preg_match('/^(nenhum|-|sem)$/i', $v)) $patch[$k] = null; elseif (iaData($v)) $patch[$k] = iaData($v); else $avisos[] = "$k inválida"; }
        if (isset($m['action']) && trim((string)$m['action']) !== '') $patch['action'] = mb_substr(trim((string)$m['action']), 0, 160);
        if (isset($m['obs']) && is_string($m['obs'])) $patch['obs'] = mb_substr(trim($m['obs']), 0, 2000);
        if (!empty($m['obs_acrescenta'])) $patch['obs'] = mb_substr(trim(trim((string)($patch['obs'] ?? $antes['obs'] ?? '')) . "\n" . trim((string)$m['obs_acrescenta'])), 0, 2000);
        if (array_key_exists('tarefa_mae', $m)) { $mae = strtoupper(trim((string)$m['tarefa_mae'])); $patch['tarefa_mae_id'] = ($mae === '' || preg_match('/^(nenhuma|-|sem)$/i', $mae)) ? null : $mae; }
        if (!$patch) { $out[] = "⚠️ $tid: nada válido pra mudar" . ($avisos ? ' (' . implode('; ', $avisos) . ')' : ''); continue; }
        $r = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid), 'PATCH', $patch, ['Prefer: return=representation']);
        if (($r['code'] ?? 500) >= 300) { $out[] = "⚠️ $tid: o banco recusou — " . mb_substr((string)($r['body']['message'] ?? $r['raw'] ?? ''), 0, 120); continue; }
        $depois = $r['body'][0] ?? null; if (!$depois) { $out[] = "⚠️ $tid: nenhuma linha gravada"; continue; }
        $conf = []; foreach ($patch as $k => $v) { $g = $depois[$k] ?? null; $ok = ($v === null) ? ($g === null || $g === '') : (substr((string)$g, 0, strlen((string)$v)) === (string)$v); if (!$ok) $conf[] = "$k ficou " . mb_substr((string)($g ?? 'vazio'), 0, 30); }
        $antesSlim = array_intersect_key($antes, $patch);
        iaRegistra($lote, $u, 'alterar', $tid, $antesSlim, $patch, implode(', ', array_keys($patch)));
        pdEvento($tid, 'edicao', 'Alterado pela IA a pedido de ' . $nome, json_encode($patch, JSON_UNESCAPED_UNICODE), $nome, 'pessoa');
        $partes = [];
        foreach ($patch as $k => $v) $partes[] = ['status' => 'status', 'resp' => 'resp', 'project' => 'projeto', 'client_id' => 'cliente', 'product_id' => 'produto', 'date_int' => 'prazo', 'date_cli' => 'prazo cliente', 'action' => 'título', 'obs' => 'obs', 'tarefa_mae_id' => 'mãe'][$k] . ' ' . (in_array($k, ['client_id', 'product_id']) ? ($v ? 'ok' : 'tirado') : (in_array($k, ['date_int', 'date_cli']) ? iaBR($v) : ($k === 'obs' ? 'atualizada' : ($v ?? 'tirada'))));
        $out[] = ($conf ? '⚠️ ' : '✅ ') . "$tid → " . implode(' · ', $partes) . ($conf ? ' (' . implode('; ', $conf) . ')' : '') . ($avisos ? ' — ' . implode('; ', $avisos) : '');
        $ids[] = $tid;
    }
    return ['texto' => implode("\n", $out), 'ids' => $ids];
}

function iaCriar($args, $u, $lote) {
    $novas = array_slice((array)($args['novas'] ?? []), 0, 40); $out = []; $ids = []; $pos2id = [];
    $nome = $u['name'] ?? 'equipe'; $minhaKey = strtoupper((string)($u['team_key'] ?? '')) ?: (array_keys(iaEquipe())[0] ?? 'SERGIO');
    foreach ($novas as $pos => $n) {
        $n = (array)$n; $acao = trim((string)($n['action'] ?? '')); if ($acao === '') { $out[] = '⚠️ item sem título'; continue; }
        $avisos = [];
        $nid = sd_supabase('/rest/v1/rpc/next_task_id', 'POST', (object)[]); $id = is_string($nid['body'] ?? null) ? trim($nid['body'], '"') : null;
        if (!$id) { $out[] = "⚠️ \"$acao\": não consegui gerar o número"; continue; }
        $c = !empty($n['cliente']) ? iaCliente($n['cliente']) : null; if (!empty($n['cliente']) && !$c) $avisos[] = "cliente \"{$n['cliente']}\" não achei";
        $st = strtoupper(trim((string)($n['status'] ?? 'BRIEFING'))); if (!in_array($st, iaStatusValidos(), true)) $st = 'BRIEFING';
        $resp = !empty($n['resp']) ? iaRespOk(iaResp($n['resp'])) : null; $obs = trim((string)($n['obs'] ?? ''));
        if (!empty($n['resp']) && !$resp) { $avisos[] = "\"{$n['resp']}\" não está na equipe"; $obs = trim($obs . "\nresponsável sugerido: {$n['resp']}"); }
        $nova = ['id' => $id, 'action' => mb_substr($acao, 0, 160), 'status' => $st, 'resp' => $resp ?: $minhaKey, 'project' => mb_substr(strtoupper(trim((string)($n['projeto'] ?? ''))) ?: 'GERAL', 0, 60), 'week' => 'ESTA SEMANA', 'created_by' => $u['id'] ?? null];
        if ($c) { $nova['client_id'] = $c['id']; if (!empty($n['produto'])) { $p = iaProduto($c['id'], $n['produto']); if ($p) $nova['product_id'] = $p['id']; else $avisos[] = "produto \"{$n['produto']}\" não existe (crie com novo_produto)"; } }
        foreach (['date_int', 'date_cli'] as $k) if (!empty($n[$k])) { if (iaData($n[$k])) $nova[$k] = iaData($n[$k]); else $avisos[] = "$k inválida"; }
        if ($obs !== '') $nova['obs'] = mb_substr($obs, 0, 2000);
        if (!empty($n['depois_de']) && isset($pos2id[(int)$n['depois_de']])) $nova['tarefa_mae_id'] = $pos2id[(int)$n['depois_de']];
        elseif (!empty($n['tarefa_mae'])) $nova['tarefa_mae_id'] = strtoupper(trim((string)$n['tarefa_mae']));
        $r = sd_supabase('/rest/v1/tasks', 'POST', $nova, ['Prefer: return=representation']);
        if (($r['code'] ?? 500) >= 300) { $out[] = "⚠️ \"" . mb_substr($acao, 0, 40) . "\": o banco recusou — " . mb_substr((string)($r['body']['message'] ?? $r['raw'] ?? ''), 0, 120); continue; }
        $pos2id[$pos + 1] = $id; $ids[] = $id;
        iaRegistra($lote, $u, 'criar', $id, null, $nova, mb_substr($acao, 0, 60));
        pdEvento($id, 'criacao', 'Criada pela IA a pedido de ' . $nome, null, $nome, 'pessoa');
        try { if ($resp && $resp !== $minhaKey) pdNotificarResp($resp, 'task_new', '📋 Nova tarefa atribuída', trim(($c['name'] ?? '') . ' — ' . mb_substr($acao, 0, 80)), 'task:' . $id, $u['id'] ?? null, $id); } catch (Throwable $e) {}
        $out[] = "✅ $id — " . mb_substr($acao, 0, 60) . ' [' . ($c['name'] ?? 'sem cliente') . ' · ' . $nova['project'] . ' · ' . $nova['resp'] . ' · prazo ' . iaBR($nova['date_int'] ?? null) . ']' . ($avisos ? ' — ' . implode('; ', $avisos) : '');
    }
    return ['texto' => implode("\n", $out), 'ids' => $ids];
}

function iaExcluir($args, $u, $lote) {
    $ids = array_slice(array_map(fn($x) => strtoupper(trim((string)$x)), (array)($args['ids'] ?? [])), 0, 60); $out = []; $ok = [];
    foreach ($ids as $tid) {
        if ($tid === '') continue;
        $snap = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid) . '&select=*&limit=1')['body'][0] ?? null;
        if (!$snap) { $out[] = "⚠️ $tid não existe"; continue; }
        $coms = (array)(sd_supabase('/rest/v1/comments?task_id=eq.' . urlencode($tid) . '&select=*')['body'] ?? []);
        iaRegistra($lote, $u, 'excluir', $tid, ['tarefa' => $snap, 'comentarios' => $coms], null, mb_substr((string)$snap['action'], 0, 60));
        sd_supabase('/rest/v1/comments?task_id=eq.' . urlencode($tid), 'DELETE');
        $r = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid), 'DELETE');
        $ainda = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid) . '&select=id&limit=1')['body'][0] ?? null;
        if ($ainda) { $out[] = "⚠️ $tid: o banco não deixou excluir (" . mb_substr((string)($r['body']['message'] ?? ''), 0, 100) . ')'; continue; }
        $out[] = "🗑️ $tid — " . mb_substr((string)$snap['action'], 0, 50) . ' excluída'; $ok[] = $tid;
    }
    return ['texto' => implode("\n", $out) ?: '⚠️ nenhum id', 'ids' => $ok];
}

function iaComentar($args, $u, $lote) {
    $tid = strtoupper(trim((string)($args['id'] ?? ''))); $txt = trim((string)($args['texto'] ?? ''));
    if ($tid === '' || $txt === '') return ['texto' => '⚠️ faltou id ou texto', 'ids' => []];
    $t = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid) . '&select=id&limit=1')['body'][0] ?? null; if (!$t) return ['texto' => "⚠️ $tid não existe", 'ids' => []];
    $r = sd_supabase('/rest/v1/comments', 'POST', ['task_id' => $tid, 'user_id' => $u['id'] ?? null, 'body' => mb_substr($txt, 0, 2000)], ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) return ['texto' => "⚠️ $tid: não consegui comentar", 'ids' => []];
    iaRegistra($lote, $u, 'comentar', (string)($r['body'][0]['id'] ?? ''), null, ['task_id' => $tid], mb_substr($txt, 0, 60));
    return ['texto' => "💬 comentário na $tid", 'ids' => [$tid]];
}

function iaNovoProduto($args, $u, $lote) {
    $c = iaCliente($args['cliente'] ?? ''); $nm = trim((string)($args['nome'] ?? ''));
    if (!$c) return ['texto' => "⚠️ cliente \"" . ($args['cliente'] ?? '') . "\" não achei", 'ids' => []];
    if ($nm === '') return ['texto' => '⚠️ faltou o nome do produto', 'ids' => []];
    if ($ja = iaProduto($c['id'], $nm)) return ['texto' => "ℹ️ \"{$ja['name']}\" já existe em {$c['name']}", 'ids' => []];
    $r = sd_supabase('/rest/v1/products', 'POST', ['client_id' => $c['id'], 'name' => mb_substr($nm, 0, 120)], ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) return ['texto' => '⚠️ o banco recusou o produto', 'ids' => []];
    iaRegistra($lote, $u, 'produto', (string)($r['body'][0]['id'] ?? ''), null, ['client_id' => $c['id'], 'name' => $nm], $nm);
    return ['texto' => "✅ produto \"$nm\" criado em {$c['name']}", 'ids' => []];
}

function iaLembrar($args, $u, $lote) {
    $ms = array_slice((array)($args['memorias'] ?? []), 0, 10); $out = [];
    foreach ($ms as $m) {
        $m = (array)$m; $esc = in_array($m['escopo'] ?? '', ['agencia', 'cliente', 'pessoa', 'tarefa'], true) ? $m['escopo'] : 'agencia'; $eid = null;
        if (!empty($GLOBALS['PD_IA_CLIENTE'])) { $esc = 'cliente'; $eid = $GLOBALS['PD_IA_CLIENTE']; }   // cliente só ensina sobre a própria empresa
        elseif ($esc === 'cliente') { $c = iaCliente($m['alvo'] ?? ''); if (!$c) { $out[] = "⚠️ memória: cliente \"" . ($m['alvo'] ?? '') . "\" não achei"; continue; } $eid = $c['id']; }
        elseif ($esc === 'pessoa') $eid = $u['id'] ?? null; elseif ($esc === 'tarefa') $eid = strtoupper(trim((string)($m['alvo'] ?? '')));
        $d = ['escopo' => $esc, 'escopo_id' => $eid, 'assunto' => mb_substr((string)($m['assunto'] ?? 'nota'), 0, 120), 'conteudo' => mb_substr((string)($m['conteudo'] ?? ''), 0, 2000), 'natureza' => $m['natureza'] ?? 'orientacao', 'autor_id' => $u['id'] ?? null, 'origem' => 'chat', 'ia' => $esc === 'pessoa' ? (pdIaSlug($u['id'] ?? '') ?: null) : null];
        $r = sd_supabase('/rest/v1/sd_memoria', 'POST', $d, ['Prefer: return=representation']);
        if (($r['code'] ?? 500) >= 300 && array_key_exists('ia', $d)) { unset($d['ia']); $r = sd_supabase('/rest/v1/sd_memoria', 'POST', $d, ['Prefer: return=representation']); }   // SQL v146 ainda não rodou
        if (($r['code'] ?? 500) >= 300) { $out[] = '⚠️ não guardei "' . $d['assunto'] . '"'; continue; }
        iaRegistra($lote, $u, 'lembrar', (string)($r['body'][0]['id'] ?? ''), null, $d, $d['assunto']);
        if ($esc === 'pessoa') pdMemGeralDerivar($u['id'] ?? '', $d['assunto'], $d['conteudo']);
        $out[] = '🧠 guardado: ' . $d['assunto'];
    }
    return ['texto' => implode("\n", $out) ?: '⚠️ nada pra guardar', 'ids' => []];
}

function iaVerTarefa($args) {
    $tid = strtoupper(trim((string)($args['id'] ?? '')));
    $t = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($tid) . '&select=*,clients(name),products(name)&limit=1')['body'][0] ?? null;
    if (!$t) return ['texto' => "não existe $tid", 'ids' => []];
    $coms = (array)(sd_supabase('/rest/v1/comments?task_id=eq.' . urlencode($tid) . '&select=body,created_at,profiles(name)&order=created_at.desc&limit=8')['body'] ?? []);
    $filhas = (array)(sd_supabase('/rest/v1/tasks?tarefa_mae_id=eq.' . urlencode($tid) . '&select=id,action,status')['body'] ?? []);
    $t['comentarios'] = array_map(fn($c) => ['quem' => $c['profiles']['name'] ?? '', 'quando' => substr((string)$c['created_at'], 0, 16), 'texto' => mb_substr((string)$c['body'], 0, 300)], $coms);
    $t['filhas'] = $filhas;
    return ['texto' => json_encode($t, JSON_UNESCAPED_UNICODE), 'ids' => []];
}

function iaExecutar($nome, $args, $u, $lote, $podeEscrever) {
    if ($nome === 'ver_tarefa') return iaVerTarefa($args);
    if (!$podeEscrever) return ['texto' => '⚠️ este usuário só consulta; não executa', 'ids' => []];
    switch ($nome) {
        case 'alterar_tarefas': return iaAlterar($args, $u, $lote);
        case 'criar_tarefas':   return iaCriar($args, $u, $lote);
        case 'excluir_tarefas': return iaExcluir($args, $u, $lote);
        case 'pedir_trabalho':  return iaPedirTrabalho($args, $u, $lote);
        case 'comentar':        if (!empty($GLOBALS['PD_IA_CLIENTE']) && !iaClienteTarefaDele($args['id'] ?? '')) return ['texto' => '⚠️ essa tarefa não é da sua empresa', 'ids' => []]; return iaComentar($args, $u, $lote);
        case 'novo_produto':    return iaNovoProduto($args, $u, $lote);
        case 'lembrar':         return iaLembrar($args, $u, $lote);
    }
    return ['texto' => "⚠️ ferramenta desconhecida $nome", 'ids' => []];
}

// ---------- DESFAZER um lote inteiro (ordem inversa) ----------
function iaDesfazer($lote, $u) {
    $acs = (array)(sd_supabase('/rest/v1/pd_ia_acoes?lote=eq.' . urlencode($lote) . '&desfeita_em=is.null&select=*&order=id.desc')['body'] ?? []);
    if (!$acs) return ['ok' => false, 'texto' => 'Esse lote já foi desfeito (ou o registro não existe).'];
    $out = [];
    foreach ($acs as $a) {
        $ok = false; $tipo = $a['tipo']; $alvo = (string)$a['alvo'];
        try {
            if ($tipo === 'alterar') { $r = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($alvo), 'PATCH', (array)$a['antes']); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . "$alvo voltou como estava"; }
            elseif ($tipo === 'criar') { sd_supabase('/rest/v1/comments?task_id=eq.' . urlencode($alvo), 'DELETE'); $r = sd_supabase('/rest/v1/tasks?id=eq.' . urlencode($alvo), 'DELETE'); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . "$alvo apagada (tinha sido criada)"; }
            elseif ($tipo === 'excluir') { $t = (array)($a['antes']['tarefa'] ?? []); unset($t['created_at'], $t['updated_at']); $r = sd_supabase('/rest/v1/tasks', 'POST', $t); $ok = ($r['code'] ?? 500) < 300;
                if ($ok) foreach ((array)($a['antes']['comentarios'] ?? []) as $c) { unset($c['id']); sd_supabase('/rest/v1/comments', 'POST', $c); }
                $out[] = ($ok ? '↩️ ' : '⚠️ ') . "$alvo recriada" . ($ok ? '' : ' (o banco recusou: ' . mb_substr((string)($r['body']['message'] ?? ''), 0, 80) . ')'); }
            elseif ($tipo === 'comentar') { $r = sd_supabase('/rest/v1/comments?id=eq.' . urlencode($alvo), 'DELETE'); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'comentário removido'; }
            elseif ($tipo === 'produto') { $r = sd_supabase('/rest/v1/products?id=eq.' . urlencode($alvo), 'DELETE'); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'produto removido'; }
            elseif ($tipo === 'acesso') { $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($alvo), 'PATCH', (array)$a['antes']); $at = !empty($a['antes']['active']); sd_supabase('/auth/v1/admin/users/' . urlencode($alvo), 'PUT', ['ban_duration' => $at ? 'none' : '876000h']); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'acesso voltou como estava'; }
            elseif ($tipo === 'funcoes') { $an = (array)$a['antes']; $pt = ['funcoes' => '{' . implode(',', (array)($an['funcoes'] ?? [])) . '}']; if (array_key_exists('team_key', $an)) $pt['team_key'] = $an['team_key']; $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($alvo), 'PATCH', $pt); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'funções voltaram como estavam'; }
            elseif ($tipo === 'ferramentas') { $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($alvo), 'PATCH', ['studio_areas' => '{' . implode(',', (array)($a['antes']['studio_areas'] ?? [])) . '}']); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'ferramentas voltaram como estavam'; }
            elseif ($tipo === 'cliente') { $r = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($alvo), 'PATCH', ['active' => false]); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'cliente pausado (criado neste lote)'; }
            elseif ($tipo === 'cliente_editar') { $r = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($alvo), 'PATCH', (array)$a['antes']); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'ficha do cliente voltou como estava'; }
            elseif ($tipo === 'usuario') { $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($alvo), 'PATCH', ['active' => false]); sd_supabase('/auth/v1/admin/users/' . urlencode($alvo), 'PUT', ['ban_duration' => '876000h']); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'acesso da pessoa cadastrada pausado (o convite deixa de valer)'; }
            elseif ($tipo === 'permissoes') { $out[] = 'ℹ️ permissões: ajuste na tela de permissões (não desfaço sozinha)'; $ok = true; }
            elseif ($tipo === 'lembrar') { $r = sd_supabase('/rest/v1/sd_memoria?id=eq.' . urlencode($alvo), 'PATCH', ['ativo' => false, 'revogado_em' => date('c')]); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'memória esquecida'; }
        } catch (Throwable $e) { $out[] = "⚠️ $alvo: " . mb_substr($e->getMessage(), 0, 80); }
        if ($ok) sd_supabase('/rest/v1/pd_ia_acoes?id=eq.' . (int)$a['id'], 'PATCH', ['desfeita_em' => date('c')]);
    }
    return ['ok' => true, 'texto' => implode("\n", $out)];
}

// =====================================================================================
// CATÁLOGO ÚNICO DE FERRAMENTAS DA CASA — "dois volantes": tudo que tem botão tem ação aqui.
// O que decide o que a IA pode acionar é QUEM pede (papel + permissões), nunca o canal.
// =====================================================================================
function iaEhAdmin($u) { return (($u['role'] ?? '') === 'admin'); }
function iaPermissoes($u) {   // permissões da pessoa (my_perms) — com o JWT dela quando houver; admin tem tudo
    static $cache = null; if ($cache !== null) return $cache;
    if (iaEhAdmin($u)) return $cache = ['*'];
    $tok = $GLOBALS['PD_IA_TOKEN'] ?? '';
    if ($tok) { $r = sd_supabase('/rest/v1/rpc/my_perms', 'POST', (object)[], ['Authorization: Bearer ' . $tok]); if (($r['code'] ?? 500) < 300 && is_array($r['body'])) return $cache = array_values(array_map('strval', $r['body'])); }
    return $cache = [];
}
function iaPode($u, $chave) { $p = iaPermissoes($u); return in_array('*', $p, true) || in_array($chave, $p, true); }

// Acha UMA pessoa pelo nome/e-mail. Nunca chuta: primeiro nome igual não basta quando há mais de um "Sérgio".
// Devolve o perfil, ou null; $GLOBALS['IA_PESSOA_AMBIGUA'] traz os nomes quando deu empate (pra responder "qual deles?").
function iaPessoaPorNome($txt, $soAtivos = false) {
    $GLOBALS['IA_PESSOA_AMBIGUA'] = [];
    $q = iaNorm($txt); if ($q === '') return null;
    $ps = (array)(sd_supabase('/rest/v1/profiles?select=id,name,email,role,team_key,active,client_id,funcoes,studio_areas,zap&limit=300')['body'] ?? []);
    foreach ($ps as $p) if (iaNorm($p['name']) === $q || iaNorm($p['email'] ?? '') === $q || explode('@', iaNorm($p['email'] ?? ''))[0] === $q) return $p;
    $pal = array_values(array_filter(explode(' ', $q))); $cands = [];
    foreach ($ps as $p) { $n = ' ' . iaNorm($p['name']) . ' '; $ok = true; foreach ($pal as $w) if (strpos($n, ' ' . $w) === false) { $ok = false; break; } if ($ok) $cands[] = $p; }
    if (count($cands) === 1) return $cands[0];
    if (count($cands) > 1) { $GLOBALS['IA_PESSOA_AMBIGUA'] = array_map(fn($p) => trim($p['name']) . ' (' . ($p['role'] === 'admin' ? 'admin' : ($p['role'] === 'client' ? 'cliente' : 'equipe')) . ')', $cands); return null; }
    return null;
}
function iaPessoaErro($txt) { $a = $GLOBALS['IA_PESSOA_AMBIGUA'] ?? []; return $a ? '⚠️ "' . $txt . '" bate com mais de uma pessoa: ' . implode(' · ', $a) . '. Qual delas?' : '⚠️ não achei "' . $txt . '"'; }

// ---- pessoas ----
function iaUsuariosCriar($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin cadastra pessoas', 'ids' => []];
    require_once __DIR__ . '/pd-usuarios-lib.php';
    $out = [];
    foreach (array_slice((array)($args['pessoas'] ?? []), 0, 20) as $p) {
        $p = (array)$p; $nome = trim((string)($p['nome'] ?? '')); $email = trim((string)($p['email'] ?? ''));
        $papel = in_array($p['papel'] ?? '', ['team', 'admin', 'client'], true) ? $p['papel'] : 'team';
        $cid = null; if ($papel === 'client') { $c = iaCliente($p['cliente'] ?? ''); if (!$c) { $out[] = "⚠️ $nome: cliente \"" . ($p['cliente'] ?? '') . "\" não achei"; continue; } $cid = $c['id']; }
        $fun = array_values(array_filter(array_map(fn($x) => iaFuncaoChave($x), (array)($p['funcoes'] ?? []))));
        try {
            $r = pdUsuarioCriar(['name' => $nome, 'email' => $email, 'role' => $papel, 'client_id' => $cid, 'funcoes' => $fun, 'perfil_permissoes' => (string)($p['perfil_permissoes'] ?? '')], $u);
            $uid = $r['user_id']; $extra = [];
            if (!empty($p['zap'])) { $z = preg_replace('/[^\d+]/', '', (string)$p['zap']); sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid), 'PATCH', ['zap' => $z, 'zap_ativo' => true]); $extra[] = 'zap ' . $z; }
            if (!empty($r['permissoes'])) $extra[] = $r['permissoes'];
            if (!empty($p['ferramentas']) && $papel !== 'client') { $fr = iaFerramentasAplicar($uid, (array)$p['ferramentas']); $extra[] = $fr; }
            iaRegistra($lote, $u, 'usuario', $uid, null, ['name' => $nome, 'email' => $email, 'role' => $papel], $nome);
            $out[] = "✅ $nome ($email) cadastrad" . ($papel === 'client' ? 'o como cliente' : 'o na equipe') . ($fun ? ' · funções: ' . implode(', ', $fun) : '') . ($r['team_key'] && $r['team_key'] !== 'OUTROS' ? ' · chave ' . $r['team_key'] : '') . "\n   " . (!empty($r['email_enviado']) ? '📧 convite enviado por e-mail' : '⚠️ o e-mail NÃO saiu do servidor') . " · link de acesso (vale 24h, pode mandar pela pessoa por zap):\n   " . $r['link'] . ($extra ? "\n   " . implode(' · ', $extra) : '') . ' · convite enviado por e-mail';
        } catch (Throwable $e) { $out[] = "⚠️ $nome: " . mb_substr($e->getMessage(), 0, 160); }
    }
    return ['texto' => implode("\n", $out) ?: '⚠️ nenhuma pessoa', 'ids' => []];
}
function iaFuncaoChave($txt) {
    $cat = pdFuncoesCatalogo(); $q = iaNorm($txt); if ($q === '') return null;
    if (isset($cat[$q])) return $q;
    foreach ($cat as $k => $lab) { $l = iaNorm($lab); if ($l === $q || strpos($l, $q) !== false || strpos($q, $k) !== false) return $k; }
    $ap = ['design' => 'designer', 'arte' => 'designer', 'redator' => 'copy', 'texto' => 'copy', 'copys' => 'copy', 'midia' => 'trafego', 'ads' => 'trafego', 'video' => 'video', 'motion' => 'video', 'edicao' => 'video', 'locucao' => 'som', 'audio' => 'som', 'site' => 'webdev', 'dev' => 'webdev', 'gestao' => 'producao', 'projetos' => 'producao', 'conta' => 'atendimento', 'cs' => 'atendimento', 'social media' => 'social', 'redes' => 'social', 'planejamento' => 'estrategia', 'criacao' => 'direcao', 'vendas' => 'comercial', 'vendedor' => 'comercial', 'sdr' => 'prospeccao', 'prospec' => 'prospeccao', 'bdr' => 'prospeccao', 'trafego' => 'trafego', 'performance' => 'trafego', '3d' => 'foto3d', 'foto' => 'foto3d', 'render' => 'foto3d', 'estrategia' => 'estrategia'];
    foreach ($ap as $a => $k) if (strpos($q, $a) !== false) return $k;
    return null;
}
// atalho determinístico (roda antes da IA): "me dá o link do fulano@x.com", "reenvia o convite da Luana", "link de acesso do Luis"
function iaAtalhoConvite($u, $txt) {
    $t = trim((string)$txt);
    if (!preg_match('/\b(link|convite)\b/iu', $t) || !preg_match('/\b(link (de|do) (acesso|convite|cadastro)|reenvia\w*|manda\w* o link|me d[áa] o link|gera\w* (o |um )?link|novo link|link do|link da|link pro|link pra)\b/iu', $t)) return null;
    if (!iaEhAdmin($u)) return null;
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $t, $em)) $quem = $em[0];
    else { $quem = trim(preg_replace('/^.*?\b(?:link|convite)\b(?: de acesso| do convite| de cadastro)?(?: d[oa]| pr[oa]| para [oa]?| d[oa]s)?\s*/iu', '', $t)); $quem = trim(preg_replace('/[?.!]+$/u', '', $quem)); }
    if ($quem === '' || mb_strlen($quem) > 80) return null;
    $lote = sprintf('%08x-%04x-4%03x-%04x-%012x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0x8000, 0xbfff), mt_rand(0, 0xffffffffffff));
    $r = iaConviteReenviar(['pessoa' => $quem], $u, $lote);
    return $r['texto'] ?? null;
}
function iaConviteReenviar($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin reenvia convite', 'ids' => []];
    $p = iaPessoaPorNome($args['pessoa'] ?? ''); if (!$p) return ['texto' => iaPessoaErro($args['pessoa'] ?? ''), 'ids' => []];
    $pf = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']) . '&select=id,name,email,role,active')['body'][0] ?? null;
    if (!$pf || empty($pf['email'])) return ['texto' => '⚠️ ' . $p['name'] . ' não tem e-mail no cadastro', 'ids' => []];
    require_once __DIR__ . '/pd-usuarios-lib.php';
    $r = pdConviteLink($pf['email'], $pf['name']);
    if (empty($r['link'])) return ['texto' => '⚠️ não consegui gerar o link: ' . ($r['erro'] ?? '?'), 'ids' => []];
    iaRegistra($lote, $u, 'convite', $pf['id'], null, ['email' => $pf['email']], $pf['name']);
    return ['texto' => '🔗 ' . $pf['name'] . ' (' . $pf['email'] . ') — ' . (!empty($r['email_enviado']) ? 'convite reenviado por e-mail' : 'o e-mail NÃO saiu do servidor') . ". Link de acesso (vale 24h, pode mandar por zap):\n   " . $r['link'], 'ids' => []];
}
function iaUsuarioAcesso($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin liga/desliga acesso', 'ids' => []];
    $ativo = !empty($args['ativo']);
    if ($ativo) { $p = iaPessoaPorNome($args['pessoa'] ?? ''); if (!$p) return ['texto' => iaPessoaErro($args['pessoa'] ?? ''), 'ids' => []]; return iaUsuarioAcessoExec($p, true, $u, $lote); }
    $cands = iaContasLista((string)($args['pessoa'] ?? '')); $cands = array_values(array_filter($cands, fn($p) => $p['id'] !== ($u['id'] ?? '')));
    if (!$cands) return ['texto' => '⚠️ não achei nenhuma conta com "' . ($args['pessoa'] ?? '') . '"', 'ids' => []];
    return ['texto' => iaConfirmaPedir($u, $GLOBALS['PD_IA_CANAL'] ?? 'chat', $GLOBALS['PD_IA_CHAVE'] ?? ($u['id'] ?? ''), 'pausar', $cands, 'Pausar acesso'), 'ids' => [], 'confirmacao' => true];
}
function iaUsuarioExcluir($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin exclui pessoas', 'ids' => []];
    $cands = iaContasLista((string)($args['pessoa'] ?? '')); $cands = array_values(array_filter($cands, fn($p) => $p['id'] !== ($u['id'] ?? '')));
    if (!$cands) return ['texto' => '⚠️ não achei nenhuma conta com "' . ($args['pessoa'] ?? '') . '"', 'ids' => []];
    return ['texto' => iaConfirmaPedir($u, $GLOBALS['PD_IA_CANAL'] ?? 'chat', $GLOBALS['PD_IA_CHAVE'] ?? ($u['id'] ?? ''), 'excluir', $cands, 'Excluir conta da plataforma' . (!empty($args['tarefas_para']) ? ' (tarefas abertas → ' . $args['tarefas_para'] . ')' : '')), 'ids' => [], 'confirmacao' => true];
}
function iaFuncoesDefinir($args, $u, $lote) {
    if (!iaEhAdmin($u) && !in_array('gestao_equipe', (array)(sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($u['id']) . '&select=funcoes')['body'][0]['funcoes'] ?? []), true)) return ['texto' => '⚠️ só admin ou gestão de equipe define funções', 'ids' => []];
    $p = iaPessoaPorNome($args['pessoa'] ?? ''); if (!$p) return ['texto' => iaPessoaErro($args['pessoa'] ?? ''), 'ids' => []];
    $f = array_values(array_unique(array_filter(array_map('iaFuncaoChave', (array)($args['funcoes'] ?? [])))));
    if (!$f) return ['texto' => '⚠️ nenhuma função válida (' . implode(', ', array_keys(pdFuncoesCatalogo())) . ')', 'ids' => []];
    $upd = ['funcoes' => '{' . implode(',', $f) . '}'];
    if (empty($p['team_key']) || $p['team_key'] === 'OUTROS') { $upd['team_key'] = pdTeamKeyNova($p['name'], $p['id']); pdTeamMemberGarante($upd['team_key']); } else pdTeamMemberGarante($p['team_key']);
    $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']), 'PATCH', $upd);
    if (($r['code'] ?? 500) >= 300) return ['texto' => '⚠️ o banco recusou (rode o SQL v132)', 'ids' => []];
    iaRegistra($lote, $u, 'funcoes', $p['id'], ['funcoes' => $p['funcoes'] ?? [], 'team_key' => $p['team_key'] ?? null], $upd, $p['name']);
    return ['texto' => '✅ ' . $p['name'] . ' → ' . implode(', ', $f) . (isset($upd['team_key']) ? ' · chave ' . $upd['team_key'] : ''), 'ids' => []];
}
function iaPermissoesAplicar($uid, $papel, $perfil, $chaves) {
    $lista = is_array($chaves) ? $chaves : [];
    if ($perfil !== '') { $pre = (array)(sd_supabase('/rest/v1/permission_presets?select=*')['body'] ?? []); $q = iaNorm($perfil); $ach = null;
        foreach ($pre as $x) if (($x['papel'] ?? '') === $papel && (iaNorm($x['nome'] ?? '') === $q || strpos(iaNorm($x['nome'] ?? ''), $q) !== false)) { $ach = $x; break; }
        if (!$ach) return '⚠️ perfil de permissões "' . $perfil . '" não existe (' . implode(', ', array_map(fn($x) => $x['nome'] ?? '', array_filter($pre, fn($x) => ($x['papel'] ?? '') === $papel))) . ')';
        $lista = array_merge($lista, (array)($ach['chaves'] ?? $ach['perms'] ?? $ach['permissoes'] ?? [])); }
    [$okP, $msgP] = pdPermissoesServidor($uid, array_values(array_unique($lista)), null);
    if (!$okP) return '⚠️ permissões não gravadas: ' . $msgP;
    return '✅ permissões: ' . ($perfil !== '' ? 'perfil ' . $perfil : count($lista) . ' chaves');
}
function iaPermissoesDefinir($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin mexe em permissões', 'ids' => []];
    $p = iaPessoaPorNome($args['pessoa'] ?? ''); if (!$p) return ['texto' => iaPessoaErro($args['pessoa'] ?? ''), 'ids' => []];
    $t = iaPermissoesAplicar($p['id'], $p['role'] ?? 'team', (string)($args['perfil'] ?? ''), (array)($args['chaves'] ?? []));
    iaRegistra($lote, $u, 'permissoes', $p['id'], null, $args, $p['name']);
    return ['texto' => $p['name'] . ': ' . $t, 'ids' => []];
}
function iaFerramentasAplicar($uid, $areas) {
    $map = ['digital' => 'digital', 'arte' => 'digital', 'arte digital' => 'digital', 'audio' => 'audio', 'locucao' => 'audio', 'som' => 'audio', 'video' => 'video', 'videos' => 'video', 'grafica' => 'grafica', 'edicao grafica' => 'grafica', 'impresso' => 'grafica', 'zap' => 'zap', 'whatsapp' => 'zap', 'comunicador' => 'zap', 'atendimento' => 'zap', 'vendas' => 'vendas', 'comercial' => 'vendas', 'prospeccao' => 'vendas', 'todas' => 'todas', 'tudo' => 'todas', 'nenhuma' => 'nenhuma'];
    $sel = []; foreach ($areas as $a) { $k = $map[iaNorm($a)] ?? null; if ($k === 'todas') $sel = ['digital', 'audio', 'video', 'grafica', 'zap', 'vendas']; elseif ($k === 'nenhuma') $sel = []; elseif ($k) $sel[] = $k; }
    $sel = array_values(array_unique($sel));
    $r = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($uid), 'PATCH', ['studio_areas' => '{' . implode(',', $sel) . '}']);
    if (($r['code'] ?? 500) >= 300) return '⚠️ ferramentas não gravadas (rode o SQL v133)';
    return '✅ ferramentas: ' . ($sel ? implode(', ', $sel) : 'só Copys');
}
function iaFerramentasLiberar($args, $u, $lote) {
    if (!iaEhAdmin($u)) return ['texto' => '⚠️ só admin libera ferramentas', 'ids' => []];
    $p = iaPessoaPorNome($args['pessoa'] ?? ''); if (!$p) return ['texto' => iaPessoaErro($args['pessoa'] ?? ''), 'ids' => []];
    $t = iaFerramentasAplicar($p['id'], (array)($args['ferramentas'] ?? []));
    iaRegistra($lote, $u, 'ferramentas', $p['id'], ['studio_areas' => $p['studio_areas'] ?? []], $args, $p['name']);
    return ['texto' => $p['name'] . ': ' . $t, 'ids' => []];
}

// ---- clientes ----
function iaClienteCriar($args, $u, $lote) {
    if (!iaEhAdmin($u) && !iaPode($u, 'cliente.criar')) return ['texto' => '⚠️ você não tem permissão pra criar cliente', 'ids' => []];
    $nome = trim((string)($args['nome'] ?? '')); if ($nome === '') return ['texto' => '⚠️ faltou o nome', 'ids' => []];
    if ($ja = iaCliente($nome)) if (iaNorm($ja['name']) === iaNorm($nome)) return ['texto' => 'ℹ️ ' . $ja['name'] . ' já existe', 'ids' => []];
    $slug = preg_replace('/^-|-$/', '', preg_replace('/[^a-z0-9]+/', '-', strtolower(iaNorm($nome)))) . '-' . time();
    $d = ['name' => $nome, 'slug' => $slug, 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($args['cor'] ?? '')) ? $args['cor'] : '#2563eb', 'website' => trim((string)($args['site'] ?? '')) ?: null, 'notes' => trim((string)($args['observacoes'] ?? '')) ?: null];
    $r = sd_supabase('/rest/v1/clients', 'POST', $d, ['Prefer: return=representation']);
    if (($r['code'] ?? 500) >= 300) return ['texto' => '⚠️ o banco recusou: ' . mb_substr((string)($r['body']['message'] ?? ''), 0, 100), 'ids' => []];
    $cid = $r['body'][0]['id']; $pp = [];
    foreach (array_slice((array)($args['produtos'] ?? []), 0, 20) as $pn) { $pn = trim((string)$pn); if ($pn === '') continue; sd_supabase('/rest/v1/products', 'POST', ['client_id' => $cid, 'name' => mb_substr($pn, 0, 120)]); $pp[] = $pn; }
    $extra = iaClienteZapAplica($cid, $args);
    iaRegistra($lote, $u, 'cliente', $cid, null, $d, $nome);
    return ['texto' => "✅ cliente $nome criado" . ($pp ? ' · produtos: ' . implode(', ', $pp) : '') . ($extra ? ' · ' . $extra : ''), 'ids' => []];
}
function iaClienteZapAplica($cid, $args) {
    $upd = []; $t = [];
    if (array_key_exists('zap', $args) && $args['zap'] !== null) { $z = preg_replace('/[^\d+]/', '', (string)$args['zap']) ?: null; $upd['zap'] = $z; $upd['zap_ativo'] = (bool)$z; $t[] = $z ? 'zap ' . $z : 'zap removido'; }
    if (array_key_exists('email_aviso', $args) && $args['email_aviso'] !== null) { $upd['email_aviso'] = trim((string)$args['email_aviso']) ?: null; $t[] = 'e-mail de aviso'; }
    if (array_key_exists('gigi', $args) && $args['gigi'] !== null) { $upd['ia_zap'] = (bool)$args['gigi']; $t[] = 'Gigi ' . ($upd['ia_zap'] ? 'ligada' : 'desligada'); }
    if (!$upd) return '';
    $r = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($cid), 'PATCH', $upd);
    return ($r['code'] ?? 500) < 300 ? implode(' · ', $t) : '⚠️ zap/Gigi não gravados (rode o SQL v131)';
}
function iaClienteEditar($args, $u, $lote) {
    if (!iaEhAdmin($u) && !iaPode($u, 'cliente.editar')) return ['texto' => '⚠️ você não tem permissão pra editar cliente', 'ids' => []];
    $c = iaCliente($args['cliente'] ?? ''); if (!$c) return ['texto' => '⚠️ cliente "' . ($args['cliente'] ?? '') . '" não achei', 'ids' => []];
    $antes = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($c['id']) . '&select=*&limit=1')['body'][0] ?? [];
    $upd = []; $t = [];
    if (!empty($args['nome'])) { $upd['name'] = mb_substr(trim((string)$args['nome']), 0, 120); $t[] = 'nome'; }
    if (!empty($args['cor']) && preg_match('/^#[0-9a-f]{6}$/i', $args['cor'])) { $upd['color'] = $args['cor']; $t[] = 'cor'; }
    if (array_key_exists('site', $args)) { $upd['website'] = trim((string)$args['site']) ?: null; $t[] = 'site'; }
    if (array_key_exists('observacoes', $args)) { $upd['notes'] = trim((string)$args['observacoes']) ?: null; $t[] = 'observações'; }
    if (array_key_exists('ativo', $args)) { $upd['active'] = (bool)$args['ativo']; $t[] = $upd['active'] ? 'reativado' : 'pausado'; }
    if ($upd) { $r = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($c['id']), 'PATCH', $upd); if (($r['code'] ?? 500) >= 300) return ['texto' => '⚠️ o banco recusou', 'ids' => []]; }
    $z = iaClienteZapAplica($c['id'], $args); if ($z) $t[] = $z;
    iaRegistra($lote, $u, 'cliente_editar', $c['id'], array_intersect_key($antes, $upd + ['zap' => 1, 'zap_ativo' => 1, 'ia_zap' => 1, 'email_aviso' => 1]), $upd, $c['name']);
    return ['texto' => $t ? '✅ ' . $c['name'] . ' → ' . implode(' · ', $t) : 'ℹ️ nada pra mudar em ' . $c['name'], 'ids' => []];
}

// ---- lembrete, recado, produção (mesmas rotinas do zap) ----
function iaLembrete($args, $u, $lote) { $a = $args; $a['tipo'] = 'lembrete'; return ['texto' => pdZapLembrete($a, $u), 'ids' => []]; }
function iaDelegar($args, $u, $lote) { return ['texto' => pdDelegar($args, $u), 'ids' => []]; }
function iaRecado($args, $u, $lote) { return ['texto' => pdZapRecado(['para' => $args['para'] ?? '', 'texto' => $args['texto'] ?? ''], $u), 'ids' => []]; }
function iaProducao($args, $u, $lote) {
    $op = (string)($args['op'] ?? ''); $tid = strtoupper(trim((string)($args['id'] ?? '')));
    if (!in_array($op, ['play', 'pausar', 'retomar', 'mensagem', 'nota'], true)) return ['texto' => '⚠️ op inválida (play, pausar, retomar, mensagem, nota)', 'ids' => []];
    $r = pdZapAcao(['tipo' => $op, 'task_id' => $tid, 'texto' => (string)($args['texto'] ?? '')], $u, iaStatusValidos());
    return ['texto' => str_replace('(WhatsApp)', '', (string)$r), 'ids' => [$tid]];
}

// ---- catálogo (schema pra IA), filtrado por quem pede ----
// ferramentas do CLIENTE (Gigi): pedir trabalho, ver tarefa, recado, lembrar — tudo preso ao client_id dele
function iaCatalogoCliente($u) {
    $S = fn($props, $req = []) => ['type' => 'object', 'properties' => $props, 'required' => $req];
    $str = ['type' => 'string'];
    return [
        ['name' => 'pedir_trabalho', 'description' => 'Abre um pedido (tarefa) do cliente pra agência. Por item: action (o que ele quer, objetivo), produto (opcional, nome do empreendimento/produto), obs (detalhes, referências), date_cli (prazo desejado AAAA-MM-DD, opcional). O sistema põe o cliente e o responsável certos.',
            'input_schema' => $S(['pedidos' => ['type' => 'array', 'items' => $S(['action' => $str, 'produto' => $str, 'obs' => $str, 'date_cli' => $str], ['action'])]], ['pedidos'])],
        ['name' => 'ver_tarefa', 'description' => 'Detalhes de uma tarefa do cliente (comentários, filhas) pelo id SD-####.', 'input_schema' => $S(['id' => $str], ['id'])],
        ['name' => 'comentar', 'description' => 'Deixa um recado do cliente numa tarefa dele (a equipe recebe).', 'input_schema' => $S(['id' => $str, 'texto' => $str], ['id', 'texto'])],
        ['name' => 'lembrar', 'description' => 'Guarda algo que o cliente ensinou sobre a empresa dele (escopo cliente).', 'input_schema' => $S(['memorias' => ['type' => 'array', 'items' => $S(['assunto' => $str, 'conteudo' => $str], ['assunto', 'conteudo'])]], ['memorias'])],
    ];
}
function iaClienteTarefaDele($id) {   // a tarefa é do cliente logado?
    $cid = $GLOBALS['PD_IA_CLIENTE'] ?? null; if (!$cid) return true;
    $t = sd_supabase('/rest/v1/tasks?id=eq.' . rawurlencode(strtoupper(trim((string)$id))) . '&select=client_id')['body'][0] ?? null;
    return $t && (string)$t['client_id'] === (string)$cid;
}
function iaPedirTrabalho($args, $u, $lote) {
    $cid = $GLOBALS['PD_IA_CLIENTE'] ?? ($u['client_id'] ?? null); if (!$cid) return ['texto' => '⚠️ conta sem cliente', 'ids' => []];
    $cl = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($cid) . '&select=name')['body'][0] ?? null;
    // responsável: quem tem a função atendimento; senão a primeira chave da equipe
    $resp = null; try { $at = sd_supabase('/rest/v1/profiles?active=eq.true&role=in.(admin,team)&funcoes=cs.{atendimento}&select=team_key&limit=1')['body'][0] ?? null; $resp = strtoupper(trim((string)($at['team_key'] ?? ''))) ?: null; } catch (Throwable $e) {}
    $novas = [];
    foreach (array_slice((array)($args['pedidos'] ?? []), 0, 10) as $p) { $p = (array)$p; $novas[] = ['action' => $p['action'] ?? '', 'cliente' => $cl['name'] ?? '', 'produto' => $p['produto'] ?? '', 'obs' => trim("Pedido do cliente (" . ($u['name'] ?? '') . ") pelo chat.\n" . (string)($p['obs'] ?? '')), 'date_cli' => $p['date_cli'] ?? '', 'status' => 'BRIEFING', 'resp' => $resp]; }
    return iaCriar(['novas' => $novas], $u, $lote);
}
function iaCatalogo($u) {
    $S = fn($props, $req = []) => ['type' => 'object', 'properties' => $props, 'required' => $req];
    $str = ['type' => 'string']; $arrS = ['type' => 'array', 'items' => $str]; $bool = ['type' => 'boolean'];
    $T = [];
    $T[] = ['name' => 'alterar_tarefas', 'description' => 'Altera tarefas existentes (até 60 por chamada). Campos opcionais por item: status, resp (chave ou nome), projeto, cliente, produto ("" tira), date_int, date_cli ("" tira), action (título), obs (substitui), obs_acrescenta, tarefa_mae (id; "" desliga).',
        'input_schema' => $S(['mudancas' => ['type' => 'array', 'items' => $S(['id' => $str, 'status' => $str, 'resp' => $str, 'projeto' => $str, 'cliente' => $str, 'produto' => $str, 'date_int' => $str, 'date_cli' => $str, 'action' => $str, 'obs' => $str, 'obs_acrescenta' => $str, 'tarefa_mae' => $str], ['id'])]], ['mudancas'])];
    $T[] = ['name' => 'criar_tarefas', 'description' => 'Cria tarefas (até 40). Por item: action (título objetivo), cliente, produto, projeto, resp, status (padrão BRIEFING), date_int, date_cli, obs, depois_de (posição 1..n do item que vem antes) ou tarefa_mae (id existente).',
        'input_schema' => $S(['novas' => ['type' => 'array', 'items' => $S(['action' => $str, 'cliente' => $str, 'produto' => $str, 'projeto' => $str, 'resp' => $str, 'status' => $str, 'date_int' => $str, 'date_cli' => $str, 'obs' => $str, 'depois_de' => ['type' => 'integer'], 'tarefa_mae' => $str], ['action'])]], ['novas'])];
    $T[] = ['name' => 'excluir_tarefas', 'description' => 'Exclui tarefas de vez (fica cópia pra desfazer).', 'input_schema' => $S(['ids' => $arrS, 'motivo' => $str], ['ids'])];
    $T[] = ['name' => 'comentar', 'description' => 'Comenta numa tarefa, assinado pela pessoa.', 'input_schema' => $S(['id' => $str, 'texto' => $str], ['id', 'texto'])];
    $T[] = ['name' => 'novo_produto', 'description' => 'Cria um produto/empreendimento num cliente.', 'input_schema' => $S(['cliente' => $str, 'nome' => $str], ['cliente', 'nome'])];
    $T[] = ['name' => 'lembrar', 'description' => 'Guarda na memória da casa algo duradouro. escopo: agencia | cliente (alvo = nome) | pessoa | tarefa (alvo = id).',
        'input_schema' => $S(['memorias' => ['type' => 'array', 'items' => $S(['escopo' => $str, 'alvo' => $str, 'assunto' => $str, 'conteudo' => $str, 'natureza' => $str], ['escopo', 'assunto', 'conteudo'])]], ['memorias'])];
    $T[] = ['name' => 'ver_tarefa', 'description' => 'Lê uma tarefa completa (campos, comentários, filhas).', 'input_schema' => $S(['id' => $str], ['id'])];
    $T[] = ['name' => 'lembrete', 'description' => 'Lembrete pra própria pessoa (plataforma + zap). op: criar|cancelar. quando "AAAA-MM-DD HH:MM"; recorrencia nenhuma|diaria|dias_uteis|semanal|mensal (com hora "HH:MM").',
        'input_schema' => $S(['op' => $str, 'texto' => $str, 'quando' => $str, 'recorrencia' => $str, 'hora' => $str, 'id' => ['type' => 'integer']])];
    $T[] = ['name' => 'delegar', 'description' => 'Passa um pedido a outra IA da casa (tumi = captação e pesquisa de mercado; sumi = campanhas; muru = social; gigi = cliente) ou à IA parceira de alguém. Use quando o trabalho cabe no papel dela.', 'input_schema' => $S(['para' => $str, 'pedido' => $str, 'cliente' => $str, 'quantos' => ['type' => 'integer']], ['para', 'pedido'])];
    $T[] = ['name' => 'recado', 'description' => 'Leva um recado a um colega da equipe pelo WhatsApp (via a IA dele).', 'input_schema' => $S(['para' => $str, 'texto' => $str], ['para', 'texto'])];
    $T[] = ['name' => 'producao', 'description' => 'Fala com a IA de produção da tarefa. op: play (dá start), pausar, retomar, mensagem (texto pra IA aplicar no trabalho), nota (anota na tarefa).', 'input_schema' => $S(['op' => $str, 'id' => $str, 'texto' => $str], ['op', 'id'])];
    if (iaEhAdmin($u) || iaPode($u, 'cliente.criar')) $T[] = ['name' => 'cliente_criar', 'description' => 'Cria um cliente (empresa), com produtos, zap, e-mail de aviso e Gigi (IA de atendimento no zap do cliente).',
        'input_schema' => $S(['nome' => $str, 'produtos' => $arrS, 'cor' => $str, 'site' => $str, 'observacoes' => $str, 'zap' => $str, 'email_aviso' => $str, 'gigi' => $bool], ['nome'])];
    if (iaEhAdmin($u) || iaPode($u, 'cliente.editar')) $T[] = ['name' => 'cliente_editar', 'description' => 'Edita a ficha do cliente: nome, cor, site, observações, zap, e-mail de aviso, gigi (liga/desliga a IA de atendimento), ativo (pausa/reativa).',
        'input_schema' => $S(['cliente' => $str, 'nome' => $str, 'cor' => $str, 'site' => $str, 'observacoes' => $str, 'zap' => $str, 'email_aviso' => $str, 'gigi' => $bool, 'ativo' => $bool], ['cliente'])];
    if (iaEhAdmin($u)) {
        $T[] = ['name' => 'usuarios_criar', 'description' => 'Cadastra pessoas (até 20): manda convite por e-mail. papel: team|admin|client (client exige cliente). funcoes: nomes do catálogo (' . implode(', ', pdFuncoesCatalogo()) . '). perfil_permissoes: Produção|Atendimento|Gestor de mídia. ferramentas: digital|audio|video|grafica|todas.',
            'input_schema' => $S(['pessoas' => ['type' => 'array', 'items' => $S(['nome' => $str, 'email' => $str, 'zap' => $str, 'papel' => $str, 'cliente' => $str, 'funcoes' => $arrS, 'perfil_permissoes' => $str, 'ferramentas' => $arrS], ['nome', 'email'])]], ['pessoas'])];
        $T[] = ['name' => 'usuario_excluir', 'description' => 'Pede a exclusão de uma pessoa: o SISTEMA lista as contas com e-mail e exige e-mail + senha do admin antes de executar. Você só chama; a confirmação não é sua.', 'input_schema' => $S(['pessoa' => $str, 'tarefas_para' => $str], ['pessoa'])];
        $T[] = ['name' => 'convite_reenviar', 'description' => 'Gera um NOVO link de acesso (vale 24h) pra uma pessoa já cadastrada e reenvia o e-mail de convite. Use quando o e-mail não chegou ou a pessoa pediu o link; o link vem na resposta e pode ser mandado por zap.', 'input_schema' => $S(['pessoa' => $str], ['pessoa'])];
        $T[] = ['name' => 'usuario_acesso', 'description' => 'Reativa (ativo=true) o acesso de uma pessoa, ou pede a pausa (ativo=false; o sistema confirma com e-mail + senha do admin).', 'input_schema' => $S(['pessoa' => $str, 'ativo' => $bool], ['pessoa', 'ativo'])];
        $T[] = ['name' => 'funcoes_definir', 'description' => 'Define as funções de uma pessoa (designer, copy, atendimento...). Quem ganha função ganha chave própria de responsável.', 'input_schema' => $S(['pessoa' => $str, 'funcoes' => $arrS], ['pessoa', 'funcoes'])];
        $T[] = ['name' => 'permissoes_definir', 'description' => 'Permissões da pessoa: perfil pronto (Produção, Atendimento, Gestor de mídia) e/ou chaves (tarefa.criar, tarefa.excluir, social.publicar, social.conectar...).', 'input_schema' => $S(['pessoa' => $str, 'perfil' => $str, 'chaves' => $arrS], ['pessoa'])];
        $T[] = ['name' => 'ferramentas_liberar', 'description' => 'Libera ferramentas pra pessoa: digital (arte), audio (locução), video, grafica, zap (comunicador de WhatsApp: atendimento e vendas), vendas (prospecção/CRM), todas, nenhuma (só Copys).', 'input_schema' => $S(['pessoa' => $str, 'ferramentas' => $arrS], ['pessoa', 'ferramentas'])];
    }
    if (function_exists('vdPode') && vdPode($u)) {
        $T[] = ['name' => 'vendas_funil', 'description' => 'CRM de vendas (captação de clientes, liderada pelo Tumi). Mostra o funil (quantos leads por estágio) da carteira e, com listar=true, os melhores leads. carteira: nome do cliente dono da carteira, ou vazio pra carteira da própria casa, ou "todas".',
            'input_schema' => $S(['carteira' => $str, 'listar' => $bool])];
        $T[] = ['name' => 'vendas_importar', 'description' => 'Cadastra leads na carteira. linhas = uma empresa por linha: "Empresa; site; cidade; segmento; contato; e-mail; zap". Depois busca sinais públicos do site (pixel, WhatsApp, lançamento…) e calcula a nota de encaixe.',
            'input_schema' => $S(['carteira' => $str, 'lote' => $str, 'linhas' => $str], ['linhas'])];
        $T[] = ['name' => 'vendas_abordar', 'description' => 'Escreve os RASCUNHOS de abordagem (nada é enviado) pros N melhores leads novos da carteira, cada um com um ângulo específico baseado nos sinais verificados. canal: email|zap. Os rascunhos ficam na Caixa de saída esperando aprovação.',
            'input_schema' => $S(['carteira' => $str, 'canal' => $str, 'quantos' => ['type' => 'integer'], 'oferta' => $str])];
        $T[] = ['name' => 'vendas_descobrir', 'description' => 'Acha leads NOVOS sozinha, pesquisando na web (empresas reais com site confirmado), e põe na carteira com a leitura do site. consulta = o que procurar, ex.: "incorporadoras com lançamento em Campinas". Gasta busca: no máx. 10 por dia.',
            'input_schema' => $S(['carteira' => $str, 'consulta' => $str, 'quantos' => ['type' => 'integer']], ['consulta'])];
        $T[] = ['name' => 'vendas_enviar', 'description' => 'Envia os rascunhos aprovados pela pessoa que está pedindo (ela pediu, vale como aprovação). Sem ids, envia todos os rascunhos da carteira. Respeita pedido de sair e o teto diário.',
            'input_schema' => $S(['carteira' => $str, 'ids' => $arrS])];
        $T[] = ['name' => 'vendas_resposta', 'description' => 'Registra o que um lead respondeu (colado pela pessoa). O sistema classifica (interessado, pergunta, objeção, não, pedido de sair…), age (sair = nunca mais) e escreve um rascunho de resposta quando cabe.',
            'input_schema' => $S(['lead' => $str, 'texto' => $str, 'canal' => $str], ['lead', 'texto'])];
    }
    return $T;
}
function iaExecutarTudo($nome, $args, $u, $lote) {
    switch ($nome) {
        case 'alterar_tarefas': return iaAlterar($args, $u, $lote);
        case 'pedir_trabalho':  return iaPedirTrabalho($args, $u, $lote);
        case 'criar_tarefas':   return iaCriar($args, $u, $lote);
        case 'excluir_tarefas': if (!iaEhAdmin($u) && !iaPode($u, 'tarefa.excluir') && iaPermissoes($u)) return ['texto' => '⚠️ você não tem permissão pra excluir tarefa', 'ids' => []]; return iaExcluir($args, $u, $lote);
        case 'comentar':        return iaComentar($args, $u, $lote);
        case 'novo_produto':    return iaNovoProduto($args, $u, $lote);
        case 'lembrar':         return iaLembrar($args, $u, $lote);
        case 'ver_tarefa':      if (!empty($GLOBALS['PD_IA_CLIENTE']) && !iaClienteTarefaDele($args['id'] ?? '')) return ['texto' => '⚠️ essa tarefa não é da sua empresa', 'ids' => []]; return iaVerTarefa($args);
        case 'lembrete':        return iaLembrete($args, $u, $lote);
        case 'recado':          return iaRecado($args, $u, $lote);
        case 'delegar':         return iaDelegar($args, $u, $lote);
        case 'producao':        return iaProducao($args, $u, $lote);
        case 'cliente_criar':   return iaClienteCriar($args, $u, $lote);
        case 'cliente_editar':  return iaClienteEditar($args, $u, $lote);
        case 'usuarios_criar':  return iaUsuariosCriar($args, $u, $lote);
        case 'usuario_acesso':  return iaUsuarioAcesso($args, $u, $lote);
        case 'convite_reenviar': return iaConviteReenviar($args, $u, $lote);
        case 'usuario_excluir': return iaUsuarioExcluir($args, $u, $lote);
        case 'funcoes_definir': return iaFuncoesDefinir($args, $u, $lote);
        case 'permissoes_definir': return iaPermissoesDefinir($args, $u, $lote);
        case 'ferramentas_liberar': return iaFerramentasLiberar($args, $u, $lote);
        case 'vendas_funil': case 'vendas_descobrir': case 'vendas_importar': case 'vendas_abordar': case 'vendas_enviar': case 'vendas_resposta': return iaVendas($nome, $args, $u);
    }
    return ['texto' => "⚠️ ferramenta desconhecida $nome", 'ids' => []];
}

// =====================================================================================
// CONFIRMAÇÃO OFICIAL — tirar ou pausar pessoa nunca sai só pelo nome.
// 1) a casa lista as contas com e-mail; 2) o admin responde com o E-MAIL da conta; 3) e com a SENHA dele (a do zap).
// Só então executa. Vale pro chat da plataforma e pro zap. Pendência expira em 15 min.
// =====================================================================================
function iaContasLista($filtro = '') {
    $ps = (array)(sd_supabase('/rest/v1/profiles?select=id,name,email,role,active&order=name&limit=300')['body'] ?? []);
    $q = iaNorm($filtro); $out = [];
    foreach ($ps as $p) { if ($q !== '' && strpos(iaNorm($p['name']) . ' ' . iaNorm($p['email'] ?? ''), $q) === false && strpos($q, iaNorm($p['name'])) === false) { $pal = array_filter(explode(' ', $q)); $ok = (bool)$pal; foreach ($pal as $w) if (strpos(' ' . iaNorm($p['name']), ' ' . $w) === false) { $ok = false; break; } if (!$ok) continue; } $out[] = $p; }
    return $out;
}
function iaConfirmaPedir($u, $canal, $chave, $acao, array $cands, $rotulo) {
    $lista = array_map(fn($p) => '• ' . trim($p['name']) . ' — ' . ($p['email'] ?: 'sem e-mail') . ' (' . ($p['role'] === 'admin' ? 'admin' : ($p['role'] === 'client' ? 'cliente' : 'equipe')) . ($p['active'] === false ? ', pausado' : '') . ')', $cands);
    try { sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . rawurlencode((string)$chave) . '&canal=eq.' . $canal . '&status=eq.confirma_pendente', 'PATCH', ['status' => 'confirma_cancelada']); } catch (Throwable $e) {}
    sd_supabase('/rest/v1/pd_avisos_log', 'POST', ['canal' => $canal, 'para' => (string)$chave, 'quem' => $u['name'] ?? '', 'corpo' => $rotulo, 'status' => 'confirma_pendente', 'origem' => 'sistema', 'direcao' => 'entrada',
        'ref' => 'confirma:' . json_encode(['acao' => $acao, 'ids' => array_column($cands, 'id')], JSON_UNESCAPED_UNICODE)]);
    $temSenha = pdPalavraMagica($u['id'] ?? '') !== '';
    return "🔐 $rotulo\n" . implode("\n", $lista) . "\n\nPra confirmar, responda com o *e-mail da conta* + a *palavra combinada* entre nós, na mesma mensagem (ex.: `fulano@empresa.com abacaxi`). Não é a sua senha de acesso."
        . ($temSenha ? '' : "\n\n⚠️ A gente ainda não combinou uma palavra. Antes, me diga: *guarda: minha palavra combinada é …*");
}
// roda ANTES da IA, em qualquer canal: se há pendência e a mensagem traz e-mail + senha, executa de verdade
function iaPalavras($s) { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$s))))); }
function iaConfirmaProcessar($u, $canal, $chave, $txt) {
    $pend = sd_supabase('/rest/v1/pd_avisos_log?para=eq.' . rawurlencode((string)$chave) . '&canal=eq.' . $canal . '&status=eq.confirma_pendente&criado_em=gte.' . rawurlencode(gmdate('c', time() - 900)) . '&select=id,ref,corpo&order=criado_em.desc&limit=1')['body'][0] ?? null;
    if (!$pend) return null;
    $t = trim((string)$txt);
    if (preg_match('/^(cancela\w*|deixa pra l[áa]|esquece)\b/iu', $t) || preg_match('/^n[aã]o[.!, ]*$/iu', $t)) { /* só "cancela" / "não" seco cancelam; "não, ..." em prosa segue */ sd_supabase('/rest/v1/pd_avisos_log?id=eq.' . (int)$pend['id'], 'PATCH', ['status' => 'confirma_cancelada']); return ['texto' => 'Cancelado. Nada foi feito.', 'feito' => false]; }
    $d = json_decode(substr((string)$pend['ref'], 9), true) ?: []; $ids = (array)($d['ids'] ?? []); $acao = (string)($d['acao'] ?? '');
    $email = ''; $senha = ''; $num = 0;
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $t, $em)) { $email = strtolower($em[0]); $senha = trim(str_replace($em[0], '', $t)); }
    elseif (count($ids) === 1 && preg_match('/^(\S+)$/u', $t)) { $num = 1; $senha = $t; }   // só uma conta na lista: basta a palavra
    elseif (count($ids) === 1 && ($pw = pdSegredoLimpa(pdPalavraMagica($u['id'] ?? ''))) !== '' && strpos(' ' . iaPalavras($t) . ' ', ' ' . iaPalavras($pw) . ' ') !== false) { $num = 1; $senha = $pw; }   // a palavra veio no meio da frase ("a nossa palavra é X")
    else return null;   // não é a confirmação: segue conversa normal
    $alvo = null; $k = 0; foreach ($ids as $id) { $k++; $p = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($id) . '&select=id,name,email,role,team_key,active')['body'][0] ?? null; if ($p && (($email !== '' && strtolower((string)$p['email']) === $email) || ($num && $k === $num))) { $alvo = $p; break; } }
    if (!$alvo) return ['texto' => '⚠️ Esse e-mail não é de nenhuma das contas listadas. Confere e manda de novo (e-mail + senha).', 'feito' => false];
    $pal = pdPalavraMagica($u['id'] ?? '');
    if ($pal === '') return ['texto' => '⚠️ A gente ainda não combinou uma palavra. Diga *guarda: minha palavra combinada é …* e depois confirme de novo.', 'feito' => false];
    // "palavra combinada Joy", "senha: Joy", "é Joy" → Joy; ou a palavra em qualquer lugar do resto da mensagem
    $senha = pdSegredoLimpa($senha); $pal = pdSegredoLimpa($pal);
    $bate = $senha !== '' && (pdSegredoNorm($senha) === pdSegredoNorm($pal) || strpos(' ' . iaPalavras($senha) . ' ', ' ' . iaPalavras($pal) . ' ') !== false);
    if (!$bate) return ['texto' => '⚠️ A palavra combinada não bate. Manda de novo: e-mail + palavra.', 'feito' => false];
    sd_supabase('/rest/v1/pd_avisos_log?id=eq.' . (int)$pend['id'], 'PATCH', ['status' => 'confirma_ok']);
    $lote = sprintf('%08x-%04x-4%03x-%04x-%012x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0x8000, 0xbfff), mt_rand(0, 0xffffffffffff));
    if ($acao === 'excluir') $r = iaUsuarioExcluirExec($alvo, $u, $lote, null);
    elseif ($acao === 'pausar') $r = iaUsuarioAcessoExec($alvo, false, $u, $lote);
    else return ['texto' => '⚠️ ação desconhecida', 'feito' => false];
    return ['texto' => "✔ Confirmado.\n" . $r['texto'], 'feito' => true];
}
function iaUsuarioAcessoExec($p, $ativo, $u, $lote) {
    sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']), 'PATCH', ['active' => $ativo]);
    sd_supabase('/auth/v1/admin/users/' . urlencode($p['id']), 'PUT', ['ban_duration' => $ativo ? 'none' : '876000h']);
    iaRegistra($lote, $u, 'acesso', $p['id'], ['active' => $p['active'] ?? true], ['active' => $ativo], $p['name']);
    return ['texto' => ($ativo ? '✅ acesso de ' : '⏸️ acesso de ') . $p['name'] . ' (' . $p['email'] . ')' . ($ativo ? ' reativado' : ' pausado'), 'ids' => []];
}
function iaUsuarioExcluirExec($p, $u, $lote, $para) {
    $n = 0;
    if (!empty($p['team_key']) && !in_array($p['team_key'], ['OUTROS', 'CLIENTE'], true)) {
        $tt = (array)(sd_supabase('/rest/v1/tasks?resp=eq.' . urlencode($p['team_key']) . '&status=neq.ENTREGUE&select=id')['body'] ?? []); $n = count($tt);
        if ($n && $para) sd_supabase('/rest/v1/tasks?resp=eq.' . urlencode($p['team_key']) . '&status=neq.ENTREGUE', 'PATCH', ['resp' => $para]);
    }
    $antes = ['active' => $p['active'] ?? true, 'email' => $p['email'] ?? '', 'name' => $p['name'], 'role' => $p['role'] ?? 'team', 'team_key' => $p['team_key'] ?? null];
    sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']), 'PATCH', ['active' => false, 'zap' => null]);   // some do painel e do número compartilhado já
    sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($p['id']), 'DELETE');
    sd_supabase('/rest/v1/pd_avisos_log?ref=eq.' . rawurlencode('persona:' . $p['id']), 'PATCH', ['status' => 'persona_cancelada']);   // se era a persona ativa de um número, solta
    $del = sd_supabase('/auth/v1/admin/users/' . urlencode($p['id']), 'DELETE');
    if (($del['code'] ?? 0) >= 500) {   // o Auth trava quando alguma tabela ainda aponta pro usuário: tira o perfil (as FKs em cascata somem) e tenta de novo
        $dp = sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']), 'DELETE');
        if (($dp['code'] ?? 0) < 300) $del = sd_supabase('/auth/v1/admin/users/' . urlencode($p['id']), 'DELETE');
    }
    if (!in_array($del['code'] ?? 0, [200, 204, 404], true)) { sd_supabase('/auth/v1/admin/users/' . urlencode($p['id']), 'PUT', ['ban_duration' => '876000h']);
        return ['texto' => '⚠️ ' . $p['name'] . ' (' . $p['email'] . '): acesso bloqueado e perfil inativo, mas a conta não saiu do Auth (HTTP ' . ($del['code'] ?? '?') . ': ' . mb_substr((string)($del['body']['msg'] ?? $del['body']['message'] ?? $del['raw'] ?? ''), 0, 120) . ')', 'ids' => []]; }
    sd_supabase('/rest/v1/profiles?id=eq.' . urlencode($p['id']), 'DELETE');   // perfil sai junto (se alguma tabela ainda segurar, fica inativo e some do painel)
    iaRegistra($lote, $u, 'usuario_excluir', $p['id'], $antes, null, $p['name']);
    return ['texto' => '🗑️ ' . $p['name'] . ' (' . $p['email'] . ') removid' . 'o(a) da plataforma' . ($n ? ($para ? " · $n tarefa(s) aberta(s) passaram pra $para" : " · $n tarefa(s) aberta(s) ficaram no nome dela(e)") : ''), 'ids' => []];
}

// ---------------------------------------------------------------- VENDAS (Tumi) pelo chat e pelo zap
function iaVendasCarteira($txt) {
    $t = trim((string)$txt); if ($t === '' || preg_match('/^(casa|propria|própria|sd|agencia|agência|strat|sd\/strat)$/iu', $t)) { $cid = vdCasaId(); return [$cid, 'SD/STRAT']; }
    if (preg_match('/^todas?$/iu', $t)) return ['todas', 'todas as carteiras'];
    $c = iaCliente($t); return $c ? [$c['id'], $c['name']] : [false, $t];
}
function iaVendas($nome, $a, $u) {
    require_once __DIR__ . '/pd-vendas-lib.php';
    if (!vdPode($u)) return ['texto' => '⚠️ Vendas não está liberada pra você', 'ids' => []];
    @set_time_limit(240);
    if ($nome === 'vendas_resposta') {
        $t = mb_strtolower(trim((string)($a['lead'] ?? '')));
        $ls = vdLinhas(vdSup('/rest/v1/pd_prospects?select=id,nome&order=updated_at.desc&limit=400'));
        $hit = null; foreach ($ls as $l) if (mb_strtolower($l['nome']) === $t) { $hit = $l; break; }
        if (!$hit) foreach ($ls as $l) if ($t !== '' && mb_strpos(mb_strtolower($l['nome']), $t) !== false) { $hit = $l; break; }
        if (!$hit) return ['texto' => '⚠️ não achei o lead "' . ($a['lead'] ?? '') . '"', 'ids' => []];
        $r = vdEntrada($hit['id'], in_array($a['canal'] ?? '', ['zap', 'email', 'dm'], true) ? $a['canal'] : 'email', (string)($a['texto'] ?? ''));
        if (empty($r['ok'])) return ['texto' => '⚠️ ' . ($r['erro'] ?? 'erro'), 'ids' => []];
        $x = ['opt_out' => 'pediu pra sair — nunca mais contato', 'juridico' => 'tom jurídico — parei e avisei a equipe', 'nao' => 'recusou', 'interessado' => '🔥 interessado', 'pergunta' => 'fez uma pergunta', 'objecao' => 'trouxe uma objeção', 'pessoa_errada' => 'pessoa errada'][$r['intencao']] ?? $r['intencao'];
        return ['texto' => '📨 ' . $hit['nome'] . ': ' . $x . (!empty($r['rascunho']) ? ' — deixei um rascunho de resposta na Caixa de saída' : ''), 'ids' => []];
    }
    [$cart, $rot] = iaVendasCarteira($a['carteira'] ?? '');
    if ($cart === false) return ['texto' => '⚠️ não achei a carteira/cliente "' . $rot . '"', 'ids' => []];
    $f = $cart === 'todas' ? '' : '&carteira_id=' . ($cart ? 'eq.' . $cart : 'is.null');
    if ($nome === 'vendas_funil') {
        $fn = vdFunil($cart); $linhas = []; foreach ($fn as $e => $n) if ($n) $linhas[] = "$e: $n";
        $txt = "📊 Carteira de $rot — " . ($linhas ? implode(' · ', $linhas) : 'vazia');
        if (!empty($a['listar'])) { foreach (vdLinhas(vdSup('/rest/v1/pd_prospects?select=nome,estagio,nota,angulo&estagio=not.in.(ganho,perdido)' . $f . '&order=nota.desc.nullslast&limit=10')) as $l) $txt .= "
• " . $l['nome'] . ' (' . $l['estagio'] . ', nota ' . ($l['nota'] ?? '–') . ')' . ($l['angulo'] ? ' — ' . $l['angulo'] : ''); }
        return ['texto' => $txt, 'ids' => []];
    }
    if ($cart === 'todas') return ['texto' => '⚠️ pra isso diga qual carteira (cliente ou "casa")', 'ids' => []];
    if ($nome === 'vendas_descobrir') {
        $r = vdDescobrir((string)($a['consulta'] ?? ''), (int)($a['quantos'] ?? 10), $cart, $u['id']);
        return ['texto' => !empty($r['ok']) ? "🔎 Achei {$r['novos']} empresa(s) nova(s) pra carteira de $rot" . ($r['duplicados'] ? " ({$r['duplicados']} já estavam)" : '') . ' e já li os sites. Quer que eu escreva as abordagens?' : '⚠️ ' . ($r['erro'] ?? 'não consegui'), 'ids' => []];
    }
    if ($nome === 'vendas_importar') {
        $r = vdImportar((string)($a['linhas'] ?? ''), $cart, trim((string)($a['lote'] ?? '')) ?: null, $u['id']);
        $nota = 0; foreach (array_slice($r['ids'], 0, 8) as $pid) { $e = vdEnriquecer($pid); if (!empty($e['ok'])) $nota++; }
        return ['texto' => "✅ Carteira de $rot: {$r['novos']} lead(s) novo(s)" . ($r['duplicados'] ? ", {$r['duplicados']} já existiam" : '') . ($r['ignorados'] ? ", {$r['ignorados']} linha(s) ignorada(s)" : '') . ($nota ? ". Li o site de $nota deles e já tem nota de encaixe." : '') . (count($r['ids']) > 8 ? ' Os demais eu leio quando você pedir pra abordar.' : ''), 'ids' => []];
    }
    if ($nome === 'vendas_abordar') {
        $canal = ($a['canal'] ?? '') === 'zap' ? 'zap' : 'email'; $n = max(1, min(8, (int)($a['quantos'] ?? 5)));
        foreach (vdLinhas(vdSup('/rest/v1/pd_prospects?enriquecido_em=is.null&select=id' . $f . '&order=created_at.desc&limit=5')) as $x) vdEnriquecer($x['id']);
        $ok = 0; $falhas = [];
        foreach (vdLinhas(vdSup('/rest/v1/pd_prospects?estagio=eq.novo&select=id,nome' . $f . '&order=nota.desc.nullslast&limit=40')) as $l) {
            if ($ok >= $n) break;
            if (vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?prospect_id=eq.' . $l['id'] . '&direcao=eq.saida&select=id&limit=1'))) continue;
            $r = vdEscrever($l['id'], $canal, 1, null, (string)($a['oferta'] ?? '')); if (!empty($r['ok'])) $ok++; else $falhas[] = $l['nome'] . ' (' . $r['erro'] . ')';
        }
        return ['texto' => "✍️ $ok rascunho(s) de $canal na Caixa de saída de $rot — nada foi enviado ainda." . ($falhas ? "
Sem contato/erro: " . implode('; ', array_slice($falhas, 0, 4)) : ''), 'ids' => []];
    }
    if ($nome === 'vendas_enviar') {
        $ids = array_filter(array_map('vdUuid', (array)($a['ids'] ?? [])));
        if (!$ids) { $rs = vdLinhas(vdSup('/rest/v1/pd_prospect_conversas?status=in.(aguardando,aprovada)&direcao=eq.saida&select=id,pd_prospects!inner(carteira_id)&order=created_at.asc&limit=40'));
            foreach ($rs as $x) if ($cart === ($x['pd_prospects']['carteira_id'] ?? null)) $ids[] = $x['id']; }
        $ok = 0; $erros = [];
        foreach (array_slice($ids, 0, 25) as $i) { $r = vdEnviar($i, $u['id']); if (!empty($r['ok'])) $ok++; else { $erros[] = $r['erro'] ?? 'erro'; if (!empty($r['teto'])) break; } }
        return ['texto' => "📤 $ok enviada(s) de $rot." . ($erros ? ' Não saíram: ' . implode('; ', array_unique($erros)) : ''), 'ids' => []];
    }
    return ['texto' => '⚠️ ação de vendas desconhecida', 'ids' => []];
}
