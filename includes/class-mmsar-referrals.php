<?php
/**
 * Counts visitors an AI assistant sent to the site.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI referrals: a person who clicked a link in ChatGPT, Perplexity, Claude and the like.
 *
 * The agent log answers "who reads this site"; this answers the question a site owner asks next,
 * "does any of it send people back". It is a count, not a log.
 *
 * **Why a script, when nothing else this plugin serves runs JavaScript.** A visit arriving from an
 * assistant is an ordinary page view, and on a cached site an ordinary page view never reaches PHP:
 * on miriamschwab.me a repeat request carrying `Referer: perplexity.ai`, or `?utm_source=chatgpt.com`,
 * came back a Cloudflare HIT. Server-side counting would see almost nothing. The script is inline and
 * small, runs only when switched on, and sends anything only when the referrer or `utm_source` names
 * an assistant on the list below. Off by default, and nothing else in the plugin relies on it.
 *
 * **Why its own table and not the agent log.** These are people, and the agent log is a log of what
 * agents do; its surface categories would count an unknown surface as an agent document, and its
 * identity check would read an assistant's name as a crawler claim and call a reader's address a
 * forgery. So this keeps a daily count per assistant per landing page, and nothing per visit: no
 * address, no user-agent, no cookie, no referrer URL. The throttle that stops one reader refreshing
 * from counting twice keys on the address in a transient that never reaches the table.
 *
 * **What it cannot see.** Many assistant apps open links without a referrer, and only ChatGPT adds
 * `utm_source` consistently, so the count is a floor. A browser that blocks the script, or a site
 * whose security plugin blocks the REST API for visitors, sends nothing. And the request can be
 * forged by anyone who reads the script; the throttle bounds that per address, and the landing page
 * is always one of the site's own published pages, never text from the request.
 */
class MMSAR_Referrals {

	/**
	 * Option holding the switch, '1' when on.
	 */
	const OPTION = 'mmsar_referrals';

	/**
	 * Option holding the installed table version.
	 */
	const DB_VERSION_OPTION = 'mmsar_referrals_db_version';

	/**
	 * Table version.
	 */
	const DB_VERSION = 1;

	/**
	 * How long one address landing on one page from one assistant counts once.
	 */
	const THROTTLE = 1800;

	/**
	 * Days of counts kept.
	 */
	const RETENTION_DAYS = 400;

	/**
	 * The REST namespace and route the script posts to.
	 */
	const REST_NAMESPACE = 'mmsar/v1';
	const REST_ROUTE     = '/referral';

	/**
	 * Every assistant recognised: label, referrer domains, `utm_source` values.
	 *
	 * A referrer domain matches itself and any subdomain, dot-bounded, so `www.perplexity.ai` is
	 * Perplexity and `notperplexity.ai` is not. Only domains that are the assistant itself are
	 * listed: `bing.com` and `google.com` carry Copilot and AI Overviews traffic, but also every
	 * ordinary search click, and cannot be told apart from a referrer.
	 */
	const ASSISTANTS = array(
		'chatgpt'    => array(
			'label' => 'ChatGPT',
			'hosts' => array( 'chatgpt.com', 'chat.openai.com' ),
			'utm'   => array( 'chatgpt.com', 'chatgpt', 'openai' ),
		),
		'perplexity' => array(
			'label' => 'Perplexity',
			'hosts' => array( 'perplexity.ai' ),
			'utm'   => array( 'perplexity', 'perplexity.ai' ),
		),
		'claude'     => array(
			'label' => 'Claude',
			'hosts' => array( 'claude.ai' ),
			'utm'   => array( 'claude', 'claude.ai' ),
		),
		'gemini'     => array(
			'label' => 'Gemini',
			'hosts' => array( 'gemini.google.com', 'bard.google.com' ),
			'utm'   => array( 'gemini', 'gemini.google.com' ),
		),
		'copilot'    => array(
			'label' => 'Microsoft Copilot',
			'hosts' => array( 'copilot.microsoft.com', 'copilot.cloud.microsoft', 'm365copilot.com' ),
			'utm'   => array( 'copilot', 'copilot.microsoft.com' ),
		),
		'deepseek'   => array(
			'label' => 'DeepSeek',
			'hosts' => array( 'chat.deepseek.com' ),
			'utm'   => array( 'deepseek' ),
		),
		'grok'       => array(
			'label' => 'Grok',
			'hosts' => array( 'grok.com' ),
			'utm'   => array( 'grok', 'grok.com' ),
		),
		'mistral'    => array(
			'label' => 'Le Chat (Mistral)',
			'hosts' => array( 'chat.mistral.ai' ),
			'utm'   => array( 'mistral', 'chat.mistral.ai' ),
		),
		'meta'       => array(
			'label' => 'Meta AI',
			'hosts' => array( 'meta.ai' ),
			'utm'   => array( 'meta.ai' ),
		),
		'you'        => array(
			'label' => 'You.com',
			'hosts' => array( 'you.com' ),
			'utm'   => array( 'you.com' ),
		),
		'phind'      => array(
			'label' => 'Phind',
			'hosts' => array( 'phind.com' ),
			'utm'   => array( 'phind', 'phind.com' ),
		),
		'duckai'     => array(
			'label' => 'Duck.ai',
			'hosts' => array( 'duck.ai' ),
			'utm'   => array( 'duck.ai' ),
		),
	);

	/**
	 * Init. Hooked only while the agent log is on and this is switched on.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 99 );
	}

	/**
	 * Whether referrals are being counted.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return mmsar_feature_enabled( 'agent_log' ) && '1' === (string) get_option( self::OPTION, '' );
	}

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mmsar_referrals';
	}

	/**
	 * Creates or upgrades the table.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
			day date NOT NULL,
			assistant varchar(32) NOT NULL DEFAULT '',
			path varchar(190) NOT NULL DEFAULT '',
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,assistant,path),
			KEY assistant (assistant)
			) {$collate};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Whether a host is a domain or one of its subdomains, dot-bounded.
	 *
	 * @param string $host   Host, lowercase.
	 * @param string $domain Domain, lowercase.
	 * @return bool
	 */
	private static function host_is( $host, $domain ) {
		return $host === $domain || ( strlen( $host ) > strlen( $domain ) && substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain );
	}

	/**
	 * Which assistant sent a visit, from the referrer's host and `utm_source`, or '' for none.
	 *
	 * The referrer wins when both are present, because it is set by the browser rather than written
	 * into a link.
	 *
	 * @param string $host Referrer host.
	 * @param string $utm  `utm_source` value.
	 * @return string Key into ASSISTANTS, or ''.
	 */
	public static function classify( $host, $utm ) {
		$host = strtolower( trim( (string) $host ) );
		$utm  = strtolower( trim( (string) $utm ) );
		if ( '' !== $host ) {
			foreach ( self::ASSISTANTS as $key => $assistant ) {
				foreach ( $assistant['hosts'] as $domain ) {
					if ( self::host_is( $host, $domain ) ) {
						return $key;
					}
				}
			}
		}
		if ( '' !== $utm ) {
			foreach ( self::ASSISTANTS as $key => $assistant ) {
				if ( in_array( $utm, $assistant['utm'], true ) ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * Label for an assistant key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function label( $key ) {
		return isset( self::ASSISTANTS[ $key ]['label'] ) ? self::ASSISTANTS[ $key ]['label'] : (string) $key;
	}

	/**
	 * The script, as a string, so it can be tested and printed through core's inline-script helper.
	 *
	 * Decides in the browser whether there is anything to send, so an ordinary visit makes no
	 * request at all. The lists come from ASSISTANTS, and the endpoint checks them again.
	 *
	 * @param string $endpoint REST URL to post to.
	 * @return string
	 */
	public static function script( $endpoint ) {
		$hosts = array();
		$utms  = array();
		foreach ( self::ASSISTANTS as $assistant ) {
			$hosts = array_merge( $hosts, $assistant['hosts'] );
			$utms  = array_merge( $utms, $assistant['utm'] );
		}
		$config = wp_json_encode(
			array(
				'e' => $endpoint,
				'h' => array_values( array_unique( $hosts ) ),
				'u' => array_values( array_unique( $utms ) ),
			)
		);

		return '(function(c){try{var h="",r=document.referrer,s="";'
			. 'if(r){h=new URL(r).hostname.toLowerCase();}'
			. 's=(new URL(location.href).searchParams.get("utm_source")||"").toLowerCase();'
			. 'if(!h&&!s){return;}'
			. 'var m=function(x){for(var i=0;i<c.h.length;i++){var d=c.h[i];if(x===d||x.slice(-d.length-1)==="."+d){return true;}}return false;};'
			. 'if(!(h&&m(h))&&c.u.indexOf(s)<0){return;}'
			. 'var b=JSON.stringify({r:h,u:s,p:location.pathname});'
			. 'if(navigator.sendBeacon){navigator.sendBeacon(c.e,new Blob([b],{type:"application/json"}));}'
			. 'else{fetch(c.e,{method:"POST",body:b,headers:{"Content-Type":"application/json"},keepalive:true});}'
			. '}catch(e){}})(' . $config . ');';
	}

	/**
	 * Prints the script on front-end pages.
	 *
	 * @return void
	 */
	public static function print_script() {
		if ( is_admin() || is_feed() || is_robots() || is_404() || is_preview() || is_user_logged_in() ) {
			return;
		}
		wp_print_inline_script_tag( self::script( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ), array( 'id' => 'mmsar-referral' ) );
	}

	/**
	 * Registers the endpoint the script posts to.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: the visitor is anonymous. What it can write is bounded below —
				// a known assistant, one of the site's own published pages, once per address per
				// half hour.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The landing page to count, as a path on this site: a published post's permalink, '/' for the
	 * front page, or '(other)' for anything else. Never the path that was sent.
	 *
	 * @param string $path Path from the request.
	 * @return string
	 */
	private static function landing_path( $path ) {
		$path = '/' . ltrim( (string) wp_parse_url( (string) $path, PHP_URL_PATH ), '/' );
		$home = '/' . ltrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( rtrim( $path, '/' ) === rtrim( $home, '/' ) ) {
			return '/';
		}
		$scheme_host = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$post_id     = url_to_postid( $scheme_host . $path );
		$post        = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) || ! is_post_type_viewable( $post->post_type ) ) {
			return '(other)';
		}
		return MMSAR_Agent_Log::request_path( (string) get_permalink( $post ) );
	}

	/**
	 * Counts one referral.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$done = new WP_REST_Response( null, 204 );
		$done->header( 'Cache-Control', 'no-store' );

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $done;
		}
		$host = isset( $body['r'] ) && is_string( $body['r'] ) ? substr( $body['r'], 0, 253 ) : '';
		$utm  = isset( $body['u'] ) && is_string( $body['u'] ) ? substr( $body['u'], 0, 100 ) : '';
		$key  = self::classify( $host, $utm );
		if ( '' === $key ) {
			return $done;
		}
		$path = self::landing_path( isset( $body['p'] ) && is_string( $body['p'] ) ? substr( $body['p'], 0, 2000 ) : '' );

		$throttle = 'mmsar_ref_' . md5( MMSAR_Agent_Log::client_ip() . '|' . $key . '|' . $path );
		if ( get_transient( $throttle ) ) {
			return $done;
		}
		set_transient( $throttle, 1, self::THROTTLE );

		self::maybe_install();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Incrementing a counter in this plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (day, assistant, path, hits) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
				self::table(),
				gmdate( 'Y-m-d' ),
				$key,
				mb_substr( $path, 0, 190 )
			)
		);

		// Pruned now and then rather than on a schedule, the same way the agent log is.
		if ( 0 === wp_rand( 0, 49 ) ) {
			self::prune();
		}
		return $done;
	}

	/**
	 * Deletes counts older than the retention window.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pruning this plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table(), gmdate( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Whether the table exists, so a read on a site that never switched this on is not an error.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		return (int) get_option( self::DB_VERSION_OPTION, 0 ) > 0;
	}

	/**
	 * Totals over the last N days: overall, per assistant, and the most-landed-on pages.
	 *
	 * @param int $days  Window in days, counting today.
	 * @param int $pages How many landing pages to return.
	 * @return array{total:int,days:int,by_assistant:array<int,array{assistant:string,label:string,hits:int}>,by_page:array<int,array{path:string,hits:int,assistants:string}>}
	 */
	public static function get_summary( $days = 30, $pages = 15 ) {
		$out = array(
			'total'        => 0,
			'days'         => (int) $days,
			'by_assistant' => array(),
			'by_page'      => array(),
		);
		if ( ! self::table_exists() ) {
			return $out;
		}
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table for an admin screen.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT assistant, SUM(hits) AS hits FROM %i WHERE day >= %s GROUP BY assistant ORDER BY hits DESC', self::table(), $since ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$hits                  = (int) $row['hits'];
			$out['total']         += $hits;
			$out['by_assistant'][] = array(
				'assistant' => (string) $row['assistant'],
				'label'     => self::label( (string) $row['assistant'] ),
				'hits'      => $hits,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT path, SUM(hits) AS hits, GROUP_CONCAT(DISTINCT assistant ORDER BY assistant) AS assistants FROM %i WHERE day >= %s GROUP BY path ORDER BY hits DESC LIMIT %d', self::table(), $since, max( 1, (int) $pages ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$labels           = array_map( array( __CLASS__, 'label' ), array_filter( explode( ',', (string) $row['assistants'] ) ) );
			$out['by_page'][] = array(
				'path'       => (string) $row['path'],
				'hits'       => (int) $row['hits'],
				'assistants' => implode( ', ', $labels ),
			);
		}
		return $out;
	}
}
