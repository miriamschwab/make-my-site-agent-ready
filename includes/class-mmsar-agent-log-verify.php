<?php
/**
 * Verifies that an agent log entry's claimed crawler identity is genuine.
 *
 * The `agent` column is whatever the caller put in its `User-Agent`, and on this site that has been
 * measurably forged: three IPs each rotated through five or more AI-crawler identities within
 * seconds, and every 404 attributed to GPTBot came from an address OpenAI does not publish. A log
 * of what visitors said they were is a different thing from a log of who they were, and only the
 * second one answers "do AI agents use these surfaces".
 *
 * Two methods, because the operators are split on which they publish:
 *
 * - **Forward-confirmed reverse DNS.** Reverse-lookup the IP to a hostname, forward-lookup that
 *   hostname, require it to resolve back to the same IP, then require the hostname to sit under a
 *   suffix the claimed operator owns. A `User-Agent` is free to forge; an rDNS record under
 *   someone else's domain is not. Google, Apple, Amazon and Microsoft document this.
 * - **Published IP ranges.** Anthropic, OpenAI, Perplexity and DuckDuckGo publish no rDNS for their
 *   crawlers at all — a genuine ClaudeBot address and a genuine GPTBot address both have no reverse
 *   record, so FCrDNS returns no evidence either way for exactly the operators this log cares most
 *   about. For them the range file is the only method documented, and it is decisive in both
 *   directions.
 *
 * Nothing here ever runs while serving a visitor. See run_batch() for why, and for where it does.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log identity verification.
 */
class MMSAR_Agent_Log_Verify {

	/**
	 * Verdicts. Stored in the `verified` column; every one of them is a distinct piece of news.
	 *
	 * `unverifiable` and `nodns` are deliberately not folded into `failed`. Collapsing them would
	 * manufacture an accusation of spoofing out of missing data, which is the exact failure this
	 * feature exists to correct.
	 */
	const PENDING      = '';
	const VERIFIED     = 'verified';
	const FAILED       = 'failed';
	const UNVERIFIABLE = 'unverifiable';
	const UNCLAIMED    = 'unclaimed';
	const NODNS        = 'nodns';

	/**
	 * Distinct IPs resolved per trickle pass, when someone opens the log or reads the ability.
	 */
	const TRICKLE_IPS = 10;

	/**
	 * Wall-clock budget for a trickle pass, in seconds.
	 */
	const TRICKLE_BUDGET = 1.0;

	/**
	 * Distinct IPs resolved per press of "Verify now".
	 */
	const BUTTON_IPS = 200;

	/**
	 * Wall-clock budget for a "Verify now" pass, in seconds.
	 */
	const BUTTON_BUDGET = 20.0;

	/**
	 * How long a decided verdict is cached against its IP.
	 *
	 * IP assignments are reused, so this is not forever. A week is short enough that a reassigned
	 * address is re-judged within a sensible window and long enough that a crawler returning daily
	 * costs no lookups at all.
	 */
	const CACHE_TTL = WEEK_IN_SECONDS;

	/**
	 * How long a `nodns` row waits before the ordinary verification pass picks it up again.
	 *
	 * `nodns` is documented as the retryable verdict, and until 1.24.4 nothing actually retried it:
	 * `run_batch()` only ever looked at rows in the pending state, so the promise was kept by a
	 * button a person had to press. That is the wrong shape — it put a permanent call to action on
	 * screen for a condition that resolves itself or does not, and on a row whose address has no
	 * reverse record it could never come to anything.
	 *
	 * A day is long enough that a dead address costs one lookup between daily visits to the screen,
	 * and short enough that a genuine resolver outage is repaired without anybody noticing it
	 * happened.
	 */
	const RETRY_NODNS_AFTER = DAY_IN_SECONDS;

	/**
	 * How long an undecided (`nodns`) result is cached.
	 *
	 * Much shorter than a verdict, because a resolver that was unavailable for one request is the
	 * likeliest cause and freezing that answer for a week would turn a blip into a permanent
	 * non-answer. This is the one verdict that is meant to be retried.
	 */
	const NODNS_TTL = HOUR_IN_SECONDS;

	/**
	 * Reverse-DNS hostname suffixes each operator publishes for its crawlers.
	 *
	 * Confirmed on 2026-09-03 two ways, as the spec for this work insisted: against the operator's
	 * own crawler documentation, and by resolving addresses from this site's live log and reading
	 * what came back. `66.249.68.2` reverses to `crawl-66-249-68-2.googlebot.com` and that name
	 * forward-resolves to `66.249.68.2`, so the Google entry is proven end to end rather than
	 * remembered. `amazonbot.amazon`, `applebot.apple.com` and `search.msn.com` are all real
	 * delegated zones served by their operator's own nameservers.
	 *
	 * An agent absent from this map and absent from the range data yields `unverifiable`, which is
	 * honest and harmless. A *wrong* entry yields confident `failed` verdicts against real
	 * crawlers, which is worse than having no feature — so add an entry only once its suffix has
	 * been confirmed the same two ways.
	 *
	 * Note what is deliberately not here. `crawl.claude.com` has no SOA and no A record, and
	 * Anthropic's own crawler documentation points at a range file instead; it was in the brief for
	 * this work and it does not exist. **DuckAssistBot was here in 1.24.0 and was removed in
	 * 1.24.1** — live data settled it: all 13 of its requests came from Azure addresses with no PTR
	 * record at all, and DuckDuckGo publishes `duckassistbot.json` instead, which covers all 13. A
	 * `duckduckgo.com` zone existing is not evidence the crawler uses it, which is the same mistake
	 * `crawl.claude.com` was.
	 *
	 * Two entries here are unexercised by this site's log and rest on operator documentation alone:
	 * `Applebot`/`Applebot-Extended` and `bingbot`. Both operators have documented FCrDNS for years,
	 * so they are kept — but if either ever produces a run of `nodns` the way DuckAssistBot did,
	 * check for a range file before assuming the resolver is at fault.
	 */
	const VERIFY_HOSTS = array(
		// Google — https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot.
		'GoogleOther'       => array( 'googlebot.com', 'google.com', 'googleusercontent.com' ),
		'Google-Extended'   => array( 'googlebot.com', 'google.com', 'googleusercontent.com' ),
		'Googlebot'         => array( 'googlebot.com', 'google.com' ),
		// Apple — https://support.apple.com/en-us/119829.
		'Applebot-Extended' => array( 'applebot.apple.com' ),
		'Applebot'          => array( 'applebot.apple.com' ),
		// Amazon — https://developer.amazon.com/amazonbot.
		'Amazonbot'         => array( 'crawl.amazonbot.amazon' ),
		// Microsoft — https://www.bing.com/webmasters/help/verifying-bingbot-2195837f.
		'bingbot'           => array( 'search.msn.com' ),
		// Perplexity documents both methods; the range data below covers it as well.
		'PerplexityBot'     => array( 'perplexity.ai', 'perplexity.com' ),
		'Perplexity-User'   => array( 'perplexity.ai', 'perplexity.com' ),
	);

	/**
	 * Which bundled range group, if any, covers each claimed agent.
	 *
	 * An agent listed here is verifiable by range. An agent listed here and *not* found in its
	 * group's prefixes is `failed`, not `unverifiable` — these operators publish the list precisely
	 * so that absence from it is meaningful.
	 */
	const VERIFY_RANGES = array(
		'DuckAssistBot'    => 'duckduckgo',
		'ClaudeBot'        => 'anthropic',
		'Claude-User'      => 'anthropic',
		'Claude-SearchBot' => 'anthropic',
		'Anthropic-AI'     => 'anthropic',
		'GPTBot'           => 'openai',
		'OAI-SearchBot'    => 'openai',
		'ChatGPT-User'     => 'openai',
		'PerplexityBot'    => 'perplexity',
		'Perplexity-User'  => 'perplexity',
	);

	/**
	 * Memoized range data for this request.
	 *
	 * @var array|null
	 */
	private static $ranges = null;

	/**
	 * The rDNS suffix map, filtered.
	 *
	 * @return array<string, string[]> Claimed agent name => hostname suffixes.
	 */
	public static function verify_hosts() {
		/**
		 * Filters the reverse-DNS hostname suffixes accepted for each claimed crawler.
		 *
		 * Exists so a site owner can recognise an operator this release has never heard of, or one
		 * that changed its hostnames, without waiting for a plugin update. Matching is on the
		 * dot-bounded end of the hostname, so a suffix here can never be satisfied by a lookalike
		 * domain that merely contains it.
		 *
		 * @param array<string, string[]> $hosts Claimed agent name => list of hostname suffixes.
		 */
		return (array) apply_filters( 'mmsar_agent_log_verify_hosts', self::VERIFY_HOSTS );
	}

	/**
	 * The bundled published-range data, filtered.
	 *
	 * @return array<string, array{captured: string, v4: string[], v6: string[]}>
	 */
	public static function verify_ranges() {
		if ( null === self::$ranges ) {
			$data         = include __DIR__ . '/data/crawler-ranges.php';
			self::$ranges = is_array( $data ) ? $data : array();
		}

		/**
		 * Filters the published crawler IP ranges used for verification.
		 *
		 * The bundled data is a snapshot of each operator's own range file, so it ages. This is the
		 * escape hatch for that: a genuine crawler arriving from a prefix added after the snapshot
		 * would otherwise be recorded as `failed`, and adding it here fixes that without a release.
		 * Each group is `array( 'captured' => 'Y-m-d', 'v4' => array( CIDR, … ), 'v6' => array() )`.
		 *
		 * @param array $ranges Operator group => range data.
		 */
		return (array) apply_filters( 'mmsar_agent_log_verify_ranges', self::$ranges );
	}

	/**
	 * The oldest capture date across the bundled range groups.
	 *
	 * Reported alongside the verdicts rather than kept to ourselves: a `failed` verdict on a
	 * range-verified operator is only as trustworthy as the freshness of the list it was judged
	 * against, and a reader cannot weigh that without knowing the date.
	 *
	 * @return string A `Y-m-d` date, or an empty string when there is no range data.
	 */
	public static function ranges_captured() {
		$dates = array();
		foreach ( self::verify_ranges() as $group ) {
			if ( ! empty( $group['captured'] ) ) {
				$dates[] = (string) $group['captured'];
			}
		}
		if ( ! $dates ) {
			return '';
		}
		sort( $dates );
		return $dates[0];
	}

	/**
	 * Every verdict value, for schema descriptions and the admin filter.
	 *
	 * @return string[]
	 */
	public static function verdicts() {
		return array( self::VERIFIED, self::FAILED, self::UNVERIFIABLE, self::UNCLAIMED, self::NODNS );
	}

	/**
	 * Human-readable label for a verdict.
	 *
	 * @param string $verdict Verdict value.
	 * @return string
	 */
	public static function label( $verdict ) {
		switch ( $verdict ) {
			case self::VERIFIED:
				return __( 'Verified', 'make-my-site-agent-ready' );
			case self::FAILED:
				return __( 'Spoofed', 'make-my-site-agent-ready' );
			case self::UNVERIFIABLE:
				return __( 'Unverifiable', 'make-my-site-agent-ready' );
			case self::UNCLAIMED:
				return __( 'Unclaimed', 'make-my-site-agent-ready' );
			case self::NODNS:
				return __( 'No DNS', 'make-my-site-agent-ready' );
			default:
				return __( 'Pending', 'make-my-site-agent-ready' );
		}
	}

	// -------------------------------------------------------------------------
	// Running a pass
	// -------------------------------------------------------------------------

	/**
	 * Verifies a batch of unverified rows.
	 *
	 * **Deliberately never called while serving content.** `gethostbyaddr()` has no timeout that
	 * PHP exposes and can block for seconds, and `MMSAR_Agent_Log::record()` runs while a `.md`
	 * file is being handed to the caller — resolving there would put a DNS round trip in front of
	 * a response. There is no cron either: the session opener states "no cron jobs" as a scope
	 * promise for this plugin and this is not the feature to break it for.
	 *
	 * So the cost is paid on read, by the person asking the question: a small trickle when the
	 * Agent Log screen renders or the `get-agent-log` ability is called, and a large batch when an
	 * administrator presses "Verify now". Both are authenticated admin contexts.
	 *
	 * Work is done per **distinct IP**, not per row, and the verdict is then written to every row
	 * that shares that IP and claimed agent. On this site's own log that is 634 addresses across
	 * 1,584 rows, and a single crawler sweep can be 41 rows over 41 addresses; resolving per row
	 * would do the same lookup dozens of times.
	 *
	 * @param int   $max_ips Distinct IPs to resolve at most.
	 * @param float $budget  Wall-clock seconds to spend at most.
	 * @return array{ips: int, rows: int, verdicts: array<string, int>, exhausted: bool} What was done.
	 */
	public static function run_batch( $max_ips = self::TRICKLE_IPS, $budget = self::TRICKLE_BUDGET ) {
		$done = array(
			'ips'       => 0,
			'rows'      => 0,
			'verdicts'  => array(),
			'exhausted' => false,
		);

		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return $done;
		}

		$max_ips = max( 1, absint( $max_ips ) );
		$budget  = (float) $budget;
		$started = microtime( true );

		$pairs = MMSAR_Agent_Log::get_unverified_pairs( $max_ips );
		if ( ! $pairs ) {
			$done['exhausted'] = true;
			return $done;
		}

		foreach ( $pairs as $pair ) {
			$agent = isset( $pair['agent'] ) ? (string) $pair['agent'] : '';
			$ip    = isset( $pair['ip'] ) ? (string) $pair['ip'] : '';

			$verdict = self::verdict_for( $agent, $ip );
			$rows    = MMSAR_Agent_Log::apply_verdict( $ip, $agent, $verdict );

			++$done['ips'];
			$done['rows'] += $rows;
			if ( ! isset( $done['verdicts'][ $verdict ] ) ) {
				$done['verdicts'][ $verdict ] = 0;
			}
			++$done['verdicts'][ $verdict ];

			// Checked between lookups rather than before the loop, so a pass that runs into a slow
			// resolver stops cleanly and leaves the remainder pending rather than holding the
			// request open. Whatever is left is picked up by the next pass.
			if ( ( microtime( true ) - $started ) >= $budget ) {
				return $done;
			}
		}

		// A short pass means the query found fewer distinct pairs than it was allowed, so there is
		// nothing left waiting.
		$done['exhausted'] = count( $pairs ) < $max_ips;
		return $done;
	}

	/**
	 * The verdict for one claimed agent and IP, using the cache where it can.
	 *
	 * @param string $agent Claimed agent, as stored in the log.
	 * @param string $ip    Client IP, as stored in the log.
	 * @return string One of the verdict constants.
	 */
	public static function verdict_for( $agent, $ip ) {
		$claimed = self::claimed_operator_key( $agent );

		// No known crawler name in the user-agent, so there is no claim to check. Answered before
		// any lookup: most unbranded traffic lands here and none of it should cost a DNS query.
		if ( '' === $claimed ) {
			return self::UNCLAIMED;
		}

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return self::NODNS;
		}

		$cache_key = self::cache_key( $claimed, $ip );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$verdict = self::resolve_verdict( $claimed, $ip );

		set_transient( $cache_key, $verdict, self::NODNS === $verdict ? self::NODNS_TTL : self::CACHE_TTL );

		return $verdict;
	}

	/**
	 * Whether this release has any way at all to check a claimed agent.
	 *
	 * The distinction a re-check depends on. `unverifiable` means no method existed when the row
	 * was judged, and the *only* thing that changes that is this plugin learning the operator — so
	 * re-running an `unverifiable` row whose agent still has no method cannot produce a different
	 * answer, however many times it is asked. Offering that as an action is offering work with no
	 * possible outcome.
	 *
	 * @param string $agent Agent value as stored in the log.
	 * @return bool True when a suffix or a range group covers the claimed operator.
	 */
	public static function has_method( $agent ) {
		$claimed = self::claimed_operator_key( $agent );
		if ( '' === $claimed ) {
			return false;
		}

		$hosts = self::verify_hosts();
		if ( ! empty( $hosts[ $claimed ] ) ) {
			return true;
		}

		$ranges = self::verify_ranges();
		$group  = isset( self::VERIFY_RANGES[ $claimed ] ) ? self::VERIFY_RANGES[ $claimed ] : '';
		return '' !== $group && ! empty( $ranges[ $group ] );
	}

	/**
	 * The transient key a verdict is cached under.
	 *
	 * One method rather than an expression repeated at each site, so that forgetting a cached
	 * verdict and reading one can never disagree about where it lives. Keyed on the *claimed
	 * operator* rather than the raw agent string, so two spellings of one crawler name share a
	 * cache entry the way they share a verdict.
	 *
	 * @param string $claimed Claimed operator name.
	 * @param string $ip      Client IP.
	 * @return string
	 */
	private static function cache_key( $claimed, $ip ) {
		return 'mmsar_alv_' . md5( $claimed . '|' . $ip );
	}

	/**
	 * Discards the cached verdict for one claimed agent and address.
	 *
	 * Needed because a re-check is pointless without it. `unverifiable` is cached for a week, so
	 * resetting those rows to pending and re-running inside that week would hand back the same
	 * stale answer and change nothing — which is exactly the case a re-check exists to serve, since
	 * `unverifiable` is what an operator reads as until the day this plugin learns how to check it.
	 *
	 * @param string $agent Agent value as stored in the log.
	 * @param string $ip    Client IP.
	 * @return void
	 */
	public static function forget( $agent, $ip ) {
		$claimed = self::claimed_operator_key( $agent );
		if ( '' === $claimed ) {
			return;
		}
		delete_transient( self::cache_key( $claimed, $ip ) );
	}

	/**
	 * Decides a verdict from scratch.
	 *
	 * Range data is consulted first, and not merely for speed. For Anthropic, OpenAI and Perplexity
	 * it is the *only* method the operator publishes, so a hit is conclusive and no lookup is
	 * needed; and for an operator that publishes both, a range hit is stronger evidence than a
	 * hostname because it cannot be affected by whoever controls the address's rDNS.
	 *
	 * @param string $claimed Claimed agent name, exactly as it appears in the maps.
	 * @param string $ip      Validated client IP.
	 * @return string Verdict.
	 */
	private static function resolve_verdict( $claimed, $ip ) {
		$ranges    = self::verify_ranges();
		$range_map = self::VERIFY_RANGES;
		$group     = isset( $range_map[ $claimed ] ) ? $range_map[ $claimed ] : '';
		$has_range = '' !== $group && ! empty( $ranges[ $group ] );

		if ( $has_range && self::ip_in_ranges( $ip, $ranges[ $group ] ) ) {
			return self::VERIFIED;
		}

		$hosts    = self::verify_hosts();
		$suffixes = isset( $hosts[ $claimed ] ) ? (array) $hosts[ $claimed ] : array();

		if ( $suffixes ) {
			$host = self::reverse_lookup( $ip );

			// No reverse record at all. For an operator that publishes ranges too, we have already
			// missed the range above and that answer is decisive, so this is a forgery. For an
			// rDNS-only operator it is simply an absence of evidence, and retryable.
			if ( '' === $host || $host === $ip ) {
				return $has_range ? self::FAILED : self::NODNS;
			}

			if ( ! self::forward_confirms( $host, $ip ) ) {
				return self::FAILED;
			}

			return self::host_matches( $host, $suffixes ) ? self::VERIFIED : self::FAILED;
		}

		// Range-only operator that missed its range: the list exists so that absence from it means
		// something. This is the spoofing signal for Anthropic and OpenAI.
		if ( $has_range ) {
			return self::FAILED;
		}

		// A crawler name we recognise well enough to log but whose operator publishes neither
		// method here. Not a judgement about the caller — an admission about this plugin.
		return self::UNVERIFIABLE;
	}

	/**
	 * The claimed crawler name for a stored agent value.
	 *
	 * The log stores the matched name from MMSAR_Agent_Log::AGENTS where it recognised one and the
	 * raw user-agent otherwise, so an exact match covers the first case and the substring pass
	 * covers a raw string that names a crawler.
	 *
	 * **The candidate list is every crawler the log recognises, not only the ones we can verify.**
	 * That difference is what keeps `unclaimed` and `unverifiable` apart, and they are not
	 * interchangeable: CCBot, Bytespider, meta-externalagent and several others are names this
	 * plugin knows and has no verification method for. Matching only against the verification maps
	 * would file all of them as `unclaimed` — "nothing was claimed" — when something very
	 * definitely was. The honest answer is `unverifiable`, and it only comes out that way if the
	 * claim is recognised here first.
	 *
	 * @param string $agent Stored agent value.
	 * @return string The claimed crawler name, or an empty string when nothing is claimed.
	 */
	private static function claimed_operator_key( $agent ) {
		$agent = trim( (string) $agent );
		if ( '' === $agent ) {
			return '';
		}

		$known = array_unique(
			array_merge(
				array_keys( self::verify_hosts() ),
				array_keys( self::VERIFY_RANGES ),
				MMSAR_Agent_Log::AGENTS
			)
		);

		foreach ( $known as $name ) {
			if ( 0 === strcasecmp( $agent, $name ) ) {
				return $name;
			}
		}

		// Longest name first, so `Claude-SearchBot` inside a raw user-agent is not claimed by the
		// shorter overlapping `ClaudeBot`, which would send it to the wrong operator's checks.
		usort(
			$known,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $known as $name ) {
			if ( false !== stripos( $agent, $name ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Whether a hostname ends at a dot boundary inside one of the given suffixes.
	 *
	 * Matching is on the dot-bounded end of the name, or exact equality — never a substring test.
	 * `stripos()` here would accept `evil-crawl-claude-com.attacker.net` and
	 * `googlebot.com.attacker.net` alike, which would make the whole check decorative.
	 *
	 * @param string   $host     Hostname from the reverse lookup.
	 * @param string[] $suffixes Accepted suffixes.
	 * @return bool
	 */
	private static function host_matches( $host, $suffixes ) {
		$host = strtolower( rtrim( (string) $host, '.' ) );
		foreach ( $suffixes as $suffix ) {
			$suffix = strtolower( trim( rtrim( (string) $suffix, '.' ) ) );
			if ( '' === $suffix ) {
				continue;
			}
			if ( $host === $suffix ) {
				return true;
			}
			$tail = '.' . $suffix;
			if ( strlen( $host ) > strlen( $tail ) && substr( $host, -strlen( $tail ) ) === $tail ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a hostname forward-resolves back to the given IP.
	 *
	 * This is the half that makes the check worth anything. A reverse record is set by whoever
	 * controls the address block, so on its own it proves only that they typed a name; requiring
	 * the name to resolve back means the claim also has to be true in the operator's own zone,
	 * which an impersonator does not control.
	 *
	 * @param string $host Hostname to confirm.
	 * @param string $ip   IP it must resolve back to.
	 * @return bool
	 */
	private static function forward_confirms( $host, $ip ) {
		$addresses = self::forward_lookup( $host, self::is_ipv6( $ip ) );
		if ( ! $addresses ) {
			return false;
		}

		$target = self::normalize_ip( $ip );
		foreach ( $addresses as $address ) {
			if ( '' !== $target && self::normalize_ip( $address ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an IP falls inside any of a group's published prefixes.
	 *
	 * @param string $ip    Validated IP.
	 * @param array  $group Range group, with `v4` and `v6` CIDR lists.
	 * @return bool
	 */
	private static function ip_in_ranges( $ip, $group ) {
		$key   = self::is_ipv6( $ip ) ? 'v6' : 'v4';
		$cidrs = isset( $group[ $key ] ) ? (array) $group[ $key ] : array();
		foreach ( $cidrs as $cidr ) {
			if ( self::ip_in_cidr( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an IP falls inside a single CIDR block.
	 *
	 * Compared as packed binary from inet_pton so one implementation covers both families: mask
	 * whole bytes, then the remaining bits of the boundary byte.
	 *
	 * @param string $ip   Validated IP.
	 * @param string $cidr CIDR block.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );

		$packed_ip     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed stored input returns false with a warning; false is the answer we want.
		$packed_subnet = @inet_pton( trim( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
		if ( false === $packed_ip || false === $packed_subnet || strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
			return false;
		}

		$bits = (int) $bits;
		if ( $bits < 0 || $bits > ( strlen( $packed_ip ) * 8 ) ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		if ( $whole_bytes > 0 && substr( $packed_ip, 0, $whole_bytes ) !== substr( $packed_subnet, 0, $whole_bytes ) ) {
			return false;
		}

		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $remaining ) ) - 1 ) & 0xFF;
		return ( ord( $packed_ip[ $whole_bytes ] ) & $mask ) === ( ord( $packed_subnet[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Whether an address is IPv6.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	private static function is_ipv6( $ip ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	}

	/**
	 * An address in its canonical packed form, for comparison.
	 *
	 * Text comparison is wrong for IPv6: `2001:db8::1` and `2001:0db8:0000::0001` are the same
	 * address and different strings, and a resolver may hand back either spelling.
	 *
	 * @param string $ip Address.
	 * @return string Packed address, or an empty string when it will not parse.
	 */
	private static function normalize_ip( $ip ) {
		$packed = @inet_pton( trim( (string) $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A resolver answer that will not parse is simply not a match.
		return false === $packed ? '' : $packed;
	}

	// -------------------------------------------------------------------------
	// The resolver, behind one seam
	// -------------------------------------------------------------------------

	/**
	 * Reverse lookup, filtered so it can be replaced.
	 *
	 * @param string $ip Address to reverse.
	 * @return string Hostname, or an empty string when there is none.
	 */
	private static function reverse_lookup( $ip ) {
		/**
		 * Filters the result of a reverse DNS lookup.
		 *
		 * Both lookups go through a filter for the same reason: a local clone sees no real crawler
		 * traffic, so without a seam to substitute a fake resolver this feature cannot be tested
		 * anywhere except production. Return a hostname to answer the lookup, or null to let the
		 * real resolver run.
		 *
		 * @param string|null $host Hostname to use, or null to perform the real lookup.
		 * @param string      $ip   Address being reversed.
		 */
		$filtered = apply_filters( 'mmsar_agent_log_reverse_lookup', null, $ip );
		if ( null !== $filtered ) {
			return (string) $filtered;
		}

		$host = gethostbyaddr( $ip );
		return false === $host ? '' : $host;
	}

	/**
	 * Forward lookup, filtered so it can be replaced.
	 *
	 * @param string $host Hostname to resolve.
	 * @param bool   $ipv6 Whether to ask for AAAA records rather than A.
	 * @return string[] Addresses.
	 */
	private static function forward_lookup( $host, $ipv6 = false ) {
		/**
		 * Filters the result of a forward DNS lookup.
		 *
		 * @param string[]|null $addresses Addresses to use, or null to perform the real lookup.
		 * @param string        $host      Hostname being resolved.
		 * @param bool          $ipv6      Whether AAAA records were asked for.
		 */
		$filtered = apply_filters( 'mmsar_agent_log_forward_lookup', null, $host, $ipv6 );
		if ( null !== $filtered ) {
			return array_map( 'strval', (array) $filtered );
		}

		if ( $ipv6 ) {
			$records = dns_get_record( $host, DNS_AAAA );
			if ( ! is_array( $records ) ) {
				return array();
			}
			$addresses = array();
			foreach ( $records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
			return $addresses;
		}

		$addresses = gethostbynamel( $host );
		return is_array( $addresses ) ? $addresses : array();
	}
}
