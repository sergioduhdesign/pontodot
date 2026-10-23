<?php
// =====================================================================
// SD Workflow — inicia a conexão com a Meta
//
// Devolve a URL de autorização do Facebook Login for Business, com um
// "state" assinado (HMAC) que carrega quem pediu a conexão e quando
// expira. O callback confere essa assinatura antes de fazer qualquer
// coisa — é o que impede alguém de forjar um retorno da Meta.
//
// Por que não dá pra usar sessão comum aqui: o passo seguinte é a Meta
// redirecionando o NAVEGADOR inteiro para o meta-callback.php — não é
// uma chamada fetch, é navegação de página, então não tem como anexar
// o cabeçalho Authorization nela. O "state" assinado faz esse papel.
// =====================================================================

require_once __DIR__ . '/sd-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail('Metodo nao permitido', 405);

$me = sd_current_user();

// Só quem tem a permissão de conectar contas — admin sempre pode,
// verificado à parte porque este PHP usa a chave de serviço, que
// ignora a RLS (é a RLS que aplicaria has_perm() sozinha).
if ($me['role'] !== 'admin') {
    $p = sd_supabase('/rest/v1/profile_permissions?profile_id=eq.' . urlencode($me['id'])
        . '&permission=eq.social.conectar&select=permission');
    if (empty($p['body'][0])) {
        sd_fail('Você não tem permissão para conectar contas sociais.', 403);
    }
}

$nonce = bin2hex(random_bytes(12));
// volta pra MESMA casa de onde a pessoa pediu (pontodot.ai ou sd.sergioduh.com):
// a sessão do navegador é de cada endereço, voltar pro outro parece "não conectou".
$orig = $_SERVER['HTTP_ORIGIN'] ?? '';
$ret  = in_array($orig, sd_origens(), true) ? $orig : '';
$payload = base64_encode(json_encode([
    'ret'   => $ret,
    'uid'   => $me['id'],
    'nonce' => $nonce,
    'exp'   => time() + 600,   // 10 minutos pra completar o login na Meta
]));
$assinatura = hash_hmac('sha256', $payload, SD_META_APP_SECRET);
$state = $payload . '.' . $assinatura;

$params = http_build_query([
    'client_id'     => SD_META_APP_ID,
    'redirect_uri'  => SD_META_REDIRECT,
    'state'         => $state,
    // Carregando pesado na configuração, como combinado — pede tudo que
    // já sabemos que vamos usar: comentário (equipe e, mais pra frente,
    // a IA), curtida/engajamento, vídeo/reels no Facebook, e a base pra
    // métrica dos dois lados. Nomes sem o prefixo "business_" — confirmado
    // repetidas vezes nessa sessão que é esse o padrão certo pro nosso
    // fluxo (login do Facebook).
    'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,'
                      . 'business_management,instagram_basic,instagram_content_publish,'
                      . 'instagram_manage_insights,instagram_manage_comments,instagram_manage_engagement,'
                      // ANÚNCIOS E LEADS: ler e (no futuro) criar campanhas pausadas,
                      // receber leads de formulário na hora e ligar o aviso da Página
                      . 'ads_read,ads_management,leads_retrieval,pages_manage_ads,pages_manage_metadata',
    'response_type' => 'code',
]);

echo json_encode([
    'url' => 'https://www.facebook.com/v25.0/dialog/oauth?' . $params
]);
