# Google sitemap failure: request-layer investigation

Owner: existing `plugin_dashen_seo_geo`. Source branch:
`research/sitemap-fetch-layer-20261022`, based on `465608e`.
Observed on 2026-10-22, 05:32–05:39 UTC / 13:32–13:39 CST.
This is diagnosis, not a production fix or evidence of successful sitemap processing.
No service/configuration/Cloudflare changes, sitemap submissions, browser actions,
model calls or content mutations were performed by this investigator.

## What the new evidence establishes

1. **The missing boundary is between Google's sitemap processing and origin arrival.**
   All retained dedicated nginx logs were scanned. Within the last 24 hours,
   Google official common-crawler ranges matched 13 requests, independently of UA:
   8 Googlebot requests for `/robots.txt`, 4 InspectionTool requests for the exact
   index, and 1 GoogleOther request for robots. Every one was HTTP200. Each emitted
   Google identity also passed PTR `.googlebot.com` plus forward-address equality.
   There were no common-range requests hiding behind an unrecognized UA.
2. The most recent verified Googlebot robots response was
   **05:08:49 UTC / 13:08:49 CST**, 200. The most recent Googlebot sitemap request
   retained in origin logs remains **October 10, 23:59:25 UTC**, post-1, 200.
   Most recent InspectionTool index requests were **01:32:25 UTC**, both200.
   InspectionTool success is not automatic sitemap processing success.
3. **The current index does not show a generic protocol/compression/cache mismatch.**
   One same-client comparison fetched only the exact index and robots, each once
   through public Cloudflare HTTP/2 and once through normal verified origin TLS
   HTTP/1.1. Index gzip bytes were878 on both routes; decompression/CRC succeeded,
   yielding9983 bytes and SHA `cbc86935aee4e3dc11a873e088d286e73f60f1905ecc2a3396b07c6d53f15ac9`.
   Both returned200/XML, `private, no-store`; the edge reported `DYNAMIC`.
   No redirect, challenge or X-Robots-Tag was observed for this client.
4. Robots was118 decoded bytes on both routes, with matching SHA and explicit
   allow-all plus the two existing sitemap declarations. Edge wasMISS with
   max-age14400. This does not prove every Google edge response is identical.
5. Google Public DNS A/AAAA remainNOERROR and match the prior Cloudflare IPv4/IPv6
   addresses. DS is an authenticated negative answer (AD=true), consistent with an
   unsigned delegation, not DNSSEC SERVFAIL. This resolver is not Google's private
   crawler resolver. Previously recorded IPv6/TLS transport checks were not rerun.
6. Five sitemap502s occurred at00:46:13–14 and01:24:01–03 UTC, all post-1.
   Corresponding nginx errors were upstream `connect() failed`. No verified Google
   sitemap/robots request in this window had5xx. These are real transient origin
   failures; they do not explain the exact index's later failed report with no
   new automatic index request in origin logs.

## Current hypotheses, in investigation order

| Candidate | Evidence and falsifiable next observation | Change justified now? |
| --- | --- | --- |
| Google-side scheduling/processing or report state | No automatic map origin arrivals; exact index remains generic failed in the root's refreshed UI. Need an exact download timestamp/processing flag, not just the date shown by a stale tab. | No server change justified. |
| Sitemap-specific edge rejection or unusual caching | Origin absence is compatible. Current ordinary client isDYNAMIC, but does not represent Google's request. Need a real Google edge request with status/service/ray and origin status. | Only after a specific rule/request is identified. |
| Origin response/protocol fault for Google's distinct request | Currently not reproduced. Current H2 edge and H1 origin decoded bytes are equal; genuine Google robots and inspection requests succeed. | No speculative header/compression changes. |
| Sitemap content/date/hreflang semantics | Existing54 live XML/XSD acceptance belongs to a separate standard repair. Index/static maps also failed before that repair. | Does not explain missing new origin requests; no further XML rewrite for this error. |

This order is an investigation priority, not a probability estimate or a claimed
root cause. In particular, missing origin requests alone cannot distinguish
"Google did not dispatch" from "Cloudflare rejected before origin".

## One next discriminating observation

Root owns Chrome. In Cloudflare **request analytics**, filter exact host
`dashen.wang`, path `/sitemap-index.xml`, from01:25 UTC through current time.
Do not prefilter `verified bot`: mistaken verification is itself a possible failure.
Separate the two known01:32:25 InspectionTool requests. For any other Google/common
crawler request, retain only UTC, verified Google identity, UA category, edge status,
origin status, service/action and CF-Ray. A real edge403/challenge with no origin
response localizes the failure; a matching edge200/origin200 moves investigation
to Google's processing. Adaptive sampling or Security Events alone cannot prove
that no request exists.

Root's subsequent actual Free Traffic UI exposes no host/path/UA/ASN filter and
no HTTP status/CF-Ray field. The verified Search Engine Crawler sample was3.79k,
with1 mitigated row; expansion identified Bytespider on an upload image, blocked
by Hotlink Protection, not Google or a sitemap. This still does not establish
complete edge coverage. Existing restricted credentials only authorize a
destination-bound cache purge, not Analytics Read; they were not repurposed and
permissions were not expanded. Exact edge correlation therefore remains missing.

The refreshed GSC detail has no expanded HTTP status (root reported this at13:39).
If an existing authorized read surface exposes official sitemap API fields,
retain only `path`, `lastSubmitted`, `lastDownloaded`, `isPending`, `type`,
`errors`, `warnings`, and `contents`. `isPending=true` means not processed;
it does not prove a request was never dispatched. An RFC3339 download timestamp
can be compared to origin logs; a UI date without an explicit timezone cannot.

Root then read the actual GSC UI network response without calling a new API.
It was an unnamed tuple, not an official API object. Its timestamp aligns with
the01:31:42 submission, so its numeric/null/boolean fields were not labeled as
HTTP status/isPending/lastDownloaded. The complete9-row DOM has an empty last-read
column;10/22 in the list is the submission date. Detail dates changing after
reload provide no new automatic-fetch evidence. Host status is older10/19 data:
robots/DNS acceptable, recent server connectivity acceptable with past failures.
It is not current sitemap request evidence.

Do not perform another BFM-off experiment, submit again, or add a UA allowlist.
The previous65.468-second diagnostic authorization was consumed and BFM isON.
Cloudflare's free BFM cannot be bypassed with WAF Skip/Page Rules. If evidence
instead identifies BIC or a particular managed rule, root can review a narrowly
scoped exception for that verified traffic and exact path; this report authorizes
no production change.

## Evidence and reproducibility

Under `docs/evidence/sitemap-fetch-layer-20261022/`:

- `origin-google-logs.json`: 24h aggregate, verified public Google identities only;
  official range source/creation/SHA and origin-log scope retained.
- `layer-probe.json`: four requests, safe headers, decoded hashes and gzip checks.
- `google-dns-current.json`: three Google Public DNS answers.
- `sitemap-transient-errors.json`: sanitized5xx/error-mechanism correlation.

The read-only collector runs locally with the project SSH profile and project root
as cwd so its relative known-host/key references remain valid:

```sh
python3 ops/collect-sitemap-origin-readonly.py /path/to/new-evidence.json
```

It executes only log reads remotely. Raw rows/non-Google IPs stay in process memory;
the saved output contains no visitor IPs, cookies, query strings or credentials.
No entire nginx configuration, vendor bundle, backup or article body is saved.

## Primary references checked during this investigation

- [Google sitemap report](https://support.google.com/webmasters/answer/7451001?hl=en):
  failed fetches and parse errors differ; repeated failures can stop retries;
  submitted sitemap redirects are not followed; typeUnknown may mean unprocessed.
- [Google request verification](https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests):
  verify reverse name and forward address, or published crawler ranges.
- [Google sitemap API](https://developers.google.com/webmaster-tools/v1/sitemaps):
  exact processing/download fields and their meanings.
- [Cloudflare BFM](https://developers.cloudflare.com/bots/get-started/bot-fight-mode/):
  service-labeled events; no WAF Skip bypass for free BFM.

Google's robots status/caching specification was also checked; a200 allow-all
response here provides no new robots restriction to fix.

## Subsequent bounded repair

The six historical submitted301 URLs were subsequently changed by root to
internal rewrites. Independent public HTTPS verification at05:54:49 UTC found
all six200/XML with noLocation, DYNAMIC/noAge and canonical-identical bytes.
See `sitemap-alias-candidate-20261022.md` and its production-public receipt.
The exact canonical index was already200 and its Google processing failure
remains unexplained; this compatibility repair is not claimed as that root cause.
