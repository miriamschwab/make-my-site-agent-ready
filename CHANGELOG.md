# Changelog

All notable changes to Make My Site Agent-Ready.

## 1.32.0 — 2026-09-07

- **Fixed: renaming a post left its Markdown address broken forever.** WordPress keeps a renamed post's old URL alive — it records the old slug in `_wp_old_slug` and `wp_old_slug_redirect()` turns the old address into a 301 — but that handler only runs on a WordPress 404, and a `.md` request is not one: it matches this plugin's own rewrite rule, so `is_404()` is false and core never looks. The `.md` mirror was therefore the one spelling of a renamed page that stayed permanently dead while every other spelling quietly kept working. Found in a live log, where Amazonbot fetched a post's pre-rename `.md` URL and got a 404 that the same URL without the suffix would have redirected.
- **The mirror now reads the same record core does** and 301s to the renamed post's `.md` URL, applying core's own `old_slug_redirect_post_id` filter on the way — so a site that has already taught WordPress where its renamed posts went does not have to teach this plugin separately, and the two surfaces cannot disagree about where a page went.
- **Fixed: a renamed post's old URL redirected a browser and 404'd an agent.** `MMSAR_Not_Found` ran at `template_redirect` priority 2, ahead of core's `wp_old_slug_redirect()` and `redirect_canonical()` at 10 — so for any client that asked for Markdown or JSON, this plugin declared the request unrecoverable while WordPress was still holding the recovery. One address, two behaviours, split by `Accept`. The handler now runs at 11: core's redirects go first, and the recovery response is what happens when there is genuinely nothing to redirect to.
- **Fixed: only some of the generated documents were refreshed when content changed.** Saving a post cleared `llms.txt` and `llms-full.txt` but not the per-section indexes, which cache for a day and list the same posts under the same `.md` addresses — so a rename refreshed one index immediately and left `/writing/llms.txt` and its siblings advertising the retired address for up to 24 hours. Every content hook now calls `mmsar_flush_generated_documents()`, which is the single place that knows the full set, rather than re-listing part of it.
- **Redirects are logged as `Markdown (redirect)`**, under the Markdown surface rather than Not found, with the *requested* path as the detail — so the log shows which retired addresses agents are still holding, which is what says whether a stale link is worth chasing.
- **For developers:** `mmsar_md_redirect_post_id` filters where a retired `.md` URL is sent, for redirects this plugin cannot see by itself — a redirect plugin's own rules, a manual map, an import that changed every address at once. Deliberately *not* implemented by asking the site over HTTP where `/old-path/` ends up: that would catch more, at the cost of a loopback request on the exact code path a crawler guessing at addresses hits hardest.

## 1.31.4 — 2026-09-07

- **Fixed: an agent using Node's built-in fetch was recorded as a browser**, and browser rows are excluded from the Agent Log's default view — so the log was hiding exactly the traffic it exists to surface. The check treated *any* `Sec-Fetch-*` header as a browser signature, and Node's fetch (undici) sends `Sec-Fetch-Mode: cors`. Found in a live log where ten Markdown fetches from `OraBot/1.0 (+https://ora.ai/bot)` and from a bare `node` user-agent, seconds apart from one address, all sat under **Browser**.
- **The test is now a document navigation** — `Sec-Fetch-Mode: navigate` or `Sec-Fetch-Dest: document`, or Chromium's `Sec-CH-UA` — rather than the presence of any one header. A page load produces that shape and the Fetch API cannot ask for it: `fetch()` rejects `mode: 'navigate'` outright. `Sec-Fetch-Site` and `Sec-Fetch-User` are no longer consulted, because neither separates the two populations.
- **New: a client that announces itself as a bot is recorded as a declared crawler**, even when the name is not one this plugin knows — a `bot`, `crawler`, `spider` or `scraper` token, or the `+https://example.com/bot` self-identification link. It is a claim rather than a signal, so it is tested only after the browser shapes, and it changes nothing about the identity check: an unrecognised name still reports as *unclaimed*, because there is still no claim that can be checked.
- **Not retroactive, and it cannot be.** The headers were never stored, so every existing entry keeps the client type it was given. Entries recorded between 1.26.0 and 1.30.1 under-count scripts and fetch tools and over-count browsers; anything comparing those shares across this version boundary is comparing two different classifications.
- **New: the Surface filters say what they cover.** Hovering a Surface option now explains it, and **Agent documents** — the one whose name cannot describe it, because it is defined as everything that is not a page view, a markdown response or a 404 — lists the actual documents it covers with their request counts, read out of your own log. A surface added in a later version appears there on its own, so the list cannot go stale.
- **New: "What agents looked for and did not find".** A panel under the log groups every 404 by the address that was asked for, with how many agents asked and when it was last seen, so a crawler working through a URL pattern the site could support is visible as a pattern instead of as scattered rows. It opens by default when you filter to *Not found*.
- **Those totals are a floor, and the panel says so.** One 404 per agent and address is recorded every five minutes, so a client walking a URL list is counted far fewer times than it called. The order is the signal; the counts understate persistent guessing.
- **Changed: filters apply as you tick them.** No more pressing *Apply filters*. Ticks are batched behind a short pause so combining three or four does not reload between each one. With JavaScript off the button is still there and the screen works exactly as before — every view is still a plain URL you can bookmark or send to someone.

## 1.30.1 — 2026-09-04

- **Changed: the two dashboard widgets are now one.** 1.29.1 added a second widget for the identity-check backlog, and it overlapped the existing one on three things — the forged-identity count, the "log is switched off" notice, and the link through to the full log — so a dashboard with both showed the same facts twice. The single **Agent Log** widget carries the forged count, anything waiting on an identity check with the *Verify now* and *Re-check* buttons, and the list of recent requests, in that order.
- **The surviving widget keeps the `mmsar_agent_log` id it has always had**, so an existing install keeps its dashboard position and Screen Options state. Only the title changed, from "Recent Agent Requests" to "Agent Log", because the widget now does more than list recent requests.
- Unchanged and deliberate: the widget still never runs an identity check on load. It counts only, and its buttons post to the same capability-gated handlers the Agent Log screen uses.

## 1.29.1 — 2026-09-04

- **New: an "Agent Identity Checks" dashboard widget.** It shows how many logged requests are still waiting on an identity check, broken down by the crawler each one claimed to be, and carries the *Verify now* and *Re-check* buttons so a backlog can be cleared from the dashboard instead of from the log screen. Pressing a button returns you to the dashboard with the result, rather than dropping you on a different page.
- **The widget never verifies anything on load.** Identity checks make DNS lookups that can block for seconds, and the dashboard is the first screen an administrator opens. It reads counts only; the checks still run exclusively when a button is pressed, on the same capability-gated handler the log screen uses.
- **Changed: the agent request log's toggle now says what switching it off does.** New entries stop being recorded and nothing already recorded is deleted — the log stays readable and exportable until it is cleared deliberately. That was already how it behaved; nothing said so.
- **Fixed: the same toggle claimed that page views are never recorded.** That stopped being true in 1.25.0, when the page-view setting was added, and the description was never updated.
- **Changed:** with the log switched off, the Agent Log screen now names how many entries are being kept and that only clearing the log removes them.

## 1.28.0 — 2026-09-04

- **New: a real filter bar above the log.** Checkbox groups for surface, client and identity, so views can be combined: agent documents *and* Markdown, read by crawlers *and* browsers. It is a plain GET form, so every view is a URL that can be bookmarked or sent to someone.
- **New: export what you are looking at.** With filters active the export offers both "Export this view" and "Export everything", each labelled with its row count. The filtered export walks the same id cursor, so it stays correct while the log is being written to.
- **Changed: clearing the log now takes real intent.** It had no confirmation at all: one click on a button sitting inches from Export destroyed every entry. It has moved to the bottom of the screen, below the data, and requires typing DELETE before the button enables. The typed word is checked on the server too, so the disabled button is a convenience rather than the guard.
- **Changed: the crawler identity panel is readable.** The verdict counts are a row of tiles instead of a run-on line, and the explanation is one idea per bullet instead of five consecutive paragraphs.
- **Fixed:** a call to a method removed during this release's refactor would have made every `get-agent-log` request fatal. Caught by static analysis before shipping.

## 1.27.0 — 2026-09-04

- **New: filter the log by what was asked for, not just who asked.** A Surface filter on the Agent Log screen and a `surface` input on the `get-agent-log` ability, splitting requests into agent documents, Markdown, HTML pages and not-found. Combined with the client filter this answers the question the log exists for: did a real crawler read the agent-facing documents, or only the HTML.
- **New:** the ability returns `surface_categories` counts alongside `client_types`, so the split is visible without paging through entries.
- **Changed: page views now record the URL as the visitor requested it**, query string included, instead of only the page it resolved to. The resolved page is still used for throttling, which is what stops a caller writing unlimited entries by varying a query string; storing and throttling were never the same job. Internal search terms are therefore recorded as typed.
- **Fixed:** the page-view setting ran each option's explanation straight on from its label with no separator, so it read as one sentence and changed font midway. Explanations now sit under their option.
- **Changed:** the page-view help text no longer pushes a retention limit. Keeping everything is reasonable when the log is being used to answer a question about agent behaviour over time.

## 1.26.0 — 2026-09-04

- **New: every entry records what kind of client made the request.** A declared crawler, a real browser engine, or a script or fetch tool. The distinction comes from headers a browser cannot avoid sending, chiefly the `Sec-Fetch-*` set, which no fetch tool sends. An agent using a fetch tool is therefore recorded as a script even when it borrows a browser's user-agent string, which is the case that matters.
- **New:** a Client column and filter on the Agent Log screen, a `client` filter and `client_types` counts on the `get-agent-log` ability, and a `client_type` column on the CSV export.
- **Browser page views are hidden by default.** This is an agent log, and once every page view is recorded a screen listing them all shows mostly people. Those entries are still recorded, because they are the denominator every share is computed against; they are one click away under Browsers or Everything.
- **What this does not do:** tell people from machines. An agent driving a real headless Chrome sends exactly what a reader does and is indistinguishable here. It separates browser engines from HTTP clients, which is a different and more answerable question.
- **Dev:** log schema bumped to version 4. Entries recorded before this release cannot be classified retroactively, because the headers were never stored, and show as not recorded.

## 1.25.0 — 2026-09-04

- **New: the agent log can record every page view, not only those from recognized crawlers.** The page-view setting is now a three-way choice: off, recognized AI agents only (what the old checkbox did), or every page view including human traffic. The middle option quietly skewed every percentage taken from this log — an unrecognized client's requests for agent-facing files were recorded while its ordinary page views were not, so anything unbranded appeared to read nothing else. On one twelve-day sample that made agent-surface activity look 3.6 times higher than it was.
- **New:** page views record which page was requested, so "read as HTML" and "pulled as Markdown" are directly comparable for the same article.
- **Privacy:** page views from user-agents that are not recognized crawlers store the caller's network rather than its full address — 203.0.113.4 becomes 203.0.113.0, and IPv6 keeps its first four groups. Recognized crawlers keep their full address, which is what identity verification needs. The five-minute throttle still works off the real address, which never reaches the database.
- The recorded page is always resolved from the request, never taken from the URL as typed: a search is stored as "(search)" and never the search term, a query string is discarded, and a 404 writes no page-view entry because the 404 surfaces already record it.
- **Before switching this on for a busy site, set a retention limit on the Agent Log screen.** It records one entry per visitor, per page, per five minutes, and the default keeps everything.

## 1.24.4 — 2026-09-03

- **Fix: No DNS entries are retried automatically, so the re-check button stops asking.** *No DNS* was documented as the retryable verdict, but nothing actually retried it — the only way to reopen one was the button, which meant an address with no reverse record left a "Re-check 1" button on screen permanently, doing nothing each time it was pressed. The ordinary verification pass now picks up any *No DNS* entry older than a day, so a resolver problem repairs itself quietly and a genuinely unresolvable address stops asking for attention.
- **Change:** the re-check button is now only about entries that became answerable because an update taught the plugin a new operator — the one case where a person is actually needed, since the plugin cannot detect that about itself. When there is nothing of the kind, the button is not shown at all.
- `Verified`, `Spoofed` and `Unverifiable` verdicts remain untouchable by an automatic pass; only a never-checked entry or a day-old *No DNS* one can be written.

## 1.24.3 — 2026-09-03

- **Fix: the re-check button no longer offers work it cannot do.** It counted every undecided entry, but most of them are *Unverifiable* because nobody publishes a way to confirm that crawler at all — re-checking those produces the same answer every time. The button now counts only entries whose verdict could actually come out differently: anything that failed to resolve, and anything whose operator this plugin has since learned. On a log full of uncheckable crawlers the button correctly disappears.
- **New:** the panel names the crawlers it cannot check and says why, so a count that never moves reads as an answer rather than as something stuck.
- **Fix:** the re-check result now reports what actually changed. It said how many entries it had reopened, which looked like progress even when every one reached the same verdict; it now says either how many verdicts changed or that none did.

## 1.24.2 — 2026-09-03

- **New: a "Re-check undecided" button on the Agent Log screen.** A verdict of *Unverifiable* or *No DNS* records that the plugin had no way to check an identity — not that the caller was suspicious — so those entries become answerable the moment an update teaches it a new operator. That happened immediately: 1.24.1 taught it DuckAssistBot, and the entries already in the log kept saying *No DNS*. This button reopens them and judges them again. *Verified* and *Spoofed* entries are deliberately left alone, so a re-check can never overwrite a conclusion already reached.
- **Fix:** re-checking also clears the cached verdict for the addresses involved. Without that, an *Unverifiable* result cached against an address for a week would have been handed straight back and the re-check would have appeared to do nothing.

## 1.24.1 — 2026-09-03

- **Fix: DuckAssistBot is verified instead of unresolvable.** 1.24.0 checked it by reverse DNS, and live data showed why that was wrong: all 13 of its requests came from Azure addresses with no reverse record at all, so every one was recorded as "no DNS" rather than confirmed. DuckDuckGo publishes an IP range file instead, which covers all 13 — those requests now read as verified. The `duckduckgo.com` hostname suffix has been removed, so a DuckAssistBot claim from outside the published range is now identified as forged rather than left undecided, and costs no DNS lookup either way.
- **Dev:** the bundled range data gains a fourth operator group (DuckDuckGo, 486 prefixes, captured 2026-09-01). Existing verdicts are not rewritten in place — entries keep the verdict they were given, and re-checking is a matter of clearing the log or waiting for new traffic.

## 1.24.0 — 2026-09-03

- **New: the agent log verifies who callers actually are.** The `agent` column has always been a self-declared user-agent string, and on a real site it is routinely forged — three addresses in one nine-day sample each rotated through five or more AI-crawler identities, and a readiness scanner accounted for most traffic attributed to GPTBot. Each entry now carries a verdict: `verified`, `failed` (the identity was forged), `unverifiable` (no published way to check that operator — not an accusation), `unclaimed` (no crawler was named), or `nodns`.
- **New: two verification methods, chosen per operator.** Anthropic, OpenAI and Perplexity publish no reverse-DNS records for their crawlers, only IP range files, so those are checked against ranges bundled with the plugin. Google, Apple, Amazon, Microsoft and DuckDuckGo are checked by forward-confirmed reverse DNS — the address reverses to a hostname under a domain that operator owns, and that hostname resolves back to the same address. A user-agent is trivial to forge; neither of these is.
- **New: markdown surfaces record which page was fetched.** `.md` URLs, content negotiation and `SKILL.md` now store the permalink path of the post served, so `by_detail` distinguishes a crawler sweeping the whole corpus from one that wanted a specific article. Every alias for a post — the `.md` suffix, the negotiated canonical URL, a trailing slash — records the same value, so they aggregate together instead of splitting.
- **New:** an *Identity* column with badges and a verdict filter on the Agent Log screen, a "Verify now" button for clearing a backlog, `verified` and `verified_at` columns appended to the CSV export, a forged-count headline on the dashboard widget, and a `verification` block, `verified` input filter and per-agent verdict counts on the `get-agent-log` ability.
- **Fix:** a request for a `.md` URL that does not exist was not recorded at all. It took an earlier exit than the other two 404 paths, so the 404 an agent is most likely to produce against this plugin was the one the log could not show. It is now recorded with its path.
- **Fix:** stored paths no longer flatten non-ASCII characters. Accented and non-Latin slugs were reduced to the same value, which could merge two different posts into one `by_detail` row. Control characters are still stripped, which was the actual reason for the original filter.
- **Dev:** log schema bumped to version 3. The two new columns are added by `dbDelta` on the next page load after updating; existing entries are kept and start unverified. Verification never runs while a page is being served to a visitor — it runs in a small bounded batch when an administrator opens the Agent Log screen or calls the ability, and in a larger batch from the button. No cron, no third-party requests.
- **Note:** because the markdown surfaces now record a path, a crawler sweeping forty markdown files writes forty entries where it previously wrote one. That is the point of the change, but the log grows faster than before, so the retention limit is worth a look on content-heavy sites.

## 1.23.0 — 2026-08-31

- **New:** The agent log now records *what* was asked for, not just which surface. A new detail column carries the requested path on a 404 and the invoked method on an MCP call. Shown on the Agent Log screen, included in the CSV export, and returned by the `get-agent-log` ability both per-entry and as a new `by_detail` aggregate.
- **New:** **The MCP endpoint is logged.** Every JSON-RPC message is recorded with its method — `initialize`, `tools/list`, `tools/call: <tool name>` — along with declined GET stream requests, unparseable bodies and rate-limited callers. Previously only the `mcp.json` and `server-card.json` discovery documents were logged, so there was no way to tell whether a client that found the MCP server ever actually called it.
- **New:** **404s record the path.** A count of agent 404s said only that agents were asking for something absent; the path shows a crawler guessing at a URL pattern the site could support, which was previously invisible.
- **Dev:** Log schema bumped to version 2. The new column is added by `dbDelta` on the next page load after updating — existing entries are kept and simply carry an empty detail.

## 1.22.3 — 2026-08-31

- Fix: Removed an unused dependency. `composer.json` required `yahnis-elsts/plugin-update-checker`, which the plugin never loaded and which was not present in `vendor/` — a leftover from an abandoned self-update experiment. Nothing in the shipped code referenced it.
- Fix: The bundled Composer metadata identified the plugin by a stale package name inherited from the project it was originally derived from. Regenerated, so `vendor/composer/installed.php` now names this plugin.
- Dev: Added `composer.lock`, so the bundled `vendor/` tree is reproducible. `league/html-to-markdown` stays at 5.1.1 — the Markdown converter is byte-for-byte unchanged.

## 1.22.2 — 2026-08-30

- Added a written justification to a `phpcs:ignore` in `uninstall.php`. No behaviour change.

## 1.22.1 — 2026-08-29

WordPress.org Plugin Check compliance. No behaviour changes — every output is byte-identical to 1.22.0.

- Removed the `Domain Path` header and the `load_plugin_textdomain()` call, which both pointed at an empty directory that has never shipped. WordPress has loaded plugin translations automatically since 4.6.
- Added a `function_exists()` guard around `wp_register_ability_category()`.
- Replaced two heredoc blocks with string arrays; Plugin Check disallows heredoc syntax.
- Added a `composer.json` describing the two bundled vendor packages.
- `readme.txt` now keeps only recent releases and points here for the rest — WordPress.org truncates that section at 5,000 characters.
- `Tested up to: 7.1`.

## 1.22.0 — 2026-08-29

- Scoped llms.txt indexes are now advertised properly. Each page points at the most specific index covering it — a page under `/media/` advertises `/media/llms.txt`, not the site root index.
- The `describedby` link is now sent on Markdown responses too (`.md` URLs, Markdown 404s, negotiated pages), which previously advertised no index at all.
- The footer llms.txt link and 404 recovery both follow the same scoping and name the section they cover.
- The `mmsar_llms_txt_link_text` filter now also receives the resolved URL and covering section. Existing filters are unaffected.

## 1.21.3 — 2026-08-28

- Code formatting and coding-standards cleanup across `includes/`. No functional change.

## 1.21.2 — 2026-08-27

- `get_site_overview` now lists `/auth.md`.
- Fixed: the overview advertised `/openapi.json` even on sites where the plugin has stood down from serving it.
- Fixed: a stray blank line at the end of the overview output.

## 1.21.1 — 2026-08-27

- Fixed: the Agentic Resource Discovery catalog did not match its own specification — the entry array was misnamed and `type` carried a category word instead of a media type, so validators saw an empty catalog. Entries now also carry `capabilities`, `representativeQueries` and a `trustManifest`.
- The catalog is served at `/.well-known/ard.json` as well as `/.well-known/ai-catalog.json`.
- Added a Content-Security-Policy inside the MCP Apps UI template.
- A GET to the MCP endpoint now returns rate-limit headers alongside its 405.

## 1.21.0 — 2026-08-27

- **"When to use this" section in llms.txt** — helps an agent decide whether the site is worth fetching at all. Editable on the settings page.
- **`/auth.md`** — documents how to authenticate with the site. For most sites the honest answer is "you don't", which is worth publishing rather than omitting.
- **`/.well-known/ai-catalog.json`** (Agentic Resource Discovery) — a typed, machine-readable resource catalog for directories.
- **`/.well-known/mcp/server-card.json`** — full tool detail so a directory can preview the MCP server without connecting.
- **`?mode=agent`** on any page, for clients that can't set an `Accept` header.
- **Per-section llms.txt** — `/press/llms.txt` indexes exactly what lives under `/press/`.
- **NLWeb `/ask` with SSE streaming**, plus a Schema Map and a `Schemamap:` robots.txt directive. Off by default.
- **Rate-limit headers on the MCP endpoint**, in both the individual and structured formats.
- **Optional MCP Apps support** — an experimental `ui://` resource rendering search results as cards. Off by default and labelled experimental: no MCP Apps host was available to verify it against.
- A 404 on an API-shaped path (`/api`, `/api/v1`, `/v1`) returns JSON even when the client didn't ask for it.
- `get_site_overview` takes an optional `sections` argument.
- Fixed: `/auth.md` was being swallowed by the `.md` catch-all rule.
- Fixed: per-section llms.txt rules were never registered, because custom post types register after plugins.
- Fixed: scoped llms.txt URLs were being redirected to a trailing-slash variant.

## 1.20.1 — 2026-08-27

- A 404 answers in JSON when the request asks for JSON, using the same `code` / `message` / `data.status` shape as the REST API. A browser can never trigger this.
- The MCP manifest carries the site icon.
- MCP tool input schemas are closed (`additionalProperties: false`).
- The OpenAPI document references its `Error` schema everywhere that shape is genuinely returned.

## 1.20.0 — 2026-08-27

- **An OpenAPI specification at `/openapi.json`**, generated from the site's actual routes and enabled features — nothing is documented that the site doesn't answer. Stands down if a real `openapi.json` already exists in the site root.
- **A read-only MCP server at `/wp-json/mmsar/v1/mcp`, off by default.** Read-only, published content only, rate-limited per IP. Discovery manifest at `/.well-known/mcp.json`.
- **Agent-recoverable 404s** — every 404 now carries `Link` headers and `<link>` elements pointing at the sitemap, llms.txt, the OpenAPI document and the endpoint catalog. Nothing changes for browsers.
- llms.txt opens with a "For agents" section naming the machine-readable endpoints the site publishes.
- A missing `.md` URL returns a recovery list and distinguishes its cases.

## 1.19.0 — 2026-08-26

- **CSV export on the Agent Log screen** — the whole log, streamed in batches. Spreadsheet formula injection is neutralised on export.
- **A `get-agent-log` ability** returning aggregates by agent, surface and day, plus one page of entries. Administrators only. `summary_only` omits the entries and never handles IP addresses.

## 1.18.1 — 2026-08-23

- Fixed: the content-negotiation self-check could report the feature working while it was switched off. Some CDNs convert pages to Markdown at the edge, and the check credited that to the plugin. It now compares the returned body against the Markdown the plugin would actually serve.
- Added a `foreign` result: Markdown came back, browsers correctly got HTML, but the Markdown isn't this plugin's. Reported as a warning.
- The content negotiation section links back to its toggle in the Features list.

## 1.18.0 — 2026-08-23

- **Markdown content negotiation, reinstated off by default.** A request for an ordinary page URL is answered with that page's Markdown when its `Accept` header prefers Markdown. Parsing is deliberately strict so a person can never be served a Markdown file. Singular posts and pages only.
- **A self-check for it** at Settings > Agent-Ready. Requests one of the site's own pages twice — once as an agent, once as a browser — and reports what came back, plus the `Cache-Control` and `Vary` that actually arrived. Runs automatically when the feature is switched on.
- The check switches the feature back off if a browser-style request is answered with Markdown, and says so at the top of the screen.
- Settings copy now describes what the plugin attempts rather than an outcome it can't guarantee behind a CDN.
- Fixed: a stored setting left behind by 1.13.x no longer silently re-enables the feature on update.

## 1.17.1 — 2026-08-23

- Spelling normalised to US English across settings copy, `readme.txt`, `README.md` and code comments. No functional change.

## 1.17.0 — 2026-08-23

- A **Recent Agent Requests** dashboard widget listing the 20 most recent entries, with a link to the full log.

## 1.16.1 — 2026-08-23

- Fixed: the one-time import of pre-1.16.0 log entries could run twice under concurrent requests, duplicating every migrated row. Sites already showing duplicates can clear the log once.

## 1.16.0 — 2026-08-23

- The agent request log has its own screen at **Settings > Agent Log** — paginated, with a Clear log button and a configurable retention limit (default unlimited).
- Entries are stored in a dedicated table rather than a serialised option, so the log can grow without rewriting the whole history on every request. Existing entries are migrated on upgrade.
- The plugin now adds one database table, only when the agent log is switched on, and drops it on uninstall.
- Minimum WordPress raised from 6.0 to 6.2.

## 1.15.1 — 2026-08-21

- Fixed: "Visit plugin site" appeared twice on the Plugins screen. WordPress core already adds that link.

## 1.15.0 — 2026-08-19

- **Removed: Markdown by content negotiation** (added in 1.13.0). Serving a different representation from the canonical URL requires the cache layer to key on `Accept`; the CDN tested did not, so a cached Markdown response was served to the next browser and a visitor received a file download instead of the page. Neither that nor the `Cache-Control` rewriting is under a plugin's control. Reinstated with a self-check in 1.18.0.
- Fixed: the agent request log keeps its own record rather than depending on the Activity Log plugin, whose API proved unreachable on front-end requests on one host — exactly the requests agents make.
- Database errors from the Activity Log mirror are suppressed so they can never print into a response.
- Corrected the footer llms.txt link's description, which overstated what fetch tools can see.

## 1.14.0 — 2026-08-19

- Optional visible link to `llms.txt` in the site footer, off by default. Fetch tools receive the response body and discard headers and `<link>` tags, so a visible anchor is the one channel that reliably survives. Text filterable via `mmsar_llms_txt_link_text`.

## 1.13.1 — 2026-08-19

- Fixed: Markdown served by content negotiation is marked `Cache-Control: private, no-store`, after a CDN cached a Markdown response and served it to a human visitor. Negotiation now works on cache misses and is inert on hits, which fails safely.

## 1.13.0 — 2026-08-19

- **Markdown by content negotiation** (off by default) — the canonical URL returns Markdown when the request's `Accept` header prefers it. Parsing is deliberately strict.
- **Agent request log** (off by default) — records which agents fetch the plugin's files and what they asked for, via the Activity Log plugin. Optionally also records page views from recognised AI crawlers. Throttled to one entry per agent, file and IP per five minutes.

## 1.12.1 — 2026-08-19

- Fixed: directives in **Additional Rules** are appended at the very end of the filter chain, so nothing running later can rewrite or remove them. Yoast's `remove_default_robots()` was silently stripping them from the served file while the settings preview continued to show them.
- Extra rules are still honoured on `blog_public = 0` sites.

## 1.12.0 — 2026-08-13

- `robots.txt` carries an `Llms-txt:` directive pointing at the site's `/llms.txt`. No ratified directive exists for this, but RFC 9309 parsers skip unrecognised directives, so it can't affect crawling.
- A `Link: <…/llms.txt>; rel="describedby"` header on every front-end response.
- Feature toggles now flush rewrite rules however the option is written — previously only saves through the settings page did.
- The pending-flush flag lives for a day instead of a minute.

## 1.11.0 — 2026-08-12

- Fixed: an endpoint published in api-catalog, llms.txt and the Agent Skills index is no longer contradicted by a `Disallow` rule in the same site's `robots.txt`. The plugin adds an `Allow:` line for the specific path, inside the group that blocks it. Prompted by Yoast's default `Disallow: /wp-json/`.
- Nothing is emitted for an endpoint that no rule blocks, one on another host, or the site root.

## 1.10.1 — 2026-08-12

- Fixed: the plugin's documents send their own `Cache-Control` header instead of inheriting a host default, after a CDN pinned a week-old copy of `/.well-known/api-catalog`. Adjustable via the `mmsar_document_max_age` filter.

## 1.10.0 — 2026-08-12

- Endpoints can be added and managed from Settings > Agent-Ready, with no code.
- Each saved endpoint reports where it is actually published, and says why when it isn't.
- Three abilities for the WordPress Abilities API (WP 6.9+): `list-endpoints`, `set-endpoint`, `delete-endpoint`.
- Endpoints registered in code appear under a read-only "Added by Plugins" heading.
- Security: URLs are validated, not merely escaped, before publication.

## 1.9.0 — 2026-08-12

- Other plugins and themes can add their own endpoints to the documents this plugin publishes, via `mmsar_register_endpoint()` or the `mmsar_registered_endpoints` filter. One description is published to api-catalog, llms.txt and the Agent Skills index together.
- Registered endpoints appear read-only on the settings page, showing which documents each is published in.
- Three whole-document filters: `mmsar_api_catalog_linkset`, `mmsar_llms_txt_content`, `mmsar_agent_skills_index`.
- Registrations are validated and dropped if they can't be published safely. With nothing registered, all documents are byte-for-byte what they were before.

## 1.8.2 — 2026-08-05

- Security hardening: the request URI used for canonical-redirect checks is sanitised and parsed properly; admin output is explicitly escaped. No behaviour change.

## 1.8.1 — 2026-07-22

- Packaging: added a `.gitattributes` with `export-ignore` rules so the archives GitHub generates contain only the plugin's runtime files. Previously they also carried `.github/`, `README.md`, `CHANGELOG.md` and `.gitignore`.

## 1.8.0 — 2026-07-21

- The settings page's separate "Quick Links" list is folded into the Features toggle list. Each feature serving a fixed URL shows a "View" link under its toggle, and only when enabled.
- Features with their own settings section show a "Configure below ↓" link beside the toggle.

## 1.7.1 — 2026-07-21

Security and hardening pass following an external code review.

- Fixed: password-protected posts could leak through `/llms-full.txt` and `/llms.txt` if the password was added after the Markdown was cached.
- Fixed: the security.txt Contact field trusted any URI scheme, allowing an unsafe scheme to be published.
- Fixed: the Content Signals sanitiser fell back to `yes` for every signal including `ai_train`, contradicting its registered default of `no`.
- Fixed: Markdown serving now explicitly requires a published post status.
- The Agent Skills SKILL.md and index document only the endpoints actually enabled.
- `/robots.txt` no longer adds AI-crawler `Allow:` rules when the site is set to discourage search engines.
- api-catalog advertises llms.txt and llms-full.txt as `text/plain`, matching what they actually send.

## 1.7.0 — 2026-07-20

- **Every output can be switched off individually** under Settings > Agent-Ready — Markdown URLs, llms.txt, llms-full.txt, robots.txt rules, security.txt, api-catalog and Agent Skills discovery. A disabled feature registers nothing at all. Existing installs are unaffected: a missing setting means "on".
- `Link` headers and the api-catalog list only enabled endpoints, so neither can advertise a switched-off one.
- Switching off robots.txt handling disables both the filter and the rewrite rule.
- security.txt gains a dedicated Security Contact field accepting a URL, a bare path or an email address, falling back to the site admin email.
- The `get-settings` ability reports the feature states.
- Fixed: the `Sitemap:` directive and the api-catalog sitemap entry hardcoded Yoast's filename, so sites using core sitemaps, All in One SEO or SEOPress advertised a URL that 404s.
- Fixed: the "don't add a Sitemap line if one exists" guard ran before Yoast wrote its own, leaving two directives in the served file.

## 1.6.1 — 2026-07-15

- Fixed: 1.6.0's Yoast schema injection never fired, because the filters were registered behind a check that ran before Yoast had loaded. They are now registered unconditionally.

## 1.6.0 — 2026-07-14

- JSON-LD structured data merges into Yoast SEO's own schema instead of adding a separate block, injecting just the `encoding` pointer to the Markdown alternate.
- Falls back to the standalone block when Yoast isn't active or doesn't produce a piece for the page.
- The admin conflict notice no longer warns about Yoast, since there is nothing left to conflict with.

## 1.5.0 — 2026-07-14

- Optional JSON-LD structured data (off by default) — a minimal `Article` or `WebPage` block with an `encoding` field pointing at the `.md` URL. Deliberately minimal so it can't collide with an SEO plugin's own graph.

## 1.4.3 — 2026-07-06

- Content Signals — a `Content-Signal:` directive built from three yes/no settings, emitted under each of the plugin's AI-crawler groups in robots.txt. Defaults to `search=yes, ai-input=yes, ai-train=no`.

## 1.4.2 — 2026-07-06

- HTTP `Link` response headers (RFC 8288) on every front-end response, plus a third on singular posts and pages mirroring the Markdown alternate tag.

## 1.4.1 — 2026-07-06

- Fixed: the `.md` catch-all rewrite rule matched the Agent Skills `SKILL.md` path and made it 404.

## 1.4.0 — 2026-07-06

- `/.well-known/api-catalog` (RFC 9727) — a Linkset document indexing llms.txt, llms-full.txt, security.txt, the Agent Skills index, sitemap and feed.
- Agent Skills discovery — `/.well-known/agent-skills/index.json` plus a bundled `fetch-content-as-markdown` skill.
- Version bumps trigger an automatic rewrite-rule flush, so new rules take effect without resaving Permalinks.

## 1.3.3 — 2026-06-18

- robots.txt "Current Content" read-only preview in the settings page.

## 1.3.2 — 2026-06-18

- robots.txt settings section with an "Additional Rules" textarea for custom directives.

## 1.3.1 — 2026-06-15

- Fixed: removed `X-Robots-Tag: noindex` from `/llms.txt` and `/llms-full.txt` — these files are meant to be found.
- Fixed: added a rewrite rule routing `robots.txt` through WordPress, so the filter fires even when a physical file exists.
- Admin notice when a static `robots.txt` is detected in the webroot.

## 1.3.0 — 2026-06-15

Plugin renamed: LLM Markdown → Make My Site Agent-Ready. Prefixes updated `LLMMD_` → `MMSAR_`; option keys kept for data continuity.

- `/llms-full.txt` — full site content concatenated as Markdown, cached with a 24h TTL.
- `/.well-known/security.txt` per RFC 9116, configurable, falling back to the admin email.
- AI crawler rules in `robots.txt` for GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot and FacebookBot, plus a `Sitemap:` directive.
- Fixed: trailing-slash redirect on plugin-owned paths.
- `regenerate-files` is always registered and marked `destructive: true`; the "Enable write abilities" checkbox is removed.

## 1.2.2 — 2026-06-01

- Fixed: PHP 8 compatibility in abilities execute callbacks.

## 1.2.1 — 2026-06-01

- Fixed: `meta.mcp.public` key in abilities registration.

## 1.2.0 — 2026-06-01

- WordPress Abilities API integration (`get-settings`, `regenerate-files`).

## 1.1.2 — 2026-05-24

- Fixed: YAML frontmatter `url` and `markdown_url` fields are quoted for spec compliance.
- Fixed: Markdown link titles in llms.txt escape `]` characters.
- Added the `llmmd_bulk_generate_limit` filter for large-site memory control.

## 1.1.1 — 2026-05-20

- Replaced the "View details" plugin row link with "Visit plugin site".

## 1.1.0 — 2026-05-20

- Security: CSS selectors are sanitised to prevent XPath injection.
- Security: `X-Content-Type-Options: nosniff` on `.md` responses.
- Security: `$wpdb->prepare()` in `uninstall.php`.
- Fixed: YAML escape order.

## 1.0.5 — 2026-05-20

- HTML entities decoded in llms.txt.
- Fixed: homepage URL in llms.txt.

## 1.0.4 — 2026-05-20

- HTML entities decoded in YAML frontmatter.
- Fixed: front page `markdown_url`.

## 1.0.3 — 2026-05-20

- Fixed: front page `/index.md`.
- Added an alternate link tag to the homepage.

## 1.0.2 — 2026-05-20

- Fixed: front page `/index.md` returning 404 (partial).

## 1.0.1 — 2026-05-20

- Post excerpts added to llms.txt entries.

## 1.0.0 — 2026-05-20

Initial release.

- `.md` URL suffix serves a Markdown version of any post or page.
- YAML frontmatter with title, date, author, URL, excerpt, categories and tags.
- Markdown pre-generated on save and stored in post meta.
- `/llms.txt` site index listing available Markdown URLs by category.
- `<link rel="alternate" type="text/markdown">` in page headers.
- Settings page for post type selection and content root CSS selector.
- Clean uninstall removes all plugin data.
