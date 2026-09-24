<?php
/**
 * Agent request log — records which agents fetch the surfaces this plugin publishes.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent request log.
 */
class MMSAR_Agent_Log {

	/**
	 * How long the same agent, surface and IP is suppressed for, in seconds.
	 *
	 * The question this log answers is "which agents fetch what", not "how many times". Without a
	 * throttle a single crawler looping on one URL would drown out everything else.
	 */
	const THROTTLE = 300;

	/**
	 * Schema version. Bump to trigger dbDelta on the next load.
	 *
	 * 6 (1.48.0) adds `signature_agent` and `same_site`, the two stored browser signals. Both are
	 * additive; rows before them read '' and NULL, which the readers treat as "unsigned" and "not
	 * recorded" respectively.
	 */
	const DB_VERSION = 6;

	/**
	 * How long a caller may go quiet before its next request counts as a new visit, in seconds.
	 *
	 * The journeys view exists because a sequence of requests is worth more than the same requests
	 * as a flat list, and a sequence needs an end. Thirty minutes is the long-standing web-analytics
	 * convention and it suits crawlers well: they work through a site in bursts and then leave, so
	 * a gap this long almost always means the run finished rather than paused.
	 */
	const VISIT_GAP = 1800;

	/**
	 * How many recent requests the journeys view stitches into visits.
	 *
	 * Sessionizing is done in PHP over a bounded window rather than in SQL over the whole table.
	 * The log has no upper size — retention defaults to keeping everything — so a query that
	 * grouped across all of it would grow without limit on exactly the sites that log the most.
	 * A window keeps the cost of opening the screen flat, and the view says what it covers rather
	 * than implying it covers everything.
	 */
	const JOURNEY_WINDOW = 5000;

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'mmsar_agent_log_db_version';

	/**
	 * Option holding the retention limit. 0 means keep everything.
	 */
	const LIMIT_OPTION = 'mmsar_agent_log_limit';

	/**
	 * Option the log used before 1.16.0, when entries lived in a capped array.
	 */
	const LEGACY_OPTION = 'mmsar_agent_log';

	/**
	 * Option used to claim the one-time migration.
	 *
	 * Created with add_option(), which is an INSERT against a unique index, so exactly one caller
	 * can succeed. That
	 * gives a lock that holds across concurrent requests, which a read-then-write guard does
	 * not: two requests arriving during the same upgrade both read the legacy option before either
	 * deleted it, and both wrote its contents into the table. Every migrated entry appeared twice.
	 */
	const MIGRATED_FLAG = 'mmsar_agent_log_migrated';

	/**
	 * How often pruning runs, as one prune per N inserts.
	 *
	 * Trimming on every insert would add a COUNT and a DELETE to requests that are already doing
	 * the useful work. The log is allowed to overshoot its limit by up to this many rows between
	 * prunes, which nobody can observe and which costs one extra row of storage each.
	 */
	const PRUNE_EVERY = 50;

	/**
	 * User-agent fragments identifying a known agent or AI crawler, matched case-insensitively.
	 *
	 * Used only when logging ordinary page views. The plugin's own endpoints do not consult this
	 * list — anything fetching those is agent traffic by definition.
	 */
	const AGENTS = array(
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'Anthropic-AI',
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'GoogleOther',
		'Gemini',
		'Applebot-Extended',
		'meta-externalagent',
		'Bytespider',
		'CCBot',
		'cohere-ai',
		'DuckAssistBot',
		'Amazonbot',
		'YouBot',
		'Diffbot',
		'LinkupBot',
		'SSI-Nutch',
		// Added 2026-09-14 from the agent-log-bot-watch report. Not all of these are AI crawlers,
		// deliberately: recognition, verification and full-address storage work the same way for
		// every named bot, and CRAWLER_CATEGORIES is what tells them apart.
		'AhrefsBot',
		'Barkrowler',
		'SeznamBot',
		'MojeekBot',
		'SERankingBacklinksBot',
		'DuckDuckBot',
		'PetalBot',
		'ExaSearchBot',
		'ShapBot',
		'SofyaBot',
		'SemrushBot',
		'MJ12bot',
		'AwarioBot',
		// Desktop software run by whoever installed it, with a user-editable user-agent. Never
		// verifiable by design: recognising it only says the software identified itself.
		'Screaming Frog SEO Spider',
		'PublicWWWBot',
		// Decentralised, volunteer-run crawler with no fixed addresses. Never verifiable by design.
		'mwmbl',
		'trendictionbot',
		'Pandalytics',
		'Google-CloudVertexBot',
		// Ora's own documentation says it also sends requests under other crawlers' names to test
		// AI-crawler access, which is part of why GPTBot and ClaudeBot carry `failed` rows here.
		'OraBot',
		'EtherdeckBot',
		'LyonlBot',
		// Self-hosted feed reader software rather than one operator: name-only recognition.
		'Miniflux',
		'Twitterbot',
		// Meta's documentation says this may bypass robots.txt for security and integrity checks.
		'facebookexternalhit',
		// Slack's documentation says it does not honour robots.txt for this bot.
		'Slackbot-LinkExpanding',
		// Added 1.43.0. All three already had a reverse-DNS method in
		// MMSAR_Agent_Log_Verify::VERIFY_HOSTS and an entry in CRAWLER_CATEGORIES, and were missing
		// only here — the one list that decides whether an address is kept. So every page view from
		// the three largest search crawlers on the web was stored at network precision and could
		// never be verified afterwards, and under the `agents` page-view mode was not recorded at
		// all. Nothing about them was ever unrecognised except this.
		//
		// `Applebot` must stay below `Applebot-Extended`: agent_label() returns the first match in
		// this order and the shorter name is a substring of the longer one, so listing it earlier
		// would relabel every Applebot-Extended row. Appending is what keeps that safe, which is
		// the general rule for adding a name that is a prefix of one already here.
		'Googlebot',
		'bingbot',
		'Applebot',
		// Added 1.45.0, recognise-only: both are named here so their addresses stop being reduced,
		// and neither gets a verification entry because neither operator publishes a method for the
		// token it actually sends.
		//
		// FeedBurner is Google's, and its traffic is genuinely Google's — the addresses seen here
		// reverse to `google-proxy-66-249-83-*.google.com`, which is the mask Google documents for
		// user-triggered fetchers, alongside `user-triggered-fetchers.json`. But Google's list of
		// fetcher tokens is Feedfetcher, Google-Read-Aloud, Google-NotebookLM and the rest; it does
		// not include `FeedBurner`. Documented-for-Google's-fetchers is not documented-for-this-name,
		// and the gap is the whole reason MJ12bot and SemrushBot were declined too.
		//
		// Recognition is still worth doing now and verification is not, because the two have
		// different deadlines: an address reduced today can never be checked against a list
		// published tomorrow, while a suffix can be added whenever the documentation catches up.
		// Same reasoning as the SSI-Nutch split.
		'FeedBurner',
		// Feedbin publishes no ranges and no hostname convention; its addresses sit in Hurricane
		// Electric colocation, where the records that do resolve are HE's own routers. Categorised
		// with Miniflux: a feed reader fetching on behalf of subscribers.
		'Feedbin',
		// Added 1.46.0 from the agent-log-bot-watch reports of 2026-09-18 and 2026-09-21. Appended,
		// not inserted, for the ordering reason given above Googlebot.
		//
		// Censys's internet-wide scanner. Verified by reverse DNS under censys-scanner.com.
		'CensysInspect',
		// Palo Alto Networks' Cortex Xpanse scanner. Its user-agent is a sentence with no product
		// token — "Hello from Palo Alto Networks, find out more about our scans in https://…" — so
		// the operator's name is the only stable thing to match. Stored under the fuller label in
		// AGENT_LABELS, so the log says which Palo Alto product it was. Verified by the ranges
		// Palo Alto publishes for Xpanse scanning; the addresses have no reverse DNS.
		'Palo Alto Networks',
		// LeakIX's scanner. The version after `l9scan/` is randomised per request, so only the
		// token is matched. Verified by reverse DNS under leakix.org (hosts are *.scan.leakix.org).
		'l9scan',
		// The Internet Archive's Wayback Machine crawler. Archival, so categorised `other`.
		'archive.org_bot',
		'YandexBot',
		// agenttru.st documents forward-confirmed reverse DNS under agenttru.st, and eight of the ten
		// addresses it publishes do resolve there — but 129.121.133.131, which is on its list and is
		// the address that actually called this site, reverses to ip-129-121-133-131.local
		// (checked 2026-09-22). Adding the suffix would call the operator's own crawler a forgery,
		// so this is recognise-only until the documentation and the DNS agree.
		'AgentTrustBot',
		// Small independent search engine; publishes no verification method.
		'fyndbot',
		// Site-benchmarking service in early access; publishes no crawler documentation yet.
		'TheWebReport',
		// A National Taiwan University course project, disclosing a student contact. No operator
		// domain to check, and likely short-lived — expect it to stop appearing.
		'ntu-sa-crawler',
		// Linkfluence (now part of Meltwater). Guarded in AGENT_DISCLOSURES: a three-letter token
		// matched case-insensitively would otherwise claim "Kayak" and "Yakima". linkfluence.com
		// now redirects to Meltwater, which has no crawler page, so the category and the claim that
		// it honours robots.txt rest on the operator's business and third-party sources, not on any
		// documentation of this bot.
		'YaK',
		// Ubermetrics Technologies. Guarded for the same reason — a short token — and with the same
		// caveat: the operator's site does not document the crawler, so its category and
		// robots.txt behaviour rest on the company's business and third-party sources. Seen so far
		// only as a "Script or fetch tool" from 88.99.144.0/24, so every row before this release is
		// stored at network precision.
		'um-LN',
		// Added 1.50.0 from the agent-log-bot-watch report of 2026-09-24. Appended, for the ordering
		// reason given above Googlebot. The first four are verified by reverse DNS; each suffix was
		// documented by the operator and forward-confirmed on a real address from this site's log
		// on 2026-09-24. See MMSAR_Agent_Log_Verify::VERIFY_HOSTS.
		//
		// Qwant's search crawler. The token also covers the documented Qwantbot-news variant.
		'Qwantbot',
		// DataForSEO's backlink crawler.
		'DataForSeoBot',
		// LohiSoft's independent search engine. Two user-agent forms are in transition
		// (`+message@lohisoft.com` and `+https://lohisoft.com/bot`); the token covers both.
		'LohiSoftBot',
		// Keywords Everywhere's PoweredBy technology profiler. Guarded in AGENT_DISCLOSURES, because
		// "PoweredBy" is a generic word and the operator's domain is always in the user-agent.
		'PoweredByBot',
		// The rest are recognise-only.
		//
		// DomainStats documents reverse DNS to bot.domainstats.com, and all three addresses seen
		// here do reverse to that name — but it forward-resolves to only one of them
		// (148.251.121.91; 136.243.59.237 and 136.243.222.140 do not come back, checked
		// 2026-09-24). Those two sent 29 of its 30 rows. Adding the suffix would call the
		// operator's own crawler a forgery, the same call as AgentTrustBot in 1.46.0; revisit if
		// the forward records come to cover every crawl address.
		'DomainStatsBot',
		// Qlyze's backlink index. Publishes no verification method; its addresses carry generic
		// Hetzner hostnames.
		'QlyzeBot',
		// MapTheNet, an open-source map of who links to whom. Categorised `other` rather than
		// `seo-tool`: it overlaps with the backlink indexes, but the operator presents it as a
		// public link-graph map, not an SEO product. Publishes no verification method.
		'MapTheNetBot',
		// Amazon Quick's Web Crawler integration, which crawls URLs a Quick customer configures to
		// build a knowledge base for Quick's AI chat. The user-agent is this prefix followed by a
		// per-customer identifier, so only the prefix is matched, and the stored label (see
		// AGENT_LABELS) drops the identifier. It runs on AWS, not on the customer's device, so
		// keeping its address is not keeping a person's. It fetches pages through a headless
		// browser, so before recognition its page views were filed as Browser.
		'amazon-Quick-on-behalf-of',
		// Micro.blog, the hosted blogging service. It publishes no crawler page, so both its
		// category and whether it honours robots.txt are unconfirmed — `other` rests on what the
		// service does (feed, bookmark and link-preview fetching), not on bot documentation. Seen
		// from a Linode network, so it fetches from Micro.blog's servers rather than a reader's
		// device. The dot is literal: matching is a substring test, never a pattern.
		'Micro.blog',
	);

	/**
	 * The label stored for a recognised name, where it differs from the name that is matched.
	 *
	 * The matched name is normally the right thing to store — it is what the operator calls its
	 * bot. The exception is a user-agent with no product token, where the only stable substring is
	 * the operator's name and storing that alone would be less precise than what is known.
	 *
	 * **Every label must contain its key.** Verification and categorisation re-derive the claim from
	 * the stored value by substring (see MMSAR_Agent_Log_Verify::claimed_name()), so a label that
	 * dropped the matched name would read as unclaimed. A test asserts it.
	 */
	const AGENT_LABELS = array(
		'Palo Alto Networks'        => 'Cortex Xpanse (Palo Alto Networks)',
		// The matched prefix names the integration rather than the product, and the full user-agent
		// carries a per-customer identifier that has no place in a label.
		'amazon-Quick-on-behalf-of' => 'Amazon Quick (amazon-Quick-on-behalf-of)',
	);

	/**
	 * Crawler categories — what kind of bot a recognised name is.
	 *
	 * The line between AI crawlers, search engines and SEO tools is not a clean one — search indexes
	 * feed AI answers and SEO companies run AI products — so every recognised bot sits in one list
	 * and carries a tag here instead of being kept in a separate one. See the decisions log,
	 * "Recognised crawlers carry a category".
	 *
	 * Assigned by what the operator documents that specific bot doing, not by the operator's
	 * business overall. Confirmed per name on 2026-09-14.
	 */
	const CRAWLER_AI_TRAINING  = 'ai-training';
	const CRAWLER_AI_SEARCH    = 'ai-search';
	const CRAWLER_AI_ASSISTANT = 'ai-assistant';
	const CRAWLER_SEARCH       = 'search-engine';
	const CRAWLER_SEO          = 'seo-tool';
	const CRAWLER_MONITORING   = 'monitoring';
	const CRAWLER_SCANNER      = 'scanner';
	const CRAWLER_OTHER        = 'other';

	/**
	 * The filter value for rows that name no recognised crawler.
	 */
	const CRAWLER_UNRECOGNISED = 'unrecognised';

	/**
	 * Category per recognised name.
	 *
	 * Covers every name in AGENTS, plus the three the verifier knows but AGENTS does not
	 * (Googlebot, Applebot, bingbot) — those are verifiable when they appear on an agent surface,
	 * and are deliberately not in AGENTS, which would switch their page views to full-address
	 * storage. A test asserts that every AGENTS entry has a category here.
	 */
	const CRAWLER_CATEGORIES = array(
		'ClaudeBot'                 => self::CRAWLER_AI_TRAINING,
		'Claude-User'               => self::CRAWLER_AI_ASSISTANT,
		'Claude-SearchBot'          => self::CRAWLER_AI_SEARCH,
		'Anthropic-AI'              => self::CRAWLER_AI_TRAINING,
		'GPTBot'                    => self::CRAWLER_AI_TRAINING,
		'ChatGPT-User'              => self::CRAWLER_AI_ASSISTANT,
		'OAI-SearchBot'             => self::CRAWLER_AI_SEARCH,
		'PerplexityBot'             => self::CRAWLER_AI_SEARCH,
		'Perplexity-User'           => self::CRAWLER_AI_ASSISTANT,
		// A robots.txt control token rather than a crawler that visits; a row under it is a claim.
		'Google-Extended'           => self::CRAWLER_AI_TRAINING,
		// General-purpose crawler for Google product and research teams.
		'GoogleOther'               => self::CRAWLER_OTHER,
		'Gemini'                    => self::CRAWLER_AI_ASSISTANT,
		// Also a control token, like Google-Extended.
		'Applebot-Extended'         => self::CRAWLER_AI_TRAINING,
		'meta-externalagent'        => self::CRAWLER_AI_TRAINING,
		'Bytespider'                => self::CRAWLER_AI_TRAINING,
		// An open dataset, used mostly for model training.
		'CCBot'                     => self::CRAWLER_AI_TRAINING,
		'cohere-ai'                 => self::CRAWLER_AI_TRAINING,
		'DuckAssistBot'             => self::CRAWLER_AI_SEARCH,
		// Amazon says it may train models; it also feeds Alexa answers.
		'Amazonbot'                 => self::CRAWLER_AI_TRAINING,
		'YouBot'                    => self::CRAWLER_AI_SEARCH,
		// Builds a knowledge graph sold largely for AI use.
		'Diffbot'                   => self::CRAWLER_AI_TRAINING,
		'LinkupBot'                 => self::CRAWLER_AI_SEARCH,
		// Inferred: SSI publishes no crawler documentation.
		'SSI-Nutch'                 => self::CRAWLER_AI_TRAINING,
		'AhrefsBot'                 => self::CRAWLER_SEO,
		'Barkrowler'                => self::CRAWLER_SEO,
		'SeznamBot'                 => self::CRAWLER_SEARCH,
		'MojeekBot'                 => self::CRAWLER_SEARCH,
		'SERankingBacklinksBot'     => self::CRAWLER_SEO,
		'DuckDuckBot'               => self::CRAWLER_SEARCH,
		'PetalBot'                  => self::CRAWLER_SEARCH,
		'ExaSearchBot'              => self::CRAWLER_AI_SEARCH,
		'ShapBot'                   => self::CRAWLER_AI_SEARCH,
		'SofyaBot'                  => self::CRAWLER_AI_SEARCH,
		'SemrushBot'                => self::CRAWLER_SEO,
		'MJ12bot'                   => self::CRAWLER_SEO,
		'AwarioBot'                 => self::CRAWLER_MONITORING,
		'Screaming Frog SEO Spider' => self::CRAWLER_SEO,
		// Indexes source code rather than general content.
		'PublicWWWBot'              => self::CRAWLER_SEARCH,
		'mwmbl'                     => self::CRAWLER_SEARCH,
		// Also does general search-engine crawling.
		'trendictionbot'            => self::CRAWLER_MONITORING,
		'Pandalytics'               => self::CRAWLER_OTHER,
		// On-demand, customer-triggered Vertex AI Agent fetches.
		'Google-CloudVertexBot'     => self::CRAWLER_OTHER,
		'OraBot'                    => self::CRAWLER_SCANNER,
		'EtherdeckBot'              => self::CRAWLER_SEARCH,
		'LyonlBot'                  => self::CRAWLER_SEARCH,
		'Miniflux'                  => self::CRAWLER_OTHER,
		'FeedBurner'                => self::CRAWLER_OTHER,
		'Feedbin'                   => self::CRAWLER_OTHER,
		'Twitterbot'                => self::CRAWLER_OTHER,
		'facebookexternalhit'       => self::CRAWLER_OTHER,
		'Slackbot-LinkExpanding'    => self::CRAWLER_OTHER,
		'Googlebot'                 => self::CRAWLER_SEARCH,
		'Applebot'                  => self::CRAWLER_SEARCH,
		'bingbot'                   => self::CRAWLER_SEARCH,
		// Added 1.46.0; confirmed per name by Miriam on 2026-09-22.
		'CensysInspect'             => self::CRAWLER_SCANNER,
		'Palo Alto Networks'        => self::CRAWLER_SCANNER,
		'l9scan'                    => self::CRAWLER_SCANNER,
		// Archival rather than AI, search or SEO.
		'archive.org_bot'           => self::CRAWLER_OTHER,
		'YandexBot'                 => self::CRAWLER_SEARCH,
		// Probes for agent cards rather than collecting content; the overlap with `ai-search` is
		// real but it is discovery behaviour.
		'AgentTrustBot'             => self::CRAWLER_SCANNER,
		'fyndbot'                   => self::CRAWLER_SEARCH,
		'TheWebReport'              => self::CRAWLER_OTHER,
		'ntu-sa-crawler'            => self::CRAWLER_OTHER,
		// Both from the operator's business and third-party descriptions; neither operator
		// documents the bot itself.
		'YaK'                       => self::CRAWLER_MONITORING,
		'um-LN'                     => self::CRAWLER_MONITORING,
		// Added 1.50.0.
		'Qwantbot'                  => self::CRAWLER_SEARCH,
		'DataForSeoBot'             => self::CRAWLER_SEO,
		'LohiSoftBot'               => self::CRAWLER_SEARCH,
		// A technology profiler (which software a site runs), sold as marketing intelligence by an
		// SEO-tool company.
		'PoweredByBot'              => self::CRAWLER_SEO,
		'DomainStatsBot'            => self::CRAWLER_SEO,
		'QlyzeBot'                  => self::CRAWLER_SEO,
		// Overlaps with the backlink indexes; see the note in AGENTS.
		'MapTheNetBot'              => self::CRAWLER_OTHER,
		// Fetches because a customer configured it, but ingests several levels deep and resyncs.
		'amazon-Quick-on-behalf-of' => self::CRAWLER_AI_ASSISTANT,
		// Unconfirmed: the operator documents no crawler.
		'Micro.blog'                => self::CRAWLER_OTHER,
	);

	/**
	 * Agent names that are only claimed when the user-agent also discloses the right operator.
	 *
	 * Two unrelated crawlers can pick the same name, and the matching above is case-insensitive, so
	 * one operator's bot would otherwise be recorded under the other's name and then judged against
	 * the wrong operator's published ranges — which produces a confident `failed` against a real
	 * crawler, the one outcome the verification code says is worse than having no feature at all.
	 *
	 * `LinkupBot` is the live case. Linkup (linkup.so) runs an AI search crawler; LinkUp
	 * (linkup.com), unrelated, runs a job-listings crawler that spells its name the same way. Each
	 * discloses its own domain in its user-agent, which is the thing an impersonator cannot borrow
	 * without pointing at the operator it is impersonating.
	 *
	 * `SSI-Nutch` is here for the other reason a name needs guarding: not a collision that has
	 * already happened, but a name cheap enough to wear. `SSI` is a generic initialism and Nutch is
	 * an off-the-shelf crawler anyone can run, so the pair says very little on its own; requiring
	 * `ssi.inc` at least holds a claimant to naming the operator it claims to be. Nothing about this
	 * verifies the claim — SSI publishes no ranges and no reverse-DNS convention, so requests under
	 * this name reach `unverifiable` and never `verified`. It is recognition, not endorsement, and
	 * the reason to want it is that a recognised crawler's address is kept in full rather than
	 * reduced to its network, which is what makes any later verification possible at all.
	 *
	 * A name listed here that arrives without its disclosure is not recognised: it falls through to
	 * the trimmed user-agent, reads as an unrecognised self-declared crawler, and is never accused.
	 */
	const AGENT_DISCLOSURES = array(
		'LinkupBot'    => 'linkup.so',
		'SSI-Nutch'    => 'ssi.inc',
		// Short tokens, guarded against accidental substrings rather than a known collision. Both
		// domains sit inside the first 80 characters of the user-agent in either stored shape — the
		// pre-1.45.1 cut that kept `Mozilla/5.0 ` and the current one that drops it — so rows logged
		// before recognition still satisfy the guard. CrawlerCategoryTest asserts it for um-LN,
		// whose user-agent is the long one.
		'YaK'          => 'linkfluence.com',
		'um-LN'        => 'ubermetrics-technologies.com',
		// A generic word rather than a short token, guarded for the same reason. The domain sits
		// well inside the first 80 characters of the only form seen.
		'PoweredByBot' => 'keywordseverywhere.com',
	);

	/**
	 * Crawler names that a user-run client also sends, and the token that says which one it is.
	 *
	 * Claude Code fetches from the person's own machine and still identifies as Claude-User. Read
	 * out of the shipped client (2.1.231, 2.1.236 and 2.1.280 all agree), its WebFetch sends
	 * `Claude-User (claude-code/2.1.280; +https://support.anthropic.com/)`, with `agent-sdk/…` and
	 * `client-app/…` added inside the comment when it runs under the Agent SDK. That request can
	 * never come from Anthropic's published ranges, so judging it against them reported every
	 * Claude Code session on the site as a forgery — the most engaged agent traffic in the log,
	 * counted as spoofing.
	 *
	 * The token is the signal, not the address. "Residential and unattributed" would also clear a
	 * real forger who happened not to be caught in a burst, and the token is at least something the
	 * client says about itself. It can be forged like any user-agent, which is why the verdict it
	 * earns is `client` — cannot be checked, by design — and never `verified`.
	 *
	 * **Add an entry only with evidence that the operator's own client sends it from user
	 * machines.** ChatGPT-User and Perplexity-User are deliberately absent: every failed row under
	 * those names on the site this was built for was a real forgery.
	 */
	const USER_RUN_CLIENTS = array(
		'Claude-User' => 'claude-code',
	);

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );

		// Ordinary page views are only inspected when the owner opts in, and even then the work is
		// one pass over the user-agent. Everything else is triggered from a serve point the plugin
		// already owns, so a normal HTML request costs nothing at all.
		if ( 'off' !== self::page_view_mode() ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_record_page_view' ), 20 );
		}

		// robots.txt and feeds are machine-readable files like every agent-facing surface, so they
		// are recorded whenever the log is on, whatever the page-view setting (1.49.0). Each callback
		// is one conditional tag on an ordinary request.
		if ( self::is_active() ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_record_robots' ), 20 );
			add_filter( 'wp_headers', array( __CLASS__, 'maybe_record_feed' ) );
		}
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mmsar_agent_log';
	}

	/**
	 * Creates or upgrades the table when the stored schema version is behind.
	 *
	 * Runs from an option read on every load, which is a cached lookup, rather than only on
	 * activation — updating a plugin's files in place does not re-fire the activation hook, so a
	 * schema added in an update would otherwise never be created on an existing install.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one space around types.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			surface varchar(100) NOT NULL DEFAULT '',
			detail varchar(190) NOT NULL DEFAULT '',
			agent varchar(120) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			verified varchar(12) NOT NULL DEFAULT '',
			verified_at datetime DEFAULT NULL,
			client_type varchar(12) NOT NULL DEFAULT '',
			signature_agent varchar(100) NOT NULL DEFAULT '',
			same_site tinyint(1) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY verified (verified),
			KEY client_type (client_type),
			KEY visit (ip, logged_at)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::migrate_legacy_entries();
	}

	/**
	 * Moves entries from the pre-1.16.0 option into the table, then removes the option.
	 *
	 * @return void
	 */
	private static function migrate_legacy_entries() {
		// Claim the migration before reading anything. Whichever request creates this option owns
		// the job; any other request racing it here stops now rather than importing the same rows
		// a second time.
		if ( ! add_option( self::MIGRATED_FLAG, time(), '', false ) ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}

		global $wpdb;
		// Oldest first, so the table's ascending ids match the order the requests happened in.
		foreach ( array_reverse( $legacy ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration into this plugin's own table.
				self::table(),
				array(
					'logged_at' => isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) : current_time( 'mysql', true ),
					'surface'   => isset( $entry['surface'] ) ? (string) $entry['surface'] : '',
					'agent'     => isset( $entry['agent'] ) ? (string) $entry['agent'] : '',
					'ip'        => isset( $entry['ip'] ) ? (string) $entry['ip'] : '',
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}

		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return mmsar_feature_enabled( 'agent_log' );
	}

	/**
	 * The retention limit. 0 means keep everything.
	 *
	 * @return int
	 */
	public static function get_limit() {
		return absint( get_option( self::LIMIT_OPTION, 0 ) );
	}

	/**
	 * Total number of recorded entries.
	 *
	 * @return int
	 */
	public static function count_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; a cached count would show a stale log.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Normalizes a filter set into the three comma-joined strings the queries take.
	 *
	 * Multi-select is done with `FIND_IN_SET` against a validated, comma-joined list rather than a
	 * generated `IN (…)`. One placeholder covers any number of selected values, which keeps the SQL
	 * a fixed string in every combination — the same reason the rest of this class avoids assembling
	 * clauses. Every value is checked against a whitelist here, and none of them contains a comma.
	 *
	 * Empty means "no filter on that axis", with one deliberate exception: an empty client list
	 * excludes browser page views. This is an agent log, and once every page view is recorded a
	 * default that lists them all answers a different question. Tick Browsers to see them.
	 *
	 * **Except when a signal is ticked** (1.48.0). The signals exist to pick agents out of the
	 * browser rows, so a signal filter with the default client set would hide the very rows it
	 * selects. With a signal chosen and no client chosen, every client type is included.
	 *
	 * Signals combine with OR among themselves, like every other axis. The cloud signal is derived
	 * rather than stored, so it is resolved here the way crawler categories are: the distinct
	 * browser addresses are checked in PHP and the matching ones handed to SQL. Addresses never
	 * contain a comma, so they go to FIND_IN_SET as themselves.
	 *
	 * @param array $filters Keys 'verdicts', 'clients', 'categories', 'crawlers', 'signals', each an array of values.
	 * @return array{verdicts: string, clients: string, categories: string, crawlers: string, signals: string, cloud_ips: string}
	 */
	private static function normalize_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();

		$signals = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['signals'] ?? array() ) ),
				MMSAR_Agent_Log_Signals::signals()
			)
		);

		$verdicts = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['verdicts'] ?? array() ) ),
				array_merge( MMSAR_Agent_Log_Verify::verdicts(), array( 'pending' ) )
			)
		);

		$clients = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['clients'] ?? array() ) ),
				array_merge( self::client_types(), array( 'unrecorded' ) )
			)
		);
		if ( ! $clients && ! $signals ) {
			$clients = array( self::CLIENT_CRAWLER, self::CLIENT_HTTP, 'unrecorded' );
		} elseif ( ! $clients ) {
			$clients = array_merge( self::client_types(), array( 'unrecorded' ) );
		}
		// Every client type ticked is the same as no client filter, and saying so lets the query
		// skip the test entirely.
		if ( count( $clients ) === count( self::client_types() ) + 1 ) {
			$clients = array();
		}

		$categories = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['categories'] ?? array() ) ),
				self::categories()
			)
		);
		if ( count( $categories ) === count( self::categories() ) ) {
			$categories = array();
		}

		$crawlers = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['crawlers'] ?? array() ) ),
				array_merge( self::crawler_categories(), array( self::CRAWLER_UNRECOGNISED ) )
			)
		);
		if ( count( $crawlers ) === count( self::crawler_categories() ) + 1 ) {
			$crawlers = array();
		}

		return array(
			'verdicts'   => implode( ',', $verdicts ),
			'clients'    => implode( ',', $clients ),
			'categories' => implode( ',', $categories ),
			'crawlers'   => $crawlers ? self::crawler_filter_hashes( $crawlers ) : '',
			'signals'    => implode( ',', $signals ),
			'cloud_ips'  => in_array( MMSAR_Agent_Log_Signals::CLOUD, $signals, true ) ? self::cloud_filter_ips() : '',
		);
	}

	/**
	 * The stored browser addresses that sit in a cloud range, as a list the queries can take.
	 *
	 * An address that only partly overlaps a range is left out: the filter selects what the signal
	 * says, and for those the signal says "cannot tell".
	 *
	 * @return string Comma-joined addresses, or 'none' when there are none — a value no address
	 *                equals, so the selection narrows to nothing rather than to everything.
	 */
	private static function cloud_filter_ips() {
		$ips = array();
		foreach ( self::distinct_browser_ips() as $ip ) {
			$network = MMSAR_Agent_Log_Signals::cloud_network( $ip );
			if ( '' !== $network && MMSAR_Agent_Log_Signals::CLOUD_PARTIAL !== $network ) {
				$ips[] = $ip;
			}
		}
		return $ips ? implode( ',', $ips ) : 'none';
	}

	/**
	 * Every distinct address stored against a browser row, memoized for the request.
	 *
	 * @return string[]
	 */
	private static function distinct_browser_ips() {
		static $ips = null;
		if ( null === $ips ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
			$ips = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ip FROM %i WHERE client_type = %s', self::table(), self::CLIENT_BROWSER ) );
			$ips = is_array( $ips ) ? array_map( 'strval', $ips ) : array();
		}
		return $ips;
	}

	/**
	 * The stored agent values in the chosen crawler categories, as a list the queries can take.
	 *
	 * A crawler category is not stored — it is derived from the agent value by the same matching
	 * that decides the verdict, so a row logged as a raw user-agent before its crawler was
	 * recognised is categorised too. That derivation lives in PHP, so the filter is resolved here:
	 * the distinct agent values are categorised and the matching ones handed to SQL.
	 *
	 * They are passed as MD5 hashes to `FIND_IN_SET( MD5( agent ), %s )` rather than as the values
	 * themselves, because a raw user-agent routinely contains commas (`KHTML, like Gecko`) and
	 * FIND_IN_SET splits on them. A hash never does, and the SQL stays a fixed string.
	 *
	 * @param string[] $crawlers Chosen categories, validated.
	 * @return string Comma-joined hashes, or 'none' when nothing matches — a value no hash equals,
	 *                so an empty selection narrows to nothing rather than falling back to everything.
	 */
	private static function crawler_filter_hashes( $crawlers ) {
		$hashes = array();
		foreach ( self::distinct_agents() as $agent ) {
			$category = self::crawler_category( $agent );
			$key      = '' === $category ? self::CRAWLER_UNRECOGNISED : $category;
			if ( in_array( $key, $crawlers, true ) ) {
				$hashes[] = md5( $agent );
			}
		}
		return $hashes ? implode( ',', $hashes ) : 'none';
	}

	/**
	 * Every distinct agent value in the log, memoized for the request.
	 *
	 * A few hundred values on a busy site, which is what makes categorising them in PHP cheap.
	 *
	 * @return string[]
	 */
	private static function distinct_agents() {
		static $agents = null;
		if ( null === $agents ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
			$agents = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent FROM %i', self::table() ) );
			$agents = is_array( $agents ) ? array_map( 'strval', $agents ) : array();
		}
		return $agents;
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param int   $per_page Rows per page.
	 * @param int   $offset   Rows to skip.
	 * @param array $filters  Filter set, as accepted by normalize_filters().
	 * @return array[] Entries as associative arrays.
	 */
	public static function get_entries( $per_page = 50, $offset = 0, $filters = array() ) {
		global $wpdb;
		$f   = self::normalize_filters( $filters );
		$cat = self::category_patterns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, surface, detail, agent, ip, verified, verified_at, client_type, signature_agent, same_site
				FROM %i
				WHERE ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface = %s OR ( surface LIKE %s AND ( detail = %s OR detail LIKE %s ) ) THEN 'robots'
				             WHEN surface = %s THEN 'feed'
				             WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( MD5( agent ), %s ) > 0 )
				  AND ( %s = ''
				        OR ( FIND_IN_SET( 'signed', %s ) > 0 AND signature_agent <> '' )
				        OR ( FIND_IN_SET( 'same_site', %s ) > 0 AND same_site = 1 )
				        OR ( client_type = 'browser' AND FIND_IN_SET( ip, %s ) > 0 ) )
				ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table(),
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$cat['robots'],
				$cat['html'],
				$cat['robots_legacy'],
				$cat['robots_query'],
				$cat['feed'],
				$cat['html'],
				$cat['markdown'],
				$cat['notfound'],
				$f['categories'],
				$f['crawlers'],
				$f['crawlers'],
				$f['signals'],
				$f['signals'],
				$f['signals'],
				$f['cloud_ips'],
				absint( $per_page ),
				absint( $offset )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows matching a filter set.
	 *
	 * @param array $filters Filter set, as accepted by normalize_filters().
	 * @return int
	 */
	public static function count_filtered( $filters = array() ) {
		global $wpdb;
		$f   = self::normalize_filters( $filters );
		$cat = self::category_patterns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface = %s OR ( surface LIKE %s AND ( detail = %s OR detail LIKE %s ) ) THEN 'robots'
				             WHEN surface = %s THEN 'feed'
				             WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( MD5( agent ), %s ) > 0 )
				  AND ( %s = ''
				        OR ( FIND_IN_SET( 'signed', %s ) > 0 AND signature_agent <> '' )
				        OR ( FIND_IN_SET( 'same_site', %s ) > 0 AND same_site = 1 )
				        OR ( client_type = 'browser' AND FIND_IN_SET( ip, %s ) > 0 ) )",
				self::table(),
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$cat['robots'],
				$cat['html'],
				$cat['robots_legacy'],
				$cat['robots_query'],
				$cat['feed'],
				$cat['html'],
				$cat['markdown'],
				$cat['notfound'],
				$f['categories'],
				$f['crawlers'],
				$f['crawlers'],
				$f['signals'],
				$f['signals'],
				$f['signals'],
				$f['cloud_ips']
			)
		);
	}

	/**
	 * The most recent requests as visits, newest visit first.
	 *
	 * A visit is one caller's run of requests: the same network and the same declared agent, with
	 * no gap longer than VISIT_GAP between consecutive requests. The declared name is half the
	 * identity because a client that changes what it calls itself partway through is two different
	 * things asking, and merging them would invent a journey nobody made.
	 *
	 * The other half is the *network* rather than the exact address, and that is not an accuracy
	 * that was given up cheaply. This log deliberately stores two different precisions for the same
	 * caller: a page view from a user-agent it does not recognise as a crawler is stored against the
	 * network (see maybe_record_page_view), while that same caller's request for an agent surface
	 * keys through record() and keeps its full address. Grouping on the exact address therefore tore
	 * every unrecognised crawler's visit in half — its HTML page views into one journey and its
	 * llms.txt, .md and MCP requests into another, each looking like a different caller. Measured on
	 * live traffic that was 7 of the agents in a 400-request sample, and it hid the most interesting
	 * journey on the site: one crawler doing full agent discovery interleaved with reading pages,
	 * split into two unremarkable halves.
	 *
	 * Matching on the network reunites them, because the reduced form *is* the network of the full
	 * one. The cost is that two genuinely different callers sharing a network AND an identical
	 * user-agent string merge into one visit; the declared name does most of the work of keeping
	 * them apart, and that pair of coincidences is far rarer than the split it fixes. Nothing about
	 * storage changes: the full address is still recorded, still shown, and is still what
	 * verification runs against.
	 *
	 * Stitched in PHP over a bounded window of rows rather than grouped in SQL. Sessionizing needs
	 * each row's distance from the previous row in the same series, which SQL cannot express without
	 * window functions — unavailable on the MySQL 5.7 this plugin still supports — and the window
	 * is what keeps the work flat as the log grows. See JOURNEY_WINDOW.
	 *
	 * Rows arrive newest-first from the query, are walked oldest-first so each visit reads in the
	 * order the requests happened, and the visits themselves come back newest-first to match every
	 * other view of this log. A visit at the far edge of the window may be truncated — its earlier
	 * requests fell outside — which is why `window_full` is reported alongside.
	 *
	 * @param array  $filters Filter set, as accepted by normalize_filters().
	 * @param string $ip      Restrict to one address. Empty for every address.
	 * @return array{visits: array[], rows: int, window_full: bool}
	 */
	public static function get_journeys( $filters = array(), $ip = '' ) {
		$rows = self::journey_rows( $filters, $ip );
		if ( ! $rows ) {
			return array(
				'visits'      => array(),
				'rows'        => 0,
				'window_full' => false,
			);
		}

		$total = count( $rows );

		// Oldest first from here on: a journey is only a journey in the order it happened.
		$rows = array_reverse( $rows );

		/**
		 * How long a caller may go quiet before its next request starts a new visit.
		 *
		 * @param int $seconds Gap in seconds. Default 1800.
		 */
		$gap = (int) apply_filters( 'mmsar_agent_log_visit_gap', self::VISIT_GAP );
		$gap = $gap > 0 ? $gap : self::VISIT_GAP;

		$visits = array();
		$open   = array();

		foreach ( $rows as $row ) {
			$row_ip  = isset( $row['ip'] ) ? (string) $row['ip'] : '';
			$agent   = isset( $row['agent'] ) ? (string) $row['agent'] : '';
			$network = self::journey_network( $row_ip );
			$key     = $network . '|' . $agent;
			$when    = isset( $row['logged_at'] ) ? (int) strtotime( $row['logged_at'] . ' UTC' ) : 0;

			// A row whose timestamp will not parse cannot be placed in a sequence. Dropping it from
			// the journeys view leaves it visible in the list view, which is the honest outcome:
			// better a visit that omits it than one that claims it happened at the epoch.
			if ( ! $when ) {
				continue;
			}

			if ( isset( $open[ $key ] ) && ( $when - $open[ $key ]['ended'] ) <= $gap ) {
				$index                      = $open[ $key ]['index'];
				$visits[ $index ]['hops'][] = self::journey_hop( $row, $when );
				$visits[ $index ]['ended']  = $when;
				$open[ $key ]['ended']      = $when;

				// Every distinct stored address the visit was seen under, in the order they first
				// appeared. Usually one; two when this is a caller whose page views were reduced to
				// the network and whose agent-surface requests were not. The screen shows them.
				if ( '' !== $row_ip && ! in_array( $row_ip, $visits[ $index ]['addresses'], true ) ) {
					$visits[ $index ]['addresses'][] = $row_ip;
				}

				// A verdict is recorded per row and the rows of one visit can disagree — an
				// address checked later, or re-checked. The most decisive answer is the one the
				// visit is labelled with; see journey_verdict_rank().
				if ( self::journey_verdict_rank( $row ) > self::journey_verdict_rank( $visits[ $index ]['sample'] ) ) {
					$visits[ $index ]['sample'] = $row;
				}
				continue;
			}

			$visits[]     = array(
				'agent'     => $agent,
				'ip'        => $row_ip,
				'network'   => $network,
				'addresses' => '' === $row_ip ? array() : array( $row_ip ),
				'started'   => $when,
				'ended'     => $when,
				'sample'    => $row,
				'hops'      => array( self::journey_hop( $row, $when ) ),
			);
			$open[ $key ] = array(
				'index' => count( $visits ) - 1,
				'ended' => $when,
			);
		}

		// Newest visit first, matching every other view of this log. Ties broken on the end time so
		// two visits that began in the same second order by which was still going.
		usort(
			$visits,
			static function ( $a, $b ) {
				if ( $a['started'] === $b['started'] ) {
					return $b['ended'] <=> $a['ended'];
				}
				return $b['started'] <=> $a['started'];
			}
		);

		return array(
			'visits'      => $visits,
			'rows'        => $total,
			'window_full' => $total >= self::JOURNEY_WINDOW,
		);
	}

	/**
	 * One request inside a visit.
	 *
	 * @param array $row  Log row.
	 * @param int   $when Unix time of the request.
	 * @return array
	 */
	private static function journey_hop( $row, $when ) {
		return array(
			'when'        => $when,
			'surface'     => isset( $row['surface'] ) ? (string) $row['surface'] : '',
			'detail'      => isset( $row['detail'] ) ? (string) $row['detail'] : '',
			'client_type' => isset( $row['client_type'] ) ? (string) $row['client_type'] : '',
			'ip'          => isset( $row['ip'] ) ? (string) $row['ip'] : '',
		);
	}

	/**
	 * The network a stored address belongs to, as the journeys view groups on.
	 *
	 * This reuses anonymize_ip() rather than reimplementing it: that is the same reduction the log applies
	 * when storing a page view, so feeding it an address that is already reduced returns that same
	 * value and the two halves of a split visit land on one key. It also normalizes through
	 * inet_pton/inet_ntop, so two spellings of one IPv6 address group together.
	 *
	 * Falls back to the raw value when the address will not parse. Returning the empty string there
	 * would file every unparseable row under one key and invent a visit out of unrelated callers.
	 *
	 * @param string $ip Stored address.
	 * @return string Grouping key for that address.
	 */
	private static function journey_network( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		$network = self::anonymize_ip( $ip );
		return '' === $network ? $ip : $network;
	}

	/**
	 * How much a row's verification verdict tells the reader, as a sortable rank.
	 *
	 * Used to pick which of a visit's rows labels the whole visit. `failed` outranks everything
	 * because it is the only verdict that says something was actively misrepresented, and a visit
	 * containing one forged claim is a visit worth looking at whatever its other rows say. Below
	 * that, a reached verdict beats an unreached one, and anything beats not having looked yet.
	 *
	 * @param array $row Log row.
	 * @return int
	 */
	private static function journey_verdict_rank( $row ) {
		$verdict = isset( $row['verified'] ) ? (string) $row['verified'] : '';
		switch ( $verdict ) {
			case MMSAR_Agent_Log_Verify::FAILED:
				return 4;
			case MMSAR_Agent_Log_Verify::VERIFIED:
				return 3;
			case MMSAR_Agent_Log_Verify::UNCLAIMED:
				return 2;
			case '':
				return 0;
			default:
				return 1;
		}
	}

	/**
	 * The window of recent rows the journeys view is stitched from, newest first.
	 *
	 * The same filter clause as get_entries(), so ticking a filter narrows both views the same way
	 * and the journeys shown are made of the requests the list view would show. One extra test for
	 * a single caller, which is what the IP links in the list view lead to.
	 *
	 * That test matches the address's whole network, not the address alone, for the same reason
	 * get_journeys() groups on the network: the caller's own requests are stored at two precisions,
	 * so an exact match would hand this view half a visit and then split what is left. Following a
	 * link from one address is a request to see *that caller*, and this is what that means.
	 *
	 * @param array  $filters Filter set, as accepted by normalize_filters().
	 * @param string $ip      Restrict to this address and its network. Empty for every address.
	 * @return array[]
	 */
	private static function journey_rows( $filters, $ip ) {
		global $wpdb;
		$f   = self::normalize_filters( $filters );
		$cat = self::category_patterns();
		$ip  = (string) $ip;

		// The network as a LIKE prefix, which is the reduced address with its last character
		// removed. anonymize_ip() always ends a network in a separator plus one filler — '.0' for
		// IPv4, '::' for IPv6 — so dropping one character leaves exactly the prefix every address
		// in that network shares: '203.0.113.0' becomes '203.0.113.', and '2001:db8:1:2::' becomes
		// '2001:db8:1:2:'. Both keep a trailing separator, which is what stops them reaching into a
		// neighbouring network: '203.0.113.' cannot match 203.0.1130.x (not an address), and
		// '2001:db8:1:2:' cannot match 2001:db8:1:20:: because the fifth character of that group is
		// '0' where the prefix requires ':'.
		//
		// Keeping the colon matters for the IPv6 case specifically: the network itself ends '::',
		// and a full address in it does not, so a prefix built from the unmodified network would
		// match only the reduced form — the exact split this is here to heal.
		//
		// An address that will not reduce has no network, so the prefix falls back to the address
		// and the LIKE simply restates the exact test beside it.
		$net_like = '';
		if ( '' !== $ip ) {
			$network  = self::anonymize_ip( $ip );
			$prefix   = '' === $network ? $ip : substr( $network, 0, -1 );
			$net_like = $wpdb->esc_like( $prefix ) . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, surface, detail, agent, ip, verified, verified_at, client_type, signature_agent, same_site
				FROM %i
				WHERE ( %s = '' OR ip = %s OR ip LIKE %s )
				  AND ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface = %s OR ( surface LIKE %s AND ( detail = %s OR detail LIKE %s ) ) THEN 'robots'
				             WHEN surface = %s THEN 'feed'
				             WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( MD5( agent ), %s ) > 0 )
				  AND ( %s = ''
				        OR ( FIND_IN_SET( 'signed', %s ) > 0 AND signature_agent <> '' )
				        OR ( FIND_IN_SET( 'same_site', %s ) > 0 AND same_site = 1 )
				        OR ( client_type = 'browser' AND FIND_IN_SET( ip, %s ) > 0 ) )
				ORDER BY id DESC LIMIT %d",
				self::table(),
				$ip,
				$ip,
				$net_like,
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$cat['robots'],
				$cat['html'],
				$cat['robots_legacy'],
				$cat['robots_query'],
				$cat['feed'],
				$cat['html'],
				$cat['markdown'],
				$cat['notfound'],
				$f['categories'],
				$f['crawlers'],
				$f['crawlers'],
				$f['signals'],
				$f['signals'],
				$f['signals'],
				$f['cloud_ips'],
				self::JOURNEY_WINDOW
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Request counts by client type across the whole log.
	 *
	 * @return array<string, int>
	 */
	public static function get_client_type_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT client_type, COUNT(*) AS requests FROM %i GROUP BY client_type', self::table() ),
			ARRAY_A
		);

		$counts               = array_fill_keys( self::client_types(), 0 );
		$counts['unrecorded'] = 0;
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['client_type'] ) && '' !== $row['client_type'] ? (string) $row['client_type'] : 'unrecorded';
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] += (int) $row['requests'];
			}
		}
		return $counts;
	}

	/**
	 * The three browser signals, counted per client type over the whole log.
	 *
	 * One grouped read by client type and address, then the cloud signal worked out per distinct
	 * address in PHP, because it is derived rather than stored. The cloud counts are for browser
	 * rows only — see MMSAR_Agent_Log_Signals::for_row() — so they are zero on every other type.
	 *
	 * `same_site_recorded` is how many rows carry the Referer signal at all: rows from before 1.48.0
	 * hold NULL, and a share of same-site rows is only meaningful over the recorded ones.
	 *
	 * @return array{by_client: array, signed_by: array[]}
	 */
	public static function get_signal_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_type, ip, COUNT(*) AS requests,
					SUM(CASE WHEN signature_agent <> '' THEN 1 ELSE 0 END) AS signed,
					SUM(CASE WHEN same_site = 1 THEN 1 ELSE 0 END) AS same_site,
					SUM(CASE WHEN same_site IS NOT NULL THEN 1 ELSE 0 END) AS same_site_recorded
				FROM %i GROUP BY client_type, ip",
				self::table()
			),
			ARRAY_A
		);

		$blank = array(
			'requests'           => 0,
			'signed'             => 0,
			'same_site'          => 0,
			'same_site_recorded' => 0,
			'cloud_network'      => 0,
			'cloud_partial'      => 0,
			'by_cloud_provider'  => array(),
		);
		$by    = array();
		foreach ( array_merge( self::client_types(), array( 'unrecorded' ) ) as $type ) {
			$by[ $type ] = $blank;
		}

		foreach ( (array) $rows as $row ) {
			$type = isset( $row['client_type'] ) && '' !== $row['client_type'] ? (string) $row['client_type'] : 'unrecorded';
			if ( ! isset( $by[ $type ] ) ) {
				continue;
			}
			$requests                           = (int) $row['requests'];
			$by[ $type ]['requests']           += $requests;
			$by[ $type ]['signed']             += (int) $row['signed'];
			$by[ $type ]['same_site']          += (int) $row['same_site'];
			$by[ $type ]['same_site_recorded'] += (int) $row['same_site_recorded'];

			if ( self::CLIENT_BROWSER !== $type ) {
				continue;
			}
			$network = MMSAR_Agent_Log_Signals::cloud_network( (string) $row['ip'] );
			if ( MMSAR_Agent_Log_Signals::CLOUD_PARTIAL === $network ) {
				$by[ $type ]['cloud_partial'] += $requests;
			} elseif ( '' !== $network ) {
				$by[ $type ]['cloud_network'] += $requests;
				if ( ! isset( $by[ $type ]['by_cloud_provider'][ $network ] ) ) {
					$by[ $type ]['by_cloud_provider'][ $network ] = 0;
				}
				$by[ $type ]['by_cloud_provider'][ $network ] += $requests;
			}
		}
		foreach ( $by as $type => $counts ) {
			arsort( $counts['by_cloud_provider'] );
			// An empty map serialises to [] rather than {}; keep it an object in the JSON.
			$by[ $type ]['by_cloud_provider'] = (object) $counts['by_cloud_provider'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$signed_by = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT signature_agent, COUNT(*) AS requests, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i WHERE signature_agent <> '' GROUP BY signature_agent ORDER BY requests DESC LIMIT 25",
				self::table()
			),
			ARRAY_A
		);

		return array(
			'by_client' => $by,
			'signed_by' => self::int_columns( $signed_by, array( 'requests' ) ),
		);
	}

	/**
	 * Request counts by surface category, across every client.
	 *
	 * @return array<string, int>
	 */
	public static function get_category_counts() {
		$counts = array();
		foreach ( self::categories() as $category ) {
			$counts[ $category ] = self::count_filtered(
				array(
					'clients'    => array_merge( self::client_types(), array( 'unrecorded' ) ),
					'categories' => array( $category ),
				)
			);
		}
		return $counts;
	}

	/**
	 * The distinct surfaces inside one category, most-requested first.
	 *
	 * Exists because `docs` is the residual category — everything that is not an HTML page view, a
	 * markdown response, a 404, robots.txt or a feed — so its label cannot say what is in it, and a reader ticking
	 * "Agent documents" has no way to find out short of reading the source. Answering that from the
	 * log itself rather than from a hand-written list is the point: a surface added in a later
	 * version becomes a document by default, and a fixed list would quietly stop being true the
	 * first time one is.
	 *
	 * The same CASE expression as every other category query, for the same reason — one definition
	 * of what a category is, rather than a second copy here that can drift from it.
	 *
	 * @param string $category Category value, from categories().
	 * @param int    $limit    Maximum distinct surfaces to return.
	 * @return array[] Rows of `surface` and `total`, descending by total.
	 */
	public static function get_surfaces_in_category( $category, $limit = 40 ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array();
		}
		$cat = self::category_patterns();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would describe a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT surface, COUNT(*) AS total
				FROM %i
				WHERE CASE WHEN surface = %s OR ( surface LIKE %s AND ( detail = %s OR detail LIKE %s ) ) THEN 'robots'
				           WHEN surface = %s THEN 'feed'
				           WHEN surface LIKE %s THEN 'html'
				           WHEN surface LIKE %s THEN 'markdown'
				           WHEN surface LIKE %s THEN 'notfound'
				           ELSE 'docs' END = %s
				GROUP BY surface
				ORDER BY total DESC, surface ASC
				LIMIT %d",
				self::table(),
				$cat['robots'],
				$cat['html'],
				$cat['robots_legacy'],
				$cat['robots_query'],
				$cat['feed'],
				$cat['html'],
				$cat['markdown'],
				$cat['notfound'],
				(string) $category,
				absint( $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Paths that produced a 404, most-asked-for first.
	 *
	 * A count of 404s says agents are asking for something that is not there; only the paths say
	 * what, and only the paths side by side show a crawler working through a URL pattern the site
	 * could support rather than failing at random.
	 *
	 * **These counts are a sample, not a census, and the caller must say so.** The five-minute
	 * write throttle keys on agent + surface + IP and deliberately excludes the path, so one 404
	 * path per agent and address lands per window rather than every one. That is on purpose — a
	 * caller-supplied path is unbounded, and keying the throttle on it would let anything walking a
	 * URL list write a row per request. The consequence is that a path guessed twenty times in a
	 * minute appears once, so these totals rank what is being asked for; they do not measure it.
	 *
	 * @param int $limit Maximum distinct paths to return.
	 * @return array[] Rows of `path`, `total`, `agents` and `last_seen`.
	 */
	public static function get_notfound_paths( $limit = 30 ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array();
		}
		$like_404 = $wpdb->esc_like( '404' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT detail AS path,
				        COUNT(*) AS total,
				        COUNT( DISTINCT agent ) AS agents,
				        MAX( logged_at ) AS last_seen
				FROM %i
				WHERE surface LIKE %s AND detail <> ''
				GROUP BY detail
				ORDER BY total DESC, last_seen DESC
				LIMIT %d",
				self::table(),
				$like_404,
				absint( $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether the log table has been created yet.
	 *
	 * The table is only created once the agent log is switched on, so anything that queries it
	 * outside a serve path has to cope with it being absent. Checked with SHOW TABLES rather than
	 * by catching an error, so a site with the log off never emits a database error at all.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for this plugin's own table; nothing to cache usefully.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Distinct (ip, agent) pairs that have not been verified yet, newest first.
	 *
	 * Grouped rather than listed per row because the work is one DNS resolution per address: this
	 * site's own log is 634 addresses across 1,584 rows, and a single crawler sweep can be 41 rows
	 * from 41 different addresses. Paired with the agent because the verdict is about a *claim* —
	 * the same address arriving as ClaudeBot and as GPTBot is two claims and can be one forgery
	 * and one genuine crawl.
	 *
	 * Newest first so a freshly arrived crawler is judged while its rDNS assignment is still the
	 * one it used, which is the verdict worth the most.
	 *
	 * @param int $limit Maximum pairs to return.
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_unverified_pairs( $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would re-resolve rows already decided.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ip, agent, MAX(id) AS newest FROM %i
				WHERE verified = %s
				   OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) )
				GROUP BY ip, agent ORDER BY newest DESC LIMIT %d',
				self::table(),
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff(),
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The UTC datetime before which a `nodns` row is due another attempt.
	 *
	 * @return string `Y-m-d H:i:s`.
	 */
	private static function nodns_retry_cutoff() {
		return gmdate( 'Y-m-d H:i:s', time() - MMSAR_Agent_Log_Verify::RETRY_NODNS_AFTER );
	}

	/**
	 * Writes a verdict to every unverified row sharing an address and claimed agent.
	 *
	 * Scoped to rows still at the pending value, so a verdict reached now can never overwrite one
	 * reached earlier — an older row keeps the `verified_at` it was actually judged at, which is
	 * the whole point of storing that column.
	 *
	 * @param string $ip      Client IP.
	 * @param string $agent   Claimed agent.
	 * @param string $verdict Verdict to store.
	 * @return int Rows updated.
	 */
	public static function apply_verdict( $ip, $agent, $verdict ) {
		global $wpdb;

		// Writable states are exactly two: never judged, and judged `nodns` long enough ago to be
		// due another attempt. `verified`, `failed` and `unverifiable` are untouchable here, which
		// is what stops a later pass quietly rewriting a conclusion reached against better data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET verified = %s, verified_at = %s
				WHERE ip = %s AND agent = %s
				  AND ( verified = %s
				        OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) ) )',
				self::table(),
				mb_substr( (string) $verdict, 0, 12 ),
				current_time( 'mysql', true ),
				(string) $ip,
				(string) $agent,
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff()
			)
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * The verdicts a re-check reconsiders: the ones that mean "could not decide".
	 *
	 * `verified` and `failed` are deliberately absent. Both are conclusions, and re-running them
	 * risks overwriting a sound verdict reached against better data than today's — a `verified`
	 * from a live range file should not become `failed` because a bundled snapshot has since aged.
	 * The undecided two are the ones that turn into information when the suffix map or the range
	 * data improves, which is the only reason to re-check at all.
	 *
	 * @return string[]
	 */
	public static function recheckable_verdicts() {
		return array( MMSAR_Agent_Log_Verify::NODNS, MMSAR_Agent_Log_Verify::UNVERIFIABLE );
	}

	/**
	 * Distinct (ip, agent) pairs currently holding an undecided verdict.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_undecided_pairs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified IN ( %s, %s )',
				self::table(),
				MMSAR_Agent_Log_Verify::NODNS,
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Distinct (ip, agent) pairs whose verdict could actually come out differently today.
	 *
	 * Undecided is not the same as re-checkable, and conflating them offers a button that provably
	 * cannot change anything. Two cases qualify:
	 *
	 * - **`nodns`**, always. The resolver failing is a transient condition by definition, and this
	 *   is the one verdict the design promises to retry.
	 * - **`unverifiable`, but only where the claimed agent now has a method.** That verdict records
	 *   that this plugin knew no way to check the operator, so the only thing that can change it is
	 *   the plugin learning one. Where it still has not — `meta-externalagent`, `YouBot`,
	 *   `Bytespider`, `CCBot` at the time of writing — re-running produces `unverifiable` again,
	 *   every time, for as long as nobody publishes a method.
	 * - **`unclaimed`, on the same condition.** This verdict means no *recognised* name was found
	 *   in the user-agent, and the set of recognised names grows with the plugin: every request a
	 *   newly-added crawler made before it was added is sitting in the log reading `unclaimed`, and
	 *   nothing retries it. LinkupBot is the case that exposed this — 326 rows on one site, all
	 *   answerable the moment the name was added, none of them reopened. The `has_method()` test
	 *   below is what keeps this from meaning "recheck everything": the overwhelming majority of
	 *   `unclaimed` rows are browsers and unbranded tools that claim nothing, and they fail it.
	 *
	 * The second test cannot be done in SQL: whether an operator is covered is a fact about the
	 * suffix map and the bundled range data, both of which live in PHP and are filterable. So the
	 * agents are pulled distinct and filtered here, which is a handful of rows rather than a scan.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_recheckable_pairs() {
		global $wpdb;

		// `unverifiable` only. `nodns` used to be in here, which is why a button reading
		// "Re-check 1" sat on screen permanently for an address with no reverse record: the button
		// was the only thing that ever retried that verdict. It is retried by the ordinary pass now
		// (see get_unverified_pairs), so the button is left with the one job a person is actually
		// needed for — reopening rows that became answerable because this plugin learned a new
		// operator, which is not something the plugin can detect about itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified IN ( %s, %s )',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE,
				MMSAR_Agent_Log_Verify::UNCLAIMED
			),
			ARRAY_A
		);

		$pairs = array();
		foreach ( (array) $rows as $pair ) {
			$agent   = isset( $pair['agent'] ) ? (string) $pair['agent'] : '';
			$pair_ip = isset( $pair['ip'] ) ? (string) $pair['ip'] : '';
			if ( ! MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				continue;
			}

			// A row stored at network precision can never reach a verdict — verdict_for() withholds
			// one rather than risk accusing a real crawler on evidence that was reduced at storage
			// time. Offering it for re-check would put a count on screen that no number of presses
			// could ever clear, which is the same emptiness this method exists to filter out.
			if ( '' === $pair_ip || self::anonymize_ip( $pair_ip ) === $pair_ip ) {
				continue;
			}

			$pairs[] = $pair;
		}
		return $pairs;
	}

	/**
	 * Rows stored under one exact agent value with one verdict.
	 *
	 * @param string $agent   Agent value as stored.
	 * @param string $verdict Verdict.
	 * @return int
	 */
	public static function count_verdict_for_agent( $agent, $verdict ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE agent = %s AND verified = %s',
				self::table(),
				(string) $agent,
				(string) $verdict
			)
		);
		return (int) $count;
	}

	/**
	 * Agents holding an `unverifiable` verdict that this release still cannot check.
	 *
	 * Surfaced on screen so the absence of a re-check button is explained rather than merely
	 * observed: these entries are not stuck, they are answered, and the answer is "nobody publishes
	 * a way to confirm this crawler".
	 *
	 * @return array<string, int> Agent name => row count, busiest first.
	 */
	public static function get_uncheckable_agents() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, COUNT(*) AS requests FROM %i WHERE verified = %s GROUP BY agent ORDER BY requests DESC',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agent = isset( $row['agent'] ) ? (string) $row['agent'] : '';
			if ( '' !== $agent && ! MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				$out[ $agent ] = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			}
		}
		return $out;
	}

	/**
	 * How many rows a re-check would actually reconsider.
	 *
	 * Counted per agent with a fixed set of placeholders rather than by building an `IN` list.
	 * A generated placeholder string is safe here — it is derived from a count, never from data —
	 * but it cannot be read as safe without tracing where the count comes from, and the WordPress.org
	 * review re-scans without honouring inline suppressions. The distinct re-checkable agents are a
	 * handful, so a short loop of fully static queries costs nothing and needs no explaining.
	 *
	 * @return int
	 */
	public static function count_recheckable() {
		global $wpdb;

		$total = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE verified IN ( %s, %s ) AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					MMSAR_Agent_Log_Verify::UNCLAIMED,
					$agent
				)
			);
		}
		return $total;
	}

	/**
	 * Rows still awaiting an identity check, grouped by the crawler they claimed.
	 *
	 * The verification panel on the log screen reports one total, which answers "is there a
	 * backlog" but not "a backlog of what". On a dashboard widget the second question is the more
	 * useful one: fifty pending rows all claiming one crawler is a different situation from fifty
	 * spread across twenty, and it is the difference between pressing the button and going to look.
	 *
	 * Counts rows, not addresses, so the numbers here add up to the pending total shown elsewhere
	 * rather than to the number of addresses a pass would resolve.
	 *
	 * @param int $limit How many of the most frequent agents to return.
	 * @return array<string, int> Agent name => pending rows, largest first.
	 */
	public static function pending_by_agent( $limit = 5 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, COUNT(*) AS requests FROM %i WHERE verified = %s GROUP BY agent ORDER BY requests DESC LIMIT %d',
				self::table(),
				MMSAR_Agent_Log_Verify::PENDING,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agent = ( isset( $row['agent'] ) && '' !== $row['agent'] )
				? (string) $row['agent']
				: __( 'Unnamed', 'make-my-site-agent-ready' );

			$out[ $agent ] = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
		}
		return $out;
	}

	/**
	 * The distinct agents a re-check could answer differently.
	 *
	 * @return string[]
	 */
	private static function recheckable_agents() {
		$agents = array();
		foreach ( self::get_recheckable_pairs() as $pair ) {
			$agents[ (string) $pair['agent'] ] = true;
		}
		return array_keys( $agents );
	}

	/**
	 * Returns every undecided row to the pending state so a later pass judges it again.
	 *
	 * Scoped to the undecided verdicts, so a re-check can never disturb a `verified` or a `failed`.
	 * `verified_at` is cleared along with the verdict rather than kept: the row is about to be
	 * judged again, and leaving the old timestamp would date the new verdict to when the *previous*
	 * one was reached, which is the one thing that column exists to prevent.
	 *
	 * @return int Rows reset.
	 */
	public static function reset_undecided() {
		global $wpdb;

		$reset = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
			$rows   = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET verified = %s, verified_at = NULL WHERE verified IN ( %s, %s ) AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::PENDING,
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					MMSAR_Agent_Log_Verify::UNCLAIMED,
					$agent
				)
			);
			$reset += is_numeric( $rows ) ? (int) $rows : 0;
		}
		return $reset;
	}

	/**
	 * Counts per verdict across the whole log, plus how many rows are still undecided.
	 *
	 * `pending` is the number that stops the rest being misread. Verdict counts over a partially
	 * verified log describe the part that has been checked and nothing else, and a reader with no
	 * pending count has no way to tell a quiet result from an unfinished one.
	 *
	 * @return array{counts: array<string, int>, pending: int, verified_first: string, verified_last: string}
	 */
	public static function get_verification_summary() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT verified, COUNT(*) AS requests FROM %i GROUP BY verified', self::table() ),
			ARRAY_A
		);

		$counts  = array_fill_keys( MMSAR_Agent_Log_Verify::verdicts(), 0 );
		$pending = 0;
		foreach ( (array) $rows as $row ) {
			$verdict = isset( $row['verified'] ) ? (string) $row['verified'] : '';
			$count   = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			if ( MMSAR_Agent_Log_Verify::PENDING === $verdict ) {
				$pending += $count;
				continue;
			}
			if ( isset( $counts[ $verdict ] ) ) {
				$counts[ $verdict ] += $count;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$span = $wpdb->get_row(
			$wpdb->prepare( 'SELECT MIN(verified_at) AS first_at, MAX(verified_at) AS last_at FROM %i WHERE verified_at IS NOT NULL', self::table() ),
			ARRAY_A
		);

		return array(
			'counts'         => $counts,
			'pending'        => $pending,
			'verified_first' => isset( $span['first_at'] ) ? (string) $span['first_at'] : '',
			'verified_last'  => isset( $span['last_at'] ) ? (string) $span['last_at'] : '',
		);
	}

	/**
	 * The path of the current request, decoded and reduced to something safe to store.
	 *
	 * Shared rather than duplicated: a 404 path and a permalink path both end up in a column an
	 * administrator reads on screen and exports to CSV, and both want the same treatment. Lived in
	 * MMSAR_Not_Found until 1.24.0, which is where its reasoning is written up.
	 *
	 * The query string is dropped. It is rarely the interesting half of a URL, and leaving it out
	 * keeps arbitrary caller-supplied text — tracking parameters, injection attempts, scanner junk
	 * — out of that column.
	 *
	 * @param string $url Optional URL or path to reduce. Defaults to this request's own URI.
	 * @return string Leading-slash path, or '/' when there is nothing to report.
	 */
	public static function request_path( $url = '' ) {
		if ( '' === $url ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the request path for a log annotation, not a state change.
			$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		}
		if ( '' === $url ) {
			return '/';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '/';
		}

		// Percent-decoded so the stored value is the path as the caller meant it, then stripped of
		// control characters, which is the actual hazard: this string is rendered on an admin screen
		// and written to a CSV export, and a decoded path can carry NUL, newlines or terminal escapes.
		//
		// Only control characters. Until 1.24.0 this stripped everything outside printable ASCII,
		// which was fine while the only caller was a 404 path but is wrong now that permalinks come
		// through here: on a site with accented or non-Latin slugs it would reduce /café/ and /cafè/
		// to the same value, and two different posts would share one by_detail row. Letters are not
		// the danger — esc_html() handles the screen and csv_cell() handles the spreadsheet.
		$path  = rawurldecode( $path );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		$path  = null === $clean ? '' : $clean;

		// Percent-decoding can produce a byte sequence that is not valid UTF-8, which would be
		// rejected on the way into a utf8mb4 column and lost entirely. Fall back to ASCII-only for
		// those rather than storing nothing.
		if ( '' !== $path && ! mb_check_encoding( $path, 'UTF-8' ) ) {
			$clean = preg_replace( '/[^\x20-\x7E]/', '', $path );
			$path  = null === $clean ? '' : $clean;
		}

		return '' === $path ? '/' : '/' . ltrim( $path, '/' );
	}

	/**
	 * A batch of entries older than a given id, newest first.
	 *
	 * Used for walking the whole log — an export, or anything else that reads every row. Paging by
	 * id rather than by OFFSET matters here because the log is appended to while the walk is in
	 * progress: with OFFSET, every row inserted mid-walk shifts the window and the reader sees a
	 * row twice or skips one. An id cursor addresses rows rather than positions, so newly appended
	 * rows are simply not part of the walk, and the query stays fast at any depth.
	 *
	 * @param int   $before_id Return rows with a lower id than this. 0 starts from the newest row.
	 * @param int   $limit     Maximum rows to return.
	 * @param array $filters   Filter set, as accepted by normalize_filters(), so an export can be
	 *                         restricted to what the screen is currently showing.
	 * @return array[] Entries as associative arrays, including id.
	 */
	public static function get_entries_before( $before_id = 0, $limit = 500, $filters = array() ) {
		global $wpdb;
		$before_id = absint( $before_id );
		$limit     = absint( $limit );
		$f         = self::normalize_filters( $filters );
		$cat       = self::category_patterns();

		// A cursor of 0 means "start from the newest", expressed as an id above anything real so the
		// same fixed statement serves the first batch and every later one.
		$cursor = $before_id > 0 ? $before_id : PHP_INT_MAX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, logged_at, surface, detail, agent, ip, verified, verified_at, client_type, signature_agent, same_site
				FROM %i
				WHERE id < %d
				  AND ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface = %s OR ( surface LIKE %s AND ( detail = %s OR detail LIKE %s ) ) THEN 'robots'
				             WHEN surface = %s THEN 'feed'
				             WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( MD5( agent ), %s ) > 0 )
				  AND ( %s = ''
				        OR ( FIND_IN_SET( 'signed', %s ) > 0 AND signature_agent <> '' )
				        OR ( FIND_IN_SET( 'same_site', %s ) > 0 AND same_site = 1 )
				        OR ( client_type = 'browser' AND FIND_IN_SET( ip, %s ) > 0 ) )
				ORDER BY id DESC LIMIT %d",
				self::table(),
				$cursor,
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$cat['robots'],
				$cat['html'],
				$cat['robots_legacy'],
				$cat['robots_query'],
				$cat['feed'],
				$cat['html'],
				$cat['markdown'],
				$cat['notfound'],
				$f['categories'],
				$f['crawlers'],
				$f['crawlers'],
				$f['signals'],
				$f['signals'],
				$f['signals'],
				$f['cloud_ips'],
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate counts over the whole log.
	 *
	 * The log answers "which agents fetch what", and answering it from a page of raw rows means
	 * reading every page and tallying by hand. These are the tallies, computed by the database in
	 * one pass each, so a caller that only wants the shape of the traffic never has to pull the
	 * rows at all.
	 *
	 * Datetimes are UTC, as stored.
	 *
	 * @param int $top  Maximum rows in the by-agent, by-surface and by-detail breakdowns.
	 * @param int $days Maximum rows in the by-day breakdown, most recent first.
	 * @return array Aggregates.
	 */
	public static function get_summary( $top = 25, $days = 60 ) {
		global $wpdb;
		$table = self::table();
		$top   = max( 1, absint( $top ) );
		$days  = max( 1, absint( $days ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT agent) AS unique_agents, COUNT(DISTINCT ip) AS unique_ips, MIN(logged_at) AS first_logged_at, MAX(logged_at) AS last_logged_at FROM %i',
				$table
			),
			ARRAY_A
		);

		// The verdict columns are the point of this breakdown as of 1.24.0. A `requests` figure on
		// its own is what the last analysis of this log had to work with, and it was wrong: GPTBot
		// looked like the best customer here at 74% of its requests hitting agent surfaces, and
		// most of those requests were a readiness scanner wearing its name. `verified` and `failed`
		// side by side on the same row is what makes that visible without cross-tabbing by hand.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_agent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, COUNT(*) AS requests, COUNT(DISTINCT surface) AS surfaces, COUNT(DISTINCT ip) AS unique_ips,
					SUM(CASE WHEN verified = 'verified' THEN 1 ELSE 0 END) AS verified,
					SUM(CASE WHEN verified = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN verified = 'client' THEN 1 ELSE 0 END) AS client,
					SUM(CASE WHEN verified = 'unverifiable' THEN 1 ELSE 0 END) AS unverifiable,
					SUM(CASE WHEN verified = 'unclaimed' THEN 1 ELSE 0 END) AS unclaimed,
					SUM(CASE WHEN verified = 'nodns' THEN 1 ELSE 0 END) AS nodns,
					SUM(CASE WHEN verified = '' THEN 1 ELSE 0 END) AS pending,
					MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen
				FROM %i GROUP BY agent ORDER BY requests DESC, agent ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_surface = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT surface, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i GROUP BY surface ORDER BY requests DESC, surface ASC LIMIT %d',
				$table,
				$top
			),
			ARRAY_A
		);

		// Only rows that carry a detail, because on every other surface the surface name already is
		// the whole request and a blank row here would say nothing. Grouped by the pair rather than
		// by detail alone: "/api/v2/products" means one thing under a 404 and another under an MCP
		// call, and merging them would invent a total that describes neither.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_detail = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT surface, detail, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i WHERE detail <> '' GROUP BY surface, detail ORDER BY requests DESC, detail ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(logged_at) AS day, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents FROM %i GROUP BY day ORDER BY day DESC LIMIT %d',
				$table,
				$days
			),
			ARRAY_A
		);

		$by_agent = self::int_columns( $by_agent, array( 'requests', 'surfaces', 'unique_ips', 'verified', 'failed', 'client', 'unverifiable', 'unclaimed', 'nodns', 'pending' ) );
		foreach ( $by_agent as $i => $row ) {
			$by_agent[ $i ]['crawler_category'] = self::crawler_category( isset( $row['agent'] ) ? (string) $row['agent'] : '' );
		}

		return array(
			'total'           => isset( $totals['total'] ) ? (int) $totals['total'] : 0,
			'unique_agents'   => isset( $totals['unique_agents'] ) ? (int) $totals['unique_agents'] : 0,
			'unique_ips'      => isset( $totals['unique_ips'] ) ? (int) $totals['unique_ips'] : 0,
			'first_logged_at' => isset( $totals['first_logged_at'] ) ? (string) $totals['first_logged_at'] : '',
			'last_logged_at'  => isset( $totals['last_logged_at'] ) ? (string) $totals['last_logged_at'] : '',
			'by_agent'        => $by_agent,
			'by_surface'      => self::int_columns( $by_surface, array( 'requests', 'agents' ) ),
			'by_detail'       => self::int_columns( $by_detail, array( 'requests', 'agents' ) ),
			'by_day'          => self::int_columns( $by_day, array( 'requests', 'agents' ) ),
		);
	}

	/**
	 * Casts the named columns of a result set to integers.
	 *
	 * MySQL hands back counts as numeric strings. Left alone they serialize into JSON as "12"
	 * rather than 12, which is the wrong type for an output schema that says integer.
	 *
	 * @param array[]  $rows    Result rows.
	 * @param string[] $columns Column names to cast.
	 * @return array[] Rows with those columns cast.
	 */
	private static function int_columns( $rows, $columns ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			foreach ( $columns as $column ) {
				if ( isset( $row[ $column ] ) ) {
					$rows[ $index ][ $column ] = (int) $row[ $column ];
				}
			}
		}
		return $rows;
	}

	/**
	 * Deletes every entry.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying this plugin's own table on explicit request.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Drops rows beyond the retention limit, oldest first.
	 *
	 * @return void
	 */
	public static function prune() {
		$limit = self::get_limit();
		if ( $limit < 1 ) {
			return;
		}

		global $wpdb;
		// %i is the identifier placeholder, so the table name goes through prepare() like any other
		// value rather than being interpolated into the query string.
		$table = self::table();

		// The id of the newest row already outside the limit. Everything at or below it goes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would prune against a stale count.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $limit ) );
		if ( ! $cutoff ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $cutoff ) );
	}

	/**
	 * Surface categories, for answering "did anything read the agent-facing documents".
	 *
	 * Derived from the surface name rather than stored, because the plugin generates every surface
	 * string and the three non-document families each share a fixed prefix. That keeps the SQL fully
	 * static: three `LIKE` prefixes, and documents are what is left over. A surface added later is
	 * therefore a document by default, which is the right way round.
	 *
	 * **robots.txt and feeds are neither (1.49.0).** Both are machine-readable, so left to the
	 * residual rule they would have been counted as agent documents and inflated the number this
	 * log exists to measure; and until then robots.txt was being counted as an HTML page view,
	 * which skewed the other side of the same comparison. Each is its own category, matched by an
	 * exact surface name, ahead of the HTML test. Rows recorded before 1.49.0 as an HTML page view
	 * of `/robots.txt` are *categorised* as robots.txt without being rewritten: the stored surface
	 * keeps what was recorded, and the category is derived, as it always was. See the decisions log.
	 */
	const CAT_DOCS     = 'docs';
	const CAT_MARKDOWN = 'markdown';
	const CAT_HTML     = 'html';
	const CAT_NOTFOUND = 'notfound';
	const CAT_ROBOTS   = 'robots';
	const CAT_FEED     = 'feed';

	/**
	 * The stored surface names for the two machine-readable files that are not agent documents.
	 */
	const SURFACE_ROBOTS = 'robots.txt';
	const SURFACE_FEED   = 'Feed';

	/**
	 * Feed readers that are software a person installs, not a service one operator runs.
	 *
	 * Recognition keeps a crawler's full address, which is right for a service and wrong for a
	 * reader somebody hosts at home. On the Feed surface a name here keeps its full address only
	 * from inside a published cloud range. See feed_reduces_address().
	 */
	const SELF_HOSTED_READERS = array( 'Miniflux' );

	/**
	 * Every surface category, for schemas and filters.
	 *
	 * @return string[]
	 */
	public static function categories() {
		return array( self::CAT_DOCS, self::CAT_MARKDOWN, self::CAT_HTML, self::CAT_NOTFOUND, self::CAT_ROBOTS, self::CAT_FEED );
	}

	/**
	 * The values the category CASE compares against, in the order it asks for them.
	 *
	 * The CASE itself has to be a literal in every query that uses it — the statement must be a
	 * fixed string — so it is written out five times and a test asserts the five are identical.
	 * What it compares against is defined once, here. The order the values are passed in is:
	 * robots, html, robots_legacy, robots_query, feed, html, markdown, notfound.
	 *
	 * `robots_legacy` and `robots_query` are how a pre-1.49.0 row is recognised: it was stored as an
	 * HTML page view whose detail is the requested URL, so `/robots.txt`, with or without a query.
	 *
	 * @return array<string, string>
	 */
	private static function category_patterns() {
		global $wpdb;
		return array(
			'robots'        => self::SURFACE_ROBOTS,
			'html'          => $wpdb->esc_like( 'HTML page view' ) . '%',
			'robots_legacy' => '/robots.txt',
			'robots_query'  => $wpdb->esc_like( '/robots.txt?' ) . '%',
			'feed'          => self::SURFACE_FEED,
			'markdown'      => $wpdb->esc_like( 'Markdown' ) . '%',
			'notfound'      => $wpdb->esc_like( '404' ) . '%',
		);
	}

	/**
	 * Human-readable label for a surface category.
	 *
	 * @param string $category Category value.
	 * @return string
	 */
	public static function category_label( $category ) {
		switch ( $category ) {
			case self::CAT_DOCS:
				return __( 'Agent documents', 'make-my-site-agent-ready' );
			case self::CAT_MARKDOWN:
				return __( 'Markdown', 'make-my-site-agent-ready' );
			case self::CAT_HTML:
				return __( 'HTML pages', 'make-my-site-agent-ready' );
			case self::CAT_NOTFOUND:
				return __( 'Not found', 'make-my-site-agent-ready' );
			case self::CAT_ROBOTS:
				return __( 'robots.txt', 'make-my-site-agent-ready' );
			case self::CAT_FEED:
				return __( 'Feeds', 'make-my-site-agent-ready' );
			default:
				return __( 'All surfaces', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Every crawler category, for schemas and filters.
	 *
	 * @return string[]
	 */
	public static function crawler_categories() {
		return array(
			self::CRAWLER_AI_TRAINING,
			self::CRAWLER_AI_SEARCH,
			self::CRAWLER_AI_ASSISTANT,
			self::CRAWLER_SEARCH,
			self::CRAWLER_SEO,
			self::CRAWLER_MONITORING,
			self::CRAWLER_SCANNER,
			self::CRAWLER_OTHER,
		);
	}

	/**
	 * Human-readable label for a crawler category.
	 *
	 * @param string $category Category value.
	 * @return string
	 */
	public static function crawler_category_label( $category ) {
		switch ( $category ) {
			case self::CRAWLER_AI_TRAINING:
				return __( 'AI training', 'make-my-site-agent-ready' );
			case self::CRAWLER_AI_SEARCH:
				return __( 'AI search', 'make-my-site-agent-ready' );
			case self::CRAWLER_AI_ASSISTANT:
				return __( 'AI assistant', 'make-my-site-agent-ready' );
			case self::CRAWLER_SEARCH:
				return __( 'Search engine', 'make-my-site-agent-ready' );
			case self::CRAWLER_SEO:
				return __( 'SEO tool', 'make-my-site-agent-ready' );
			case self::CRAWLER_MONITORING:
				return __( 'Monitoring', 'make-my-site-agent-ready' );
			case self::CRAWLER_SCANNER:
				return __( 'Scanner', 'make-my-site-agent-ready' );
			case self::CRAWLER_OTHER:
				return __( 'Other bot', 'make-my-site-agent-ready' );
			default:
				return __( 'Unrecognised', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * The crawler category of a stored agent value.
	 *
	 * Derived on read, never stored, and through MMSAR_Agent_Log_Verify::claimed_name() — the same
	 * matching that decides the row's verdict — so both stored shapes (the bare canonical name and a
	 * raw user-agent logged before the crawler was recognised) come out the same, and a tag can be
	 * corrected in a release without touching a single row.
	 *
	 * @param string $agent Stored agent value.
	 * @return string A crawler category, or an empty string when no recognised crawler is named.
	 */
	public static function crawler_category( $agent ) {
		$name = MMSAR_Agent_Log_Verify::claimed_name( (string) $agent );
		return ( '' !== $name && isset( self::CRAWLER_CATEGORIES[ $name ] ) ) ? self::CRAWLER_CATEGORIES[ $name ] : '';
	}

	/**
	 * Request counts by crawler category across the whole log.
	 *
	 * Grouped by agent in SQL and bucketed in PHP, for the same reason as the filter: the category
	 * is derived from the agent value, not stored. Verified and failed ride along so an AI-traffic
	 * figure can be read against how much of it was proven.
	 *
	 * @return array<string, array{requests: int, agents: int, verified: int, failed: int}> Keyed by
	 *         category, plus `unrecognised`.
	 */
	public static function get_crawler_category_counts() {
		global $wpdb;
		$empty = array(
			'requests' => 0,
			'agents'   => 0,
			'verified' => 0,
			'failed'   => 0,
		);
		$out   = array_fill_keys( array_merge( self::crawler_categories(), array( self::CRAWLER_UNRECOGNISED ) ), $empty );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, COUNT(*) AS requests,
					SUM(CASE WHEN verified = 'verified' THEN 1 ELSE 0 END) AS verified,
					SUM(CASE WHEN verified = 'failed' THEN 1 ELSE 0 END) AS failed
				FROM %i GROUP BY agent",
				self::table()
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$category                 = self::crawler_category( isset( $row['agent'] ) ? (string) $row['agent'] : '' );
			$key                      = '' === $category ? self::CRAWLER_UNRECOGNISED : $category;
			$out[ $key ]['requests'] += (int) $row['requests'];
			$out[ $key ]['verified'] += (int) $row['verified'];
			$out[ $key ]['failed']   += (int) $row['failed'];
			++$out[ $key ]['agents'];
		}
		return $out;
	}

	/**
	 * Client types. What kind of software made the request, as distinct from who it claimed to be.
	 */
	const CLIENT_CRAWLER = 'crawler';
	const CLIENT_BROWSER = 'browser';
	const CLIENT_HTTP    = 'http';

	/**
	 * What kind of client made this request, from the shape of the request rather than its name.
	 *
	 * **This separates browser navigations from HTTP clients. It does not separate people from
	 * machines,** and the difference matters enough to state at the top. An agent driving a real
	 * Chrome through Playwright sends everything below, because it *is* Chrome, and is
	 * indistinguishable here from a person reading the site. What this does catch is the far more
	 * common case: an agent using a fetch tool, a script, a scraper or a CLI.
	 *
	 * The signals, strongest first:
	 *
	 * - **A document navigation.** `Sec-Fetch-Mode: navigate`, or `Sec-Fetch-Dest: document`, is
	 *   what a browser sends when it loads a page — and it is a shape the Fetch API cannot ask for,
	 *   since `fetch()` rejects `mode: 'navigate'` outright. No fetch tool built on it can produce
	 *   one. Confirmed against this site: a browser navigation arrives with `Sec-Fetch-Mode:
	 *   navigate` and `Sec-Fetch-Dest: document`; a bare curl arrives with neither.
	 * - **`Sec-CH-UA`**. User-agent client hints, Chromium only, so its absence proves nothing on
	 *   Safari or Firefox and its presence is good evidence. No HTTP client library sends it.
	 * - **A self-declared bot name**, which is a claim rather than a signal, but a claim worth
	 *   taking at face value here: something calling itself `SomethingBot` or advertising
	 *   `+https://…/bot` is not a browser, whatever else it is. Tested only after the browser
	 *   shapes above, so a phone whose model name happens to end in "bot" is not caught by it.
	 * - **`Accept-Language` with an HTML-shaped `Accept`**. Weak on its own, and the tiebreak that
	 *   covers a browser too old for fetch metadata.
	 *
	 * **The mere presence of a `Sec-Fetch-*` header is not the test, and treating it as one was a
	 * bug from 1.26.0 to 1.30.1.** Node's built-in fetch (undici) sends `Sec-Fetch-Mode: cors`, so
	 * every agent built on it recorded as a browser — and browser rows are excluded from the
	 * default view, which hid exactly the traffic this log exists to show. It was found in the live
	 * log: ten `.md` fetches within seconds, from `OraBot/1.0 (+https://ora.ai/bot)` and from a bare
	 * `node` user-agent at one AWS address, all filed as `browser`. Reproduced against Node 24,
	 * which sends a wildcard `Accept`, `accept-language: *` and `sec-fetch-mode: cors`, with no
	 * `Sec-Fetch-Dest`, no `Sec-Fetch-Site` and no `Sec-CH-UA`. So `Sec-Fetch-Site` and
	 * `Sec-Fetch-User` are no longer consulted at all: neither distinguishes the two populations,
	 * and each was doing nothing but widening the false positive.
	 *
	 * None of this is proof against a client that simply chooses to send these headers. They are
	 * *forbidden headers in a browser*, which stops page JavaScript forging them; it constrains
	 * nothing outside one. This reads the shape of a request, and a shape is a claim like any other.
	 *
	 * A declared crawler name short-circuits all of it: those are already described by the `agent`
	 * column and its verification verdict, and calling ClaudeBot an "http client" would bury the
	 * more useful fact.
	 *
	 * @return string One of the CLIENT_* constants.
	 */
	private static function detect_client_type() {
		$ua = self::user_agent();
		if ( self::is_known_agent( $ua ) ) {
			return self::CLIENT_CRAWLER;
		}

		$mode = isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ) ) : '';
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) ) : '';
		if ( 'navigate' === $mode || 'document' === $dest || ! empty( $_SERVER['HTTP_SEC_CH_UA'] ) ) {
			return self::CLIENT_BROWSER;
		}

		// Not browser-shaped. A name that announces itself as a bot is the next most useful thing
		// the request carries, and it belongs with the crawlers rather than with anonymous scripts.
		if ( self::is_self_declared_bot( $ua ) ) {
			return self::CLIENT_CRAWLER;
		}

		// No navigation shape at all. Before calling it a script, allow for a browser old enough to
		// predate fetch metadata: it would still send a language and an HTML-shaped Accept listing
		// several types with quality values, which a fetch tool almost never does.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) && false !== stripos( $accept, 'text/html' ) && false !== strpos( $accept, ';q=' ) ) {
			return self::CLIENT_BROWSER;
		}

		return self::CLIENT_HTTP;
	}

	/**
	 * Human-readable label for a client type.
	 *
	 * @param string $type Stored client type.
	 * @return string
	 */
	public static function client_type_label( $type ) {
		switch ( $type ) {
			case self::CLIENT_CRAWLER:
				return __( 'Declared crawler', 'make-my-site-agent-ready' );
			case self::CLIENT_BROWSER:
				return __( 'Browser', 'make-my-site-agent-ready' );
			case self::CLIENT_HTTP:
				return __( 'Script or fetch tool', 'make-my-site-agent-ready' );
			default:
				return __( 'Not recorded', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Every client type value, for schemas and filters.
	 *
	 * @return string[]
	 */
	public static function client_types() {
		return array( self::CLIENT_CRAWLER, self::CLIENT_BROWSER, self::CLIENT_HTTP );
	}

	/**
	 * How much ordinary page-view traffic is recorded.
	 *
	 * Three states in one option, kept backwards compatible: the value was a checkbox until 1.25.0,
	 * so the stored '1' still means "recognized agents only" and an empty value still means off.
	 *
	 * @return string 'off', 'agents' or 'all'.
	 */
	public static function page_view_mode() {
		$stored = (string) get_option( 'mmsar_agent_log_pages', '' );
		if ( 'all' === $stored ) {
			return 'all';
		}
		return '1' === $stored ? 'agents' : 'off';
	}

	/**
	 * Reduces an address to its network, for storing against traffic that is probably a person.
	 *
	 * IPv4 keeps three octets, IPv6 the first four groups. That is enough to tell one visitor's
	 * session apart from another's in the log and to recognise a cloud range, and not enough to be
	 * an identifier for a household.
	 *
	 * **Applied only to page views from user-agents this plugin does not recognise as crawlers.**
	 * A request for an agent-facing endpoint keeps its full address whoever made it: those are
	 * deliberate requests for machine-readable files rather than someone reading the site, and the
	 * exact address is what made the scanner pool identifiable in the first place. Recognized
	 * crawlers keep theirs too, because verification needs it.
	 *
	 * Two exceptions reduce an agent-facing request too: a user-run client such as Claude Code
	 * (1.47.0), and a real browser that followed a link on this site from outside every cloud range,
	 * unsigned (1.48.0) — see MMSAR_Agent_Log_Signals::is_probably_reader(). Both are a person.
	 *
	 * @param string $ip Client IP.
	 * @return string Network-level address, or '' when the input will not parse.
	 */
	public static function anonymize_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// The four groups are read from the packed address, not by splitting its text. Until
			// 1.48.0 this split inet_ntop()'s output on ':', and that output is compressed: for
			// `2001:db8::1` the "first four groups" were `2001`, `db8`, `` and `1`, which stored
			// `2001:db8::1::` — not an address at all, and one that kept the interface-ID bits it
			// exists to drop. Any address with a zero group in its first half was affected. Written
			// out uncompressed here, so the result always parses and is its own reduction.
			$words = unpack( 'n8', (string) inet_pton( $ip ) );
			if ( ! is_array( $words ) ) {
				return '';
			}
			return implode( ':', array_map( 'dechex', array_slice( array_values( $words ), 0, 4 ) ) ) . '::';
		}
		$octets = explode( '.', $ip );
		if ( 4 !== count( $octets ) ) {
			return '';
		}
		$octets[3] = '0';
		return implode( '.', $octets );
	}

	/**
	 * Whether a user-agent announces itself as automated software, whoever it turns out to be.
	 *
	 * The two long-standing conventions, and nothing beyond them: a `bot`, `crawler`, `spider` or
	 * `scraper` token in the name, and the `+https://example.com/bot` self-identification URL that
	 * `robots.txt` culture asks operators to put in the comment. `OraBot/1.0 (+https://ora.ai/bot)`
	 * matches on both.
	 *
	 * **A claim, not a signal**, which is the whole reason it is tested last among the positive
	 * checks in detect_client_type(): anything can say it is a bot, and anything can say it is not.
	 * It earns its place because a client that volunteers "I am a crawler" is telling the truth
	 * about the only thing this column records — what kind of software made the request — and
	 * because unlike the recognised list it needs no prior knowledge of the operator. Nothing here
	 * touches the `agent` column or the verification verdict; an unrecognised name still verifies
	 * as `unclaimed`, because there is still no claim this plugin knows how to check.
	 *
	 * The `bot` token deliberately matches at the end of a word (`SomethingBot`) rather than only
	 * as a whole one, which is how these names are actually written — at the cost of matching a
	 * device called CUBOT. That is tolerable **only** because detect_client_type() runs the browser
	 * shapes first, and a phone browser sends them.
	 *
	 * @param string $ua User-agent string.
	 * @return bool
	 */
	private static function is_self_declared_bot( $ua ) {
		return 1 === preg_match( '~(?:bot|crawler|spider|scraper)\b|\+https?://~i', (string) $ua );
	}

	/**
	 * Whether one agent name is the right label for this user-agent.
	 *
	 * The name has to appear, and — for a name two operators share — so does the disclosure that
	 * says which of them is calling. See AGENT_DISCLOSURES.
	 *
	 * @param string $ua     User-agent string.
	 * @param string $needle Agent name from AGENTS.
	 * @return bool
	 */
	private static function agent_matches( $ua, $needle ) {
		if ( false === stripos( $ua, $needle ) ) {
			return false;
		}
		if ( ! isset( self::AGENT_DISCLOSURES[ $needle ] ) ) {
			return true;
		}
		return false !== stripos( $ua, self::AGENT_DISCLOSURES[ $needle ] );
	}

	/**
	 * Whether a user-agent names a crawler this plugin recognises.
	 *
	 * @param string $ua User-agent string.
	 * @return bool
	 */
	private static function is_known_agent( $ua ) {
		foreach ( self::AGENTS as $needle ) {
			if ( self::agent_matches( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Records one agent request.
	 *
	 * Called from the plugin's serve points, which is why there is no user-agent test: a request
	 * for llms.txt or a .md URL is agent traffic whatever it calls itself, and filtering on
	 * user-agent would hide exactly the clients worth knowing about.
	 *
	 * @param string $surface             Human-readable name of what was served, e.g. 'llms.txt'.
	 * @param string $detail              What exactly was asked for within that surface — the
	 *                                    requested path on a 404, the method on an MCP call.
	 *                                    Empty for surfaces where the name is the whole answer.
	 * @param bool   $throttle_on_detail  Whether two requests differing only in $detail are two
	 *                                    entries rather than one. See below.
	 * @param bool   $anonymize           Store the caller's network rather than its full address.
	 *                                    Set for page views from user-agents this plugin does not
	 *                                    recognise as crawlers, which are mostly people. Never
	 *                                    affects the throttle, which always keys on the real
	 *                                    address; see anonymize_ip().
	 * @param string $throttle_detail     Value to use in the throttle key in place of $detail. Pass
	 *                                    a bounded, site-derived value when $detail is caller input:
	 *                                    the stored value stays faithful while the key stays safe.
	 *                                    Null uses $detail itself.
	 * @return void
	 */
	public static function record( $surface, $detail = '', $throttle_on_detail = false, $anonymize = false, $throttle_detail = null ) {
		if ( ! self::is_active() ) {
			return;
		}

		$agent  = self::agent_label();
		$ip     = self::client_ip();
		$detail = (string) $detail;

		// A user-run client is a person's own machine, so its address is reduced on every surface,
		// agent-facing files included. The full address is kept elsewhere because verification
		// runs against it, and a user-run client can never be verified — so here it would buy
		// nothing and cost a real person's IP. See "Before recognising a name, ask where the
		// software runs" in the decisions log; this is the same rule applied to a name that was
		// recognised before anybody asked.
		$anonymize = $anonymize || self::is_user_run_client( $agent );

		// The three browser signals (1.48.0). Two are stored: whether the request carried a Web Bot
		// Auth signature and whom it claims, and whether it followed a link on this site. The third,
		// the cloud network, is derived on read from the stored address and is not stored at all.
		$client_type     = self::detect_client_type();
		$signature_agent = MMSAR_Agent_Log_Signals::request_signature_agent();
		$same_site       = MMSAR_Agent_Log_Signals::request_same_site();

		// A real browser, unsigned, that followed a link on this site to an agent-facing file from
		// outside every cloud range is almost always a person clicking the footer's llms.txt link.
		// Agent-facing files otherwise keep the full address whoever asks; this person gets the
		// page-view treatment instead. The cloud check here is the only request-time use of a signal
		// on the full address, it runs last and only when the cheaper three conditions already hold,
		// and it stores nothing — its one effect is that less is stored. See the decisions log.
		if ( ! $anonymize && MMSAR_Agent_Log_Signals::is_probably_reader( $client_type, $same_site, $signature_agent, $ip ) ) {
			$anonymize = true;
		}

		// The throttle always keys on the real address, even when a reduced one is stored: it lives
		// in a transient for five minutes and never reaches the table, and keying it on the network
		// instead would collapse everyone behind one ISP range into a single entry.
		$stored_ip = $anonymize ? self::anonymize_ip( $ip ) : $ip;

		// Throttle before touching the database. Only reached by requests already known to be
		// agent-facing, so this never runs on an ordinary page view.
		//
		// Whether $detail belongs in this key is the whole difference between the two callers, and
		// it is a judgement about who supplies the value. An MCP method name comes from a closed
		// set behind a rate limiter, so keying on it is safe and necessary: initialize, tools/list
		// and tools/call inside one session are three facts, and collapsing them to one would lose
		// the only thing anybody wants to know about that endpoint. A 404 path is supplied by the
		// caller and unbounded, so keying on it would let anything walking a URL list write a row
		// per request. There the row was going to be written anyway and the path is an annotation
		// on it, which samples the pattern over days without handing a fuzzer a write primitive.
		// What is stored and what is throttled on are separate questions, and conflating them is why
		// this used to refuse to store a raw URL at all. The throttle needs a *bounded* value or a
		// caller can mint unlimited distinct keys and write a row per request; the stored value has
		// no such constraint, because it is only ever read. A page view therefore stores the URL as
		// requested and throttles on the page it resolved to.
		$throttle_on = $throttle_on_detail ? ( null === $throttle_detail ? $detail : (string) $throttle_detail ) : '';
		$key         = 'mmsar_al_' . md5( $agent . '|' . $surface . '|' . $throttle_on . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::THROTTLE );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Appending to this plugin's own table.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'logged_at'       => current_time( 'mysql', true ),
				'surface'         => mb_substr( $surface, 0, 100 ),
				'detail'          => self::fit_detail( $detail ),
				'agent'           => mb_substr( $agent, 0, 120 ),
				'ip'              => $stored_ip,
				'client_type'     => $client_type,
				'signature_agent' => $signature_agent,
				'same_site'       => $same_site,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		// Prune every so often rather than on every insert: an append is the cost this request
		// should pay, and a log a few rows over its limit between prunes is not observable.
		if ( $inserted && 0 === ( (int) $wpdb->insert_id % self::PRUNE_EVERY ) ) {
			self::prune();
		}
	}

	/**
	 * Fits a detail value into the column without letting two different values become one.
	 *
	 * The column is varchar(190) and the values written to it are paths, which on a site with deep
	 * nesting or long slugs can exceed that. A plain truncation would be worse than lossy: two
	 * distinct posts sharing a 190-character prefix would collapse into a single `by_detail` row
	 * and report a total that belongs to neither, which is the same class of error the aggregates
	 * exist to avoid. An over-long value therefore keeps its readable head and carries a short
	 * digest of the whole original, so it stays legible and stays distinct.
	 *
	 * @param string $detail Raw detail value.
	 * @return string Value that fits the column.
	 */
	private static function fit_detail( $detail ) {
		$detail = (string) $detail;
		if ( mb_strlen( $detail ) <= 190 ) {
			return $detail;
		}
		return mb_substr( $detail, 0, 181 ) . '…' . substr( md5( $detail ), 0, 8 );
	}

	/**
	 * Records a normal HTML page view, but only when the user-agent looks like a known agent.
	 *
	 * This supplies the denominator: without it the log shows only the agents that asked for an
	 * agent-facing file, and "which agents ask for markdown" cannot be answered without also
	 * knowing which ones came and did not.
	 *
	 * In 'all' mode it records every page view, not only those from a recognised crawler. That
	 * closes a blind spot which quietly distorted every share calculated from this log: an
	 * unrecognised client's agent-surface requests were recorded while its ordinary page views were
	 * not, so anything unbranded looked like it consumed nothing but agent-facing files. It also
	 * means the log now contains human traffic, which is why those rows are stored against a
	 * network rather than an address.
	 *
	 * @return void
	 */
	public static function maybe_record_page_view() {
		// Feeds and robots.txt are recorded under surfaces of their own (1.49.0), and are not pages.
		// The favicon is not a page either: WordPress answers /favicon.ico through this same request
		// cycle when the site has no file of that name, and it would otherwise land here as one.
		if ( is_admin() || is_feed() || is_robots() || is_favicon() || ! self::is_active() ) {
			return;
		}

		// A 404 is recorded by the 404 surfaces, which already reason carefully about the fact that
		// the path is caller-supplied. Recording it a second time here would duplicate the row and,
		// worse, put that unbounded path into this surface's throttle key.
		if ( is_404() ) {
			return;
		}

		$ua = self::user_agent();
		if ( '' === $ua ) {
			return;
		}

		$known = self::is_known_agent( $ua );
		if ( ! $known && 'all' !== self::page_view_mode() ) {
			return;
		}

		// Unrecognised user-agents are mostly people, so their address is reduced to its network
		// before storage. A recognised crawler keeps its full one, which is what verification runs
		// against.
		//
		// **Anything detect_client_type() calls a crawler keeps its full address, even when this
		// release has never heard of the name** (1.43.0). Reduction is not reversible, so a row
		// stored that way can never be verified — not when the operator is recognised in a later
		// release, not when its published ranges are added, never. The cost is only visible in
		// hindsight: LinkupBot arrived in August announcing `bot@linkup.so`, was recognised weeks
		// later, and by then 303 of its page views were on file at network precision against a
		// published /32 they could no longer be tested against. `unverifiable` on those rows is a
		// fact about this log, not about Linkup.
		//
		// **Why the client type and not is_self_declared_bot() directly.** That test matches a
		// `bot`/`crawler`/`spider`/`scraper` token at a word ending, which is how these names are
		// really written and also how a phone called CUBOT is written. Wrong there costs a mislabelled
		// row; wrong *here* costs a person's full address, so the looser test is not good enough on
		// its own. detect_client_type() runs the browser shapes first — `Sec-Fetch-Mode: navigate`,
		// `Sec-Fetch-Dest: document`, `Sec-CH-UA` — and a phone browser sends them, so CUBOT resolves
		// as a browser and keeps the reduction. What reaches the bot-name test is a request that is
		// already not browser-shaped.
		//
		// So this keeps no address it would not already have kept had the plugin known the name, and
		// an agent driving a headless browser still reduces — indistinguishable from a reader, which
		// is the right way for that one to fail.
		self::record(
			'HTML page view (' . self::accept_summary() . ')',
			self::requested_url(),
			true,
			self::CLIENT_CRAWLER !== self::detect_client_type(),
			self::page_view_path()
		);
	}

	/**
	 * Records a request for WordPress's robots.txt.
	 *
	 * A crawler reading the rules before deciding what to fetch — neither a page view nor an agent
	 * document, and worth seeing on its own: on the site this plugin was built on, ClaudeBot fetched
	 * robots.txt 170 times against 77 page views. Until 1.49.0 it was recorded as an HTML page
	 * view, because WordPress serves its virtual robots.txt through the ordinary request cycle.
	 *
	 * **Hooked on `template_redirect`, not `do_robots`.** Core runs `template_redirect`, then exits
	 * on a HEAD request, then fires `do_robots`, so the action would never see a HEAD. This is also
	 * the point the pre-1.49.0 rows were recorded at, so the two stay comparable.
	 *
	 * **Only WordPress's virtual robots.txt is seen.** A physical robots.txt in the web root is
	 * served by the web server without PHP running, and nothing in a plugin can record it.
	 *
	 * No Accept summary: WordPress serves robots.txt as text/plain whatever the request asks for,
	 * so it would describe nothing. The address follows the page-view rule — kept in full for
	 * anything detect_client_type() reads as a crawler, reduced to its network otherwise.
	 *
	 * @return void
	 */
	public static function maybe_record_robots() {
		if ( ! is_robots() || ! self::is_active() ) {
			return;
		}
		self::record( self::SURFACE_ROBOTS, '', false, self::CLIENT_CRAWLER !== self::detect_client_type() );
	}

	/**
	 * A feed request seen at `wp_headers`, waiting to learn how it was answered.
	 *
	 * Holds the detail and the address decision, or null when nothing is pending.
	 *
	 * @var array{detail: string, anonymize: bool}|null
	 */
	private static $pending_feed = null;

	/**
	 * Notes a feed request, to be recorded once its response is known. A filter used as an action.
	 *
	 * Feeds were skipped from 1.15.0 to 1.48.0 and the reason was never written down. The likely
	 * one was sound — a feed is not HTML, so counting it as a page view would have skewed the
	 * denominator — but it left feeds the only machine-readable content the log could not see,
	 * and made every feed reader look like an occasional HTML visitor. See the decisions log.
	 *
	 * **Why `wp_headers` and not `template_redirect`.** A feed reader polls with `If-None-Match` /
	 * `If-Modified-Since`, and when nothing has changed `WP::send_headers()` answers 304 and exits
	 * — before the `send_headers` action and long before `template_redirect`. Hooked there, this
	 * would only have seen the polls where the feed had changed, which is the blind spot again.
	 * `wp_headers` runs inside send_headers() before that exit, after the query has resolved, so
	 * is_feed() and the queried object are both available.
	 *
	 * **Why it is not recorded here.** At this point nobody knows whether a feed will be served.
	 * Yoast's crawl cleanup redirects the comment, Atom and search feeds to the homepage on the
	 * `wp` action, which runs after this filter, and core does not tell a filter whether it is about
	 * to send a 304. So the request is noted here and recorded on `shutdown` — which runs after
	 * core's 304 exit and after a redirect's exit too — only if the response went out as 200 or
	 * 304. Found on the clone and on live, where /feed/atom/ and /comments/feed/ both 301.
	 *
	 * The detail is the feed's canonical path, derived from the resolved query and never from
	 * REQUEST_URI, and it is also the throttle key — every value is something the site publishes,
	 * so a reader appending cache-busters cannot mint new rows. See feed_path().
	 *
	 * @param array $headers Response headers, returned unchanged.
	 * @return array
	 */
	public static function maybe_record_feed( $headers ) {
		if ( null === self::$pending_feed && is_feed() && ! is_404() && self::is_active() ) {
			self::$pending_feed = array(
				'detail'    => self::feed_path(),
				'anonymize' => self::feed_reduces_address( self::detect_client_type(), self::agent_label(), self::client_ip() ),
			);
			add_action( 'shutdown', array( __CLASS__, 'record_pending_feed' ) );
		}
		return $headers;
	}

	/**
	 * Records the noted feed request, on `shutdown`, if it was answered.
	 *
	 * @return void
	 */
	public static function record_pending_feed() {
		self::record_feed_response( (int) http_response_code() );
	}

	/**
	 * Records the noted feed request if the response it got was the feed: 200, or 304 Not Modified.
	 *
	 * A redirect, an error or anything else means no feed was served, so nothing is recorded. The
	 * note is cleared either way. Public so the rule can be asserted without a real response.
	 *
	 * @param int $status HTTP status the response went out with.
	 * @return void
	 */
	public static function record_feed_response( $status ) {
		$pending            = self::$pending_feed;
		self::$pending_feed = null;
		if ( null === $pending || ! in_array( (int) $status, array( 200, 304 ), true ) ) {
			return;
		}
		self::record( self::SURFACE_FEED, $pending['detail'], true, $pending['anonymize'] );
	}

	/**
	 * Whether a feed request's address is reduced to its network before storage.
	 *
	 * The page-view rule, plus one exception for self-hosted readers. Browsers and desktop readers
	 * (NetNewsWire, FreshRSS and the like announce no bot) are not crawlers, so they reduce. Hosted
	 * services — Feedly and Inoreader send a `+http://` URL, FeedBurner and Feedbin are recognised —
	 * keep the full address, which is where verification would run.
	 *
	 * **The exception.** Miniflux is software a person installs, often on a home server, and is
	 * recognised by name — so the page-view rule would keep that person's address. On this surface
	 * a self-hosted reader keeps its full address only from inside a published cloud range, where
	 * it is a server rather than somebody's broadband. It can only ever reduce what is stored,
	 * which is the rule for request-time signal checks (decisions log, 1.48.0).
	 *
	 * Public because it is a pure function of its inputs and the privacy rule worth pinning.
	 *
	 * @param string $client_type Detected client type.
	 * @param string $agent       Agent label as stored.
	 * @param string $ip          Full client address.
	 * @return bool
	 */
	public static function feed_reduces_address( $client_type, $agent, $ip ) {
		if ( self::CLIENT_CRAWLER !== $client_type ) {
			return true;
		}
		foreach ( self::SELF_HOSTED_READERS as $name ) {
			if ( false !== stripos( (string) $agent, $name ) ) {
				return '' === MMSAR_Agent_Log_Signals::cloud_network( $ip );
			}
		}
		return false;
	}

	/**
	 * The canonical path of the feed a request resolved to, bounded to what the site publishes.
	 *
	 * Every feed WordPress serves is counted — the main feed, comment feeds, per-post comment feeds,
	 * category, tag, taxonomy, author and post-type feeds, in every format — because a reader
	 * subscribed to /category/ai/feed/ is the same kind of fact as one subscribed to /feed/. Core's
	 * own link builders produce the path, so the format is in it (`/feed/`, `/feed/atom/`) and two
	 * spellings of one feed share a row.
	 *
	 * The value enters the throttle key, so nothing here comes from the request line. A search feed
	 * records `(search feed)` and never the term; a feed type the site has not registered records
	 * `(other feed)`, as does anything that does not resolve to a published object.
	 *
	 * @return string
	 */
	private static function feed_path() {
		global $wp_rewrite;

		$type = str_replace( 'comments-', '', (string) get_query_var( 'feed' ) );
		if ( '' === $type || 'feed' === $type ) {
			$type = get_default_feed();
		}
		$known = isset( $wp_rewrite->feeds ) ? (array) $wp_rewrite->feeds : array( 'rdf', 'rss', 'rss2', 'atom' );
		if ( ! in_array( $type, $known, true ) ) {
			return '(other feed)';
		}

		$link = '';
		if ( is_comment_feed() ) {
			if ( is_singular() ) {
				$id   = get_queried_object_id();
				$link = $id ? get_post_comments_feed_link( $id, $type ) : '';
			} else {
				$link = get_feed_link( 'comments_' . $type );
			}
		} elseif ( is_search() ) {
			// Deliberately not the search term, which is caller-supplied and unbounded.
			return '(search feed)';
		} else {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Term ) {
				$link = get_term_feed_link( $queried->term_id, $queried->taxonomy, $type );
			} elseif ( $queried instanceof WP_User ) {
				$link = get_author_feed_link( $queried->ID, $type );
			} elseif ( $queried instanceof WP_Post_Type ) {
				$link = get_post_type_archive_feed_link( $queried->name, $type );
			} elseif ( is_date() ) {
				return '(date feed)';
			} elseif ( ! is_archive() && ! is_singular() ) {
				$link = get_feed_link( $type );
			}
		}

		return is_string( $link ) && '' !== $link ? self::request_path( $link ) : '(other feed)';
	}

	/**
	 * The URL as the caller actually requested it, path and query string.
	 *
	 * Safe to *store* because storage is not the constraint: the value is escaped on the admin screen
	 * and run through csv_cell() on export, and control characters are stripped here. It is not safe
	 * to *throttle* on, which is a different job handled by page_view_path().
	 *
	 * The query string is kept, so an internal search is recorded as the visitor typed it. That is
	 * ordinary for a site's own logs and it is worth knowing about rather than discovering.
	 *
	 * @return string
	 */
	private static function requested_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the request line for a log annotation, not a state change.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '/';
		}

		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		$out   = rawurldecode( $path ) . ( '' !== $query ? '?' . rawurldecode( $query ) : '' );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $out );
		$out   = null === $clean ? '' : $clean;

		if ( '' !== $out && ! mb_check_encoding( $out, 'UTF-8' ) ) {
			$clean = preg_replace( '/[^\x20-\x7E]/', '', $out );
			$out   = null === $clean ? '' : $clean;
		}

		return '' === $out ? '/' : '/' . ltrim( $out, '/' );
	}

	/**
	 * The bounded page a request resolved to, used as the throttle key rather than stored.
	 *
	 * Never `REQUEST_URI`. The throttle key is what stops a caller writing a row per request: a search
	 * query or a junk querystring is unbounded caller input, so keying on it would hand anyone a way
	 * to grow the table at will. Everything below comes from WordPress resolving the request to
	 * something the site actually publishes.
	 *
	 * Since 1.27.0 this is *only* the key. The row stores requested_url() instead, so nothing about
	 * what the visitor asked for is lost. The cost of keying here is sampling rather than omission:
	 * two different URLs resolving to the same page within the throttle window produce one row, and
	 * it keeps whichever arrived first.
	 *
	 * @return string
	 */
	private static function page_view_path() {
		if ( is_singular() ) {
			$id = get_queried_object_id();
			return $id ? self::request_path( (string) get_permalink( $id ) ) : '/';
		}
		if ( is_front_page() || is_home() ) {
			return '/';
		}
		if ( is_search() ) {
			// Deliberately not the search term, which is caller-supplied and unbounded.
			return '(search)';
		}

		$queried = get_queried_object();
		if ( $queried instanceof WP_Term ) {
			$link = get_term_link( $queried );
			return is_wp_error( $link ) ? '(archive)' : self::request_path( (string) $link );
		}
		if ( $queried instanceof WP_Post_Type ) {
			return self::request_path( (string) get_post_type_archive_link( $queried->name ) );
		}
		if ( $queried instanceof WP_User ) {
			return self::request_path( (string) get_author_posts_url( $queried->ID ) );
		}
		if ( is_date() ) {
			return '(date archive)';
		}

		return '(other)';
	}

	/**
	 * A short label for the requesting agent: the matched agent name where recognized, otherwise a
	 * trimmed user-agent so unknown clients stay identifiable.
	 *
	 * @return string
	 */
	private static function agent_label() {
		return self::label_for( self::user_agent() );
	}

	/**
	 * The stored label for a user-agent.
	 *
	 * Public because it is a pure string function and the stored value is what every later read —
	 * verdict, category, journey — is derived from, so it is worth asserting directly with real
	 * user-agents. Same reasoning as `trimmed_user_agent()`.
	 *
	 * @param string $ua Raw user-agent.
	 * @return string The recognised name (or its AGENT_LABELS label), else a trimmed user-agent.
	 */
	public static function label_for( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua ) {
			return 'unknown';
		}
		foreach ( self::AGENTS as $needle ) {
			if ( self::agent_matches( $ua, $needle ) ) {
				// A user-run client keeps its token in the label. The bare name alone would discard
				// the only evidence that tells it apart from a forgery, and the verdict is derived
				// from this stored value later — nothing else about the request survives.
				if ( isset( self::USER_RUN_CLIENTS[ $needle ] ) && false !== stripos( $ua, self::USER_RUN_CLIENTS[ $needle ] . '/' ) ) {
					return $needle . ' (' . self::USER_RUN_CLIENTS[ $needle ] . ')';
				}
				return isset( self::AGENT_LABELS[ $needle ] ) ? self::AGENT_LABELS[ $needle ] : $needle;
			}
		}
		return self::trimmed_user_agent( $ua );
	}

	/**
	 * Whether a stored agent value is a user-run client of a crawler name, such as Claude Code.
	 *
	 * Accepts both stored shapes, as everything reading the `agent` column must: the label
	 * label_for() writes (`Claude-User (claude-code)`) and a raw user-agent carrying the versioned
	 * token (`claude-code/2.1.280`). The token only counts beside the name it belongs to.
	 *
	 * @param string $agent Stored agent value, or a raw user-agent.
	 * @return bool
	 */
	public static function is_user_run_client( $agent ) {
		$agent = (string) $agent;
		foreach ( self::USER_RUN_CLIENTS as $name => $token ) {
			if ( false === stripos( $agent, $name ) ) {
				continue;
			}
			if ( false !== stripos( $agent, $token . '/' ) || false !== stripos( $agent, '(' . $token . ')' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An unrecognised user-agent, reduced to the part that identifies the caller.
	 *
	 * **The cut used to discard the wrong end.** `mb_substr( $ua, 0, 80 )` keeps the first 80
	 * characters, and in a browser user-agent every one of those is boilerplate that each browser
	 * of that family sends identically — while the version and product that tell two callers apart
	 * sit past the cut:
	 *
	 *     Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like |Gecko) Chrome/141.0.0.0 Safari/537.36
	 *     \_____________________________ stored ______________________________________/ \______ discarded ______/
	 *
	 * On the site this was found on, one such label covered 725 requests from 339 different
	 * addresses: the rows were not merely displayed the same, they were stored the same.
	 *
	 * So the boilerplate is removed first and the truncation applied to what is left. Nothing is
	 * stored that was not being stored before — this keeps *less* of the string, not more, which is
	 * the reason it is a safe change to make to a column that holds human traffic. Widening the cap,
	 * or synthesising a `Chrome 141 (macOS)` signature, would both make ordinary visitors more
	 * distinguishable from one another, and this log records them as a denominator rather than as
	 * subjects.
	 *
	 * Only exact, well-known stanzas are removed, and the platform comment is deliberately kept —
	 * `(Macintosh; Intel Mac OS X 10_15_7)` says which OS, which is real information. A bot name
	 * living in a comment, as in `(compatible; SomeBot/1.0; +https://example.com/bot)`, is never
	 * touched, so a name added to AGENTS later still matches rows stored before it was recognised.
	 *
	 * **Not retroactive.** Rows already written keep the old truncation; there is no un-cutting a
	 * string.
	 *
	 * Public because it is a pure string function and the only part of this path worth asserting
	 * directly — `agent_label()` reads `$_SERVER`. Same reasoning as `anonymize_ip()`.
	 *
	 * @param string $ua Raw user-agent.
	 * @return string Label of at most 80 characters.
	 */
	public static function trimmed_user_agent( $ua ) {
		$ua = (string) $ua;

		$trimmed = preg_replace(
			array(
				// The version token every browser opens with, and nothing else starts with.
				'~^Mozilla/\d+\.\d+\s*~i',
				// The WebKit stanza, whole. Chrome, Safari and every Chromium derivative send it
				// byte-identically, so it separates nothing.
				'~AppleWebKit/[\d.]+\s*\(KHTML,\s*like\s+Gecko\)\s*~i',
				// The same comment where it appears without the AppleWebKit token in front of it.
				'~\(KHTML,\s*like\s+Gecko\)\s*~i',
			),
			'',
			$ua
		);

		// Removing a stanza from the middle can leave a dangling separator — `Mozilla/5.0
		// AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0` becomes `; compatible;
		// ShapBot/0.1.0`. Tidy the joint rather than the whole string, so the caller's own
		// punctuation survives.
		$trimmed = is_string( $trimmed ) ? trim( preg_replace( '~\s+~', ' ', $trimmed ) ) : '';
		$trimmed = ltrim( $trimmed, ';, ' );

		// A user-agent that was nothing but boilerplate has nothing left to identify it by, so keep
		// the original rather than storing an empty string and losing the row's only evidence.
		if ( '' === $trimmed ) {
			$trimmed = trim( $ua );
		}

		return mb_substr( $trimmed, 0, 80 );
	}

	/**
	 * Whether the request asked for markdown, HTML, or expressed no preference.
	 *
	 * Only ever called for a page that was answered with HTML, so the two Markdown labels describe
	 * why a client that mentioned Markdown did not get it — and they must use the serving rule, not
	 * a looser one. Until 1.46.1 any mention of `markdown` read "asked for markdown", which on a
	 * negotiable page looked like the plugin refusing a request it had in fact read as "no
	 * preference".
	 *
	 * - `wanted markdown`: Markdown won under MMSAR_Accept's rule, so this page has no Markdown
	 *   version to give — an archive, a post type that is not enabled, or negotiation switched off.
	 * - `accepts markdown`: Markdown was named but HTML outranked it, so HTML was the right answer.
	 *
	 * Rows written before 1.46.1 keep "asked for markdown"; the surface is stored, not derived.
	 *
	 * @return string
	 */
	private static function accept_summary() {
		$accept = MMSAR_Accept::request_header();
		if ( '' === $accept ) {
			return 'no Accept';
		}
		if ( MMSAR_Accept::prefers_markdown( $accept ) ) {
			return 'wanted markdown';
		}
		if ( false !== stripos( $accept, 'markdown' ) ) {
			return 'accepts markdown';
		}
		if ( false !== stripos( $accept, 'text/html' ) ) {
			return 'asked for HTML';
		}
		return 'Accept: ' . mb_substr( $accept, 0, 30 );
	}

	/**
	 * User agent string for this request.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Client IP, preferring Cloudflare's header — behind a CDN, REMOTE_ADDR is the edge, so every
	 * agent would otherwise share one address and the throttle would collapse them together.
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			return '';
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
