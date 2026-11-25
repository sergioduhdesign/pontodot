<?php
// PD · TUMI NO GOOGLE MAPS — o scraper próprio (máquina "maps", maps.pontodot.ai) traz TUDO que o Maps publica do local:
// nome, categoria, endereço, telefone, site, e-mail (lido no site), nota, avaliações, horários, fotos, coordenadas, dono, status…
// Config no sd-config.php (servidor): define('SD_MAPS_KEY', '…');  (SD_MAPS_URL é opcional; padrão https://maps.pontodot.ai)
require_once __DIR__ . '/pd-vendas-lib.php';
require_once __DIR__ . '/pd-aula-lib.php';

function mapsCfg() {
    $k = defined('SD_MAPS_KEY') ? trim((string)SD_MAPS_KEY) : '';
    if ($k === '') { try { $k = trim((string)(sd_supabase('/rest/v1/app_settings?id=eq.1&select=maps_key')['body'][0]['maps_key'] ?? '')); } catch (Throwable $e) {} }   // alternativa: coluna app_settings.maps_key
    $u = defined('SD_MAPS_URL') ? rtrim((string)SD_MAPS_URL, '/') : 'https://maps.pontodot.ai';
    return $k !== '' ? ['url' => $u, 'key' => $k] : null;
}
function mapsReq($cfg, $metodo, $path, $corpo = null, $timeout = 30) {
    $ch = curl_init($cfg['url'] . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['X-Key: ' . $cfg['key'], 'Content-Type: application/json'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'raw' => (string)$raw];
}

/** Pedido livre -> termos de busca do Maps + região. Ex.: "clínicas de estética em Campinas" -> ["clínica de estética em Campinas SP"]. */
function mapsPlanejar($consulta) {
    $sys = "Você transforma um pedido de prospecção em buscas do Google Maps. Responda SOMENTE JSON: {\"usa_maps\":true|false,\"termos\":[\"categoria em cidade UF\", ...],\"lugar\":\"cidade/UF principal\"}. "
         . "usa_maps=true quando o pedido é por negócios/lugares físicos de uma região (lojas, clínicas, imobiliárias, construtoras, escritórios, restaurantes, escolas, etc.). false quando é por pessoas, por empresas sem lugar físico/região, ou por sinais de site. "
         . "Gere de 1 a 4 termos, cada um 'categoria + cidade UF' (varie sinônimos e bairros/cidades vizinhas quando o pedido for amplo). Nunca invente cidade que o pedido não pediu.";
    [$txt] = pdClaude($sys, mb_substr((string)$consulta, 0, 1500), 400);
    $j = $txt ? pdJson($txt) : null;
    if (!$j || empty($j['usa_maps']) || empty($j['termos']) || !is_array($j['termos'])) return null;
    return ['termos' => array_slice(array_values(array_filter(array_map(fn($t) => mb_substr(trim((string)$t), 0, 120), $j['termos']))), 0, 4), 'lugar' => (string)($j['lugar'] ?? '')];
}

/** Roda uma busca no scraper e devolve as linhas (cada local com TODOS os campos do Maps). */
function mapsBuscar(array $termos, $max = 20, $emails = true, $esperaSeg = 150) {
    $cfg = mapsCfg(); if (!$cfg) return ['erro' => 'falta SD_MAPS_KEY no sd-config.php'];
    $r = mapsReq($cfg, 'POST', '/api/v1/jobs', ['name' => 'pd-' . date('dHis'), 'keywords' => array_values($termos), 'lang' => 'pt', 'depth' => 1, 'email' => (bool)$emails,
        'max_time' => 180, 'extra_reviews' => false, 'fast_mode' => false]);
    $j = json_decode($r['raw'], true); $id = $j['id'] ?? null;
    if ($r['code'] >= 300 || !$id) return ['erro' => 'o scraper do Maps não aceitou o pedido (HTTP ' . $r['code'] . ')'];
    $fim = time() + $esperaSeg; $st = '';
    while (time() < $fim) {
        sleep(6); $s = mapsReq($cfg, 'GET', '/api/v1/jobs/' . $id); $sj = json_decode($s['raw'], true); $st = strtolower((string)($sj['Status'] ?? ($sj['status'] ?? '')));
        if (in_array($st, ['ok', 'done', 'completed', 'finished'], true)) break;
        if (in_array($st, ['failed', 'error'], true)) return ['erro' => 'o scraper do Maps falhou nessa busca'];
    }
    if (!in_array($st, ['ok', 'done', 'completed', 'finished'], true)) return ['erro' => 'o Maps demorou mais que o limite; tente de novo (a máquina é pequena)', 'job' => $id];
    $d = mapsReq($cfg, 'GET', '/api/v1/jobs/' . $id . '/download', null, 60);
    if ($d['code'] >= 300 || $d['raw'] === '') return ['erro' => 'o Maps terminou, mas não consegui baixar o resultado'];
    return ['ok' => true, 'job' => $id, 'linhas' => mapsCsv($d['raw'], $max)];
}

function mapsCsv($csv, $max) {
    $fp = fopen('php://temp', 'r+'); fwrite($fp, $csv); rewind($fp); $cab = fgetcsv($fp); if (!$cab) return []; $out = [];
    while (($l = fgetcsv($fp)) !== false && count($out) < $max) { if (count($l) < 2) continue; $x = []; foreach ($cab as $i => $c) $x[trim((string)$c)] = $l[$i] ?? ''; $out[] = $x; }
    fclose($fp); return $out;
}

/** Só celular (DDD + 9 dígitos começando em 9) vira zap; fixo fica só no registro do Maps. */
function mapsCelular($t) { $d = preg_replace('/\D/', '', (string)$t); if (strpos($d, '55') === 0 && strlen($d) >= 12) $d = substr($d, 2); return (strlen($d) === 11 && $d[2] === '9') ? $d : ''; }

/** Cidade/UF a partir do endereço do Maps ("Rua X, 10 - Centro, Jundiaí - SP, 13200-000"). */
function mapsCidadeUf($end) {
    if (preg_match('/,\s*([^,\-]+?)\s*-\s*([A-Z]{2})\b/u', (string)$end, $m)) return [trim($m[1]), $m[2]];
    return ['', ''];
}

/** Descobre leads pelo Maps e grava na carteira com o registro COMPLETO do Maps em sinais.MAPS. */
function vdDescobrirMaps($consulta, $quantos, $carteira, $uid) {
    $plano = mapsPlanejar($consulta); if (!$plano) return ['usar_outro' => true];
    $quantos = max(3, min(40, (int)$quantos)); $cart = $carteira && $carteira !== 'todas' ? $carteira : null;
    $b = mapsBuscar($plano['termos'], $quantos * 2, true);
    if (empty($b['ok'])) return ['erro' => $b['erro'] ?? 'Maps indisponível', 'usar_outro' => true];
    $rows = []; $completo = [];
    foreach ($b['linhas'] as $x) {
        $nome = trim((string)($x['title'] ?? '')); if ($nome === '') continue;
        if (stripos((string)($x['status'] ?? ''), 'fechado') !== false || stripos((string)($x['status'] ?? ''), 'closed') !== false) continue;   // fechado definitivamente não é lead
        [$cid, $uf] = mapsCidadeUf($x['address'] ?? ''); $em = ''; foreach (preg_split('/[,;\s]+/', (string)($x['emails'] ?? '')) as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $em = $e; break; }
        $rows[] = ['nome' => $nome, 'site' => $x['website'] ?? '', 'email' => $em, 'zap' => mapsCelular($x['phone'] ?? ''), 'cidade' => $cid, 'uf' => $uf, 'segmento' => $x['category'] ?? ''];
        $completo[mb_strtolower($nome)] = $x;
        if (count($rows) >= $quantos) break;
    }
    if (!$rows) return ['erro' => 'o Maps não trouxe locais para esse pedido', 'usar_outro' => true];
    $lr = vdLinhas(vdSup('/rest/v1/pd_lotes', 'POST', ['nome' => 'Maps ' . date('d/m H:i') . ' · ' . mb_substr($consulta, 0, 60), 'motivo' => $consulta, 'carteira_id' => $cart, 'criado_por' => $uid]));
    $lote = $lr[0]['id'] ?? null;
    $r = vdImportarLinhas($rows, $cart, $lote, $uid, 'maps');
    if (!empty($r['erro'])) return ['erro' => 'não consegui gravar os leads do Maps: ' . $r['erro']];
    if (empty($r['ids'])) return ['erro' => 'tudo que o Maps trouxe já estava na carteira (' . count($rows) . ' achados)', 'duplicados' => $r['duplicados'] ?? 0];
    // guarda o registro completo do Maps (nota, avaliações, horários, fotos, coordenadas, dono…) junto do lead
    $ps = vdLinhas(vdSup('/rest/v1/pd_prospects?lote_id=eq.' . $lote . '&select=id,nome,sinais&limit=200'));
    foreach ($ps as $p) { $x = $completo[mb_strtolower((string)$p['nome'])] ?? null; if (!$x) continue;
        $guarda = array_filter($x, fn($v) => $v !== '' && $v !== null && $v !== '[]' && $v !== '{}');
        foreach (['user_reviews_extended', 'images', 'popular_times'] as $pesado) if (isset($guarda[$pesado]) && strlen((string)$guarda[$pesado]) > 6000) $guarda[$pesado] = mb_substr((string)$guarda[$pesado], 0, 6000);
        $sin = is_array($p['sinais'] ?? null) ? $p['sinais'] : []; $sin['MAPS'] = $guarda;
        $patch = ['sinais' => $sin]; if (($x['review_rating'] ?? '') !== '') $patch['angulo'] = mb_substr('Maps: nota ' . $x['review_rating'] . ' (' . ($x['review_count'] ?? '?') . ' avaliações)' . (!empty($x['category']) ? ' · ' . $x['category'] : ''), 0, 300);
        vdSup('/rest/v1/pd_prospects?id=eq.' . $p['id'], 'PATCH', $patch); }
    foreach (array_slice($r['ids'], 0, 15) as $id) vdEnriquecer($id);   // já lê os sites, como na descoberta de sempre
    return ['ok' => true, 'novos' => $r['novos'], 'duplicados' => $r['duplicados'], 'lote' => $lote];
}
