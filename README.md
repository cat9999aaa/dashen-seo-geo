# 大神 Seo geo 运行时插件

插件发布源码版本为 0.2.12。0.2.11 曾因缺少原生 action 声明被原生激活流程拒绝；实际线上版本与验收以部署收据为准。归属大神网，源码位于本目录；生产通过瓜奇运行时插件 ZIP 安装和为 `dashen.wang` 单独启用。安装包也放在 GitHub Release：<https://github.com/cat9999aaa/dashen-seo-geo>。

## 功能

- WordPress 普通上传及 sideload 的 JPEG/PNG 转为 WebP，验证格式后删除本次原文件。转换失败时上传报错并清理，不保留原格式。
- 已经是 WebP 的图片校验后保留。GIF、SVG 等未支持的新上传图片报错；其他非图片附件不受影响。
- 新建附件且无人工 alt 时，从有描述性的标题补 WordPress 原生 `_wp_attachment_image_alt`；相机流水号不编造描述。
- 注册瓜奇 MCP 工具 `dashen.upload_webp`，调用者需 `upload_files` 权限。参数为 `filename`、`mimeType`、`base64`，可选 `title`；返回 `id`、`url`、`mimeType`、`alt`。
- 注册只读瓜奇 MCP 工具 `dashen.seo_audit`，调用者需 `edit_posts` 权限。它检查已发布文章是否有有效作者和瓜奇语言关联，返回缺项，不自动更改文章。
- `gqPage.single` 只放行 `post_type=page`。非 page，以及非 `publish` 且查看者不是作者也没有 `manage_options` 的 page，在字段 resolver 之前返回 GraphQL `404`。
- 文章、文档、商品、链接在状态变为 `publish` 后，若没有特色图，排队向 `eva.dashen.wang` 请求封面。文章/文档/链接 1200×675 主图另写 1200×1200 列表方图；商品 1200×1200。列表模块请求的 WordPress `thumbnail` 指向该 1200 方图，避免手机 1:1 卡片把 150×150 放大发糊。链接的 `site_icon` 同步到同一张特色方图，首页官方文档卡片不再用旧 16:9 附件的 150 缩略图。商品列表索引随封面刷新重建，商店卡片用 1200 方图。请求失败时保留原图，不回退本地 Imagick 字卡或 ComfyUI 底图。头像、登录大图、会员个人封面和默认特色图附件 50 不覆盖。
- 封面由 Worker HTTP API 生成 WebP（质量 80）。令牌来自环境变量或常量 `EVA_API_TOKEN`，不进 git。文章另注册原生尺寸 `dashen_sq`（1200×1200）；列表模块需在构建器选择该尺寸才会用方图，默认 `MEDIUM_LARGE` 仍是 16:9 主图。
- 后台「工具 → 大神封面」可排队替换已发布内容封面，可覆盖推广图 54/55/56（1280×320），也可临时生成一张新推广图。已有非保护特色图原地覆盖。

通过官方 `components/CommonCustomCode` replacement 包装并保留原生自定义代码组件，以标准 Unhead 修正当前站点的原生 JSON-LD 及已配置语言首页 canonical。文章双向 hreflang 仅来自原生语言关联中、当前前端范围内已发布且无密码的文章，SSR 输出后以只含公开 URL 的 JSON seed 交给客户端；草稿、私密、未配置语言及站外内容不输出。不接管 sitemap，不改原文或可见性。服务器不保留原图；媒体附件仍可能包含 WordPress 需要的多个 WebP 子尺寸。

2026-10-22 SEO 候选的契约、测试、部署与回退要求见 `docs/multilingual-seo-candidate-20261022.md`。当前候选未自行部署。

## 验证与更新

`tests/test.php` 是 PHP+Imagick 隔离测试；`tests/seo-audit.php` 检查文章发布审计；`tests/eva-svg.php` 检查本地栅格尺寸与字体；`tests/eva-api.php` 检查 HTTP 封面客户端；`tests/eva-cover.php` 检查发布排队与保护附件；`tests/draft-acl.php` 检查草稿 ACL。生产安装包在 `dist/`，ZIP 根目录含 `manifest.json`。

修改后先完成测试、递增版本，再通过瓜奇插件管理的 ZIP 安装与同步流程更新。不要直接修改瓜奇本体或生产已安装包。运行必需的 Eva-Ming OTF 在 `wordpress/assets/fonts/`，官方 `runtime-plugin.mjs build` 会打进 ZIP。源码树里 `assets/fonts/` 仍作备份，生产路径优先 `wordpress/assets/fonts/`。

## Native sitemap operational maintenance

The separately reviewed host XML field-order repair is maintained under `ops/sitemap-order-release.py`, with six isolated fault tests and redacted root deployment receipts. It is not part of the plugin ZIP build. See [operational paths and CLI compatibility](docs/sitemap-order-release-runbook.md) and [ordering evidence](docs/sitemap-ordering-proposal-20261022.md).
