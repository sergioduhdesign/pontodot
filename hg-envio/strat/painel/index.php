<?php
// Stratesign 2026 — painel do site: artigos, contatos do diagnóstico e ajustes.
define('STRAT', 1); require dirname(__DIR__) . '/_lib.php';
header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('strat_painel'); session_set_cookie_params(['lifetime' => 0, 'path' => '/painel/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']); session_start();
$cfg = le_json('config.json', []); $msg = ''; $erro = '';
$ip = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|painel'), 0, 24);
function tentativas($ip, $soma = false) { $t = le_json('tentativas.json', []); $ag = time(); foreach ($t as $k => $v) { $t[$k] = array_values(array_filter($v, function ($x) use ($ag) { return $ag - $x < 900; })); if (!$t[$k]) unset($t[$k]); } if ($soma) { $t[$ip][] = $ag; grava_json('tentativas.json', $t); } return count($t[$ip] ?? []); }
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function confere() { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sessão expirada. Volte e tente de novo.'); } }
function vai($p) { header('Location: /painel/' . $p); exit; }
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $acao = (string)($_POST['acao'] ?? '');
$logado = !empty($_SESSION['ok']) && !empty($cfg['senha']) && hash_equals($_SESSION['marca'] ?? '', substr($cfg['senha'], -16));

// ---------- primeiro acesso: define a senha com o código de instalação ----------
if (empty($cfg['senha'])) {
    if ($post && $acao === 'instalar') {
        confere(); $cod = ''; if (is_file(dirname(__DIR__) . '/_setup.php')) $cod = (string)(include dirname(__DIR__) . '/_setup.php');
        $s1 = (string)($_POST['senha'] ?? ''); $s2 = (string)($_POST['senha2'] ?? '');
        if (tentativas($ip) >= 8) $erro = 'Muitas tentativas. Espere 15 minutos.';
        elseif ($cod === '' || !password_verify((string)($_POST['codigo'] ?? ''), $cod)) { tentativas($ip, true); $erro = 'Código de instalação incorreto.'; }
        elseif (mb_strlen($s1) < 10) $erro = 'A senha precisa de pelo menos 10 caracteres.';
        elseif ($s1 !== $s2) $erro = 'As duas senhas não são iguais.';
        else { $cfg['senha'] = password_hash($s1, PASSWORD_DEFAULT); $cfg['criado'] = date('c'); grava_json('config.json', $cfg); session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['marca'] = substr($cfg['senha'], -16); vai(''); }
    }
    tela('Primeiro acesso', function () use ($erro) { ?>
      <form method="post" class="cx"><h1>Primeiro acesso</h1><p>Defina a senha do painel. O código de instalação foi entregue a quem publicou o site e só vale até a senha ser criada.</p>
      <?php if ($erro): ?><p class="er"><?= e($erro) ?></p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="instalar">
      <label>Código de instalação<input type="password" name="codigo" required autocomplete="off"></label>
      <label>Nova senha (10 caracteres ou mais)<input type="password" name="senha" required minlength="10" autocomplete="new-password"></label>
      <label>Repita a senha<input type="password" name="senha2" required minlength="10" autocomplete="new-password"></label>
      <button class="bt">Criar senha e entrar</button></form>
    <?php }, false); exit;
}
// ---------- entrar / sair ----------
if ($post && $acao === 'entrar') {
    confere();
    if (tentativas($ip) >= 8) $erro = 'Muitas tentativas. Espere 15 minutos.';
    elseif (password_verify((string)($_POST['senha'] ?? ''), $cfg['senha'])) { session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['marca'] = substr($cfg['senha'], -16); vai(''); }
    else { tentativas($ip, true); $erro = 'Senha incorreta.'; }
}
if ($post && $acao === 'sair') { confere(); $_SESSION = []; session_destroy(); vai(''); }
if (!$logado) {
    tela('Entrar', function () use ($erro) { ?>
      <form method="post" class="cx"><h1>Painel da Stratesign</h1>
      <?php if ($erro): ?><p class="er"><?= e($erro) ?></p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="entrar">
      <label>Senha<input type="password" name="senha" required autofocus autocomplete="current-password"></label>
      <button class="bt">Entrar</button></form>
    <?php }, false); exit;
}
// ---------- ações ----------
$p = (string)($_GET['p'] ?? 'artigos'); $AUT = ['Marcus Araujo', 'Sérgio Duh', 'Stratesign'];
function sobe_imagem($campo) {
    if (empty($_FILES[$campo]['tmp_name']) || ($_FILES[$campo]['error'] ?? 1) !== UPLOAD_ERR_OK) return [null, ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? '' : 'A imagem não subiu (talvez grande demais).'];
    if ($_FILES[$campo]['size'] > 6 * 1024 * 1024) return [null, 'Imagem acima de 6 MB.'];
    $i = @getimagesize($_FILES[$campo]['tmp_name']); $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$i[2] ?? 0] ?? null;
    if (!$ext) return [null, 'Use imagem JPG, PNG ou WebP.'];
    $dir = dirname(__DIR__) . '/img/artigos'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nome = date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $dir . '/' . $nome)) return [null, 'Não consegui guardar a imagem.'];
    return ['/img/artigos/' . $nome, ''];
}
if ($post && $acao === 'salvar_artigo') {
    confere(); $T = le_json('artigos.json', []); $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? '')); $pos = -1;
    foreach ($T as $k => $x) if (($x['id'] ?? '') === $id && $id !== '') $pos = $k;
    $a = $pos >= 0 ? $T[$pos] : ['id' => bin2hex(random_bytes(6)), 'criado' => date('c')];
    $a['titulo'] = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 160); $a['autor'] = in_array($_POST['autor'] ?? '', $AUT, true) ? $_POST['autor'] : 'Stratesign';
    $a['data'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['data'] ?? '')) ? $_POST['data'] : date('Y-m-d');
    $a['resumo'] = mb_substr(trim((string)($_POST['resumo'] ?? '')), 0, 300); $a['corpo'] = mb_substr((string)($_POST['corpo'] ?? ''), 0, 60000);
    $a['publicado'] = !empty($_POST['publicado']); $a['atualizado'] = date('c');
    if ($a['titulo'] === '') $erro = 'O artigo precisa de título.';
    else {
        if (empty($a['slug']) || !empty($_POST['refazer_slug'])) { $base = faz_slug($a['titulo']); $s = $base; $n = 2; $usados = []; foreach ($T as $x) if (($x['id'] ?? '') !== $a['id']) $usados[] = $x['slug'] ?? ''; while (in_array($s, $usados, true)) $s = $base . '-' . $n++; $a['slug'] = $s; }
        list($capa, $e1) = sobe_imagem('capa'); if ($capa) $a['capa'] = $capa; if (!empty($_POST['tirar_capa'])) unset($a['capa']);
        list($img, $e2) = sobe_imagem('imagem'); if ($img) $a['corpo'] = rtrim($a['corpo']) . "\n\n![](" . $img . ")\n";
        if ($pos >= 0) $T[$pos] = $a; else $T[] = $a;
        if (!grava_json('artigos.json', $T)) $erro = 'Não consegui gravar. Tente de novo.';
        else { $_SESSION['msg'] = trim('Artigo salvo' . ($a['publicado'] ? ' e publicado.' : ' como rascunho.') . ' ' . $e1 . ' ' . $e2); vai('?p=artigo&id=' . $a['id']); }
    }
}
if ($post && $acao === 'apagar_artigo') { confere(); $id = (string)($_POST['id'] ?? ''); $T = array_values(array_filter(le_json('artigos.json', []), function ($x) use ($id) { return ($x['id'] ?? '') !== $id; })); grava_json('artigos.json', $T); $_SESSION['msg'] = 'Artigo apagado.'; vai('?p=artigos'); }
$BR = dirname(__DIR__) . '/_briefings';
function contatos($BR) { $o = []; foreach (glob($BR . '/briefing-*.json') ?: [] as $f) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) { $j['_arq'] = basename($f); $o[] = $j; } } usort($o, function ($a, $b) { return strcmp($b['quando'] ?? '', $a['quando'] ?? ''); }); return $o; }
if ($post && $acao === 'apagar_contato') { confere(); $f = basename((string)($_POST['arq'] ?? '')); if (preg_match('/^briefing-[0-9a-f-]+\.json$/', $f) && is_file($BR . '/' . $f)) unlink($BR . '/' . $f); $_SESSION['msg'] = 'Contato apagado.'; vai('?p=contatos'); }
if ($p === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="stratesign-contatos-' . date('Ymd') . '.csv"'); $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Data', 'Nome', 'WhatsApp', 'Comece por', 'Mercado', 'Marca', 'Campanha', 'Respostas'], ';');
    foreach (contatos($BR) as $c) { $v = [substr($c['quando'] ?? '', 0, 16), $c['nome'] ?? '', $c['whatsapp'] ?? ($c['contato'] ?? ''), $c['resultado'] ?? '', $c['pontos']['mercado'] ?? '', $c['pontos']['marca'] ?? '', $c['pontos']['campanha'] ?? '', implode(' | ', (array)($c['respostas'] ?? []))];
        foreach ($v as $k => $x) if (preg_match('/^[=+\-@\t\r]/', (string)$x)) $v[$k] = "'" . $x;           // evita fórmula ao abrir na planilha
        fputcsv($o, $v, ';'); }
    exit;
}
if ($post && $acao === 'salvar_ajustes') { confere(); $em = trim((string)($_POST['destino'] ?? '')); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) $erro = 'E-mail inválido.'; else { $cfg['destino'] = $em; grava_json('config.json', $cfg); $_SESSION['msg'] = $em ? 'Os diagnósticos passam a chegar em ' . $em . '.' : 'Aviso por e-mail desligado.'; vai('?p=ajustes'); } }
if ($post && $acao === 'trocar_senha') { confere(); $s1 = (string)($_POST['senha'] ?? '');
    if (!password_verify((string)($_POST['atual'] ?? ''), $cfg['senha'])) $erro = 'A senha atual não confere.'; elseif (mb_strlen($s1) < 10) $erro = 'A nova senha precisa de pelo menos 10 caracteres.'; elseif ($s1 !== (string)($_POST['senha2'] ?? '')) $erro = 'As duas senhas não são iguais.';
    else { $cfg['senha'] = password_hash($s1, PASSWORD_DEFAULT); grava_json('config.json', $cfg); $_SESSION['marca'] = substr($cfg['senha'], -16); $_SESSION['msg'] = 'Senha trocada.'; vai('?p=ajustes'); } }
if (!empty($_SESSION['msg'])) { $msg = $_SESSION['msg']; unset($_SESSION['msg']); }

// ---------- telas ----------
function tela($titulo, $miolo, $menu = true) { global $p; ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title><?= e($titulo) ?> — Painel Stratesign</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
@font-face{font-family:M;src:url(/fonts/montserrat-latin-400-normal.woff2) format("woff2");font-weight:400;font-display:swap}
@font-face{font-family:M;src:url(/fonts/montserrat-latin-700-normal.woff2) format("woff2");font-weight:700;font-display:swap}
@font-face{font-family:M;src:url(/fonts/montserrat-latin-800-normal.woff2) format("woff2");font-weight:800;font-display:swap}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 M,system-ui,sans-serif;color:#0c0c0c;background:#f4f3ef}
a{color:inherit}h1{font-size:30px;font-weight:800;letter-spacing:-.04em;line-height:1.05;margin:0 0 18px}h2{font-size:17px;margin:34px 0 10px}
.tp{background:#000;color:#fff;display:flex;gap:26px;align-items:center;padding:14px 4vw;flex-wrap:wrap}.tp b{font-weight:800;letter-spacing:-.02em;margin-right:auto}.tp a{text-decoration:none;font-weight:700;font-size:13px;opacity:.6}.tp a.on,.tp a:hover{opacity:1}.tp button{background:none;border:0;color:#fff;font:inherit;font-size:13px;font-weight:700;opacity:.6;cursor:pointer;padding:0}
main{max-width:980px;margin:0 auto;padding:36px 4vw 80px}
.cx{max-width:420px;margin:12vh auto;background:#fff;padding:34px;border:1px solid #d8d7d2}
label{display:block;font-size:12px;font-weight:700;margin:16px 0 0}input[type=text],input[type=password],input[type=email],input[type=date],select,textarea{display:block;width:100%;margin-top:6px;font:inherit;padding:11px 12px;border:1px solid #bdbcb6;background:#fff;border-radius:0}
textarea{min-height:420px;line-height:1.55;resize:vertical}input:focus,textarea:focus,select:focus{outline:2px solid #f0148c;outline-offset:-1px}
.bt{display:inline-block;margin-top:22px;background:#000;color:#fff;border:1px solid #000;font:inherit;font-weight:700;font-size:13px;padding:12px 20px;cursor:pointer;text-decoration:none}.bt:hover{background:#fff;color:#000}.bt.s{background:#fff;color:#000}.bt.p{border-color:#b00020;background:#fff;color:#b00020}
.ok{background:#e7f5ea;border:1px solid #9fcfab;padding:10px 14px;margin:0 0 20px}.er{background:#fdeaea;border:1px solid #e3a3a3;padding:10px 14px;margin:0 0 16px}
table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;padding:12px 14px;border-bottom:1px solid #e4e3de;vertical-align:top;font-size:14px}th{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#77766f}
.tg{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border:1px solid currentColor}.tg.r{color:#8c8b86}.ln{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:18px}.ln h1{margin:0 auto 0 0}
.g2{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 16px}.dica{font-size:12.5px;color:#66655f;margin:8px 0 0}.ck{display:flex;gap:8px;align-items:center;font-size:14px;font-weight:400}.ck input{width:auto;margin:0}
.rs{font-size:12.5px;color:#55544f;margin:4px 0 0}@media(max-width:700px){.g2{grid-template-columns:1fr}th:nth-child(n+4),td:nth-child(n+4){display:none}}
</style></head><body>
<?php if ($menu): ?><div class="tp"><b>Stratesign · Painel</b><a href="?p=artigos" class="<?= in_array($p, ['artigos', 'artigo']) ? 'on' : '' ?>">Artigos</a><a href="?p=contatos" class="<?= $p === 'contatos' ? 'on' : '' ?>">Contatos</a><a href="?p=ajustes" class="<?= $p === 'ajustes' ? 'on' : '' ?>">Ajustes</a><a href="/" target="_blank" rel="noopener">Ver o site</a><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="sair"><button>Sair</button></form></div><main><?php endif; $miolo(); if ($menu) echo '</main>'; ?>
</body></html>
<?php }
$aviso = function () use (&$msg, &$erro) { if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($erro) echo '<p class="er">' . e($erro) . '</p>'; };

if ($p === 'artigo') {
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? '')); $a = ['id' => '', 'titulo' => '', 'autor' => 'Sérgio Duh', 'data' => date('Y-m-d'), 'resumo' => '', 'corpo' => '', 'publicado' => false];
    foreach (le_json('artigos.json', []) as $x) if (($x['id'] ?? '') === $id && $id !== '') $a = $x;
    if ($post && $acao === 'salvar_artigo' && $erro) $a = array_merge($a, ['titulo' => $_POST['titulo'] ?? '', 'resumo' => $_POST['resumo'] ?? '', 'corpo' => $_POST['corpo'] ?? '']);
    tela($a['id'] ? 'Editar artigo' : 'Novo artigo', function () use ($a, $AUT, $aviso) { ?>
      <div class="ln"><h1><?= $a['id'] ? 'Editar artigo' : 'Novo artigo' ?></h1><?php if (!empty($a['slug']) && !empty($a['publicado'])): ?><a class="bt s" style="margin:0" href="/artigos/<?= e($a['slug']) ?>" target="_blank" rel="noopener">Ver publicado</a><?php endif; ?></div>
      <?php $aviso(); ?>
      <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="salvar_artigo"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
      <button class="bt" style="margin-top:0">Salvar</button> <a class="bt s" style="margin-top:0" href="?p=artigos">Voltar</a>
      <label>Título<input type="text" name="titulo" value="<?= e($a['titulo']) ?>" maxlength="160" required></label>
      <div class="g2"><label>Autor<select name="autor"><?php foreach ($AUT as $n): ?><option<?= ($a['autor'] ?? '') === $n ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <label>Data<input type="date" name="data" value="<?= e($a['data']) ?>"></label>
      <label>Situação<span class="ck" style="margin-top:16px"><input type="checkbox" name="publicado" value="1"<?= !empty($a['publicado']) ? ' checked' : '' ?>> Publicado no site</span></label></div>
      <label>Resumo (aparece na lista, no Google e ao compartilhar; até 300 caracteres)<input type="text" name="resumo" value="<?= e($a['resumo'] ?? '') ?>" maxlength="300"></label>
      <label>Texto<textarea name="corpo"><?= e($a['corpo'] ?? '') ?></textarea></label>
      <p class="dica">Parágrafos separados por uma linha em branco. <b>## Subtítulo</b> · <b>&gt; citação</b> · <b>**negrito**</b> · <b>*itálico*</b> · <b>[texto do link](https://endereço)</b>.</p>
      <div class="g2"><label>Imagem de capa (JPG, PNG ou WebP, até 6 MB)<input type="file" name="capa" accept="image/jpeg,image/png,image/webp" style="margin-top:8px"></label>
      <label>Imagem para o texto (entra no fim; mova a linha para onde quiser)<input type="file" name="imagem" accept="image/jpeg,image/png,image/webp" style="margin-top:8px"></label>
      <label>&nbsp;<?php if (!empty($a['capa'])): ?><span class="ck"><input type="checkbox" name="tirar_capa" value="1"> Tirar a capa atual</span><?php endif; ?><?php if (!empty($a['slug'])): ?><span class="ck" style="margin-top:8px"><input type="checkbox" name="refazer_slug" value="1"> Refazer o endereço pelo título</span><?php endif; ?></label></div>
      <?php if (!empty($a['slug'])): ?><p class="dica">Endereço: stratesign.com.br/artigos/<?= e($a['slug']) ?></p><?php endif; ?>
      </form>
      <?php if ($a['id']): ?><form method="post" onsubmit="return confirm('Apagar este artigo? Não dá para desfazer.')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="apagar_artigo"><input type="hidden" name="id" value="<?= e($a['id']) ?>"><button class="bt p">Apagar artigo</button></form><?php endif; ?>
    <?php });
} elseif ($p === 'contatos') {
    $C = contatos($BR);
    tela('Contatos', function () use ($C, $aviso) { ?>
      <div class="ln"><h1>Contatos do diagnóstico</h1><?php if ($C): ?><a class="bt" style="margin:0" href="?p=csv">Baixar planilha (CSV)</a><?php endif; ?></div><?php $aviso(); ?>
      <?php if (!$C): ?><p>Ninguém respondeu ao diagnóstico ainda.</p><?php else: ?><table><tr><th>Quando</th><th>Nome</th><th>WhatsApp</th><th>Comece por</th><th>Respostas</th><th></th></tr>
      <?php foreach ($C as $c): $z = preg_replace('/\D/', '', (string)($c['whatsapp'] ?? ($c['contato'] ?? ''))); if (strlen($z) <= 11 && $z !== '') $z = '55' . $z; ?>
        <tr><td><?= e(date('d/m/Y H:i', strtotime($c['quando'] ?? 'now'))) ?></td><td><b><?= e($c['nome'] ?? '') ?></b></td><td><?php if ($z): ?><a href="https://wa.me/<?= e($z) ?>" target="_blank" rel="noopener"><?= e($c['whatsapp'] ?? $c['contato']) ?></a><?php endif; ?></td><td><?= e($c['resultado'] ?? '') ?></td>
        <td><?php foreach ((array)($c['respostas'] ?? []) as $k => $r): ?><p class="rs"><?= e(($c['perguntas'][$k] ?? '') . ' ' . $r) ?></p><?php endforeach; ?></td>
        <td><form method="post" onsubmit="return confirm('Apagar este contato? Não dá para desfazer.')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="apagar_contato"><input type="hidden" name="arq" value="<?= e($c['_arq']) ?>"><button class="bt p" style="margin:0;padding:6px 10px">Apagar</button></form></td></tr>
      <?php endforeach; ?></table><?php endif; ?>
    <?php });
} elseif ($p === 'ajustes') {
    tela('Ajustes', function () use ($cfg, $aviso) { ?>
      <h1>Ajustes</h1><?php $aviso(); ?>
      <h2>Aviso de novo diagnóstico</h2>
      <form method="post" style="max-width:460px"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="salvar_ajustes">
      <label>E-mail que recebe cada diagnóstico (vazio = só guarda aqui no painel)<input type="email" name="destino" value="<?= e($cfg['destino'] ?? '') ?>" placeholder="nome@stratesign.com.br"></label><button class="bt">Salvar</button></form>
      <h2>Trocar a senha</h2>
      <form method="post" style="max-width:460px"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="trocar_senha">
      <label>Senha atual<input type="password" name="atual" required autocomplete="current-password"></label><label>Nova senha (10 caracteres ou mais)<input type="password" name="senha" required minlength="10" autocomplete="new-password"></label><label>Repita a nova senha<input type="password" name="senha2" required minlength="10" autocomplete="new-password"></label><button class="bt">Trocar senha</button></form>
    <?php });
} else {
    $p = 'artigos'; $T = artigos(false); $pub = count(artigos(true));
    tela('Artigos', function () use ($T, $pub, $aviso) { ?>
      <div class="ln"><h1>Artigos</h1><a class="bt" style="margin:0" href="?p=artigo">Novo artigo</a></div><?php $aviso(); ?>
      <p class="dica" style="margin:0 0 18px">Publicados: <?= $pub ?>. Cada artigo publicado já tem página própria, entra no feed e no mapa do site. O bloco de artigos na home aparece a partir de cinco publicados.</p>
      <?php if (!$T): ?><p>Nenhum artigo ainda.</p><?php else: ?><table><tr><th>Título</th><th>Autor</th><th>Data</th><th>Situação</th></tr>
      <?php foreach ($T as $a): ?><tr><td><a href="?p=artigo&amp;id=<?= e($a['id']) ?>"><b><?= e($a['titulo']) ?></b></a></td><td><?= e($a['autor'] ?? '') ?></td><td><?= e(date('d/m/Y', strtotime($a['data']))) ?></td><td><?php if (empty($a['publicado'])): ?><span class="tg r">Rascunho</span><?php elseif ($a['data'] > date('Y-m-d')): ?><span class="tg r">Agendado</span><?php else: ?><span class="tg">Publicado</span><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    <?php });
}
