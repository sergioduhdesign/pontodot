<?php
// =====================================================================
// pontodot — FUNDOS DO APP (celular)
//   GET /pd-fundos.php?ia=<id>   → álbuns disponíveis:
//     · SÓ "ia": as fotos da IA da pessoa (nada de imagem genérica)
//       ROTINA: na pasta do personagem (01_Nix/) o GPT solta *_vida_<data>.png e *_nascimento.png; pra fundo sob medida, nomeie
//       com 'mobile' (vertical) ou 'desktop' (horizontal) no nome ou numa subpasta (01_Nix/mobile/…). O mais novo entra primeiro.
//     (antes:) "ia"  : as fotos da IA da pessoa (Drive: pasta dos personagens › <id> ›
//               subpasta "mobile"; sem ela, as fotos de vida/avatar dela)
//     · outros: cada SUBPASTA da pasta de fundos no Drive é um álbum
//               (padrão: "Pontodot - Mobile 9x16 - Selfies Moda Podcasts";
//               troque com PD_PASTA_FUNDOS no sd-config.php)
//     · e, se existirem no servidor: pd/fundos/<álbum>/ e pd/personagens/<id>/mobile/
//   As imagens saem pelo seu domínio (arte.php, assinado). Nunca drive.google.com.
//   A lista do Drive fica em disco por 6 h; se a Ponte cair, vale a última boa.
// =====================================================================
require_once __DIR__ . '/../sd-private/sd-config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

@set_time_limit(80);
$PDF_AVISO = '';
const PDF_CACHE = __DIR__ . '/.cache-pd';
const PDF_VALIDADE = 21600;
const PDF_MAX_POR_ALBUM = 40;
const PDF_MAX_BYTES = 25000000;
$raiz = __DIR__;

function pdf_assina($id) { return substr(hash_hmac('sha256', 'arte:' . $id, SD_SECRET_KEY), 0, 24); }
function pdf_ponte($acao, $dados) {
    if (!defined('SD_PONTE_URL') || !defined('SD_PONTE_KEY') || !SD_PONTE_KEY) throw new Exception('ponte não configurada');
    $dados['acao'] = $acao; $dados['key'] = SD_PONTE_KEY;
    $ch = curl_init(SD_PONTE_URL);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($dados),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 70]);
    $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if (!is_array($j) || empty($j['ok'])) throw new Exception('ponte: ' . (is_array($j) ? ($j['erro'] ?? 'erro') : 'sem resposta'));
    return $j;
}
// lê uma pasta do Drive (com cache em disco; se a Ponte cair, devolve a última lista boa)
function pdf_drive($chave, $folderId) {
    if (!is_dir(PDF_CACHE)) { @mkdir(PDF_CACHE, 0755, true); @file_put_contents(PDF_CACHE . '/.htaccess', "Deny from all\n"); }
    $arq = PDF_CACHE . '/fundos-' . $chave . '-s2.json';
    $v = is_file($arq) ? json_decode((string)file_get_contents($arq), true) : null;
    if (is_array($v) && (time() - (int)($v['em'] ?? 0)) < PDF_VALIDADE) return $v['arquivos'];
    try {
        $r = pdf_ponte('listar', ['folderId' => $folderId]);
        $out = [];
        foreach ((array)($r['arquivos'] ?? []) as $f) {
            if (!preg_match('#^image/(jpeg|png|webp)$#', (string)($f['mime'] ?? ''))) continue;
            if ((int)($f['size'] ?? 0) > PDF_MAX_BYTES) continue;
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($f['id'] ?? ''));
            if ($id === '') continue;
            $out[] = ['id' => $id, 'path' => (string)($f['path'] ?? ($f['name'] ?? '')), 'em' => strtotime((string)($f['modified'] ?? '')) ?: 0];
        }
        if ($out) @file_put_contents($arq, json_encode(['em' => time(), 'arquivos' => $out], JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $out;
    } catch (Exception $e) {
        $GLOBALS['PDF_AVISO'] = $e->getMessage();
        return is_array($v) ? ($v['arquivos'] ?? []) : [];
    }
}
function pdf_img($f) {
    $k = pdf_assina($f['id']);
    return ['full' => '/arte.php?i=' . $f['id'] . '&k=' . $k . '&w=1200', 'mini' => '/arte.php?i=' . $f['id'] . '&k=' . $k . '&w=300'];
}
function pdf_nome($s) {   // "01 - Selfies e vida" → "Selfies e vida"
    $s = preg_replace('/^\s*\d+\s*[-_.)]*\s*/', '', $s);
    return trim($s) !== '' ? trim($s) : 'Álbum';
}
function pdf_slug($s) { $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s))); return trim($s, '-') ?: 'album'; }
function pdf_lista($dir, $url) {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.' || !preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
        $out[] = $url . '/' . rawurlencode($f);
    }
    natsort($out);
    return array_values($out);
}
function pdf_ordena(&$arr) { usort($arr, fn($a, $b) => strnatcasecmp($a['path'], $b['path'])); }

$albuns = [];
$id = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['ia'] ?? ''));
$disp = (($_GET['d'] ?? 'm') === 'd') ? 'd' : 'm';   // m = celular (vertical) · d = computador (horizontal)

// ---------- SÓ o álbum da IA da pessoa — e só as fotos do formato do aparelho ----------
if ($id !== '') {
    $imgs = []; $minis = [];
    $pastaP = defined('PD_PASTA_PERSONAGENS') ? PD_PASTA_PERSONAGENS : '';
    if ($pastaP !== '') {
        $fmt = []; $vida = [];
        foreach (pdf_drive('personagens', $pastaP) as $f) {
            $partes = explode('/', $f['path']);
            if (count($partes) < 2) continue;
            $quem = preg_replace('/[^a-z0-9_\-]/', '', preg_replace('/^\d+[\s_\-]*/', '', strtolower(trim($partes[0]))));
            if ($quem !== strtolower($id)) continue;
            $caminho = strtolower($f['path']);
            $ehMob = strpos($caminho, 'mobile') !== false;
            $ehDesk = (bool)preg_match('/desktop|wide|horizontal|paisagem|16x9|cabecalho|cabeçalho|header/', $caminho);
            if (($disp === 'm' && $ehMob) || ($disp === 'd' && $ehDesk && !$ehMob)) $fmt[] = $f;
            elseif (!$ehMob && !$ehDesk && preg_match('/vida|nascimento|cena|fundo/', strtolower(end($partes))) && !preg_match('/avatar/', strtolower(end($partes)))) $vida[] = $f;   // avatar é rosto recortado: não serve de fundo
        }
        $usa = $fmt ?: ($disp === 'd' ? $vida : []);   // celular: só as verticais marcadas 'mobile' (as de vida são horizontais)   // sem fotos do formato: as de vida (o app confere a proporção ao carregar)
        usort($usa, fn($a, $b) => ($b["em"] <=> $a["em"]));
        foreach (array_slice($usa, 0, PDF_MAX_POR_ALBUM) as $f) { $i = pdf_img($f); $imgs[] = $i['full']; $minis[] = $i['mini']; }
    }
    if (!$imgs) {   // servidor local, se existir
        $sub = $disp === 'm' ? 'mobile' : 'desktop';
        $imgs = pdf_lista("$raiz/pd/personagens/$id/$sub", "/pd/personagens/$id/$sub"); $minis = $imgs;
    }
    if ($imgs) $albuns[] = ['id' => 'ia', 'nome' => 'Minha IA', 'imagens' => $imgs, 'miniaturas' => $minis];
}
// ---------- ÁLBUNS DO DRIVE: cada subpasta é um álbum ----------
// os álbuns do Drive (podcast, selfies…) são VERTICAIS: só entram no celular
if ($disp === 'm') {
$pastaF = defined('PD_PASTA_FUNDOS') && PD_PASTA_FUNDOS !== '' ? PD_PASTA_FUNDOS : '102QQvyt76wJJ4Qv0BbLYEcIuVx5ZXJbi';
$porAlbum = [];
foreach (pdf_drive('fundos', $pastaF) as $f) {
    $partes = explode('/', $f['path']);
    $alb = count($partes) > 1 ? $partes[0] : 'Fotos';   // foto solta na raiz vira o álbum "Fotos"
    $porAlbum[$alb][] = $f;
}
ksort($porAlbum, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($porAlbum as $nome => $fs) {
    pdf_ordena($fs);
    $imgs = []; $minis = [];
    foreach (array_slice($fs, 0, PDF_MAX_POR_ALBUM) as $f) { $i = pdf_img($f); $imgs[] = $i['full']; $minis[] = $i['mini']; }
    $albuns[] = ['id' => pdf_slug(pdf_nome($nome)), 'nome' => pdf_nome($nome), 'imagens' => $imgs, 'miniaturas' => $minis];
}

// ---------- servidor local (opcional) ----------
$base = "$raiz/pd/fundos";
if (is_dir($base)) {
    foreach (scandir($base) as $d) {
        if ($d[0] === '.' || !is_dir("$base/$d")) continue;
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', $d);
        $imgs = pdf_lista("$base/$d", '/pd/fundos/' . rawurlencode($d));
        if ($imgs) $albuns[] = ['id' => $slug, 'nome' => ucfirst(str_replace(['-', '_'], ' ', $d)), 'imagens' => $imgs, 'miniaturas' => $imgs];
    }
}
}

echo json_encode(['ok' => true, 'albuns' => $albuns] + ($albuns || $PDF_AVISO === '' ? [] : ['aviso' => $PDF_AVISO]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
