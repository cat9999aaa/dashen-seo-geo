# Sitemap base-field order proposal (not deployed)

Owner: existing `plugin_dashen_seo_geo`. Separate worktree/branch from reciprocal-language, fallback and lastmod semantics. No runtime plugin version change, ZIP build, production write, service/cache mutation, Chrome/CF action or GSC submission.

## Authoritative production input

Read-only targeted extraction from current production2.9.61 plain Node bundle:

- `/srv/dashen/node/current/server/chunks/_/nitro.mjs`
- Bundle SHA256 `c2645bdedb260e1df2ad2ef386ba42bf7d8ed31dc32218ecf007ab25c5fc6782`
- Renderer fragment SHA256 `7979d30b9f895be1bc5f640456dbbff3059f4047bf4abb5e18865fdc98d4dd3e`
- escapeXml function SHA256 `0f06e2cc5a90822c85d35fc4a20a97e61922858785ee583650763c036e0b1ff4`

The retained readable `layers/base/server/middleware/sitemap.js` has SHA256 `cef49299c53170c9a98cd365a19476d5698ce5a24fb7385c95ef71a5934c6f9c` and the same relevant expression. The directory label does not establish the full source release version. The real installed bundle, not a3.0.45 build, supplies the tested renderer. Encrypted PHP was not read.

## Candidate

`proposals/sitemap-lastmod-order.source.patch` changes two template lines in the readable source. `sitemap-lastmod-order.production-fragment.patch` expresses exactly the same move against the captured installed renderer fragment, for isolated review/test only.

Move the existing `${hreflangs.join("")}${xDefault}` interpolation after the existing conditional lastmod. Result: `loc → lastmod? → XHTML alternates`. No escaping, values, filtering, alternate selection, currentLang/defaultLang, query/prePage, date calculation, HTTP header, error path or request is changed.

The official sitemap tUrl sequence places lastmod before other-namespace extensions. Validation retains the official base XSD bytes and a local minimal XHTML link/attribute declaration to satisfy its strict extension import; the helper is not represented as an official Google XHTML schema.

## Real red / green

`tests/sitemap-ordering-proposal.py` executes the captured production renderer and escapeXml themselves. It reconstructs only values already present in the retained public HTTP XML; it first requires the original renderer to reproduce every applicable original HTTP byte exactly, preventing a different serializer from being mistaken for the real renderer.

- Exact original HTTP replay: index plus51 dynamic children (52 outputs).
- The52nd child, `sitemap-users-1.xml`, is served by an existing nginx static alias. Its current public file hash matches the retained164-byte XML; it is kept byte-identical as a separate control.
- RED:47/52 children fail the combined XSD, with the real old field order.
- GREEN:52/52 children pass; index and separate static sitemap-pages control also pass.
-4311 loc rows remain, same per-child/per-row ownership, URL and date bytes, XHTML href/hreflang/rel attribute bytes, raw namespace declarations, node counts and each response byte length. Root/index and static user bytes are unchanged.
-49 dynamic response hashes change:47 malformed maps plus2 otherwise-valid maps whose formatting whitespace moves. This is not a claim of identical full response bytes. All raw loc/lastmod/xhtml element tokens and field values remain unchanged.
- Source patch applies to a temporary exact readable source copy and passes `node --check`. No vendor file is changed. Patched source SHA256 `b6d743e20f8173d963b914daa5cbc517d95a7c407d9e4a724f2c2adcb4e89c64`.
- Existing plugin npm tests7/7 pass; no plugin business source changes.

Project-root evidence: `docs/evidence/sitemap-ordering-candidate-20261022/` contains production-renderer-source.json, red/green JSON+text, source-patch-apply.json and independent-semantics.json. The frozen52 maps, index/static controls and original schemas remain in `docs/evidence/sitemap-protocol-diagnosis-20261022/`; their recorded SHA values are checked before every replay. Licensed host fragments are retained locally, not vendored into this candidate repository.

```sh
python3 tests/sitemap-ordering-proposal.py \
 --evidence /PROJECT/ROOT/docs/evidence/sitemap-protocol-diagnosis-20261022 \
 --source-json /PROJECT/ROOT/docs/evidence/sitemap-ordering-candidate-20261022/production-renderer-source.json \
 --patch proposals/sitemap-lastmod-order.production-fragment.patch \
 --report /tmp/sitemap-ordering-green.json
```

Omit `--patch` to reproduce the47-map failure. The verifier makes no network request and applies patches only in its temporary directory.

## Separate read-only semantic assessment

Existing fallback proposal is not included. The frozen sample has3 taxonomy rows whose x-default differs from their zh-CN alternate. Choosing the native default-language member slug would change those existing fallbacks as well as stabilize any future appended translation rows. x-default designates a fallback; Google does not require it to equal the site's default language. This is an independent language/fallback policy change requiring its own acceptance, not an XSD ordering correction. This patch preserves all three existing values.

Existing reciprocal/derived-lastmod projection remains unregistered. Its CLI prePage100 call is correctly named, but direct Sitemap class initialization produced142 groups across43 article children, while actual HTTP exposes42×100+65 articles. Public Node HTTP code also sets native route/UA/lang/currency/IP headers and goes through GraphQL initialization, unlike the direct CLI probe. The exact initialization difference remains unproven; no full-data or missing-article inference is based on CLI142. No new CLI walk or PHP resolver attachment is performed here.

## Root review / production gate

This is a source patch proposal, not an active plugin hook or deployment tool. Root must retain the current complete Node code package and rollback artifact, check exact current bundle SHA, and stage only this expression against the actual2.9.61 baseline. Do not replace production with a different retained source tree. Any compiled-bundle application needs exact one-match/old-SHA guards, whole-file before/after review and preservation of existing host fixes; those production actions have not been performed here.

**The standard defect is confirmed; Google sitemap failure causality is not.** The unchanged index/static maps already pass XSD yet GSC reports inability to fetch them too. Google processing, automatic crawler/edge evidence and actual Sitemaps Success/discovery remain independent acceptance. Do not announce GSC resolution from this patch, XML200 or live InspectionTool alone.

Sources: retained official sitemap.xsd/siteindex.xsd from [Sitemaps.org](https://www.sitemaps.org/protocol.html); [Google map construction](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), [Google alternate URLs](https://developers.google.com/search/docs/specialty/international/localized-versions), [GSC sitemap report errors](https://support.google.com/webmasters/answer/7451001). Original collection/report preserves their fetch provenance.
