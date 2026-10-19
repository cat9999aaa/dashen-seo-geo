<?php
namespace Dashen\SeoGeo;

/** IndexNow sends URL changes only. It never sends content, credentials or backend URLs. */
function indexnow_valid_url(string $url): bool
{
    return (bool) preg_match('~\Ahttps://dashen\.wang/(?:article|doc|shop|topic|link|skills)/[1-9][0-9]*\z~D', $url)
        || in_array($url, ['https://dashen.wang/about','https://dashen.wang/privacy-policy','https://dashen.wang/terms-of-service','https://dashen.wang/refund-policy','https://dashen.wang/contact'], true);
}

function indexnow_public_url($post): string
{
    if (!$post || $post->post_status !== 'publish' || !empty($post->post_password) || (string)get_option('blog_public','1') === '0') return '';
    $routes = ['post'=>'article','docs'=>'doc','product'=>'shop','community'=>'topic','link'=>'link','dsh_skill'=>'skills'];
    $pages = [8=>'about',22=>'privacy-policy',23=>'terms-of-service',24=>'refund-policy',25=>'contact'];
    if (!isset($routes[$post->post_type]) && !($post->post_type==='page' && isset($pages[(int)$post->ID]))) return '';
    if (!class_exists('GUAQI\\Langs\\Action')) return '';
    // Same language relation key as native ContentService. Unknown routes fail closed.
    $row = \GUAQI\Langs\Action::get_lang_row(['id'=>(int)$post->ID,'type'=>'post_'.$post->post_type]);
    $row = (array)$row;
    if (($row['language_code'] ?? '') !== 'zh-hans') return '';
    $seo = get_post_meta($post->ID, '_guaqi_seo', true);
    if (is_array($seo) && (stripos(json_encode($seo), 'noindex') !== false)) return '';
    $url = 'https://dashen.wang/'.($post->post_type==='page' ? $pages[(int)$post->ID] : $routes[$post->post_type].'/'.(int)$post->ID);
    return indexnow_valid_url($url) ? $url : '';
}

function indexnow_table(): string { global $wpdb; return $wpdb->prefix.'dashen_indexnow'; }

/** Explicit/lazy install: plugin activation is not guaranteed for native runtime plugins. */
function indexnow_install(): void
{
    global $wpdb;
    if (get_option('dashen_indexnow_schema') === '1') return;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $table=indexnow_table(); $charset=$wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        url_hash char(64) NOT NULL,
        url varchar(512) NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        generation bigint(20) unsigned NOT NULL DEFAULT 1,
        ack_generation bigint(20) unsigned NOT NULL DEFAULT 0,
        attempts int unsigned NOT NULL DEFAULT 0,
        next_at bigint(20) unsigned NOT NULL DEFAULT 0,
        lease_token varchar(64) NOT NULL DEFAULT '',
        lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
        state varchar(32) NOT NULL DEFAULT 'queued',
        last_http smallint unsigned NOT NULL DEFAULT 0,
        changed_at bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (url_hash),
        KEY due (next_at,lease_until),
        KEY post_id (post_id)
    ) $charset;");
    // Do not cache a failed migration as successful.
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) update_option('dashen_indexnow_schema','1',false);
}

function indexnow_enqueue_url(string $url, int $post_id): bool
{
    if (!indexnow_valid_url($url) || $post_id<=0) return false;
    indexnow_install(); global $wpdb; $table=indexnow_table(); $now=time();
    // An overlapping edit increments generation without releasing the in-flight lease.
    return false !== $wpdb->query($wpdb->prepare("INSERT INTO $table (url_hash,url,post_id,generation,ack_generation,next_at,changed_at) VALUES (%s,%s,%d,1,0,%d,%d)
        ON DUPLICATE KEY UPDATE generation=generation+1,next_at=VALUES(next_at),changed_at=VALUES(changed_at),state='queued'",hash('sha256',$url),$url,$post_id,$now,$now));
}

/** Enqueue known old canonical URLs for moves, unpublishing and deletion. Never guess a private URL. */
function indexnow_known_urls(int $id): array
{
    indexnow_install(); global $wpdb;
    return $wpdb->get_col($wpdb->prepare('SELECT url FROM '.indexnow_table().' WHERE post_id=%d',$id)) ?: [];
}

function indexnow_capture(int $id, $post=null, bool $update=false, $before=null): void
{
    // Capture only after WordPress saved; resolve native language/access metadata at shutdown.
    if (!$post) $post=get_post($id);
    if (!$post || in_array($post->post_type,['revision','attachment'],true)) return;
    $GLOBALS['dashen_indexnow_changes'][$id]=true;
}

function indexnow_deleted(int $id, $post=null): void
{
    // deleted_post runs only after actual deletion. Previous submissions remain durable tombstones.
    foreach (indexnow_known_urls($id) as $url) indexnow_enqueue_url($url,$id);
    unset($GLOBALS['dashen_indexnow_changes'][$id]);
}

function indexnow_flush_changes(): void
{
    foreach (array_keys($GLOBALS['dashen_indexnow_changes'] ?? []) as $id) {
        $post=get_post($id); $url=indexnow_public_url($post);
        foreach (indexnow_known_urls((int)$id) as $old) if ($old!==$url) indexnow_enqueue_url($old,(int)$id);
        if ($url!=='') indexnow_enqueue_url($url,(int)$id);
    }
    $GLOBALS['dashen_indexnow_changes']=[];
}

function indexnow_enqueue_existing_public(array $ids): array
{
    $result=['enqueued'=>0,'excluded'=>0];
    foreach (array_unique(array_map('intval',$ids)) as $id) {
        $url=indexnow_public_url(get_post($id));
        if ($url!=='' && indexnow_enqueue_url($url,$id)) $result['enqueued']++; else $result['excluded']++;
    }
    return $result;
}

function indexnow_response_state($response): string
{
    if (is_wp_error($response)) return 'retry';
    $code=(int)wp_remote_retrieve_response_code($response);
    return $code===200 ? 'received' : ($code===202 ? 'verification_pending' : 'retry');
}
function indexnow_retry_delay(int $attempt): int { return (int)min(86400,60*(2**min(11,max(0,$attempt-1)))); }

/** At most 100 URLs; atomic claims, lease recovery and per-generation acknowledgement. */
function indexnow_drain_queue(int $limit=100): array
{
    indexnow_install(); global $wpdb; $table=indexnow_table();
    if (!defined('DASHEN_INDEXNOW_KEY') || !preg_match('/\A[a-zA-Z0-9-]{8,128}\z/D',(string)DASHEN_INDEXNOW_KEY)) return ['state'=>'not_configured','sent'=>0];
    $now=time(); $limit=max(1,min(100,$limit)); $token=bin2hex(random_bytes(24));
    // Single UPDATE owns the claim; expired leases are recoverable after worker/process failure.
    $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET lease_token=%s,lease_until=%d WHERE generation>ack_generation AND next_at<=%d AND lease_until<=%d ORDER BY next_at,url_hash LIMIT %d",$token,$now+180,$now,$now,$limit));
    if ($claimed===false) return ['state'=>'database_error','sent'=>0];
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE lease_token=%s",$token), ARRAY_A);
    if (!$rows) return ['state'=>'idle','sent'=>0];
    $urls=[];
    foreach ($rows as $row) if (indexnow_valid_url($row['url'])) $urls[]=$row['url'];
    if (count($urls)!==count($rows)) return ['state'=>'invalid_queue_url','sent'=>0];
    $response=wp_remote_post('https://api.indexnow.org/indexnow',[
        'timeout'=>30,'redirection'=>0,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],
        'body'=>wp_json_encode(['host'=>'dashen.wang','key'=>(string)DASHEN_INDEXNOW_KEY,'keyLocation'=>'https://dashen.wang/'.DASHEN_INDEXNOW_KEY.'.txt','urlList'=>$urls]),
    ]);
    $state=indexnow_response_state($response);
    $http=is_wp_error($response) ? 0 : (int)wp_remote_retrieve_response_code($response);
    foreach ($rows as $row) {
        if ($state!=='retry') {
            // A concurrent change has a greater generation and remains pending after this ACK.
            $wpdb->query($wpdb->prepare("UPDATE $table SET ack_generation=GREATEST(ack_generation,%d),attempts=0,lease_token='',lease_until=0,last_http=%d,state=IF(generation>%d,'queued',%s) WHERE url_hash=%s AND lease_token=%s",$row['generation'],$http,$row['generation'],$state,$row['url_hash'],$token));
        } else {
            $attempt=(int)$row['attempts']+1;
            $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=%d,next_at=%d,lease_token='',lease_until=0,last_http=%d,state='retry' WHERE url_hash=%s AND lease_token=%s",$attempt,time()+indexnow_retry_delay($attempt),$http,$row['url_hash'],$token));
        }
    }
    // Neither response bodies nor WP_Error data are recorded: they may echo the key.
    return ['state'=>$state,'sent'=>count($rows),'http'=>$http];
}

function indexnow_status(): array
{
    indexnow_install(); global $wpdb; $table=indexnow_table();
    return ['configured'=>defined('DASHEN_INDEXNOW_KEY') && (bool)preg_match('/\A[a-zA-Z0-9-]{8,128}\z/D',(string)DASHEN_INDEXNOW_KEY),
        'states'=>$wpdb->get_results("SELECT state,COUNT(*) AS count,SUM(generation>ack_generation) AS pending FROM $table GROUP BY state",ARRAY_A) ?: [],
        'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE generation>ack_generation"),
        'leased'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE lease_until>%d",time()))];
}

add_action('wp_after_insert_post',__NAMESPACE__.'\\indexnow_capture',100,4);
add_action('deleted_post',__NAMESPACE__.'\\indexnow_deleted',100,2);
add_action('shutdown',__NAMESPACE__.'\\indexnow_flush_changes',100);
add_action('dashen_indexnow_drain',__NAMESPACE__.'\\indexnow_drain_queue');
