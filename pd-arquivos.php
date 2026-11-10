<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// Pontodot — ARQUIVOS DO SITE (HostGator, pelo token do cPanel do cofre). SÓ ADMIN.
//   POST {acao:"listar",   client_id, caminho?}            -> pastas e arquivos da pasta do site
//   POST {acao:"ler",      client_id, caminho}             -> conteúdo (texto até 1 MB)
//   POST {acao:"gravar",   client_id, caminho, conteudo}   -> guarda o ANTES e grava
//   POST {acao:"historico",client_id}                      -> últimas gravações
//   POST {acao:"desfazer", client_id, id}                  -> volta o ANTES
// Nunca sai da pasta do site; wp-config, .env e chaves ficam fechados.
// =====================================================================
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-lib.php';
require_once __DIR__ . '/pd-site-lib.php';
require_once __DIR__ . '/pd-hostgator-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);
$me = sd_current_user();
if (($me['role'] ?? '') !== 'admin') sd_fail('Só admin.', 403);
$d = sd_body(); $acao = (string)($d['acao'] ?? '');
header('Content-Type: application/json; charset=utf-8');
$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$cid = (string)($d['client_id'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) sd_fail('Cliente inválido.', 400);
const PD_ARQ_MAX = 1048576;

/** Caminho relativo seguro: sem "..", sem barra no começo. */
function pdArqCaminho($p) {
    $p = trim(str_replace('\\', '/', (string)$p), '/ ');
    if ($p === '') return '';
    foreach (explode('/', $p) as $seg) if ($seg === '..' || $seg === '.' || $seg === '' || strpos($seg, "\0") !== false) throw new Exception('Caminho inválido.');
    return $p;
}
function pdArqProibido($p) {
    $b = strtolower(basename($p));
    if (in_array($b, ['wp-config.php', 'wp-config-sample.php', '.env', '.htpasswd', 'id_rsa', 'error_log'], true)) return true;
    return (bool)preg_match('/\.(sql|pem|key|zip|gz|tar|jpg|jpeg|png|gif|webp|avif|pdf|mp4|mov|woff2?|ttf|ico)$/i', $b);
}
function pdArqDivide($p) { $i = strrpos($p, '/'); return $i === false ? ['', $p] : [substr($p, 0, $i), substr($p, $i + 1)]; }

try {
    $c = pdCPCred($cid); $raiz = rtrim(pdCPPasta($cid, $c), '/');
    // escopo "conta": começa na raiz da conta (home do cPanel), onde ficam todos os sites. Só admin; cada gravação guarda o antes.
    $conta = (($d['escopo'] ?? 'site') === 'conta');
    if ($conta) { if (!preg_match('#^(/home\d*/[^/]+)#', $raiz, $mm)) throw new Exception('Não consegui descobrir a raiz da conta.'); $raiz = $mm[1]; }
    $pref = $conta ? '~/' : '';
    if ($acao === 'listar') {
        $rel = pdArqCaminho($d['caminho'] ?? '');
        $j = pdCP($c, 'Fileman', 'list_files', ['dir' => $raiz . ($rel !== '' ? '/' . $rel : ''), 'include_mime' => 0, 'show_hidden' => 1]);
        if (($j['status'] ?? 0) != 1) throw new Exception(pdCPErro($j));
        $it = [];
        foreach ((array)($j['data'] ?? []) as $x) {
            $nome = (string)($x['file'] ?? ''); if ($nome === '' || $nome === '.' || $nome === '..') continue;
            $dir = ($x['type'] ?? '') === 'dir';
            $it[] = ['nome' => $nome, 'pasta' => $dir, 'tam' => (int)($x['size'] ?? 0), 'mod' => (int)($x['mtime'] ?? 0), 'editavel' => !$dir && !pdArqProibido($nome) && (int)($x['size'] ?? 0) <= PD_ARQ_MAX];
        }
        usort($it, fn($a, $b) => [!$a['pasta'], strtolower($a['nome'])] <=> [!$b['pasta'], strtolower($b['nome'])]);
        echo json_encode(['ok' => true, 'escopo' => $conta ? 'conta' : 'site', 'raiz' => $raiz, 'caminho' => $rel, 'itens' => $it], $J); exit;
    }
    if ($acao === 'ler') {
        $rel = pdArqCaminho($d['caminho'] ?? ''); if ($rel === '' || pdArqProibido($rel)) throw new Exception('Este arquivo não pode ser aberto aqui.');
        [$dir, $arq] = pdArqDivide($rel);
        $txt = pdCPLer($c, $raiz . ($dir !== '' ? '/' . $dir : ''), $arq);
        if (strlen($txt) > PD_ARQ_MAX) throw new Exception('Arquivo grande demais para editar aqui (limite 1 MB).');
        echo json_encode(['ok' => true, 'caminho' => $rel, 'conteudo' => $txt], $J); exit;
    }
    if ($acao === 'gravar') {
        $rel = pdArqCaminho($d['caminho'] ?? ''); if ($rel === '' || pdArqProibido($rel)) throw new Exception('Este arquivo não pode ser gravado aqui.');
        $novo = (string)($d['conteudo'] ?? ''); if (strlen($novo) > PD_ARQ_MAX) throw new Exception('Conteúdo grande demais (limite 1 MB).');
        [$dir, $arq] = pdArqDivide($rel); $pasta = $raiz . ($dir !== '' ? '/' . $dir : '');
        $existia = true; $antes = null;
        try { $antes = pdCPLer($c, $pasta, $arq); } catch (Throwable $e) { $existia = false; }
        if ($existia && $antes === $novo) throw new Exception('Nada mudou neste arquivo.');
        // o "antes" é guardado ANTES de gravar; se não guardar, não grava
        $h = sd_supabase('/rest/v1/pd_site_arquivos_hist', 'POST', ['client_id' => $cid, 'caminho' => $pref . $rel, 'existia' => $existia, 'antes' => $antes, 'depois' => $novo, 'por' => $me['id'] ?? null], ['Prefer: return=representation']);
        if (($h['code'] ?? 500) >= 300) throw new Exception('Não consegui guardar o backup (rode a migration v179). Nada foi gravado.');
        pdCPGravar($c, $pasta, $arq, $novo);
        echo json_encode(['ok' => true, 'id' => $h['body'][0]['id'] ?? null, 'arquivo_novo' => !$existia], $J); exit;
    }
    if ($acao === 'historico') {
        $r = sd_supabase('/rest/v1/pd_site_arquivos_hist?client_id=eq.' . urlencode($cid) . '&select=id,caminho,existia,desfeito,criado_em&order=criado_em.desc&limit=40');
        echo json_encode(['ok' => true, 'itens' => $r['body'] ?? []], $J); exit;
    }
    if ($acao === 'desfazer') {
        $id = (string)($d['id'] ?? ''); if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) throw new Exception('Registro inválido.');
        $r = sd_supabase('/rest/v1/pd_site_arquivos_hist?id=eq.' . urlencode($id) . '&client_id=eq.' . urlencode($cid) . '&select=*');
        $h = $r['body'][0] ?? null; if (!$h) throw new Exception('Registro não encontrado.');
        if (!empty($h['desfeito'])) throw new Exception('Já foi desfeito.');
        if (!$h['existia']) throw new Exception('Este arquivo era novo; apagar arquivo ainda não é feito aqui (o conteúdo antigo não existe).');
        $cam = (string)$h['caminho']; $base = $raiz;
        if (strpos($cam, '~/') === 0) { $cam = substr($cam, 2); if (preg_match('#^(/home\d*/[^/]+)#', $raiz, $mm)) $base = $mm[1]; }
        [$dir, $arq] = pdArqDivide($cam); pdCPGravar($c, $base . ($dir !== '' ? '/' . $dir : ''), $arq, (string)$h['antes']);
        sd_supabase('/rest/v1/pd_site_arquivos_hist?id=eq.' . urlencode($id), 'PATCH', ['desfeito' => true]);
        echo json_encode(['ok' => true], $J); exit;
    }
    sd_fail('Ação desconhecida.', 400);
} catch (Throwable $e) { sd_fail($e->getMessage(), 400); }
