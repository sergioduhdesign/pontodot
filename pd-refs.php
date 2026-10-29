<?php
// Pontodot — Repertório criativo (as 60 referências do GPT + famílias de linguagem + pontos de partida + bancos), por área do Studio.
// Fonte: refs/repertorio.json (no repo) + assets/refs/<ID>.jpg. Equipe/admin. Os ids escolhidos entram nos prompts via pdRepertorioBloco().
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
$u = sd_current_user(); if (!in_array($u['role'], ['admin', 'team'], true)) sd_fail('Só a equipe.', 403);
$b = sd_body(); $acao = (string)($b['acao'] ?? 'listar'); $R = pdRepertorio();
if ($acao === 'listar') { $area = (string)($b['area'] ?? ''); $segs = pdRepertorioSeg($area); $refs = [];
    foreach ($R['referencias'] as $r) { if (!in_array(substr($r['id'], 0, 2), $segs, true)) continue; $img = is_file(__DIR__ . '/assets/refs/' . $r['id'] . '.jpg') ? '/assets/refs/' . $r['id'] . '.jpg' : null;
        $refs[] = ['id' => $r['id'], 'nome' => $r['nome'], 'papel' => $r['papel'], 'obra' => $r['obra'], 'fonte' => $r['fonte'], 'valor' => $r['valor'], 'estrutura' => $r['estrutura'], 'uso' => $r['uso'], 'direcao' => $r['direcao'], 'busca' => $r['busca'], 'evitar' => $r['evitar'], 'teste' => $r['teste'], 'periodo' => $r['periodo'], 'img' => $img]; }
    $fams = []; foreach ($R['familias_de_linguagem'] as $i => $f) if (in_array($area, ['digital', 'grafica'], true) || $area === '') $fams[] = ['id' => 'fam:' . $i, 'nome' => $f[0], 'base' => $f[1], 'desc' => $f[2], 'uso' => $f[3], 'regra' => $f[4]];
    $combs = []; foreach ($R['combinacoes'] as $i => $c) { $ids = array_values(array_filter(preg_split('/\s+/', (string)$c['refs']), fn($x) => in_array(substr($x, 0, 2), $segs, true))); $combs[] = ['id' => 'comb:' . $i, 'nome' => $c['nome'], 'objetivo' => $c['objetivo'], 'briefing' => $c['briefing'], 'direcao' => $c['direcao'], 'entrega' => $c['entrega'] ?? '', 'busca' => $c['busca'] ?? '', 'refs' => $ids]; }
    $bancos = array_map(fn($x) => ['nome' => $x[0], 'tipo' => $x[1], 'url' => $x[2], 'como' => $x[3]], $R['bancos_e_acervos']);
    echo json_encode(['refs' => $refs, 'familias' => $fams, 'combinacoes' => $combs, 'bancos' => $bancos, 'qualidade' => $R['qualidade'] ?? []], JSON_UNESCAPED_UNICODE); exit; }
if ($acao === 'bloco') { echo json_encode(['bloco' => pdRepertorioBloco((array)($b['ids'] ?? []), (string)($b['area'] ?? ''))], JSON_UNESCAPED_UNICODE); exit; }
sd_fail('Ação desconhecida.');
