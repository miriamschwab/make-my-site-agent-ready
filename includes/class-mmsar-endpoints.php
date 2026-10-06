<?php
/**
 * Additional virtual endpoints: /llms-full.txt and /.well-known/security.txt
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Endpoints handler.
 */
class MMSAR_Endpoints {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			add_rewrite_rule(
				'^llms-full\.txt$',
				'index.php?mmsar_llms_full_txt=1',
				'top'
			);
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			add_rewrite_rule(
				'^\.well-known/security\.txt$',
				'index.php?mmsar_security_txt=1',
				'top'
			);
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			add_rewrite_rule(
				'^\.well-known/api-catalog$',
				'index.php?mmsar_api_catalog=1',
				'top'
			);
		}
		// Route robots.txt through WordPress so the robots_txt filter (and our AI rules) always fire,
		// even if a physical robots.txt file exists in the webroot. This rule is what overrides a
		// static file, so it must not be registered when the robots.txt feature is off — otherwise
		// opting out would still hijack a hand-maintained robots.txt, just without adding anything.
		if ( mmsar_feature_enabled( 'robots_txt' ) ) {
			add_rewrite_rule(
				'^robots\.txt$',
				'index.php?robots=1',
				'top'
			);
		}
	}

	/**
	 * Add query vars.
	 *
	 * @param mixed $vars Vars.
	 * @return mixed Result.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_llms_full_txt';
		$vars[] = 'mmsar_security_txt';
		$vars[] = 'mmsar_api_catalog';
		return $vars;
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( get_query_var( 'mmsar_llms_full_txt' ) ) {
			self::serve_llms_full_txt();
		}
		if ( get_query_var( 'mmsar_security_txt' ) ) {
			self::serve_security_txt();
		}
		if ( get_query_var( 'mmsar_api_catalog' ) ) {
			self::serve_api_catalog();
		}
	}

	// -------------------------------------------------------------------------
	// /llms-full.txt
	// -------------------------------------------------------------------------

	/**
	 * Serve llms full txt.
	 *
	 * @return void
	 */
	private static function serve_llms_full_txt() {
		$content = get_transient( 'mmsar_llms_full_txt' );
		if ( false === $content ) {
			$content = self::generate_llms_full_txt();
			set_transient( 'mmsar_llms_full_txt', $content, DAY_IN_SECONDS );
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'llms-full.txt' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain, not HTML.
		echo $content;
		exit;
	}

	/**
	 * Generate llms full txt.
	 *
	 * @return mixed Result.
	 */
	private static function generate_llms_full_txt() {
		$post_types = mmsar_get_enabled_post_types();
		$posts      = get_posts(
			MMSAR_Noindex::exclude(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = array();
		$lines[] = '# ' . $site_name . ' — Full Content';
		$lines[] = '';
		$lines[] = '> Source: ' . home_url( '/llms-full.txt' );
		$lines[] = '> Generated: ' . gmdate( 'Y-m-d' );
		$lines[] = '';

		foreach ( $posts as $post ) {
			// Never expose password-protected content in the full-text dump. The per-page .md endpoint
			// returns 403 for these, so llms-full.txt must not become a side channel around that — a
			// post can also gain a password after its markdown was already cached in _llmmd_content.
			if ( ! empty( $post->post_password ) ) {
				continue;
			}
			$title    = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url      = get_permalink( $post );
			$markdown = get_post_meta( $post->ID, '_llmmd_content', true );

			if ( empty( $markdown ) ) {
				$markdown = MMSAR_Converter::convert_post( $post->ID );
				if ( ! empty( $markdown ) ) {
					mmsar_store_markdown( $post->ID, $markdown );
				}
			}

			if ( empty( $markdown ) ) {
				continue;
			}

			$lines[] = '---';
			$lines[] = '';
			$lines[] = '# ' . $title;
			$lines[] = '';
			$lines[] = 'URL: ' . $url;
			$lines[] = '';
			// The entry already opens with the title, so the one the converter puts in the body
			// would print it a second time a few lines down.
			$lines[] = trim( MMSAR_Converter::without_title_heading( $markdown, $post ) );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// /.well-known/security.txt
	// -------------------------------------------------------------------------

	/**
	 * Serve security txt.
	 *
	 * @return void
	 */
	private static function serve_security_txt() {
		$content = get_option( 'mmsar_security_txt', '' );
		if ( empty( trim( $content ) ) ) {
			$content = self::default_security_txt();
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'security.txt' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain, not HTML.
		echo $content;
		exit;
	}

	/**
	 * Normalizes whatever the user typed in the Contact field into a value security.txt accepts.
	 *
	 * RFC 9116 requires Contact to be a URI, so a bare path or a bare email address is not valid
	 * on its own. People reasonably type all three forms, so accept them all and expand:
	 *   https://example.com/contact  ->  used as-is
	 *   /contact  or  contact        ->  https://thissite.com/contact
	 *   security@example.com         ->  mailto:security@example.com
	 *
	 * @param string $contact The raw contact value the user entered.
	 * @return string A security.txt-valid Contact URI.
	 */
	public static function normalize_contact( $contact ) {
		$contact = trim( (string) $contact );
		if ( '' === $contact ) {
			return '';
		}
		// Already a URI with a safe scheme — trust it as-is. Only https, http, mailto and tel are
		// accepted; anything else (e.g. a javascript: URI a compromised admin might paste) is not
		// echoed into the published security.txt and instead falls through to the path handling below.
		if ( preg_match( '#^(https?|mailto|tel):#i', $contact ) ) {
			return $contact;
		}
		if ( is_email( $contact ) ) {
			return 'mailto:' . $contact;
		}
		return home_url( '/' . ltrim( $contact, '/' ) );
	}

	/**
	 * Default security txt.
	 *
	 * @return mixed Result.
	 */
	public static function default_security_txt() {
		$contact = self::normalize_contact( get_option( 'mmsar_security_txt_contact', '' ) );
		if ( '' === $contact ) {
			// No contact configured. Guessing a URL that probably 404s would publish a broken
			// security.txt, so fall back to the admin email, which always exists.
			$contact = 'mailto:' . get_option( 'admin_email' );
		}
		$expires = gmdate( 'Y-m-d\T00:00:00.000\Z', strtotime( '+1 year' ) );
		return "Contact: {$contact}\nExpires: {$expires}\nPreferred-Languages: en\n";
	}

	// -------------------------------------------------------------------------
	// /.well-known/api-catalog — RFC 9727, served as a Linkset (RFC 9264)
	// -------------------------------------------------------------------------

	/**
	 * Serve api catalog.
	 *
	 * @return void
	 */
	private static function serve_api_catalog() {
		// This document exists to tell an agent what it can fetch, so it must only list resources
		// that are actually being served — a catalog entry pointing at a switched-off endpoint
		// sends agents to a 404 and makes the whole catalog less trustworthy.
		$entry = array( 'anchor' => home_url( '/' ) );

		// The `type` here must match the Content-Type each endpoint actually sends: llms.txt and
		// llms-full.txt are both served as text/plain (see serve_llms_full_txt / serve_llms_txt),
		// so advertising text/markdown would misrepresent them to an agent reading the catalog.
		$describedby = array();
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$describedby[] = array(
				'href' => home_url( '/llms.txt' ),
				'type' => 'text/plain',
			);
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$describedby[] = array(
				'href' => home_url( '/llms-full.txt' ),
				'type' => 'text/plain',
			);
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			$describedby[] = array(
				'href' => home_url( '/.well-known/security.txt' ),
				'type' => 'text/plain',
			);
		}
		if ( $describedby ) {
			$entry['describedby'] = $describedby;
		}

		// service-desc is the relation for a machine-readable description of the service, and this
		// site can publish up to three of them, each aimed at a different kind of client: the Agent
		// Skills index for a model, OpenAPI for an HTTP client, and the MCP manifest for an agent
		// that would rather hold a connection than make requests. All three are listed under the
		// same relation because that is what each of them is; the `type` is what tells them apart.
		$service_desc = array();
		if ( mmsar_feature_enabled( 'agent_skills' ) ) {
			$service_desc[] = array(
				'href' => home_url( '/.well-known/agent-skills/index.json' ),
				'type' => 'application/json',
			);
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$service_desc[] = array(
				'href' => MMSAR_OpenAPI::url(),
				'type' => 'application/vnd.oai.openapi+json',
			);
		}
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$service_desc[] = array(
				'href' => home_url( '/.well-known/mcp.json' ),
				'type' => 'application/json',
			);
		}
		// The in-browser tools, described the same way (1.58.0). Same relation as the MCP manifest:
		// a machine-readable description of a service this site offers.
		if ( MMSAR_WebMCP::manifest_enabled() ) {
			$service_desc[] = array(
				'href' => home_url( '/.well-known/webmcp.json' ),
				'type' => 'application/json',
			);
		}
		if ( $service_desc ) {
			$entry['service-desc'] = $service_desc;
		}

		$items = array();
		// Same detection the robots.txt Sitemap directive uses, rather than assuming Yoast's filename.
		$sitemap_url = mmsar_get_sitemap_url();
		if ( $sitemap_url ) {
			$items[] = array(
				'href' => $sitemap_url,
				'type' => 'application/xml',
			);
		}
		$items[]       = array(
			'href' => home_url( '/feed/' ),
			'type' => 'application/rss+xml',
		);
		$entry['item'] = $items;

		// Endpoints other plugins registered, merged in under whichever relation each one asked for
		// rather than replacing ours — a site can have both our llms.txt and a plugin's own API
		// description under `describedby`.
		foreach ( MMSAR_Registry::api_catalog_links() as $rel => $links ) {
			$entry[ $rel ] = isset( $entry[ $rel ] ) ? array_merge( $entry[ $rel ], $links ) : $links;
		}

		$linkset = array( 'linkset' => array( $entry ) );

		/**
		 * Filters the complete api-catalog linkset before it is served.
		 *
		 * The escape hatch for anything the endpoint registry cannot express — extra linkset
		 * entries with their own anchor, say. Most integrations want mmsar_registered_endpoints
		 * instead, which also covers llms.txt and Agent Skills.
		 *
		 * @param array $linkset The RFC 9264 linkset document, as a PHP array.
		 */
		$linkset = apply_filters( 'mmsar_api_catalog_linkset', $linkset );
		if ( ! is_array( $linkset ) ) {
			$linkset = array( 'linkset' => array( $entry ) );
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/linkset+json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'api-catalog' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $linkset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
