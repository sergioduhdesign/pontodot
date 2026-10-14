<?php
// =====================================================================
// SD Workflow — AS CARINHAS DA SD
//
// Uma pasta /avatares na raiz, e essa pasta é a verdade: jogou um PNG
// lá dentro, ele aparece pra escolher. Sem cadastro, sem banco, sem
// código novo a cada carinha. Pra tirar, apaga o arquivo.
//
//   POST {acao:"listar"}                    -> o que tem na pasta
//   POST {acao:"subir",  nome, dados}       -> manda a sua (base64)
//   POST {acao:"apagar", arquivo}           -> tira uma das SUAS
//
// As da pasta são de todo mundo e ninguém apaga pelo app. As que a
// pessoa sobe vão pra /avatares/meus/<id-do-usuario>/ e só ela mexe.
//
// SEGURANÇA — um sobe-arquivo na raiz do site é uma porta: aqui ela
// tem tranca. Só entra quem está logado; a imagem é RECODIFICADA pelo
// servidor (o que não for imagem de verdade morre na porta, junto com
// qualquer coisa escondida dentro dela); o nome é sorteado por nós; e
// o .htaccess da pasta proíbe o Apache de executar o que estiver lá.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

const AV_DIR   = __DIR__ . '/avatares';
const AV_WEB   = '/avatares';
const AV_MAX   = 4194304;   // 4 MB de entrada
const AV_LADO  = 512;       // o quadrado final
const AV_TETO  = 60;        // quantas uma pessoa pode ter

$me   = sd_current_user();
$body = sd_body();
$acao = $body['acao'] ?? 'listar';

$meuDir = AV_DIR . '/meus/' . preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$me['id']);
$meuWeb = AV_WEB . '/meus/' . preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$me['id']);

function av_ok($dados) { header('Content-Type: application/json'); echo json_encode(['ok' => true] + $dados); exit; }

/** Impede o Apache de executar qualquer coisa dentro da pasta de imagens. */
function av_trancar($dir) {
    $ht = $dir . '/.htaccess';
    if (is_file($ht)) return;
    @file_put_contents($ht, implode("\n", [
        'php_flag engine off',
        'AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py .sh',
        '<IfModule mod_rewrite.c>',
        '  RewriteEngine Off',
        '</IfModule>',
    ]) . "\n");
}

/** Lê as imagens de uma pasta, mais nova primeiro. */
function av_ler($dir, $web, $minha) {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.') continue;
        $caminho = $dir . '/' . $f;
        if (!is_file($caminho)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) continue;
        // só conta se o servidor reconhecer como imagem (svg não passa por getimagesize)
        if ($ext !== 'svg' && !@getimagesize($caminho)) continue;
        $out[] = [
            'arquivo' => $f,
            'url'     => $web . '/' . rawurlencode($f),
            'nome'    => preg_replace('/[_\-]+/', ' ', pathinfo($f, PATHINFO_FILENAME)),
            'minha'   => $minha,
            'em'      => @filemtime($caminho) ?: 0,
        ];
    }
    usort($out, fn($a, $b) => $b['em'] <=> $a['em']);
    return $out;
}

// ---------------------------------------------------------------- listar
if ($acao === 'listar') {
    if (!is_dir(AV_DIR)) av_ok(['avatares' => [], 'aviso' => 'A pasta /avatares ainda não existe no servidor.']);
    av_trancar(AV_DIR);
    $lista = array_merge(av_ler($meuDir, $meuWeb, true), av_ler(AV_DIR, AV_WEB, false));
    av_ok(['avatares' => $lista, 'total' => count($lista)]);
}

// ----------------------------------------------------------------- subir
if ($acao === 'subir') {
    $bruto = (string)($body['dados'] ?? '');
    if (preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $bruto)) $bruto = preg_replace('/^data:[^,]+,/', '', $bruto);
    $bin = base64_decode($bruto, true);
    if ($bin === false || strlen($bin) < 64) sd_fail('Não consegui ler esse arquivo como imagem.', 400);
    if (strlen($bin) > AV_MAX) sd_fail('Imagem grande demais. O limite é 4 MB.', 400);

    if (!function_exists('imagecreatefromstring')) sd_fail('O servidor está sem a biblioteca de imagens (GD). Peça pro suporte do HostGator ligar a extensão gd.', 500);
    $info = @getimagesizefromstring($bin);
    if (!$info || empty($info[0])) sd_fail('Esse arquivo não é uma imagem válida.', 400);

    $src = @imagecreatefromstring($bin);
    if (!$src) sd_fail('Não consegui abrir essa imagem. Tente PNG ou JPG.', 400);

    // quadrado, centralizado, 512px — e recodificado do zero
    [$w, $h] = [imagesx($src), imagesy($src)];
    $lado = min($w, $h);
    $dst = imagecreatetruecolor(AV_LADO, AV_LADO);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $lado, 2), intdiv($h - $lado, 2), AV_LADO, AV_LADO, $lado, $lado);
    imagedestroy($src);

    if (!is_dir($meuDir) && !@mkdir($meuDir, 0755, true)) sd_fail('Não consegui criar a sua pasta de avatares. Confira a permissão de escrita em /avatares.', 500);
    av_trancar(AV_DIR); av_trancar(AV_DIR . '/meus'); av_trancar($meuDir);

    if (count(av_ler($meuDir, $meuWeb, true)) >= AV_TETO) sd_fail('Você já tem ' . AV_TETO . ' carinhas suas. Apague alguma antes.', 400);

    $limpo = preg_replace('/[^a-z0-9]+/i', '-', pathinfo((string)($body['nome'] ?? 'minha'), PATHINFO_FILENAME));
    $arq   = strtolower(trim(substr($limpo, 0, 24), '-') ?: 'minha') . '-' . bin2hex(random_bytes(5)) . '.png';
    if (!imagepng($dst, $meuDir . '/' . $arq, 6)) { imagedestroy($dst); sd_fail('Não consegui gravar o arquivo.', 500); }
    imagedestroy($dst);
    @chmod($meuDir . '/' . $arq, 0644);

    av_ok(['arquivo' => $arq, 'url' => $meuWeb . '/' . rawurlencode($arq), 'minha' => true]);
}

// ---------------------------------------------------------------- apagar
if ($acao === 'apagar') {
    $arq = basename((string)($body['arquivo'] ?? ''));   // basename corta qualquer ../
    if ($arq === '' || $arq[0] === '.') sd_fail('Arquivo inválido.', 400);
    $alvo = $meuDir . '/' . $arq;                        // só dentro da SUA pasta
    if (!is_file($alvo)) sd_fail('Essa carinha não é sua ou já foi apagada.', 404);
    @unlink($alvo);
    av_ok(['apagado' => $arq]);
}

sd_fail('Ação desconhecida: ' . $acao, 400);
