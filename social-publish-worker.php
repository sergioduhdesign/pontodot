<?php
// =====================================================================
// SD Workflow — publicador automático (chamado pelo relógio)
//
// Não é chamado pelo navegador — é o Cron Job do cPanel, de tempos em
// tempos. A cada execução: pergunta ao banco "o que está vencido e
// ainda agendado?", e publica. A lógica de COMO publicar mora em
// social-publish-lib.php, compartilhada com o botão manual.
// =====================================================================

require_once __DIR__ . '/social-publish-lib.php';

if (!defined('SD_CRON_KEY') || SD_CRON_KEY === 'SUA_CRON_KEY_AQUI') {
    http_response_code(500);
    die('SD_CRON_KEY nao configurada no sd-config.php.');
}
if (($_GET['key'] ?? '') !== SD_CRON_KEY) {
    http_response_code(403);
    die('Chave invalida.');
}

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/pd-saude-lib.php'; pdBatimento('social-publish-worker');

echo "Pontodot — publicador — " . date('c') . "\n";
echo str_repeat('-', 60) . "\n";

// Comparar como TEXTO não funciona aqui: a data agendada vem do
// navegador em UTC ("...Z"), e o servidor devolve a hora local dele
// ("...-03:00"). Os dois textos têm números de hora diferentes pro
// MESMO instante real — comparar letra por letra dava sempre errado,
// mesmo quando já tinha passado da hora de verdade. Convertendo pra
// timestamp (um número absoluto de segundos), o fuso deixa de importar.
@set_time_limit(0);
$limpas = pd_limpar_publicas();
$auto = pd_agendar_sozinho();
echo "Agendadas sozinhas: " . ($auto['agendadas'] ?? 0) . (!empty($auto['sem_tabela']) ? " (tabela pd_publicacao ainda não existe)" : '') . "\n";
$rec = pd_reconciliar();
echo "Acertados pela peça aprovada: " . ($rec['acertados'] ?? 0) . " · voltaram pra fila: " . ($rec['voltaram'] ?? 0)
   . (isset($rec['erro']) ? " · erro: {$rec['erro']}" : '') . " · arquivos velhos apagados: $limpas\n\n";

$agora = time();
$r = supa('/rest/v1/scheduled_post_targets'
    . '?status=eq.agendado'
    . '&select=id,post_id,social_account_id,platform,placement,caption_override,scheduled_at_override,attempts,'
    . 'scheduled_posts(id,client_id,caption,media_urls,post_type,scheduled_at,batch_name)', 'GET');

if ($r['code'] !== 200) {
    echo "Erro ao consultar alvos: HTTP {$r['code']}\n" . $r['raw'] . "\n";
    exit;
}

$todos = $r['body'] ?? [];
$devidos = array_filter($todos, function($t) use ($agora) {
    $quando = $t['scheduled_at_override'] ?? ($t['scheduled_posts']['scheduled_at'] ?? null);
    if (!$quando) return false;
    $quandoTs = strtotime($quando);
    return $quandoTs !== false && $quandoTs <= $agora;
});

echo count($todos) . " alvo(s) agendado(s) no total, " . count($devidos) . " vencido(s) agora.\n\n";

if (!count($devidos)) { echo "Nada a fazer.\n"; exit; }

foreach ($devidos as $t) {
    echo "-> Alvo {$t['id']} ({$t['platform']}/{$t['placement']}) - post {$t['post_id']}\n";

    // Trava simples contra duas execuções do cron rodando ao mesmo tempo.
    $trava = supa('/rest/v1/scheduled_post_targets?id=eq.' . urlencode($t['id']) . '&status=eq.agendado',
        'PATCH', ['status' => 'publicando'], ['Prefer: return=representation']);
    if (empty($trava['body'][0])) { echo "  . ja sendo processado por outra execucao, pulando\n"; continue; }

    $ok = publicarAlvo($t);
    echo $ok ? "  OK publicado\n" : "  X falhou (veja o error_log)\n";
}

echo "\nConcluido.\n";
