<?php
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
        if (!empty($m['resp'])) { $k = iaResp($m['resp']); if ($k) $patch['resp'] = $k; else { $avisos[] = "\"{$m['resp']}\" não está na equipe (anotei na obs)"; $m['obs_acrescenta'] = trim((string)($m['obs_acrescenta'] ?? '') . " · responsável sugerido: {$m['resp']}"); } }
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
        $resp = !empty($n['resp']) ? iaResp($n['resp']) : null; $obs = trim((string)($n['obs'] ?? ''));
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
        if ($esc === 'cliente') { $c = iaCliente($m['alvo'] ?? ''); if (!$c) { $out[] = "⚠️ memória: cliente \"" . ($m['alvo'] ?? '') . "\" não achei"; continue; } $eid = $c['id']; }
        elseif ($esc === 'pessoa') $eid = $u['id'] ?? null; elseif ($esc === 'tarefa') $eid = strtoupper(trim((string)($m['alvo'] ?? '')));
        $d = ['escopo' => $esc, 'escopo_id' => $eid, 'assunto' => mb_substr((string)($m['assunto'] ?? 'nota'), 0, 120), 'conteudo' => mb_substr((string)($m['conteudo'] ?? ''), 0, 2000), 'natureza' => $m['natureza'] ?? 'orientacao', 'autor_id' => $u['id'] ?? null, 'origem' => 'chat'];
        $r = sd_supabase('/rest/v1/sd_memoria', 'POST', $d, ['Prefer: return=representation']);
        if (($r['code'] ?? 500) >= 300) { $out[] = '⚠️ não guardei "' . $d['assunto'] . '"'; continue; }
        iaRegistra($lote, $u, 'lembrar', (string)($r['body'][0]['id'] ?? ''), null, $d, $d['assunto']);
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
        case 'comentar':        return iaComentar($args, $u, $lote);
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
            elseif ($tipo === 'lembrar') { $r = sd_supabase('/rest/v1/sd_memoria?id=eq.' . urlencode($alvo), 'PATCH', ['ativo' => false, 'revogado_em' => date('c')]); $ok = ($r['code'] ?? 500) < 300; $out[] = ($ok ? '↩️ ' : '⚠️ ') . 'memória esquecida'; }
        } catch (Throwable $e) { $out[] = "⚠️ $alvo: " . mb_substr($e->getMessage(), 0, 80); }
        if ($ok) sd_supabase('/rest/v1/pd_ia_acoes?id=eq.' . (int)$a['id'], 'PATCH', ['desfeita_em' => date('c')]);
    }
    return ['ok' => true, 'texto' => implode("\n", $out)];
}
