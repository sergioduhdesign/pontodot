<?php
// Manifesto do app com o avatar da IA como ícone: /manifest.php?p=<personagem>
$id = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['p'] ?? ''));
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$u = fn($s, $m) => "/icone-ia.php?p=$id&s=$s" . ($m ? '&m=1' : '');
echo json_encode([
  'name' => 'Pontodot', 'short_name' => 'Pontodot', 'description' => 'O estúdio inteiro numa tela',
  'start_url' => '/index.html', 'display' => 'standalone', 'background_color' => '#0B0C0B', 'theme_color' => '#0B0C0B',
  'orientation' => 'any', 'scope' => '/',
  'icons' => [
    ['src' => $u(192, 0), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => $u(512, 0), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => $u(192, 1), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
    ['src' => $u(512, 1), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
