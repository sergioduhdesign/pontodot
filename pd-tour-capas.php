<?php
// =====================================================================
// Capas do tour (Gigi): puxa os PNGs de uma pasta do Drive (subpastas equipe/ e cliente/)
// pela ponte e grava em /pd/tour/<nome>.png. Só admin. POST {folder_id}
// Depois disso o tour acha as capas sozinho (js/pd-ajuda.js lê /pd/tour/indice.json).
// =====================================================================
require_once __DIR__ . '/sd-base.php';
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(300);
$me = sd_require_role(['admin']);
$b = sd_body(); $raiz = trim((string)($b['folder_id'] ?? ''));
if (!preg_match('/^[\w-]{10,}$/', $raiz)) sd_fail('folder_id inválido');
$dir = __DIR__ . '/pd/tour'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
$ok = []; $erros = [];
$pastas = [['id' => $raiz, 'rot' => '']];
try { foreach ((array)(ponte('listar', ['folderId' => $raiz])['arquivos'] ?? []) as $f) if (strpos((string)($f['mime'] ?? ''), 'folder') !== false) $pastas[] = ['id' => $f['id'], 'rot' => $f['name']]; } catch (Throwable $e) { sd_fail('não li a pasta: ' . $e->getMessage()); }
foreach ($pastas as $p) {
    try { $l = ponte('listar', ['folderId' => $p['id']]); } catch (Throwable $e) { $erros[] = $p['rot'] . ': ' . $e->getMessage(); continue; }
    foreach ((array)($l['arquivos'] ?? []) as $f) {
        $nm = (string)($f['name'] ?? ''); if (!preg_match('/^([a-z_]+)\.(png|jpg|jpeg|webp)$/i', $nm, $m)) continue;
        if (preg_match('/^previa/i', $nm)) continue;
        try { $d = ponte('baixar', ['fileId' => $f['id']]); $by = base64_decode((string)($d['b64'] ?? '')); if (strlen($by) < 1000) throw new Exception('vazio');
            file_put_contents($dir . '/' . strtolower($m[1]) . '.' . strtolower($m[2]), $by); $ok[] = strtolower($m[1]) . '.' . strtolower($m[2]); } catch (Throwable $e) { $erros[] = $nm . ': ' . $e->getMessage(); }
    }
}
sort($ok);
file_put_contents($dir . '/indice.json', json_encode(array_values(array_unique($ok)), JSON_UNESCAPED_SLASHES));
echo json_encode(['ok' => true, 'salvas' => $ok, 'erros' => $erros], JSON_UNESCAPED_UNICODE);
