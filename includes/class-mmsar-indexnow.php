<?php
/**
 * IndexNow: tells participating search engines when a page is published, changed or removed.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submits changed URLs to IndexNow, the shared endpoint Bing, Yandex, Seznam, Naver and others read.
 *
 * Why an agent-readiness plugin does this: Bing's index is what ChatGPT search and Copilot ground
 * their answers on, so a page Bing has not recrawled is a page those assistants describe as it was.
 * Google does not take part, and the plugin says so rather than implying otherwise.
 *
 * This is the plugin's only outbound request to a third party, which is why it is off by default
 * and disclosed under External services in readme.txt. What is sent: the site's host name, the key,
 * the key file's URL, and the public URLs that changed. Nothing about visitors.
 *
 * No cron, keeping the promise in session-opener: URLs are collected while a post saves and sent
 * once on `shutdown` of that same request. An editor save is already a slow request, and the send
 * is capped at a few seconds.
 *
 * Another SEO plugin that already pings IndexNow wins. Two plugins submitting the same URLs is
 * harmless to IndexNow but wasteful, and the owner configured that one first.
 */
class MMSAR_IndexNow {

	/**
	 * Where submissions go. The shared endpoint forwards to every participating engine.
	 */
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Option holding the site's key.
	 */
	const KEY_OPTION = 'mmsar_indexnow_key';

	/**
	 * Option holding the outcome of the last submission, for the settings screen.
	 */
	const LAST_OPTION = 'mmsar_indexnow_last';

	/**
	 * How long a page's current URL, just submitted, is not submitted again. The block editor saves
	 * a post twice when a meta box is present (once over REST, once as a form post), and both would
	 * ping. Applies only to a URL that still answers: an address a post has just left is always
	 * sent, or a slug fixed within a minute of publishing would leave its first URL unreported.
	 */
	const DEDUPE_SECONDS = 60;

	/**
	 * Most URLs IndexNow accepts in one request.
	 */
	const MAX_URLS = 10000;

	/**
	 * URLs collected during this request, each mapped to whether it is an address just left (and so
	 * exempt from the dedupe).
	 *
	 * @var array<string, bool>
	 */
	private static $queue = array();

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		// Ahead of redirect_canonical (10), which would otherwise add a trailing slash.
		add_action( 'template_redirect', array( __CLASS__, 'serve_key' ), 0 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 20, 4 );
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
	}

	/**
	 * Whether a string is a valid IndexNow key: 8 to 128 of a-z, A-Z, 0-9 and hyphen.
	 *
	 * @param mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9-]{8,128}\z/', $key );
	}

	/**
	 * The site's key, created the first time it is needed.
	 *
	 * @return string
	 */
	public static function key() {
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! self::is_valid_key( $key ) ) {
			$key = wp_generate_password( 32, false, false );
			update_option( self::KEY_OPTION, $key, true );
		}
		return $key;
	}

	/**
	 * The key file's public URL. IndexNow only accepts URLs at or below the key file's directory,
	 * so it has to sit at the root of the site.
	 *
	 * @return string
	 */
	public static function key_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Add rewrite rules. The rule names the literal key, so no other `.txt` path is claimed.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^' . preg_quote( self::key(), '/' ) . '\.txt$', 'index.php?mmsar_indexnow_key=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_indexnow_key';
		return $vars;
	}

	/**
	 * Serves the key file: the key, as plain text, which is how an engine confirms the site sent it.
	 *
	 * @return void
	 */
	public static function serve_key() {
		if ( ! get_query_var( 'mmsar_indexnow_key' ) ) {
			return;
		}
		mmsar_send_cache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		status_header( 200 );
		echo esc_html( self::key() );
		exit;
	}

	/**
	 * Another plugin that already submits to IndexNow, by name, or '' when there is none.
	 *
	 * Detected from each plugin's own setting where there is one, so having the plugin installed
	 * with its IndexNow switched off does not count. Not exhaustive: anything missing can be
	 * reported through the `mmsar_indexnow_handled_elsewhere` filter. Cloudflare's Crawler Hints
	 * also submit to IndexNow and cannot be seen from inside WordPress.
	 *
	 * @return string
	 */
	public static function handled_elsewhere() {
		$name = '';

		$yoast = get_option( 'wpseo', array() );
		if ( defined( 'WPSEO_PREMIUM_VERSION' ) && is_array( $yoast ) && ! empty( $yoast['enable_index_now'] ) ) {
			$name = 'Yoast SEO Premium';
		}

		$rank_modules = get_option( 'rank_math_modules', array() );
		if ( '' === $name && defined( 'RANK_MATH_VERSION' ) && is_array( $rank_modules ) && in_array( 'instant-indexing', $rank_modules, true ) ) {
			$name = 'Rank Math (Instant Indexing)';
		}

		if ( '' === $name ) {
			$active  = (array) get_option( 'active_plugins', array() );
			$plugins = array(
				'indexnow/indexnow-url-submission.php'  => 'IndexNow (Microsoft)',
				'aioseo-index-now/aioseo-index-now.php' => 'All in One SEO IndexNow',
			);
			foreach ( $plugins as $file => $label ) {
				if ( in_array( $file, $active, true ) ) {
					$name = $label;
					break;
				}
			}
		}

		/**
		 * Filters the name of another plugin or service that already submits to IndexNow.
		 *
		 * Return a non-empty name to stop this plugin submitting. Return '' to submit anyway.
		 *
		 * @param string $name What was detected, or ''.
		 */
		return (string) apply_filters( 'mmsar_indexnow_handled_elsewhere', $name );
	}

	/**
	 * Whether a published post is one this plugin would put in front of an engine.
	 *
	 * The same test as the lists agents read: a published post, in an enabled type, not
	 * password-protected, not noindex.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_listed( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& empty( $post->post_password )
			&& in_array( $post->post_type, mmsar_get_enabled_post_types(), true )
			&& ! MMSAR_Noindex::is_excluded( $post );
	}

	/**
	 * Which URLs a save should submit, given the post before and after it.
	 *
	 * Pure, so the rules can be tested without WordPress: the URL it has now when it is listed, and
	 * the URL it had when it was listed and that address has stopped answering for it — unpublished,
	 * protected, noindexed, or moved to a new slug. An engine told about a URL that now 404s or
	 * redirects drops or updates it; that is the point of sending it.
	 *
	 * @param string $before_url Its URL before the save, or '' when it was not listed then.
	 * @param string $after_url  Its URL after the save, or '' when it is not listed now.
	 * @return string[]
	 */
	public static function urls_for_change( $before_url, $after_url ) {
		$urls = array();
		if ( '' !== $after_url ) {
			$urls[] = $after_url;
		}
		if ( '' !== $before_url && $before_url !== $after_url ) {
			$urls[] = $before_url;
		}
		return $urls;
	}

	/**
	 * Queues the URLs a save changed.
	 *
	 * `wp_after_insert_post` runs once the post's meta and terms are saved too, so an SEO plugin's
	 * noindex choice made in the same save is already readable here.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        The post as saved.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before The post before the save, null for a new one.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post, $update, $post_before ) {
		unset( $post_id, $update );
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		// The before-object's robots setting is the current one, not the one it had: post meta has
		// already been saved. A post that was just switched to noindex therefore reads as unlisted
		// on both sides, and its old URL is not sent. The engine finds the noindex on its next
		// crawl; this only loses the early nudge in that one case.
		MMSAR_Noindex::reset();
		$before = self::is_listed( $post_before ) ? (string) get_permalink( $post_before ) : '';
		$after  = self::is_listed( $post ) ? (string) get_permalink( $post ) : '';
		foreach ( self::urls_for_change( $before, $after ) as $url ) {
			$left                = $url !== $after;
			self::$queue[ $url ] = ! empty( self::$queue[ $url ] ) || $left;
		}
	}

	/**
	 * Sends what this request queued, once, when it ends.
	 *
	 * @return void
	 */
	public static function flush_queue() {
		if ( empty( self::$queue ) ) {
			return;
		}
		$queue       = self::$queue;
		self::$queue = array();

		$elsewhere = self::handled_elsewhere();
		if ( '' !== $elsewhere ) {
			return;
		}

		$fresh = array();
		foreach ( $queue as $url => $left ) {
			$marker = 'mmsar_indexnow_' . md5( $url );
			if ( ! $left && get_transient( $marker ) ) {
				continue;
			}
			set_transient( $marker, 1, self::DEDUPE_SECONDS );
			$fresh[] = $url;
		}
		if ( ! $fresh ) {
			return;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 3,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( self::payload( $fresh ) ),
			)
		);

		update_option(
			self::LAST_OPTION,
			array(
				'time'  => time(),
				'count' => count( $fresh ),
				'code'  => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
				'error' => is_wp_error( $response ) ? $response->get_error_message() : '',
			),
			false
		);
	}

	/**
	 * The request body IndexNow expects.
	 *
	 * @param string[] $urls URLs on this site.
	 * @return array
	 */
	public static function payload( $urls ) {
		return array(
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => self::key(),
			'keyLocation' => self::key_url(),
			'urlList'     => array_slice( array_values( $urls ), 0, self::MAX_URLS ),
		);
	}

	/**
	 * What the last submission's status code means, in a sentence for the settings screen.
	 *
	 * @param int $code HTTP status, 0 when the request itself failed.
	 * @return string
	 */
	public static function describe_code( $code ) {
		switch ( (int) $code ) {
			case 200:
				return __( 'Accepted.', 'make-my-site-agent-ready' );
			case 202:
				return __( 'Received; the key file is still being checked.', 'make-my-site-agent-ready' );
			case 400:
				return __( 'Rejected as malformed.', 'make-my-site-agent-ready' );
			case 403:
				return __( 'Rejected: the key file could not be confirmed. Open the key file link above and check it shows the key.', 'make-my-site-agent-ready' );
			case 422:
				return __( 'Rejected: the URLs do not belong to this site, or do not match the key.', 'make-my-site-agent-ready' );
			case 429:
				return __( 'Rejected for sending too often.', 'make-my-site-agent-ready' );
			case 0:
				return __( 'The request did not complete.', 'make-my-site-agent-ready' );
			default:
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Answered with status %d.', 'make-my-site-agent-ready' ), (int) $code );
		}
	}
}
