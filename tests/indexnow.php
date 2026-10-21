<?php
namespace {
if (($argv[1] ?? '') === '--wp-integration-code') {
echo <<<'WPTEST'
<?php
// wp eval-file this body with the module available, using connection-local tables only.
require_once '/tmp/dashen-indexnow-module-test.php';
if (!defined('DASHEN_INDEXNOW_KEY')) define('DASHEN_INDEXNOW_KEY', 'integration-test-key-not-real');
global $wpdb;
$original_prefix=$wpdb->prefix;
$schema_filter=static function(){ return '1'; };
add_filter('pre_option_dashen_indexnow_schema',$schema_filter);
$wpdb->prefix='dashen_tmp_indexnow_'.bin2hex(random_bytes(4)).'_';
$table=\Dashen\SeoGeo\indexnow_table();
$assert=static function($ok,$message){ if(!$ok) throw new RuntimeException($message); };
$wpdb->query("CREATE TEMPORARY TABLE $table (url_hash CHAR(64) PRIMARY KEY,url VARCHAR(512) NOT NULL,post_id BIGINT UNSIGNED NOT NULL,generation BIGINT UNSIGNED DEFAULT 1,ack_generation BIGINT UNSIGNED DEFAULT 0,attempts INT UNSIGNED DEFAULT 0,next_at BIGINT UNSIGNED DEFAULT 0,lease_token VARCHAR(64) DEFAULT '',lease_until BIGINT UNSIGNED DEFAULT 0,state VARCHAR(32) DEFAULT 'queued',last_http SMALLINT UNSIGNED DEFAULT 0,changed_at BIGINT UNSIGNED DEFAULT 0)");
$response_filter=null;
try {
 $url='https://dashen.wang/article/49';
 $assert(\Dashen\SeoGeo\indexnow_enqueue_url($url,49),'enqueue failed');
 $response_filter=static function($pre,$args,$target) { return ['response'=>['code'=>500],'body'=>'','headers'=>[]]; };
 add_filter('pre_http_request',$response_filter,10,3);
 $r=\Dashen\SeoGeo\indexnow_drain_queue();
 $row=$wpdb->get_row("SELECT * FROM $table",ARRAY_A);
 $assert($r['state']==='retry' && $row['ack_generation']==0 && $row['attempts']==1 && $row['next_at']>time(),'failure lost item or no backoff');
 remove_filter('pre_http_request',$response_filter,10);
 $response_filter=static function($pre,$args,$target) use($url) { \Dashen\SeoGeo\indexnow_enqueue_url($url,49); return ['response'=>['code'=>200],'body'=>'','headers'=>[]]; };
 add_filter('pre_http_request',$response_filter,10,3);
 $wpdb->query("UPDATE $table SET next_at=0");
 $r=\Dashen\SeoGeo\indexnow_drain_queue();
 $row=$wpdb->get_row("SELECT * FROM $table",ARRAY_A);
 $assert($r['state']==='received' && $row['generation']==2 && $row['ack_generation']==1,'overlapping edit lost during ACK');
 remove_filter('pre_http_request',$response_filter,10);
 $response_filter=static function($pre,$args,$target) { return ['response'=>['code'=>202],'body'=>'','headers'=>[]]; };
 add_filter('pre_http_request',$response_filter,10,3);
 $wpdb->query("UPDATE $table SET lease_token='crashed-worker',lease_until=".(time()-1));
 $r=\Dashen\SeoGeo\indexnow_drain_queue();
 $row=$wpdb->get_row("SELECT * FROM $table",ARRAY_A);
 $assert($r['state']==='verification_pending' && $row['generation']==$row['ack_generation'] && $row['state']==='verification_pending','stale lease or 202 state failed');
 \Dashen\SeoGeo\indexnow_enqueue_url($url,49);
 $wpdb->query("UPDATE $table SET lease_token='other-worker',lease_until=".(time()+180));
 $assert(\Dashen\SeoGeo\indexnow_drain_queue()['state']==='idle','live lease stolen');
 $assert(count(\Dashen\SeoGeo\indexnow_known_urls(49))===1,'durable tombstone missing');
 \Dashen\SeoGeo\indexnow_deleted(49);
 $row=$wpdb->get_row("SELECT * FROM $table",ARRAY_A);
 $assert($row['generation']==4 && $row['ack_generation']==2,'deletion notification missing');
 echo "IndexNow isolated MySQL failure/concurrency/lease/202/deletion tests passed\n";
} finally {
 if ($response_filter) remove_filter('pre_http_request',$response_filter,10);
 $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table");
 $wpdb->prefix=$original_prefix;
 remove_filter('pre_option_dashen_indexnow_schema',$schema_filter);
}

WPTEST;
exit;
}
}
// Standalone policy/protocol tests; DB race integration checks are run in WordPress.
namespace GUAQI\Langs { class Action { public static function get_lang_row($q) { return $GLOBALS['langs'][$q['id']] ?? null; } } }
namespace {
function add_action(...$args) {}
function get_post_meta($id,$key,$single=true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_option($key,$default=false) { return $key==='blog_public' ? 1 : $default; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function is_wp_error($v) { return $v instanceof Exception; }
function wp_remote_retrieve_response_code($v) { return $v['response']['code'] ?? 0; }
require __DIR__.'/../wordpress/indexnow.php';
function check($v,$message) { if(!$v) throw new RuntimeException($message); }
$p=(object)['ID'=>49,'post_type'=>'post','post_status'=>'publish','post_password'=>''];
$GLOBALS['langs'][49]=['language_code'=>'zh-hans'];
check(\Dashen\SeoGeo\indexnow_public_url($p)==='https://dashen.wang/article/49','native SSR route, never WP backend permalink');
foreach(['draft','private','pending','trash'] as $status) { $p->post_status=$status; check(\Dashen\SeoGeo\indexnow_public_url($p)==='', 'nonpublic exclusion '.$status); }
$p->post_status='publish'; $p->post_password='secret'; check(\Dashen\SeoGeo\indexnow_public_url($p)==='', 'password exclusion'); $p->post_password='';
$GLOBALS['meta'][49]['_guaqi_seo']=['robots'=>'noindex,follow']; check(\Dashen\SeoGeo\indexnow_public_url($p)==='', 'noindex exclusion'); unset($GLOBALS['meta'][49]);
$GLOBALS['meta'][49]['guaqi_post_roles']=['type'=>'money','value'=>'9'];
check(\Dashen\SeoGeo\indexnow_public_url($p)==='https://dashen.wang/article/49','paid public preview uses only canonical URL, no body');
$GLOBALS['langs'][49]=['language_code'=>'en']; check(\Dashen\SeoGeo\indexnow_public_url($p)==='', 'unconfigured translated route excluded');
$GLOBALS['langs'][49]=['language_code'=>'zh-hans']; $p->post_type='attachment'; check(\Dashen\SeoGeo\indexnow_public_url($p)==='', 'attachment excluded');
foreach(['https://i.dashen.wang/article/49','https://pan.dashen.wang/a','https://dashen.wang/account','https://dashen.wang/article/49?preview=1','http://dashen.wang/article/49'] as $u) check(!\Dashen\SeoGeo\indexnow_valid_url($u),'reject '.$u);
check(\Dashen\SeoGeo\indexnow_response_state(['response'=>['code'=>202]])==='verification_pending','202 not verified');
check(\Dashen\SeoGeo\indexnow_response_state(['response'=>['code'=>200]])==='received','200 received, not indexed');
foreach([400,403,422,429,500] as $code) check(\Dashen\SeoGeo\indexnow_response_state(['response'=>['code'=>$code]])==='retry','failure retained '.$code);
check(\Dashen\SeoGeo\indexnow_response_state(new Exception('network'))==='retry','network failure retained');
check(\Dashen\SeoGeo\indexnow_retry_delay(2)>\Dashen\SeoGeo\indexnow_retry_delay(1),'exponential retry');
echo "IndexNow policy/protocol tests passed\n";
}
