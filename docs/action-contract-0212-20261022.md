# SEO 0.2.12 native action declaration

The 0.2.11 production activation was rejected by the native registry: `runtime plugin server action is undeclared: plugin.dashen.seo.head`. Root reported a guarded automatic rollback to 0.2.10 / generation166. The fixed 0.2.11 archive is preserved; this release uses a new version.

## Change

The only runtime change is manifest `version: 0.2.12` and `actions: ["plugin.dashen.seo.head"]`. PHP native public metadata/ACL, Node action query compilation, CommonCustomCode wrapper, Image/Avatar normalization, useHead and SSR/client paths are byte-identical to merged main `61a899dd`. No XML sitemap, original content/date or deployment assignment is changed here.

The official SDK carries `manifest.actions` into the production manifest. Native `createRuntimePluginRegistry` checks the server action map against those declarations before preparing the snapshot. Root read the same `assertDeclared` function from production2.9.61's plain `nitro.mjs`; its bundle SHA is `c2645bdedb260e1df2ad2ef386ba42bf7d8ed31dc32218ecf007ab25c5fc6782`. The exact function and source hashes are in `docs/evidence/action-contract-0212-20261022/native-contract.json`. No encrypted source was read.

## Red / green and fixed archive

- `native-red.txt`: the real native registry rejects 0.2.11 with the exact production error; no mocked declaration checker.
- `native-green.txt`: source manifest passes native prepareIsolated and local activate. Missing declaration and missing implementation remain negative controls.
- `native-fixed-zip.txt`: the same registry loads the actual compiled server export and manifest from the fixed 0.2.12 ZIP. No packageFailures; its action compiles the public query, with exact ownership.
- `ssr-spa-fixed-zip.txt`: actual compiled SeoHead + original native customCode + Unhead SSR, preserved host custom style, hydration seed with zero duplicate metadata requests, SPA stale-response and unpublished-group isolation.
- `images-fixed-zip.txt`: actual fixed ZIP Image/Avatar, explicit alt preservation and escaping, with native components and static replacement transform.
- `unit-red.txt` / `unit-green.txt`: regression now included in normal `npm test` (7/7). Avatar regression and all three owned Vue components compile locally.

The initial image integration invocation failed because the lean SDK tree omits native component-transform/config files. The test now accepts the retained native source separately via GUAQI_SOURCE_ROOT; the initial harness error is preserved as `images-harness-initial-error.txt`. No runtime change or rebuild was needed.

Archive: `dist/dashen-seo-geo-0.2.12.zip`, mode0600, **21692010 bytes**, SHA256 **76640041e227f817ee613c06183d32cbed56601007bae2fa1ecc42c08c638812**. Built exactly once with official SDK script SHA `c158bc742d5f50f8dd1c5b407a599cc0720a299210cbe371f68806d99d7c0705`; randomized SDK scopes mean this archive must be retained, not rebuilt to the same published identity. Full receipt: `fixed-build.json` in the evidence directory.

## Reproduce without rebuilding

Use the existing licensed SDK/native sources; neither is copied into this repository. After safely extracting the fixed ZIP to a local test-only directory:

```sh
export GUAQI_CLIENT_ROOT=/PATH/TO/SDK/client
export GUAQI_SOURCE_ROOT=/PATH/TO/retained/native/client
export GUAQI_TEST_ZIP=/PATH/TO/dashen-seo-geo-0.2.12.zip
export GUAQI_TEST_PACKAGE_ROOT=/PATH/TO/extracted-fixed-zip
node tests/native-action-contract.mjs
node tests/seo-runtime.mjs
node tests/render-images.mjs
npm test
```

The action source-mode test explicitly uses placeholder component objects to isolate declaration checks; fixed-ZIP mode uses the real compiled registrations. Registry code is native in both. SSR metadata HTTP responses and route changes are bounded synthetic fixtures; they do not certify a live H3→native action→WordPress request. CI runs portable declarations/behavior/Vue checks and the existing PHP suite; licensed host integration is separately recorded locally.

## Deployment boundary

Root owns fresh generation/baseline capture, package-only installation and production acceptance. Do not reuse old generation164/165 plans after rollback or the enhancer release. Expected sequencing is root enhancer0.4.61 then a fresh SEO0.2.12 plan; only actual native state decides the generation. Direct Node packageFailures, SSR HTML/canonical/hreflang/customCode, SPA and error logs still require production acceptance. This declaration repair does not resolve or explain the independent Google sitemap processing failure.
