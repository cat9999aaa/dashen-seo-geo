# 多语言 SEO 候选 · 2026-10-22

本候选归既有 SEO 插件。生产 registry 只存在 0.2.10，本分支独立准备下一可用 0.2.11；没有自行合并或部署。

## 行为

- 通过官方静态替换 `components/CommonCustomCode` 渲染原生 `@guaqi/components/common/customCode`，保留已有 CSS/link 与原生失败状态。
- 每次文章服务端首屏，经当前 H3 event.$fetch 和明确 Host=dashen.wang 调用已注册的只读原生 action。WordPress 只返回真实公开 URL，不返回标题、正文或未发布 ID。原生语言表、已配置 languages、WP_Query ContentScopes、publish、无密码、当前规范路径全部通过才输出。
- 公开 URL seed 在 HTML 中供水合复用，不再重复请求；SPA 导航立即清除旧集合，迟到响应不跨页覆盖。没有私密数据缓存。
- 标准 Unhead head entry 生成自身及所有已发布对应页的互惠 hreflang；不从语言首页推断文章译文，不发布草稿。
- 原生 JSON-LD 中明确为 Organization 且使用站点 publisher ID 的 UME 改为当前真实 og:site_name；文章悬空的站点 publisher 引用补同品牌实体，不把作者身份当发布者身份。Person 作者保持原记录。文章相对实体 ID 改为本站绝对 ID；空 articleSection 删除；先统一全图实体与引用的相对 ID，再判定并移除确实悬空的 FAQ 引用。已有非 UME 自定义发布者和带 id 的自定义 JSON-LD 保留。
- /en、/es 等原生配置语言首页 canonical/og:url 指向该语言页，不凭空加首页语言等价关系。

## 原生与上游契约

基线是本项目保留的官方 GuaQi 2.9.7 源码：

- `layers/base/app/plugins/01-runtime-plugins.server.js` 与 `.client.js` 的 Nuxt `provide.runtimePluginHost` 正式导出同一个宿主 runtime。
- `layers/base/app/runtime-plugins/createAppHostRuntime.js` 文件注释标为“运行时插件可依赖的稳定宿主接口”，提供 router/actions/components。
- 静态组件替换不位于 `RuntimePluginsModule` 的 Symbol provide 内，因此通过 Nuxt 已导出的 Vue globalProperties.$runtimePluginHost 取得该对象，再向子级原生 host proxy provide。未自建 runtime/global fallback。
- Nuxt 本地 `node_modules/nuxt/dist/app/nuxt.js` 的 `nuxtApp.provide` 将插件导出注册到 Vue globalProperties；`$nuxt.ssrContext.event.$fetch` 使用当前原生请求。此项以本机与生产基线源码为证，不承诺厂商以后版本不变。
- [Nuxt provide helper](https://nuxt.com/docs/3.x/migration/plugins-and-middleware)、[Unhead tags:resolve](https://unhead.unjs.io/docs/head/api/hooks/tags-resolve)、[Google alternate 规则](https://developers.google.com/search/docs/specialty/international/localized-versions)。Google 允许 HTML、HTTP、XML 三种等价方式；这次补 HTML 的原因是原生 XML 只有原文 loc 下的语言组，缺少每个译文独立 loc 项。

## 已执行验证

1. `npm test`：6 个真实输入边界测试通过；包括 action 查询注入拒绝、公开互惠集合、语言首页、空分类/实体 ID、站外与陈旧/非法元数据、自定义 JSON 保留。
2. `node tests/seo-runtime.mjs`：实际官方 SDK ZIP 构建、原生 customCode 源码编译、真实 Unhead 首屏 HTML、原生 CSS 保留、水合不重复请求、客户端 SDK bundle、SPA 陈旧响应隔离均通过。集成测试曾发现静态 Symbol 缺失与 SSR hydration 重复 link，已修正并回归。
3. 远程 `/tmp/dashen-seo-candidate-20261022` PHP lint 与全部 10 份非生产集成 PHP 脚本通过；实际 WordPress HTML token processor 和原插件安全回归包含在内。`tests/wordpress-integration.php` 会操作真实附件，本轮未执行。
4. 在生产 WP 进程中仅 require 候选文件、读取数据：9/39342/39356 返回同一 4 条集合，76/39352 返回同一 3 条集合；未公开草稿样本和 `/es/article/9` 非规范路径返回 null。读取当前数据首次约 16ms，后续约 1–5ms；这是 PHP/数据库处理，不是端到端 HTTP 增量。

## 独立审阅回归

补充官方真实 FAQPage `@id=/article/9/#faq` 输入，先见旧实现错误移除合法 mainEntity，再修复全图 ID 归一化顺序；真实 SDK 首屏确认引用与实体共同绝对化。publisher 名称修改必须满足 Organization 类型；opaque `/#organization` ID 的 Person 即使名称 UME 也保留原值。两项补充测试先失败，修复后 6/6 与真实 SSR/SPA 全部通过。

## CI 与构建范围

GitHub Verify 包含 JavaScript边界/头像回归与自有Vue组件编译，以及 PHP lint、10份隔离行为脚本（真实WordPress 7.1.3 token processor、Imagick与mbstring）。不把许可宿主源码或客户目录复制进公开CI；官方SDK ZIP构建、原生customCode和Image/Avatar集成测试在既有本机宿主基线运行，单独保留构建收据。会操作实际附件的wordpress-integration.php不列入无人值守CI。

## 发布前与发布后

总控审阅后使用本候选 0.2.11 和现有官方 SDK 构建及原生 PackageStore/Assignments 安装流程，保留精确旧包、分配与回退收据。不得直接改生产已安装源码或宿主。

必须现场核验：首页三语言、5 个原文/译文 URL 初始 HTML 含一致互惠集合和自指 canonical；草稿地址继续 404；原生 customCode 保留；实际浏览器 SPA 切换与登录页面可用；TTFB 增量和 Node 错误日志。SDK 集成测试代替不了生产 H3→原生 action→WP HTTP 链验收。失败时恢复原包和原生分配并按既有流程同步。

## 抓取状态

Google sitemap 处理失败独立记录于项目 `docs/seo-crawl-remediation-20261022.md`。本候选不会把 HTTP200、重新提交回执或 hreflang 上线写成 Google 收录/地图成功。
