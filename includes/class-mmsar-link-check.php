<?php
/**
 * Make My Site Agent-Ready — Link header self-check.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether the homepage's Link headers survive this site's page cache.
 *
 * The plugin sends its discovery headers (api-catalog, service-desc, auth.md, llms.txt and the
 * rest) from PHP, on `template_redirect`. A page cache that saves only the HTML to a file and
 * serves that file without running WordPress (WP Rocket, WP Fastest Cache, Cache Enabler and
 * similar) does not save response headers, so only the first visitor after the cache is cleared
 * gets them. Reported with a careful reproduction in GitHub issue #1 (2026-10-06): the first
 * request after a clear had the headers, the next two did not, and a URL with a query string,
 * which WP Rocket does not cache, had them again. Caches that keep headers with the page
 * (Cloudflare, Varnish, LiteSpeed's server cache) are not affected.
 *
 * This repeats that reproduction from the site itself: one cache-busted request, which reaches
 * WordPress, then the plain homepage twice. The first plain request may be the one that fills the
 * cache, so the second is the one judged.
 *
 * Writing the headers into `.htaccess` was considered and declined (Miriam, 2026-11-03): it does
 * nothing on nginx, one file is shared by every site of a multisite, it cannot express the
 * per-page links, and a bad write takes the whole site down. So when the headers are dropped,
 * this says so and gives the owner the lines to add at server level instead.
 *
 * The plain requests are the page every visitor already gets, so unlike the negotiation check
 * there is nothing a probe could leave behind in a cache. The requests carry a token so the page
 * views they cause are kept out of the agent log.
 */
class MMSAR_Link_Check {

	/**
	 * Query argument used to cache-bust the control request.
	 */
	const ARG = 'mmsar_link_check';

	/**
	 * Option holding the last result.
	 */
	const OPTION = 'mmsar_link_check';

	/**
	 * Transient holding the token the check's own requests carry while it runs.
	 */
	const TOKEN = 'mmsar_link_check_token';

	/**
	 * Request header carrying that token.
	 */
	const HEADER = 'X-MMSAR-Link-Check';

	/**
	 * Transient set when settings are saved, so the check runs once the new settings are live.
	 */
	const PENDING = 'mmsar_link_check_pending';

	/**
	 * How old a stored result may be before Site Health runs the check again.
	 */
	const MAX_AGE = 604800; // A week, written out so the class loads before WordPress's time constants.

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_mmsar_link_check', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_pending' ), 20 );
		add_filter( 'site_status_tests', array( __CLASS__, 'add_site_health_test' ) );
	}

	/**
	 * Flags that a check should run on the next admin request, after a settings save.
	 *
	 * Switching a feature on or off changes which headers are sent, so the stored result may no
	 * longer describe the site. The saved options are not live yet when the sanitizer runs, which
	 * is why the run waits for the next request, as the negotiation check's does.
	 *
	 * @return void
	 */
	public static function schedule() {
		set_transient( self::PENDING, 1, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Runs a flagged check.
	 *
	 * @return void
	 */
	public static function maybe_run_pending() {
		if ( ! get_transient( self::PENDING ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_transient( self::PENDING );
		self::run();
	}

	/**
	 * Handles the "Run the check" button.
	 *
	 * @return void
	 */
	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_GET['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['mmsar_nonce'] ) ), 'mmsar_link_check' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}
		self::run();
		wp_safe_redirect(
			add_query_arg( 'page', 'make-my-site-agent-ready', admin_url( 'options-general.php' ) )
			. '#mmsar-section-link-check'
		);
		exit;
	}

	/**
	 * The nonce-protected URL that runs the check.
	 *
	 * @return string
	 */
	public static function run_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'mmsar_link_check', admin_url( 'admin-post.php' ) ),
			'mmsar_link_check',
			'mmsar_nonce'
		);
	}

	/**
	 * The last stored result, or an empty array if the check has never run.
	 *
	 * @return array
	 */
	public static function get_result() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether the current request is one of this check's own, so the agent log can skip it.
	 *
	 * A token rather than a user-agent: a user-agent anyone can send, and a visitor who sent this
	 * one would vanish from the log. The token exists only while a check is running.
	 *
	 * @return bool
	 */
	public static function is_own_request() {
		$sent = isset( $_SERVER['HTTP_X_MMSAR_LINK_CHECK'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MMSAR_LINK_CHECK'] ) ) : '';
		if ( '' === $sent ) {
			return false;
		}
		$token = (string) get_transient( self::TOKEN );
		return '' !== $token && hash_equals( $token, $sent );
	}

	/**
	 * Performs the three requests and stores what came back.
	 *
	 * @return array Result: status, links, details, time.
	 */
	public static function run() {
		$home  = home_url( '/' );
		$token = wp_generate_password( 20, false );
		set_transient( self::TOKEN, $token, MINUTE_IN_SECONDS * 2 );

		$control = self::request( add_query_arg( self::ARG, wp_generate_password( 12, false ), $home ), $token );
		$first   = self::request( $home, $token );
		$second  = self::request( $home, $token );
		delete_transient( self::TOKEN );

		foreach ( array( $control, $first, $second ) as $response ) {
			if ( is_wp_error( $response ) ) {
				return self::store(
					array(
						'status' => 'error',
						'links'  => array(),
						'error'  => $response->get_error_message(),
					)
				);
			}
		}

		$result = self::evaluate(
			self::parse_links( self::header( $control, 'link' ) ),
			self::parse_links( self::header( $second, 'link' ) )
		);

		$result['details'] = array(
			'control' => self::cache_signal( $control ),
			'plain'   => self::cache_signal( $second ),
		);
		return self::store( $result );
	}

	/**
	 * What the two responses mean.
	 *
	 * Pure, so every outcome is tested without a network. Only the links that are the same on every
	 * page are judged and offered as lines to add: a page's own Markdown and JSON-LD alternates
	 * change from page to page, so no server rule could carry them.
	 *
	 * @param string[] $control Links on the request that reached WordPress.
	 * @param string[] $plain   Links on the plain homepage, possibly from a cache.
	 * @return array{status: string, links: string[], missing: string[]}
	 */
	public static function evaluate( array $control, array $plain ) {
		$sitewide = array_values( array_filter( $control, array( __CLASS__, 'is_sitewide' ) ) );

		if ( ! $sitewide ) {
			return array(
				'status'  => 'none',
				'links'   => array(),
				'missing' => array(),
			);
		}

		$plain_targets = array_map( array( __CLASS__, 'target' ), $plain );
		$missing       = array();
		foreach ( $sitewide as $link ) {
			if ( ! in_array( self::target( $link ), $plain_targets, true ) ) {
				$missing[] = $link;
			}
		}

		if ( ! $missing ) {
			$status = 'pass';
		} elseif ( count( $missing ) === count( $sitewide ) ) {
			$status = 'fail';
		} else {
			$status = 'partial';
		}
		return array(
			'status'  => $status,
			'links'   => $sitewide,
			'missing' => $missing,
		);
	}

	/**
	 * Splits a Link header value into its links, each normalised to `<url>; params`.
	 *
	 * Commas separate links, and a URL may itself contain a comma, so the split is on the angle
	 * brackets rather than on commas.
	 *
	 * @param string $value Header value, several Link headers already joined with commas.
	 * @return string[]
	 */
	public static function parse_links( $value ) {
		$links = array();
		if ( ! preg_match_all( '/<([^>]*)>((?:\s*;\s*[^;,<]+)*)/', (string) $value, $m, PREG_SET_ORDER ) ) {
			return $links;
		}
		foreach ( $m as $match ) {
			$params  = trim( preg_replace( '/\s*;\s*/', '; ', $match[2] ) );
			$links[] = '<' . trim( $match[1] ) . '>' . ( '' !== $params ? ( 0 === strpos( $params, ';' ) ? $params : '; ' . $params ) : '' );
		}
		return array_values( array_unique( $links ) );
	}

	/**
	 * Whether a link is the same on every page, and so can be added by a server rule.
	 *
	 * @param string $link Normalised link.
	 * @return bool
	 */
	public static function is_sitewide( $link ) {
		// `shortlink` too (1.73.0): core's `?p=<id>` link is per page, so a server line gave every
		// page the homepage's shortlink and a post ended up with two (Henk van den Bor, issue #1).
		// Core's `rel="https://api.w.org/"` link to wp-json/ is the same on every page and stays.
		return ! preg_match( '/;\s*rel="?(alternate|canonical|shortlink)"?/i', $link );
	}

	/**
	 * The URL and relation of a link, which is what has to match: a cache may reorder parameters.
	 *
	 * @param string $link Normalised link.
	 * @return string
	 */
	private static function target( $link ) {
		$url = preg_match( '/^<([^>]*)>/', $link, $u ) ? $u[1] : $link;
		$rel = preg_match( '/rel="?([^";]+)"?/i', $link, $r ) ? strtolower( $r[1] ) : '';
		return $url . ' ' . $rel;
	}

	/**
	 * Lines to add the links at server level, for Apache, nginx and Cloudflare.
	 *
	 * Apache uses `Header always merge` (1.73.0). `merge` leaves a value alone when the response
	 * already carries it, but only within the header table it acts on. Plain `Header` acts on the
	 * `onsuccess` table, and on PHP-FPM, which is how most hosts run PHP, the headers PHP sends
	 * reach Apache in the `always` table, so plain `merge` never saw them and every page WordPress
	 * built itself carried each link twice. Henk van den Bor measured that on local and live
	 * (issue #1, 2026-11-08), and `always merge` fixed it. Under mod_php, PHP's headers sit in the
	 * `onsuccess` table instead, so there `always merge` may add the links a second time. That is
	 * untested, and harmless: the same cost nginx has. nginx has no merge; there a page WordPress
	 * builds gets the links twice.
	 *
	 * On a multisite network the lines are scoped to this site (1.73.0, also from issue #1), because
	 * every site in a network shares one .htaccess and often one nginx server block. See
	 * apache_condition() and network_scope().
	 *
	 * @param string[] $links Normalised links.
	 * @param array    $scope This site's place in a network, from network_scope(). Empty or
	 *                        `multisite` false for a single site.
	 * @return array{apache: string, nginx: string, cloudflare: string, scope: string}
	 */
	public static function snippets( array $links, array $scope = array() ) {
		$condition = self::apache_condition( $scope );
		$indent    = '' === $condition ? "\t" : "\t\t";

		$apache = array( '# BEGIN Make My Site Agent-Ready Link headers', '<IfModule mod_headers.c>' );
		if ( '' !== $condition ) {
			$apache[] = "\t<If \"" . $condition . '">';
		}
		$nginx = array( '# Make My Site Agent-Ready Link headers, inside the server block' );
		if ( ! empty( $scope['multisite'] ) ) {
			$nginx = array( '# Make My Site Agent-Ready Link headers for ' . self::scope_label( $scope ) . ' only.' );
			if ( '/' !== self::scope_path( $scope ) ) {
				$nginx[] = '# Inside a `location ' . self::scope_path( $scope ) . '` block in the network\'s server block.';
				$nginx[] = '# add_header in a location replaces any add_header lines it would inherit.';
			} elseif ( ! empty( $scope['other_paths'] ) ) {
				$nginx[] = '# Inside this site\'s server block, but the other sites on this host share it:';
				$nginx[] = '# nginx cannot leave them out cleanly, so use the Apache or Cloudflare lines.';
			} else {
				$nginx[] = '# Inside the server block for ' . (string) $scope['host'] . ', not the network\'s other sites.';
			}
		}
		foreach ( $links as $link ) {
			$apache[] = $indent . 'Header always merge Link "' . str_replace( '"', '\"', $link ) . '"';
			$nginx[]  = "add_header Link '" . str_replace( "'", '', $link ) . "' always;";
		}
		if ( '' !== $condition ) {
			$apache[] = "\t</If>";
		}
		$apache[] = '</IfModule>';
		$apache[] = '# END Make My Site Agent-Ready Link headers';
		return array(
			'apache'     => implode( "\n", $apache ),
			'nginx'      => implode( "\n", $nginx ),
			'cloudflare' => implode( ', ', $links ),
			'scope'      => empty( $scope['multisite'] ) ? '' : self::scope_label( $scope ),
		);
	}

	/**
	 * The Apache expression that limits the lines to this site, or '' outside a network.
	 *
	 * Always the host. On a subdirectory network, where sites share a host, also the path: under
	 * this site's path for a site that has one, and outside every other site's path for the site at
	 * the root, which would otherwise hand its links to all the others. Matches what Henk van den Bor
	 * ran on his network: one `<If "%{HTTP_HOST} == '…'">` inside `<IfModule mod_headers.c>`.
	 *
	 * @param array $scope From network_scope().
	 * @return string
	 */
	public static function apache_condition( array $scope ) {
		if ( empty( $scope['multisite'] ) || empty( $scope['host'] ) ) {
			return '';
		}
		$parts = array( "%{HTTP_HOST} == '" . str_replace( array( "'", '"' ), '', (string) $scope['host'] ) . "'" );
		$path  = self::scope_path( $scope );
		if ( '/' !== $path ) {
			$parts[] = '%{REQUEST_URI} =~ m#^' . self::path_pattern( $path ) . '(/|$)#';
		} elseif ( ! empty( $scope['other_paths'] ) ) {
			$alternatives = array_map( array( __CLASS__, 'path_pattern' ), (array) $scope['other_paths'] );
			$parts[]      = '%{REQUEST_URI} !~ m#^(' . implode( '|', $alternatives ) . ')(/|$)#';
		}
		return implode( ' && ', $parts );
	}

	/**
	 * A site path without its trailing slash, escaped for an Apache regular expression.
	 *
	 * @param string $path Path such as `/site2/`.
	 * @return string
	 */
	private static function path_pattern( $path ) {
		return preg_quote( rtrim( (string) $path, '/' ), '#' );
	}

	/**
	 * This site's path in a scope, `/` when it has none.
	 *
	 * @param array $scope From network_scope().
	 * @return string
	 */
	private static function scope_path( array $scope ) {
		$path = isset( $scope['path'] ) ? '/' . trim( (string) $scope['path'], '/' ) . '/' : '/';
		return '//' === $path ? '/' : $path;
	}

	/**
	 * This site as host plus path, for the notes beside the lines.
	 *
	 * @param array $scope From network_scope().
	 * @return string
	 */
	private static function scope_label( array $scope ) {
		$path = self::scope_path( $scope );
		return (string) $scope['host'] . ( '/' === $path ? '' : rtrim( $path, '/' ) );
	}

	/**
	 * Where this site sits in its network, for snippets().
	 *
	 * The host and path come from the home URL, so a site mapped to its own domain is scoped by
	 * that domain. `other_paths` lists the paths of the other sites that share this host, which is
	 * only ever non-empty on a subdirectory network; up to 1,000 are read.
	 *
	 * @return array{multisite: bool, host: string, path: string, other_paths: string[]}
	 */
	public static function network_scope() {
		$home  = wp_parse_url( home_url( '/' ) );
		$host  = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' ) : '';
		$path  = isset( $home['path'] ) ? (string) $home['path'] : '/';
		$scope = array(
			'multisite'   => is_multisite(),
			'host'        => $host,
			'path'        => $path,
			'other_paths' => array(),
		);
		if ( ! $scope['multisite'] || '' === $host ) {
			return $scope;
		}

		$own = '/' . trim( $path, '/' ) . '/';
		foreach ( get_sites(
			array(
				'number'   => 1000,
				'domain'   => (string) $home['host'],
				'archived' => 0,
				'deleted'  => 0,
			)
		) as $site ) {
			$other = '/' . trim( (string) $site->path, '/' ) . '/';
			if ( '//' !== $other && $other !== $own ) {
				$scope['other_paths'][] = $other;
			}
		}
		$scope['other_paths'] = array_values( array_unique( $scope['other_paths'] ) );
		return $scope;
	}

	/**
	 * One probe request, as a browser would ask for the page.
	 *
	 * @param string $url   URL to fetch.
	 * @param string $token Token marking the request as this check's.
	 * @return array|WP_Error
	 */
	private static function request( $url, $token ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'headers'     => array(
					'Accept'     => MMSAR_Negotiation_Check::ACCEPT_BROWSER,
					self::HEADER => $token,
				),
				'user-agent'  => 'Make My Site Agent-Ready self-check',
			)
		);
	}

	/**
	 * A response header as a string, several values joined with commas.
	 *
	 * @param array  $response Response.
	 * @param string $name     Header name.
	 * @return string
	 */
	private static function header( $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = implode( ', ', $value );
		}
		return (string) $value;
	}

	/**
	 * Whatever the response says about caching, verbatim, for the owner or their host to read.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function cache_signal( $response ) {
		$parts = array( 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
		foreach ( array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-wp-rocket', 'age' ) as $name ) {
			$value = self::header( $response, $name );
			if ( '' !== $value ) {
				$parts[] = $name . ': ' . $value;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Stores and returns a result.
	 *
	 * @param array $result Result without a time.
	 * @return array
	 */
	private static function store( array $result ) {
		$result['time'] = time();
		update_option( self::OPTION, $result, false );
		return $result;
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
		$tests['direct']['mmsar_link_headers'] = array(
			'label' => __( 'Agent discovery Link headers', 'make-my-site-agent-ready' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * The Site Health result, from the stored check, run again when it is more than a week old.
	 *
	 * Site Health is where an owner who has never opened this plugin's settings will see the
	 * problem, which is the point: nobody goes looking for headers they don't know are missing.
	 * WordPress runs this test on the Site Health screen and in its own weekly check; the plugin
	 * schedules nothing.
	 *
	 * @return array
	 */
	public static function site_health_test() {
		$result = self::get_result();
		if ( ! $result || empty( $result['time'] ) || time() - (int) $result['time'] > self::MAX_AGE ) {
			$result = self::run();
		}

		$out = array(
			'label'       => __( 'Agents can find this site\'s discovery files from its headers', 'make-my-site-agent-ready' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Agent-Ready', 'make-my-site-agent-ready' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The homepage\'s Link headers, which point agents to files like the API catalog and llms.txt, reach visitors whether or not the page comes from a cache.', 'make-my-site-agent-ready' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready#mmsar-section-link-check' ) ) . '">' . esc_html__( 'Link header check', 'make-my-site-agent-ready' ) . '</a></p>',
			'test'        => 'mmsar_link_headers',
		);

		$status = isset( $result['status'] ) ? $result['status'] : 'error';
		if ( 'fail' === $status || 'partial' === $status ) {
			$out['status']      = 'recommended';
			$out['label']       = __( 'Your page cache drops the Link headers agents use to find this site\'s files', 'make-my-site-agent-ready' );
			$out['description'] = '<p>' . esc_html__( 'When the homepage comes from your page cache, the Link headers this plugin sends are missing, so agents and readiness checkers that look for them find nothing. The settings page has the lines to add them at server level.', 'make-my-site-agent-ready' ) . '</p>';
		} elseif ( 'error' === $status ) {
			$out['label']       = __( 'The Link header check could not reach this site', 'make-my-site-agent-ready' );
			$out['description'] = '<p>' . esc_html__( 'The site could not request its own homepage, which is usually a host blocking requests a site makes to itself. This says nothing either way about the headers.', 'make-my-site-agent-ready' ) . '</p>';
		} elseif ( 'none' === $status ) {
			$out['label']       = __( 'No agent discovery Link headers to check', 'make-my-site-agent-ready' );
			$out['description'] = '<p>' . esc_html__( 'The homepage sends none of this plugin\'s discovery Link headers, so there is nothing for a cache to drop.', 'make-my-site-agent-ready' ) . '</p>';
		}
		return $out;
	}
}
