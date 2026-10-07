# 大神 Seo geo 运行时插件

当前版本 0.1.8。归属大神网，源码位于本目录；生产通过瓜奇运行时插件 ZIP 安装和为 `dashen.wang` 单独启用。安装包也放在 GitHub Release：<https://github.com/cat9999aaa/dashen-seo-geo>。

## 功能

- WordPress 普通上传及 sideload 的 JPEG/PNG 转为 WebP，验证格式后删除本次原文件。转换失败时上传报错并清理，不保留原格式。
- 已经是 WebP 的图片校验后保留。GIF、SVG 等未支持的新上传图片报错；其他非图片附件不受影响。
- 新建附件且无人工 alt 时，从有描述性的标题补 WordPress 原生 `_wp_attachment_image_alt`；相机流水号不编造描述。
- 注册瓜奇 MCP 工具 `dashen.upload_webp`，调用者需 `upload_files` 权限。参数为 `filename`、`mimeType`、`base64`，可选 `title`；返回 `id`、`url`、`mimeType`、`alt`。
- 注册只读瓜奇 MCP 工具 `dashen.seo_audit`，调用者需 `edit_posts` 权限。它检查已发布文章是否有有效作者和瓜奇语言关联，返回缺项，不自动更改文章。
- `gqPage.single` 只放行 `post_type=page`。非 page，以及非 `publish` 且查看者不是作者也没有 `manage_options` 的 page，在字段 resolver 之前返回 GraphQL `404`。
- 文章、文档、商品、链接在状态变为 `publish` 后，若没有特色图，排队生成 1200×675 封面。头像、登录大图、会员个人封面不生成。
- 封面是两套独立产物再叠加：打包的 Eva-Ming 字卡一层，本机 ComfyUI 的 EVA 风格动漫一层。插件只做合成。队列忙或失败时退回纯字卡。默认动漫层不透明度 40%。
- 后台「工具 → 大神封面」可排队替换已发布内容封面，可覆盖推广图 54/55/56（1280×320），也可临时生成一张新推广图。

不接管瓜奇原生页面 SEO、GEO、canonical 或 sitemap。服务器不保留原图；媒体附件仍可能包含 WordPress 需要的多个 WebP 子尺寸。

## 验证与更新

`tests/test.php` 是 PHP+Imagick 隔离测试；`tests/seo-audit.php` 检查文章发布审计；`tests/eva-svg.php` 检查封面尺寸、打包字体与两套叠加；`tests/eva-cover.php` 检查发布排队；`tests/draft-acl.php` 检查草稿 ACL。生产安装包在 `dist/`，ZIP 根目录含 `manifest.json`。

修改后先完成测试、递增版本，再通过瓜奇插件管理的 ZIP 安装与同步流程更新。不要直接修改瓜奇本体或生产已安装包。
