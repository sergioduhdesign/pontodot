<?php
/**
 * Plugin Name: IB — limpeza do cabeçalho
 * Description: Remove o endereço do site que algum plugin imprime como texto solto no <head> (aparecia no topo das páginas).
 */
if (!defined('ABSPATH')) exit;
add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) return;
    ob_start(function ($html) {
        $u = preg_quote(untrailingslashit(home_url()), '#');
        $n = preg_replace('#(?<=>)[ \t\r\n]*' . $u . '/?[ \t\r\n]*(?=<meta name="tec-api-version")#', '', $html, 1);
        return $n === null ? $html : $n;
    });
}, 0);
