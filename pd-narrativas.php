<?php
// =====================================================================
// pontodot — AS SEMANAS DE CATUM (o card da vida dela)
//
// O GPT deixa na pasta "narrativas pontodot" um Doc por semana, com o
// nome da segunda-feira (2026-10-12) e um bloco por personagem:
//
//   ## nix
//   titulo: A foto mais esquisita abre a exposição     <- opcional
//   post: Escolhi a foto da minha pata. Sem medo.      <- opcional, 1ª pessoa
//   Nix mostra suas duas fotos a Iro na Galeria...     <- a história
//
//   GET /pd-narrativas.php  →  a semana que JÁ começou + a anterior
//
// A IA lê a mesma pasta direto pela Ponte (Edge Function). Isto aqui é
// só pro site mostrar. Doc de semana futura espera a vez. Guarda 6 horas;
// se a Ponte cair, devolve a última leitura boa; se nunca leu, vazio — e
// o site usa a legenda que vem no cânone. Texto é dado, nunca HTML.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

$PASTA = defined('PD_PASTA_NARRATIVAS') ? PD_PASTA_NARRATIVAS : '1HMpMSo7ZEVWxvKnW0GbEKOHoFw1HYVoO';
const PDN_CACHE    = __DIR__ . '/.cache-pd';
const PDN_VALIDADE = 21600;
const PDN_MAX      = 900;
$IDS = ['nix','kiro','brum','gigi','iro','sago','taro','raku','zumi','oru','koda','tumi','nami','sumi','boku','muru'];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function pdn_sai($d) {
    header('Cache-Control: public, max-age=600');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function pdn_ponte($acao, $dados) {
    if (!defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) throw new Exception('ponte não configurada');
    $dados['acao'] = $acao; $dados['key'] = SD_PONTE_KEY;
    $ch = curl_init(SD_PONTE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($dados),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new Exception('ponte respondeu HTTP ' . $code);
    if (empty($j['ok'])) throw new Exception('ponte: ' . ($j['erro'] ?? 'erro'));
    return $j;
}
function pdn_limpa($t, $max = PDN_MAX) {
    $t = preg_replace('/[\x00-\x1F\x7F]+|\x{2028}|\x{2029}/u', ' ', (string)$t);
    $t = preg_replace('/##\s*ACAO\s*##/i', '', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return mb_substr($t, 0, $max);
}
/** "## nix" (ou "\#\# nix", ou só "nix" na linha) abre o bloco. "titulo:" e "post:" são opcionais. */
function pdn_blocos($texto, $ids) {
    $out = []; $id = ''; $b = null;
    $fecha = function () use (&$out, &$id, &$b) {
        if ($id && $b && !isset($out[$id])) {
            $h = pdn_limpa(implode(' ', $b['h']));
            if ($h || $b['post']) $out[$id] = ['titulo' => pdn_limpa($b['titulo'], 90), 'post' => pdn_limpa($b['post'], 280), 'historia' => $h];
        }
    };
    foreach (preg_split('/\r?\n/', (string)$texto) as $linha) {
        $l = trim($linha);
        if (preg_match('/^(?:\\\\?#){1,3}\s*([A-Za-z]+)\s*:?\s*$/', $l, $m) || in_array(strtolower($l), $ids, true)) {
            $fecha();
            $cand = strtolower($m[1] ?? $l);
            $id = in_array($cand, $ids, true) ? $cand : '';
            $b = ['titulo' => '', 'post' => '', 'h' => []];
            continue;
        }
        if (!$id || $l === '') continue;
        if (preg_match('/^t[ií]tulo\s*:\s*(.+)$/iu', $l, $m)) { $b['titulo'] = $m[1]; continue; }
        if (preg_match('/^post\s*:\s*(.+)$/iu', $l, $m))      { $b['post'] = $m[1]; continue; }
        $b['h'][] = $l;
    }
    $fecha();
    return $out;
}

if (!is_dir(PDN_CACHE)) { @mkdir(PDN_CACHE, 0755, true); @file_put_contents(PDN_CACHE . '/.htaccess', "Deny from all\n"); }
$arq = PDN_CACHE . '/narrativas.json';
$velho = is_file($arq) ? json_decode((string)file_get_contents($arq), true) : null;
$hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

// cache vale 6h — e só se ainda for a mesma semana (virou a segunda, relê)
if (is_array($velho) && (time() - (int)($velho['em'] ?? 0)) < PDN_VALIDADE && ($velho['hoje'] ?? '') === $hoje) {
    pdn_sai(['ok' => true, 'fonte' => 'cache'] + $velho['dados']);
}

try {
    $r = pdn_ponte('listar', ['folderId' => $PASTA]);
    $docs = [];
    foreach ((array)($r['arquivos'] ?? []) as $f) {
        if (!preg_match('/^\s*(\d{4}-\d{2}-\d{2})/', (string)($f['name'] ?? ''), $m)) continue;
        if ($m[1] > $hoje) continue;                                    // semana futura espera a vez
        if (!preg_match('/document|pdf|text/i', (string)($f['mime'] ?? ''))) continue;
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? ''));
        if ($id) $docs[] = ['id' => $id, 'data' => $m[1]];
    }
    usort($docs, function ($a, $b) { return strcmp($b['data'], $a['data']); });
    $docs = array_slice($docs, 0, 2);
    $semanas = [];
    foreach ($docs as $d) {
        $l = pdn_ponte('reuniao_ler', ['doc_id' => $d['id']]);
        $semanas[] = ['data' => $d['data'], 'blocos' => pdn_blocos($l['texto'] ?? '', $IDS)];
    }
    $pers = [];
    foreach ($IDS as $id) {
        $a = $semanas[0]['blocos'][$id] ?? null; $p = $semanas[1]['blocos'][$id] ?? null;
        if ($a || $p) $pers[$id] = ['semana' => $a, 'anterior' => $p];
    }
    $dados = ['semana' => $semanas[0]['data'] ?? null, 'anterior' => $semanas[1]['data'] ?? null, 'personagens' => (object)$pers];
    @file_put_contents($arq, json_encode(['em' => time(), 'hoje' => $hoje, 'dados' => $dados], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    pdn_sai(['ok' => true, 'fonte' => 'drive'] + $dados);
} catch (Exception $e) {
    if (is_array($velho) && !empty($velho['dados'])) pdn_sai(['ok' => true, 'fonte' => 'cache-antigo', 'aviso' => 'Drive fora do ar agora.'] + $velho['dados']);
    pdn_sai(['ok' => true, 'fonte' => 'vazio', 'aviso' => $e->getMessage(), 'semana' => null, 'anterior' => null, 'personagens' => (object)[]]);
}
