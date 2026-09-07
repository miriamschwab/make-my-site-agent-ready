<?php
/**
 * `Deprecation` and `Sunset` headers for retiring surfaces.
 *
 * The OpenAPI document has always told agents that "retiring routes will carry `Deprecation` and
 * `Sunset` headers before they stop working". Until this class existed, nothing in the plugin could
 * emit either one — the promise was real, the mechanism was not. That is the same defect as a spec
 * describing a response the code never returns, one level up: an agent that trusted the statement
 * and watched for the headers would have been told nothing right up until the URL stopped working.
 *
 * Two headers, two different wire formats, and getting them the wrong way round is the easy mistake:
 *
 * - `Deprecation` is an RFC 9745 Item Structured Header whose value is a **Date** — an `@` followed
 *   by a Unix timestamp. `Deprecation: @1688169599`.
 * - `Sunset` is an RFC 8594 **HTTP-date** in IMF-fixdate form. `Sunset: Sat, 31 Dec 2018 23:59:59 GMT`.
 *
 * Both relations are registered with IANA (`deprecation`, `sunset`), so where a policy URL is given
 * it goes out as a `Link` rather than as an invented relation nobody else reads.
 *
 * **The schedule is empty by default and emits nothing.** This adds no header to any response until
 * a site actually retires something, which is why it needs no feature toggle: an inert default
 * cannot change a response, and a toggle would only be one more thing to explain.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR deprecation headers.
 */
class MMSAR_Deprecation {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 0: before any handler that might `exit` on its own. A surface being retired is
		// very often one served by a handler that ends the request itself, and a header added after
		// that never goes out.
		add_action( 'template_redirect', array( __CLASS__, 'send_front_end_headers' ), 0 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_rest_headers' ), 10, 3 );
	}

	/**
	 * The retirement schedule.
	 *
	 * Keys identify a surface. Two spellings are recognised, because the two halves of this plugin's
	 * output are addressed differently:
	 *
	 * - a site-relative path, for anything served off a rewrite rule — `/llms.txt`, `/auth.md`
	 * - a REST route, for anything under `/wp-json/` — `/mmsar/v1/mcp`, `/wp/v2/posts`
	 *
	 * Each value may carry:
	 *
	 * - `deprecation` — when the surface became (or becomes) deprecated. A Unix timestamp, or any
	 *   string `strtotime()` understands. A future value is legitimate: RFC 9745 allows announcing
	 *   a deprecation that has not taken effect yet.
	 * - `sunset` — when it stops working. Same accepted formats.
	 * - `link` — a URL explaining the retirement, or naming the successor.
	 *
	 * Every field is optional, but an entry with none of them emits nothing at all.
	 *
	 * @return array<string, array> Surface identifier => descriptor.
	 */
	public static function schedule() {
		/**
		 * Filters the set of surfaces being retired.
		 *
		 * Empty by default: this plugin retires nothing on its own timetable, and a site that has
		 * not scheduled a retirement should not be advertising one. Add an entry when you take a
		 * URL out of service, and agents get told in the response itself rather than by a 404 on
		 * the day it disappears.
		 *
		 *     add_filter( 'mmsar_deprecated_surfaces', function ( $surfaces ) {
		 *         $surfaces['/mmsar/v1/mcp'] = array(
		 *             'deprecation' => '2026-10-01',
		 *             'sunset'      => '2027-01-01',
		 *             'link'        => home_url( '/api/' ),
		 *         );
		 *         return $surfaces;
		 *     } );
		 *
		 * @param array<string, array> $surfaces Surface identifier => descriptor.
		 */
		$surfaces = apply_filters( 'mmsar_deprecated_surfaces', array() );

		return is_array( $surfaces ) ? $surfaces : array();
	}

	/**
	 * Whether anything at all is scheduled for retirement.
	 *
	 * Lets callers — the OpenAPI document among them — state the true position rather than describe
	 * a policy in the abstract.
	 *
	 * @return bool
	 */
	public static function has_schedule() {
		return array() !== self::schedule();
	}

	/**
	 * Turn one descriptor into header name => value pairs.
	 *
	 * @param array $entry Descriptor from the schedule.
	 * @return array<string, string> Header name => value. Empty when the descriptor says nothing.
	 */
	public static function headers_for( $entry ) {
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$headers = array();

		$deprecation = self::to_timestamp( isset( $entry['deprecation'] ) ? $entry['deprecation'] : null );
		if ( $deprecation ) {
			// RFC 9745: an Item Structured Header whose value is a Date — "@" then a Unix timestamp.
			$headers['Deprecation'] = '@' . $deprecation;
		}

		$sunset = self::to_timestamp( isset( $entry['sunset'] ) ? $entry['sunset'] : null );
		if ( $sunset ) {
			// RFC 8594: an HTTP-date, which means IMF-fixdate and GMT — not the timestamp form the
			// Deprecation header takes. gmdate() with an explicit "GMT" is the whole of it.
			$headers['Sunset'] = gmdate( 'D, d M Y H:i:s', $sunset ) . ' GMT';
		}

		$link = isset( $entry['link'] ) ? esc_url_raw( (string) $entry['link'] ) : '';
		if ( '' !== $link && ( $deprecation || $sunset ) ) {
			// Both relations are IANA-registered. Which one applies depends on what the document at
			// the other end is about, so the header names whichever dates were actually given.
			$rels = array();
			if ( $deprecation ) {
				$rels[] = 'deprecation';
			}
			if ( $sunset ) {
				$rels[] = 'sunset';
			}
			$headers['Link'] = sprintf( '<%s>; rel="%s"', $link, implode( ' ', $rels ) );
		}

		return $headers;
	}

	/**
	 * Accepts a timestamp or a date string; returns a Unix timestamp, or 0.
	 *
	 * @param mixed $value Timestamp or date string.
	 * @return int Unix timestamp, or 0 when unusable.
	 */
	private static function to_timestamp( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value > 0 ? (int) $value : 0;
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			// Parsed as UTC rather than site time. These headers are wire timestamps read by clients
			// in other timezones; resolving a bare "2027-01-01" against the site's offset would put
			// the sunset hours away from where it was written.
			$parsed = strtotime( trim( $value ) . ' UTC' );
			if ( false === $parsed ) {
				$parsed = strtotime( trim( $value ) );
			}
			return false === $parsed ? 0 : (int) $parsed;
		}

		return 0;
	}

	/**
	 * The descriptor matching a surface identifier, or an empty array.
	 *
	 * @param string $identifier Path or REST route.
	 * @return array Descriptor.
	 */
	private static function lookup( $identifier ) {
		$schedule = self::schedule();
		if ( array() === $schedule ) {
			return array();
		}

		// Compared without a trailing slash so `/llms.txt` and `/writing/` behave the same way, and
		// so an entry written either way still matches.
		$identifier = '/' . trim( (string) $identifier, '/' );

		foreach ( $schedule as $key => $entry ) {
			if ( '/' . trim( (string) $key, '/' ) === $identifier ) {
				return is_array( $entry ) ? $entry : array();
			}
		}

		return array();
	}

	/**
	 * Emit the headers on a front-end request for a retired path.
	 *
	 * @return void
	 */
	public static function send_front_end_headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		$headers = self::headers_for( self::lookup( $path ) );
		foreach ( $headers as $name => $value ) {
			// Link is appended rather than replaced: this plugin and the theme both publish Link
			// headers, and overwriting the set would drop somebody else's relation.
			header( $name . ': ' . $value, 'Link' !== $name );
		}
	}

	/**
	 * Add the headers to a REST response for a retired route.
	 *
	 * @param mixed           $result  Response, normally WP_REST_Response.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request.
	 * @return mixed Response.
	 */
	public static function add_rest_headers( $result, $server, $request ) {
		if ( ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$headers = self::headers_for( self::lookup( $request->get_route() ) );
		foreach ( $headers as $name => $value ) {
			$result->header( $name, $value, 'Link' !== $name );
		}

		return $result;
	}
}
