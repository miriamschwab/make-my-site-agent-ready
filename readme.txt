=== Make My Site Agent-Ready ===
Contributors: illuminea
Tags: markdown, llm, ai, llms-txt, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.33.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes your WordPress site ready for AI agents: markdown URLs, llms.txt, security.txt, api-catalog, Agent Skills, and AI crawler rules.

== Description ==

Make My Site Agent-Ready makes your WordPress content accessible to AI language models and AI agents. Every post and page gets a markdown endpoint automatically, a site index is generated for discovery, and the full site content is available in one request for LLMs that want it.

Every feature below can be switched off individually under Settings > Agent-Ready, so the plugin stays out of the way of anything you already manage elsewhere. Everything is on by default except structured data.

**Features:**

* **Individual feature toggles** — Turn off any output the plugin publishes (markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog, Agent Skills). A disabled feature registers nothing at all — no rewrite rule, no filter, no header — so the site behaves as if that part of the plugin did not exist.
* **`.md` URLs** — Append `.md` to any post or page URL to get a clean markdown version
* **Markdown from the normal page URL** — Optional (off by default). Answers a request for an ordinary page with its markdown when the request's `Accept` header asks for markdown, which is how AI clients ask. Comes with a self-check that requests one of your own pages as an agent and then as a browser, reports which version came back and what cache headers survived, and switches the feature off by itself if a browser-style request is ever answered with markdown. Leave it off if your site is behind a CDN that ignores `Vary: Accept` — Cloudflare does
* **llms.txt** — Auto-generated site index at `/llms.txt` listing all available markdown content
* **llms-full.txt** — Full site content in one file at `/llms-full.txt` for LLMs that want everything
* **security.txt** — Serves `/.well-known/security.txt` (RFC 9116). Enter your security contact as a full URL, a path like `/contact`, or an email address, and the plugin formats it correctly
* **api-catalog** — Serves `/.well-known/api-catalog` (RFC 9727), a machine-readable index linking llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap, and feed
* **Agent Skills discovery** — Serves `/.well-known/agent-skills/index.json` plus a bundled skill teaching agents how to use this plugin's markdown endpoints
* **Link response headers** — Every front-end response carries `Link` headers (RFC 8288) pointing to api-catalog, llms.txt, and the Agent Skills index; singular posts/pages add one more pointing to their markdown alternate — so agents that only read headers, not HTML, can still find these resources
* **Content Signals** — Declares `Content-Signal: search=..., ai-input=..., ai-train=...` (contentsignals.org) under each AI crawler's group in `robots.txt`, configurable in Settings > Agent-Ready. Defaults to allowing search and live AI retrieval, declining AI training use.
* **Structured data (JSON-LD)** — Optional (off by default) pointer to the markdown alternate on each enabled post/page. When Yoast SEO is active and produces schema for the page, the pointer merges directly into Yoast's own `Article`/`WebPage` piece — no duplicate block. Otherwise, a standalone `Article`/`WebPage` JSON-LD block is added instead. Enable in Settings > Agent-Ready.
* **AI crawler rules** — Adds explicit `Allow: /` entries for GPTBot, ClaudeBot, and other AI crawlers in `robots.txt`
* **llms.txt discovery in robots.txt** — Adds an `Llms-txt:` directive pointing at your `/llms.txt`, so agents that fetch `robots.txt` first are told where the index is. Skipped if llms.txt is switched off, or if `robots.txt` already mentions it
* **Endpoints stay reachable** — If `robots.txt` disallows a path one of your published endpoints lives on (several SEO plugins disallow `/wp-json/` by default), an `Allow:` line for that individual endpoint is added above the rule blocking it. The endpoint stays reachable to agents that found it in your api-catalog, llms.txt or Agent Skills index; the rest of the REST API stays disallowed
* **YAML frontmatter** — Title, date, author, URL, excerpt, categories, and tags
* **Pre-generated** — Markdown is generated when posts are saved, so `.md` requests are instant
* **Discoverable** — Adds `<link rel="alternate" type="text/markdown">` to page headers
* **Lightweight** — No cron jobs, no frontend JavaScript. The optional agent request log is the only feature that adds a database table, and only once you switch it on

**How it works:**

1. When you save a post, the plugin converts it to markdown and stores it in post meta
2. When someone requests `your-post.md`, the pre-generated markdown is served instantly
3. The `/llms.txt` file lists all available markdown URLs organized by category
4. The `/llms-full.txt` file concatenates the full content of all posts and pages

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
