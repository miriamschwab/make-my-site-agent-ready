<?php
/**
 * WebMCP: this site's MCP tools, registered in the visitor's browser.
 *
 * The MCP server answers agents that connect to it over HTTP. WebMCP is the other route: an agent
 * working inside a browser tab asks the page, through `document.modelContext`, which tools it
 * offers. This class makes the page offer the same read-only tools the MCP server already has, by
 * reading their definitions from the server card and sending every call to the MCP endpoint. Nothing
 * here is a second implementation of a tool, so the browser and the server can never disagree about
 * what a tool does, what it may return, or how often it may be called.
 *
 * Two independent parts:
 *
 *   1. Origin-trial tokens. Browsers expose WebMCP during a trial only to pages that carry a token
 *      registered for their origin. A token turns the API on for the whole page, whatever registers
 *      tools on it (a theme's contact form, another plugin), so the tokens are printed whenever any
 *      are saved, independently of the bridge switch.
 *   2. The bridge (the `webmcp` feature, off by default, needs the MCP server). A small inline
 *      loader checks for the API and stops there in every browser that lacks it, which is almost
 *      all of them today. Where the API exists, it loads the bridge script.
 *
 * The bridge script is served from a path that carries the plugin version rather than as a static
 * file with a `?ver=` argument. Some CDNs cache plugin assets by path and ignore the query string,
 * and a stale bridge would keep calling tools the way an older version did. The path has no `.js`
 * extension on purpose: Elementor Hosting answers a request for a `.js` file that does not exist on
 * disk with a 302 to `/index.php?dynamic_asset=…`, an extra uncached round trip on every load
 * (measured on miriamschwab.me, 2026-10-04). Browsers go by the Content-Type, not the extension.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebMCP bridge and origin-trial tokens.
 */
class MMSAR_WebMCP {

	/**
	 * Option: origin-trial tokens, an array of strings.
	 */
	const TOKENS_OPTION = 'mmsar_webmcp_tokens';

	/**
	 * Most tokens kept. One per browser vendor is the real need (Chrome and Edge run separate
	 * trials); a few spare allow a renewal to overlap the token it replaces.
	 */
	const MAX_TOKENS = 5;

	/**
	 * Longest token accepted, in characters. Real ones are a few hundred.
	 */
	const MAX_TOKEN_LENGTH = 2000;

	/**
	 * Days before expiry that the settings screen and Site Health start warning.
	 */
	const WARN_DAYS = 14;

	/**
	 * The feature name a WebMCP trial token carries.
	 */
	const FEATURE = 'WebMCP';

	/**
	 * Query var for the bridge script route.
	 */
	const QUERY_VAR = 'mmsar_webmcp_js';

	/**
	 * Init. Tokens are printed whenever saved; the bridge only when switched on.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_trial_tokens' ), 1 );
		add_filter( 'site_status_tests', array( __CLASS__, 'add_site_health_test' ) );

		if ( ! self::bridge_enabled() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_script' ), 0 );
		add_action( 'wp_footer', array( __CLASS__, 'print_loader' ), 99 );
	}

	/**
	 * Whether the bridge is on. It has no tools of its own, so it needs the MCP server.
	 *
	 * @return bool
	 */
	public static function bridge_enabled() {
		return mmsar_feature_enabled( 'webmcp' ) && mmsar_feature_enabled( 'mcp_server' );
	}

	// -------------------------------------------------------------------------
	// Tokens
	// -------------------------------------------------------------------------

	/**
	 * The saved tokens.
	 *
	 * @return string[]
	 */
	public static function tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? array_values( array_filter( $tokens, 'is_string' ) ) : array();
	}

	/**
	 * Decodes an origin-trial token's payload.
	 *
	 * The format is Chromium's: base64 of a version byte (2 or 3), a 64-byte signature, a 4-byte
	 * big-endian payload length, then that many bytes of JSON with `origin`, `feature` and `expiry`
	 * (and optionally `isSubdomain`, `isThirdParty`). The signature is the browser's to check, with
	 * the vendor's key; this only reads what the token claims, which is enough to catch a token
	 * pasted onto the wrong site, for the wrong feature, or after it expired.
	 *
	 * @param string $token Token as pasted.
	 * @return array|null Keys 'origin', 'feature', 'expiry' (int), 'subdomain' (bool), 'third_party' (bool); null if it does not decode.
	 */
	public static function decode_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LENGTH || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $token ) ) {
			return null;
		}
		$raw = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Origin-trial tokens are base64 by definition; decoded only to read their claims.
		if ( false === $raw || strlen( $raw ) < 69 ) {
			return null;
		}
		$version = ord( $raw[0] );
		if ( 2 !== $version && 3 !== $version ) {
			return null;
		}
		$length = unpack( 'N', substr( $raw, 65, 4 ) );
		$length = is_array( $length ) ? (int) $length[1] : 0;
		if ( $length <= 0 || strlen( $raw ) !== 69 + $length ) {
			return null;
		}
		$payload = json_decode( substr( $raw, 69 ), true );
		if ( ! is_array( $payload ) || ! isset( $payload['origin'], $payload['feature'], $payload['expiry'] )
			|| ! is_string( $payload['origin'] ) || ! is_string( $payload['feature'] ) || ! is_numeric( $payload['expiry'] ) ) {
			return null;
		}
		return array(
			'origin'      => $payload['origin'],
			'feature'     => $payload['feature'],
			'expiry'      => (int) $payload['expiry'],
			'subdomain'   => ! empty( $payload['isSubdomain'] ),
			'third_party' => ! empty( $payload['isThirdParty'] ),
		);
	}

	/**
	 * Whether a token's origin covers a site's address.
	 *
	 * @param string $token_origin Origin from the token, e.g. `https://example.com:443`.
	 * @param bool   $subdomain    Whether the token covers subdomains.
	 * @param string $site_url     The site's home URL.
	 * @return bool
	 */
	public static function origin_matches( $token_origin, $subdomain, $site_url ) {
		$token = self::origin_parts( $token_origin );
		$site  = self::origin_parts( $site_url );
		if ( null === $token || null === $site || $token['scheme'] !== $site['scheme'] || $token['port'] !== $site['port'] ) {
			return false;
		}
		if ( $token['host'] === $site['host'] ) {
			return true;
		}
		return $subdomain && substr( $site['host'], -strlen( '.' . $token['host'] ) ) === '.' . $token['host'];
	}

	/**
	 * Scheme, host and port of a URL, with the default port filled in.
	 *
	 * @param string $url URL or origin.
	 * @return array|null
	 */
	private static function origin_parts( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		return array(
			'scheme' => $scheme,
			'host'   => strtolower( $parts['host'] ),
			'port'   => isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 ),
		);
	}

	/**
	 * What a token means for this site.
	 *
	 * @param string   $token    Token.
	 * @param string   $site_url The site's home URL.
	 * @param int|null $now      Unix time, for tests. Null is now.
	 * @return array 'state' is one of valid, expiring, expired, wrong_origin, wrong_feature,
	 *               third_party, invalid; plus the decoded claims when there are any.
	 */
	public static function check_token( $token, $site_url, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$decoded = self::decode_token( $token );
		if ( null === $decoded ) {
			return array( 'state' => 'invalid' );
		}
		if ( 0 !== strcasecmp( $decoded['feature'], self::FEATURE ) ) {
			$state = 'wrong_feature';
		} elseif ( $decoded['third_party'] ) {
			// A third-party token only works when injected by a script from the token's origin.
			// Printed in this page's own head it does nothing.
			$state = 'third_party';
		} elseif ( ! self::origin_matches( $decoded['origin'], $decoded['subdomain'], $site_url ) ) {
			$state = 'wrong_origin';
		} elseif ( $decoded['expiry'] <= $now ) {
			$state = 'expired';
		} elseif ( $decoded['expiry'] - $now <= self::WARN_DAYS * DAY_IN_SECONDS ) {
			$state = 'expiring';
		} else {
			$state = 'valid';
		}
		return array_merge( array( 'state' => $state ), $decoded );
	}

	/**
	 * Sanitizes the token textarea: one token per line, each checked before it is kept.
	 *
	 * A token that would do nothing on this site (wrong origin, wrong feature, expired, malformed)
	 * is dropped with a notice saying why, rather than stored and silently ignored by the browser.
	 *
	 * @param mixed $input Submitted value: a string from the form, or an array from code.
	 * @return string[]
	 */
	public static function sanitize_tokens( $input ) {
		$lines = is_array( $input ) ? $input : preg_split( '/[\s,]+/', (string) $input );
		$kept  = array();
		$site  = home_url( '/' );

		foreach ( (array) $lines as $line ) {
			$token = trim( (string) $line );
			if ( '' === $token || in_array( $token, $kept, true ) ) {
				continue;
			}
			$check = self::check_token( $token, $site );
			if ( in_array( $check['state'], array( 'valid', 'expiring' ), true ) ) {
				if ( count( $kept ) < self::MAX_TOKENS ) {
					$kept[] = $token;
				}
				continue;
			}
			self::add_token_error( $check );
		}
		return $kept;
	}

	/**
	 * Adds a settings notice for a rejected token, once per reason.
	 *
	 * @param array $check Result of check_token().
	 * @return void
	 */
	private static function add_token_error( $check ) {
		$code = 'mmsar_webmcp_token_' . $check['state'];
		foreach ( get_settings_errors( self::TOKENS_OPTION ) as $error ) {
			if ( isset( $error['code'] ) && $error['code'] === $code ) {
				return;
			}
		}
		add_settings_error( self::TOKENS_OPTION, $code, self::state_message( $check ), 'error' );
	}

	/**
	 * A one-sentence explanation of a token's state, for the settings screen.
	 *
	 * @param array $check Result of check_token().
	 * @return string
	 */
	public static function state_message( $check ) {
		switch ( $check['state'] ) {
			case 'valid':
				/* translators: 1: origin, 2: expiry date */
				return sprintf( __( 'Valid for %1$s until %2$s.', 'make-my-site-agent-ready' ), $check['origin'], wp_date( get_option( 'date_format' ), $check['expiry'] ) );
			case 'expiring':
				/* translators: 1: origin, 2: expiry date */
				return sprintf( __( 'Valid for %1$s, but expires on %2$s. Renew it on the trial\'s registration page and paste the new token here.', 'make-my-site-agent-ready' ), $check['origin'], wp_date( get_option( 'date_format' ), $check['expiry'] ) );
			case 'expired':
				/* translators: %s: expiry date */
				return sprintf( __( 'A token was not saved because it expired on %s. Renew it on the trial\'s registration page.', 'make-my-site-agent-ready' ), wp_date( get_option( 'date_format' ), $check['expiry'] ) );
			case 'wrong_origin':
				/* translators: 1: origin in the token, 2: this site's address */
				return sprintf( __( 'A token was not saved because it is registered for %1$s, not for this site (%2$s).', 'make-my-site-agent-ready' ), $check['origin'], home_url( '/' ) );
			case 'wrong_feature':
				/* translators: %s: feature name in the token */
				return sprintf( __( 'A token was not saved because it is for a different trial ("%s"), not WebMCP.', 'make-my-site-agent-ready' ), $check['feature'] );
			case 'third_party':
				return __( 'A token was not saved because it was registered with third-party matching, which only works when another site\'s script injects it. Register again with third-party matching unticked.', 'make-my-site-agent-ready' );
		}
		return __( 'A token was not saved because it is not an origin-trial token. Copy the whole token from the trial\'s registration page.', 'make-my-site-agent-ready' );
	}

	/**
	 * Prints each saved token that still works as an origin-trial meta tag.
	 *
	 * Expired or mismatched tokens are skipped rather than printed: the browser would ignore them,
	 * and a stale tag in the head misleads whoever reads the source next.
	 *
	 * @return void
	 */
	public static function print_trial_tokens() {
		if ( is_admin() ) {
			return;
		}
		$site = home_url( '/' );
		foreach ( self::tokens() as $token ) {
			$check = self::check_token( $token, $site );
			if ( in_array( $check['state'], array( 'valid', 'expiring' ), true ) ) {
				printf( '<meta http-equiv="origin-trial" content="%s">' . "\n", esc_attr( $token ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Bridge
	// -------------------------------------------------------------------------

	/**
	 * The bridge script's URL, with the plugin version in the path.
	 *
	 * @return string
	 */
	public static function script_url() {
		return home_url( '/mmsar-webmcp/' . rawurlencode( MMSAR_VERSION ) . '/bridge' );
	}

	/**
	 * Route for the bridge script. Any version string is answered with the current script, so a
	 * cached page that still names an older version gets working code rather than a 404.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^mmsar-webmcp/[^/]+/bridge$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Add the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serves the bridge script.
	 *
	 * Not logged: a browser loads it once per page view where WebMCP exists, which says nothing
	 * about an agent. The tool calls that follow are logged, under their own surface.
	 *
	 * @return void
	 */
	public static function serve_script() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$path = dirname( __DIR__ ) . '/assets/webmcp.js';
		if ( ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: text/javascript; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// The path changes with every version, so the content behind one path never does.
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Serving this plugin's own static script.
		exit;
	}

	/**
	 * Prints the loader: a feature check that loads the bridge only where WebMCP exists.
	 *
	 * @return void
	 */
	public static function print_loader() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		$code = sprintf(
			'(function(d,n){var m=d.modelContext||n.modelContext;if(!m||typeof m.registerTool!=="function")return;'
			. 'var s=d.createElement("script");s.src=%1$s;s.defer=true;s.setAttribute("data-endpoint",%2$s);s.setAttribute("data-card",%3$s);d.head.appendChild(s);})(document,navigator);',
			wp_json_encode( esc_url_raw( self::script_url() ) ),
			wp_json_encode( esc_url_raw( MMSAR_MCP::endpoint_url() ) ),
			wp_json_encode( esc_url_raw( home_url( '/.well-known/mcp/server-card.json' ) ) )
		);
		wp_print_inline_script_tag( $code, array( 'id' => 'mmsar-webmcp' ) );
	}

	/**
	 * The tools the bridge registers: every read-only tool the MCP server lists.
	 *
	 * The bridge reads the same thing from the server card at run time; this is for the settings
	 * screen, so it shows what a browser will actually get, filter-added tools included.
	 *
	 * @return array[] Each with 'name' and 'title'.
	 */
	public static function browser_tools() {
		$tools = array();
		foreach ( MMSAR_MCP::tools() as $tool ) {
			if ( empty( $tool['annotations']['readOnlyHint'] ) || empty( $tool['name'] ) ) {
				continue;
			}
			$tools[] = array(
				'name'  => (string) $tool['name'],
				'title' => isset( $tool['title'] ) ? (string) $tool['title'] : (string) $tool['name'],
			);
		}
		return $tools;
	}

	// -------------------------------------------------------------------------
	// Site Health
	// -------------------------------------------------------------------------

	/**
	 * Registers the Site Health test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function add_site_health_test( $tests ) {
		$tests['direct']['mmsar_webmcp'] = array(
			'label' => __( 'WebMCP origin-trial token', 'make-my-site-agent-ready' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * The Site Health result: silent (good) unless WebMCP is on and its token is missing or ending.
	 *
	 * @return array
	 */
	public static function site_health_test() {
		$result = array(
			'label'       => __( 'WebMCP is set up', 'make-my-site-agent-ready' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Agent-Ready', 'make-my-site-agent-ready' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Browsers that support WebMCP can see this site\'s tools.', 'make-my-site-agent-ready' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready#mmsar-section-webmcp' ) ) . '">' . esc_html__( 'WebMCP settings', 'make-my-site-agent-ready' ) . '</a></p>',
			'test'        => 'mmsar_webmcp',
		);

		if ( ! self::bridge_enabled() && empty( self::tokens() ) ) {
			$result['label']       = __( 'WebMCP is not in use', 'make-my-site-agent-ready' );
			$result['description'] = '<p>' . esc_html__( 'Nothing to check.', 'make-my-site-agent-ready' ) . '</p>';
			return $result;
		}

		$best = null;
		foreach ( self::tokens() as $token ) {
			$check = self::check_token( $token, home_url( '/' ) );
			if ( 'valid' === $check['state'] ) {
				return $result;
			}
			if ( 'expiring' === $check['state'] ) {
				$best = $check;
			}
		}

		$result['status'] = 'recommended';
		if ( null !== $best ) {
			$result['label']       = __( 'The WebMCP origin-trial token expires soon', 'make-my-site-agent-ready' );
			$result['description'] = '<p>' . esc_html( self::state_message( $best ) ) . '</p>';
		} else {
			$result['label']       = __( 'WebMCP has no working origin-trial token', 'make-my-site-agent-ready' );
			$result['description'] = '<p>' . esc_html__( 'Without a token, only browsers with the WebMCP testing flag switched on can see this site\'s tools. Register this site for the trial and paste the token into the WebMCP settings.', 'make-my-site-agent-ready' ) . '</p>';
		}
		return $result;
	}
}
