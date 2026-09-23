<?php
/**
 * Three signals for browser-shaped traffic that the client type cannot see.
 *
 * `client_type` separates browser engines from HTTP clients. It cannot separate a person from an
 * agent driving a real browser, because that agent *is* a browser and sends everything a reader
 * sends. These three signals are the evidence this log can gather about that population without
 * storing anything new about the people in it:
 *
 * 1. **Signed** — the request carried a Web Bot Auth signature (HTTP Message Signatures, RFC 9421,
 *    with `tag="web-bot-auth"`) and a `Signature-Agent` naming the operator. Stored as the claimed
 *    origin. **A claim, not a verification**: nothing here fetches the operator's key directory or
 *    checks the signature, and the draft says outright that an unresolved Signature-Agent "is a
 *    claim rather than an identity". Human browsers never sign, so this stores nothing about readers.
 * 2. **Cloud network** — a browser-shaped request from a published cloud-provider range. **Derived
 *    on read, never stored**, from the address the log already keeps. That works because almost all
 *    cloud address space is published in blocks of /24 or wider (IPv4) and /64 or wider (IPv6) —
 *    exactly the precision the log reduces a reader's address to — so the stored network answers the
 *    question. Where it cannot, because a stored /24 only partly overlaps a narrower cloud block,
 *    the answer is `partial`: "cannot tell", never a guess. Being derived also makes it retroactive.
 *    **A fact about the network, not a verdict on the visitor**: people on some VPNs and corporate
 *    security proxies arrive from the same ranges.
 * 3. **Came from a link on this site** — the Referer's host is this site's host. Stored as yes/no,
 *    never the Referer itself, so a click is told apart from a direct fetch without keeping browsing
 *    history.
 *
 * None of these moves a row out of the browser denominator. They annotate it.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log signals.
 */
class MMSAR_Agent_Log_Signals {

	/**
	 * Filter values, in the order they are offered.
	 */
	const SIGNED    = 'signed';
	const CLOUD     = 'cloud';
	const SAME_SITE = 'same_site';

	/**
	 * Stored signature_agent value for a Web Bot Auth signature that named no usable operator: the
	 * header was missing, or held no https URL. Kept distinct from '' so "signed" still counts.
	 */
	const UNNAMED = '(unnamed)';

	/**
	 * What cloud_network() returns for a stored network that straddles the edge of a cloud range.
	 */
	const CLOUD_PARTIAL = 'partial';

	/**
	 * Longest stored signature_agent value; the column is varchar(100).
	 */
	const AGENT_MAX = 100;

	/**
	 * The bundled cloud range data, loaded once per request and only when something asks.
	 *
	 * @var array|null
	 */
	private static $cloud = null;

	/**
	 * Every signal value, for filters and schemas.
	 *
	 * @return string[]
	 */
	public static function signals() {
		return array( self::SIGNED, self::CLOUD, self::SAME_SITE );
	}

	/**
	 * Human-readable label for a signal.
	 *
	 * @param string $signal Signal value.
	 * @return string
	 */
	public static function label( $signal ) {
		switch ( $signal ) {
			case self::SIGNED:
				return __( 'Signed (unverified)', 'make-my-site-agent-ready' );
			case self::CLOUD:
				return __( 'Cloud network', 'make-my-site-agent-ready' );
			case self::SAME_SITE:
				return __( 'Came from a link here', 'make-my-site-agent-ready' );
			default:
				return '';
		}
	}

	// -------------------------------------------------------------------------
	// Signal 1: Web Bot Auth signatures
	// -------------------------------------------------------------------------

	/**
	 * What the current request's signature claims, as it will be stored.
	 *
	 * @return string '' when unsigned, UNNAMED, or the claimed operator origin.
	 */
	public static function request_signature_agent() {
		return self::signature_agent_from_headers(
			self::header( 'HTTP_SIGNATURE' ),
			self::header( 'HTTP_SIGNATURE_INPUT' ),
			self::header( 'HTTP_SIGNATURE_AGENT' )
		);
	}

	/**
	 * What a set of signature headers claims, reduced to the value this log stores.
	 *
	 * A request counts as signed only when it carries both `Signature` and `Signature-Input`, and
	 * the input declares `tag="web-bot-auth"` — the draft's own marker. Other uses of HTTP Message
	 * Signatures exist and are not what this signal is about.
	 *
	 * `Signature-Agent` comes in two shapes, and both are read. The current draft makes it a
	 * Structured Fields dictionary, `sig1="https://chatgpt.com"`, optionally with parameters; earlier
	 * drafts sent a bare string, `"https://chatgpt.com"`, and deployments still do. Either way the
	 * value is the first quoted https URL, reduced to its origin: the path of a key directory is not
	 * an identity, and an origin is a bounded, comparable thing to store. A request whose header is
	 * missing or names nothing usable is stored as UNNAMED, so it still counts as signed.
	 *
	 * Deliberately not a verifier. No key is fetched and no signature is checked, and nothing may
	 * treat the result as an identity. See the decisions log.
	 *
	 * @param string $signature       Signature header value.
	 * @param string $signature_input Signature-Input header value.
	 * @param string $signature_agent Signature-Agent header value.
	 * @return string '' when unsigned, UNNAMED, or an origin such as `https://chatgpt.com`.
	 */
	public static function signature_agent_from_headers( $signature, $signature_input, $signature_agent ) {
		$signature       = trim( (string) $signature );
		$signature_input = (string) $signature_input;
		if ( '' === $signature || '' === trim( $signature_input ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/;\s*tag\s*=\s*"web-bot-auth"/i', $signature_input ) ) {
			return '';
		}

		$agent = substr( (string) $signature_agent, 0, 1000 );
		if ( ! preg_match_all( '/"([^"\\\\]*)"/', $agent, $matches ) ) {
			return self::UNNAMED;
		}
		foreach ( $matches[1] as $candidate ) {
			$origin = self::origin_of( $candidate );
			if ( '' !== $origin ) {
				return $origin;
			}
		}
		return self::UNNAMED;
	}

	/**
	 * The https origin of a URL, lowercased, or '' when it is not an https URL with a host.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 !== preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
			return '';
		}
		$port   = isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
		$origin = 'https://' . $host . $port;
		return strlen( $origin ) <= self::AGENT_MAX ? $origin : '';
	}

	/**
	 * Display name for a stored signature_agent value: the host, or a note for UNNAMED.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function signature_agent_label( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( self::UNNAMED === $stored ) {
			return __( 'no operator named', 'make-my-site-agent-ready' );
		}
		return (string) preg_replace( '~^https://~', '', $stored );
	}

	// -------------------------------------------------------------------------
	// Signal 3: came from a link on this site
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request's Referer is this site, as it will be stored.
	 *
	 * @return int 1 or 0.
	 */
	public static function request_same_site() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = $host;
			}
		}
		return self::is_same_site( self::header( 'HTTP_REFERER' ), $hosts ) ? 1 : 0;
	}

	/**
	 * Whether a Referer value points at one of this site's hosts.
	 *
	 * Only the host is compared, and only the yes/no is kept. A missing Referer and an off-site one
	 * both answer no: the first is a direct fetch (or a policy that strips it), the second arrived
	 * from elsewhere, and telling those two apart would mean storing more about a reader than this
	 * signal needs. The live site sends `strict-origin-when-cross-origin`, the browser default, under
	 * which a click from one of its own pages carries the full Referer.
	 *
	 * Exact host match, case-insensitive. `www.` and the bare domain are different hosts here, which
	 * matches how the site itself treats them — one of the two redirects to the other.
	 *
	 * @param string   $referer Referer header value.
	 * @param string[] $hosts   This site's hosts.
	 * @return bool
	 */
	public static function is_same_site( $referer, $hosts ) {
		$referer = trim( (string) $referer );
		if ( '' === $referer ) {
			return false;
		}
		$host = wp_parse_url( $referer, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		foreach ( (array) $hosts as $candidate ) {
			if ( '' !== (string) $candidate && 0 === strcasecmp( $host, (string) $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Signal 2: cloud network, derived from the stored address
	// -------------------------------------------------------------------------

	/**
	 * The bundled cloud range data, decoded and filtered.
	 *
	 * The file stores each family as base64 of fixed-width big-endian records (see its header), so
	 * loading it is one include and two base64_decode() calls, and a lookup reads records in place
	 * rather than building 7,000 PHP arrays on every request that asks.
	 *
	 * @return array{providers: array, keys: string[], v4: string, v6: string}
	 */
	private static function cloud_data() {
		if ( null === self::$cloud ) {
			$data        = include __DIR__ . '/data/cloud-ranges.php';
			$data        = is_array( $data ) ? $data : array();
			self::$cloud = array(
				'providers' => isset( $data['providers'] ) ? (array) $data['providers'] : array(),
				'v4'        => isset( $data['v4'] ) ? (string) base64_decode( (string) $data['v4'] ) : '', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the plugin's own bundled range data, not obfuscated code.
				'v6'        => isset( $data['v6'] ) ? (string) base64_decode( (string) $data['v6'] ) : '', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- As above.
			);
		}

		/**
		 * Filters the cloud-provider ranges used for the "cloud network" signal.
		 *
		 * `v4` and `v6` are binary strings of fixed-width big-endian records, sorted by start and
		 * non-overlapping — the lookup is a binary search and depends on both. An IPv4 record is
		 * pack( 'NNC', start, end, provider index ); an IPv6 record is the upper 64 bits of start
		 * and of end, 8 bytes each, then the index byte. The index is the provider's position in
		 * `providers`, which maps each key to a label and capture date.
		 *
		 * @param array $data Decoded cloud range data.
		 */
		$data = (array) apply_filters( 'mmsar_agent_log_cloud_ranges', self::$cloud );

		$providers = isset( $data['providers'] ) ? (array) $data['providers'] : array();
		return array(
			'providers' => $providers,
			'keys'      => array_map( 'strval', array_keys( $providers ) ),
			'v4'        => isset( $data['v4'] ) ? (string) $data['v4'] : '',
			'v6'        => isset( $data['v6'] ) ? (string) $data['v6'] : '',
		);
	}

	/**
	 * Provider key => label.
	 *
	 * @return array<string, string>
	 */
	public static function cloud_providers() {
		$out = array();
		foreach ( self::cloud_data()['providers'] as $key => $provider ) {
			$out[ (string) $key ] = isset( $provider['label'] ) ? (string) $provider['label'] : (string) $key;
		}
		return $out;
	}

	/**
	 * Display label for a cloud_network() result.
	 *
	 * @param string $key Provider key, CLOUD_PARTIAL or ''.
	 * @return string
	 */
	public static function cloud_label( $key ) {
		if ( self::CLOUD_PARTIAL === $key ) {
			return __( 'possibly a cloud network', 'make-my-site-agent-ready' );
		}
		$providers = self::cloud_providers();
		return isset( $providers[ $key ] ) ? $providers[ $key ] : '';
	}

	/**
	 * The oldest capture date across the bundled cloud ranges, "Y-m-d", or '' with no data.
	 *
	 * Reported beside the signal for the same reason ranges_captured() is reported beside the
	 * verdicts. Here staleness fails the harmless way — a range added after capture reads as "not
	 * a cloud network" — but a reader still needs the date to know how much to trust a "no".
	 *
	 * @return string
	 */
	public static function cloud_ranges_captured() {
		$dates = array();
		foreach ( self::cloud_data()['providers'] as $provider ) {
			if ( ! empty( $provider['captured'] ) ) {
				$dates[] = (string) $provider['captured'];
			}
		}
		if ( ! $dates ) {
			return '';
		}
		sort( $dates );
		return $dates[0];
	}

	/**
	 * Which cloud provider's published range an address sits in.
	 *
	 * Works on either precision the log stores. A full address is looked up as itself. A reduced
	 * one — an address equal to its own network under MMSAR_Agent_Log::anonymize_ip(), the same test
	 * the verifier uses — stands for its whole /24 or /64, and is only called a cloud network when
	 * a single published range covers all of it. A /24 that only partly overlaps a narrower cloud
	 * block is CLOUD_PARTIAL: some addresses in it are cloud and some may not be, and the stored
	 * value cannot say which one made the request.
	 *
	 * IPv6 needs no such care: every published IPv6 range is a /64 or wider (the generator refuses
	 * anything narrower), so the upper 64 bits of any address decide it exactly.
	 *
	 * @param string $ip Stored address.
	 * @return string Provider key, CLOUD_PARTIAL, or '' for no cloud range or an unparseable value.
	 */
	public static function cloud_network( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$data = self::cloud_data();

		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}

		if ( 16 === strlen( $packed ) ) {
			$upper = substr( $packed, 0, 8 );
			return self::lookup( $data['v6'], 8, $upper, $upper, $data['keys'] );
		}

		// A reduced IPv4 address stands for its whole /24, so the span is .0 to .255.
		$reduced = MMSAR_Agent_Log::anonymize_ip( $ip ) === $ip;
		$start   = $reduced ? substr( $packed, 0, 3 ) . "\x00" : $packed;
		$end     = $reduced ? substr( $packed, 0, 3 ) . "\xFF" : $packed;
		return self::lookup( $data['v4'], 4, $start, $end, $data['keys'] );
	}

	/**
	 * Binary search for the range covering [start, end].
	 *
	 * Finds the last range starting at or before `end`. In a sorted, non-overlapping list that is
	 * the only range that can contain the span, and the only one that can overlap it without
	 * containing it — so one comparison each answers "covered", "partly covered" and "not at all".
	 *
	 * Every bound is a big-endian byte string of the same width, so strcmp() orders them exactly as
	 * the numbers they encode. No integer conversion, which keeps IPv6 exact on any platform.
	 *
	 * @param string   $records Packed records: start, end, then one provider-index byte.
	 * @param int      $width   Bytes per bound: 4 for IPv4, 8 for IPv6.
	 * @param string   $start   Span start, $width bytes.
	 * @param string   $end     Span end, $width bytes.
	 * @param string[] $keys    Provider keys by index.
	 * @return string Provider key, CLOUD_PARTIAL or ''.
	 */
	private static function lookup( $records, $width, $start, $end, $keys ) {
		$size  = $width * 2 + 1;
		$lo    = 0;
		$hi    = intdiv( strlen( $records ), $size ) - 1;
		$found = -1;
		while ( $lo <= $hi ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( strcmp( substr( $records, $mid * $size, $width ), $end ) <= 0 ) {
				$found = $mid;
				$lo    = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}
		if ( $found < 0 ) {
			return '';
		}

		$record = substr( $records, $found * $size, $size );
		$first  = substr( $record, 0, $width );
		$last   = substr( $record, $width, $width );
		if ( strcmp( $last, $start ) < 0 ) {
			return '';
		}
		if ( strcmp( $first, $start ) <= 0 && strcmp( $last, $end ) >= 0 ) {
			$index = ord( $record[ $size - 1 ] );
			return isset( $keys[ $index ] ) ? $keys[ $index ] : '';
		}
		return self::CLOUD_PARTIAL;
	}

	// -------------------------------------------------------------------------
	// Putting them together
	// -------------------------------------------------------------------------

	/**
	 * Whether an agent-file request is probably a person following a link, and so should have its
	 * address reduced like a page view.
	 *
	 * Agent-facing files keep the caller's full address, whoever it is — it is what identified the
	 * scanner pool. But a real browser that followed a link on this site to one of those files,
	 * unsigned and from outside every cloud range, is overwhelmingly a reader clicking the footer's
	 * llms.txt link. Keeping that person's full address would store more about a human than the
	 * page-view rule allows. All four conditions must hold; the cloud check runs last and only then,
	 * because it is the one that loads the range data.
	 *
	 * **This is the one place a signal is read at request time on the full address**, and nothing
	 * from it is stored: its only effect is that less is stored.
	 *
	 * @param string $client_type     Detected client type.
	 * @param int    $same_site       Same-site Referer, 1 or 0.
	 * @param string $signature_agent Stored signature value for the request.
	 * @param string $ip              Full client address.
	 * @return bool
	 */
	public static function is_probably_reader( $client_type, $same_site, $signature_agent, $ip ) {
		return MMSAR_Agent_Log::CLIENT_BROWSER === $client_type
			&& 1 === (int) $same_site
			&& '' === (string) $signature_agent
			&& '' === self::cloud_network( $ip );
	}

	/**
	 * The three signals for one stored row, as reported by the ability and drawn on the screen.
	 *
	 * The cloud signal is only assessed on browser rows. A script or a crawler arriving from a cloud
	 * is the ordinary case and says nothing new; the signal exists to pick agents out of the one
	 * population that otherwise looks human.
	 *
	 * @param array $row Stored row.
	 * @return array{signature_agent: string, cloud_network: ?string, same_site: ?bool}
	 */
	public static function for_row( $row ) {
		$client = isset( $row['client_type'] ) ? (string) $row['client_type'] : '';
		$same   = isset( $row['same_site'] ) && '' !== (string) $row['same_site'] ? ( 1 === (int) $row['same_site'] ) : null;
		return array(
			'signature_agent' => isset( $row['signature_agent'] ) ? (string) $row['signature_agent'] : '',
			'cloud_network'   => MMSAR_Agent_Log::CLIENT_BROWSER === $client ? self::cloud_network( isset( $row['ip'] ) ? (string) $row['ip'] : '' ) : null,
			'same_site'       => $same,
		);
	}

	/**
	 * Adds `signals` to each row of a page.
	 *
	 * @param array[] $rows Stored rows.
	 * @return array[]
	 */
	public static function annotate( $rows ) {
		foreach ( (array) $rows as $i => $row ) {
			$rows[ $i ]['signals'] = self::for_row( (array) $row );
		}
		return $rows;
	}

	/**
	 * A request header, sanitized and bounded.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private static function header( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a request header for a log annotation, not a state change.
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ), 0, 2000 );
	}
}
