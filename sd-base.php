<?php
// Endereço oficial da plataforma, num lugar só.
// Na virada pro pontodot.ai basta UMA linha em sd-private/sd-config.php:
//     define('PD_BASE_URL', 'https://pontodot.ai');
// Sem ela, tudo segue em sd.sergioduh.com (as duas casas funcionam ao mesmo tempo).
if (!function_exists('sd_base')) {
    function sd_base() {
        return rtrim(defined('PD_BASE_URL') ? PD_BASE_URL : 'https://sd.sergioduh.com', '/');
    }
    // origens aceitas para CORS e para links vindos de fora
    function sd_origens() {
        return ['https://sd.sergioduh.com', 'https://pontodot.ai', 'https://www.pontodot.ai'];
    }
}
