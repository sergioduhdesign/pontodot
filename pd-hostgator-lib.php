<?php
require_once __DIR__ . '/pd-site-lib.php';
// =====================================================================
// Pontodot — ACESSO AOS ARQUIVOS DO SITE (cPanel da HostGator, pela API oficial)
// O token do cPanel fica no cofre (servico "hostgator"): usuario = usuário do cPanel,
// rotulo = servidor (ex.: br123.hostgator.com.br ou com :2083), segredo = token.
// Só o servidor da PD usa. Daqui saem: instalar o plugin de SEO, ler/gravar robots.txt, .htaccess etc.
// =====================================================================
if (!function_exists('pdCPCred')) {

function pdCPCred($clientId) {
    $r = sd_supabase('/rest/v1/pd_credenciais?client_id=eq.' . urlencode($clientId) . '&servico=eq.hostgator&select=usuario,segredo,rotulo');
    $c = $r['body'][0] ?? null;
    if (!$c || empty($c['usuario']) || empty($c['segredo']) || empty($c['rotulo'])) throw new Exception('Falta o acesso da HostGator deste cliente no cofre (usuário do cPanel, servidor e token).');
    $h = preg_replace('#^https?://#', '', trim($c['rotulo'])); $h = rtrim($h, '/');
    if (!preg_match('/:\d+$/', $h)) $h .= ':2083';
    if (!preg_match('/^[a-z0-9.\-]+:\d+$/i', $h)) throw new Exception('O servidor do cPanel está em formato inválido.');
    return ['host' => $h, 'user' => $c['usuario'], 'token' => $c['segredo']];
}

/** Chama a UAPI do cPanel (v3) — JSON. */
function pdCP($c, $modulo, $func, $params = []) {
    $ch = curl_init('https://' . $c['host'] . '/execute/' . $modulo . '/' . $func);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $c['user'] . ':' . $c['token']]]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code === 401 || $code === 403) throw new Exception('O cPanel recusou o token (confira usuário, servidor e se o token não expirou).');
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new Exception('Sem resposta do cPanel' . ($err ? ': ' . $err : ' (HTTP ' . $code . ').'));
    return $j;
}
function pdCPErro($j) { return mb_substr(implode(' ', array_map('strval', (array)($j['errors'] ?? []))) ?: 'erro desconhecido', 0, 240); }

/** Testa o token e devolve os domínios da conta. */
function pdCPTestar($clientId) {
    $c = pdCPCred($clientId);
    $j = pdCP($c, 'DomainInfo', 'domains_data', ['format' => 'hash']);
    if (($j['status'] ?? 0) != 1) throw new Exception(pdCPErro($j));
    $d = $j['data'] ?? []; $lista = [];
    if (!empty($d['main_domain'])) $lista[] = ['dominio' => $d['main_domain']['domain'], 'pasta' => $d['main_domain']['documentroot']];
    foreach (['addon_domains', 'sub_domains'] as $k) foreach ((array)($d[$k] ?? []) as $x) $lista[] = ['dominio' => $x['domain'], 'pasta' => $x['documentroot']];
    return ['dominios' => $lista];
}

/** Pasta do site (documentroot) pelo endereço do site do cliente. */
function pdCPPasta($clientId, $c = null) {
    $c = $c ?: pdCPCred($clientId);
    $site = pdSiteCfg($clientId); $host = strtolower(preg_replace('/^www\./', '', (string)parse_url($site['url'] ?? '', PHP_URL_HOST)));
    if ($host === '') throw new Exception('O site do cliente não tem endereço.');
    foreach (pdCPTestar($clientId)['dominios'] as $d) if (strtolower(preg_replace('/^www\./', '', $d['dominio'])) === $host) return $d['pasta'];
    throw new Exception('Não achei o domínio ' . $host . ' nesta conta da HostGator.');
}

function pdCPLer($c, $dir, $arq) {
    $j = pdCP($c, 'Fileman', 'get_file_content', ['dir' => $dir, 'file' => $arq]);
    if (($j['status'] ?? 0) != 1) throw new Exception(pdCPErro($j));
    return (string)($j['data']['content'] ?? '');
}
function pdCPGravar($c, $dir, $arq, $conteudo) {
    $j = pdCP($c, 'Fileman', 'save_file_content', ['dir' => $dir, 'file' => $arq, 'content' => $conteudo, 'from_charset' => 'UTF-8', 'to_charset' => 'UTF-8', 'fallback' => 1]);
    if (($j['status'] ?? 0) != 1) throw new Exception(pdCPErro($j));
}
function pdCPCriaPasta($c, $dir, $nome) {
    $j = pdCP($c, 'Fileman', 'mkdir', ['path' => $dir, 'name' => $nome]);   // se já existe, segue
    return ($j['status'] ?? 0) == 1;
}

/** Instala (ou atualiza) o plugin de SEO do Pontodot direto no site, sem ninguém subir arquivo. */
function pdCPInstalarSeo($clientId) {
    require_once __DIR__ . '/pd-seo-lib.php';
    $c = pdCPCred($clientId); $raiz = pdCPPasta($clientId, $c);
    $mu = $raiz . '/wp-content/mu-plugins';
    pdCPCriaPasta($c, $raiz . '/wp-content', 'mu-plugins');
    pdCPGravar($c, $mu, 'pontodot-seo.php', pdSeoPluginFonte());
    $volta = pdCPLer($c, $mu, 'pontodot-seo.php');
    if (strpos($volta, 'Pontodot SEO') === false) throw new Exception('Gravei o arquivo, mas não consegui conferir de volta.');
    return ['pasta' => $mu, 'arquivo' => 'pontodot-seo.php'];
}

}
