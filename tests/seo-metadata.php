<?php
declare(strict_types=1);
$file=__DIR__.'/../wordpress/seo-head.php';if(is_file($file))require $file;
function check($yes,$why):void {if(!$yes)throw new RuntimeException($why);}
check(function_exists('Dashen\\SeoGeo\\public_translation_links'),'public translation filter is missing');
$languages=[['code'=>'zh-hans','locale'=>'zh_CN'],['code'=>'en','locale'=>'en_US'],['code'=>'es','locale'=>'es_ES']];
$records=[];
foreach([[9,'publish','zh-hans',''],[39342,'publish','en',''],[39356,'draft','es',''],[40001,'private','es',''],[40002,'future','es',''],[40003,'publish','es','password'],[40004,'publish','es',''],[40005,'publish','ko','']]as[$id,$status,$lang,$password])$records[]=['id'=>$id,'status'=>$status,'lang'=>$lang,'password'=>$password];
$out=Dashen\SeoGeo\public_translation_links($records,$languages,static fn($r)=>$r['id']!==40004,static fn($r)=>'/article/'.$r['id']);
check(array_column($out,'href')===['https://dashen.wang/article/9','https://dashen.wang/en/article/39342','https://dashen.wang/article/9'],'draft/private/future/password/out-of-scope/unconfigured translation leaked');
check(array_column($out,'hreflang')===['zh-CN','en-US','x-default'],'native locale/default mapping incorrect');
$out=Dashen\SeoGeo\public_translation_links($records,$languages,static fn($r)=>true,static fn($r)=>'//evil.example/'.$r['id']);
check($out===[],'foreign or malformed native slug accepted');
echo "PASS public translation statuses, passwords, scope, configured language and route guards\n";
