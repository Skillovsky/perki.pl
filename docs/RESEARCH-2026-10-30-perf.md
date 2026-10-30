**Environment note:** web.dev and docs.litespeedtech.com were not fetchable from this session (hosts not in the network allowlist). I got them only as search snippets. Items marked [unverified] come from my own knowledge and were not confirmed live. The search results disagree on none of the three CWV thresholds.

## 1. Core Web Vitals (late 2026)

- **Thresholds are unchanged since INP replaced FID on 2024-03-12.** Good means LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1, each at the 75th percentile of real-user data, split by mobile and desktop. Poor means LCP > 4 s, INP > 500 ms, CLS > 0.25. ([web.dev/articles/vitals](https://web.dev/articles/vitals) [unverified here]; [secondary summary](https://cdn.jsdelivr.net/npm/bmad-plus@0.9.0/src/bmad-plus/packs/pack-seo/ref/cwv-thresholds.md))
- One SEO blog claims a stricter 2.0 s LCP "rollout". Nothing corroborates it, so ignore it.
- **How Google uses them:** they are a page-experience ranking signal, and each metric is judged on its own, not averaged. Relevance dominates, so treat CWV as a tiebreaker plus a conversion factor. Field data comes from CrUX. A low-traffic site like this may have no CrUX data at all, so Lighthouse and PageSpeed lab data is your only measure. Target mobile LCP ≤ 1.8 s as margin.

## 2. Self-hosted fonts

- **Subsets:** Google serves `latin` and `latin-ext`. Polish letters ą ć ę ł ń ś ź ż live in `latin-ext` (U+0100-024F). Ó/ó is in `latin`. Real Polish copy therefore triggers both files, so two requests.
- **Better: one custom Polish subset.** One file, one preload:
  ```
  pyftsubset Font.ttf --flavor=woff2 --layout-features='kern,liga,ccmp,locl' \
   --unicodes="U+0020-007E,U+00A0-00FF,U+0104-0107,U+0118-0119,U+0141-0144,U+015A-015B,U+0179-017C,U+2013-2014,U+2018-201E,U+2022,U+2026,U+20AC,U+2192"
  ```
- **Variable or static:** use one variable woff2 if you use 3 or more weights. Use static files if you use 1-2 weights (smaller in total).
- **Preload only the body-text file you need for first paint. `crossorigin` is mandatory** even same-origin, otherwise the font downloads twice ([specification.website](https://specification.website/spec/performance/font-loading/)):
  ```html
  <link rel="preload" href="/wp-content/plugins/perki-lekcje-jasny/f/body-pl.woff2" as="font" type="font/woff2" crossorigin>
  ```
- **`font-display`:** `swap` plus a metric-matched fallback for the body and headings. `optional` gives the best CLS but may leave first-time visitors on the fallback. The same source recommends `optional` for CLS.
- **Metric-matched fallback, no CLS:**
  ```css
  @font-face{font-family:"Body";src:url(/f/body-pl.woff2) format("woff2");font-weight:100 900;font-display:swap}
  @font-face{font-family:"Body Fallback";src:local("Arial");size-adjust:107.4%;ascent-override:90.2%;descent-override:22.5%;line-gap-override:0%}
  body{font-family:"Body","Body Fallback",system-ui,sans-serif}
  ```
  The override numbers above are the commonly cited Inter-over-Arial values, from memory [unverified]. Do not copy them. Generate yours from the actual font with Capsize or Fontaine, as a [2026 guide](https://dev.to/joseph_anady_214bacedf939/self-hosting-google-fonts-with-size-adjust-zero-cls-web-font-swap-1g9c) also advises.
- Remove every `fonts.googleapis.com` and `fonts.gstatic.com` reference. Keep no `@import`.

## 3. LCP hero image

```html
<link rel="preload" as="image" type="image/avif"
 imagesrcset="/i/hero-480.avif 480w, /i/hero-800.avif 800w, /i/hero-1280.avif 1280w"
 imagesizes="(min-width:900px) 50vw, 100vw" fetchpriority="high">

<picture>
 <source type="image/avif" srcset="/i/hero-480.avif 480w, /i/hero-800.avif 800w, /i/hero-1280.avif 1280w" sizes="(min-width:900px) 50vw, 100vw">
 <source type="image/webp" srcset="/i/hero-480.webp 480w, /i/hero-800.webp 800w, /i/hero-1280.webp 1280w" sizes="(min-width:900px) 50vw, 100vw">
 <img src="/i/hero-800.jpg" width="800" height="1000" alt="Jacke Sienkiewicz przy perkusji" fetchpriority="high" decoding="async">
</picture>
```

- Never put `loading="lazy"` on the LCP image. Always set `width` and `height` so the browser reserves space.
- Per web.dev (updated 2026-07-10), preload has no `<picture>` equivalent. The article recommends a type-specific AVIF preload as progressive enhancement, which is what the snippet does. ([source](https://web.dev/articles/preload-responsive-images))
- If the `<img>` is in the first HTML bytes, the preload scanner already finds it. Then `fetchpriority="high"` alone is enough and the `<link>` is optional. Keep the preload only if you A/B it and it helps.
- Targets: AVIF about 40-70 KB at 800 w, quality about 50-60. Keep the WebP and JPG fallbacks.

## 4. No render-blocking third-party CSS

- Inline all CSS in `<style>`. Aim for under about 14 KB compressed, so it fits the first TCP round trip.
- Use no external stylesheet, no `@import`, no icon-font CDN. Use inline SVG for icons.
- Third-party scripts (analytics, Meta pixel): `defer`, or inject after first interaction or `requestIdleCallback`. Use `loading="lazy"` on the Google Maps iframe, or a click-to-load facade.
- Check the Autoptimize and LiteSpeed "Optimize" features. CLAUDE.md records that Autoptimize rewrote the inline script to `data:base64` plus `defer`. On this route, bypass it in PHP with `add_filter('autoptimize_filter_noptimize','__return_true');`. Also check LiteSpeed's CSS/JS combine, UCSS/CCSS and JS-delay settings, because they can rewrite the output too.

## 5. LiteSpeed page cache for a `readfile()+exit` route

What I could establish:

- LSWS reads `X-LiteSpeed-Cache-Control` from the response. When it is present, the standard `Cache-Control` is ignored for server-side caching and passes through to the browser. The directives are `public`, `private`, `no-cache`, `no-store`, `max-age`, `esi`. ([LiteSpeed docs](https://docs.litespeedtech.com/lscache/devguide/controls/), snippet only)
- The LSCache WordPress plugin sets that header on cacheable pages, with logged-in, cart and checkout pages as `no-cache`. ([StackHarbor](https://stackharbor.com/en/knowledge-base/litespeed-cache-module-overview/))
- Confirm a hit by requesting twice and checking `x-litespeed-cache: hit`.
- **Could not verify:** whether the plugin's own hooks still add the header when your handler exits early. Do not rely on it. Set the headers explicitly in your handler, then test.

Prescription [unverified, test with curl]:

```php
$f = __DIR__.'/lekcje.html';
$etag = '"'.md5_file($f).'"'; // or hash VERSION . filemtime
header('Content-Type: text/html; charset=UTF-8');
header('X-LiteSpeed-Cache-Control: public,max-age=86400');
header('X-LiteSpeed-Tag: perki_lekcje');
header('Cache-Control: public, max-age=0, must-revalidate');
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($f)).' GMT');
header('Vary: Accept-Encoding');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
readfile($f); exit;
```

- Why the ETag: `readfile` bypasses the web server's static-file handling, so you get no free validators.
- **Purge on deploy:** `do_action('litespeed_purge_url', home_url('/lekcje-nowy/'));` or `do_action('litespeed_purge_all');`. This matches the existing "purge on VERSION change" practice.
- **Cache only GET 200.** Do not cache `?utm_*` variants separately: use LiteSpeed's "Drop Query String" setting for `utm_source,utm_medium,utm_campaign,fbclid,gclid`.
- **Verify with curl:**
  ```
  curl -sI -H 'Accept-Encoding: br' https://perki.pl/lekcje-nowy/ | egrep -i 'x-litespeed|cache-control|content-encoding|etag'
  ```
  Run it twice. Expect `x-litespeed-cache: hit` on the second run and `content-encoding: br` (or `gzip`). If `content-encoding` is missing, enable LiteSpeed dynamic compression for `text/html`.
- Static assets (fonts, images): serve from real files with `Cache-Control: public, max-age=31536000, immutable` and versioned filenames. Do not stream them via PHP.
- Not verified: any HTTP 103 Early Hints support. Skip it.

## 6. Security headers that do not break inline scripts

A per-request nonce does not fit static HTML. Use SHA-256 hashes of each inline script, or move scripts to external files ([claudecode-lab](https://claudecode-lab.com/en/blog/claude-code-websecurity-headers); [Thulite](https://docs.thulite.io/advanced/security/headers/)). A hash must match the exact script bytes. Autoptimize or LiteSpeed rewriting breaks the hash, so disable both on this route (section 4). Compute hashes in the plugin at serve time or build time. `application/ld+json` blocks are data, not executed, so CSP does not block them.

```
Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'sha256-AAAA...=' 'sha256-BBBB...='; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src https://www.google.com/maps/; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; upgrade-insecure-requests
```

- `style-src 'unsafe-inline'` is an accepted trade-off for inline `<style>` and `style=` attributes.
- Run Report-Only for about a week, then rename the header to `Content-Security-Policy` ([Thulite](https://docs.thulite.io/advanced/security/headers/)).
- `connect-src 'self'` covers the JSON POST to the WP REST booking endpoint. Add any analytics host if you use one.
- **Other headers:**
  ```
  Strict-Transport-Security: max-age=31536000; includeSubDomains
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
  X-Frame-Options: SAMEORIGIN
  ```
- Add `; preload` to HSTS only after confirming all perki.pl subdomains are HTTPS. It is hard to undo. Sources suggest 63072000 with preload ([example](https://stackharbor.com/fr/base-de-connaissances/http-security-headers-defensible-set/)); one year is the safer start.
- Set the HSTS, `nosniff` and `Referrer-Policy` headers at server level (`.htaccess` or LiteSpeed context) for the whole site. Set CSP in the plugin for this route only.
- A Japanese page claims COOP/COEP became mandatory in 2026. I found no authoritative support. Skip COEP. COOP `same-origin` is optional and needs no testing here.

## Verification checklist

1. PageSpeed Insights on mobile: LCP ≤ 2.5 s (aim ≤ 1.8 s), CLS ≈ 0, TBT low as the INP proxy.
2. The curl check above shows a cache hit and br/gzip.
3. The CSP report-only console shows zero violations before enforcing.
4. The booking form still POSTs successfully under the enforced CSP.