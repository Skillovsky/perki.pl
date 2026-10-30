**Caveat on sourcing:** this session could not reach the primary documents: developers.google.com, developers.openai.com, platform.openai.com, docs.perplexity.ai, schema.org and llmstxt.org all failed DNS. Only support.claude.com and developer.apple.com resolved. Everything else comes from secondary sources, and I mark it **[unverified]** where it matters. Check those five hosts before shipping anything that depends on them.

## 1. Schema.org JSON-LD

**Type choice.**
- Use `"@type": ["LocalBusiness","EducationalOrganization"]`.
- I could not confirm that schema.org core has a `MusicSchool` type, so don't rely on it. Real music-school sites use LocalBusiness, or LocalBusiness plus EducationalOrganization ([rockstarmusic.ca/llms.txt](https://rockstarmusic.ca/llms.txt), [lantern.llc examples](https://lantern.llc/b/b-music-charlottesville/data)). **[unverified]**
- Google's LocalBusiness documentation requires `name` and `address` (PostalAddress). `openingHoursSpecification` is recommended ([Google LocalBusiness](https://developers.google.com/structured-data/local-businesses/), mirror only).

**Two addresses.**
- Put one LocalBusiness node on the homepage and give it two `location` Place nodes, each with its own PostalAddress, `geo` and `hasMap`.
- Alternatively, publish two sub-nodes with `@id` values `#kramarska` and `#zydowska`, each with a `parentOrganization` pointing to the school.
- Use identical NAP strings everywhere (page, footer, Google Business Profile, Bing Places).

**Online lessons.**
- Use `areaServed: [{"@type":"City","name":"Poznań"},{"@type":"Country","name":"Poland"}]` ([schema.org/areaServed](https://Schema.org/areaServed)).
- On the online Service use `serviceType: "Online drum lessons"` and `availableChannel: {"@type":"ServiceChannel","serviceUrl":"https://perki.pl/lekcje-nowy/"}`.

**Teacher.** Add a `Person` node with `@id` `#jacke`, `jobTitle: "Drum teacher"`, `sameAs` (TUGA, social profiles) and `knowsAbout: ["drums","drum lessons"]`. Link it with `founder` and `employee` on the school.

**Prices.** Use `hasOfferCatalog` (OfferCatalog > Offer > itemOffered Service). Each Offer needs `price`, `priceCurrency: "PLN"` and `url`:

| Offer | Price |
|---|---|
| 45 min lesson | 150 |
| 4-lesson pack | 570 |
| 8-lesson pack | 1080 |
| 12-lesson pack | 1530 |

The pack prices are derived from the 5/10/15% discounts, so check them against the page. Put `priceSpecification` with `unitText` on any price that is a range.

**What Google supports.**
- **LocalBusiness and Organization:** Google reads these for knowledge-panel and entity signals. I could not retrieve Google's current gallery to confirm their status ([search result](https://developers.google.com/structured-data/local-businesses/)).
- **FAQPage:** Google stopped showing FAQ rich results on May 7, 2026, and is removing the report and Rich Results Test support. The markup is still valid schema.org. Sources: [ppc.land](https://ppc.land/google-kills-faq-rich-results-what-seos-saw-coming-since-2019/), [seocrawl.ai](https://seocrawl.ai/blog/faq-structured-data-google-2026). They are secondary and agree with each other.
- **Claims that AI assistants cite FAQ markup more** come from vendor blogs ([theplusaddons](https://theplusaddons.com/blog/faq-schema-ai-wordpress/)). **[unverified]**
- **HowTo:** deprecated in 2023. **Course info:** deprecated June 2025 ([exchange4media](https://www.exchange4media.com/digital-news/google-deprecates-faq-content-feature-154581.html)). Don't add `Course` for 1:1 lessons. Add it only if you sell a real video course, as a semantic hint with no expected rich result.
- **Google's AI-search guidance** says no special schema is needed for AI Overviews or AI Mode, and markup must match visible text ([summary](https://dev.to/searchless_ai/googles-official-ai-search-optimization-guide-debunks-half-the-geo-industry-3ap5)). **[unverified, secondary]**

**Decision.** Keep FAQPage anyway. The cost is near zero, the visible Q&A is what matters, and the markup must mirror it exactly.

## 2. llms.txt

**Spec.**
- A Markdown file at `/llms.txt` with an H1 (the only mandatory element), a `>` blockquote summary, and H2 sections of `- [Title](url): description` lines.
- An `## Optional` H2 marks links that can be skipped. Authored by Jeremy Howard ([secondary description](https://www.agentpatterns.ai/geo/llms-txt/)).

**Adoption.**
- Roughly 10% of domains have one, but about 97% of files get no AI requests, and only 1 of the 50 most AI-cited domains has one. A 300,000-domain study found no link to citations ([ppc.land](https://ppc.land/llms-txt-adoption-rises-8-8x-but-97-of-files-get-zero-ai-requests/)).
- Google's Mueller said no AI system uses it, and called it "purely speculative" ([pulse.bot/SEJ](https://www.pulse.bot/digital-marketing/news/google-says-llmstxt-is-purely-speculative-for-now-via-sejournal-martinibuster-556342f6-2369-4160-b78e-5cd3521dbab7/)). Google's AI guidance reportedly says to ignore it.
- Claims that OpenAI or Microsoft crawlers fetch it are anecdotal and unconfirmed by the vendors. **Contested.**

**Recommendation.**
- Ship a short `/llms.txt` (under 40 lines): H1 "Perki.pl, drum lessons in Poznań", a 2-sentence summary with price and addresses, and links to pricing, booking, FAQ and contact.
- Cost is about 15 minutes. Expect no measurable citation lift.
- Skip `llms-full.txt`. The real work is clean HTML.

## 3. robots.txt and AI crawlers

| Bot | Purpose | Robots.txt behavior | Matters for |
|---|---|---|---|
| `OAI-SearchBot` | ChatGPT search index | Obeys | **Citations** |
| `ChatGPT-User` | User-triggered fetch | OpenAI says robots rules "may not apply" | Citations, live |
| `GPTBot` | Training | Obeys | Training only |
| `Claude-SearchBot` | Claude search index | Obeys | **Citations** |
| `Claude-User` | User-directed fetch | Obeys | Citations, live |
| `ClaudeBot` | Training | Obeys | Training only |
| `PerplexityBot` | Perplexity index | Obeys | **Citations** |
| `Perplexity-User` | User-triggered fetch | Reportedly ignores | Citations, live |
| `Googlebot` | Search, AI Overviews, AI Mode | Obeys | **Citations** |
| `Google-Extended` | Gemini training and grounding token | Obeys | Disputed |
| `Applebot` | Siri and Spotlight | Obeys | Apple search |
| `Applebot-Extended` | Apple model-training token only | Obeys | Training only |

- **OpenAI:** blocking `OAI-SearchBot` removes you from ChatGPT search answers. Blocking `GPTBot` opts you out of training only. User-agent form: `compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot` and `compatible; ChatGPT-User/1.0; +https://openai.com/bot`. The version differs across OpenAI pages, so match the token. IP lists are at openai.com/searchbot.json and openai.com/chatgpt-user.json ([overview via search](https://developers.openai.com/api/docs/bots)).
- **Anthropic:** all three bots honor robots.txt, and IPs are published at claude.com/crawling/bots.json. Blocking Claude-User or Claude-SearchBot reduces visibility ([Anthropic](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)). Anthropic does not publish full UA strings there.
- **Perplexity:** UA strings are `Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)` and the same with `Perplexity-User/1.0; +https://perplexity.ai/perplexity-user` ([secondary](https://www.trakkr.ai/bots/perplexity-user)). **[unverified]** Cloudflare alleged in August 2025 that Perplexity used stealth crawlers to evade blocks; Perplexity disputed it ([tech.co](https://tech.co/news/cloudflare-perplexity-bots-ignore-no-crawl-directives)). **Contested.**
- **Google-Extended:** sources disagree on whether it affects AI Overviews. Most say Googlebot governs Search and AI Overviews, and that Google-Extended governs Gemini only ([temso.ai](https://www.temso.ai/bots/google/google-extended)). **[unverified]** The safe choice is to allow both.
- **Applebot-Extended:** Apple's page 404'd, so this is from memory. Treat it as a training-only token **[unverified]**.

**Recommended file.** The simplest option for a business that wants maximum visibility is to allow all of the above explicitly:

```
User-agent: *
Allow: /
Disallow: /wp-admin/
Disallow: /wp-json/
Sitemap: https://perki.pl/sitemap.xml

User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: PerplexityBot
User-agent: Perplexity-User
User-agent: Googlebot
User-agent: Bingbot
User-agent: Applebot
Allow: /
```

- Add `User-agent: GPTBot`, `ClaudeBot`, `Google-Extended` and `Applebot-Extended` with `Disallow: /` only if you want to opt out of training. Opting out costs nothing for citations, per the vendor statements above.
- Don't block `/wp-json/` if the booking endpoint is called by a browser. Robots rules don't stop browsers, so this is safe.
- Check that the WAF and LiteSpeed don't challenge these UAs. Cloudflare-style bot management is the usual cause of invisible AI blocks.
- Bing matters because ChatGPT reportedly draws on Bing data for local results. Foursquare is also claimed as a source, with 60-70% shares quoted. Those shares are unverified vendor estimates ([Local Falcon](https://www.localfalcon.com/blog/chatgpt-local-search-data-sources-where-does-business-info-come-from)). Claim Bing Places, Apple Maps and Google Business Profile.

## 4. On-page signals for quotable content

These are my synthesis, not vendor-confirmed rules. Google itself says AI features need ordinary good SEO, with important content available as text ([summary](https://dev.to/searchless_ai/googles-official-ai-search-optimization-guide-debunks-half-the-geo-industry-3ap5)).

- **Facts first:** make the first screen state the facts in text. For example: "Drum lessons in Poznań: 150 PLN / 45 min, Kramarska 33 and Żydowska 35, or online via Google Meet." Don't leave key facts in images or JS-only widgets.
- **Price table in HTML:** use a real `<table>` for single lesson versus the 4, 8 and 12 packs, with discount percentages.
- **Q&A blocks:** use H2 or H3 questions with 40-60 word direct answers, wrapped in `<details>` or plain headings. Cover the 24 h reschedule policy, vouchers, online versus in-person, ages, and whether you need your own kit.
- **NAP consistency:** one canonical string for name, address and phone. Phone is optional on the form, but use one number site-wide.
- **Visible "Last updated: 2026-10-30"** plus `dateModified` in JSON-LD, kept in step with real edits.
- **Entity clarity:** a short About paragraph naming Jacke Sienkiewicz, drummer of TUGA, with a link to external proof. Reviews with real names help.
- **Server-rendered HTML:** the page is static, which is the right shape. Keep all key content in the initial HTML.
- **Sitemap with `lastmod`:** also mark the page `index, follow`.

## 5. Meta essentials, 2026

Sources are SEO studies, not Google rules ([Portent](https://portent.com/blog/seo/how-often-google-rewrites-our-title-tags.htm), [seo-day.de](https://www.seo-day.de/news/article/best-practices-for-writing-seo-title-tags?lang=en)).

- **Title:** about 50-60 characters (roughly 600 px desktop). Google has no fixed limit and rewrites 60-76% of titles in studies. Rewrites drop when the title matches the H1. Example: `Lekcje perkusji Poznań | 150 zł / 45 min, online i stacjonarnie`.
- **Description:** 140-160 characters, with the price and both locations. Google often rewrites it. **[unverified for 2026]**
- **Canonical:** self-referencing absolute `<link rel="canonical">`. Beware the `/lekcje-nowy/` versus final URL if the page moves.
- **`<html lang="pl">`:** hreflang is not needed for a single-language site.
- **Open Graph:** `og:title`, `og:description`, `og:type=website`, `og:url`, `og:image` (1200x630), `og:locale=pl_PL`. Twitter: `twitter:card=summary_large_image`.
- **Favicon:** a 48x48 multiple (48, 96, 144 px) as PNG or SVG, plus a 180x180 `apple-touch-icon`. This is from memory of Google's guidance, which I could not retrieve. **[unverified]**
- **`theme-color`:** include it, with media variants for light and dark. It is harmless and mobile-only.
- **Viewport and charset:** `<meta name="viewport" content="width=device-width, initial-scale=1">`.
- **Robots meta:** `max-image-preview:large, max-snippet:-1`, which permits full snippets for AI features.

## Priority list

1. Fix the WAF and LiteSpeed so none of the bots above are challenged.
2. Add robots.txt, the sitemap and the JSON-LD graph.
3. Claim Google Business Profile, Bing Places and Apple Maps for both addresses.
4. Add the visible price table, FAQ and last-updated line.
5. Add llms.txt last. It is low value.