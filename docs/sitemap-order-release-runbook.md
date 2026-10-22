# Native sitemap ordering maintenance

## Authority and scope

`ops/sitemap-order-release.py` in this SEO repository is the maintained implementation. `ops/tests/test_sitemap_order_release.py` contains its six isolated fault regressions. They were copied byte-for-byte from root's actually deployed, repaired operational source; archive-provenance.json records their hashes.

The project-root `ops/sitemap-order-release.py` will become a thin compatibility wrapper maintained by root. This branch does not edit that root file and does not introduce a second implementation. The installer remains an explicitly executed server operation, outside the runtime plugin ZIP. Manifest0.2.12, fixed ZIP and old0.2.11 rejection/rollback history are unchanged.

## CLI compatibility and paths

All modes, arguments and environment flags are unchanged:

```text
python3 /PATH/TO/SEO-CHECKOUT/ops/sitemap-order-release.py prepare --release-dir /root/dashen-backups/sitemap-order-<UTC>
python3 /PATH/TO/SEO-CHECKOUT/ops/sitemap-order-release.py verify --release-dir /root/dashen-backups/sitemap-order-<UTC>
DSH_SITEMAP_ORDER_APPLY=1 python3 /PATH/TO/SEO-CHECKOUT/ops/sitemap-order-release.py apply --release-dir /root/dashen-backups/sitemap-order-<UTC>
DSH_SITEMAP_ORDER_ROLLBACK=1 python3 /PATH/TO/SEO-CHECKOUT/ops/sitemap-order-release.py rollback --release-dir /root/dashen-backups/sitemap-order-<UTC>
```

These are server invocations, not commands to run against a local workstation. If the existing workflow copies a reviewed script to a root-private server location, keep that workflow and change only its source file to this repository's ops implementation. No SSH/credential logic is added by this migration. A root compatibility wrapper must forward argv/environment to this implementation without importing it as a different API, changing its guards or duplicating its code.

The implementation does not resolve production paths from cwd or `__file__`. Moving it between reviewed checkouts/private server locations leaves these unchanged:

- Bundle target `/srv/dashen/node/current/server/chunks/_/nitro.mjs`
- Cooperative release lock `/srv/dashen/node/.dashen-node-release.lock`
- Root-private release directory policy `/root/dashen-backups/sitemap-order-[0-9TZ]+`, mode0700
- `/usr/local/bin/node --check`, `dashen-node.service`, database `dashen_wp`
- Normal-TLS designated-origin curl checks for `/` and the real post1 XML
- Exact oldSHA, one OLD match, equal-length replacement, option-hash drift guards and preservation of mode/uid/gid

The tests resolve the implementation relative to their own repository path; no fixed local worktree name is needed. The root wrapper should explicitly select a controlled checkout containing the merged ops file. Do not assume the existing archive/source-state checkout has switched to main.

## Existing repaired state and rollback context

The completed rollout is historical and one-time: c2645bde… →1076fe49…, plugin generation168 unchanged. `prepare` is deliberately not a no-op on the already repaired bundle: it refuses a source SHA mismatch. Do not run a new prepare merely to recreate missing evidence.

`verify`/`rollback` need root's existing private plan.json/before.mjs/after.mjs, option hashes and code archive. Public receipts are not a replacement for that context. None of those private files, the whole vendor bundle, live.data or the Node program backup is committed here. The archive receipt contains only checksums/booleans and names of publicly requested XML maps.

A directory-fsync failure after rename restores known original bytes and service but records `directory_sync_verified=false`; this is not durable-sync acceptance. A failed rollback restart can be retried while original bytes are already present. Foreign bytes are refused without overwrite/restart. Append-only attempts/failures retain prior diagnostics. Root remains the sole production writer; the cooperative lock is not CAS against arbitrary writers.

## Local/CI verification

```sh
python3 ops/sitemap-order-release.py --help
python3 -m unittest discover -s ops/tests -p 'test_*.py' -v
```

Help exits before the production lock/path access. The six tests use owned temporary files, a synthetic expectedSHA, mocked production scope/options/service/health, and actual atomic file replacement. They call no real mysql, systemctl, curl, backup, production path or browser. CI runs them on Python3.12 alongside the existing JavaScript/PHP checks; no credentials or licensed vendor source is needed.

The full52-map renderer/XSD replay remains a separate local integration check using the retained public XML/schema collection and a targeted licensed renderer fragment. Neither a full vendor bundle nor private backup is required by CI. The exact original failure/green and root live receipts are in `docs/evidence/sitemap-order-release-20261022/`.

This fixes XML base-field order. It does not establish Google sitemap ingestion, indexing or the cause of the old GSC failure.
