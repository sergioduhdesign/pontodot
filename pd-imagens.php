<?php
// =====================================================================
// pontodot — AS PASTAS VIVAS
//
// O GPT alimenta as pastas do Drive toda semana. Este arquivo lista o que
// tem lá e devolve endereços que saem pelo SEU domínio (arte.php), nunca
// drive.google.com. O app sorteia daqui.
//
//   GET /pd-imagens.php?pasta=capas        → as faixas do topo da escola
//   GET /pd-imagens.php?pasta=cartazes     → cartazes de campanha (fonte pronta)
//   GET /pd-imagens.php?pasta=personagens  → a vida VIVA de cada personagem:
//        subpasta por id (nix/, kiro/…); dentro, arquivos cujo nome começa
//        com o tipo (vida…, nascimento…, avatar…). O MAIS NOVO de cada
//        tipo vale — o GPT solta a prova de vida da semana e o site troca.
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
    'capas'       => defined('PD_PASTA_CAPAS')       ? PD_PASTA_CAPAS       : '1KXR2YX_3BOqJDu_ifQ0gzZ6gfav_k_DA',
    'cartazes'    => defined('PD_PASTA_CARTAZES')    ? PD_PASTA_CARTAZES    : '10l5vwNKeD8y-azlpe-3vvcVHfEVrf2zn',
    'personagens' => defined('PD_PASTA_PERSONAGENS') ? PD_PASTA_PERSONAGENS : '',
];
// postais de Mufun: pasta própria (PD_PASTA_POSTAIS) ou, se não houver, uma subpasta
// "postais" (ou arquivo que comece com "postal") dentro da pasta de capas.
$POSTAIS_PROPRIA = defined('PD_PASTA_POSTAIS') && PD_PASTA_POSTAIS !== '';
$PASTAS['postais'] = $POSTAIS_PROPRIA ? PD_PASTA_POSTAIS : $PASTAS['capas'];
function pdi_eh_postal($f) {
    $p = strtolower((string)($f['path'] ?? ($f['name'] ?? '')));
    return (bool)preg_match('#(^|/)(postais?|postal[\s_\-])#', $p);
}
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
if (!isset($PASTAS[$chave])) pdi_sai(['ok' => false, 'erro' => 'pasta desconhecida (capas, cartazes ou personagens)'], 400);
if ($PASTAS[$chave] === '') pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'vazio',
    'aviso' => 'defina PD_PASTA_PERSONAGENS no sd-config.php', 'imagens' => [], 'personagens' => (object)[]]);

if (!is_dir(PDI_CACHE)) { @mkdir(PDI_CACHE, 0755, true); @file_put_contents(PDI_CACHE . '/.htaccess', "Deny from all\n"); }
$arqCache = PDI_CACHE . '/' . $chave . '-s2.json';
$velho = is_file($arqCache) ? json_decode((string)file_get_contents($arqCache), true) : null;

if (is_array($velho) && (time() - (int)($velho['em'] ?? 0)) < PDI_VALIDADE) {
    pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'cache', 'atualizado' => date('c', (int)$velho['em']),
        'imagens' => $velho['imagens'], 'personagens' => $velho['personagens'] ?? (object)[]]);
}

try {
    $r = pdi_ponte('listar', ['folderId' => $PASTAS[$chave]]);

    // ----------------- personagens: subpasta = id, o MAIS NOVO de cada tipo vale
    if ($chave === 'personagens') {
        $vivas = [];   // id => tipo => ['src','src_m','em']
        foreach ((array)($r['arquivos'] ?? []) as $f) {
            if (!preg_match('#^image/(jpeg|png|webp)$#', (string)($f['mime'] ?? ''))) continue;
            if ((int)($f['size'] ?? 0) > PDI_MAX_BYTES) continue;
            $path  = (string)($f['path'] ?? '');
            $parte = explode('/', $path);
            if (count($parte) < 2) continue;                              // precisa da subpasta
            // a subpasta pode vir numerada e com maiúscula ("01_Nix") — o id é só o nome
            $quem = preg_replace('/^\d+[\s_\-]*/', '', strtolower(trim($parte[0])));
            $quem = preg_replace('/[^a-z0-9_\-]/', '', $quem);
            // o tipo pode estar em QUALQUER lugar do nome do arquivo
            // ("Nix_vida_2026-10-14.png", "Nix_prova-de-vida.png", "avatar.jpg")
            $nome = strtolower((string)end($parte));
            $tipo = '';
            foreach (['nascimento', 'avatar', 'capa', 'vida'] as $t) {
                if (strpos($nome, $t) !== false) { $tipo = $t; break; }
            }
            if ($quem === '' || $tipo === '') continue;
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? ''));
            $em = strtotime((string)($f['modified'] ?? '')) ?: 0;
            if ($id === '') continue;
            $k = pdi_assina($id);
            $img = ['src' => '/arte.php?i=' . $id . '&k=' . $k . '&w=1200',
                'src_m' => '/arte.php?i=' . $id . '&k=' . $k . '&w=600', 'em' => $em];
            // o ÁLBUM enche: toda vida entra na história, a mais nova na frente
            if ($tipo === 'vida') $vivas[$quem]['album'][] = $img;
            if (!isset($vivas[$quem][$tipo]) || ($vivas[$quem][$tipo]['em'] ?? 0) < $em) $vivas[$quem][$tipo] = $img;
        }
        foreach ($vivas as &$ts) {
            if (!empty($ts['album'])) {
                // UMA vida por SEMANA (segunda a domingo): vale a PRIMEIRA que chegou na semana.
                // Se o GPT soltar uma por dia, o resto da semana fica guardado e a imagem não troca.
                usort($ts['album'], fn($a, $b) => $a['em'] <=> $b['em']);
                $porSemana = [];
                foreach ($ts['album'] as $ai0) { $sem = date('o-W', $ai0['em'] ?: time()); if (!isset($porSemana[$sem])) $porSemana[$sem] = $ai0; }
                $ts['album'] = array_values($porSemana);
                usort($ts['album'], fn($a, $b) => $b['em'] <=> $a['em']);
                $ts['vida'] = $ts['album'][0];                       // a vida atual = a da semana mais recente
                $ts['album'] = array_slice($ts['album'], 0, 24);
                foreach ($ts['album'] as &$ai) { $ai['semana'] = date('d/m', $ai['em'] ?: time()); unset($ai['em']); }
                unset($ai);
            }
            foreach ($ts as $kk => &$v) if ($kk !== 'album') unset($v['em']);
            unset($v);
        }
        unset($ts);
        @file_put_contents($arqCache, json_encode(['em' => time(), 'imagens' => [], 'personagens' => $vivas],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'drive', 'atualizado' => date('c'),
            'personagens' => $vivas ?: (object)[]]);
    }

    $imgs = [];
    foreach ((array)($r['arquivos'] ?? []) as $f) {
        $mime = (string)($f['mime'] ?? '');
        if (!preg_match('#^image/(jpeg|png|webp)$#', $mime)) continue;   // nada de SVG, PDF ou o que cair lá por engano
        if ((int)($f['size'] ?? 0) > PDI_MAX_BYTES) continue;
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? ''));
        if (!$id) continue;
        if (!$POSTAIS_PROPRIA) {                                   // mesma pasta: separa pelo nome/subpasta
            if ($chave === 'postais' && !pdi_eh_postal($f)) continue;
            if ($chave === 'capas'   &&  pdi_eh_postal($f)) continue;
        }
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
    if (is_array($velho) && (!empty($velho['imagens']) || !empty($velho['personagens']))) {
        pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'cache-antigo', 'atualizado' => date('c', (int)$velho['em']),
                 'aviso' => 'Drive fora do ar agora; mostrando a última lista boa.',
                 'imagens' => $velho['imagens'] ?? [], 'personagens' => $velho['personagens'] ?? (object)[]]);
    }
    pdi_sai(['ok' => true, 'pasta' => $chave, 'fonte' => 'vazio', 'aviso' => $e->getMessage(), 'imagens' => [], 'personagens' => (object)[]]);
}
