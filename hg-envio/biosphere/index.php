<?php
/* BIOSPHERE · roteador do site */
require __DIR__ . '/lib.php';
$r = trim((string)($_GET['r'] ?? ''), '/'); $r = preg_replace('#[^a-z0-9/_.-]#i', '', $r); $r = preg_replace('#/+#', '/', $r);
$met = $_SERVER['REQUEST_METHOD'];

if ($r === 'robots.txt') {
  header('Content-Type: text/plain; charset=utf-8'); $g = seoCfg()['global'] ?? [];
  echo "User-agent: *\n" . (($g['bloquear_buscadores'] ?? '') === '1' ? "Disallow: /\n" : "Allow: /\nDisallow: " . BASE . "/admin/\n") . "\nSitemap: " . siteUrl() . "/sitemap.xml\n"; exit;
}
if ($r === 'sitemap.xml') {
  header('Content-Type: application/xml; charset=utf-8'); $b = siteUrl(); $x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
  foreach (['', 'conceito', 'quem-faz', 'projetos', 'projeto-essence', 'projeto-cocoon', 'projeto-vistas', 'incorporadoras', 'blog', 'contato'] as $p) { $x .= '<url><loc>' . e($b . '/' . $p) . '</loc></url>'; }
  foreach (posts() as $p) $x .= '<url><loc>' . e($b . '/blog/' . $p['slug']) . '</loc><lastmod>' . e(substr($p['data'], 0, 10)) . '</lastmod></url>';
  echo $x . '</urlset>'; exit;
}
if ($r === 'enviar') {
  header('Content-Type: application/json; charset=utf-8');
  $sai = function ($ok, $m, $c = 200) { http_response_code($c); echo json_encode(['ok' => $ok, 'msg' => $m], JSON_UNESCAPED_UNICODE); exit; };
  if ($met !== 'POST') $sai(false, 'Método inválido.', 405);
  if (trim($_POST['site'] ?? '') !== '' || (time() - (int)($_POST['t0'] ?? 0)) < 3) $sai(true, V('contato', 'ok'));
  $nome = trim($_POST['nome'] ?? ''); $email = trim($_POST['email'] ?? '');
  if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $sai(false, 'Preencha nome e e-mail corretamente.', 422);
  $rf = RAIZ . '/conteudo/.envios.json'; $hist = lerJson($rf); $ip = md5($_SERVER['REMOTE_ADDR'] ?? ''); $agora = time();
  $hist[$ip] = array_values(array_filter($hist[$ip] ?? [], function ($t) use ($agora) { return $t > $agora - 3600; }));
  if (count($hist[$ip]) >= 5) $sai(false, 'Muitos envios. Tente mais tarde.', 429);
  $hist[$ip][] = $agora; @file_put_contents($rf, json_encode($hist), LOCK_EX);
  $campos = []; foreach (['tipo', 'nome', 'email', 'telefone', 'empresa', 'cidade', 'assunto', 'mensagem'] as $k) if (isset($_POST[$k])) $campos[$k] = mb_substr(trim(preg_replace('/[\r\0]/', '', (string)$_POST[$k])), 0, 3000);
  $campos['data'] = date('Y-m-d H:i:s'); $campos['id'] = substr(md5(uniqid('', true)), 0, 8);
  $lf = RAIZ . '/conteudo/leads.json'; $l = lerJson($lf); array_unshift($l, $campos); @file_put_contents($lf, json_encode(array_slice($l, 0, 500), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  $dest = V('global', 'email'); $corpo = "Novo contato pelo site — Biosphere by C. Reymond\n\n"; foreach ($campos as $k => $v) if ($k !== 'id') $corpo .= ucfirst($k) . ': ' . $v . "\n";
  $dom = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  @mail($dest, '=?UTF-8?B?' . base64_encode('Novo contato — Biosphere (' . ($campos['tipo'] ?? 'geral') . ')') . '?=', $corpo, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: site@" . $dom . "\r\nReply-To: " . str_replace(["\r", "\n"], '', $email));
  $sai(true, V('contato', 'ok'));
}

$rotas = ['' => ['home', 'paginaHome'], 'conceito' => ['conceito', 'paginaConceito'], 'quem-faz' => ['quem-faz', 'paginaQuemFaz'], 'projetos' => ['projetos', 'paginaProjetos'], 'incorporadoras' => ['incorporadoras', 'paginaIncorporadoras'], 'contato' => ['contato', 'paginaContato'], 'blog' => ['blog', 'paginaBlog']];
$ld = '';
if (isset($rotas[$r])) { $slug = $rotas[$r][0]; $corpo = call_user_func($rotas[$r][1]); }
elseif (preg_match('#^projeto-(essence|cocoon|vistas)$#', $r, $m)) { $slug = $r; $corpo = paginaProjeto($m[1]); }
elseif (preg_match('#^blog/([a-z0-9-]+)$#', $r, $m)) {
  $post = null; foreach (posts(false) as $p) if ($p['slug'] === $m[1]) $post = $p;
  $previa = false; if (!empty($_GET['previa']) && !empty($_COOKIE['bsadm'])) { session_name('bsadm'); @session_start(); $previa = !empty($_SESSION['ok']); session_write_close(); }
  if (!$post || (!$previa && !in_array($post, posts(), true))) { http_response_code(404); $slug = '404'; $corpo = '<section class="sec abre-p"><div class="wrap"><h1>Página não encontrada</h1><p class="lead"><a href="' . e(u()) . '">Voltar ao início</a></p></div></section>'; $seo = ['title' => 'Página não encontrada', 'description' => '', 'og_image' => '', 'robots' => 'noindex', 'canonical' => '']; echo moldura($slug, $seo, $r, $corpo); exit; }
  $slug = 'post'; $corpo = paginaPost($post);
  $seo = ['title' => trim($post['seo_titulo'] ?? '') ?: $post['titulo'] . ' · Biosphere', 'description' => trim($post['seo_descricao'] ?? '') ?: ($post['resumo'] ?? ''), 'og_image' => trim($post['seo_imagem'] ?? '') ?: ($post['capa'] ?? ''), 'robots' => $previa ? 'noindex,nofollow' : '', 'canonical' => ''];
  $ld = '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $post['titulo'], 'datePublished' => $post['data'], 'description' => $seo['description'], 'image' => $seo['og_image'] !== '' ? (preg_match('#^https?:#', $seo['og_image']) ? $seo['og_image'] : siteUrl() . '/' . ltrim($seo['og_image'], '/')) : null, 'mainEntityOfPage' => siteUrl() . '/blog/' . $post['slug'], 'publisher' => ['@type' => 'Organization', 'name' => 'Biosphere by C. Reymond']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
  echo moldura($slug, $seo, $r, $corpo, $ld); exit;
}
else { http_response_code(404); $slug = '404'; $corpo = '<section class="sec abre-p"><div class="wrap"><h1>Página não encontrada</h1><p class="lead"><a href="' . e(u()) . '">Voltar ao início</a></p></div></section>'; echo moldura($slug, ['title' => 'Página não encontrada · Biosphere', 'description' => '', 'og_image' => '', 'robots' => 'noindex', 'canonical' => ''], $r, $corpo); exit; }
echo moldura($slug, seoPagina($slug), $r, $corpo, $ld, false);
