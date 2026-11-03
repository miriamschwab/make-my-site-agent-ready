<?php
/**
 * The numbers at the top of the Agent Log Summary.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thirty days of bot traffic as headline numbers, a daily series, who came and what they are for.
 *
 * The findings in MMSAR_Agent_Insights say what the traffic means; this says how much of it there
 * was, so the findings have something to stand on. Added in 1.71.0 (Agent Log UI, phase 2).
 *
 * **What is counted.** Requests whose agent is a recognised crawler name, of any category, search
 * engines and SEO tools included. Unrecognised user-agents, people's browsers among them, are left
 * out: they are the denominator elsewhere, not the subject here. A Spoofed row is never counted as
 * the bot it named; it is counted on its own, as Spoofed. Everything else from a recognised name is
 * counted, and the Identity column says how much of it was confirmed, because "could not be checked"
 * is not the same as "fake".
 *
 * **Change against the previous 30 days** is given only when the log reaches back that far. A log
 * switched on three weeks ago has no previous period, and a comparison against a part-empty one would
 * report growth that is only the log starting.
 *
 * Days are the site's days, not UTC's. `compute()` is pure, like MMSAR_Agent_Insights::compute(), so
 * the counting rules are tested without a database. Cached for an hour.
 */
class MMSAR_Agent_Overview {

	/**
	 * Days in each period.
	 */
	const WINDOW_DAYS = 30;

	/**
	 * Transient holding the computed overview.
	 */
	const CACHE_KEY = 'mmsar_agent_overview';

	/**
	 * Agents listed in the Who visited table.
	 */
	const TOP_AGENTS = 8;

	/**
	 * Share of an agent's requests that must be confirmed for it to read as Verified.
	 */
	const VERIFIED_SHARE = 0.9;

	/**
	 * The traffic mix's groups, in stacking order from the baseline up.
	 *
	 * Regrouped in 1.72.0 (Miriam, 2026-11-03) from people / search & SEO / AI / other bots, so
	 * the chart answers who is legitimate: people; agents that named themselves (AI training, AI
	 * search, AI assistants); other bots that named themselves (search engines, SEO tools,
	 * scanners, monitoring and the rest); and everything else, which is unnamed scripts and
	 * crawlers plus every Spoofed request. Spoofed requests were left out of this chart until then.
	 *
	 * Identified means named and not caught faking it, not verified: plenty of real AI crawlers
	 * publish no way to check them, and putting those beside the spoofers would read "can't be
	 * checked" as "fake", which the log never does.
	 */
	const MIX_GROUPS = array( 'people', 'agents', 'known', 'rest' );

	/**
	 * The crawler categories counted as identified agents. Every other recognised category is
	 * `known`.
	 */
	const AGENT_CATEGORIES = array( 'ai-training', 'ai-search', 'ai-assistant' );

	/**
	 * What-agents-fetched groups, in stacking order from the baseline up (1.72.0).
	 *
	 * The robots.txt file is its own group (Miriam, 2026-11-03): it predates agents and is not written for
	 * them, but many agents start there and it carries the content signals, so it is neither an
	 * agent file nor an ordinary page.
	 */
	const FETCH_GROUPS = array( 'files', 'robots', 'pages', 'notfound' );

	/**
	 * Which fetch group a surface category belongs to. MCP and WebMCP calls count as agent files:
	 * they are agent-facing surfaces, and splitting them out would leave a band too thin to read.
	 */
	const FETCH_OF_CATEGORY = array(
		'docs'     => 'files',
		'markdown' => 'files',
		'mcp'      => 'files',
		'webmcp'   => 'files',
		'robots'   => 'robots',
		'html'     => 'pages',
		'feed'     => 'pages',
		'notfound' => 'notfound',
	);

	/**
	 * Works out the overview from grouped rows.
	 *
	 * @param array $rows One row per agent, verdict and day: `name`, `category`, `verified`, `day`
	 *                    (Y-m-d in the site's timezone), `n`. Recognised names only; the loader
	 *                    drops the rest, and this drops any that slip through.
	 * @param array $ctx  `today` (Y-m-d, site timezone), `has_previous` (bool: the log reaches back
	 *                    over the whole previous period), `referrals` (`now`, `previous`, `shown`),
	 *                    `people_logged` (bool: every page view is being recorded).
	 * @param array $mix     The whole log over this period by site day and group: `day`, `group`
	 *                       (one of MIX_GROUPS), `n`, Spoofed rows included (in `rest`).
	 * @param array $fetched Identified agents' requests this period by site day and surface
	 *                       category: `day`, `category`, `n`. Spoofed rows left out by the loader.
	 * @return array{totals: array, daily: array, agents: array, more_agents: int, purposes: array, has_previous: bool, mix: array, fetched: array}
	 */
	public static function compute( array $rows, array $ctx, array $mix = array(), array $fetched = array() ) {
		$today = (string) $ctx['today'];
		$days  = array();
		for ( $i = self::WINDOW_DAYS - 1; $i >= 0; $i-- ) {
			$days[] = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - $i * DAY_IN_SECONDS );
		}
		$start    = $days[0];
		$prev_beg = gmdate( 'Y-m-d', strtotime( $start . ' 12:00:00 UTC' ) - self::WINDOW_DAYS * DAY_IN_SECONDS );

		$daily    = array_fill_keys( $days, 0 );
		$totals   = array(
			'requests'  => array( 0, 0 ),
			'confirmed' => array( 0, 0 ),
			'spoofed'   => array( 0, 0 ),
		);
		$names    = array( array(), array() );
		$agents   = array();
		$purposes = array();

		foreach ( $rows as $r ) {
			if ( MMSAR_Agent_Log::CRAWLER_UNRECOGNISED === $r['category'] || '' === (string) $r['category'] ) {
				continue;
			}
			$day = (string) $r['day'];
			if ( $day >= $start && $day <= $today ) {
				$p = 0;
			} elseif ( $day >= $prev_beg && $day < $start ) {
				$p = 1;
			} else {
				continue;
			}
			$n = (int) $r['n'];

			if ( MMSAR_Agent_Log_Verify::FAILED === $r['verified'] ) {
				$totals['spoofed'][ $p ] += $n;
				continue;
			}

			$totals['requests'][ $p ] += $n;
			$confirmed                 = in_array( $r['verified'], array( MMSAR_Agent_Log_Verify::VERIFIED, MMSAR_Agent_Log_Verify::CLIENT ), true );
			if ( $confirmed ) {
				$totals['confirmed'][ $p ] += $n;
			}
			$names[ $p ][ $r['name'] ] = true;

			if ( ! isset( $agents[ $r['name'] ] ) ) {
				$agents[ $r['name'] ] = array(
					'name'     => (string) $r['name'],
					'category' => (string) $r['category'],
					'n'        => 0,
					'previous' => 0,
					'verdicts' => array(),
				);
			}
			if ( 1 === $p ) {
				$agents[ $r['name'] ]['previous'] += $n;
				continue;
			}

			$verdict = (string) $r['verified'];

			$daily[ $day ]             += $n;
			$agents[ $r['name'] ]['n'] += $n;

			$agents[ $r['name'] ]['verdicts'][ $verdict ] = ( $agents[ $r['name'] ]['verdicts'][ $verdict ] ?? 0 ) + $n;
			$purposes[ $r['category'] ]                   = ( $purposes[ $r['category'] ] ?? 0 ) + $n;
		}

		// Only agents seen in this period are listed; one seen only before it is not a visitor now.
		$agents = array_values(
			array_filter(
				$agents,
				static function ( $a ) {
					return $a['n'] > 0;
				}
			)
		);
		usort(
			$agents,
			static function ( $a, $b ) {
				$by_count = $b['n'] <=> $a['n'];
				return 0 !== $by_count ? $by_count : strcmp( $a['name'], $b['name'] );
			}
		);
		foreach ( $agents as $i => $a ) {
			$agents[ $i ]['identity'] = self::identity( $a['verdicts'], $a['n'] );
			unset( $agents[ $i ]['verdicts'] );
		}
		arsort( $purposes );

		$has_previous = ! empty( $ctx['has_previous'] );
		$referrals    = isset( $ctx['referrals'] ) ? (array) $ctx['referrals'] : array();

		$series = array();
		foreach ( $daily as $day => $n ) {
			$series[] = array(
				'day'     => $day,
				'n'       => $n,
				'partial' => $day === $today,
			);
		}

		return array(
			'totals'       => array(
				'requests'  => self::pair( $totals['requests'], $has_previous ),
				'confirmed' => self::pair( $totals['confirmed'], $has_previous ),
				'spoofed'   => self::pair( $totals['spoofed'], $has_previous ),
				'agents'    => self::pair( array( count( $names[0] ), count( $names[1] ) ), $has_previous ),
				'referrals' => array(
					'now'      => (int) ( $referrals['now'] ?? 0 ),
					'previous' => $has_previous ? (int) ( $referrals['previous'] ?? 0 ) : null,
					'shown'    => ! empty( $referrals['shown'] ),
				),
			),
			'daily'        => $series,
			'agents'       => array_slice( $agents, 0, self::TOP_AGENTS ),
			'more_agents'  => max( 0, count( $agents ) - self::TOP_AGENTS ),
			'purposes'     => $purposes,
			'has_previous' => $has_previous,
			'mix'          => self::compute_mix( $mix, $days, ! empty( $ctx['people_logged'] ) ),
			'fetched'      => self::compute_fetched( $fetched, $days ),
		);
	}

	/**
	 * What identified agents fetched this period, split four ways, per day and in total.
	 *
	 * @param array    $fetched Rows of `day`, `category` (a surface category), `n`.
	 * @param string[] $days    The period's days, oldest first.
	 * @return array{groups: string[], daily: array<string, array<string, int>>, totals: array<string, int>, total: int}
	 */
	public static function compute_fetched( array $fetched, array $days ) {
		$rows = array();
		foreach ( $fetched as $r ) {
			$category = (string) $r['category'];
			if ( ! isset( self::FETCH_OF_CATEGORY[ $category ] ) ) {
				continue;
			}
			$rows[] = array(
				'day'   => (string) $r['day'],
				'group' => self::FETCH_OF_CATEGORY[ $category ],
				'n'     => (int) $r['n'],
			);
		}
		return self::tally( $rows, $days, self::FETCH_GROUPS );
	}

	/**
	 * Everything the log saw this period, split four ways, per day and in total.
	 *
	 * The People group is browsers, which is mostly people and sometimes an agent driving a real
	 * browser. It is dropped when page views from people are not being recorded, because then the
	 * only browser rows are the few that fetched an agent file, and a thin People band would read as
	 * "almost no human visitors" when the truth is "not measured".
	 *
	 * @param array    $mix           Rows of `day`, `group`, `n`.
	 * @param string[] $days          The period's days, oldest first.
	 * @param bool     $people_logged Whether every page view is being recorded.
	 * @return array{groups: string[], daily: array<string, array<string, int>>, totals: array<string, int>, total: int}
	 */
	public static function compute_mix( array $mix, array $days, $people_logged ) {
		$groups = $people_logged ? self::MIX_GROUPS : array_values( array_diff( self::MIX_GROUPS, array( 'people' ) ) );
		return self::tally( $mix, $days, $groups );
	}

	/**
	 * Rows of `day`, `group`, `n` summed per day and group, every day and group present.
	 *
	 * @param array    $rows   Rows; a day outside the period or an unknown group is skipped.
	 * @param string[] $days   The period's days, oldest first.
	 * @param string[] $groups The groups, in stacking order.
	 * @return array{groups: string[], daily: array<string, array<string, int>>, totals: array<string, int>, total: int}
	 */
	private static function tally( array $rows, array $days, array $groups ) {
		$zero   = array_fill_keys( $groups, 0 );
		$daily  = array_fill_keys( $days, $zero );
		$totals = $zero;
		foreach ( $rows as $r ) {
			$day   = (string) $r['day'];
			$group = (string) $r['group'];
			if ( ! isset( $daily[ $day ] ) || ! isset( $zero[ $group ] ) ) {
				continue;
			}
			$daily[ $day ][ $group ] += (int) $r['n'];
			$totals[ $group ]        += (int) $r['n'];
		}
		return array(
			'groups' => $groups,
			'daily'  => $daily,
			'totals' => $totals,
			'total'  => array_sum( $totals ),
		);
	}

	/**
	 * The mix group for a stored agent value, client type and verdict.
	 *
	 * The SQL in load_mix() applies the same rules in the same order; this is the reference the
	 * tests hold them to.
	 *
	 * @param string $category    Crawler category of the agent, '' or `unrecognised` when none.
	 * @param string $client_type Stored client type.
	 * @param string $verified    Stored verdict.
	 * @return string One of MIX_GROUPS.
	 */
	public static function mix_group( $category, $client_type, $verified = '' ) {
		if ( MMSAR_Agent_Log_Verify::FAILED === $verified ) {
			return 'rest';
		}
		if ( in_array( $category, self::AGENT_CATEGORIES, true ) ) {
			return 'agents';
		}
		if ( '' !== (string) $category && MMSAR_Agent_Log::CRAWLER_UNRECOGNISED !== $category ) {
			return 'known';
		}
		return MMSAR_Agent_Log::CLIENT_BROWSER === $client_type ? 'people' : 'rest';
	}

	/**
	 * Label for a mix group.
	 *
	 * @param string $group One of MIX_GROUPS.
	 * @return string
	 */
	public static function mix_label( $group ) {
		switch ( $group ) {
			case 'people':
				return __( 'People (browsers)', 'make-my-site-agent-ready' );
			case 'agents':
				return __( 'Identified agents', 'make-my-site-agent-ready' );
			case 'known':
				return __( 'Other known bots', 'make-my-site-agent-ready' );
			default:
				return __( 'Unidentified or spoofed', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Label for a fetch group.
	 *
	 * @param string $group One of FETCH_GROUPS.
	 * @return string
	 */
	public static function fetch_label( $group ) {
		switch ( $group ) {
			case 'files':
				return __( 'Agent files', 'make-my-site-agent-ready' );
			case 'robots':
				return __( 'robots.txt', 'make-my-site-agent-ready' );
			case 'pages':
				return __( 'Site pages', 'make-my-site-agent-ready' );
			default:
				return __( 'Not found', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * A current value and, when there is a full previous period, the previous one.
	 *
	 * @param int[] $values       Current, previous.
	 * @param bool  $has_previous Whether the previous value means anything.
	 * @return array{now: int, previous: int|null}
	 */
	private static function pair( array $values, $has_previous ) {
		return array(
			'now'      => (int) $values[0],
			'previous' => $has_previous ? (int) $values[1] : null,
		);
	}

	/**
	 * One word for how far an agent's requests were confirmed.
	 *
	 * @param array<string, int> $verdicts Verdict => requests, Spoofed already excluded.
	 * @param int                $total    Requests.
	 * @return string `verified`, `client`, `partly`, `uncheckable` or `unconfirmed`.
	 */
	public static function identity( array $verdicts, $total ) {
		if ( $total < 1 ) {
			return 'unconfirmed';
		}
		$client   = (int) ( $verdicts[ MMSAR_Agent_Log_Verify::CLIENT ] ?? 0 );
		$verified = (int) ( $verdicts[ MMSAR_Agent_Log_Verify::VERIFIED ] ?? 0 );
		if ( ( $client + $verified ) / $total >= self::VERIFIED_SHARE ) {
			return $client > $verified ? 'client' : 'verified';
		}
		if ( $client + $verified > 0 ) {
			return 'partly';
		}
		if ( (int) ( $verdicts[ MMSAR_Agent_Log_Verify::UNVERIFIABLE ] ?? 0 ) * 2 > $total ) {
			return 'uncheckable';
		}
		return 'unconfirmed';
	}

	/**
	 * Label for an identity word.
	 *
	 * @param string $identity From identity().
	 * @return string
	 */
	public static function identity_label( $identity ) {
		switch ( $identity ) {
			case 'verified':
				return __( 'Verified', 'make-my-site-agent-ready' );
			case 'client':
				return __( 'User-run', 'make-my-site-agent-ready' );
			case 'partly':
				return __( 'Partly verified', 'make-my-site-agent-ready' );
			case 'uncheckable':
				return __( 'Can\'t be checked', 'make-my-site-agent-ready' );
			default:
				return __( 'Not confirmed', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Change from the previous period as a whole percentage, or null when there is nothing to say.
	 *
	 * @param int      $now      Current value.
	 * @param int|null $previous Previous value, or null without a previous period.
	 * @return int|null
	 */
	public static function change( $now, $previous ) {
		if ( null === $previous || $previous < 1 ) {
			return null;
		}
		return (int) round( 100 * ( $now - $previous ) / $previous );
	}

	/**
	 * The overview, computed or from cache.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return array
	 */
	public static function get( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			// A cache written by another version of the plugin may be missing parts this one draws,
			// as dev.4's was missing the traffic mix that dev.6 added, so it is recomputed instead.
			if ( is_array( $cached ) && isset( $cached['version'] ) && MMSAR_VERSION === $cached['version'] ) {
				return $cached;
			}
		}
		$offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) );
		$today  = gmdate( 'Y-m-d', time() + $offset );

		$result            = self::compute( self::load_rows( $today, $offset ), self::load_context( $today, $offset ), self::load_mix( $today, $offset ), self::load_fetched( $today, $offset ) );
		$result['version'] = MMSAR_VERSION;
		set_transient( self::CACHE_KEY, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * The UTC moment the previous period starts: midnight, site time, sixty days before today.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return string Stored datetime format.
	 */
	private static function since( $today, $offset ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 00:00:00 UTC' ) - ( 2 * self::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS - $offset );
	}

	/**
	 * Requests from recognised names over both periods, by agent, verdict and site day.
	 *
	 * Distinct agent values are categorised in PHP first and only recognised ones read, matched by
	 * MD5, the same way MMSAR_Agent_Insights reads AI crawlers.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return array
	 */
	private static function load_rows( $today, $offset ) {
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = MMSAR_Agent_Log::table();
		$since = self::since( $today, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; the result is cached for an hour.
		$agents = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent FROM %i WHERE logged_at >= %s', $table, $since ) );
		$keep   = array();
		foreach ( (array) $agents as $agent ) {
			if ( MMSAR_Agent_Log::CRAWLER_UNRECOGNISED !== MMSAR_Agent_Log::crawler_category( $agent ) ) {
				$keep[] = md5( $agent );
			}
		}
		if ( ! $keep ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, verified, DATE( DATE_ADD( logged_at, INTERVAL %d SECOND ) ) AS day, COUNT(*) AS n FROM %i WHERE logged_at >= %s AND FIND_IN_SET( MD5( agent ), %s ) GROUP BY agent, verified, day',
				$offset,
				$table,
				$since,
				implode( ',', $keep )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$name  = (string) MMSAR_Agent_Log_Verify::claimed_name( $r['agent'] );
			$out[] = array(
				'name'     => '' !== $name ? $name : (string) $r['agent'],
				'category' => MMSAR_Agent_Log::crawler_category( $r['agent'] ),
				'verified' => (string) $r['verified'],
				'day'      => (string) $r['day'],
				'n'        => (int) $r['n'],
			);
		}
		return $out;
	}

	/**
	 * The UTC moment this period starts: midnight, site time, 29 days before today.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return string Stored datetime format.
	 */
	private static function period_start( $today, $offset ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( $today . ' 00:00:00 UTC' ) - ( self::WINDOW_DAYS - 1 ) * DAY_IN_SECONDS - $offset );
	}

	/**
	 * MD5s of the agent values seen since a moment, sorted into identified agents and other known
	 * bots. Unrecognised values are in neither: the queries decide those by client type.
	 *
	 * @param string $since Stored-format UTC datetime.
	 * @return array{agents: string[], known: string[]}
	 */
	private static function named_lists( $since ) {
		static $memo = array();
		if ( isset( $memo[ $since ] ) ) {
			return $memo[ $since ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; the result is cached for an hour.
		$values = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent FROM %i WHERE logged_at >= %s', MMSAR_Agent_Log::table(), $since ) );
		$lists  = array(
			'agents' => array(),
			'known'  => array(),
		);
		foreach ( (array) $values as $agent ) {
			$group = self::mix_group( MMSAR_Agent_Log::crawler_category( $agent ), '' );
			if ( isset( $lists[ $group ] ) ) {
				$lists[ $group ][] = md5( $agent );
			}
		}
		$memo[ $since ] = $lists;
		return $lists;
	}

	/**
	 * Every request this period, by site day and mix group.
	 *
	 * The table holds a row per request, people's page views included, so the grouping happens in
	 * SQL: the distinct agent values are sorted into groups in PHP first, and the query matches each
	 * row's agent against those lists by MD5, falling back to its client type. A Spoofed row goes
	 * to `rest` before anything else, as in mix_group(). Only the per-day, per-group counts come
	 * back.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return array
	 */
	private static function load_mix( $today, $offset ) {
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return array();
		}
		global $wpdb;
		$since = self::period_start( $today, $offset );
		$lists = self::named_lists( $since );
		// FIND_IN_SET against an empty list matches nothing, which is what an empty group should do.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; the result is cached for an hour.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE( DATE_ADD( logged_at, INTERVAL %d SECOND ) ) AS day,'
				. ' CASE WHEN verified = %s THEN %s WHEN FIND_IN_SET( MD5( agent ), %s ) THEN %s WHEN FIND_IN_SET( MD5( agent ), %s ) THEN %s WHEN client_type = %s THEN %s ELSE %s END AS grp,'
				. ' COUNT(*) AS n FROM %i WHERE logged_at >= %s GROUP BY day, grp',
				$offset,
				MMSAR_Agent_Log_Verify::FAILED,
				'rest',
				implode( ',', $lists['agents'] ),
				'agents',
				implode( ',', $lists['known'] ),
				'known',
				MMSAR_Agent_Log::CLIENT_BROWSER,
				'people',
				'rest',
				MMSAR_Agent_Log::table(),
				$since
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'day'   => (string) $r['day'],
				'group' => (string) $r['grp'],
				'n'     => (int) $r['n'],
			);
		}
		return $out;
	}

	/**
	 * What identified agents fetched this period, by site day and surface category.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return array
	 */
	private static function load_fetched( $today, $offset ) {
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return array();
		}
		$since = self::period_start( $today, $offset );
		return MMSAR_Agent_Log::daily_categories_for_agents( $since, $offset, self::named_lists( $since )['agents'] );
	}

	/**
	 * Whether the log covers the previous period, and the referral counts for both.
	 *
	 * @param string $today  Today, site time.
	 * @param int    $offset Site offset from UTC in seconds.
	 * @return array
	 */
	private static function load_context( $today, $offset ) {
		$has_previous = false;
		if ( MMSAR_Agent_Log::table_exists() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
			$oldest       = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(logged_at) FROM %i', MMSAR_Agent_Log::table() ) );
			$has_previous = '' !== $oldest && $oldest <= self::since( $today, $offset );
		}

		// The referral table keeps days, not times, so its periods are UTC days. Close enough for a
		// count of visitors, and the tile says nothing more precise than the number.
		$now  = MMSAR_Referrals::get_summary( self::WINDOW_DAYS );
		$both = MMSAR_Referrals::get_summary( 2 * self::WINDOW_DAYS );

		return array(
			'today'         => $today,
			'has_previous'  => $has_previous,
			'people_logged' => 'all' === MMSAR_Agent_Log::page_view_mode(),
			'referrals'     => array(
				'now'      => (int) $now['total'],
				'previous' => max( 0, (int) $both['total'] - (int) $now['total'] ),
				'shown'    => MMSAR_Referrals::is_enabled() || (int) $both['total'] > 0,
			),
		);
	}

	/**
	 * Drops the cache.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
