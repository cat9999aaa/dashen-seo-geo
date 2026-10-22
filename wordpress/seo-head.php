<?php
declare(strict_types=1);
namespace Dashen\SeoGeo;

/** Public routing metadata only. Never return titles, bodies or unpublished IDs. */
function public_translation_links(array $records,array $languages,callable $in_scope,callable $slug): array
{
    $codes=[];$default=(string)($languages[0]['code']??'');
    foreach($languages as $language){
        $code=(string)($language['code']??'');$locale=str_replace('_','-',(string)($language['locale']??''));
        if(preg_match('/^[a-z]{2}(?:-[a-z]+)?$/D',$code)&&preg_match('/^[a-z]{2}(?:-[A-Za-z0-9]{2,8})*$/D',$locale))$codes[$code]=$locale;
    }
    $links=[];$duplicates=[];
    foreach($records as $record){
        $lang=(string)($record['lang']??'');
        if(($record['status']??'')!=='publish'||($record['password']??'')!==''||!isset($codes[$lang])||!$in_scope($record))continue;
        $path=(string)$slug($record);
        if(!preg_match('~^/article/[1-9][0-9]{0,9}$~D',$path))continue;
        if(isset($links[$lang])){$duplicates[$lang]=true;continue;}
        $links[$lang]=['hreflang'=>$codes[$lang],'href'=>'https://dashen.wang'.($lang===$default?'':'/'.$lang).$path];
    }
    foreach($duplicates as $lang=>$_)unset($links[$lang]);
    if(count($links)<2)return [];
    $out=array_values($links);
    if(isset($links[$default]))$out[]=['hreflang'=>'x-default','href'=>$links[$default]['href']];
    return $out;
}

function seo_head_payload(string $path): ?array
{
    if(!preg_match('~^/(?:(?<lang>[a-z]{2}(?:-[a-z]+)?)/)?article/(?<id>[1-9][0-9]{0,9})$~D',$path,$match))return null;
    if(!function_exists('guaqi_current_frontend_url')||guaqi_current_frontend_url(false)!=='dashen.wang'||!class_exists('GUAQI\\Frontend\\ContentScopes')||!class_exists('GUAQI\\Langs\\Action'))return null;
    $languages=topt('languages');if(!is_array($languages)||!$languages)return null;
    $lang=($match['lang']??'')?:($languages[0]['code']??'');
    $post=get_post((int)$match['id']);
    if(!$post||$post->post_type!=='post'||$post->post_status!=='publish'||$post->post_password!=='')return null;
    $row=\GUAQI\Langs\Action::get_lang_row(['id'=>(int)$post->ID,'type'=>'post_post']);
    if(!is_array($row)||($row['language_code']??'')!==$lang||empty($row['trid']))return null;
    global $wpdb;
    $relations=$wpdb->get_results($wpdb->prepare("SELECT element_id,language_code FROM {$wpdb->prefix}guaqi_translations WHERE trid=%d AND BINARY element_type=BINARY %s ORDER BY translation_id",(int)$row['trid'],'post_post'),ARRAY_A);
    if($wpdb->last_error!=='')return null;
    $records=[];$posts=[];$allowed=[];
    foreach($relations as $relation){
        $p=get_post((int)$relation['element_id']);if(!$p||$p->post_type!=='post')continue;
        $posts[(int)$p->ID]=$p;$records[]=['id'=>(int)$p->ID,'lang'=>(string)$relation['language_code'],'status'=>(string)$p->post_status,'password'=>(string)$p->post_password];
    }
    foreach($languages as $language){
        $code=(string)($language['code']??'');
        $ids=array_column(array_filter($records,static fn($r)=>$r['lang']===$code),'id');if(!$ids)continue;
        $args=['post_type'=>'post','post_status'=>'publish','has_password'=>false,'post__in'=>$ids,'posts_per_page'=>count($ids),'no_found_rows'=>true,'fields'=>'ids','ignore_sticky_posts'=>true];
        $args=\GUAQI\Frontend\ContentScopes::applyToQueryArgs($args,'post',$code,true);
        foreach((new \WP_Query($args))->posts as $id)$allowed[(int)$id]=true;
    }
    if(!isset($allowed[(int)$post->ID]))return null;
    $slug=(string)guaqi_get_single_slug($post);
    $canonical=(($lang===($languages[0]['code']??''))?'':'/'.$lang).$slug;
    if($canonical!==$path)return null;
    return ['path'=>$path,'canonical'=>'https://dashen.wang'.$canonical,'alternates'=>public_translation_links($records,$languages,static fn($r)=>isset($allowed[$r['id']]),static fn($r)=>(string)guaqi_get_single_slug($posts[$r['id']]))];
}

function register_seo_head_field(): void
{
    register_graphql_field('gqModule','dashenSeoHead',[
        'type'=>'String','args'=>['path'=>['type'=>'String']],
        'resolve'=>static fn($root,$args)=>wp_json_encode(seo_head_payload((string)($args['path']??'')),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
}
if(function_exists('add_action'))add_action('graphql_register_types',__NAMESPACE__.'\\register_seo_head_field');
