<?php
// ===== CONFIGURAÇÃO =====
// Troque pelo e-mail real que vai receber os contatos do site.
$destinatario = 'contato@biospherebycreymond.com.br';

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
