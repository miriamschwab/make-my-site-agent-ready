<?php
/**
 * Reads the `Accept` header: does this request want a given type rather than an HTML page?
 *
 * One parser for every place the plugin answers an ordinary URL with something other than HTML —
 * content negotiation on a page, and the Markdown and JSON bodies of an agent-recoverable 404. Until
 * 1.46.1 there were two copies, in MMSAR_Server and MMSAR_Not_Found, and changing the rule meant
 * remembering both.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Accept-header preference test.
 */
class MMSAR_Accept {

	/**
	 * The spellings each type travels under. Clients are inconsistent, and a request that says
	 * `application/problem+json` has unambiguously asked for a JSON error.
	 */
	const ALIASES = array(
		'text/markdown'    => array( 'text/markdown', 'text/x-markdown' ),
		'application/json' => array( 'application/json', 'application/problem+json', 'text/json' ),
	);

	/**
	 * Whether an `Accept` header asks for `$wanted` rather than HTML.
	 *
	 * **What keeps a browser safe is that the type must be named.** A browser's `Accept` leads with
	 * `text/html` and covers everything else with a wildcard; it never names `text/markdown` or
	 * `application/json`, and a wildcard counts towards HTML but never towards the wanted type. So no
	 * browser can match, whichever way a tie is broken.
	 *
	 * **The tie is a separate question, and for Markdown it goes to Markdown (1.46.1).**
	 * `text/markdown, text/html` plus a full-weight wildcard is a client that went out of its way to
	 * name Markdown — a thing no browser does — and weighted it equally with HTML. Before 1.46.1 that
	 * tie went to HTML, and on a host whose edge converts HTML to Markdown the client then received
	 * the edge's conversion, nav chrome and all, instead of the plugin's clean one. See the decisions
	 * log, "A tie between Markdown and HTML goes to Markdown".
	 *
	 * JSON keeps the strict rule for now. axios's default Accept names JSON and ties it with a
	 * full-weight wildcard, which is how a lot of ordinary script traffic reads, and changing what
	 * those callers get on a 404 is its own decision, not a side effect of this one.
	 *
	 * Public and pure — the header is a parameter, not read from `$_SERVER` — so it can be asserted
	 * directly with real clients' headers.
	 *
	 * @param string $header   Raw `Accept` header value.
	 * @param string $wanted   Media type to test for; see ALIASES for the spellings it covers.
	 * @param bool   $tie_wins Whether an equal weight goes to `$wanted` instead of to HTML.
	 * @return bool True when `$wanted` is named, with a positive weight, and wins against HTML.
	 */
	public static function prefers( $header, $wanted, $tie_wins = false ) {
		$header = (string) $header;
		if ( '' === $header ) {
			return false;
		}

		$names    = isset( self::ALIASES[ $wanted ] ) ? self::ALIASES[ $wanted ] : array( $wanted );
		$wanted_q = -1.0;
		$html_q   = -1.0;

		foreach ( explode( ',', $header ) as $part ) {
			$bits = explode( ';', $part );
			$type = strtolower( trim( array_shift( $bits ) ) );
			if ( '' === $type ) {
				continue;
			}

			$q = 1.0;
			foreach ( $bits as $param ) {
				$param = strtolower( trim( $param ) );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
				}
			}

			if ( in_array( $type, $names, true ) ) {
				$wanted_q = max( $wanted_q, $q );
			} elseif ( 'text/html' === $type || 'text/*' === $type || '*/*' === $type ) {
				$html_q = max( $html_q, $q );
			}
		}

		if ( $wanted_q <= 0 ) {
			return false;
		}
		return $tie_wins ? $wanted_q >= $html_q : $wanted_q > $html_q;
	}

	/**
	 * Whether an `Accept` header asks for Markdown rather than HTML — the rule every Markdown path
	 * shares, so a page and its 404 can never disagree about what the same client wanted.
	 *
	 * @param string $header Raw `Accept` header value.
	 * @return bool
	 */
	public static function prefers_markdown( $header ) {
		return self::prefers( $header, 'text/markdown', true );
	}

	/**
	 * The current request's `Accept` header.
	 *
	 * @return string
	 */
	public static function request_header() {
		return isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
	}
}
