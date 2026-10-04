<?php
/**
 * What an MCP tool call asked for, reduced to something safe to store.
 *
 * The agent log has recorded which tool was called since 1.23.0. What the caller asked that tool
 * for is the more useful half: "list_content" says an agent looked around, "list_content
 * topic=ai" says what it was looking for. Every value here comes from the request body, and
 * it ends up on an admin screen and in a CSV export, so it is held to the rule the log has applied
 * since 1.23.0: a value is stored only once it has been checked against something the site already
 * knows, and a value that fails the check is dropped rather than cleaned up.
 *
 * | Argument  | Stored when                                              | Stored as                 |
 * |-----------|----------------------------------------------------------|---------------------------|
 * | post_type | it is one of the enabled post types                      | the slug                  |
 * | topic     | it resolves to a term in a topic taxonomy                | the slug                  |
 * | url       | it resolves to a published post this server would serve  | that post's path          |
 * | sections  | each value is a known overview section                   | the known ones, joined    |
 * | query     | the owner has switched query logging on                  | redacted and capped text  |
 *
 * `limit` and `offset` are never stored: they say how much was read, not what was wanted.
 *
 * The checks are injected so the rules can be tested without WordPress. In production they are the
 * same lookups the tools themselves run, which keeps "stored" and "served" in agreement: a url is
 * logged as the path of the post the tool returned, never as what the caller typed.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP tool-call arguments for the agent log.
 */
class MMSAR_MCP_Arguments {

	/**
	 * Option: store search queries (off by default). '1' or ''.
	 */
	const QUERY_OPTION = 'mmsar_agent_log_queries';

	/**
	 * Longest stored query, in characters. Keyword searches are a few words; the cap exists so a
	 * public endpoint cannot be used to write arbitrarily large rows, not to trim real queries.
	 */
	const MAX_QUERY = 300;

	/**
	 * Longest stored argument line. Matches the column.
	 */
	const MAX_LENGTH = 500;

	/**
	 * Overview sections, as get_site_overview accepts them.
	 */
	const SECTIONS = array( 'about', 'content', 'topics', 'endpoints', 'actions' );

	/**
	 * Whether the site owner has chosen to store search queries.
	 *
	 * @return bool
	 */
	public static function queries_enabled() {
		return '1' === (string) get_option( self::QUERY_OPTION, '' );
	}

	/**
	 * The stored form of one tool call's arguments.
	 *
	 * @param string     $tool      Tool name. Only this server's own tools are summarised.
	 * @param mixed      $arguments The call's `arguments` object.
	 * @param array|null $checks    Lookups: 'post_type' (callable string→bool), 'topic' (callable
	 *                              string→string|null, the canonical slug), 'url' (callable
	 *                              string→string|null, the served post's path) and 'queries'
	 *                              (bool). Null uses the live site.
	 * @return string Space-separated `key=value` pairs, or '' when nothing passed its check.
	 */
	public static function summarize( $tool, $arguments, $checks = null ) {
		if ( ! is_array( $arguments ) || empty( $arguments ) ) {
			return '';
		}
		$checks = is_array( $checks ) ? $checks : self::live_checks();
		$parts  = array();

		if ( in_array( $tool, array( 'search_content', 'list_content' ), true ) ) {
			if ( isset( $arguments['post_type'] ) && is_string( $arguments['post_type'] ) && '' !== $arguments['post_type']
				&& call_user_func( $checks['post_type'], $arguments['post_type'] ) ) {
				$parts[] = 'post_type=' . $arguments['post_type'];
			}
			if ( isset( $arguments['topic'] ) && is_string( $arguments['topic'] ) && '' !== $arguments['topic'] ) {
				$slug = call_user_func( $checks['topic'], $arguments['topic'] );
				if ( is_string( $slug ) && '' !== $slug ) {
					$parts[] = 'topic=' . $slug;
				}
			}
		}

		if ( 'search_content' === $tool && ! empty( $checks['queries'] )
			&& isset( $arguments['query'] ) && is_string( $arguments['query'] ) ) {
			$query = self::redact_query( $arguments['query'] );
			if ( '' !== $query ) {
				// JSON-quoted, so a query containing spaces, `=` or quotes stays one unambiguous value.
				$parts[] = 'query=' . wp_json_encode( $query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}

		if ( 'get_content' === $tool && isset( $arguments['url'] ) && is_string( $arguments['url'] ) && '' !== $arguments['url'] ) {
			$path = call_user_func( $checks['url'], $arguments['url'] );
			if ( is_string( $path ) && '' !== $path ) {
				$parts[] = 'url=' . $path;
			}
		}

		if ( 'get_site_overview' === $tool && isset( $arguments['sections'] ) && is_array( $arguments['sections'] ) ) {
			$known = array_values( array_intersect( self::SECTIONS, array_filter( $arguments['sections'], 'is_string' ) ) );
			if ( ! empty( $known ) ) {
				$parts[] = 'sections=' . implode( ',', $known );
			}
		}

		$line = implode( ' ', $parts );
		return mb_strlen( $line ) > self::MAX_LENGTH ? mb_substr( $line, 0, self::MAX_LENGTH ) : $line;
	}

	/**
	 * A search query as it may be stored.
	 *
	 * An agent searching on someone's behalf may put that person's details into the query, so
	 * anything shaped like an email address or a phone number is replaced before storage, and what
	 * remains is reduced to one line of printable text. Letters in any script are kept: a Hebrew or
	 * Japanese query is as meaningful as an English one.
	 *
	 * @param string $query Raw query.
	 * @return string Redacted query, possibly empty.
	 */
	public static function redact_query( $query ) {
		$query = (string) $query;
		if ( ! preg_match( '//u', $query ) ) {
			return ''; // Not valid UTF-8: nothing worth keeping, and nothing safe to keep.
		}

		$query = wp_strip_all_tags( $query );
		// Control characters (C0, DEL, C1) and the Unicode line and paragraph separators all become
		// spaces, so the stored value is one line whatever the caller sent.
		$query = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]/u', ' ', $query );

		// Emails first, so their digits are not then read as a phone number.
		$query = (string) preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $query );
		// Nine or more digits, with up to two spaces, dots, dashes or brackets between any two of
		// them (so "(415) 555-0132" is one number) and an optional leading plus or bracket: phone
		// numbers with an area code, and card and ID numbers. Nine rather than seven so a pair of
		// years ("2025-2026", eight digits) is left alone; a version number is too short to reach it.
		$query = (string) preg_replace( '/\+?\(?\d(?:[\s().-]{0,2}\d){8,}/u', '[number]', $query );

		$query = trim( (string) preg_replace( '/\s+/u', ' ', $query ) );

		if ( mb_strlen( $query ) > self::MAX_QUERY ) {
			$query = rtrim( mb_substr( $query, 0, self::MAX_QUERY - 1 ) ) . '…';
		}
		return $query;
	}

	/**
	 * The production lookups.
	 *
	 * @return array
	 */
	private static function live_checks() {
		return array(
			'post_type' => static function ( $type ) {
				return in_array( $type, mmsar_get_enabled_post_types(), true );
			},
			'topic'     => array( 'MMSAR_MCP', 'topic_slug' ),
			'url'       => array( 'MMSAR_MCP', 'served_path' ),
			'queries'   => self::queries_enabled(),
		);
	}
}
