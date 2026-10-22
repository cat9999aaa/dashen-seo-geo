# 六条已提交 sitemap 旧地址：内部 rewrite 候选

归属 `plugin_dashen_seo_geo`。只准备源码/计划/隔离验证；生产写入由 root 独占。
不改 SEO 版本、固定 ZIP、XML、原文、日期、分页、原生 Node 或 robots。

## 已证的问题与范围

根代理于2026-10-22约13:45 CST通过已有 Chrome 完整 DOM 核准9条提交：

| 已提交精确路径 | 当前源站 | 本候选 |
| --- | --- | --- |
| `/sitemap-index.xml` | 200 XML | 原样保持 |
| `/sitemap-posts-post-1.xml` | 200 XML | 原样保持 |
| `/sitemap-pages.xml` | 200 XML | 原样保持 |
| `/sitemap.xml` | 301 → index | 内部 rewrite → index，外部200 |
| `/wp-sitemap.xml` | 301 → index | 内部 rewrite → index，外部200 |
| `/post-sitemap.xml` | 301 → index | 内部 rewrite → index，外部200 |
| `/wp-sitemap-posts-post-1.xml` | 301 → post-1 | 内部 rewrite → post-1，外部200 |
| `/wp-sitemap-posts-post-2.xml` | 301 → post-2 | 内部 rewrite → post-2，外部200 |
| `/wp-sitemap-posts-post-3.xml` | 301 → post-3 | 内部 rewrite → post-3，外部200 |

根代理纠正了列表日期含义：10/22是提交列，9条上次读取列实际为空。
详情界面日期反复变化，观测到的未命名 RPC tuple 不能解释为官方 API 字段。
不把这些日期当自动地图下载证明。

[Google 官方报告文档](https://support.google.com/webmasters/answer/7451001?hl=en)
在 Sitemap URL 段明确提交 URL 按精确地址记录且不跟随重定向。因此6条301有
独立、可整改的兼容问题；本候选**不能解释或宣称解决其余3条直出200仍失败**。

## 最小字节差分

- 目标：`/etc/nginx/sites-available/dashen`，已确认 enabled 软链接指向该文件。
- 原模式0644、uid0、gid0。
- before SHA256：`8bf9cd76c7e42ef968e88903749aba86373f66629d57ba2073b4bb31213afc3c`
- after SHA256：`ddef81388771687f75b1774e0e24dbd9b89010d4c307ff1d01ea4dee0e102537`
- 仅 HTTPS `server_name dashen.wang` 已有6条 exact location 的
  `return 301 https://dashen.wang/目标;` → `rewrite ^ /目标 last;`。
- HTTP主站、www、i/pan虚拟主机、其他旧别名及全部现有location数量保持。
  特别 `/wp-sitemap-posts-page-1.xml` 不在这9行里，仍保留原301。
- 不复制或冻结地图文件；每次请求通过同一个原生canonical处理器返回当时真实XML。
  原query由nginx正常保留，不把query拼为任意文件或上游地址。

公开差分：`docs/evidence/sitemap-fetch-layer-20261022/alias-candidate.patch`。
`ops/sitemap-alias-plan.py` 只生成本机0600候选及无上下文差分，没有SSH、安装、reload
或production apply命令；完整before SHA/HTTPS anchor/6条old规则必须匹配，否则拒绝。
输出目录必须新建，重复运行不覆盖旧证据。

```sh
python3 ops/sitemap-alias-plan.py \
  --before /private/readonly-export/dashen \
  --out-dir /private/new-plan-directory
python3 -m unittest discover -s ops/tests -p test_sitemap_alias_plan.py -v
```

## 实际红绿

断网自有容器复用现有 `dashen-recovery-runtime:ubuntu24-php83-20261022`，其nginx
为同生产1.24.0(Ubuntu)。只读导出的16份完整nginx配置未进Git；其私有目录0700、
文件0600。没有复制生产TLS私钥。隔离配置仅替换证书/私钥为新建fixture，并额外
提供localhost3000静态公开XML假上游及公开robots/pages文件；没有实际Node、PHP、DB。
容器network=none、无host端口、finally只删除本次UUID命名容器。

- before/after完整配置分别 `nginx -t` 通过。
- 原规则12请求（6路径×无query/有query）全301，要求200的真实断言失败。
- 候选12请求全200，XML SHA/字节数与这次源站5份公开canonical XML完全一致。
- 6条query `v=1&next=%2Fprivate%3Fx%3D1` 原样转发；没有改写为任意目标。
- 10项negative（错误后缀/子目录/超出页别名/未纳入两旧别名/HTTP/www/i/pan）及
  4项canonical/robots控制请求，before/after状态、body SHA、header一致。
- 4项纯计划测试：只改6行、漂移/重复拒绝、缺失alias拒绝、重复scope拒绝，通过。

隔离证据：`nginx-before-ten-red.json`、`nginx-after-ten-green.json`、`alias-isolation-summary.json`。
首轮8项negative汇总保留`alias-first-eight-summary.json`；扩展重跑复用了旧详细JSON
路径，原8项详细件未单独保存，不把新10项当作旧8项原件。新10项是完整真实重跑。
原始生产11 GET（9已提交+post2/3 canonical）记录在`alias-before-live.json`，均正常TLS。
公网6旧地址的before缓存/状态另在`alias-before-edge.json`。

```sh
# 预期red：原6别名仍301。先保留输出，不把退出1当测试设施故障。
python3 ops/tests/sitemap_alias_nginx_fixture.py \
  --private-source /private/export-root \
  --output /private/before-red.json --expect-fixed
# 预期green：对比同一before并检查query/negatives/control。
python3 ops/tests/sitemap_alias_nginx_fixture.py \
  --private-source /private/export-root \
  --candidate /private/new-plan-directory/after.conf \
  --baseline /private/before-red.json \
  --output /private/after-green.json --expect-fixed
```

## Root 发布与回退清单（尚未执行）

1. Root唯一生产writer；整个备份/验证/热重载/验收/必要回退期间持统一
   `/etc/nginx/sites-available/.dashen-nginx-config.lock`。合作锁不防任意外部root写者。
2. fresh确认目标/父路径是预期物理文件、enabled链接绑定、before SHA8bf…及原mode/uid/gid；
   私有0700目录备份原件0600，另存安全metadata。任何漂移停止，不能宽松套patch。
3. 只将已审after字节按原mode/uid/gid写同目录临时文件；重核before与metadata，原子rename、
   fsync目录。实机`nginx -t`失败即恢复原件，不继续reload。
4. 使用已审gzip6工具同等master身份/worker代际检查做graceful reload，≤30秒等新worker
   连续两次稳定，不等全部旧长连接退出。不要重启Node/FPM/nginx来掩盖问题。
5. 正常TLS `--resolve dashen.wang:443:源站`逐一验证6alias直接200、无Location、正确XML/MIME，
   与fresh canonical identity响应SHA及URL/日期/namespace/数量相同；检查query和上面negative。
   index/robots及另3提交保持原语义，CMS/pins/gen171/包和Node无需改变。
6. 再验公网6alias。若有旧301缓存，先看CF cache-status/Age；仅在确证缓存时由root决定
   精确6URL purge，不全站清缓存、不改变防护。未实际消除公网301不得宣称发布验收完成。
7. 任一失败恢复原件，仅接受current为expected after或已还原before；foreign字节拒绝覆盖。
   原mode/uid/gid原子恢复+目录fsync、`nginx -t`、同样热reload及worker等待、复查原状态。
   日志收据失败不能阻断回退；完整保存原失败/回退结果，不覆盖旧证据。

Root实际运行前仍须独立审查自己的发布helper。这里记录候选与操作清单，尚无生产执行收据。
不重新提交任何地图；alias直出兼容修复与Google异步处理成功分别验收。
