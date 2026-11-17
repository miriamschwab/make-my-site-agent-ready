<?php
/**
 * Make My Site Agent-Ready — refreshes a post's markdown when its metadata changes.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds a post's stored markdown when one of its custom fields changes without a post save.
 *
 * Until 1.74.0 markdown was rebuilt only on `save_post`. A field written on its own (an
 * agent ability updating only meta, `update_post_meta()` from code, the REST API, which writes meta
 * after `save_post` has run) left the stored copy stale until Regenerate All. Found on
 * miriamschwab.me on 2026-11-17: three media items kept an old `source_url` for two weeks.
 *
 * Four safeguards keep it from costing more than an ordinary post save:
 * 1. Changes are queued and each post is checked once, at the end of the request.
 * 2. Housekeeping fields (edit locks, old slugs, this plugin's own markdown) are ignored. Sites can
 *    add their own with the `mmsar_meta_watch_ignored_keys` filter.
 * 3. A post's markdown is stored, and the cached documents cleared, only when the markdown changed.
 * 4. Only wp-admin, REST, WP-CLI, cron and XML-RPC requests count. Writes during an ordinary page
 *    view (view counters and the like) are not watched at all.
 *
 * Every check is counted per day and per field (`mmsar_meta_watch_stats`), so a field that keeps
 * triggering checks that change nothing can be found and added to the ignore list. Site Health
 * reports it above a threshold, and the `get-markdown-refresh-log` ability returns the counts.
 */
class MMSAR_Meta_Watch {

	/**
	 * Option holding the counts. Not autoloaded.
	 */
	const STATS_OPTION = 'mmsar_meta_watch_stats';

	/**
	 * Days of counts kept.
	 */
	const KEEP_DAYS = 14;

	/**
	 * Most distinct fields tracked; later ones are counted together under OTHER_KEY.
	 */
	const MAX_KEYS = 100;

	/**
	 * Bucket for fields past MAX_KEYS.
	 */
	const OTHER_KEY = '(other)';

	/**
	 * Checks of a single field in a single day, changing nothing, before Site Health speaks up.
	 */
	const NOISE_THRESHOLD = 50;

	/**
	 * Markdown rebuilds caused by a single field in a single day before Site Health speaks up.
	 */
	const CHURN_THRESHOLD = 50;

	/**
	 * Fields that never change a post's markdown and are written for housekeeping.
	 */
	const IGNORED_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_encloseme',
		'_pingme',
	);

	/**
	 * Prefixes of fields that are ignored: this plugin's own stored markdown and state.
	 */
	const IGNORED_PREFIXES = array( '_llmmd_', '_mmsar_' );

	/**
	 * Queued posts: post ID => array of field names that changed.
	 *
	 * @var array<int, array<string, true>>
	 */
	private static $queue = array();

	/**
	 * Posts whose markdown `save_post` already rebuilt in this request.
	 *
	 * @var array<int, true>
	 */
	private static $saved = array();

	/**
	 * Request type of the last watched write, for the counts.
	 *
	 * @var string
	 */
	private static $context = '';

	/**
	 * Set while the queue is being processed, so writes made then are not queued again.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'noted' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'noted' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'noted' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'process' ), 5 );
		add_filter( 'site_status_tests', array( __CLASS__, 'add_site_health_test' ) );
	}

	/**
	 * Records that `save_post` rebuilt a post's markdown in this request.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function saved( $post_id ) {
		self::$saved[ (int) $post_id ] = true;
	}

	/**
	 * Queues a post whose field changed.
	 *
	 * @param int|int[] $meta_id  Meta ID(s). Unused.
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Field name.
	 * @return void
	 */
	public static function noted( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );
		if ( self::$running || ! is_string( $meta_key ) ) {
			return;
		}
		/**
		 * Filters whether field changes refresh stored markdown at all. Return false to switch the
		 * watcher off; markdown is then rebuilt on post save and Regenerate All only, as before 1.74.0.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'mmsar_meta_watch_enabled', true ) ) {
			return;
		}
		// Worked out on each write rather than once: a REST request is only recognisable once
		// WordPress has parsed it, so a write earlier in the request would otherwise fix it as a
		// page view. The flags are constants and cheap to read.
		$context = self::context_from( self::request_flags() );
		if ( '' === $context || self::is_ignored( $meta_key, self::ignored_keys() ) ) {
			return;
		}
		self::$context = $context;
		$post_id       = (int) $post_id;
		if ( $post_id <= 0 || ! in_array( get_post_type( $post_id ), mmsar_get_enabled_post_types(), true ) ) {
			return;
		}
		self::$queue[ $post_id ][ $meta_key ] = true;
	}

	/**
	 * The ignore list, after the filter.
	 *
	 * @return string[]
	 */
	private static function ignored_keys() {
		/**
		 * Filters the custom fields whose changes never refresh a post's markdown.
		 *
		 * Add a field here when it is written often and never changes what the markdown says; the
		 * Site Health check and the get-markdown-refresh-log ability name the candidates.
		 *
		 * @param string[] $keys Field names.
		 */
		$keys = apply_filters( 'mmsar_meta_watch_ignored_keys', self::IGNORED_KEYS );
		return is_array( $keys ) ? array_map( 'strval', $keys ) : self::IGNORED_KEYS;
	}

	/**
	 * Whether a field is ignored.
	 *
	 * @param string   $meta_key Field name.
	 * @param string[] $ignored  Ignored names.
	 * @return bool
	 */
	public static function is_ignored( $meta_key, array $ignored ) {
		if ( in_array( $meta_key, $ignored, true ) ) {
			return true;
		}
		foreach ( self::IGNORED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The flags describing this request.
	 *
	 * @return array<string, bool>
	 */
	private static function request_flags() {
		return array(
			'cli'    => defined( 'WP_CLI' ) && WP_CLI,
			'cron'   => wp_doing_cron(),
			'rest'   => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'xmlrpc' => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'ajax'   => wp_doing_ajax(),
			'admin'  => is_admin(),
		);
	}

	/**
	 * The request type the watcher reports, or '' for a request it ignores (an ordinary page view).
	 *
	 * @param array<string, bool> $flags Request flags.
	 * @return string
	 */
	public static function context_from( array $flags ) {
		foreach ( array( 'cli', 'cron', 'rest', 'xmlrpc', 'ajax', 'admin' ) as $context ) {
			if ( ! empty( $flags[ $context ] ) ) {
				return $context;
			}
		}
		return '';
	}

	/**
	 * Checks every queued post once, at the end of the request.
	 *
	 * @return void
	 */
	public static function process() {
		if ( empty( self::$queue ) || self::$running ) {
			return;
		}
		self::$running = true;
		$queue         = self::$queue;
		self::$queue   = array();
		$results       = array();
		$changed       = false;

		try {
			foreach ( $queue as $post_id => $keys ) {
				$post = get_post( $post_id );
				if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
					continue;
				}
				$markdown = MMSAR_Converter::convert_post( $post_id );
				$stored   = (string) get_post_meta( $post_id, '_llmmd_content', true );
				if ( $markdown === $stored ) {
					$outcome = isset( self::$saved[ $post_id ] ) ? 'after_save' : 'unchanged';
				} else {
					mmsar_store_markdown( $post_id, $markdown );
					$outcome = 'rebuilt';
					$changed = true;
				}
				$results[] = array(
					'post'    => (int) $post_id,
					'keys'    => array_keys( $keys ),
					'outcome' => $outcome,
				);
			}
			if ( $changed ) {
				mmsar_flush_generated_documents();
			}
			if ( $results ) {
				$stats = get_option( self::STATS_OPTION, array() );
				$stats = self::record( is_array( $stats ) ? $stats : array(), gmdate( 'Y-m-d' ), (string) self::$context, $results, $changed, time() );
				update_option( self::STATS_OPTION, $stats, false );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		self::$running = false;
	}

	/**
	 * Adds one request's results to the counts. Pure.
	 *
	 * Shape: `days` maps a date to totals (`checked`, `rebuilt`, `unchanged`, `after_save`,
	 * `flushes`); `keys` maps a field to `days` (date => the same outcome counts), `contexts`
	 * (request type => checks), `last_seen` and `last_post`. A check whose post was queued by
	 * several fields is counted against each of them.
	 *
	 * @param array  $stats   Existing counts.
	 * @param string $date    Today, Y-m-d (UTC).
	 * @param string $context Request type.
	 * @param array  $results Each { post, keys, outcome }.
	 * @param bool   $flushed Whether cached documents were cleared.
	 * @param int    $now     Unix time.
	 * @return array
	 */
	public static function record( array $stats, $date, $context, array $results, $flushed, $now ) {
		$blank = array(
			'checked'    => 0,
			'rebuilt'    => 0,
			'unchanged'  => 0,
			'after_save' => 0,
		);
		$days  = isset( $stats['days'] ) && is_array( $stats['days'] ) ? $stats['days'] : array();
		$keys  = isset( $stats['keys'] ) && is_array( $stats['keys'] ) ? $stats['keys'] : array();

		$day = isset( $days[ $date ] ) ? $days[ $date ] : $blank + array( 'flushes' => 0 );
		foreach ( $results as $result ) {
			++$day['checked'];
			++$day[ $result['outcome'] ];
			foreach ( $result['keys'] as $key ) {
				$key = self::clean_key( $key );
				if ( ! isset( $keys[ $key ] ) && count( $keys ) >= self::MAX_KEYS ) {
					$key = self::OTHER_KEY;
				}
				$entry = isset( $keys[ $key ] ) ? $keys[ $key ] : array(
					'days'     => array(),
					'contexts' => array(),
				);
				$kday  = isset( $entry['days'][ $date ] ) ? $entry['days'][ $date ] : $blank;
				++$kday['checked'];
				++$kday[ $result['outcome'] ];
				$entry['days'][ $date ]        = $kday;
				$entry['contexts'][ $context ] = ( isset( $entry['contexts'][ $context ] ) ? $entry['contexts'][ $context ] : 0 ) + 1;
				$entry['last_seen']            = (int) $now;
				$entry['last_post']            = (int) $result['post'];
				$keys[ $key ]                  = $entry;
			}
		}
		if ( $flushed ) {
			++$day['flushes'];
		}
		$days[ $date ] = $day;

		// Keep the newest KEEP_DAYS days, and drop fields with nothing left in them.
		$cutoff = gmdate( 'Y-m-d', strtotime( $date . ' -' . ( self::KEEP_DAYS - 1 ) . ' days' ) );
		foreach ( array_keys( $days ) as $d ) {
			if ( $d < $cutoff ) {
				unset( $days[ $d ] );
			}
		}
		foreach ( $keys as $key => $entry ) {
			foreach ( array_keys( $entry['days'] ) as $d ) {
				if ( $d < $cutoff ) {
					unset( $keys[ $key ]['days'][ $d ] );
				}
			}
			if ( empty( $keys[ $key ]['days'] ) ) {
				unset( $keys[ $key ] );
			}
		}
		ksort( $days );

		return array(
			'days' => $days,
			'keys' => $keys,
		);
	}

	/**
	 * A field name made safe to store and show: printable ASCII, 191 characters at most.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	public static function clean_key( $key ) {
		$key = substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', (string) $key ), 0, 191 );
		return '' === $key ? '(empty)' : $key;
	}

	/**
	 * Fields over a threshold on any day in the counts, worst first. Pure.
	 *
	 * `noise`: checks that changed nothing (the field doesn't affect the markdown, so it is a
	 * candidate for the ignore list). `churn`: rebuilds that did change it (the field really changes
	 * the markdown that often, and each one clears the cached documents).
	 *
	 * @param array $stats Counts from record().
	 * @return array[] Each { key, kind, day, count, contexts }.
	 */
	public static function findings( array $stats ) {
		$found = array();
		$keys  = isset( $stats['keys'] ) && is_array( $stats['keys'] ) ? $stats['keys'] : array();
		foreach ( $keys as $key => $entry ) {
			$worst = array(
				'noise' => array( '', 0 ),
				'churn' => array( '', 0 ),
			);
			foreach ( $entry['days'] as $date => $counts ) {
				if ( $counts['unchanged'] > $worst['noise'][1] ) {
					$worst['noise'] = array( $date, $counts['unchanged'] );
				}
				if ( $counts['rebuilt'] > $worst['churn'][1] ) {
					$worst['churn'] = array( $date, $counts['rebuilt'] );
				}
			}
			foreach ( array(
				'noise' => self::NOISE_THRESHOLD,
				'churn' => self::CHURN_THRESHOLD,
			) as $kind => $threshold ) {
				if ( $worst[ $kind ][1] >= $threshold ) {
					$found[] = array(
						'key'      => (string) $key,
						'kind'     => $kind,
						'day'      => $worst[ $kind ][0],
						'count'    => $worst[ $kind ][1],
						'contexts' => $entry['contexts'],
					);
				}
			}
		}
		usort(
			$found,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $found;
	}

	/**
	 * The counts for the ability: daily totals and each field's totals over the kept days.
	 *
	 * @return array
	 */
	public static function report() {
		$stats  = get_option( self::STATS_OPTION, array() );
		$stats  = is_array( $stats ) ? $stats : array();
		$fields = array();
		foreach ( isset( $stats['keys'] ) ? (array) $stats['keys'] : array() as $key => $entry ) {
			$totals = array(
				'checked'    => 0,
				'rebuilt'    => 0,
				'unchanged'  => 0,
				'after_save' => 0,
			);
			foreach ( $entry['days'] as $counts ) {
				foreach ( array_keys( $totals ) as $outcome ) {
					$totals[ $outcome ] += (int) $counts[ $outcome ];
				}
			}
			$fields[] = array(
				'key'       => (string) $key,
				'contexts'  => $entry['contexts'],
				'last_seen' => gmdate( 'c', (int) $entry['last_seen'] ),
				'last_post' => (int) $entry['last_post'],
			) + $totals;
		}
		usort(
			$fields,
			function ( $a, $b ) {
				return $b['checked'] <=> $a['checked'];
			}
		);
		return array(
			'enabled'    => (bool) apply_filters( 'mmsar_meta_watch_enabled', true ),
			'ignored'    => self::ignored_keys(),
			'thresholds' => array(
				'noise' => self::NOISE_THRESHOLD,
				'churn' => self::CHURN_THRESHOLD,
			),
			'days'       => isset( $stats['days'] ) ? $stats['days'] : array(),
			'fields'     => $fields,
			'findings'   => self::findings( $stats ),
		);
	}

	/**
	 * Registers the Site Health check.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function add_site_health_test( $tests ) {
		$tests['direct']['mmsar_meta_watch'] = array(
			'label' => __( 'Markdown refreshes from custom fields', 'make-my-site-agent-ready' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * The Site Health result.
	 *
	 * @return array
	 */
	public static function site_health_test() {
		$stats    = get_option( self::STATS_OPTION, array() );
		$findings = self::findings( is_array( $stats ) ? $stats : array() );
		$result   = array(
			'label'       => __( 'Custom field changes refresh Markdown without extra work', 'make-my-site-agent-ready' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Agent-Ready', 'make-my-site-agent-ready' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'When a custom field on a published post changes, its Markdown version is checked once and rebuilt only if it changed. No field has caused an unusual number of checks in the last two weeks.', 'make-my-site-agent-ready' ) . '</p>',
			'test'        => 'mmsar_meta_watch',
		);
		if ( ! $findings ) {
			return $result;
		}

		$items = '';
		foreach ( array_slice( $findings, 0, 10 ) as $finding ) {
			$text = 'noise' === $finding['kind']
				/* translators: 1: custom field name, 2: number of checks, 3: date. */
				? __( '%1$s: %2$d checks that changed nothing on %3$s.', 'make-my-site-agent-ready' )
				/* translators: 1: custom field name, 2: number of rebuilds, 3: date. */
				: __( '%1$s: %2$d Markdown rebuilds on %3$s, each clearing the cached llms.txt files.', 'make-my-site-agent-ready' );
			$items .= '<li>' . esc_html( sprintf( $text, $finding['key'], $finding['count'], $finding['day'] ) ) . '</li>';
		}
		$result['label']       = __( 'A custom field is causing frequent Markdown checks', 'make-my-site-agent-ready' );
		$result['status']      = 'recommended';
		$kinds                 = array_unique( wp_list_pluck( $findings, 'kind' ) );
		$result['description'] = '<p>' . esc_html__( 'These fields changed often enough to make the plugin check or rebuild Markdown many times in a day:', 'make-my-site-agent-ready' ) . '</p><ul>' . $items . '</ul>';
		if ( in_array( 'noise', $kinds, true ) ) {
			$result['description'] .= '<p>' . esc_html__( 'A field whose checks change nothing does not affect the Markdown. Add it to the mmsar_meta_watch_ignored_keys filter to stop the checks. Each check takes about a millisecond, so this matters little unless the count runs into the thousands.', 'make-my-site-agent-ready' ) . '</p>';
		}
		if ( in_array( 'churn', $kinds, true ) ) {
			$result['description'] .= '<p>' . esc_html__( 'A field that really changes the Markdown this often also clears the cached llms.txt and llms-full.txt each time, so they are rebuilt on the next request. If the field is something like a counter that the Markdown shows, consider leaving it out of the Markdown.', 'make-my-site-agent-ready' ) . '</p>';
		}
		return $result;
	}
}
