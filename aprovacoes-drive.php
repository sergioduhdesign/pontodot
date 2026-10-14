<?php
// =====================================================================
// SD Workflow — AS APROVAÇÕES MUDAM DE CASA
//
// 1.132 artes ocupavam 1,39 GB dos 1,44 GB do plano do Supabase. Elas
// passam a morar no Drive da agência, que tem espaço de sobra.
//
//   POST {acao:"contar"}                 -> quanto ainda falta mover
//   POST {acao:"migrar", quantos:20}     -> move uma leva e GRAVA os links novos
//   POST {acao:"limpar", quantos:50}     -> apaga do Supabase SÓ o que já está no Drive
//   POST {acao:"pasta", cliente, set_id} -> garante a pasta (uso do app)
//   POST {acao:"subir", ...}             -> sobe uma arte nova direto pro Drive
//
// A REGRA QUE NÃO SE NEGOCIA: apagar é um passo separado, e cada arquivo
// é conferido no Drive (existe? tem bytes?) imediatamente antes de sair
// do Supabase. Migrar e apagar na mesma passada é como mudar de casa
// jogando os móveis pela janela antes de ver se o caminhão chegou.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

@set_time_limit(300);

if (!defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) {
    sd_fail('Ponte do Drive não configurada (SD_PONTE_URL / SD_PONTE_KEY).', 500);
}

$me = sd_current_user();
$body = sd_body();
$acao = $body['acao'] ?? 'contar';
// Subir arte nova e garantir a pasta: a EQUIPE toda (antes só admin — e a
// arte de quem é da equipe caía calada no Supabase). Mexer no acervo
// (contar, migrar, limpar) continua só admin.
$daEquipe = in_array($acao, ['pasta', 'subir'], true);
if (!in_array($me['role'], $daEquipe ? ['admin', 'team'] : ['admin'], true)) {
    sd_fail($daEquipe ? 'Só a equipe sobe arte de aprovação.' : 'Só admin mexe no acervo de aprovações.', 403);
}

function ad_ok($d) { header('Content-Type: application/json'); echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }

/** O endereço que o cliente enxerga: o SEU domínio, nunca o Drive.
 *  Assinado — sem a assinatura não abre, e trocar o id na barra não
 *  revela a arte de outro cliente. Mesma conta do arte.php. */
function ad_assina($id) { return substr(hash_hmac('sha256', 'arte:' . $id, SD_SECRET_KEY), 0, 24); }
function ad_mascarar($g) {
    $id = $g['drive_id'] ?? '';
    if (!$id) return $g;
    $k = ad_assina($id);
    $g['url']   = '/arte.php?i=' . rawurlencode($id) . '&k=' . $k . '&w=1800';
    $g['thumb'] = '/arte.php?i=' . rawurlencode($id) . '&k=' . $k . '&w=600';
    $g['original'] = '/arte.php?i=' . rawurlencode($id) . '&k=' . $k . '&w=2400';
    return $g;
}

function ad_gas($acao, $dados = [], $timeout = 280) {
    $dados['acao'] = $acao; $dados['key'] = SD_PONTE_KEY;
    $ch = curl_init(SD_PONTE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($dados, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new Exception('A ponte do Drive não respondeu (HTTP ' . $code . ($err ? ' · ' . $err : ') .'));
    if (empty($j['ok'])) throw new Exception('Drive: ' . ($j['erro'] ?? 'erro desconhecido'));
    return $j;
}

/** Só o que ainda mora no Supabase conta como pendente. */
function ad_no_supabase($m) {
    $u = (string)($m['url'] ?? '');
    return $u && strpos($u, '/storage/v1/object/public/aprovacao/') !== false;
}

/** O caminho dentro do bucket, extraído da URL pública. */
function ad_caminho($url) {
    $p = strpos($url, '/public/aprovacao/');
    return $p === false ? '' : rawurldecode(substr($url, $p + strlen('/public/aprovacao/')));
}

/** Os itens que ainda têm arte no Supabase. */
function ad_itens($limite = 500) {
    $r = sd_supabase('/rest/v1/approval_items?select=id,set_id,media,title&media=not.is.null&order=created_at.asc&limit=' . (int)$limite);
    if ($r['code'] !== 200) throw new Exception('Não consegui ler approval_items (HTTP ' . $r['code'] . ')');
    $out = [];
    foreach (($r['body'] ?? []) as $it) {
        $media = is_array($it['media']) ? $it['media'] : json_decode((string)$it['media'], true);
        if (!is_array($media)) continue;
        $pend = 0;
        foreach ($media as $m) if (is_array($m) && ad_no_supabase($m)) $pend++;
        if ($pend) $out[] = ['id' => $it['id'], 'set_id' => $it['set_id'] ?? '', 'media' => $media, 'pendentes' => $pend];
    }
    return $out;
}

/** O nome do cliente de um lote, pra montar a pasta. Em cache por lote. */
function ad_cliente($setId) {
    static $cache = [];
    $setId = (string)$setId;
    if (isset($cache[$setId])) return $cache[$setId];
    $nome = 'SEM CLIENTE';
    $r = sd_supabase('/rest/v1/approval_sets?id=eq.' . urlencode($setId) . '&select=client_id,clients(name)');
    if ($r['code'] === 200 && !empty($r['body'][0])) {
        $nome = $r['body'][0]['clients']['name'] ?? $nome;
    }
    return $cache[$setId] = $nome;
}

// ---------------------------------------------------------------- contar
if ($acao === 'contar') {
    $itens = ad_itens(2000);
    $arquivos = 0; $porCliente = [];
    foreach ($itens as $it) {
        $arquivos += $it['pendentes'];
        $c = ad_cliente($it['set_id']);
        $porCliente[$c] = ($porCliente[$c] ?? 0) + $it['pendentes'];
    }
    arsort($porCliente);
    ad_ok(['itens' => count($itens), 'arquivos' => $arquivos, 'por_cliente' => $porCliente]);
}

// ---------------------------------------------------------------- migrar
if ($acao === 'migrar') {
    $quantos = max(1, min(40, (int)($body['quantos'] ?? 15)));   // levas pequenas: o GAS morre aos 6 min
    $itens = ad_itens(400);
    if (!$itens) ad_ok(['acabou' => true, 'migrados' => 0, 'faltam' => 0]);

    // monta a leva, sem estourar o limite de tempo do Apps Script
    $leva = []; $mapa = [];
    foreach ($itens as $it) {
        foreach ($it['media'] as $i => $m) {
            if (!is_array($m) || !ad_no_supabase($m)) continue;
            $ref = $it['id'] . '#' . $i;
            $leva[] = ['ref' => $ref, 'url' => $m['url'], 'nome' => $m['name'] ?? ('arte-' . $i),
                       'cliente' => ad_cliente($it['set_id']), 'set_id' => $it['set_id']];
            $mapa[$ref] = ['item' => $it['id'], 'idx' => $i];
            if (count($leva) >= $quantos) break 2;
        }
    }
    if (!$leva) ad_ok(['acabou' => true, 'migrados' => 0, 'faltam' => 0]);

    $r = ad_gas('aprov_puxar', ['arquivos' => $leva]);

    // grava os links novos item por item, preservando o resto do media
    $porItem = []; $erros = []; $bytes = 0;
    foreach (($r['migrados'] ?? []) as $g) {
        $ref = $g['ref'] ?? '';
        if (!isset($mapa[$ref])) continue;
        if (!empty($g['erro'])) { $erros[] = $ref . ': ' . $g['erro']; continue; }
        $porItem[$mapa[$ref]['item']][$mapa[$ref]['idx']] = $g;
        $bytes += (int)($g['bytes'] ?? 0);
    }

    $gravados = 0;
    foreach ($porItem as $itemId => $trocas) {
        $atual = null;
        foreach ($itens as $it) if ($it['id'] === $itemId) { $atual = $it['media']; break; }
        if (!is_array($atual)) continue;
        foreach ($trocas as $idx => $g) {
            if (!isset($atual[$idx]) || !is_array($atual[$idx])) continue;
            $g = ad_mascarar($g);
            // guarda o endereço antigo: sem ele não dá pra apagar depois,
            // e não dá pra voltar atrás se algo der errado.
            $atual[$idx]['url_supabase']  = $atual[$idx]['url'];
            $atual[$idx]['path_supabase'] = ad_caminho($atual[$idx]['url']);
            $atual[$idx]['url']      = $g['url'];
            $atual[$idx]['thumb']    = $g['thumb'];
            $atual[$idx]['original'] = $g['original'];
            $atual[$idx]['drive_id'] = $g['drive_id'];
            $atual[$idx]['source']   = 'drive';
        }
        $u = sd_supabase('/rest/v1/approval_items?id=eq.' . urlencode($itemId), 'PATCH', ['media' => $atual]);
        if ($u['code'] < 300) $gravados += count($trocas);
        else $erros[] = $itemId . ': não gravei o media novo (HTTP ' . $u['code'] . ')';
    }

    $restam = ad_itens(2000);
    $faltam = 0; foreach ($restam as $it) $faltam += $it['pendentes'];
    ad_ok(['migrados' => $gravados, 'faltam' => $faltam, 'mb' => round($bytes / 1048576, 1),
           'erros' => array_slice($erros, 0, 8), 'acabou' => $faltam === 0]);
}

// ---------------------------------------------------------------- limpar
if ($acao === 'limpar') {
    $quantos = max(1, min(100, (int)($body['quantos'] ?? 50)));
    // candidatos: já têm drive_id E ainda guardam o caminho velho
    $r = sd_supabase('/rest/v1/approval_items?select=id,media&media=not.is.null&limit=2000');
    if ($r['code'] !== 200) sd_fail('Não consegui ler os itens.', 500);

    $cands = [];
    foreach (($r['body'] ?? []) as $it) {
        $media = is_array($it['media']) ? $it['media'] : json_decode((string)$it['media'], true);
        if (!is_array($media)) continue;
        foreach ($media as $i => $m) {
            if (!is_array($m) || empty($m['drive_id']) || empty($m['path_supabase'])) continue;
            $cands[] = ['item' => $it['id'], 'idx' => $i, 'drive_id' => $m['drive_id'],
                        'path' => $m['path_supabase'], 'media' => $media];
            if (count($cands) >= $quantos) break 2;
        }
    }
    if (!$cands) ad_ok(['apagados' => 0, 'acabou' => true, 'nada' => 'Não há arquivo com cópia confirmada esperando limpeza.']);

    // CONFERE NO DRIVE ANTES. Um arquivo que não está lá não sai daqui.
    $conf = ad_gas('aprov_conferir', ['ids' => array_values(array_unique(array_column($cands, 'drive_id')))]);
    $estado = $conf['conferidos'] ?? [];

    // Separa quem tem cópia boa no Drive de quem não tem.
    $prontos = []; $pulados = [];
    foreach ($cands as $c) {
        $e = $estado[$c['drive_id']] ?? null;
        if (!$e || empty($e['existe']) || (int)($e['bytes'] ?? 0) <= 0) {
            $pulados[] = $c['path'] . ' (a cópia no Drive não confere)'; continue;
        }
        $prontos[] = $c;
    }

    // APAGAR EM LOTE, pela via própria do Storage.
    //
    // A versão anterior chamava DELETE num arquivo por vez com cabeçalho
    // dizendo "vai JSON" e corpo vazio — e o servidor devolvia 400 em todos.
    // Esta rota recebe a lista no corpo (que é o que o cabeçalho promete) e,
    // melhor ainda, RESPONDE QUAIS saíram. Assim eu não deduzo pelo código
    // de resposta: eu confiro nome por nome o que o servidor confirmou.
    $apagados = 0; $porItem = [];
    foreach (array_chunk($prontos, 50) as $lote) {
        $caminhos = array_values(array_unique(array_column($lote, 'path')));
        $del = sd_supabase('/storage/v1/object/aprovacao', 'DELETE',
                           ['prefixes' => $caminhos],
                           ['Authorization: Bearer ' . SD_SECRET_KEY]);

        if ($del['code'] >= 300) {
            foreach ($lote as $c) $pulados[] = $c['path'] . ' (lote HTTP ' . $del['code']
                . (is_string($del['raw']) ? ' · ' . substr(strip_tags($del['raw']), 0, 90) : '') . ')';
            continue;
        }
        // O que o servidor diz que apagou. Só isso conta.
        $saiu = [];
        foreach ((array)($del['body'] ?? []) as $o) {
            if (is_array($o) && !empty($o['name'])) $saiu[$o['name']] = true;
        }
        foreach ($lote as $c) {
            if (!isset($saiu[$c['path']])) { $pulados[] = $c['path'] . ' (o servidor não confirmou)'; continue; }
            $porItem[$c['item']] = $porItem[$c['item']] ?? $c['media'];
            unset($porItem[$c['item']][$c['idx']]['path_supabase']);   // limpo = não volta na próxima rodada
            $apagados++;
        }
    }
    foreach ($porItem as $itemId => $media) {
        sd_supabase('/rest/v1/approval_items?id=eq.' . urlencode($itemId), 'PATCH', ['media' => array_values($media)]);
    }
    ad_ok(['apagados' => $apagados, 'pulados' => array_slice($pulados, 0, 8), 'acabou' => $apagados === 0]);
}

// ------------------------------------------------------- pasta  /  subir
if ($acao === 'pasta') {
    ad_ok(ad_gas('aprov_pasta', ['cliente' => $body['cliente'] ?? '', 'set_id' => $body['set_id'] ?? '']));
}
if ($acao === 'subir') {
    $dados = (string)($body['dados'] ?? '');
    if (preg_match('/^data:[^,]+,/', $dados)) $dados = preg_replace('/^data:[^,]+,/', '', $dados);
    if (!$dados) sd_fail('Veio sem o arquivo.', 400);
    $g = ad_gas('aprov_subir', ['cliente' => $body['cliente'] ?? '', 'set_id' => $body['set_id'] ?? '',
        'nome' => $body['nome'] ?? 'arquivo', 'mime' => $body['mime'] ?? '', 'dados' => $dados]);
    ad_ok(ad_mascarar($g));
}

sd_fail('Ação desconhecida: ' . $acao, 400);
