# Make My Site Agent-Ready — WordPress Plugin

A WordPress plugin that makes your site ready for AI agents and language models. Serves clean markdown at `.md` URLs, an Open Knowledge Format bundle at `/okf/`, `/llms.txt` and `/llms-full.txt` site indexes, an `/openapi.json` API description, a read-only MCP server and the same tools in the browser through WebMCP (listed at `/.well-known/webmcp.json`), `/auth.md`, an Agentic Resource Discovery catalog, an `?mode=agent` view, an NLWeb `/ask` endpoint with a Schemamap, `/.well-known/security.txt`, and a machine-readable `/.well-known/api-catalog`, exposes Agent Skills discovery, sends `Link` response headers advertising all of it, declares AI usage preferences via Content Signals in `robots.txt` and a TDMRep reservation header, adds AI crawler rules, agent-recoverable 404s and `Deprecation`/`Sunset` headers for retiring endpoints, optionally points agents at the markdown alternate via JSON-LD structured data (merging into Yoast SEO's own schema when active, so nothing is duplicated), can submit changed pages to IndexNow, keeps an optional agent request log that opens on a Summary of what the traffic means, and exposes WordPress Abilities API endpoints for AI agent management.

## Why

AI models and agents increasingly need to read website content, discover what's available, and know what a site owner will and won't let them do with it. HTML is noisy for the first problem — navigation, ads, scripts, and styling all get in the way. Discovery and usage preferences are largely unsolved by default WordPress at all. This plugin addresses all three: clean markdown for reading, machine-readable indexes and headers for discovery, and explicit signals for usage preferences.

Eight existing plugins were analyzed before building the original `.md`/llms.txt feature set. Most were overengineered — custom converters, content negotiation, user-agent sniffing. This plugin takes a simpler approach throughout: generate markdown once on save, serve pre-built indexes, declare preferences plainly.

## Features

Every feature below can be switched off individually under **Settings > Agent-Ready**. Most default on — publishing a new file or header that changes no existing response is low-risk enough to ship active. A handful default off instead, each for its own stated reason: content negotiation and the footer llms.txt link change something visible to a human visitor; the MCP server, NLWeb and MCP Apps UI run a query per request rather than serving a static file; the agent log writes to a database table nobody asked for until they opt in; IndexNow is the only feature that sends anything to an outside service; and counting AI referrals adds the plugin's only visitor-side script. The settings page states the reason on each toggle. A disabled feature registers nothing at all — no rewrite rule, no filter, no `Link` header — so the site behaves as if that part of the plugin did not exist.

### Content access
- **`.md` URL suffix** — any post or page is available at its URL with `.md` appended (e.g., `your-site.com/my-post.md`). The body opens with the title as a level-one heading, since WordPress renders the title outside the post content; a body that already starts with one is left alone
- **Front page** at `/index.md`
- **YAML frontmatter** — title, date, modified date, author, URL, markdown URL, content type, excerpt, meta description (from Yoast SEO), categories, and tags. The excerpt and the description can each be switched off at Settings > Agent-Ready. Themes and plugins can add fields of their own with the [`mmsar_frontmatter` filter](#adding-frontmatter-fields)
- **Pre-generated on save** — markdown is stored in post meta, so `.md` requests serve instantly with zero processing
- **`/llms.txt` site index** (v2 of the [llms.txt](https://llmstxt.org/) proposal) — lists all available markdown URLs organized by category, cached with 24-hour transient. Large sites can also publish a scoped index per section (e.g. `/writing/llms.txt`) — each page advertises whichever index actually covers it via `rel="describedby"`, header or `<link>`, rather than always pointing at the site-wide one. The root index has a 30,000-byte budget (`mmsar_llms_txt_budget`, 0 turns it off): when it would be larger, sections that have a scoped index are shortened to one line linking to it, largest first, until it fits. Sections without one are never shortened.
- **`/llms-full.txt`** — full site content concatenated as markdown in a single file, for LLMs that want everything at once
- **OKF bundle** at `/okf/` — the same content as an [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) v0.2 tree: a root index, one index per post type, and one typed Markdown "concept" file per post/page (YAML front matter: `type`, `title`, `description`, `resource`, `tags`, `modified`), plus a `log.md` change log. Lets an agent fetch and address individual pieces of the corpus rather than either scraping HTML or downloading everything in `llms-full.txt`. Reuses the same generated markdown as the `.md` URLs — nothing is converted twice.
- **Noindex respected** — a page your SEO plugin marks noindex is left out of `llms.txt`, `llms-full.txt`, the OKF indexes, MCP search and list, and NLWeb. Its own `.md` URL keeps working, as the HTML page does: noindex means "don't list this", and password protection is the tool for "don't serve this". Read from Yoast SEO and Rank Math (only while each is active); anything else can answer through the `mmsar_post_is_noindex` filter. On by default, switchable under Markdown Endpoints. "Discourage search engines" is deliberately not read, or every staging copy would publish empty indexes
- **`<link rel="alternate">`** — HTML pages include a link tag pointing to their markdown version
- **Markdown from the canonical URL** — opt-in, off by default. Answers a request for an ordinary page with its markdown when the request's `Accept` header prefers markdown, which is how AI fetch tools actually ask; the `.md` mirror only helps a client that already knows the mirror exists. The `Accept` parsing is strict where it protects people — markdown must be named explicitly, which no browser does, and a wildcard counts only towards HTML — and generous where it does not: markdown weighted equally with HTML (`text/markdown, text/html, */*`) gets markdown, since naming it at all is a choice only an agent makes. The same rule decides the Markdown body of an agent-recoverable 404. `Vary: Accept` is sent on both representations. Ships with a self-check (see Architecture notes) because whether this is safe depends on infrastructure the plugin cannot see.
- **`?mode=agent`** — appended to any URL, returns that page as Markdown; on the homepage, returns a summary of every machine-readable surface the site has. A convention rather than a standard, but it gives a client the one lever it always has (a query parameter) when it's handed a bare URL and doesn't already know the site's other conventions.

### Discovery
- **`/openapi.json`** — an OpenAPI 3.1 description of every public endpoint this plugin serves, generated from the site's actual registered REST routes rather than hand-maintained, so it can't drift out of sync with what's really there. Includes a typed error schema for the MCP endpoint below and an `info.x-lifecycle` block describing the retirement policy (see Lifecycle below). Skipped automatically if a real `openapi.json` already sits in your site root.
- **MCP server** (read-only, off by default) — a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint at `/wp-json/mmsar/v1/mcp` that AI clients can connect to directly over Streamable HTTP, with tools to search the site, list content, read a page as Markdown, and get an overview. `search_content` and `list_content` take an optional `topic` (a category, tag or other public taxonomy slug; an unknown one returns an error naming the site's topics), and the overview's `topics` section lists the 40 most used. Exposes nothing that `llms-full.txt` doesn't already publish, and is rate-limited to 60 calls/minute/IP. Publishes a discovery manifest at `/.well-known/mcp.json` and a server card at `/.well-known/mcp/server-card.json`. Off by default because, unlike everything else here, it answers by running a query rather than serving a file.
- **WebMCP** (off by default, needs the MCP server) — registers the MCP server's read-only tools with an AI agent working inside the visitor's browser, through `document.modelContext`. A ~475-byte inline loader checks for the API and stops at once where it is missing; where it exists, it loads the bridge from `/mmsar-webmcp/<version>/bridge` (a versioned path without a `.js` extension, because some hosts redirect missing `.js` files and some CDNs ignore `?ver=`). The bridge reads tool definitions from the server card, registers the ones annotated read-only, and sends every call to the MCP endpoint, so search, the noindex and password rules and the rate limit stay on the server. `get_content` defaults to the page the visitor is on. Errors come back as the tool's text rather than thrown, because Chrome replaces a thrown error with a generic message. Calls are logged as `WebMCP JSON-RPC` with the address reduced to its network. Browser support is a Chrome and Edge origin trial: the WebMCP settings section takes the site's tokens, checks each one on save (origin, feature, expiry, no third-party tokens) and prints the working ones as `origin-trial` meta tags on every page, whether or not the bridge is on, since a token serves any WebMCP tools on the site. Site Health warns when the token is missing or expires within 14 days. While the bridge is on, `/.well-known/webmcp.json` (and `/.well-known/webmcp`) lists the same tools as the page registers them, built from the server card with the bridge's own shaping, plus the bridge script's URL and a link to the server card. **No specification defines that file**: WebMCP registers tools in the page, and the draft lists discovery as a non-goal. Its own toggle, on by default, so an owner can let another plugin answer that address instead. Requests for it are logged as `webmcp.json` and, like `WebMCP JSON-RPC`, shown in the default Agent Log view even from a browser (`MMSAR_Agent_Log::default_view_browser_surfaces()`).
- **`/auth.md`** — a plain-language explanation of how an agent gets access to the site. For most sites the honest answer is "you don't need credentials," and saying so out loud stops an agent from assuming it needs a key it can't get and either giving up or probing for login endpoints.
- **Agentic Resource Discovery (ARD) catalog** — `/.well-known/ai-catalog.json` (also served at `/.well-known/ard.json`), a typed inventory of the site's agentic resources (MCP server, its WebMCP tools, API, skills, content index) with stable identifiers, per the [ARD spec](https://agenticresourcediscovery.org/). Complements `/.well-known/api-catalog` below, which is a list of links rather than a typed inventory.
- **`/.well-known/api-catalog`** (RFC 9727) — a Linkset (RFC 9264) JSON document indexing `llms.txt`, `llms-full.txt`, `security.txt`, the Agent Skills index, `openapi.json`, the MCP manifest, `webmcp.json`, the sitemap, and the feed in one machine-readable file, each listed only while it is served
- **Agent Skills discovery** — `/.well-known/agent-skills/index.json` plus a bundled skill (`fetch-content-as-markdown`) teaching an agent how to use this plugin's markdown endpoints instead of parsing HTML. The served skill file and its index digest are computed from the same source at request time, so they can never drift out of sync.
- **NLWeb `/ask` endpoint** (off by default) — answers questions about the site in [NLWeb](https://github.com/nlweb-ai/NLWeb)'s shape, with optional SSE streaming. A request in NLWeb protocol v0.54's nested form (`{"query": {"text": ...}}`) gets a v0.54 `Answer` or `Failure` back, and v0.54-style `data:` events when streaming; a flat `query` gets the earlier shape. Both request shapes and the event stream are described in `/openapi.json`. It is listed with the site's other endpoints in `llms.txt` and the API catalog. Retrieval only — it returns ranked pages, not a generated answer, and says so in every response. Ships with a Schema Map at `/schema-map.xml` in the format of NLWeb's [Schema Feeds spec](https://github.com/nlweb-ai/website/blob/main/SCHEMA_SPEC.md), listing the site's RSS feed, plus a `Schemamap:` robots.txt directive. Google Search Console reports the `Schemamap:` line as "Syntax not understood" (Google ignores it), so a checkbox under the NLWeb toggle can switch the robots.txt lines off; the maps are served either way. When Yoast SEO's schema aggregation endpoint is on, robots.txt also points to Yoast's map at `/schemamap.xml`, which lists its schema.org feeds per post type. Other plugins can add maps with the `mmsar_schemamap_urls` filter.
- **MCP Apps UI** (experimental, off by default) — lets an MCP client render the search/list tools' results as a card list instead of plain text. Marked experimental because no MCP Apps host was available to verify it against; a client that ignores the metadata still gets the normal text result.
- **Agent-recoverable 404s** — a normal 404 tells an agent only that its URL was wrong. This adds `Link` headers and `<link>` tags pointing at the sitemap, `llms.txt` and the endpoint catalog, and returns a short Markdown list of those destinations (instead of the themed error page) to clients that asked for Markdown explicitly. The 404 page itself looks identical to visitors.
- **`Link` response headers** (RFC 8288) — every front-end response carries `Link` headers pointing to the resources above that are actually switched on; singular posts/pages add one pointing to their markdown alternate. Lets agents that only read headers, never HTML, still find these resources.
- **IndexNow** (off by default) — submits a page's URL to [IndexNow](https://www.indexnow.org/) when it is published, changed or removed, so Bing, Yandex, Seznam, Naver and the other participating engines recrawl it instead of waiting. Bing's index is what ChatGPT search and Copilot ground on, which is why an agent-readiness plugin does this; Google does not take part. Serves the key file at `/{key}.txt` (IndexNow only accepts URLs at or below the key file, so it has to be at the root). Sends on `shutdown` of the save request itself, no cron. A post's old URL is sent too when it is unpublished, protected, or moved to a new slug. Skipped when Yoast SEO Premium, Rank Math's Instant Indexing, the IndexNow plugin or All in One SEO's IndexNow add-on already submits (`mmsar_indexnow_handled_elsewhere` covers anything else). The plugin's only outbound request to a third party: see External services in `readme.txt`
- **Structured data (JSON-LD)** — opt-in, off by default. Points agents at the markdown alternate via an `encoding`/`MediaObject` field. When Yoast SEO is active and produces schema for the page, this merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block, nothing else in Yoast's graph touched. Otherwise (no Yoast, or a page type Yoast doesn't cover), a standalone minimal `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.

### Usage preferences and crawler rules
- **Content Signals** — `Content-Signal: search=..., ai-input=..., ai-train=...` (per [contentsignals.org](https://contentsignals.org/) / the IETF AI Preferences draft) declared under each AI crawler's group in `robots.txt`. Configurable per-site: allow indexing, allow live AI retrieval, allow/decline model training use, independently.
- **TDMRep reservation header** — sends `tdm-reservation: 1` or `0` on every response, the machine-readable form the EU's Copyright in the Digital Single Market Directive (Article 4) requires for a text-and-data-mining reservation to actually count — without it, mining is permitted by default. Not a separate setting: the value is derived from the AI Train answer in Content Signals above, so the two can never disagree. An optional Policy URL is sent alongside as `tdm-policy` when reserving.
- **AI crawler rules in `robots.txt`** — explicit `Allow: /` entries for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot, Amazonbot, CCBot, and LinkupBot. Each group carries the site's Content-Signal line, so a training crawler is told what the content may be used for rather than being left to the general rules. Appends rather than replaces, so it works alongside a `robots.txt` generated by an SEO plugin. Also adds a `Sitemap:` directive if nothing else already has — detecting Yoast, Rank Math, All in One SEO, SEOPress, or WordPress core sitemaps to get the filename right. Switch this feature off and the plugin stops touching `robots.txt` entirely, including the rewrite rule that routes it through WordPress.
- **Decline AI training crawlers** (off by default) — Content-Signal asks; this enforces as far as robots.txt can. Each token in `mmsar_training_crawler_tokens()` (GPTBot, ClaudeBot, Anthropic-AI, CCBot, Applebot-Extended, meta-externalagent, FacebookBot, Bytespider, Amazonbot, cohere-ai, cohere-training-data-crawler, Diffbot) gets `Disallow: /`. Search and assistant crawlers stay allowed. Google-Extended is excluded on purpose, because Google documents it as governing Gemini's grounding as well as training. The endpoint `Allow:` carve-outs below skip groups made only of declined crawlers, which would otherwise reopen part of the site to them.
- **`/.well-known/security.txt`** — serves a security.txt file (RFC 9116). Enter your security contact as a full URL, a path like `/contact`, or an email address; the plugin expands it into a valid Contact URI. Falls back to the site admin email if unset. A free-text field is available for sites needing extra fields such as Encryption or Policy.

### Lifecycle
- **`Deprecation`/`Sunset` headers** for retiring endpoints — add a surface to the `mmsar_deprecated_surfaces` filter and its responses carry `Deprecation` (RFC 9745) and `Sunset` (RFC 8594) headers, plus a `Link` under the registered `deprecation`/`sunset` relations when a policy URL is given, so an agent is told a URL is going away before it actually does. Empty by default, so it adds no header to any response until a site fills the schedule in.

### Configuration and operations
- **Settings page** (Settings > Agent-Ready) — per-feature on/off toggles, post type selector, CSS root selector, robots.txt preview and extra-rules textarea, security contact, Content Signals toggles, a TDMRep policy URL, a structured data (JSON-LD) toggle, WebMCP origin-trial tokens with their status, and a "View" link to every endpoint currently being served
- **Bulk regeneration** — "Regenerate All" button on the settings page
- **Agent request log** (off by default) — records which agents fetch the surfaces above, and robots.txt and the site's feeds, on its own screen at Settings > Agent Log, with filters, a Journeys view, a retention setting, a dashboard widget, a CSV export, and a read-only ability so an agent can read it too. Verifies each claimed crawler identity and tags every recognised bot with a category, so AI traffic can be separated from search and SEO traffic. An MCP tool call records what it asked for in its own `arguments` column (content type, topic, the path of the page served, overview sections), each value stored only after it matches something on the site; search terms only if the owner opts in, redacted. Browser-shaped rows carry three signals — a Web Bot Auth signature (recorded, not verified), a published cloud-provider network, and whether the request followed a link on the site — because an agent driving a real browser otherwise looks exactly like a reader; the cloud-network signal also covers HTTP clients such as axios or curl. The screen opens on a Summary of what the last 30 days mean, with findings under three questions (being read, being cited, broken or ignored), also on the dashboard widget and as the `get-agent-insights` ability. See [The agent log](#the-agent-log)
- **Visitors sent by AI assistants** (off by default, needs the agent log) — counts people who arrive from ChatGPT, Perplexity, Claude, Gemini, Copilot and other assistants, per assistant and landing page, shown on the Agent Log screen. Kept as a daily count only. See [Visitors sent by AI assistants](#visitors-sent-by-ai-assistants)
- **Proper HTTP headers** — `Content-Type: text/markdown`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, canonical link
- **Password protection** — password-protected posts return 403 on `.md` URLs, and are excluded from every aggregate document (`llms-full.txt`, the OKF bundle) and the agent log
- **Clean uninstall** — removes all plugin data (post meta, options, transients, and the agent log and AI referral tables)

## How it works

1. When you save a post, the plugin converts its rendered HTML to markdown using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) and stores it in post meta
2. A single rewrite rule catches all `.md` requests (excluding `/.well-known/`, `/auth.md` and `/okf/`, which route to their own handlers — see Architecture Notes below)
3. The plugin resolves the request to a post, reads the pre-generated markdown from meta, and serves it with proper headers
4. The `/llms.txt` endpoint builds a categorized index of all available markdown URLs, shortening sections that have their own scoped index if the whole would exceed its size budget
5. The `/llms-full.txt` endpoint concatenates the full content of all posts and pages into a single file
6. The OKF bundle at `/okf/` wraps the same pre-generated markdown in typed front matter, one concept file per post/page, addressed at the same path its `.md` URL already uses
7. `/.well-known/api-catalog`, `/.well-known/ai-catalog.json`, `/openapi.json`, the Agent Skills endpoints, and `Content-Signal`/`tdm-reservation` are all generated the same way — computed from live site state at request time, not hand-maintained static files

Since markdown is generated at save time, serving `.md` requests is essentially a single meta query — no HTML parsing, no API calls, no processing overhead.

## Installation

1. Download or clone this repository
2. Upload the `make-my-site-agent-ready` folder to `wp-content/plugins/`
3. Activate the plugin in WordPress
4. Go to **Settings > Agent-Ready** to configure post types, robots.txt rules, security.txt content, and Content Signals
5. Visit **Settings > Permalinks** and click Save (to flush rewrite rules) — not required after future plugin updates, only on first install, since version bumps auto-flush rewrite rules from v1.4.0 onward

The plugin includes its only dependency (`league/html-to-markdown`) in the `vendor/` folder — no Composer install needed.

## Example output

**`your-site.com/hello-world.md`** returns:

```markdown
---
title: "Hello World"
date: 2026-01-15
modified: 2026-01-20
author: "Jane Doe"
url: "https://your-site.com/hello-world/"
markdown_url: "https://your-site.com/hello-world.md"
type: post
excerpt: "Welcome to my site."
description: "A first post, written with Yoast SEO's meta description."
categories:
  - "Uncategorized"
---
# Hello World

Welcome to WordPress. This is your first post. Edit or delete it, then start writing!
```

**`your-site.com/llms.txt`** returns a site index with all available markdown URLs grouped by category.

**`your-site.com/llms-full.txt`** returns the full content of every published post and page as concatenated markdown.

**`your-site.com/.well-known/api-catalog`** returns a Linkset JSON document indexing every discoverable resource the plugin serves.

**`your-site.com/robots.txt`** returns, per AI crawler group:
```
User-agent: GPTBot
Allow: /
Content-Signal: search=yes, ai-input=yes, ai-train=no
```

**Every response** carries a TDMRep header declaring your text-and-data-mining reservation:
```
tdm-reservation: 1
tdm-policy: https://your-site.com/tdm-policy
```

**`your-site.com/okf/hello-world.md`** returns the same content as the `.md` URL, under OKF front matter instead:
```markdown
---
type: "post"
title: "Hello World"
description: "Welcome to my site."
resource: "https://your-site.com/hello-world/"
tags: ["Uncategorized"]
modified: "2026-01-15T09:00:00+00:00"
---

Welcome to WordPress. This is your first post. Edit or delete it, then start writing!
```

**A single post, with structured data enabled and Yoast SEO active**, gets an `encoding` field merged straight into Yoast's own `Article` piece:
```json
{
  "@type": "Article",
  "headline": "Hello World",
  "datePublished": "2026-01-15T09:00:00+00:00",
  "...": "...Yoast's other Article fields (author, publisher, wordCount, etc.), unchanged...",
  "encoding": {
    "@type": "MediaObject",
    "contentUrl": "https://your-site.com/hello-world.md",
    "encodingFormat": "text/markdown"
  }
}
```

**Without Yoast active** (or on a page type Yoast doesn't cover), the same information ships as its own standalone block instead:
```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "url": "https://your-site.com/hello-world/",
  "headline": "Hello World",
  "datePublished": "2026-01-15T09:00:00+00:00",
  "dateModified": "2026-01-15T09:00:00+00:00",
  "encoding": {
    "@type": "MediaObject",
    "contentUrl": "https://your-site.com/hello-world.md",
    "encodingFormat": "text/markdown"
  }
}
```

## Adding frontmatter fields

Every markdown version opens with YAML frontmatter the plugin writes: `title`, `date`, `modified`, `author`, `url`, `markdown_url`, `type`, `excerpt`, `description`, `categories` and `tags` (the last four only when the post has them). A theme or plugin can add its own fields with the `mmsar_frontmatter` filter. It receives an associative array of extra fields, empty by default, and the `WP_Post` being converted, and returns the array. You supply values; the plugin writes and escapes the YAML.

```php
add_filter( 'mmsar_frontmatter', function ( $fields, $post ) {
    $fields['reading_time'] = (int) get_post_meta( $post->ID, 'reading_time', true );
    $fields['series']       = 'Agent-ready WordPress';
    $fields['featured']     = is_sticky( $post->ID );
    $fields['topics']       = wp_get_post_terms( $post->ID, 'topic', array( 'fields' => 'names' ) );
    return $fields;
}, 10, 2 );
```

Produces, after the core keys and before the closing `---`:

```yaml
reading_time: 7
series: "Agent-ready WordPress"
featured: false
topics:
  - "MCP"
  - "llms.txt"
```

| Value | Written as |
| --- | --- |
| string | double-quoted, escaped the same way as `title`; line breaks become spaces |
| integer, float | unquoted number (`INF` and `NAN` are skipped) |
| boolean | `true` / `false` |
| flat list of strings or numbers | a list, like `categories` and `tags`, every item quoted |

**What gets skipped.** Each field is checked on its own and dropped if it can't be written safely, so one bad field never costs the document:

- Keys that don't match `^[a-z][a-z0-9_]*$`.
- The core keys above. They can't be overridden or removed through the filter; a colliding key is ignored, not merged.
- Empty strings, empty lists, `null`, objects, associative arrays, and lists containing anything other than strings and numbers (a nested array, a boolean, `null`).

Fields appear in the order you return them. Line breaks are flattened because a value containing `\n---\n` would otherwise close the frontmatter early for any reader that splits on delimiter lines.

**Markdown is generated on save.** A post's markdown, frontmatter included, is stored when the post is saved, so a new or changed filter shows on existing posts only after they're saved again or **Regenerate All** is pressed under Settings > Agent-Ready. The fields appear everywhere that markdown is served: `.md` URLs, content negotiation, `?mode=agent`, `llms-full.txt`, the MCP `get_content` tool, and the body of OKF concept files, which carry their own front matter above it.

### Leaving out the excerpt or the description

Settings > Agent-Ready > **Summary in Frontmatter** has a checkbox for each. Both default to on, and an install updating from before 1.53.0 keeps both: a missing setting reads as on, never off. They are stored as `frontmatter_excerpt` and `frontmatter_description` in the `llmmd_settings` option. Both stay protected keys whichever way they are set, so the `mmsar_frontmatter` filter cannot write them back in.

The excerpt often repeats the opening of the body below it. A site that writes meta descriptions in an SEO plugin may want only `description:`; a site that doesn't should keep `excerpt:`, or its markdown has no summary line. The settings page warns when a save would leave no summary: the excerpt off, and either the description off or nothing to supply one (no Yoast SEO and nothing on `mmsar_frontmatter_description`).

The excerpt is taken at the site's own `excerpt_length` wherever the markdown is built. WordPress 7.0's post-excerpt block sets that length to 101 on every admin request, at `PHP_INT_MAX`, so without correction markdown rebuilt from wp-admin had longer excerpts than markdown rebuilt from the block editor or WP-CLI. The plugin lifts that one core filter for its own `get_the_excerpt()` call and puts it back afterwards.

### The description

`description:` is the post's meta description as Yoast SEO resolves it, read through `YoastSEO()->meta->for_post()` rather than the `_yoast_wpseo_metadesc` meta. That way a description produced by a content-type template is included, with its variables filled in. It goes after `excerpt:` and is left out when empty.

Rank Math, All in One SEO and SEOPress are not read yet, because none of them could be verified against a real install. Supply any source through `mmsar_frontmatter_description`, which receives Yoast's value (or `''`) and the `WP_Post`:

```php
add_filter( 'mmsar_frontmatter_description', function ( $description, $post ) {
    return (string) get_post_meta( $post->ID, 'rank_math_description', true );
}, 10, 2 );
```

Return `''` to omit the key. HTML is stripped and line breaks become spaces.

**Why Yoast edits still land on save.** Yoast saves its meta and rebuilds its indexable on `wp_insert_post`, which runs after `save_post`, where this plugin generates markdown. The plugin also hooks `wpseo_saved_indexable` and regenerates a post's markdown when its description line has changed. It compares that line first, so Yoast's bulk indexing costs a lookup per post rather than a conversion. Yoast only saves indexables on production sites (`wp_get_environment_type()`). On a local or staging site, both Yoast's page `<meta>` and this key show the description Yoast last saved there.

## Registering your own endpoints

If you've made something else on the site agent-ready — a contact form, a booking API, a product feed — it needs to be listed somewhere agents actually look. Describe it once and this plugin publishes it in `/.well-known/api-catalog`, `/llms.txt`, and the Agent Skills index together, each in that document's own idiom.

```php
add_action( 'init', function () {
    if ( ! function_exists( 'mmsar_register_endpoint' ) ) {
        return; // Plugin not installed — your integration keeps working regardless.
    }

    mmsar_register_endpoint( array(
        'title'       => 'Contact form',
        'href'        => rest_url( 'my-plugin/v1/contact' ),
        'description' => 'Send the site owner a message. Requires name, email and message.',
        'type'        => 'application/json',
        'methods'     => array( 'POST' ),
        'auth'        => 'none',
        'rel'         => 'service-desc',
    ) );
} );
```

The equivalent via filter, for code that would rather not make a direct call:

```php
add_filter( 'mmsar_registered_endpoints', function ( $endpoints ) {
    $endpoints[] = array( /* same array as above */ );
    return $endpoints;
} );
```

### Descriptor keys

| Key | Required | Meaning |
| --- | --- | --- |
| `title` | yes | Short human-readable name. |
| `href` | yes | Absolute `http(s)` URL of the endpoint. |
| `id` | no | Stable slug, used as the Agent Skills entry name. Derived from the title when omitted. |
| `description` | no | One sentence on what it does and when to use it. |
| `type` | recommended | The media type the endpoint really returns, e.g. `application/json`. Omitted when unstated — never guessed. |
| `rel` | no | api-catalog link relation: `item` (default), `service-desc`, `service-doc`, `describedby`, `status`, `terms-of-service`, `license`. |
| `methods` | no | HTTP methods accepted, e.g. `array( 'POST' )`. |
| `auth` | no | How to authenticate, e.g. `'none'` or `'X-Api-Key header'`. |
| `surfaces` | no | Which documents to appear in: `api_catalog`, `llms_txt`, `agent_skills`. Defaults to all three. |
| `skill_url` | no | Absolute URL of a `SKILL.md` you serve yourself. Gets its own entry in the Agent Skills index instead of a bullet inside this plugin's skill. |
| `skill_digest` | no | `sha256:<hex>` digest of that `SKILL.md`, so agents can cache it and detect changes. |

Register on `init` or earlier — the documents are built on `template_redirect`. A surface only publishes your endpoint while its own feature toggle is on; the settings page shows each registered endpoint and where it is actually being listed.

### What gets validated

These documents are read by agents that act on them, so a malformed entry is dropped rather than published. Registrations are rejected outright without a title and an `http(s)` URL; unrecognized link relations fall back to `item`, unrecognized HTTP methods and media types are discarded rather than passed through. Text is flattened to a single line and markdown link/code syntax is escaped, so a value containing a newline or `[link](…)` can't forge a heading, a list item, or a link in `llms.txt` or `SKILL.md` — worth knowing if your descriptions come from user input.

### Whole-document filters

For the rare change the registry can't express:

- `mmsar_api_catalog_linkset` — the complete RFC 9264 linkset, as a PHP array.
- `mmsar_llms_txt_content` — the complete `llms.txt` body. Runs on every request, after the cached content is assembled.
- `mmsar_llms_txt_budget` — the root `llms.txt` size budget in bytes (default 30000; 0 lists every entry).
- `mmsar_agent_skills_index` — the complete Agent Skills discovery index, as a PHP array.

## Architecture notes

**The `.md` catch-all rewrite rule excludes `/.well-known/`, `/auth.md` and `/okf/`.** The broad rule that serves post/page `.md` URLs (`^(.+)\.md/?$`) would otherwise also match paths like `/.well-known/agent-skills/*/SKILL.md` and every path in the OKF bundle (which also ends in `.md`), and — depending on rewrite rule registration order — can shadow more specific rules for those paths. The catch-all is scoped with a negative lookahead (`^(?!\.well-known/|auth\.md|okf/)(.+)\.md/?$`) so this can't happen regardless of what else the plugin adds under those prefixes.

**The OKF bundle mirrors the site's own permalink structure rather than inventing a new path scheme.** A concept file lives at `/okf/<same path its .md URL uses>`, resolved with the same `url_to_postid()`/`get_page_by_path()` lookup the plain `.md` endpoint uses. This means a URL that already works at the site root works again under `/okf/`, with no second slug-collision surface to reason about. It carries its own `redirect_canonical` guard for the same reason the `.md` endpoint does — otherwise WordPress tries to add a trailing slash to a path that's supposed to end in `.md`.

**TDMRep's reservation value is derived, not a separate setting.** It reads the AI Train answer in Content Signals rather than storing its own yes/no, because the two conventions answer the same question for two different audiences, and letting them disagree is a contradiction with no good resolution.

**`Link` headers are sent on `template_redirect`, not `send_headers`.** `send_headers` fires before WordPress resolves the main query, so conditional tags like `is_singular()` aren't reliable yet at that point. `template_redirect` fires after the query resolves and still early enough to set headers.

**Content Signals are emitted per AI-crawler group, never under `User-agent: *`.** That group is typically owned by an SEO plugin (Yoast, by default here) — adding to it risks fighting another plugin's output.

**Structured data merges into Yoast's schema instead of duplicating it.** Yoast's Schema Framework already declares type, url, title, and dates on every page — the only new fact this plugin adds is the `encoding`/`MediaObject` pointer to the markdown alternate. When Yoast produces a schema piece for the current page, that one field is injected directly into Yoast's own `Article`/`WebPage` piece via Yoast's documented `wpseo_schema_article`/`wpseo_schema_webpage` filters — registered unconditionally (not gated on detecting Yoast at plugin-load time, since load order across plugins isn't guaranteed; if Yoast isn't active, these filters simply never fire). Falls back to a standalone block, with no `@id`, whenever the injection doesn't apply — no Yoast, Yoast's schema output disabled, or a content type (e.g. a WooCommerce product) Yoast gives its own distinct schema to.

**Content negotiation ships with a self-check, and that is the whole reason it ships at all.** The feature was built in 1.13.0, withdrawn in 1.15.0, and reinstated in 1.18.0. It was never wrong: `Accept` and `Vary` are exactly the mechanism for serving two representations from one URL. It was withdrawn because a CDN that leaves `Accept` out of its cache key stores the markdown response and serves it to the next visitor — a reader gets a file download instead of the page — and marking the response `no-store` did not help, because the host rewrote `Cache-Control` before it reached the edge. Neither condition is visible from inside a request, and the failure lands on humans rather than on agents.

Both conditions are visible from *outside* one. `MMSAR_Negotiation_Check` requests one of the site's own pages twice — markdown-preferring `Accept` first, then a browser's, against the same URL — and reports which representation came back each time plus the `Cache-Control` and `Vary` that actually arrived. A browser-style request answered with markdown is the cache-key failure, reproduced by the plugin instead of by hand; it switches the feature back off. Headers altered in transit are reported as a warning with the observed values shown verbatim, since the person who has to raise it with their host needs the real strings. The probe URL always carries a throwaway query argument, so the check tests a fresh cache entry and can never leave a markdown copy of a real page in a shared cache — a check that caused the failure it looks for would be worse than none. Ordering matters: markdown first, so that if a cache stores it, the browser request that follows is the one handed the stored copy.

A pass means no problem was found from this server, not that none exists — the request may not traverse the same edge a distant reader hits. The settings copy says so. The 1.13.1 wording promised markdown responses "can never be shown to a visitor", which the host's header rewriting made false; nothing in the current copy guarantees an outcome that depends on infrastructure the plugin cannot observe.

**A stored setting from 1.13.x does not survive the reinstatement.** 1.15.0 removed the `markdown_negotiation` feature key from the code but left its value in the options table, so reinstating the key would have switched the feature back on for exactly the installs it had already failed on. The value is discarded once on update, claimed with `add_option()` so concurrent requests during the same upgrade cannot both perform it.

## The agent log

Off by default. Once switched on at Settings > Agent-Ready, every request for one of the surfaces
this plugin publishes — a `.md` URL, `llms.txt`, `llms-full.txt`, `security.txt`, the api-catalog,
the Agent Skills index, a `SKILL.md` — is recorded with the time, the requesting agent, and the IP
(reduced to its network for a user-run client such as Claude Code, which is a person's own machine,
and for a person who followed a link on the site to one of those files — see [Privacy](#privacy)).
There is no user-agent test on those: anything fetching `llms.txt` is agent traffic whatever it
calls itself, and filtering on user-agent would hide exactly the clients worth knowing about.

An optional sub-setting also records ordinary HTML page views — from recognized crawlers only, or
from everything including human visitors (human rows stored against the network, not the full
address; anything read as a crawler keeps its address, recognised or not, except a user-run client
such as Claude Code). That
one supplies the denominator. Without it the log shows only the agents that asked for an agent-facing
file, and "which agents ask for markdown" cannot be answered without also knowing which ones came
and did not. In practice this is where the interesting answer lives — on the author's own site, the
best-known AI crawlers turned out to fetch HTML and ignore every agent-facing file, while the
clients that actually walked the discovery chain were unbranded ones.

Four properties decide what the counts can honestly be read to mean, and all three are reported by
the ability alongside the data:

- **The log is throttled.** The same agent, surface and IP is recorded at most once per five minutes,
  so a crawler looping on one URL cannot drown out everything else. Every count is a lower bound on
  requests — the log measures reach, not volume.
- **Page-view recording is separate.** With it off, an agent that visited and ignored the
  agent-facing files leaves no trace at all, so the log is a record of who *used* these surfaces and
  never a share of agent traffic.
- **Retention is configurable.** `0` (the default) keeps everything; with a limit set, the oldest
  entries are dropped, so the first entry is not necessarily the start of the record.
- **Identity is verified, but not instantly.** Entries arrive unverified and are checked in
  batches; a `pending` count above zero means the verdict totals cover only the checked part of the
  log. See below.

### robots.txt and feeds

Every entry falls into one of eight surface categories, filterable on the screen and in the ability:
**Agent documents** (llms.txt, the catalogs, the MCP descriptors, Agent Skills, webmcp.json and
everything else this plugin publishes for agents), **Markdown**, **HTML pages**, **Not found**,
**robots.txt**, **Feeds**, **WebMCP calls** and **MCP calls**. The headline the log exists to produce is agent documents and Markdown against
HTML pages, and robots.txt and feeds are kept out of both sides of it on purpose (1.49.0). WebMCP
calls were agent documents until 1.58.0, when they got a category so they can be read on their own;
like every category, it is worked out when the log is read, so older rows move with it. MCP calls
followed in 1.59.0, for a further reason: one MCP session writes several rows (`initialize`,
`tools/list`, each `tools/call`) where reading a document writes one, so counting them as documents
inflated that side of the comparison. The Agent documents count reads lower for every date as a
result; that is the correction.

- **robots.txt** is a crawler reading the rules before deciding what to fetch — neither a document nor
  a page. WordPress serves its robots.txt through the ordinary request cycle, so before 1.49.0 every
  fetch was logged as an HTML page view: on the author's site, about 15% of all "HTML" rows over a
  month. Those older rows are *categorised* as robots.txt without being rewritten — they keep the
  surface they were recorded under, and the category is worked out when the log is read, as every
  category always has been. Only WordPress's virtual robots.txt can be seen; a `robots.txt` file on
  disk is served by the web server without PHP running.
- **Feeds** were not logged at all before 1.49.0. Every feed WordPress serves is counted — main,
  comment, category, tag, author and post-type feeds, RSS and Atom — including polls answered
  `304 Not Modified`, which is most of what a feed reader sends and which WordPress answers before
  the hooks a page view is recorded on. A feed request that another plugin redirects (Yoast's crawl
  cleanup sends the comment and Atom feeds to the homepage) is not logged, because no feed was served.
  The detail is the feed's canonical path, never a search feed's term.

Both are recorded whenever the log is on, whatever the page-view setting, and neither carries an
`Accept` summary: WordPress picks both formats from the URL, never from the header.

### Whether the caller was who it said it was

The `agent` column is a user-agent string, which the caller chooses, and forging one is common
rather than exotic. On the author's own site, three addresses each rotated through five or more
AI-crawler identities within seconds, and a readiness scanner wearing GPTBot's name accounted for
most of the traffic attributed to OpenAI. Read naively, the log said GPTBot was its best customer.

Since 1.24.0 each entry carries a verdict, shown as a badge in an *Identity* column and filterable:

| Verdict | Meaning |
|---|---|
| **Verified** | Claimed a known crawler and proved it. |
| **Spoofed** | Claimed a known crawler and is not it. The user-agent was forged. |
| **User-run client** | A crawler name sent by software running on a person's own machine, such as Claude Code fetching as Claude-User. Real agent traffic that no published method can confirm. |
| **Unverifiable** | Claimed a crawler whose operator publishes no way to check. Not an accusation — an admission that this plugin cannot tell. |
| **Unclaimed** | Named no known crawler, so there was nothing to check. Most unbranded traffic, including ordinary browsers. |
| **No DNS** | The resolver gave no usable answer. Retried on a later pass rather than left decided. |

Two methods, chosen per operator, because the operators are split on which they publish:

- **Published IP ranges** for Anthropic, OpenAI, Perplexity, DuckDuckGo (DuckAssistBot and
  DuckDuckBot), Common Crawl (CCBot), Linkup, Seznam, Mojeek, SE Ranking, Parallel (ShapBot),
  Sofya, You.com (YouBot), Palo Alto Networks (Cortex Xpanse), Known Good (KnownGood-Verifier),
  Inoreader and Amazon's search crawler (Amzn-SearchBot). Most of them
  publish no reverse-DNS records for their crawlers, so this is the only method their documentation
  describes. The ranges are bundled with the plugin rather than fetched, so nothing calls a
  third-party service and verification works on a host with no outbound HTTP. The trade-off is that
  they age: the capture date is reported alongside the verdicts, and `mmsar_agent_log_verify_ranges`
  lets you add a prefix without waiting for a release.
- **Forward-confirmed reverse DNS** for Google, Apple, Amazon, Microsoft, Ahrefs, Babbar
  (Barkrowler), Common Crawl, Huawei (PetalBot), You.com (YouBot), Yandex, Censys, LeakIX (l9scan),
  the Internet Archive (archive.org_bot), Qwant, DataForSEO, LohiSoft, Keywords Everywhere
  (PoweredByBot), Ibou (IbouBot), Baidu (Baiduspider), Cốc Cốc (coccocbot), FindFiles, Iframely and
  creasource.dev (crawl-engine). The address is reversed to a hostname, that hostname is resolved
  forward and must come back to the same address, and it must sit under a domain the claimed
  operator owns. Anyone
  can put any string in a `User-Agent`; nobody can put a record in someone else's DNS zone. A
  hostname under the operator's domain that resolves to nothing reads **No DNS**, not Spoofed, since
  that is what a resolver timeout looks like (1.58.0); before, it was an accusation Re-check never
  reopened.

A few operators publish both, and there both are used: the range is checked first, and a miss falls
through to reverse DNS rather than deciding — so a prefix added after the bundled ranges were
captured still verifies instead of reading as a forgery. Common Crawl, Perplexity and You.com are
the cases.

Every other recognised crawler reads as **Unverifiable** — its operator publishes no method this
release knows about. A suffix or range is only added once it has been confirmed against the
operator's own documentation *and* a real address from a live log, because a wrong entry produces
confident "Spoofed" verdicts against genuine crawlers.

**That list is re-checked, not assumed.** "Publishes no method" is true on the day a crawler is
added and can stop being true later — Common Crawl published both methods while CCBot was still
being read as unverifiable here. The harder half is declining: MJ12bot, SemrushBot,
meta-externalagent and Bytespider all have working reverse DNS on *some* addresses while their
operators document no convention at all, so adopting one would verify those addresses and turn
every genuine caller without the matching hostname into an accusation of forgery. Majestic says
plainly that it cannot restrict MJ12bot to fixed addresses; Semrush says it uses no consecutive IP
blocks. Both stay unverifiable on purpose. AgentTrustBot is the reverse case: its operator does
document reverse DNS, but the address it actually crawled from is on the operator's own published
list and does not resolve under that domain, so following the documentation would have accused the
genuine crawler. DomainStatsBot is the same case (1.50.0): all three of its addresses reverse to the
documented `bot.domainstats.com`, but that name resolves forward to only one of them.
Dataprovider.com documents reverse DNS under `dataproviderbot.com`, but the one full address on file
had no reverse record at all, so it stays unverifiable until a real address confirms it (1.54.0). Amazon's search crawler is the one bundled list expected to go stale fastest: Amazon publishes it
as single cloud addresses, so a new one used before the plugin is updated reads as Spoofed until then
(1.58.0).

**Unverifiable** also covers a second case that says nothing about the operator: a row whose address
was reduced to its network at storage time cannot be tested against a published range, so the
verdict is withheld rather than guessed. Withholding is deliberate — a range check against a network
address answers "no", which would read as `Spoofed` and accuse a real operator. Since 1.43.0
anything the log reads as a crawler keeps its full address, so this applies to rows written before a
crawler was recognised rather than to new traffic.

**User-run clients get their own verdict (1.47.0).** Claude Code and the Claude desktop app's Code
tab fetch pages from the user's own machine and still send a `Claude-User` user-agent, with a
`claude-code/<version>` token in it. Such a request can never come from Anthropic's published ranges,
so until 1.47.0 every Claude Code session was reported as **Spoofed** — often the most engaged agent
traffic in the log, counted as forgery. The token now earns **User-run client** instead: not verified,
because it cannot be, and not an accusation. A `Claude-User` claim *without* the token, from outside
Anthropic's ranges, is still Spoofed. The token is self-declared and can be forged like any
user-agent, which is why the verdict stops at "user-run client" and never reaches "verified". Claude's
chat — claude.ai and the desktop app outside its Code tab — is the other half of `Claude-User`, and it
fetches from Anthropic's servers: a probe on 2026-09-23 asked desktop chat to read a unique address,
which arrived as a bare `Claude-User` from `34.162.191.81`, inside Anthropic's published list, and read
Verified. So a verified `Claude-User` means a fetch from Anthropic's infrastructure, not necessarily a
person in the Claude app. These
entries are stored against the network rather than the full address, because the address belongs to a
person and there is nothing to verify against it. Entries logged before 1.47.0 stored every
`Claude-User` request under the bare name, so the token was never kept and those Claude Code sessions
still read Spoofed; the screen and the ability say so beside the count. ChatGPT-User and
Perplexity-User are deliberately not treated this way — nothing shows their clients fetching from user
machines.

### What kind of bot it was

Recognised crawlers are not all AI crawlers. Search indexes feed AI answers and SEO companies run AI
products, so rather than keep separate lists, every recognised bot sits in one list and carries a
category:

| Category | Meaning |
|---|---|
| **AI training** | Collects content to train models (GPTBot, ClaudeBot, CCBot…) |
| **AI search** | Builds or queries an index used to answer questions (OAI-SearchBot, PerplexityBot, LinkupBot, Amzn-SearchBot…) |
| **AI assistant** | Fetches a page because a person asked an assistant right then (ChatGPT-User, Claude-User, Amazon Quick…) |
| **Search engine** | Conventional web search (SeznamBot, DuckDuckBot, YandexBot, Baiduspider, Qwantbot…) |
| **SEO tool** | SEO and backlink platforms (AhrefsBot, SemrushBot, Barkrowler, DataForSeoBot…) |
| **Monitoring** | Brand and media monitoring (AwarioBot, trendictionbot, YaK, um-LN) |
| **Scanner** | Readiness, security and attack-surface scanners (OraBot, KnownGood-Verifier, AgentReadyScanner, CensysInspect, Cortex Xpanse, l9scan, AgentTrustBot) |
| **Other** | Link previews, feed readers, archiving and everything else named (Twitterbot, facebookexternalhit, Feedly, Inoreader, archive.org_bot…) |

A category goes by what the operator documents *that specific bot* doing, not by the operator's
business overall. It is shown under the agent name, filterable as **Crawler type**, and returned by
the ability as `crawler_category` and `by_crawler_category`. It is derived on read with the same
matching that decides the verdict, so entries logged before a crawler was recognised are categorised
too. A category describes a *claim* — read it alongside the verdict before attributing traffic to an
operator.

**No lookup ever happens while a page is being served.** DNS can block for seconds, and the log
records requests while content is going out to the caller. There is no cron either. Verification
runs in a small bounded batch when an administrator opens the Agent Log screen or reads the log
through the ability, and in a larger batch from the **Verify now** button — all of them
authenticated admin contexts, and all of them bounded by a wall-clock budget.

### Which browser rows are agents

`client_type` separates browser engines from HTTP clients. It cannot separate a person from an agent
driving a real browser, because that agent *is* a browser and sends everything a reader sends. Since
1.48.0 browser-shaped traffic carries three signals, shown in a *Signals* column and filterable. None
is a verdict, and none moves a row out of the browser count; they annotate it. Since 1.55.0 the cloud
signal also covers HTTP clients, where it answers a different question (see below).

| Signal | What it is | What it cannot tell you |
|---|---|---|
| **Signed** | The request carried a [Web Bot Auth](https://datatracker.ietf.org/doc/draft-ietf-webbotauth-httpsig-protocol/) signature (HTTP Message Signatures, RFC 9421, `tag="web-bot-auth"`) and a `Signature-Agent` naming the operator, stored as its origin — `https://chatgpt.com` for ChatGPT agent. | Whether the claim is true. The signature is recorded, not verified: checking it means fetching the operator's key directory, which is a call to a third party this plugin does not make. Copying the headers is trivial. |
| **Cloud network** | The address is in a published AWS (EC2), Google Cloud, Azure, Oracle, DigitalOcean, Linode or Vultr range. Cloud browser agents arrive this way. Assessed on browser and HTTP-client rows, not on named crawlers. | Whether the visitor is an agent. People on some VPNs and corporate security proxies arrive from the same ranges. Cloudflare WARP, iCloud Private Relay and home and mobile networks are deliberately not in the list. |
| **Came from a link here** | The Referer's host is this site's. Separates a click, such as the footer's llms.txt link, from a direct fetch. | Where the click came from. Only yes or no is stored. |

**The cloud signal is derived on read, not stored.** Almost all published cloud address space comes in
blocks of /24 or wider (IPv4) and /64 or wider (IPv6), which is exactly the precision the log reduces
a reader's address to, so the stored network answers the question. Where a stored /24 only partly
overlaps a narrower cloud block, the answer is "possibly a cloud network" rather than a guess. Being
derived makes it retroactive: entries logged before 1.48.0 are covered. The ranges are bundled in
`includes/data/cloud-ranges.php`, generated by `tools/build-cloud-ranges.py` from each provider's own
published list, and dated; a stale snapshot misses new ranges rather than accusing anyone. The
generator drops IANA special-purpose blocks, because Vultr's own feed lists `2002::/16` (6to4), which
would otherwise label real people as a Vultr network.

**On HTTP clients the cloud signal is attribution, not a human-or-agent test (1.55.0).** A request from
axios, curl or python-requests is already known to be software, and most of them name nothing. Whether
one runs on a cloud provider or on someone's own connection is the only thing the log can say about
who runs it. It does separate them: on the site this plugin was built on, 20% of HTTP-client requests
came from a published cloud range, against 11% of browser requests. Named crawlers are left out,
because their operator's own verification method says more than their network does.

**What no signal covers:** an agent that runs inside the person's own browser, such as Claude for
Chrome or Perplexity Comet. It signs nothing, uses the person's own network and follows links the
way a person does. Here it is indistinguishable from a human reader.

### Privacy

The log is off by default. When it is on, this is what it keeps about people:

- **Page views from anything not recognised as a crawler** store the network, not the address —
  `203.0.113.4` becomes `203.0.113.0`, IPv6 keeps its first four groups. The five-minute throttle
  keys on the real address in memory; it never reaches the database.
- **A person who follows a link on the site to an agent-facing file** — a real browser, unsigned,
  following a link from the site's own pages, from outside every cloud network — is stored at
  network level too (1.48.0). Other agent-file requests keep the full address, because they are
  almost always automated and the exact address is what identified the scanner pool.
- **User-run clients such as Claude Code** are a person's own machine, and are stored at network
  level on every surface.
- **Feeds and robots.txt** follow the page-view rule. A feed read in a browser or a desktop reader
  is stored at network level. A self-hosted reader such as Miniflux is software a person installs,
  often at home, so it keeps its full address only from inside a cloud-provider network (1.49.0).
  A hosted reader that names itself, such as Feedly or Inoreader, runs on its operator's servers and
  keeps its address like any crawler.
- **The page address is kept as requested**, query string included, so an internal search is
  recorded as typed (1.27.0). The throttle keys on the resolved page instead.
- **The Referer is never stored.** Only whether it was this site.
- **The cloud signal stores nothing.** It is derived from the network address already held.
- **Web Bot Auth signatures come from agents, never from people's browsers**, so recording one stores
  nothing about a reader.

- **WebMCP calls come from a visitor's own browser** (1.57.0), so they are stored at network level,
  like a page view. They and requests for `webmcp.json` (1.58.0) are the browser rows the default
  view shows, because they are agent activity rather than people reading pages. So are MCP calls
  (1.59.0): no browser makes one, so an MCP row filed as a browser is a mislabel from the 1.26.0–1.30.1
  rule that read Node's fetch as a browser.
- **MCP tool arguments are stored only when they match the site** (1.56.0): an enabled content
  type, an existing topic slug, the path of the post `get_content` served (never the URL as typed),
  known overview sections. A search query is free text an agent may have copied from a person, so
  it is stored only when the owner ticks *Record what agents search for*, with email-shaped text and
  runs of nine or more digits replaced and a 300-character cap.
- **Suggested privacy-policy text** is added under Settings > Privacy, describing only the logging
  options that are switched on.

- **AI referral counts keep nothing about the visitor.** A day, an assistant, a landing page and a
  count. No address, no user-agent, no referrer URL, no cookie.

Recognised crawlers keep their full address, because verification needs it. Uninstalling the plugin
drops the table, and the Agent Log screen can clear it at any time.

### The Summary

The Agent Log opens on a Summary: what the last 30 days mean, not the rows themselves. Findings sit under three questions, and each one is a sentence, the numbers behind it, a link to the matching log rows, and a badge saying which kind it is: **Worth doing** when there is an action, **FYI** when it is context only:

- **Am I being read?** Who takes the Markdown version (by crawler and category), edited pages no AI search crawler has re-read (links to IndexNow), and coverage, but only when it is below 80%. And, from 1.58.0, whether agents in a visitor's browser used the WebMCP tools: calls per tool, the pages they read, what they asked about, and how often webmcp.json was fetched. Silent below three calls, so a site owner's own test does not count as use.
- **Am I being cited?** The pages AI assistants (ChatGPT-User, Claude-User and similar) fetched while answering someone, and the visitors they sent.
- **What's broken or being ignored?** Training crawlers reading despite `ai-train=no`, or despite the decline above once two days have passed, and 404s for known agent standards with what to do about each. Paths that match no standard, mostly security probes, are counted in one line rather than listed.

Every finding has a threshold and stays silent below it. A row whose identity check failed is never counted. WebMCP calls claim no crawler identity, so the WebMCP finding counts them as they are. Verified rows and user-run clients such as Claude Code count, and the copy says the latter may be the owner. Unconfirmed rows are reported alongside. Computed on read and cached for an hour. The same findings are on the dashboard widget (the top one) and in the `get-agent-insights` ability.

The thresholds and wording came from testing every candidate finding against 30 days of the author's own log. Two obvious ones were noise there: "pages no AI crawler read" (81 of 82 were read) and the raw 404 list (mostly probes).

### Visitors sent by AI assistants

Off by default, and only available while the log is on. The log answers "who reads this site";
this answers the next question, "does any of it send people back".

A visit counts when the referrer is an assistant's own domain (`chatgpt.com`, `perplexity.ai`,
`claude.ai`, `gemini.google.com`, `copilot.microsoft.com`, `chat.deepseek.com`, `grok.com`,
`chat.mistral.ai`, `meta.ai`, `you.com`, `phind.com`, `duck.ai`, matched dot-bounded) or the
landing URL carries a matching `utm_source`. `bing.com` and `google.com` are deliberately absent:
they carry Copilot and AI Overviews clicks, but also every ordinary search click.

**It needs a script, which is the one thing this plugin runs in a visitor's browser.** An assistant
referral is an ordinary page view, and a cached page view never reaches PHP. On the author's site a
repeat request carrying `Referer: perplexity.ai`, or `?utm_source=chatgpt.com`, was a Cloudflare
`HIT`. The inline script (about 1.2 KB) is printed through `wp_print_inline_script_tag()`, so a CSP
nonce filter can reach it. It does nothing unless the referrer or `utm_source` matches, and then
sends one `POST` to `/wp-json/mmsar/v1/referral` with the referrer host, the `utm_source` and the
path.

**The server trusts none of it.** It re-classifies against the same list, counts the landing page
as the permalink it resolves to (`/` for the front page, `(other)` for anything that is not a
published post), never the path sent, and counts one address once per assistant and page per half
hour. Counts live in their own table, `{prefix}mmsar_referrals`, not in the agent log. These are
people, and the log's surface categories and identity checks are built for agents.

**It is a floor.** Many assistant apps open links without a referrer, and only ChatGPT tags
`utm_source` consistently.

### Reading it

The screen paginates at 50 entries. **Export CSV** writes the whole log — columns `logged_at_utc`,
`agent`, `surface`, `detail`, `ip`, `verified`, `verified_at_utc`, `client_type`, `signature_agent`,
`same_site`, `cloud_network`, `arguments` — streamed in batches so peak
memory does not grow with the log. Columns are only ever appended, never reordered. The
timestamp column is named for its timezone on purpose: rows are stored in UTC and the screen renders
them in the site's timezone. Cells whose value begins `=`, `+`, `-`, `@`, tab or CR are written with
a leading apostrophe, because the agent column holds a user-agent string chosen by the caller and
spreadsheets execute such cells as formulas on open.

Until 1.47.1 entries were also copied into the [Activity Log](https://wordpress.org/plugins/aryo-activity-log/)
plugin. That copy has been removed: it duplicated this screen with less information, and it carried
full IP addresses, including for entries this log stores only at network level. Copies already
written there belong to that plugin and are left alone.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Requirements

- WordPress 6.2+
- PHP 7.4+

## License

GPL-2.0-or-later

## WordPress Abilities API

This plugin exposes abilities for the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) (WordPress 6.9+), making it manageable by AI agents via the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin.

### Requirements

- WordPress 6.9+
- [MCP Adapter plugin](https://github.com/WordPress/mcp-adapter)

### Available abilities

| Ability | Access | Description |
|---|---|---|
| `make-my-site-agent-ready/get-settings` | Always on | Returns the enabled post types and content root CSS selector |
| `make-my-site-agent-ready/regenerate-files` | Always on (destructive) | Regenerates cached markdown for all published content and clears the llms.txt and llms-full.txt caches. AI tools will ask for confirmation before running. |
| `make-my-site-agent-ready/list-endpoints` | Always on | Lists every endpoint being published, flagging which are managed on the settings page and which a plugin or theme registered in code, plus where each is actually appearing right now. |
| `make-my-site-agent-ready/set-endpoint` | Always on | Adds an endpoint, or updates one already managed on the settings page. Send only the fields you want changed when updating. |
| `make-my-site-agent-ready/delete-endpoint` | Always on (destructive) | Removes an endpoint managed on the settings page. |
| `make-my-site-agent-ready/get-agent-insights` | Always on (read-only) | The Agent Log Summary as data: findings about the last 30 days under read, cited and broken, each with a kind, a sentence, supporting items and usually an action. Counts only verified crawler identities and user-run clients, never forged ones; WebMCP tool calls, which claim no identity, count as they are. Cached hourly; pass `fresh` to recompute |
| `make-my-site-agent-ready/get-agent-log` | Always on (read-only) | Reads the agent request log: counts by agent, by surface, by requested detail and by day across the whole log, a verification breakdown, plus a page of individual entries. Pass `summary_only` for the aggregates alone, which carry counts of distinct IPs but no addresses, or `verified` to list only entries with a given verdict — `failed` lists the requests that forged a crawler identity. Every entry and `by_agent` row carries a `crawler_category`, `by_crawler_category` breaks traffic down by kind of bot, and the `crawler_category` input filter (`ai` for all three AI categories) separates AI traffic from search and SEO traffic. A `signals` block, a per-entry `signals` object and a `signal` filter carry the browser signals (signed, cloud network, came from a link on the site), each with its limits stated in the schema. `surface_categories` counts every entry by surface category — `docs`, `markdown`, `html`, `notfound`, `robots`, `feed`, `webmcp` (WebMCP tool calls, 1.58.0) and `mcp` (MCP server calls, 1.59.0) — and the `surface` filter takes the same values. Each entry carries `arguments`: on an MCP tool call, what the caller asked for (1.56.0). |

Endpoints a plugin or theme registered in code are read-only to `set-endpoint` and `delete-endpoint`: both return a `409` explaining that the owning plugin or theme has to be edited instead. Reporting success for a write that changed nothing would be worse than refusing it.
