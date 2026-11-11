<?php
// PD · ISCA — página pública de captura com consentimento. GET ?s=slug mostra a página; POST grava o cadastro como lead (pessoa) no CRM do cliente.
// Só para iscas ativas criadas pela equipe em Dig › Captar. O consentimento (texto + data) fica registrado no lead.
require_once __DIR__ . '/sd-auth.php';
require_once __DIR__ . '/pd-pessoas-lib.php';
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['s'] ?? '')));
$isca = $slug ? (sd_supabase('/rest/v1/pd_iscas?slug=eq.' . urlencode($slug) . '&ativo=is.true&select=*&limit=1')['body'][0] ?? null) : null;
function iscaEsc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (!$isca) { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><meta charset="utf-8"><title>Página não encontrada</title><body style="font:16px system-ui;padding:40px">Esta página não está disponível.</body>'; exit; }
$cli = sd_supabase('/rest/v1/clients?id=eq.' . urlencode($isca['client_id']) . '&select=id,name,logo_url,color,site')['body'][0] ?? ['name' => '', 'color' => '#111'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $fim = fn($ok, $d = []) => print(json_encode(['ok' => $ok] + $d, JSON_UNESCAPED_UNICODE)) && exit;
    if (!empty($b['site_url'])) { echo json_encode(['ok' => true]); exit; }   // isca para robô: campo escondido preenchido = descarta sem avisar
    $nome = trim(mb_substr((string)($b['nome'] ?? ''), 0, 120)); $email = strtolower(trim((string)($b['email'] ?? ''))); $tel = trim((string)($b['telefone'] ?? ''));
    if (mb_strlen($nome) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['ok' => false, 'erro' => 'Confira o nome e o e-mail.']); exit; }
    if (empty($b['consentimento'])) { http_response_code(422); echo json_encode(['ok' => false, 'erro' => 'Marque a caixa de consentimento para continuar.']); exit; }
    $iph = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|isca'); $desde = gmdate('c', time() - 3600);
    $ja = sd_supabase('/rest/v1/pd_prospects?origem=eq.isca&created_at=gte.' . rawurlencode($desde) . '&sinais->CONSENTIMENTO->>ip=eq.' . $iph . '&select=id&limit=6')['body'] ?? [];
    if (is_array($ja) && count($ja) >= 5) { http_response_code(429); echo json_encode(['ok' => false, 'erro' => 'Muitas tentativas. Tente mais tarde.']); exit; }
    $r = vdPessoasGrava([['nome' => $nome, 'email' => $email, 'zap' => $isca['pede_telefone'] ? $tel : '', 'empresa' => $cli['name'] ?? '', 'cargo' => 'Lead da isca', 'nota' => 70, 'fonte_url' => 'isca:' . $isca['slug'], 'motivo' => 'Pediu o material "' . $isca['titulo'] . '" e consentiu o contato.', 'perfil' => 'Cadastro direto na página da isca',
        'sinais_extra' => ['CONSENTIMENTO' => ['texto' => $isca['consentimento'], 'em' => gmdate('c'), 'ip' => $iph, 'isca' => $isca['slug']]]]], 'isca ' . $isca['slug'], $isca['client_id'], null, 'isca', 'Isca · ' . mb_substr($isca['titulo'], 0, 60));
    if (!empty($r['erro'])) { http_response_code(500); echo json_encode(['ok' => false, 'erro' => 'Não deu para salvar agora. Tente de novo.']); exit; }
    if (!empty($r['novos'])) sd_supabase('/rest/v1/pd_iscas?id=eq.' . $isca['id'], 'PATCH', ['capturas' => (int)$isca['capturas'] + 1], ['Prefer: return=minimal']);
    echo json_encode(['ok' => true, 'entrega' => $isca['entrega_url'] ?: null], JSON_UNESCAPED_UNICODE); exit;
}
$cor = preg_match('/^#[0-9a-f]{6}$/i', (string)($cli['color'] ?? '')) ? $cli['color'] : '#111111';
$lum = (hexdec(substr($cor, 1, 2)) * 299 + hexdec(substr($cor, 3, 2)) * 587 + hexdec(substr($cor, 5, 2)) * 114) / 1000; $fg = $lum > 150 ? '#111' : '#fff';
header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex'); ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= iscaEsc($isca['titulo']) ?> · <?= iscaEsc($cli['name']) ?></title>
<style>*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f4f2;color:#161616;min-height:100vh;display:grid;place-items:center;padding:20px}
.box{background:#fff;border-radius:18px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.08)}.top{background:<?= $cor ?>;color:<?= $fg ?>;padding:26px 28px;display:flex;align-items:center;gap:14px}.top img{max-height:44px;max-width:140px}.top b{font-size:15px;letter-spacing:.02em}
.in{padding:28px}h1{margin:0 0 8px;font-size:26px;line-height:1.15}p{margin:0 0 14px;color:#444}label{display:block;font-size:13px;margin:12px 0 4px;color:#333}input[type=text],input[type=email],input[type=tel]{width:100%;padding:12px 13px;border:1px solid #cfcfc9;border-radius:10px;font:inherit}
.chk{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:#444;margin:16px 0}.chk input{margin-top:3px}button{width:100%;padding:14px;border:0;border-radius:99px;background:<?= $cor ?>;color:<?= $fg ?>;font:600 16px system-ui;cursor:pointer;margin-top:6px}button:disabled{opacity:.6}
.er{color:#b3261e;font-size:13px;margin-top:10px;min-height:18px}.hp{position:absolute;left:-9999px}.ok{display:none}.ok a{display:block;text-align:center;padding:14px;border-radius:99px;background:<?= $cor ?>;color:<?= $fg ?>;text-decoration:none;font-weight:600;margin-top:16px}small{color:#777;font-size:12px;display:block;margin-top:14px}</style></head><body>
<div class="box"><div class="top"><?php if (!empty($cli['logo_url'])): ?><img src="<?= iscaEsc($cli['logo_url']) ?>" alt=""><?php endif; ?><b><?= iscaEsc($cli['name']) ?></b></div>
<div class="in" id="f"><h1><?= iscaEsc($isca['titulo']) ?></h1><?php if ($isca['subtitulo']): ?><p><?= iscaEsc($isca['subtitulo']) ?></p><?php endif; ?><?php if ($isca['promessa']): ?><p><b><?= iscaEsc($isca['promessa']) ?></b></p><?php endif; ?>
<form onsubmit="return env(event)"><div class="hp"><input type="text" id="site_url" tabindex="-1" autocomplete="off"></div>
<label for="nome">Nome</label><input type="text" id="nome" autocomplete="name" required><label for="email">E-mail</label><input type="email" id="email" autocomplete="email" required>
<?php if ($isca['pede_telefone']): ?><label for="tel">WhatsApp (opcional)</label><input type="tel" id="tel" autocomplete="tel" inputmode="tel"><?php endif; ?>
<label class="chk"><input type="checkbox" id="ck"><span><?= iscaEsc($isca['consentimento']) ?></span></label><button id="bt" type="submit">Quero receber</button><div class="er" id="er"></div></form>
<small>Seus dados ficam com <?= iscaEsc($cli['name']) ?>. Para sair ou apagar seus dados, é só pedir por qualquer mensagem que você receber.</small></div>
<div class="in ok" id="ok"><h1>Pronto.</h1><p>Recebemos o seu cadastro.</p><a id="ent" href="#" style="display:none" target="_blank" rel="noopener">Abrir o material</a></div></div>
<script>async function env(e){e.preventDefault();var er=document.getElementById('er'),bt=document.getElementById('bt');er.textContent='';bt.disabled=true;
try{var r=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({nome:document.getElementById('nome').value,email:document.getElementById('email').value,telefone:(document.getElementById('tel')||{}).value||'',consentimento:document.getElementById('ck').checked,site_url:document.getElementById('site_url').value})});
var j=await r.json().catch(function(){return{}});if(!j.ok){er.textContent=j.erro||'Não deu certo. Tente de novo.';bt.disabled=false;return false;}
document.getElementById('f').style.display='none';document.getElementById('ok').style.display='block';if(j.entrega){var a=document.getElementById('ent');a.href=j.entrega;a.style.display='block';}}catch(x){er.textContent='Sem conexão. Tente de novo.';bt.disabled=false;}return false;}</script></body></html>
