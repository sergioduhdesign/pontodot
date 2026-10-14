<?php
// =====================================================================
// SD Workflow - pagina publica de compartilhamento de tarefa
//
// MUDANCA: antes recebia ?id=SD-0001. Como os IDs sao sequenciais,
// qualquer pessoa podia varrer a base inteira de todos os clientes.
// Agora recebe ?t=<uuid do share_token>, gerado sob demanda por quem
// tem acesso a tarefa (ver RPC create_task_share_token na migration).
//
// A pagina mostra apenas o preview (capa, cliente, projeto, acao) e um
// botao para abrir no app. O conteudo real continua exigindo login.
// =====================================================================

require_once __DIR__ . '/../sd-private/sd-config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

$shareToken = $_GET['t'] ?? '';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$task = null;
if (preg_match('/^[0-9a-fA-F-]{36}$/', $shareToken)) {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => SD_SUPABASE_URL
      . '/rest/v1/tasks?share_token=eq.' . urlencode($shareToken)
      . '&select=id,action,project,resp,status,cover_url,clients(name,logo_url,color),products(name)',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    // Chave nova nao e JWT: vai apenas no cabecalho apikey.
    CURLOPT_HTTPHEADER => ['apikey: ' . SD_SECRET_KEY]
  ]);
  $res = curl_exec($ch); curl_close($ch);
  $rows = json_decode($res, true);
  if (is_array($rows) && count($rows)) $task = $rows[0];
}

$RESP_LABELS = ['SERGIO'=>'Sérgio Duh','JOY'=>'Joy','LUANA'=>'Luana','JUAN'=>'Juan','CLIENTE'=>'Cliente','OUTROS'=>'Outros / IA'];
$STATUS_LABELS = ['BRIEFING'=>['Briefing','#6366f1'],'EM ANDAMENTO'=>['Em Andamento','#2563eb'],'DELEGADA'=>['Com o cliente','#7c3aed'],'ATENÇÃO'=>['Aprovado','#d97706'],'BUCHA'=>['Bucha','#dc2626'],'ALTERAÇÃO'=>['Alteração','#db2777'],'ENTREGUE'=>['Entregue','#16a34a']];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
$origin = $scheme.'://'.$_SERVER['HTTP_HOST'];
$pageUrl = $origin.'/share-task.php?t='.urlencode($shareToken);

if (!$task) {
  http_response_code(404);
  $title = 'Tarefa não encontrada — Pontodot';
  $desc = 'Esse link de tarefa não existe mais ou foi removido.';
  $image = $origin.'/assets/icon-512.png';
} else {
  $clientName = $task['clients']['name'] ?? 'Pontodot';
  $productName = $task['products']['name'] ?? null;
  $respLabel = $RESP_LABELS[$task['resp']] ?? $task['resp'];
  $statusInfo = $STATUS_LABELS[$task['status']] ?? [$task['status'], '#999'];
  $title = h($task['action']).' — '.h($clientName);
  $descParts = [$clientName];
  if ($task['project']) $descParts[] = $task['project'];
  if ($productName) $descParts[] = $productName;
  $descParts[] = 'Responsável: '.$respLabel;
  $desc = implode(' · ', $descParts);
  $image = $task['cover_url'] ?: ($task['clients']['logo_url'] ?? null) ?: ($origin.'/assets/icon-512.png');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?></title>

<meta property="og:type" content="website">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:image" content="<?= h($image) ?>">
<meta property="og:url" content="<?= h($pageUrl) ?>">
<meta property="og:site_name" content="Pontodot">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($desc) ?>">
<meta name="twitter:image" content="<?= h($image) ?>">

<link rel="icon" href="assets/icon-pwa.svg" type="image/svg+xml">
<link rel="stylesheet" href="css/main.css">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;padding:24px;box-sizing:border-box;}
  .card{max-width:440px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,0.12);}
  .cover{width:100%;aspect-ratio:1;object-fit:cover;background:#eee;display:block;}
  .body{padding:20px 22px 22px;}
  .badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:0.04em;padding:3px 10px;border-radius:20px;margin-bottom:10px;}
  .title{font-size:18px;font-weight:700;color:#111318;line-height:1.4;margin-bottom:8px;}
  .meta{font-size:13px;color:#6b7280;line-height:1.7;margin-bottom:18px;}
  .meta b{color:#111318;}
  .btn{display:block;text-align:center;background:#0a0a0a;color:#fff;text-decoration:none;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:700;}
  .footer{text-align:center;margin-top:14px;font-size:11.5px;color:#9ca3af;}
</style>
</head>
<body>
<?php if (!$task): ?>
  <div class="card">
    <div class="body">
      <div class="title"><?= h($title) ?></div>
      <div class="meta"><?= h($desc) ?></div>
      <a class="btn" href="/index.html">Abrir o Pontodot</a>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <img class="cover" src="<?= h($image) ?>" alt="">
    <div class="body">
      <span class="badge" style="background:<?= h($statusInfo[1]) ?>18;color:<?= h($statusInfo[1]) ?>"><?= h($statusInfo[0]) ?></span>
      <div class="title"><?= h($task['action']) ?></div>
      <div class="meta">
        <b><?= h($clientName) ?></b><?= $productName ? ' · '.h($productName) : '' ?><br>
        Projeto: <b><?= h($task['project'] ?: 'Geral') ?></b><br>
        Responsável: <b><?= h($respLabel) ?></b>
      </div>
      <a class="btn" href="/index.html?redirect=task:<?= urlencode($task['id']) ?>">Abrir no Pontodot</a>
    </div>
  </div>
  <script>
    // Se a pessoa já tem sessão ativa no navegador, pula direto pra tarefa.
    // Se não, o botão acima leva pro login normalmente.
  </script>
<?php endif; ?>
<div class="footer">Pontodot</div>
</body>
</html>
