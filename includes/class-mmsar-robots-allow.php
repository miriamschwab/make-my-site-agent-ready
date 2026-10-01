<?php
/**
 * Keeps robots.txt from contradicting the endpoints this site advertises.
 *
 * The plugin publishes registered endpoints in three documents an agent reads before acting —
 * /.well-known/api-catalog, /llms.txt and the Agent Skills index. Those endpoints usually live
 * under /wp-json/, and a broad `Disallow: /wp-json/` is a rule several SEO plugins add by default
 * (Yoast's "deny_wp_json_crawling" writes exactly that). The site then tells an agent to call a URL
 * it has also told it to stay away from, and a well-behaved agent obeys the robots rule.
 *
 * The fix is an `Allow:` line for the individual advertised path, in the same group as the rule
 * that blocks it. Compliant parsers apply the longest matching rule (RFC 9309 §2.2.2), so the one
 * endpoint stays reachable while the rest of the REST API stays disallowed.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Allow rules for advertised endpoints that the finished robots.txt would otherwise block.
 */
class MMSAR_Robots_Allow {

	/**
	 * Adds an Allow line for every advertised endpoint the assembled robots.txt blocks.
	 *
	 * Deliberately works on the finished document rather than only on the rules this plugin writes.
	 * The plugin writes no Disallow of its own, so a check limited to its own output would never
	 * find anything to override and the contradiction would survive untouched — the rules that
	 * block these endpoints in practice come from an SEO plugin, from WordPress core, or from the
	 * owner's own extra directives. Whichever wrote them, the site is advertising an endpoint it
	 * also forbids, and that is the thing worth fixing.
	 *
	 * @param string $output    The robots.txt content assembled so far.
	 * @param mixed  $is_public Whether the site is set to be indexed (blog_public).
	 * @return string The robots.txt content, with Allow lines added where they were missing.
	 */
	public static function filter( $output, $is_public = null ) {
		if ( ! is_string( $output ) || '' === trim( $output ) ) {
			return $output;
		}

		// Same judgement mmsar_robots_txt() makes: on a site set to discourage search engines the
		// owner has asked crawlers to stay away, and quietly carving an exception out of that is not
		// this plugin's call to make. The filter passes the value, but the option is read directly
		// when it does not (the admin preview and any caller applying the filter with one argument).
		if ( null === $is_public ) {
			$is_public = get_option( 'blog_public' );
		}
		if ( ! $is_public ) {
			return $output;
		}

		$paths = self::endpoint_paths();
		if ( empty( $paths ) ) {
			return $output;
		}

		// Split on "\n" only, so a document written with CRLF keeps its "\r" at the end of each
		// element and survives the round trip unchanged.
		$lines      = explode( "\n", $output );
		$insertions = array();

		$declined = function_exists( 'mmsar_declines_training' ) && mmsar_declines_training()
			? array_map( 'strtolower', mmsar_training_crawler_tokens() )
			: array();

		foreach ( self::parse_groups( $lines ) as $group ) {
			// A group made only of training crawlers the owner declined is a refusal, not an
			// accident of an SEO plugin's defaults: carving endpoints out of it would reopen part of
			// the site to exactly the crawlers the owner asked to stay out.
			if ( $declined && $group['agents'] && ! array_diff( $group['agents'], $declined ) ) {
				continue;
			}
			foreach ( $paths as $path ) {
				$index = self::blocking_rule_index( $group['rules'], $path );
				if ( null === $index ) {
					continue;
				}
				$insertions[ $index ][] = $path;
			}
		}

		if ( empty( $insertions ) ) {
			return $output;
		}

		// Insert from the bottom up so the line numbers collected above stay valid as the document
		// grows underneath them.
		krsort( $insertions, SORT_NUMERIC );
		foreach ( $insertions as $index => $group_paths ) {
			$eol = ( '' !== $lines[ $index ] && "\r" === substr( $lines[ $index ], -1 ) ) ? "\r" : '';
			$new = array();
			foreach ( $group_paths as $path ) {
				$new[] = 'Allow: ' . $path . $eol;
			}
			array_splice( $lines, $index, 0, $new );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The paths of the endpoints this site advertises, as robots.txt would match them.
	 *
	 * Read from the registry — the same list that feeds the api-catalog, llms.txt and the Agent
	 * Skills index, and the reason a settings-page endpoint and one registered in code are both
	 * covered here without either being named. Deriving the paths from anywhere else, or naming a
	 * known one, is what would let the documents and robots.txt drift apart again.
	 *
	 * @return string[] Root-relative paths, deduplicated, in registration order.
	 */
	private static function endpoint_paths() {
		if ( ! class_exists( 'MMSAR_Registry' ) ) {
			return array();
		}

		$home = wp_parse_url( home_url( '/' ) );
		$host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		if ( '' === $host ) {
			return array();
		}

		$paths = array();
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			$parts = wp_parse_url( $endpoint['href'] );

			// A robots.txt governs one host. An endpoint somewhere else — a booking API on a
			// third-party domain — is not ours to write rules about, and a path lifted out of its
			// URL would land on a path of this site that has nothing to do with it.
			if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== $host ) {
				continue;
			}

			$path = ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
			if ( '/' !== substr( $path, 0, 1 ) ) {
				continue;
			}

			// Rules are matched against the path *and* query string, so a REST route reached as
			// /?rest_route=... (what a site on plain permalinks serves) needs its query to override
			// the matching `Disallow: /?rest_route=`.
			if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
				$path .= '?' . $parts['query'];
			}

			// An Allow for the site root is not an endpoint-specific exception — it is broad enough
			// to outrank a site-wide Disallow and reopen everything. Skipped rather than published.
			if ( '/' === $path ) {
				continue;
			}

			// robots.txt is line-oriented and treats # as the start of a comment, so a value
			// containing either cannot be written as a directive that means what it says.
			if ( preg_match( '/[\s#]/', $path ) ) {
				continue;
			}

			if ( ! in_array( $path, $paths, true ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Splits the document into user-agent groups, keeping only the rules and where they sit.
	 *
	 * Consecutive User-agent lines share one group, and neither a blank line nor a non-rule field
	 * ends one — a group runs until the next User-agent line that follows a rule (RFC 9309 §2.2.1).
	 * Getting that wrong would put an Allow in the wrong group, where it means nothing.
	 *
	 * @param string[] $lines The document, split into lines.
	 * @return array[] Groups, each array( 'agents' => lowercase user-agent tokens, 'rules' => list of
	 *                 array( index, directive, value ) ).
	 */
	private static function parse_groups( $lines ) {
		$groups        = array();
		$current       = array();
		$agents        = array();
		$seen_rule     = false;
		$has_useragent = false;

		foreach ( $lines as $index => $line ) {
			// Everything from an unquoted # to the end of the line is a comment.
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$field = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( 'user-agent' === $field ) {
				// A User-agent line after a rule starts a new group; one directly after another
				// User-agent line joins the group being opened.
				if ( $seen_rule ) {
					if ( $has_useragent ) {
						$groups[] = array(
							'agents' => $agents,
							'rules'  => $current,
						);
					}
					$current   = array();
					$agents    = array();
					$seen_rule = false;
				}
				$has_useragent = true;
				$agents[]      = strtolower( $value );
				continue;
			}

			if ( 'allow' !== $field && 'disallow' !== $field ) {
				continue;
			}

			// Rules before any User-agent line belong to no group and are ignored, exactly as a
			// crawler would ignore them.
			if ( ! $has_useragent ) {
				continue;
			}

			$seen_rule = true;
			$current[] = array(
				'index'     => $index,
				'directive' => $field,
				'value'     => $value,
			);
		}

		if ( $has_useragent ) {
			$groups[] = array(
				'agents' => $agents,
				'rules'  => $current,
			);
		}

		return $groups;
	}

	/**
	 * Where an Allow line has to go for one path in one group, or null if it needs none.
	 *
	 * Precedence follows the same longest-match rule crawlers apply: the most specific rule wins,
	 * and an Allow at least as specific as the Disallow already covers the path. Only a path that
	 * is genuinely blocked here gets a line — a group that never disallows it is left untouched,
	 * and so is one that already allows it.
	 *
	 * @param array  $group Rules in the group, as returned by parse_groups().
	 * @param string $path  Root-relative endpoint path.
	 * @return int|null Line index of the first Disallow that blocks the path, or null.
	 */
	private static function blocking_rule_index( $group, $path ) {
		$disallow_len   = -1;
		$allow_len      = -1;
		$disallow_index = null;

		foreach ( $group as $rule ) {
			// `Disallow:` with an empty value means "nothing is disallowed" and matches no path.
			// An empty Allow says nothing either.
			if ( '' === $rule['value'] || ! self::path_matches( $rule['value'], $path ) ) {
				continue;
			}
			$length = strlen( $rule['value'] );
			if ( 'disallow' === $rule['directive'] ) {
				if ( $length > $disallow_len ) {
					$disallow_len = $length;
				}
				if ( null === $disallow_index ) {
					$disallow_index = $rule['index'];
				}
			} elseif ( $length > $allow_len ) {
				$allow_len = $length;
			}
		}

		if ( null === $disallow_index || $allow_len >= $disallow_len ) {
			return null;
		}

		return $disallow_index;
	}

	/**
	 * Whether a robots.txt rule value matches a path.
	 *
	 * Rules match on prefix, with `*` standing for any run of characters and a trailing `$`
	 * anchoring the end of the path. Matching is case-sensitive, as the format requires.
	 *
	 * @param string $pattern Rule value, e.g. '/wp-json/' or '/*.php$'.
	 * @param string $path    Root-relative path, with query string when the URL had one.
	 * @return bool Whether the rule applies to the path.
	 */
	private static function path_matches( $pattern, $path ) {
		$anchored = false;
		if ( '$' === substr( $pattern, -1 ) ) {
			$anchored = true;
			$pattern  = substr( $pattern, 0, -1 );
		}

		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $chunk ) {
			if ( $i > 0 ) {
				$regex .= '.*';
			}
			$regex .= preg_quote( $chunk, '#' );
		}

		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#', $path );
	}
}
