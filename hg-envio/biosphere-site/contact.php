<?php
// ===== CONFIGURAÇÃO =====
// Troque pelo e-mail real que vai receber os contatos do site.
$destinatario = 'contato@biospherebycreymond.com.br';
$_c = @json_decode(@file_get_contents(__DIR__ . '/_cms/config.json'), true);
if (!empty($_c['email_contato'])) $destinatario = $_c['email_contato']; // definido no painel (/admin)

header('Content-Type: text/plain; charset=utf-8');

$tipo = $_POST['tipo'] ?? 'geral';
$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo 'Preencha nome e e-mail corretamente.';
    exit;
}

$linhas = ["Novo contato pelo site — Biosphere by C. Reymond", "Tipo: " . strtoupper($tipo), ""];

foreach ($_POST as $campo => $valor) {
    if ($campo === 'tipo') continue;
    $valor = is_array($valor) ? implode(', ', $valor) : $valor;
    $linhas[] = ucfirst(str_replace('_', ' ', $campo)) . ': ' . trim($valor);
}

$corpo = implode("\n", $linhas);

// guarda no painel (/admin > Contatos)
$_f = __DIR__ . '/_cms/leads.json';
$_l = is_file($_f) ? (json_decode(file_get_contents($_f), true) ?: []) : [];
$_l[] = ['data' => date('Y-m-d H:i'), 'tipo' => $tipo, 'nome' => $nome, 'email' => $email, 'dados' => array_map(function ($v) { return is_array($v) ? implode(', ', $v) : mb_substr((string)$v, 0, 1500); }, $_POST)];
@file_put_contents($_f, json_encode(array_slice($_l, -500), JSON_UNESCAPED_UNICODE), LOCK_EX);

$assuntos = [
    'incorporadoras' => 'Novo projeto apresentado — Biosphere',
    'compradores'    => 'Novo interesse em empreendimento — Biosphere',
    'imprensa'       => 'Contato de imprensa/parceria — Biosphere',
];
$assunto = $assuntos[$tipo] ?? 'Novo contato — Biosphere';

$headers   = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/plain; charset=UTF-8';
$headers[] = 'From: site@biospherebycreymond.com.br'; // ajuste para um e-mail do mesmo domínio do servidor
$headers[] = "Reply-To: $email";

$enviado = @mail($destinatario, $assunto, $corpo, implode("\r\n", $headers));

if ($enviado) {
    echo 'ok';
} else {
    http_response_code(500);
    echo 'Não foi possível enviar agora.';
}
