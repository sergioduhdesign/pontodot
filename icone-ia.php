<?php
// Ícone do app com o avatar da IA da pessoa: /icone-ia.php?p=<personagem>&s=512[&m=1 (maskable)]
$id = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['p'] ?? ''));
$s  = max(64, min(1024, (int)($_GET['s'] ?? 512)));
$m  = !empty($_GET['m']);
$f  = __DIR__ . "/pd/personagens/$id/avatar.jpg";
if ($id === '' || !is_file($f) || !function_exists('imagecreatefromjpeg')) { header('Location: /assets/icon-512.png', true, 302); exit; }
$src = @imagecreatefromjpeg($f);
if (!$src) { header('Location: /assets/icon-512.png', true, 302); exit; }
$dst = imagecreatetruecolor($s, $s);
$cor = imagecolorat($src, 3, 3);                       // a cor do fundo do avatar preenche a margem do maskable
imagefill($dst, 0, 0, $cor);
$k = $m ? 0.72 : 1.0;                                  // maskable: o desenho cabe na zona segura
$d = (int)round($s * $k); $o = (int)(($s - $d) / 2);
imagecopyresampled($dst, $src, $o, $o, 0, 0, $d, $d, imagesx($src), imagesy($src));
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($dst);
