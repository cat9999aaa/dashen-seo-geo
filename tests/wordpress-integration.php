<?php
// Run only with an explicit WordPress path, on the target host.
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require getenv('DASHEN_WP_LOAD') ?: '/srv/dashen/wp/wp-load.php';
if (!function_exists('Dashen\\SeoGeo\\convert_upload')) require (getenv('DASHEN_PLUGIN_FILE') ?: __DIR__.'/../wordpress/plugin.php');
require_once ABSPATH.'wp-admin/includes/media.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/image.php';
if (!\GUAQI\Common\Mcp\Registry::hasTool('dashen.upload_webp')) throw new Exception('MCP tool missing');
$administrators = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!$administrators) throw new Exception('Administrator account unavailable for test');
wp_set_current_user((int) $administrators[0]);
$im = new Imagick(); $im->newImage(80, 50, new ImagickPixel('red'), 'png');
$bytes=$im->getImageBlob(); $id=0;
try {
  $result=\GUAQI\Common\Mcp\Registry::callTool('dashen.upload_webp', ['filename'=>'mcp-photo.png','mimeType'=>'image/png','base64'=>base64_encode($bytes),'title'=>'MCP 图片上传测试']);
  $id=(int)($result['id']??0);
  if (!$id || get_post_mime_type($id)!=='image/webp') throw new Exception('MCP image MIME mismatch');
  $file=get_attached_file($id);
  if (!is_file($file) || getimagesize($file)['mime']!=='image/webp') throw new Exception('MCP file invalid');
  if (file_exists(substr($file,0,-5).'.png')) throw new Exception('MCP original remains');
  echo "PASS MCP WebP upload\n";
} finally { if ($id>0) wp_delete_attachment($id,true); }
