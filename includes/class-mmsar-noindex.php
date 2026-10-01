<?php
/**
 * Keeps posts an SEO plugin marks noindex out of the lists this plugin publishes.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Noindex means "don't list this", not "this is private".
 *
 * A site owner who marks a page noindex — a thank-you page, a thin archive, a landing page for one
 * campaign — has said they do not want it surfaced to people searching. The indexes this plugin
 * publishes (llms.txt and its scoped indexes, llms-full.txt, the OKF tree, MCP search and list, NLWeb)
 * are exactly that kind of surfacing, so a noindexed post is left out of them.
 *
 * The post's own `.md` address keeps working, and so do the other ways of reading one addressed
 * post (an OKF concept file, MCP `get_content`). The HTML page is public and a visitor who has its
 * URL can read it; the Markdown copy of it is no less public, and it already carries
 * `X-Robots-Tag: noindex`, so it never competes with anything in search. Password protection is
 * the tool for content that must not be read, and that is excluded everywhere (decisions-log
 * *Password-protected content: exclude at every surface*).
 *
 * The SEO plugin is read only while it is active. A site that has removed Yoast is no longer
 * noindexing anything, whatever post meta Yoast left behind.
 *
 * "Discourage search engines" (`blog_public` = 0) is deliberately not read: it is how staging sites
 * are run, and honouring it would empty every index on every staging copy.
 */
class MMSAR_Noindex {

	/**
	 * The `llmmd_settings` key holding the owner's choice.
	 */
	const SETTING = 'respect_noindex';

	/**
	 * Per-request cache of excluded ids, or null until first computed.
	 *
	 * @var int[]|null
	 */
	private static $excluded = null;

	/**
	 * Whether noindexed posts are left out of the published lists.
	 *
	 * Absent means on, for the same reason as the feature toggles: every install saved before this
	 * setting existed has no key for it.
	 *
	 * @param mixed $settings The `llmmd_settings` option.
	 * @return bool
	 */
	public static function enabled_in( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings[ self::SETTING ] ) ) {
			return true;
		}
		return '0' !== (string) $settings[ self::SETTING ];
	}

	/**
	 * Whether noindexed posts are left out, read from the stored setting.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return self::enabled_in( get_option( 'llmmd_settings', array() ) );
	}

	/**
	 * Yoast SEO's verdict for one post, from its stored values.
	 *
	 * Yoast stores the per-post choice in `_yoast_wpseo_meta-robots-noindex`: `1` is noindex, `2` is
	 * index, anything else (`0`, empty, absent) defers to the post type's default, which lives in the
	 * `wpseo_titles` option as `noindex-{post_type}`. This is the same resolution Yoast's indexable
	 * builder makes.
	 *
	 * @param mixed  $meta      The post's `_yoast_wpseo_meta-robots-noindex` value.
	 * @param mixed  $titles    The `wpseo_titles` option.
	 * @param string $post_type The post's type.
	 * @return bool
	 */
	public static function yoast_noindex( $meta, $titles, $post_type ) {
		$meta = (string) $meta;
		if ( '1' === $meta ) {
			return true;
		}
		if ( '2' === $meta ) {
			return false;
		}
		return is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post_type ] );
	}

	/**
	 * Rank Math's verdict for one post, from its stored values.
	 *
	 * Rank Math stores the per-post robots as an array in `rank_math_robots`. When that is empty, a
	 * post type with custom robots switched on (`pt_{type}_custom_robots` = `on` in the
	 * `rank-math-options-titles` option) uses `pt_{type}_robots`; otherwise the site-wide
	 * `robots_global` applies.
	 *
	 * Not verified against a running Rank Math install: none is available on the test clone. The
	 * storage keys come from Rank Math's settings and are covered by tests on their shape only.
	 *
	 * @param mixed  $post_robots The post's `rank_math_robots` value.
	 * @param mixed  $titles      The `rank-math-options-titles` option.
	 * @param string $post_type   The post's type.
	 * @return bool
	 */
	public static function rank_math_noindex( $post_robots, $titles, $post_type ) {
		$robots = is_array( $post_robots ) ? array_filter( $post_robots ) : array();
		if ( empty( $robots ) && is_array( $titles ) ) {
			$custom = isset( $titles[ 'pt_' . $post_type . '_custom_robots' ] ) && 'on' === $titles[ 'pt_' . $post_type . '_custom_robots' ];
			if ( $custom && isset( $titles[ 'pt_' . $post_type . '_robots' ] ) && is_array( $titles[ 'pt_' . $post_type . '_robots' ] ) ) {
				$robots = $titles[ 'pt_' . $post_type . '_robots' ];
			} elseif ( isset( $titles['robots_global'] ) && is_array( $titles['robots_global'] ) ) {
				$robots = $titles['robots_global'];
			}
		}
		return in_array( 'noindex', $robots, true );
	}

	/**
	 * Whether Yoast SEO is running.
	 *
	 * @return bool
	 */
	private static function yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Whether Rank Math is running.
	 *
	 * @return bool
	 */
	private static function rank_math_active() {
		return defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * What the active SEO plugins say about one post, before the filter.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function seo_plugins_say( $post ) {
		if ( self::yoast_active() && self::yoast_noindex( get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ), get_option( 'wpseo_titles', array() ), $post->post_type ) ) {
			return true;
		}
		if ( self::rank_math_active() && self::rank_math_noindex( get_post_meta( $post->ID, 'rank_math_robots', true ), get_option( 'rank-math-options-titles', array() ), $post->post_type ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether one post is left out of the published lists.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_excluded( $post ) {
		if ( ! $post instanceof WP_Post || ! self::enabled() ) {
			return false;
		}
		/**
		 * Filters whether a post counts as noindex for this plugin's published lists.
		 *
		 * For an SEO plugin other than Yoast SEO or Rank Math, or for a rule of the site's own. A
		 * post this returns true for is left out of llms.txt, llms-full.txt, the OKF indexes, MCP
		 * search and list, and NLWeb; its `.md` address keeps working.
		 *
		 * @param bool    $noindex What Yoast SEO or Rank Math says, false when neither is active.
		 * @param WP_Post $post    The post.
		 */
		return (bool) apply_filters( 'mmsar_post_is_noindex', self::seo_plugins_say( $post ), $post );
	}

	/**
	 * Ids of every published post, in an enabled type, that is left out of the lists.
	 *
	 * Computed once per request. Without a filter only the posts an SEO plugin could be noindexing
	 * are examined — those carrying a per-post value, and every post of a type whose default is
	 * noindex — so a large site does not load every post to find none. With a filter registered,
	 * any post could be the one it excludes, so every published post is examined.
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		if ( null !== self::$excluded ) {
			return self::$excluded;
		}
		self::$excluded = array();
		if ( ! self::enabled() ) {
			return self::$excluded;
		}

		$types   = mmsar_get_enabled_post_types();
		$filter  = has_filter( 'mmsar_post_is_noindex' );
		$yoast   = self::yoast_active();
		$rank    = self::rank_math_active();
		$base    = array(
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
		$checked = array();

		if ( $filter ) {
			$checked = get_posts( array_merge( $base, array( 'post_type' => $types ) ) );
		} else {
			$meta = array();
			if ( $yoast ) {
				$meta[] = array(
					'key'     => '_yoast_wpseo_meta-robots-noindex',
					'value'   => '1',
					'compare' => '=',
				);
			}
			if ( $rank ) {
				$meta[] = array(
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'LIKE',
				);
			}
			if ( $meta ) {
				$checked = get_posts(
					array_merge(
						$base,
						array(
							'post_type'  => $types,
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only when generating a cached document or answering a rate-limited query, and narrows the candidates so every post is not loaded.
							'meta_query' => array_merge( array( 'relation' => 'OR' ), $meta ),
						)
					)
				);
			}
			// A type whose default is noindex: every post of it is a candidate, and the per-post
			// check below lets an explicit "index" through.
			$default_types = array();
			$yoast_titles  = $yoast ? get_option( 'wpseo_titles', array() ) : array();
			$rank_titles   = $rank ? get_option( 'rank-math-options-titles', array() ) : array();
			foreach ( $types as $type ) {
				if ( ( $yoast && self::yoast_noindex( '', $yoast_titles, $type ) ) || ( $rank && self::rank_math_noindex( array(), $rank_titles, $type ) ) ) {
					$default_types[] = $type;
				}
			}
			if ( $default_types ) {
				$checked = array_merge( $checked, get_posts( array_merge( $base, array( 'post_type' => $default_types ) ) ) );
			}
		}

		foreach ( array_unique( array_map( 'intval', $checked ) ) as $id ) {
			if ( self::is_excluded( get_post( $id ) ) ) {
				self::$excluded[] = $id;
			}
		}
		return self::$excluded;
	}

	/**
	 * Adds the excluded ids to a post query's arguments.
	 *
	 * @param array $args `get_posts()` / `WP_Query` arguments.
	 * @return array
	 */
	public static function exclude( array $args ) {
		$ids = self::excluded_ids();
		if ( $ids ) {
			$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
		}
		return $args;
	}

	/**
	 * How many of one type's published posts are left out.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	public static function count_for_type( $post_type ) {
		$count = 0;
		foreach ( self::excluded_ids() as $id ) {
			if ( get_post_type( $id ) === $post_type ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Forgets the per-request cache, after a save that may have changed a post's robots setting.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$excluded = null;
	}
}
