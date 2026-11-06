<?php
// SÉRIES SEMANAIS (vistoria de social) — a plataforma cria a tarefa da semana toda sexta (prazo: quinta seguinte),
// lê a planilha de gestão (.xlsx no Drive, sem converter) e lembra a responsável pelo zap com o que falta.
// A planilha é da equipe: AQUI SÓ LEMOS. Nunca escrevemos nela.
require_once __DIR__ . '/pd-ia-lib.php';

function vsC($s) { return str_replace(' ', '', iaNorm(str_replace(['’', '‘', '`', '´'], "'", (string)$s))); }
function vsHoje() { return new DateTime('now', new DateTimeZone('America/Sao_Paulo')); }
/** Janela da semana: sexta (início) → quinta (fim) que contém a data. */
function vsJanela($d = null) { $d = $d ? clone $d : vsHoje(); $d->setTime(12, 0); $diff = ((int)$d->format('N') - 5 + 7) % 7;
    $ini = (clone $d)->modify("-$diff days"); $fim = (clone $ini)->modify('+6 days'); return [$ini->format('Y-m-d'), $fim->format('Y-m-d')]; }
function vsBR($d) { return $d ? date('d/m', strtotime($d)) : ''; }

// ---------------------------------------------------------------- planilha (config)
const VS_PLANILHA_PADRAO = '1Y7pRAil4Xkl_havCUOV22o-TBUV4DrCklvD-NX-GbTQ';   // SD_Vistoria_Social_Simples (Google Planilha da pasta da vistoria)
function vsConfig() { $r = sd_supabase('/rest/v1/pd_series_config?id=eq.1&select=*&limit=1'); $c = ($r['code'] ?? 500) < 300 ? ($r['body'][0] ?? null) : null;
    return ($c && !empty($c['planilha_id'])) ? $c : ['id' => 1, 'planilha_id' => VS_PLANILHA_PADRAO, 'planilha_url' => 'https://docs.google.com/spreadsheets/d/' . VS_PLANILHA_PADRAO . '/edit']; }
function vsSemTabela() { $r = sd_supabase('/rest/v1/pd_series?select=id&limit=1'); return ($r['code'] ?? 500) >= 300; }
/** Aceita link do arquivo, link da pasta (pega o arquivo de vistoria dentro dela) ou o id. */
function vsPlanilhaConfigurar($link) {
    $link = trim((string)$link); $id = null; $url = $link;
    if (preg_match('~/folders/([A-Za-z0-9_-]{15,})~', $link, $m)) {
        $ls = ponte('listar', ['folderId' => $m[1]]); $achou = null;
        foreach ((array)($ls['arquivos'] ?? []) as $f) if (preg_match('/\.xlsx$|spreadsheet/i', ($f['name'] ?? '') . ($f['mime'] ?? '')) && (!$achou || preg_match('/vistoria/i', $f['name'] ?? ''))) $achou = $f;
        if (!$achou) return ['ok' => false, 'erro' => 'não achei planilha nessa pasta'];
        $id = $achou['id'] ?? null; $url = 'https://drive.google.com/file/d/' . $id . '/view';
    } elseif (preg_match('~/d/([A-Za-z0-9_-]{15,})~', $link, $m) || preg_match('~[?&]id=([A-Za-z0-9_-]{15,})~', $link, $m) || preg_match('~^([A-Za-z0-9_-]{25,})$~', $link, $m)) { $id = $m[1]; }
    if (!$id) return ['ok' => false, 'erro' => 'link inválido'];
    $x = vsPlanilhaLer($id, true); if (!empty($x['erro'])) return ['ok' => false, 'erro' => 'não consegui abrir a planilha: ' . $x['erro']];
    sd_supabase('/rest/v1/pd_series_config?on_conflict=id', 'POST', ['id' => 1, 'planilha_id' => $id, 'planilha_url' => $url, 'atualizado_em' => gmdate('c')], ['Prefer: resolution=merge-duplicates']);
    return ['ok' => true, 'id' => $id, 'url' => $url, 'abas' => array_keys($x['abas'] ?? [])];
}

// ---------------------------------------------------------------- leitura do .xlsx (só leitura)
/** Baixa o arquivo do Drive e devolve as abas como [nome => [linha => [A,B,C]]]. $soNomes: só lista os nomes das abas. */
function vsPlanilhaLer($id = null, $soNomes = false, $abaNome = null) {
    static $cache = [];
    if (!$id) { $cfg = vsConfig(); $id = $cfg['planilha_id'] ?? null; } if (!$id) return ['erro' => 'sem planilha configurada'];
    if (!isset($cache[$id])) {
        try { $r = ponte('baixar', ['fileId' => $id]); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 160)]; }
        $b = base64_decode((string)($r['b64'] ?? ''), true); if (!$b) return ['erro' => 'arquivo vazio'];
        $tmp = tempnam(sys_get_temp_dir(), 'vs'); file_put_contents($tmp, $b); $z = new ZipArchive();
        if ($z->open($tmp) !== true) { @unlink($tmp); return ['erro' => 'não é um .xlsx válido']; }
        $ss = []; if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) { $sx = @simplexml_load_string($x); if ($sx) foreach ($sx->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $rr) $t .= (string)$rr->t; $ss[] = $t; } }
        $wb = @simplexml_load_string((string)$z->getFromName('xl/workbook.xml')); $rels = @simplexml_load_string((string)$z->getFromName('xl/_rels/workbook.xml.rels'));
        $alvo = []; if ($rels) foreach ($rels->Relationship as $rl) $alvo[(string)$rl['Id']] = (string)$rl['Target'];
        $abas = [];
        if ($wb) foreach ($wb->sheets->sheet as $sh) { $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']; $tg = $alvo[$rid] ?? ''; $abas[(string)$sh['name']] = 'xl/' . ltrim(preg_replace('~^/?xl/~', '', $tg), '/'); }
        $cache[$id] = ['z' => $z, 'ss' => $ss, 'abas' => $abas, 'tmp' => $tmp];
    }
    $c = &$cache[$id]; if ($soNomes) return ['abas' => $c['abas']];
    $out = [];
    foreach ($c['abas'] as $nome => $path) {
        if ($abaNome !== null && $nome !== $abaNome) continue;
        $sx = @simplexml_load_string((string)$c['z']->getFromName($path)); if (!$sx) continue; $linhas = [];
        foreach ($sx->sheetData->row as $row) { $n = (int)$row['r'];
            foreach ($row->c as $cel) { $ref = (string)$cel['r']; $col = preg_replace('/\d+/', '', $ref); $t = (string)$cel['t']; $v = isset($cel->v) ? (string)$cel->v : '';
                if ($t === 's') $v = $c['ss'][(int)$v] ?? ''; elseif ($t === 'inlineStr') $v = (string)$cel->is->t;
                if (trim($v) !== '') $linhas[$n][$col] = trim($v); } }
        $out[$nome] = $linhas;
    }
    return ['abas' => $out];
}
/** Aba cujo nome começa com a semana (ex.: "Semana 1 06-11 a 12-11") — casa pela data de início. */
function vsAbaDaSemana($nomes, $ini) { $dm = date('d-m', strtotime($ini)); foreach ($nomes as $n) if (strpos($n, $dm . ' a ') !== false) return $n; return null; }
/** Blocos da aba: título "Cliente — Produto" → itens [nome, estado, texto]. */
function vsBlocos($linhas) {
    $bl = []; $atual = null;
    foreach ($linhas as $n => $cols) {
        $a = $cols['A'] ?? ''; $b = $cols['B'] ?? '';
        if ($a !== '' && mb_strpos($a, ' — ') !== false && $b === '' && stripos($a, 'Em espera') !== 0 && stripos($a, 'Vistoria') !== 0) { $atual = $a; $bl[$a] = ['itens' => [], 'espera' => false]; continue; }
        if ($atual && stripos($a, 'Em espera') === 0) { $bl[$atual]['espera'] = true; continue; }
        if ($atual && $a !== '' && $a !== 'Conferir' && $b !== '') $bl[$atual]['itens'][] = ['nome' => $a, 'estado' => $b, 'texto' => $cols['C'] ?? ''];
    }
    return $bl;
}
/** Acha o bloco da série pelo nome digitado (cliente + produto). */
function vsAcharBloco($serie, $blocos) {
    $cc = vsC($serie['cliente_nome'] ?? ''); $pc = vsC($serie['produto_nome'] ?? '');
    foreach ($blocos as $label => $_) { if (vsC($label) === $cc . $pc) return $label; }
    foreach ($blocos as $label => $_) { [$a, $b] = array_pad(explode(' — ', $label, 2), 2, ''); $ac = vsC($a); $bc = vsC($b);
        if ($bc === $pc && $ac !== '' && (strpos($cc, $ac) === 0 || strpos($ac, $cc) === 0 || strpos($cc, $ac) !== false)) return $label; }
    foreach ($blocos as $label => $_) { [$a, $b] = array_pad(explode(' — ', $label, 2), 2, ''); if (vsC($b) === $pc && vsC($a) === $cc) return $label; }
    return null;
}
/** Situação de um bloco: quantos itens conferidos, o que falta e o que precisa ajustar. */
function vsSituacao($bloco) {
    $r = ['total' => count($bloco['itens']), 'feitos' => 0, 'faltam' => [], 'ajustar' => [], 'sem_acesso' => 0];
    foreach ($bloco['itens'] as $i) { $e = vsC($i['estado']);
        if ($e === 'selecione' || $e === '') { $r['faltam'][] = $i['nome']; continue; }
        $r['feitos']++;
        if ($e === 'ajustar') { $t = trim($i['texto']); $r['ajustar'][] = $i['nome'] . (($t !== '' && vsC($t) !== 'escrevaaqui') ? ' → ' . $t : ''); }
        if ($e === 'semacesso') $r['sem_acesso']++; }
    return $r;
}

// ---------------------------------------------------------------- nomes (cliente/produto do texto → cadastro da PD)
const VS_ALIAS_PRODUTO = ['cs' => 'Villagio Carmen Steffens', 'carmensteffens' => 'Villagio Carmen Steffens', 'terrasdesantamaria' => 'Santa Maria VF', 'terrenosnarepresa' => 'Terreno Da Represa', 'terrenonarepresa' => 'Terreno Da Represa', 'liveonstudios' => 'LiveOn', 'liveon' => 'LiveOn'];
function vsProdutoEm($clientId, $txt) {
    $q = vsC($txt); if ($q === '' || !$clientId) return null;
    $ps = (array)(sd_supabase('/rest/v1/products?client_id=eq.' . urlencode($clientId) . '&select=id,name&limit=120')['body'] ?? []);
    foreach ($ps as $p) if (vsC($p['name']) === $q) return $p;
    if (isset(VS_ALIAS_PRODUTO[$q])) foreach ($ps as $p) if (vsC($p['name']) === vsC(VS_ALIAS_PRODUTO[$q])) return $p;
    foreach ($ps as $p) { $n = vsC($p['name']); if ($n !== '' && (strpos($n, $q) !== false || strpos($q, $n) !== false)) return $p; }
    return null;
}
function vsResolver($cli, $prod) {
    $av = []; $c = iaCliente($cli); $p = null; $espera = false; $motivo = '';
    if (!$c && $prod !== '' && ($c2 = iaCliente($prod))) { $c = $c2; $av[] = "\"$prod\" é cliente à parte na PD (não produto de $cli)"; $prod = $c2['name']; }
    if (!$c) { $espera = true; $motivo = "cliente \"$cli\" ainda não cadastrado na PD"; $av[] = $motivo; return ['client' => null, 'product' => null, 'espera' => true, 'motivo' => $motivo, 'avisos' => $av]; }
    if ($prod !== '') { $p = vsProdutoEm($c['id'], $prod);
        if (!$p) { $r = sd_supabase('/rest/v1/products', 'POST', ['client_id' => $c['id'], 'name' => mb_substr($prod, 0, 120)], ['Prefer: return=representation']);
            if (($r['code'] ?? 500) < 300 && !empty($r['body'][0]['id'])) { $p = $r['body'][0]; $av[] = "criei o produto \"$prod\" em {$c['name']}"; } else $av[] = "produto \"$prod\" não existe em {$c['name']} e não consegui criar"; } }
    return ['client' => $c, 'product' => $p, 'espera' => false, 'motivo' => '', 'avisos' => $av];
}

// ---------------------------------------------------------------- criar / listar / liberar séries
function vsDataBR($v) { $v = trim((string)$v); if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $v, $m)) return "$m[1]-$m[2]-$m[3]"; if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})~', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); return null; }
function vsCriar($args, $u) {
    if (vsSemTabela()) return ['texto' => '⚠️ falta rodar o SQL v168 no Supabase (sql/migration_v168_series_vistoria.sql). Peça ao Sérgio.', 'ids' => []];
    $itens = array_slice((array)($args['itens'] ?? []), 0, 40); $desc = trim((string)($args['descricao'] ?? '')); $out = []; $n = 0; $pend = [];
    $cfg = vsConfig(); if (!empty($args['planilha'])) { $pc = vsPlanilhaConfigurar($args['planilha']); $out[] = $pc['ok'] ? '📎 planilha de gestão ligada (' . count($pc['abas']) . ' abas)' : '⚠️ planilha: ' . $pc['erro']; $cfg = vsConfig(); }
    foreach ($itens as $it) { $it = (array)$it; $cli = trim((string)($it['cliente'] ?? '')); $prod = trim((string)($it['produto'] ?? '')); $titulo = trim((string)($it['titulo'] ?? $args['titulo'] ?? '')) ?: 'Vistoria semanal das redes sociais';
        if ($cli === '') { $out[] = '⚠️ item sem cliente'; continue; }
        $rs = vsResolver($cli, $prod); $resp = !empty($it['resp'] ?? $args['resp'] ?? '') ? iaRespOk(iaResp($it['resp'] ?? $args['resp'])) : null;
        $espera = !empty($it['em_espera']) || $rs['espera']; $motivo = trim((string)($it['motivo'] ?? '')) ?: $rs['motivo'];
        $ini = vsDataBR($it['inicio'] ?? $args['inicio'] ?? '') ?: vsJanela()[0];
        $row = ['tipo' => 'vistoria_social', 'client_id' => $rs['client']['id'] ?? null, 'cliente_nome' => $cli, 'product_id' => $rs['product']['id'] ?? null, 'produto_nome' => $prod, 'resp' => $resp ?: strtoupper((string)($u['team_key'] ?? '')) ?: null,
            'titulo' => $titulo, 'obs' => trim((string)($it['obs'] ?? '')) ?: $desc, 'dow' => 5, 'prazo_dias' => 6, 'inicio' => $ini, 'em_espera' => $espera, 'motivo_espera' => $espera ? ($motivo ?: 'aguardando liberação de acesso') : null, 'ativa' => true, 'criado_por' => $u['id'] ?? null];
        $ja = sd_supabase('/rest/v1/pd_series?ativa=is.true&cliente_nome=eq.' . urlencode($cli) . '&produto_nome=eq.' . urlencode($prod) . '&titulo=eq.' . urlencode($titulo) . '&select=id&limit=1')['body'][0] ?? null;
        $r = $ja ? sd_supabase('/rest/v1/pd_series?id=eq.' . $ja['id'], 'PATCH', $row) : sd_supabase('/rest/v1/pd_series', 'POST', $row);
        if (($r['code'] ?? 500) >= 300) { $out[] = "⚠️ $cli / $prod: o banco recusou — " . mb_substr((string)($r['body']['message'] ?? ''), 0, 100); continue; }
        $n++; $out[] = ($espera ? '⏸️' : '✅') . " $cli" . ($prod ? " / $prod" : '') . ' → ' . ($resp ?: ($row['resp'] ?: 'sem responsável')) . ($espera ? ' · em espera (' . $row['motivo_espera'] . ')' : ' · toda sexta, prazo quinta') . ($ja ? ' (atualizada)' : '') . ($rs['avisos'] ? ' — ' . implode('; ', $rs['avisos']) : '');
    }
    $g = vsGerar(); if ($g['criadas']) $out[] = '🆕 ' . $g['criadas'] . ' tarefa(s) da semana atual já criada(s): ' . implode(', ', $g['ids']);
    if (!$cfg || empty($cfg['planilha_id'])) $out[] = '❓ SEM_PLANILHA: ainda não tenho o link da planilha de gestão. Peça ao usuário o link e chame serie_semanal op=planilha.';
    return ['texto' => implode("\n", $out) ?: '⚠️ nada criado', 'ids' => $g['ids'] ?? []];
}
function vsListar() {
    $r = (array)(sd_supabase('/rest/v1/pd_series?ativa=is.true&select=*&order=created_at.asc&limit=100')['body'] ?? []); $cfg = vsConfig();
    $t = array_map(fn($s) => ($s['em_espera'] ? '⏸️' : '▶️') . " {$s['cliente_nome']}" . ($s['produto_nome'] ? " / {$s['produto_nome']}" : '') . " · {$s['resp']} · " . ($s['em_espera'] ? 'em espera: ' . $s['motivo_espera'] : 'última semana gerada ' . ($s['ultima_semana'] ? vsBR($s['ultima_semana']) : 'nenhuma')) . " [{$s['id']}]", $r);
    return ['texto' => ($t ? implode("\n", $t) : 'nenhuma série ativa') . "\nplanilha: " . ($cfg['planilha_url'] ?? 'não configurada'), 'ids' => []];
}
/** op liberar/pausar/encerrar — casa por cliente (+produto) ou por id. */
function vsMudar($op, $args) {
    $cli = trim((string)($args['cliente'] ?? '')); $prod = trim((string)($args['produto'] ?? '')); $id = trim((string)($args['id'] ?? ''));
    $q = '/rest/v1/pd_series?ativa=is.true&select=id,cliente_nome,produto_nome' . ($id ? '&id=eq.' . urlencode($id) : ($cli ? '&cliente_nome=ilike.' . rawurlencode('*' . $cli . '*') : '')) . ($prod ? '&produto_nome=ilike.' . rawurlencode('*' . $prod . '*') : '');
    if (!$id && !$cli) return ['texto' => '⚠️ diga o cliente (e produto) ou o id da série', 'ids' => []];
    $ss = (array)(sd_supabase($q)['body'] ?? []); if (!$ss) return ['texto' => '⚠️ não achei série com esse cliente/produto', 'ids' => []];
    $upd = $op === 'liberar' ? ['em_espera' => false, 'motivo_espera' => null] : ($op === 'pausar' ? ['em_espera' => true, 'motivo_espera' => trim((string)($args['motivo'] ?? '')) ?: 'pausada'] : ['ativa' => false]);
    foreach ($ss as $s) { sd_supabase('/rest/v1/pd_series?id=eq.' . $s['id'], 'PATCH', $upd); if ($op === 'liberar') sd_supabase('/rest/v1/pd_series?id=eq.' . $s['id'], 'PATCH', ['client_id' => null, 'product_id' => null]); }
    $g = $op === 'liberar' ? vsGerar() : ['ids' => [], 'criadas' => 0];
    return ['texto' => '✅ ' . $op . ': ' . implode(', ', array_map(fn($s) => $s['cliente_nome'] . ($s['produto_nome'] ? ' / ' . $s['produto_nome'] : ''), $ss)) . ($g['criadas'] ? ' · tarefa da semana criada: ' . implode(', ', $g['ids']) : ''), 'ids' => $g['ids']];
}

// ---------------------------------------------------------------- geração semanal (o relógio chama; idempotente)
function vsGerar() {
    $r = sd_supabase('/rest/v1/pd_series?ativa=is.true&em_espera=is.false&select=*&limit=200'); if (($r['code'] ?? 500) >= 300) return ['criadas' => 0, 'ids' => []];
    [$ini, $fim] = vsJanela(); $ids = []; $cfg = vsConfig(); $aba = null;
    if ($cfg && !empty($cfg['planilha_id'])) { $nm = vsPlanilhaLer($cfg['planilha_id'], true); $aba = vsAbaDaSemana(array_keys($nm['abas'] ?? []), $ini); }
    foreach ((array)$r['body'] as $s) {
        if (!empty($s['inicio']) && $s['inicio'] > $fim) continue; if (($s['ultima_semana'] ?? '') === $ini) continue;
        if (empty($s['client_id'])) { $rs = vsResolver((string)$s['cliente_nome'], (string)$s['produto_nome']);   // o cliente pode ter sido cadastrado/renomeado depois do pedido
            if (!$rs['client']) continue; $s['client_id'] = $rs['client']['id']; $s['product_id'] = $rs['product']['id'] ?? null; sd_supabase('/rest/v1/pd_series?id=eq.' . $s['id'], 'PATCH', ['client_id' => $s['client_id'], 'product_id' => $s['product_id']]); }
        $nid = sd_supabase('/rest/v1/rpc/next_task_id', 'POST', (object)[]); $id = is_string($nid['body'] ?? null) ? trim($nid['body'], '"') : null; if (!$id) continue;
        $resp = $s['resp'] ?: 'SERGIO';
        $obs = trim(($s['obs'] ?? '') . "\n\n" . 'Semana ' . vsBR($ini) . ' a ' . vsBR($fim) . ($aba ? ' · aba "' . $aba . '"' : '') . ' · bloco: ' . $s['cliente_nome'] . ($s['produto_nome'] ? ' — ' . $s['produto_nome'] : '') . ($cfg['planilha_url'] ?? '' ? "\nPlanilha: " . $cfg['planilha_url'] : ''));
        $t = ['id' => $id, 'action' => mb_substr($s['titulo'] . ' — ' . vsBR($ini) . ' a ' . vsBR($fim), 0, 160), 'status' => 'EM ANDAMENTO', 'resp' => $resp, 'project' => 'SOCIAL', 'week' => 'ESTA SEMANA', 'date_int' => $fim, 'date_cli' => $fim, 'obs' => mb_substr($obs, 0, 2000), 'created_by' => $s['criado_por']];
        if ($s['client_id']) $t['client_id'] = $s['client_id']; if ($s['product_id']) $t['product_id'] = $s['product_id'];
        $ins = sd_supabase('/rest/v1/tasks', 'POST', $t, ['Prefer: return=representation']); if (($ins['code'] ?? 500) >= 300) continue;
        sd_supabase('/rest/v1/pd_series?id=eq.' . $s['id'], 'PATCH', ['ultima_semana' => $ini, 'ultima_task' => $id]);
        try { pdEvento($id, 'criacao', 'Criada pela série semanal (vistoria de social)', null, 'Série semanal', 'robo'); } catch (Throwable $e) {}
        $ids[] = $id;
    }
    // uma notificação só por responsável, mesmo com muitas tarefas
    $por = []; foreach ($ids as $i) { $x = sd_supabase('/rest/v1/tasks?id=eq.' . $i . '&select=resp,action,clients(name)')['body'][0] ?? null; if ($x) $por[$x['resp']][] = $i; }
    foreach ($por as $rp => $l) { try { pdNotificarResp($rp, 'task_new', '📋 Vistoria da semana criada', count($l) . ' tarefa(s) de vistoria de ' . vsBR($ini) . ' a ' . vsBR($fim) . ': ' . implode(', ', $l) . '. Prazo: quinta ' . vsBR($fim) . '.', 'task:' . $l[0], null, $l[0]); } catch (Throwable $e) {} }
    return ['criadas' => count($ids), 'ids' => $ids];
}

// ---------------------------------------------------------------- lembrete diário (lê a planilha da semana)
function vsLembrar($forcar = false) {
    $h = vsHoje(); if (!$forcar && ((int)$h->format('G') < 9 || (int)$h->format('N') > 5)) return ['enviados' => 0, 'motivo' => 'fora do horário'];
    $cfg = vsConfig(); if (!$cfg || empty($cfg['planilha_id'])) return ['enviados' => 0, 'motivo' => 'sem planilha'];
    [$ini, $fim] = vsJanela(); $hoje = $h->format('Y-m-d');
    $ss = (array)(sd_supabase('/rest/v1/pd_series?ativa=is.true&em_espera=is.false&ultima_semana=eq.' . $ini . '&select=*')['body'] ?? []); if (!$ss) return ['enviados' => 0, 'motivo' => 'sem séries nesta semana'];
    $nm = vsPlanilhaLer($cfg['planilha_id'], true); $aba = vsAbaDaSemana(array_keys($nm['abas'] ?? []), $ini);
    if (!$aba) { if ((int)$h->format('G') === 9 && (int)$h->format('i') < 10) { try { pdNotificarResp('SERGIO', 'task_new', '⚠️ Planilha de vistoria sem a aba da semana', 'A planilha não tem aba para a semana de ' . vsBR($ini) . ' a ' . vsBR($fim) . '. Crie a aba nova (duplique a última) para a Camila preencher.', null, null, null); } catch (Throwable $e) {} }
        return ['enviados' => 0, 'motivo' => 'a planilha não tem aba para a semana de ' . vsBR($ini) . ' (crie a aba nova)', 'aviso_aba' => true]; }
    $lin = vsPlanilhaLer($cfg['planilha_id'], false, $aba)['abas'][$aba] ?? []; $bl = vsBlocos($lin); $por = [];
    foreach ($ss as $s) { if (($s['ultimo_lembrete'] ?? '') === $hoje && !$forcar) continue; $lab = vsAcharBloco($s, $bl); $por[$s['resp'] ?: 'SERGIO'][] = [$s, $lab, $lab ? vsSituacao($bl[$lab]) : null]; }
    $env = 0;
    foreach ($por as $rp => $lista) {
        $abertas = []; $feitas = 0; $nome = ucfirst(strtolower($rp));
        foreach ($lista as [$s, $lab, $sit]) { $rot = $s['cliente_nome'] . ($s['produto_nome'] ? ' — ' . $s['produto_nome'] : '');
            if (!$sit) { $abertas[] = "• $rot: não achei esse bloco na aba \"$aba\" da planilha"; continue; }
            if (!$sit['faltam'] && !$sit['ajustar']) { $feitas++; continue; }
            $abertas[] = '• ' . $rot . ($sit['faltam'] ? ' (faltam ' . count($sit['faltam']) . ')' : '') . ($sit['ajustar'] ? ' [ajustar: ' . implode('; ', array_slice($sit['ajustar'], 0, 2)) . ']' : ''); }
        if (!$abertas) { $corpo = "✅ Vistoria de " . vsBR($ini) . " a " . vsBR($fim) . ": tudo preenchido na planilha. Conclua as tarefas na PD."; }
        else $corpo = "Bom dia, $nome! Vistoria " . vsBR($ini) . ' a ' . vsBR($fim) . ": $feitas de " . count($lista) . " blocos prontos, prazo quinta " . vsBR($fim) . ". Planilha: " . ($cfg['planilha_url'] ?? '') . "\nFalta: " . implode(' ', array_slice($abertas, 0, 12));
        try { pdNotificarResp($rp, 'task_new', '📋 Vistoria semanal de social', $corpo, 'task:' . ($lista[0][0]['ultima_task'] ?? ''), null, $lista[0][0]['ultima_task'] ?? null); $env++; } catch (Throwable $e) {}
        foreach ($lista as [$s]) sd_supabase('/rest/v1/pd_series?id=eq.' . $s['id'], 'PATCH', ['ultimo_lembrete' => $hoje]);
    }
    return ['enviados' => $env, 'aba' => $aba];
}

/** Ponto de entrada das ferramentas (chat da plataforma e zap). */
function iaSerieSemanal($args, $u) {
    $op = strtolower(trim((string)($args['op'] ?? 'criar')));
    if (in_array($op, ['criar', 'listar', 'liberar', 'pausar', 'encerrar'], true) && vsSemTabela()) return ['texto' => '⚠️ falta rodar o SQL v168 no Supabase (sql/migration_v168_series_vistoria.sql). Peça ao Sérgio.', 'ids' => []];
    switch ($op) {
        case 'criar':   return vsCriar($args, $u);
        case 'listar':  return vsListar();
        case 'liberar': case 'pausar': case 'encerrar': return vsMudar($op, $args);
        case 'planilha': $r = vsPlanilhaConfigurar($args['link'] ?? $args['planilha'] ?? ''); return ['texto' => $r['ok'] ? '📎 planilha de gestão ligada: ' . count($r['abas']) . ' abas (' . implode(', ', array_slice($r['abas'], 0, 3)) . '…)' : '⚠️ ' . $r['erro'], 'ids' => []];
        case 'lembrar_agora': $r = vsLembrar(true); return ['texto' => 'lembrete: ' . json_encode($r, JSON_UNESCAPED_UNICODE), 'ids' => []];
    }
    return ['texto' => '⚠️ op desconhecida (criar, listar, liberar, pausar, encerrar, planilha, lembrar_agora)', 'ids' => []];
}
