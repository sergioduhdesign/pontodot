<?php
if(($_GET['k']??'')!=='60c2a2b37e63f0a00d296ced'){http_response_code(404);exit;}
header('Content-Type: text/plain; charset=utf-8');
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
global $wpdb; $p=$wpdb->prefix;
echo "utc: ".gmdate('Y-m-d H:i:s')."\n";
foreach (['frmt_form_views'=>'date_created','frmt_form_entry'=>'date_created'] as $tb=>$col) {
  $t=$p.$tb;
  if ($wpdb->get_var("SHOW TABLES LIKE '$t'")!==$t) { echo "sem $tb\n"; continue; }
  echo "$tb (ultimas 30h, por hora UTC; BRT = UTC-3):\n";
  foreach ($wpdb->get_results("SELECT form_id, DATE_FORMAT($col,'%Y-%m-%d %H:00') h, COUNT(*) n FROM $t WHERE $col >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 HOUR) GROUP BY form_id, h ORDER BY h") as $r) echo " form {$r->form_id} | {$r->h} | {$r->n}\n";
}
echo "forms existentes:\n";
foreach ($wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='forminator_forms'") as $r) echo " {$r->ID} | {$r->post_title}\n";
