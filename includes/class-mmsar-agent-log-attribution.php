<?php
/**
 * Who was actually behind a forged crawler identity.
 *
 * Verification answers "is this really GPTBot" and returns `failed` when it is not. It cannot say
 * what it *was*, and that is the question worth answering: a scanner that forges six operator names
 * inside one second is one client wearing six masks, not six operators misbehaving.
 *
 * The rule shipped here names no vendor. An address that presents several crawler identities in a
 * short burst, at least one of them honestly, is a single client — so the forged rows are attributed
 * to the identity it declared for itself. A scanner announces a name nobody publishes a verification
 * method for (`unclaimed` or `unverifiable`), which is exactly the shape that makes this safe:
 * **a verified crawler is never used as an attributor**, so this can never produce "ClaudeBot,
 * spoofed by GPTBot". Sites that want a known scanner named explicitly add a signature through
 * `mmsar_agent_log_scanner_signatures` rather than waiting on a release.
 *
 * **Derived, never stored.** Attribution is an inference from neighbouring rows, not a fact about
 * the request, and inferences go stale: a datacenter address is reassigned, and a mapping written
 * to the database would keep accusing whoever holds it next. Computing it per read also keeps it
 * bounded to the burst that produced the evidence, which is the whole basis for believing it.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log attribution.
 */
class MMSAR_Agent_Log_Attribution {

	/**
	 * How far apart two requests may be and still count as one burst, in seconds.
	 *
	 * Observed scan runs finish in seconds; the longest seen spanned about six minutes. Thirty
	 * minutes is generous enough for a slow crawl and far short of the days or weeks over which a
	 * cloud address changes hands, which is the failure this bound exists to prevent.
	 */
	const WINDOW = 1800;

	/**
	 * Most candidate rows to consider in one pass.
	 */
	const MAX_CANDIDATES = 2000;

	/**
	 * Known scanner signatures.
	 *
	 * The general correlation below needs the scanner to have declared itself honestly at least
	 * once in the burst. That is not guaranteed — a run may forge every identity it sends and
	 * declare nothing, and then only the request paths give it away. A signature catches that case.
	 *
	 * `ua` matches anywhere in the user-agent and `path` matches the start of the recorded detail.
	 * Prefer a token the operator owns — its own domain, or a probe path it invented. Do **not**
	 * match on a bare product name: "ora" appears inside `collaboration`, which is a real page on
	 * at least one site running this plugin.
	 *
	 * @return array[] Each: operator (string), ua (string, optional), path (string, optional).
	 */
	public static function signatures() {
		$defaults = array(
			array(
				'operator' => 'Ora',
				'ua'       => 'ora.ai',
				'path'     => '/__ora-404-probe-',
			),
		);

		/**
		 * Filters the scanner signatures used to attribute forged crawler identities.
		 *
		 * @param array[] $signatures Each: operator, and one or both of ua / path.
		 */
		$filtered = apply_filters( 'mmsar_agent_log_scanner_signatures', $defaults );

		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * The network an address belongs to.
	 *
	 * Deliberately the same reduction `MMSAR_Agent_Log::anonymize_ip()` applies, because the log
	 * stores one client under two different addresses: an unrecognized crawler's ordinary page
	 * views are anonymized to the network, while its requests for agent-facing files keep the full
	 * address. Ora's own traffic splits about a third / two thirds that way, so matching on the
	 * exact address would miss a large part of every burst. Reducing both forms to the same key
	 * rejoins them by construction rather than by a rule that has to be kept in step.
	 *
	 * @param string $ip Address.
	 * @return string Network key, or '' when the address will not parse.
	 */
	public static function network( $ip ) {
		return MMSAR_Agent_Log::anonymize_ip( $ip );
	}

	/**
	 * Short display name for an attributor.
	 *
	 * @param string $agent Raw user-agent.
	 * @return string Product token, e.g. `OraBot`.
	 */
	private static function short_name( $agent ) {
		$agent = trim( (string) $agent );
		if ( '' === $agent ) {
			return '';
		}
		$token = strtok( $agent, '/ ' );
		return false === $token ? $agent : $token;
	}

	/**
	 * Which signature, if any, a row matches.
	 *
	 * @param string $agent  User-agent.
	 * @param string $detail Recorded detail, usually a path.
	 * @return string Operator name, or ''.
	 */
	private static function signature_for( $agent, $detail ) {
		$agent  = strtolower( (string) $agent );
		$detail = (string) $detail;

		foreach ( self::signatures() as $sig ) {
			if ( empty( $sig['operator'] ) ) {
				continue;
			}
			if ( ! empty( $sig['ua'] ) && false !== strpos( $agent, strtolower( (string) $sig['ua'] ) ) ) {
				return (string) $sig['operator'];
			}
			if ( ! empty( $sig['path'] ) && 0 === strpos( $detail, (string) $sig['path'] ) ) {
				return (string) $sig['operator'];
			}
		}

		return '';
	}

	/**
	 * Attribution for a set of rows, keyed by row id.
	 *
	 * Only rows whose identity was forged are attributed. A row that told the truth needs no
	 * explanation, and labelling it would turn a log of what was claimed into a log of what this
	 * code guessed.
	 *
	 * Keyed by the caller's own array keys rather than by row id, because the log's read paths do
	 * not all select one — `get_entries()` returns no id at all.
	 *
	 * @param array[] $rows Rows carrying at least logged_at, agent, ip, verified.
	 * @return array<int|string, string> Row key => attributor display name.
	 */
	public static function for_rows( $rows ) {
		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		$targets = array();
		$span_lo = null;
		$span_hi = null;
		foreach ( $rows as $key => $r ) {
			if ( ! isset( $r['verified'] ) || MMSAR_Agent_Log_Verify::FAILED !== $r['verified'] ) {
				continue;
			}
			$net = self::network( isset( $r['ip'] ) ? $r['ip'] : '' );
			if ( '' === $net ) {
				continue;
			}
			$t         = strtotime( (string) $r['logged_at'] . ' UTC' );
			$targets[] = array(
				'key' => $key,
				'net' => $net,
				't'   => $t,
			);
			$span_lo   = ( null === $span_lo ) ? $t : min( $span_lo, $t );
			$span_hi   = ( null === $span_hi ) ? $t : max( $span_hi, $t );
		}

		if ( array() === $targets ) {
			return array();
		}

		$candidates = self::candidates( $span_lo - self::WINDOW, $span_hi + self::WINDOW );
		if ( array() === $candidates ) {
			return array();
		}

		$out = array();
		foreach ( $targets as $target ) {
			$best    = '';
			$best_dt = null;
			foreach ( $candidates as $c ) {
				if ( $c['net'] !== $target['net'] ) {
					continue;
				}
				$dt = abs( $c['t'] - $target['t'] );
				if ( $dt > self::WINDOW ) {
					continue;
				}
				// A named signature beats a correlated self-declared name: it is evidence about who
				// the client is, rather than an inference from what else shared its network.
				if ( '' !== $c['operator'] ) {
					if ( '' === $best || null === $best_dt || $dt < $best_dt || ! self::is_named( $best ) ) {
						$best    = $c['operator'];
						$best_dt = $dt;
					}
					continue;
				}
				if ( '' === $best && '' !== $c['declared'] ) {
					$best    = $c['declared'];
					$best_dt = $dt;
				}
			}
			if ( '' !== $best ) {
				$out[ $target['key'] ] = $best;
			}
		}

		return $out;
	}

	/**
	 * The same rows back, with `attributed_to` filled in where there is evidence.
	 *
	 * Every row gets the key so a consumer never has to distinguish "absent" from "not attributed";
	 * an empty string is the honest answer for a request nothing explains.
	 *
	 * @param array[] $rows Rows from the log.
	 * @return array[] Rows, each with an `attributed_to` string.
	 */
	public static function annotate( $rows ) {
		if ( ! is_array( $rows ) || array() === $rows ) {
			return $rows;
		}
		$map = self::for_rows( $rows );
		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['attributed_to'] = isset( $map[ $key ] ) ? $map[ $key ] : '';
		}
		return $rows;
	}

	/**
	 * Whether a chosen attributor came from a signature rather than from correlation.
	 *
	 * @param string $name Attributor.
	 * @return bool
	 */
	private static function is_named( $name ) {
		foreach ( self::signatures() as $sig ) {
			if ( ! empty( $sig['operator'] ) && $sig['operator'] === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rows that could explain a forged identity, within a time span.
	 *
	 * @param int $from Unix time.
	 * @param int $to   Unix time.
	 * @return array[] Each: net, t, operator, declared.
	 */
	private static function candidates( $from, $to ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT logged_at, agent, detail, ip, verified, client_type
				FROM %i
				WHERE logged_at BETWEEN %s AND %s
				ORDER BY id DESC LIMIT %d',
				MMSAR_Agent_Log::table(),
				gmdate( 'Y-m-d H:i:s', (int) $from ),
				gmdate( 'Y-m-d H:i:s', (int) $to ),
				self::MAX_CANDIDATES
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$net = self::network( isset( $r['ip'] ) ? $r['ip'] : '' );
			if ( '' === $net ) {
				continue;
			}

			$operator = self::signature_for(
				isset( $r['agent'] ) ? $r['agent'] : '',
				isset( $r['detail'] ) ? $r['detail'] : ''
			);

			// A self-declared bot whose name nobody publishes a check for. Verified crawlers are
			// excluded on purpose: attributing a forgery to an operator that proved its identity
			// would be an accusation this evidence cannot support.
			$declared = '';
			$verdict  = isset( $r['verified'] ) ? $r['verified'] : '';
			$is_bot   = ( isset( $r['client_type'] ) && 'crawler' === $r['client_type'] );
			if ( $is_bot && in_array( $verdict, array( MMSAR_Agent_Log_Verify::UNCLAIMED, MMSAR_Agent_Log_Verify::UNVERIFIABLE ), true ) ) {
				$declared = self::short_name( isset( $r['agent'] ) ? $r['agent'] : '' );
			}

			if ( '' === $operator && '' === $declared ) {
				continue;
			}

			$out[] = array(
				'net'      => $net,
				't'        => strtotime( (string) $r['logged_at'] . ' UTC' ),
				'operator' => $operator,
				'declared' => $declared,
			);
		}

		return $out;
	}
}
