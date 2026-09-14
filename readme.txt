=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.40.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site agent-ready: markdown URLs, OKF bundle, llms.txt, MCP, OpenAPI, api-catalog, Agent Skills, TDMRep, and AI crawler rules.

== Description ==

Make My Site Agent-Ready makes your WordPress content accessible to AI language models and AI agents. Every post and page gets a markdown endpoint automatically, a site index and a typed Open Knowledge Format bundle are generated for discovery, and the full site content is available in one request for LLMs that want it.

Every feature below can be switched off individually under Settings > Agent-Ready, so the plugin stays out of the way of anything you already manage elsewhere. Most default on; a handful default off, each for its own stated reason (see Features below and the settings page itself).

**Features:**

* **Individual feature toggles** — Turn off any output the plugin publishes. A disabled feature registers nothing at all — no rewrite rule, no filter, no header — so the site behaves as if that part of the plugin did not exist.
* **`.md` URLs** — Append `.md` to any post or page URL to get a clean markdown version
* **Markdown from the normal page URL** — Optional (off by default). Answers a request for an ordinary page with its markdown when the request's `Accept` header asks for markdown, which is how AI clients ask. Comes with a self-check that requests one of your own pages as an agent and then as a browser, reports which version came back and what cache headers survived, and switches the feature off by itself if a browser-style request is ever answered with markdown. Leave it off if your site is behind a CDN that ignores `Vary: Accept` — Cloudflare does
* **`?mode=agent`** — Appended to any URL, returns that page as Markdown; on the homepage, a summary of every machine-readable surface the site has
* **llms.txt** — Auto-generated site index at `/llms.txt` listing all available markdown content, per v2 of the llms.txt proposal (scoped indexes per section, discoverable by link relation)
* **llms-full.txt** — Full site content in one file at `/llms-full.txt` for LLMs that want everything
* **OKF bundle** — Serves `/okf/`, the same content as an Open Knowledge Format v0.2 tree: a root index, one index per post type, and one typed Markdown "concept" file per post/page (front matter: type, title, description, resource, tags, modified), plus a change log at `/okf/log.md`. Reuses the same generated markdown as the `.md` URLs
* **OpenAPI specification** — Serves `/openapi.json`, an OpenAPI 3.1 description of every public endpoint this plugin serves, generated from the site's actual registered routes
* **MCP server** — Optional (off by default). A read-only Model Context Protocol endpoint AI clients can connect to directly, with tools to search the site, list content, and read a page as Markdown. Rate-limited, and exposes nothing llms-full.txt doesn't already publish
* **auth.md** — Serves `/auth.md`, a plain-language explanation of how an agent gets access to the site — usually "you don't need credentials," stated so an agent doesn't assume otherwise
* **Agentic Resource Discovery catalog** — Serves `/.well-known/ai-catalog.json` (also at `/.well-known/ard.json`), a typed inventory of the site's agentic resources with stable identifiers
* **security.txt** — Serves `/.well-known/security.txt` (RFC 9116). Enter your security contact as a full URL, a path like `/contact`, or an email address, and the plugin formats it correctly
* **api-catalog** — Serves `/.well-known/api-catalog` (RFC 9727), a machine-readable index linking llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed
* **Agent Skills discovery** — Serves `/.well-known/agent-skills/index.json` plus a bundled skill teaching agents how to use this plugin's markdown endpoints
* **NLWeb `/ask` endpoint** — Optional (off by default). Answers questions about the site in NLWeb's shape, retrieval only. Ships with a Schemamap at `/schema-map.xml` plus a `Schemamap:` robots.txt directive
* **MCP Apps UI** — Experimental, off by default. Lets an MCP client render search/list results as a card list instead of plain text
* **Agent-recoverable 404s** — A 404 adds Link headers and a short Markdown list pointing at the sitemap, llms.txt and endpoint catalog, instead of leaving an agent with nothing but a dead end. Looks identical to visitors
* **Link response headers** — Every front-end response carries `Link` headers (RFC 8288) pointing to whichever of the resources above are switched on; singular posts/pages add one more pointing to their markdown alternate — so agents that only read headers, not HTML, can still find these resources
* **Content Signals** — Declares `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org) under each AI crawler's group in `robots.txt`, configurable in Settings > Agent-Ready. Defaults to allowing search and live AI retrieval, declining AI training use.
* **TDMRep reservation header** — Sends `tdm-reservation: 1` or `0` on every response, the machine-readable form EU copyright law (DSM Directive Article 4) requires for a text-and-data-mining reservation to count. The value is derived from the AI Train setting above, not a separate choice, so the two can never disagree. An optional Policy URL is sent alongside it when reserving
* **Structured data (JSON-LD)** — Optional (off by default) pointer to the markdown alternate on each enabled post/page. When Yoast SEO is active and produces schema for the page, the pointer merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block. Otherwise, a standalone `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.
* **AI crawler rules** — Adds explicit `Allow: /` entries for GPTBot, ClaudeBot, and other AI crawlers in `robots.txt`
* **llms.txt discovery in robots.txt** — Adds an `Llms-txt:` directive pointing at your `/llms.txt`, so agents that fetch `robots.txt` first are told where the index is. Skipped if llms.txt is switched off, or if `robots.txt` already mentions it
* **Endpoints stay reachable** — If `robots.txt` disallows a path one of your published endpoints lives on (several SEO plugins disallow `/wp-json/` by default), an `Allow:` line for that individual endpoint is added above the rule blocking it. The endpoint stays reachable to agents that found it in your api-catalog, llms.txt or Agent Skills index; the rest of the REST API stays disallowed
* **Deprecation/Sunset headers** — For surfaces you schedule for retirement (via a filter), responses carry `Deprecation` and `Sunset` headers so an agent is told a URL is going away before it does. Empty, and inactive, until you fill in a schedule
* **YAML frontmatter** — Title, date, author, URL, excerpt, categories, and tags
* **Pre-generated** — Markdown is generated when posts are saved, so `.md` requests are instant
* **Discoverable** — Adds `<link rel="alternate" type="text/markdown">` to page headers
* **Lightweight** — No cron jobs, no frontend JavaScript. The optional agent request log is the only feature that adds a database table, and only once you switch it on

**How it works:**

1. When you save a post, the plugin converts it to markdown and stores it in post meta
2. When someone requests `your-post.md`, the pre-generated markdown is served instantly
3. The `/llms.txt` file lists all available markdown URLs organized by category
4. The `/llms-full.txt` file concatenates the full content of all posts and pages
5. The OKF bundle at `/okf/` wraps the same markdown in typed front matter, addressable one concept at a time

== Installation ==

1. Upload the `make-my-site-agent-ready` folder to `/wp-content/plugins/`.
2. Activate from Plugins > Installed Plugins.
3. Configure under Settings > Agent-Ready.
4. Visit `/llms.txt` on your site to verify the index.

== Frequently Asked Questions ==

= A visitor got a markdown file instead of my page. What happened? =

Switch off "Markdown from the normal page URL" under Settings > Agent-Ready. That feature answers a page request with markdown when the request asks for markdown, and it relies on caches honoring the `Vary: Accept` header, which tells them the two versions are not interchangeable. Some CDNs ignore it — Cloudflare among them — and then hand the markdown copy to whoever asks next, including people. The plugin also marks that response uncacheable as a second line of defence, but some hosts rewrite that header before it leaves their network.

This is the only way the plugin can affect what a human visitor sees, which is why the feature ships off and why the check on that settings screen exists. Run it: if a browser-style request comes back as markdown, the check switches the feature off itself. Your `.md` URLs are unaffected and keep working.

= Does this slow down my site? =

No. The only impact on normal page loads is a single `<link>` tag in the HTML head. Markdown is pre-generated on post save, so `.md` requests serve directly from the database with no runtime conversion.

= What URL format does it use? =

Append `.md` to any post or page URL. For example: `example.com/my-post.md`. The front page is available at `example.com/index.md`.

= What is llms.txt? =

It's an emerging convention (similar to robots.txt) that helps AI models discover available content on your site. The file at `/llms.txt` lists all your markdown-enabled content.

= What is llms-full.txt? =

A companion to `llms.txt` — it concatenates the full markdown content of all published posts and pages into a single file for LLMs that want the entire site in one request.

= Can I control which post types get markdown? =

Yes. Go to Settings > Agent-Ready and check the post types you want to enable.

= I have a very large site — will activation or "Regenerate all" time out? =

On activation and when you regenerate manually, the plugin converts every published post in one request, which can be slow or hit memory/time limits on sites with thousands of posts. Use the `mmsar_bulk_generate_limit` filter to cap how many posts are processed per run (default `-1` = all):

`add_filter( 'mmsar_bulk_generate_limit', function() { return 500; } );`

Remaining posts are still converted on demand the first time their `.md`, `/llms.txt`, or `/llms-full.txt` is requested, and the result is cached from then on.

= I made something else on my site agent-ready. Can I get it listed in these files? =

Yes, and you don't need to write any code. Go to **Settings > Agent-Ready > Your Endpoints**, fill in the empty row with a name and the URL, tick which documents it should appear in, and save. It's then listed in `/.well-known/api-catalog`, `/llms.txt`, and the Agent Skills index together.

The description field is what an agent reads to decide whether to use your endpoint, so say what it does and mention anything a caller must do first. Each saved endpoint tells you underneath exactly which documents it's currently appearing in — or why it isn't.

= Can a plugin register an endpoint in code instead? =

Yes. Plugin and theme authors can register one so it works on any site without the owner filling in a form:

`add_action( 'init', function() {`
`    if ( ! function_exists( 'mmsar_register_endpoint' ) ) { return; }`
`    mmsar_register_endpoint( array(`
`        'title'       => 'Contact form',`
`        'href'        => rest_url( 'my-plugin/v1/contact' ),`
`        'description' => 'Send the site owner a message.',`
`        'type'        => 'application/json',`
`        'methods'     => array( 'POST' ),`
`        'auth'        => 'none',`
`    ) );`
`} );`

Use the `mmsar_registered_endpoints` filter for the same thing without a direct call. Add `'surfaces' => array( 'llms_txt' )` to limit where it appears, and `'rel'` to set its api-catalog link relation. Endpoints that publish a SKILL.md of their own can pass `'skill_url'` to get their own entry in the Agent Skills index. Code-registered endpoints appear read-only under "Added by Plugins" on the settings page. Full documentation is in the plugin's README on GitHub.

== Changelog ==

= 1.40.0 - 2026-09-14 =

* New: an Open Knowledge Format (OKF v0.2) bundle at /okf/. Publishes this site's content as a browsable tree of typed Markdown "concept" files — one per published post/page, each with YAML front matter (type, title, description, resource, tags, modified) — plus a per-post-type index and a root index, so an agent can ingest the whole corpus in one pass instead of scraping page by page.
* Reuses the same Markdown already generated for the .md URLs; nothing is converted twice. Deliberately does not ship the spec's optional packaged .tar.gz archive or a references/ directory — the first adds real generation cost for a convenience nothing here requires, the second exists to mirror externally cited standards that ordinary post content doesn't have.
* New: /okf/log.md, a change log generated from each concept's own last-modified date (newest first, 200 most recent) — not a hand-maintained editorial log, since WordPress content has no such thing to draw from.
* Advertised from /llms.txt and the Agentic Resource Discovery catalog, the same way llms-full.txt is — not from a Link header on every page, to avoid saying it twice.
* New feature toggle: OKF bundle, on by default like the plugin's other document-only features.

= 1.39.0 - 2026-09-14 =

* New: a TDMRep reservation header. Sends `tdm-reservation` on every response — `1` if you reserve the right to object to text and data mining of your content, `0` if you don't — which is the machine-readable form the EU's Copyright in the Digital Single Market Directive (Article 4) requires for that reservation to actually count; without it, mining is permitted by default. It is a legal notice, not a technical block: it stops no request.
* The reservation value is not a new setting to configure. It is derived from the existing AI Train answer in Content Signals, because the two conventions answer the same underlying question for two different audiences, and stating one thing in one and the opposite in the other is a contradiction with no good resolution. Set AI Train and both now agree automatically.
* New: an optional Policy URL setting. Sent as a `tdm-policy` header alongside the reservation, and only alongside an actual reservation, so a would-be licensee has somewhere to ask rather than just a closed door.
* New feature toggle: TDM reservation (TDMRep), on by default like the plugin's other document-only features — it changes no existing response body, only adds headers.

= 1.38.0 - 2026-09-14 =

* New: SSI-Nutch is recognised in the Agent Log. SSI (ssi.inc) runs a broad web crawler built on Apache Nutch. Until now its requests were logged as ordinary page views, which had a cost beyond the label: an unrecognised crawler's page views are stored against the network rather than the full address, so the evidence needed to identify it was being discarded on every visit and cannot be recovered afterwards. Recognising the name is what stops that.
* Recognition here is not endorsement, and the log says so. SSI publishes no IP ranges and no reverse-DNS convention, so there is nothing to check a claim against: requests under this name reach Unverifiable — a claim was made and this plugin cannot settle it — and never Verified. That is the same standing CCBot has had since it was added.
* The name is guarded by a disclosure requirement, as LinkupBot is: SSI is a generic initialism and Nutch is an off-the-shelf crawler anyone can run, so the name alone is cheap to wear. A user-agent claiming it is only recognised when it also discloses ssi.inc; one that does not is logged as an unrecognised self-declared crawler and is never accused of anything.
* No robots.txt group was added for it. The crawler groups this plugin writes state a site's Content Signals to operators it has decided to address by name, which is a policy choice rather than a consequence of recognising a crawler in the log. The two lists are deliberately separate.

= 1.37.3 - 2026-09-14 =

* Identity checks can no longer run the request past PHP's time limit. A verification pass was bounded by a fixed 20-second budget tested only *after* each lookup returned — so the next lookup was always allowed to start, and a reverse-DNS call against an address that does not resolve blocks for seconds. A pass measured here took 32.8 seconds against that 20-second budget. Most shared hosts stop a request at 30 seconds, and being stopped is a fatal: the blank "critical error" page rather than a slow screen.
* The budget is now derived from the host's own limit instead of being a fixed number. It subtracts the time the request has already spent rendering the page, since it is the total that gets stopped and not the pass, then keeps half of what remains — leaving room for a lookup still in flight and for the response itself. A host with no limit, which includes WP-CLI, keeps the full budget. The budget is only ever reduced, never raised.
* A pass also stops while there is still room for another lookup as slow as the slowest one it has seen. The reserve is measured rather than assumed, because resolver latency belongs to the host and the addresses, not to this plugin. Where slow lookups recur it ends a pass roughly one lookup early; where every lookup is fast it reserves nothing and the whole budget is still used. It cannot anticipate a first, isolated stall — that case is what the budget cap above exists for.
* Whatever a pass does not finish stays pending and is picked up by the next one, exactly as before. No verdict is skipped, only deferred.

= 1.37.2 - 2026-09-13 =

* Fixed a regression in 1.37.1 that stopped LinkupBot verifying at all. 1.37.1 applied the LinkupBot disclosure requirement when identifying a stored log entry. That was right for entries holding a raw user-agent, and wrong for the ones holding the crawler's canonical name: a recognised request is stored as just LinkupBot, and asking whether that contains linkup.so answers no — so every entry from the crawler the guard was written for came out Unclaimed, and no address check ever ran. A value byte-identical to a canonical name is now accepted without the test, because it was written by the labelling step, which had already applied the guard and kept only its result.
* The exemption is byte-exact on purpose. Name comparison is otherwise case-insensitive, which reads LinkUpBot and LinkupBot as the same string — so a looser exemption would admit linkup.com's unrelated crawler under Linkup's name and judge it against Linkup's address, which is the accusation the guard exists to prevent. A spelling variant cannot have come from the labelling step, so it is treated as a raw user-agent and still has to disclose.
* 1.37.1 was never released; this is what it should have been.

= 1.37.1 - 2026-09-13 =

* Fixed: a newly recognised crawler's existing log entries stayed Unclaimed forever. Unclaimed means no recognised name was found in the user-agent — but the set of recognised names grows with the plugin, so every request a crawler made *before* it was added holds that verdict and nothing ever retried it. The Re-check control skipped unclaimed entirely. It now reopens those rows, on the same condition it already applied to unverifiable: only where the claimed name is one this release can actually check, which is what stops it meaning "re-check everything" — the overwhelming majority of unclaimed rows are browsers and unbranded tools that claim nothing.
* An entry whose address was reduced to a network is never judged as a forgery. Page views from user-agents this plugin does not recognise as crawlers are stored at network precision, so a crawler's requests from before it was recognised are on file without a full address. Asking whether that network sits inside an operator's published range gets the answer "no" — and "no" is reported as a forged identity. That would have accused a real crawler on evidence discarded at storage time, in bulk, the moment those rows were reopened. Such rows now read Unverifiable, which is what they are: something was claimed, and this entry cannot settle it.
* The LinkupBot disclosure requirement now applies to verification, not only to labelling. Identifying a *stored* entry works on the raw user-agent, where the guard added alongside LinkupBot support had never run — so linkup.com's unrelated job-listings crawler matched the name on a case-insensitive substring and would have been judged against linkup.so's address. It is left Unclaimed and never accused, which is what the guard was for.
* The Re-check control no longer offers entries whose verdict provably cannot change, so its count returns to zero instead of sitting on screen permanently.

= 1.37.0 - 2026-09-13 =
* Fixed: one caller no longer splits into two journeys. The Journeys view grouped visits by exact address, but this log deliberately records a caller at two precisions — a page view from a user-agent it does not recognise as a crawler is stored against the network, while that same caller's request for an agent file keeps its full address. Every unrecognised crawler's visit was therefore torn in half: its page views in one journey, its llms.txt, .md and MCP requests in another, each looking like a different caller. On live traffic that affected 7 of the agents in a 400-request sample, and it hid the most revealing journey on the site — a crawler doing full agent discovery interleaved with reading pages, shown as two unremarkable halves. Visits are now grouped by network and declared name, which reunites them.
* Where a visit spans both precisions the screen says so, and the expanded sequence shows which address made each request. Following an address from the list view now shows that caller's whole network for the same reason; callers within it are still told apart by the name they declare. Nothing about what is stored changed — the full address is still recorded, still shown, and is still what identity verification runs against.
* New: LinkupBot is recognised in the Agent Log. Linkup (linkup.so) runs a web-search API that AI applications query for grounding, so its crawler is in the same category as PerplexityBot and OAI-SearchBot: it reads your pages so they can be cited in answers, not to train a model. Until now its requests were logged as ordinary page views rather than agent traffic, which meant they were invisible in the log's default read.
* Its identity is verified against Linkup's published range file rather than by reverse DNS, because the address it crawls from has no PTR record. The file currently lists a single prefix, `35.198.113.100/32`, captured 2026-09-13 — so a LinkupBot claim from anywhere else now reads as forged rather than undecided.
* LinkupBot also gets its own group in `robots.txt`, alongside GPTBot, ClaudeBot and the rest: an explicit `Allow: /` and the site's Content-Signal line, which by default declares `search=yes, ai-input=yes, ai-train=no`. It is listed on the same rationale as PerplexityBot — a crawler that reads to answer questions rather than to train — so what the group does is state the site's terms to an operator that will quote it, and keep it off general rules that may be stricter than the owner intends. Change the declaration for every listed crawler in Settings > Agent-Ready.
* A second, unrelated crawler spells its name the same way: `LinkUpBot`, from linkup.com, a job-listings aggregator. Because name matching is case-insensitive it would have been recorded under Linkup's name and then judged against Linkup's addresses, which would have labelled a real crawler as forged. So a LinkupBot claim is only accepted when the user-agent also discloses `linkup.so` — the thing an impersonator cannot borrow without pointing at the operator it is impersonating. The job crawler is logged as an unrecognised self-declared crawler instead, and is never accused.

= 1.36.0 - 2026-09-10 =
* The existing list is untouched and is still what the screen opens on: same columns, same filters, same pagination, and every existing link to it still lands there. The two are tabs on one screen, filters carry across, and each address in the list is now a link into that address's journeys.
* New: a Journeys view on the Agent Log screen. The flat list answers "what did agents fetch"; this answers the question it cannot — what one caller did, in order. Requests are stitched back into visits: one address and one declared agent, with no gap longer than 30 minutes between consecutive requests. Each visit opens into the sequence of requests it was made of.
* The identity is the address *and* the declared name, not the address alone. A client that renames itself partway through is two different things asking, and merging them would invent a journey nobody made — so it appears twice, and the screen says so.
* Visits are built from a bounded window of the most recent matching requests rather than the whole table. The log has no upper size, so grouping across all of it would grow without limit on exactly the sites that log the most. The summary line says how many requests the window covered, and warns when the oldest visit shown may be missing its first requests because it began before the window did.
* Single-request visits are hidden by default — a caller that took one thing and left is a row, not a journey — with a button to include them. Where every visit is a single request, the screen says which filter took the sequence apart rather than showing an empty table.
* A visit is labelled with the most decisive verdict among its requests, so one confirmed identity is not hidden behind rows that happened to be checked later. Spoofed visits carry their attribution, as in the list view.
* The visit gap is filterable via `mmsar_agent_log_visit_gap`. The log table gains an index on address and time for this view; it is added on upgrade with no action needed.

= 1.35.0 - 2026-09-07 =
* New: the Agent Log now says who was behind a forged crawler identity. A scanner that sends six operator names from one address in the same second is one client wearing six masks, and until now the log could only say each of them was `failed`. A spoofed row keeps the name it claimed and gains an attribution, so it reads "GPTBot — spoofed by Ora".
* Two things feed it. A configured scanner signature matches a token the operator owns — its own domain in the user-agent, or a probe path it invented. Failing that, correlation: the same network, in the same burst, also presenting a self-declared bot name that no operator publishes a verification method for. A **verified** crawler is never used as an attributor, so this can never claim one real operator forged another.
* Add your own with the `mmsar_agent_log_scanner_signatures` filter. Ora ships as the one default, matched on `ora.ai` and its `/__ora-404-probe-` path rather than on the word "Ora", which appears inside ordinary words like "collaboration".
* Attribution is derived on read and never stored, and it only holds within a 30-minute burst on the same network. A datacenter address gets reassigned, and a stored mapping would go on accusing whoever holds it next.

= 1.34.1 - 2026-09-07 =
* Refreshed the bundled crawler IP ranges for OpenAI and Perplexity from each operator's own published file. OpenAI gained 4 prefixes and dropped 1 (254 to 257, unioned across gptbot.json, searchbot.json and chatgpt-user.json). Perplexity's data came back byte-identical to what was already bundled — its capture date was old, its contents were not. The reported capture date moves from 2025-02-07 to 2026-08-18, because that figure is the oldest of the four operators rather than a single date, and Perplexity was the one dragging it down.
* Why it matters: verification judges a crawler against these ranges, so a prefix an operator adds after the capture date makes a genuine crawler read as `failed`. The bundled data was ten months behind for OpenAI. Anthropic's and DuckDuckGo's lists were already current and are untouched.

= 1.34.0 - 2026-09-07 =
* New: retiring a URL now tells agents before it breaks. The OpenAPI document has always promised that a route being withdrawn would carry `Deprecation` and `Sunset` headers first — but nothing in the plugin could send either one, so the promise had no mechanism behind it. Add a surface to the `mmsar_deprecated_surfaces` filter and its responses carry both headers, in the formats the specifications actually require: `Deprecation` as an RFC 9745 structured-field Date (`@1790812800`) and `Sunset` as an RFC 8594 HTTP-date (`Fri, 01 Jan 2027 00:00:00 GMT`). A policy URL, if given, goes out as a `Link` with the registered `deprecation` and `sunset` relations.
* New: the OpenAPI document carries an `info.x-lifecycle` block — the versioning scheme, how deprecation is signalled and in which format, and a list of exactly what is currently scheduled for retirement. It is generated from the same schedule the headers use, so the description and the headers cannot drift apart.
* The retirement schedule is empty by default and adds no header to any response, which is why it has no feature toggle. A site that is retiring nothing says so, rather than describing a policy in the abstract.

= 1.33.2 - 2026-09-07 =
* Fixed: the OpenAPI document described the MCP endpoint's 400 as always being a JSON-RPC error with code -32700, and that was only half true. A body that is valid JSON of the wrong type does get -32700, but a body that is not parseable JSON at all never reaches the endpoint — WordPress's REST server rejects it first with `rest_invalid_json`, in the site's own error shape. An agent building a handler from the spec would have been ready for one of the two and surprised by the other. Both are now documented, and both carry a schema.
* New: the MCP endpoint's error responses have a typed `JsonRpcError` schema in the OpenAPI document, covering the JSON-RPC error object and what each code means. Previously the 400 and 429 were described in prose with no schema at all, so a client could not tell from the spec what the body would contain.
* New: the MCP App panel's Content-Security-Policy names `connect-src` explicitly. The panel fetches nothing and `default-src 'none'` already denied it, but a policy that stays silent about a directive cannot be told apart from one whose author forgot it. The directive names the site's own MCP endpoint origin, derived from `rest_url()` rather than assumed, so it stays correct on installs where the REST API answers from another host.

= 1.33.0 - 2026-09-07 =
* New: Amazonbot gets its own group in robots.txt, alongside GPTBot, ClaudeBot and the rest. Amazon's documentation says Amazonbot's crawl is eligible for AI model training, so it belongs with the crawlers whose group carries the Content-Signal line — crawling stays allowed, and the signal states what the content may be used for. Amazon's two narrower tokens, Amzn-SearchBot and Amzn-User, are documented as never crawling for training and are deliberately not listed.

= 1.32.0 - 2026-09-07 =
* Fixed: renaming a post left its Markdown address permanently broken. WordPress keeps a renamed post's old URL working by redirecting it, but that only ever ran for the HTML page — the matching `.md` URL returned 404 forever. It now redirects to the renamed page's `.md` URL, using the same record of old slugs WordPress itself uses, so the two cannot disagree about where a page went.
* Fixed: a renamed post's old URL redirected a browser and 404'd an agent. Anything that asked for Markdown or JSON was handed this plugin's "not found" response before WordPress got a chance to redirect it, so one address behaved two different ways depending on who asked. WordPress's redirects now go first; the recovery response is what happens when there is genuinely nothing to redirect to.
* Fixed: renaming a post refreshed the site-wide llms.txt and llms-full.txt but not the per-section indexes, so `/writing/llms.txt` and its siblings could go on advertising the retired `.md` address for up to a day. Every generated document is now refreshed together.
* For developers: `mmsar_md_redirect_post_id` filters where a retired `.md` URL is sent, for redirects this plugin cannot see on its own — a redirect plugin's rules, or a manual map. WordPress's own `old_slug_redirect_post_id` is applied too.

= 1.31.4 - 2026-09-07 =
* Fixed: agents using Node's built-in fetch were recorded as browsers, which hid them from the Agent Log's default view. Node sends one of the same `Sec-Fetch-*` headers a browser sends, and any one of those headers was enough to be called a browser. The log now looks for a document navigation — the shape a browser makes when it loads a page, and one a fetch tool cannot produce — so a tool that borrows a browser's headers no longer borrows its label.
* Changed: a client that announces itself as a bot, either by a name like "SomethingBot" or by the `+https://example.com/bot` link crawlers put in their user-agent, is now recorded as a declared crawler even when this plugin does not recognise the name. It is still shown under the name it gave and still identity-checked the same way, which for an unrecognised name means no claim to check.
* Existing entries keep the client type they were given. The headers were never stored, so nothing recorded before this version can be reclassified — entries from 1.26.0 to 1.30.1 under-count scripts and fetch tools, and over-count browsers.
* New: the Surface filters now explain themselves on hover. "Agent documents" lists the documents it actually covers, with counts, taken from your own log rather than from a fixed list — so it stays accurate as the plugin adds surfaces.
* New: a "What agents looked for and did not find" panel under the log, grouping every 404 by the address requested, with how many agents asked for it and when it was last seen. It opens automatically when you filter to Not found. Note that one 404 per agent and address is kept every five minutes, so the counts are a floor, not a total — read the ranking rather than the numbers.
* Changed: filters now apply as you tick them, instead of needing "Apply filters". Several ticks in a row are batched into one update. With JavaScript disabled the button remains and nothing changes.

= 1.30.1 - 2026-09-04 =
* Changed: the two dashboard widgets are now one. 1.29.1 added a second widget for the identity-check backlog, which duplicated the first one's forged-identity count, its "log is switched off" notice and its link to the full log. The single "Agent Log" widget shows the forged count, anything waiting on an identity check with the Verify and Re-check buttons, and the recent requests list.
* The widget keeps its existing id, so its position on your dashboard and its Screen Options state are unchanged.

= 1.29.1 - 2026-09-04 =
* New: an "Agent Identity Checks" dashboard widget. It shows how many logged requests are still waiting on an identity check, broken down by the crawler each one claimed to be, and carries the Verify and Re-check buttons so a backlog can be cleared without opening the log screen. It only counts on load — the checks themselves still run only when you press the button.
* The agent request log's description now says what switching it off actually does: new entries stop, and nothing already recorded is deleted. It also no longer claims that page views are never recorded, which stopped being true in 1.25.0 when the page-view setting was added.
* The Agent Log screen now says, when the log is off, how many entries are being kept and that only clearing the log removes them.

= 1.28.0 - 2026-09-04 =
* **New: a real filter bar above the log.** Checkbox groups for surface, client and identity, so views can be combined: agent documents *and* Markdown, read by crawlers *and* browsers. It is a plain GET form, so every view is a URL that can be bookmarked or sent to someone.
* **New: export what you are looking at.** With filters active the export offers both "Export this view" and "Export everything", each labelled with its row count. The filtered export walks the same id cursor, so it stays correct while the log is being written to.
* **Changed: clearing the log now takes real intent.** It had no confirmation at all: one click on a button sitting inches from Export destroyed every entry. It has moved to the bottom of the screen, below the data, and requires typing DELETE before the button enables. The typed word is checked on the server too, so the disabled button is a convenience rather than the guard.
* **Changed: the crawler identity panel is readable.** The verdict counts are a row of tiles instead of a run-on line, and the explanation is one idea per bullet instead of five consecutive paragraphs.
* **Fixed:** a call to a method removed during this release's refactor would have made every `get-agent-log` request fatal. Caught by static analysis before shipping.

= 1.27.0 - 2026-09-04 =
* **New: filter the log by what was asked for, not just who asked.** A Surface filter on the Agent Log screen and a `surface` input on the `get-agent-log` ability, splitting requests into agent documents, Markdown, HTML pages and not-found. Combined with the client filter this answers the question the log exists for: did a real crawler read the agent-facing documents, or only the HTML.
* **New:** the ability returns `surface_categories` counts alongside `client_types`, so the split is visible without paging through entries.
* **Changed: page views now record the URL as the visitor requested it**, query string included, instead of only the page it resolved to. The resolved page is still used for throttling, which is what stops a caller writing unlimited entries by varying a query string; storing and throttling were never the same job. Internal search terms are therefore recorded as typed.
* **Fixed:** the page-view setting ran each option's explanation straight on from its label with no separator, so it read as one sentence and changed font midway. Explanations now sit under their option.
* **Changed:** the page-view help text no longer pushes a retention limit. Keeping everything is reasonable when the log is being used to answer a question about agent behaviour over time.

= 1.26.0 - 2026-09-04 =
* **New: every entry records what kind of client made the request.** A declared crawler, a real browser engine, or a script or fetch tool. The distinction comes from headers a browser cannot avoid sending, chiefly the `Sec-Fetch-*` set, which no fetch tool sends. An agent using a fetch tool is therefore recorded as a script even when it borrows a browser's user-agent string, which is the case that matters.
* **New:** a Client column and filter on the Agent Log screen, a `client` filter and `client_types` counts on the `get-agent-log` ability, and a `client_type` column on the CSV export.
* **Browser page views are hidden by default.** This is an agent log, and once every page view is recorded a screen listing them all shows mostly people. Those entries are still recorded, because they are the denominator every share is computed against; they are one click away under Browsers or Everything.
* **What this does not do:** tell people from machines. An agent driving a real headless Chrome sends exactly what a reader does and is indistinguishable here. It separates browser engines from HTTP clients, which is a different and more answerable question.
* **Dev:** log schema bumped to version 4. Entries recorded before this release cannot be classified retroactively, because the headers were never stored, and show as not recorded.

= 1.25.0 - 2026-09-04 =
* **New: the agent log can record every page view, not only those from recognized crawlers.** The page-view setting is now a three-way choice: off, recognized AI agents only (what the old checkbox did), or every page view including human traffic. The middle option quietly skewed every percentage taken from this log — an unrecognized client's requests for agent-facing files were recorded while its ordinary page views were not, so anything unbranded appeared to read nothing else. On one twelve-day sample that made agent-surface activity look 3.6 times higher than it was.
* **New:** page views record which page was requested, so "read as HTML" and "pulled as Markdown" are directly comparable for the same article.
* **Privacy:** page views from user-agents that are not recognized crawlers store the caller's network rather than its full address — 203.0.113.4 becomes 203.0.113.0, and IPv6 keeps its first four groups. Recognized crawlers keep their full address, which is what identity verification needs. The five-minute throttle still works off the real address, which never reaches the database.
* The recorded page is always resolved from the request, never taken from the URL as typed: a search is stored as "(search)" and never the search term, a query string is discarded, and a 404 writes no page-view entry because the 404 surfaces already record it.
* **Before switching this on for a busy site, set a retention limit on the Agent Log screen.** It records one entry per visitor, per page, per five minutes, and the default keeps everything.

= 1.24.4 - 2026-09-03 =
* **Fix: No DNS entries are retried automatically, so the re-check button stops asking.** *No DNS* was documented as the retryable verdict, but nothing actually retried it — the only way to reopen one was the button, which meant an address with no reverse record left a "Re-check 1" button on screen permanently, doing nothing each time it was pressed. The ordinary verification pass now picks up any *No DNS* entry older than a day, so a resolver problem repairs itself quietly and a genuinely unresolvable address stops asking for attention.
* **Change:** the re-check button is now only about entries that became answerable because an update taught the plugin a new operator — the one case where a person is actually needed, since the plugin cannot detect that about itself. When there is nothing of the kind, the button is not shown at all.
* `Verified`, `Spoofed` and `Unverifiable` verdicts remain untouchable by an automatic pass; only a never-checked entry or a day-old *No DNS* one can be written.

= 1.24.3 - 2026-09-03 =
* **Fix: the re-check button no longer offers work it cannot do.** It counted every undecided entry, but most of them are *Unverifiable* because nobody publishes a way to confirm that crawler at all — re-checking those produces the same answer every time. The button now counts only entries whose verdict could actually come out differently: anything that failed to resolve, and anything whose operator this plugin has since learned. On a log full of uncheckable crawlers the button correctly disappears.
* **New:** the panel names the crawlers it cannot check and says why, so a count that never moves reads as an answer rather than as something stuck.
* **Fix:** the re-check result now reports what actually changed. It said how many entries it had reopened, which looked like progress even when every one reached the same verdict; it now says either how many verdicts changed or that none did.

= 1.24.2 - 2026-09-03 =
* **New: a "Re-check undecided" button on the Agent Log screen.** A verdict of *Unverifiable* or *No DNS* records that the plugin had no way to check an identity — not that the caller was suspicious — so those entries become answerable the moment an update teaches it a new operator. That happened immediately: 1.24.1 taught it DuckAssistBot, and the entries already in the log kept saying *No DNS*. This button reopens them and judges them again. *Verified* and *Spoofed* entries are deliberately left alone, so a re-check can never overwrite a conclusion already reached.
* **Fix:** re-checking also clears the cached verdict for the addresses involved. Without that, an *Unverifiable* result cached against an address for a week would have been handed straight back and the re-check would have appeared to do nothing.

= 1.24.1 - 2026-09-03 =
* **Fix: DuckAssistBot is verified instead of unresolvable.** 1.24.0 checked it by reverse DNS, and live data showed why that was wrong: all 13 of its requests came from Azure addresses with no reverse record at all, so every one was recorded as "no DNS" rather than confirmed. DuckDuckGo publishes an IP range file instead, which covers all 13 — those requests now read as verified. The `duckduckgo.com` hostname suffix has been removed, so a DuckAssistBot claim from outside the published range is now identified as forged rather than left undecided, and costs no DNS lookup either way.
* **Dev:** the bundled range data gains a fourth operator group (DuckDuckGo, 486 prefixes, captured 2026-09-01). Existing verdicts are not rewritten in place — entries keep the verdict they were given, and re-checking is a matter of clearing the log or waiting for new traffic.

= 1.24.0 - 2026-09-03 =
* **New: the agent log verifies who callers actually are.** The `agent` column has always been a self-declared user-agent string, and on a real site it is routinely forged — three addresses in one nine-day sample each rotated through five or more AI-crawler identities, and a readiness scanner accounted for most traffic attributed to GPTBot. Each entry now carries a verdict: `verified`, `failed` (the identity was forged), `unverifiable` (no published way to check that operator — not an accusation), `unclaimed` (no crawler was named), or `nodns`.
* **New: two verification methods, chosen per operator.** Anthropic, OpenAI and Perplexity publish no reverse-DNS records for their crawlers, only IP range files, so those are checked against ranges bundled with the plugin. Google, Apple, Amazon, Microsoft and DuckDuckGo are checked by forward-confirmed reverse DNS — the address reverses to a hostname under a domain that operator owns, and that hostname resolves back to the same address. A user-agent is trivial to forge; neither of these is.
* **New: markdown surfaces record which page was fetched.** `.md` URLs, content negotiation and `SKILL.md` now store the permalink path of the post served, so `by_detail` distinguishes a crawler sweeping the whole corpus from one that wanted a specific article. Every alias for a post — the `.md` suffix, the negotiated canonical URL, a trailing slash — records the same value, so they aggregate together instead of splitting.
* **New:** an *Identity* column with badges and a verdict filter on the Agent Log screen, a "Verify now" button for clearing a backlog, `verified` and `verified_at` columns appended to the CSV export, a forged-count headline on the dashboard widget, and a `verification` block, `verified` input filter and per-agent verdict counts on the `get-agent-log` ability.
* **Fix:** a request for a `.md` URL that does not exist was not recorded at all. It took an earlier exit than the other two 404 paths, so the 404 an agent is most likely to produce against this plugin was the one the log could not show. It is now recorded with its path.
* **Fix:** stored paths no longer flatten non-ASCII characters. Accented and non-Latin slugs were reduced to the same value, which could merge two different posts into one `by_detail` row. Control characters are still stripped, which was the actual reason for the original filter.
* **Dev:** log schema bumped to version 3. The two new columns are added by `dbDelta` on the next page load after updating; existing entries are kept and start unverified. Verification never runs while a page is being served to a visitor — it runs in a small bounded batch when an administrator opens the Agent Log screen or calls the ability, and in a larger batch from the button. No cron, no third-party requests.
* **Note:** because the markdown surfaces now record a path, a crawler sweeping forty markdown files writes forty entries where it previously wrote one. That is the point of the change, but the log grows faster than before, so the retention limit is worth a look on content-heavy sites.

= 1.23.0 - 2026-08-31 =
* New: The agent log now records **what** was asked for, not just which surface. A new detail column carries the requested path on a 404 and the invoked method on an MCP call. Shown on the Agent Log screen, included in the CSV export, and returned by the `get-agent-log` ability both per-entry and as a new `by_detail` aggregate.
* New: **The MCP endpoint is logged.** Every JSON-RPC message is recorded with its method — `initialize`, `tools/list`, `tools/call: <tool name>` — along with declined GET stream requests, unparseable bodies and rate-limited callers. Previously only the `mcp.json` and `server-card.json` discovery documents were logged, so there was no way to tell whether a client that found the MCP server ever actually called it.
* New: **404s record the path.** A count of agent 404s said only that agents were asking for something absent; the path shows a crawler guessing at a URL pattern the site could support, which was previously invisible.
* Dev: Log schema bumped to version 2. The new column is added by `dbDelta` on the next page load after updating — existing entries are kept and simply carry an empty detail.

Older releases are listed in CHANGELOG.md in the plugin's GitHub repository:
https://github.com/miriamschwab/make-my-site-agent-ready/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.3.0 =
Plugin renamed to Make My Site Agent-Ready. Deactivate the old plugin and activate the new one. Existing settings are preserved automatically.
