<?php
// =====================================================================
// pontodot — AS PASTAS VIVAS
//
// O GPT alimenta as pastas do Drive toda semana. Este arquivo lista o que
// tem lá e devolve endereços que saem pelo SEU domínio (arte.php), nunca
// drive.google.com. O app sorteia daqui.
//
//   GET /pd-imagens.php?pasta=capas      → as faixas do topo da escola
//   GET /pd-imagens.php?pasta=cartazes   → cartazes de campanha (fonte pronta)
//
// Não mexe no Apps Script: usa a ação "listar" que a Ponte já tem.
// Guarda a lista em disco por 6 horas. Se a Ponte cair, devolve a última
// lista boa; se nunca teve lista, devolve vazio — e o app usa as cenas que
// vêm embutidas no site. A janela nunca fica sem imagem.
//
// ORDEM: um número no nome manda ("1-aula", "aula-2", "03 formatura").
// Sem número vai depois, em ordem de nome. É assim que o GPT fixa a
// sequência de uma história; o resto entra no sorteio.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

// as pastas são do Sérgio e não são segredo — o que protege é a assinatura
// do arte.php. Dá pra trocar no sd-config.php sem mexer aqui.
$PASTAS = [
    'capas'    => defined('PD_PASTA_CAPAS')    ? PD_PASTA_CAPAS    : '1KXR2YX_3BOqJDu_ifQ0gzZ6gfav_k_DA',
    'cartazes' => defined('PD_PASTA_CARTAZES') ? PD_PASTA_CARTAZES : '10l5vwNKeD8y-azlpe-3vvcVHfEVrf2zn',
];
const PDI_CACHE    = __DIR__ . '/.cache-pd';
const PDI_VALIDADE = 21600;     // 6 horas: alimentação é semanal, isto basta
const PDI_MAX      = 60;        // teto de imagens por pasta
const PDI_MAX_BYTES = 25000000; // arquivo maior que isso não é capa, é engano

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function pdi_sai($dados, $code = 200) {
    http_response_code($code);
    // o navegador pode guardar 10 min; quem manda de verdade é o cache em disco
    header('Cache-Control: public, max-age=600');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function pdi_assina($id) {      // a MESMA assinatura do arte.php
    return substr(hash_hmac('sha256', 'arte:' . $id, SD_SECRET_KEY), 0, 24);
}
function pdi_ordem($nome) {
    $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $nome);
    if (preg_match('/^\s*(\d{1,4})(?=\D|$)/', $base, $m)) return (int)$m[1];
    if (preg_match('/[-_ ](\d{1,4})\s*$/', $base, $m))    return (int)$m[1];
    return 9999;
}
function pdi_ponte($acao, $dados) {
    if (!defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY)
        throw new Exception('ponte não configurada');
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

$chave = preg_replace('/[^a-z]/', '', (string)($_GET['pasta'] ?? ''));
if (!isset($PASTAS[$chave])) pdi_sai(['ok' => false, 'erro' => 'pasta desconhecida (use capas ou cartazes)'], 400);

if (!is_dir(PDI_CACHE)) { @mkdir(PDI_CACHE, 0755, true); @file_put_contents(PDI_CACHE . '/.htaccess', "Deny from all\n"); }
$arqCache = PDI_CACHE . '/' . $chave . '.json';
$velho = is_file($arqCache) ? json_decode((string)file_get_contents($arqCache), true) : null;

if (is_array($velho) && (time() - (int)($velho['em'] ?? 0)) < PDI_VALIDADE) {
    pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'cache', 'atualizado' => date('c', (int)$velho['em']), 'imagens' => $velho['imagens']]);
}

try {
    $r = pdi_ponte('listar', ['folderId' => $PASTAS[$chave]]);
    $imgs = [];
    foreach ((array)($r['arquivos'] ?? []) as $f) {
        $mime = (string)($f['mime'] ?? '');
        if (!preg_match('#^image/(jpeg|png|webp)$#', $mime)) continue;   // nada de SVG, PDF ou o que cair lá por engano
        if ((int)($f['size'] ?? 0) > PDI_MAX_BYTES) continue;
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? ''));
        if (!$id) continue;
        $nome = (string)($f['name'] ?? '');
        $k = pdi_assina($id);
        $imgs[] = [
            'id'    => $id,
            'nome'  => preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $nome),
            'ordem' => pdi_ordem($nome),
            'src'   => '/arte.php?i=' . $id . '&k=' . $k . '&w=1800',
            'src_m' => '/arte.php?i=' . $id . '&k=' . $k . '&w=1200',
        ];
    }
    usort($imgs, function ($a, $b) { return [$a['ordem'], $a['nome']] <=> [$b['ordem'], $b['nome']]; });
    $imgs = array_slice($imgs, 0, PDI_MAX);
    @file_put_contents($arqCache, json_encode(['em' => time(), 'imagens' => $imgs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'drive', 'atualizado' => date('c'), 'imagens' => $imgs]);
} catch (Exception $e) {
    // a Ponte tropeçou: a última lista boa vale mais que lista nenhuma
    if (is_array($velho) && !empty($velho['imagens'])) {
        pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'cache-antigo', 'atualizado' => date('c', (int)$velho['em']),
                 'aviso' => 'Drive fora do ar agora; mostrando a última lista boa.', 'imagens' => $velho['imagens']]);
    }
    pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'vazio', 'aviso' => $e->getMessage(), 'imagens' => []]);
}
