# Multilingual SEO candidate

Goal: complete public article translation links and correct verified native metadata without publishing content or editing the host.

1. Add a public, read-only native action that returns only the current frontend's published, password-free members of an article translation group. Obtain routes and language codes from native CMS configuration. Never return body, draft, private, or out-of-scope IDs.
2. Wrap the official CommonCustomCode component through its supported replacement target, preserving the original component. Register a standard Unhead tags:resolve hook. Await public metadata on SSR; discard stale SPA responses and clear old alternates immediately on navigation.
3. Add reciprocal HTML alternates only from the checked published group. Correct localized-home canonical/og:url, native UME publisher name using the actual site name, missing article publisher entity using the site brand, relative article IDs and dangling FAQ references, and empty articleSection. Preserve unrelated schema/custom code.
4. Test pure transformations, metadata privacy boundaries and actual official SDK + Unhead SSR HTML. Keep the package version unchanged until root review and release. Root owns deployment, native cache reconciliation and production acceptance.

Risks: the read-only action adds one backend request for article navigation; measure it. Current sitemap fetching failure is separate and remains open until GSC actually processes it.
