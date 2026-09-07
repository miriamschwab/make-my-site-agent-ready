# Make My Site Agent-Ready — WordPress Plugin

A WordPress plugin that makes your site ready for AI agents and language models. Serves clean markdown at `.md` URLs, generates `/llms.txt` and `/llms-full.txt` site indexes, serves `/.well-known/security.txt`, publishes a machine-readable `/.well-known/api-catalog`, exposes Agent Skills discovery, sends `Link` response headers advertising all of it, declares AI usage preferences via Content Signals in `robots.txt`, adds AI crawler rules, optionally points agents at the markdown alternate via JSON-LD structured data (merging into Yoast SEO's own schema when active, so nothing is duplicated), and exposes WordPress Abilities API endpoints for AI agent management.

## Why

AI models and agents increasingly need to read website content, discover what's available, and know what a site owner will and won't let them do with it. HTML is noisy for the first problem — navigation, ads, scripts, and styling all get in the way. Discovery and usage preferences are largely unsolved by default WordPress at all. This plugin addresses all three: clean markdown for reading, machine-readable indexes and headers for discovery, and explicit signals for usage preferences.

Eight existing plugins were analyzed before building the original `.md`/llms.txt feature set. Most were overengineered — custom converters, content negotiation, user-agent sniffing. This plugin takes a simpler approach throughout: generate markdown once on save, serve pre-built indexes, declare preferences plainly.

## Features

Every feature below can be switched off individually under **Settings > Agent-Ready**. Everything defaults to on (except structured data), and a disabled feature registers nothing at all — no rewrite rule, no filter, no `Link` header — so the site behaves as if that part of the plugin did not exist.

### Content access
- **`.md` URL suffix** — any post or page is available at its URL with `.md` appended (e.g., `your-site.com/my-post.md`)
- **Front page** at `/index.md`
- **YAML frontmatter** — title, date, author, URL, excerpt, categories, and tags
- **Pre-generated on save** — markdown is stored in post meta, so `.md` requests serve instantly with zero processing
- **`/llms.txt` site index** — lists all available markdown URLs organized by category, cached with 24-hour transient
- **`/llms-full.txt`** — full site content concatenated as markdown in a single file, for LLMs that want everything at once
- **`<link rel="alternate">`** — HTML pages include a link tag pointing to their markdown version
- **Markdown from the canonical URL** — opt-in, off by default. Answers a request for an ordinary page with its markdown when the request's `Accept` header prefers markdown, which is how AI fetch tools actually ask; the `.md` mirror only helps a client that already knows the mirror exists. The `Accept` parsing is strict — markdown must be named explicitly and outrank HTML, a wildcard counts only towards HTML, and a tie goes to HTML. `Vary: Accept` is sent on both representations. Ships with a self-check (see Architecture notes) because whether this is safe depends on infrastructure the plugin cannot see.

### Discovery
- **`/.well-known/api-catalog`** (RFC 9727) — a Linkset (RFC 9264) JSON document indexing `llms.txt`, `llms-full.txt`, `security.txt`, the Agent Skills index, the sitemap, and the feed in one machine-readable file
- **Agent Skills discovery** — `/.well-known/agent-skills/index.json` plus a bundled skill (`fetch-content-as-markdown`) teaching an agent how to use this plugin's markdown endpoints instead of parsing HTML. The served skill file and its index digest are computed from the same source at request time, so they can never drift out of sync.
- **`Link` response headers** (RFC 8288) — every front-end response carries `Link` headers pointing to the api-catalog and the Agent Skills index; singular posts/pages add a third pointing to their markdown alternate. Lets agents that only read headers, never HTML, still find these resources.
- **Structured data (JSON-LD)** — opt-in, off by default. Points agents at the markdown alternate via an `encoding`/`MediaObject` field. When Yoast SEO is active and produces schema for the page, this merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block, nothing else in Yoast's graph touched. Otherwise (no Yoast, or a page type Yoast doesn't cover), a standalone minimal `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.

### Usage preferences and crawler rules
- **Content Signals** — `Content-Signal: search=..., ai-input=..., ai-train=...` (per [contentsignals.org](https://contentsignals.org/) / the IETF AI Preferences draft) declared under each AI crawler's group in `robots.txt`. Configurable per-site: allow indexing, allow live AI retrieval, allow/decline model training use, independently.
- **AI crawler rules in `robots.txt`** — explicit `Allow: /` entries for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot, and Amazonbot. Appends rather than replaces, so it works alongside a `robots.txt` generated by an SEO plugin. Also adds a `Sitemap:` directive if nothing else already has — detecting Yoast, Rank Math, All in One SEO, SEOPress, or WordPress core sitemaps to get the filename right. Switch this feature off and the plugin stops touching `robots.txt` entirely, including the rewrite rule that routes it through WordPress.
- **`/.well-known/security.txt`** — serves a security.txt file (RFC 9116). Enter your security contact as a full URL, a path like `/contact`, or an email address; the plugin expands it into a valid Contact URI. Falls back to the site admin email if unset. A free-text field is available for sites needing extra fields such as Encryption or Policy.

### Configuration and operations
- **Settings page** (Settings > Agent-Ready) — per-feature on/off toggles, post type selector, CSS root selector, robots.txt preview and extra-rules textarea, security contact, Content Signals toggles, a structured data (JSON-LD) toggle, and Quick Links to every endpoint currently being served
- **Bulk regeneration** — "Regenerate All" button on the settings page
- **Agent request log** (off by default) — records which agents fetch the surfaces above, on its own screen at Settings > Agent Log, with a retention setting, a dashboard widget, a CSV export of the whole log, and a read-only ability so an agent can read it too. See [The agent log](#the-agent-log)
- **Proper HTTP headers** — `Content-Type: text/markdown`, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, canonical link
- **Password protection** — password-protected posts return 403 on `.md` URLs
- **Clean uninstall** — removes all plugin data (post meta, options, transients)

## How it works

1. When you save a post, the plugin converts its rendered HTML to markdown using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) and stores it in post meta
2. A single rewrite rule catches all `.md` requests (excluding `/.well-known/` paths, which route to their own handlers — see Architecture Notes below)
3. The plugin resolves the request to a post, reads the pre-generated markdown from meta, and serves it with proper headers
4. The `/llms.txt` endpoint builds a categorized index of all available markdown URLs
5. The `/llms-full.txt` endpoint concatenates the full content of all posts and pages into a single file
6. `/.well-known/api-catalog`, the Agent Skills endpoints, and `Content-Signal` directives are all generated the same way — computed from live site state at request time, not hand-maintained static files

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
date: "2026-01-15"
author: "Jane Doe"
url: "https://your-site.com/hello-world/"
excerpt: "Welcome to my site."
categories:
  - "Uncategorized"
tags: []
---

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
- `mmsar_agent_skills_index` — the complete Agent Skills discovery index, as a PHP array.

## Architecture notes

**The `.md` catch-all rewrite rule excludes `/.well-known/`.** The broad rule that serves post/page `.md` URLs (`^(.+)\.md/?$`) would otherwise also match paths like `/.well-known/agent-skills/*/SKILL.md`, and — depending on rewrite rule registration order — can shadow more specific rules for those paths. The catch-all is scoped with a negative lookahead (`^(?!\.well-known/)(.+)\.md/?$`) so this can't happen regardless of what else the plugin (or a future version of it) adds under `/.well-known/`.

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
the Agent Skills index, a `SKILL.md` — is recorded with the time, the requesting agent, and the IP.
There is no user-agent test on those: anything fetching `llms.txt` is agent traffic whatever it
calls itself, and filtering on user-agent would hide exactly the clients worth knowing about.

An optional sub-setting also records ordinary HTML page views from recognized AI crawlers. That one
supplies the denominator. Without it the log shows only the agents that asked for an agent-facing
file, and "which agents ask for markdown" cannot be answered without also knowing which ones came
and did not. In practice this is where the interesting answer lives — on the author's own site, the
best-known AI crawlers turned out to fetch HTML and ignore every agent-facing file, while the
clients that actually walked the discovery chain were unbranded ones.

Three properties decide what the counts can honestly be read to mean, and all three are reported by
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
| **Unverifiable** | Claimed a crawler whose operator publishes no way to check. Not an accusation — an admission that this plugin cannot tell. |
| **Unclaimed** | Named no known crawler, so there was nothing to check. Most unbranded traffic, including ordinary browsers. |
| **No DNS** | The resolver gave no usable answer. Retried on a later pass rather than left decided. |

Two methods, chosen per operator, because the operators are split on which they publish:

- **Published IP ranges** for Anthropic, OpenAI and Perplexity. None of them publishes reverse-DNS
  records for its crawlers, so this is the only method their documentation describes. The ranges are
  bundled with the plugin rather than fetched, so nothing calls a third-party service and
  verification works on a host with no outbound HTTP. The trade-off is that they age: the capture
  date is reported alongside the verdicts, and `mmsar_agent_log_verify_ranges` lets you add a prefix
  without waiting for a release.
- **Forward-confirmed reverse DNS** for Google, Apple, Amazon, Microsoft and DuckDuckGo. The address
  is reversed to a hostname, that hostname is resolved forward and must come back to the same
  address, and it must sit under a domain the claimed operator owns. Anyone can put any string in a
  `User-Agent`; nobody can put a record in someone else's DNS zone.

**No lookup ever happens while a page is being served.** DNS can block for seconds, and the log
records requests while content is going out to the caller. There is no cron either. Verification
runs in a small bounded batch when an administrator opens the Agent Log screen or reads the log
through the ability, and in a larger batch from the **Verify now** button — all of them
authenticated admin contexts, and all of them bounded by a wall-clock budget.

### Reading it

The screen paginates at 50 entries. **Export CSV** writes the whole log — columns `logged_at_utc`,
`agent`, `surface`, `detail`, `ip`, `verified`, `verified_at_utc` — streamed in batches so peak
memory does not grow with the log. Columns are only ever appended, never reordered. The
timestamp column is named for its timezone on purpose: rows are stored in UTC and the screen renders
them in the site's timezone. Cells whose value begins `=`, `+`, `-`, `@`, tab or CR are written with
a leading apostrophe, because the agent column holds a user-agent string chosen by the caller and
spreadsheets execute such cells as formulas on open.

Entries are also mirrored into the [Activity Log](https://wordpress.org/plugins/aryo-activity-log/)
plugin under the object type `Agent-Ready` wherever its API is reachable. The plugin's own table is
the record; the mirror is a convenience and can never affect a response being served.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Requirements

- WordPress 6.0+
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
| `make-my-site-agent-ready/get-agent-log` | Always on (read-only) | Reads the agent request log: counts by agent, by surface, by requested detail and by day across the whole log, a verification breakdown, plus a page of individual entries. Pass `summary_only` for the aggregates alone, which carry counts of distinct IPs but no addresses, or `verified` to list only entries with a given verdict — `failed` lists the requests that forged a crawler identity. |

Endpoints a plugin or theme registered in code are read-only to `set-endpoint` and `delete-endpoint`: both return a `409` explaining that the owning plugin or theme has to be edited instead. Reporting success for a write that changed nothing would be worse than refusing it.
