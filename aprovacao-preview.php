<?php
require_once __DIR__ . '/sd-base.php';
// =====================================================================
// SD Workflow — preview do link de aprovação (SEO / WhatsApp / iMessage)
//
// PROBLEMA QUE ISSO RESOLVE:
// O link de aprovação (ver.html) monta a tela inteira via JavaScript,
// depois de buscar os dados no Supabase. O rastreador que gera a
// prévia no WhatsApp, iMessage, Slack etc. NÃO executa JavaScript —
// ele só lê as tags <meta> que já vêm prontas no HTML. Por isso o
// link saía sempre igual: "Aprovação — Pontodot", sem imagem,
// sem dizer de quem é nem o que é.
//
// COMO FUNCIONA:
// Esta página busca os dados no servidor (PHP), antes de mandar
// qualquer HTML pro navegador, e escreve as tags de preview já
// preenchidas: título com o cliente e o ID da tarefa, descrição com
// a contagem de peças, e a imagem — o mockup da peça quando é peça
// única, ou a primeira arte do lote.
//
// Uma PESSOA que abre este link é redirecionada na hora pro ver.html
// de verdade (é lá que a aprovação acontece). Só o RASTREADOR de
// preview é que fica só na leitura das tags, porque ele não roda o
// JavaScript do redirecionamento.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$token = $_GET['t'] ?? '';
$destino = '/ver.html?t=' . urlencode($token);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// --- Valores padrão, usados se o token não existir ou já tiver vencido ---
$titulo      = 'Aprovação de material';
$cliente     = 'Pontodot';
$descricao   = 'Confira e aprove o material preparado para você.';
$imagem      = sd_base() . '/assets/icon-512.png';
$imagemQuad  = true;

if (preg_match('/^[0-9a-fA-F-]{36}$/', $token)) {
    // Uma consulta só: traz o lote e TODAS as peças (id + media),
    // ordenadas. Sem limite aqui — um lote raramente passa de 200
    // peças, e assim evitamos uma segunda ida ao banco só pra contar.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => SD_SUPABASE_URL
            . '/rest/v1/approval_sets?share_token=eq.' . urlencode($token)
            . '&select=id,title,subtitle,task_id,status,'
            . 'clients(name),'
            . 'approval_items(position,media)'
            . '&approval_items.order=position.asc',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        // Chave nova não é JWT: vai só no cabeçalho apikey.
        CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $rows = json_decode($res, true);

    if (is_array($rows) && count($rows)) {
        $set = $rows[0];

        // Link expirado ou ainda rascunho: mantém os valores padrão,
        // não expõe nome de cliente de um material que não deveria
        // estar visível.
        $expirado = !empty($set['status']) && $set['status'] !== 'rascunho'
            ? false : true;

        if (!$expirado) {
            $cliente = $set['clients']['name'] ?? 'Pontodot';
            $titulo  = $set['title'] ?? $titulo;

            $itens = $set['approval_items'] ?? [];
            $totalPecas = is_array($itens) ? count($itens) : 0;

            $descricao = ($set['task_id'] ? $set['task_id'] . ' · ' : '')
                . $totalPecas . ' peça' . ($totalPecas === 1 ? '' : 's')
                . ' para aprovar' . ($set['subtitle'] ? ' — ' . $set['subtitle'] : '');

            // Imagem de capa: a primeira peça do lote. Se for peça única
            // (mockup), é exatamente essa imagem que deve aparecer.
            $primeiraMedia = $itens[0]['media'][0] ?? null;
            if (!empty($primeiraMedia['thumb']) || !empty($primeiraMedia['url'])) {
                $imagem = $primeiraMedia['thumb'] ?? $primeiraMedia['url'];
                $imagemQuad = false;
            }
        }
    }
}

$urlCompleta = sd_base() . $destino;
$tituloCompleto = $titulo . ' — ' . $cliente;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= h($tituloCompleto) ?></title>

<!-- Open Graph (WhatsApp, iMessage, Slack, LinkedIn...) -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="Pontodot">
<meta property="og:title" content="<?= h($tituloCompleto) ?>">
<meta property="og:description" content="<?= h($descricao) ?>">
<meta property="og:image" content="<?= h($imagem) ?>">
<?php if ($imagemQuad): ?>
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">
<?php endif; ?>
<meta property="og:url" content="<?= h($urlCompleta) ?>">

<!-- Twitter/X -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($tituloCompleto) ?>">
<meta name="twitter:description" content="<?= h($descricao) ?>">
<meta name="twitter:image" content="<?= h($imagem) ?>">

<meta name="robots" content="noindex">
<meta http-equiv="refresh" content="0; url=<?= h($destino) ?>">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#0a0a0a;color:#fff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif}
  a{color:#fff}
</style>
</head>
<body>
  <p>Abrindo aprovação… <a href="<?= h($destino) ?>">clique aqui</a> se não for automático.</p>
  <script>location.replace(<?= json_encode($destino) ?>);</script>
</body>
</html>
