<?php
/**
 * `?mode=agent` — a machine-readable view of any page on this site.
 *
 * A convention rather than a standard: several readiness scanners probe `?mode=agent` on the
 * homepage and expect something other than marketing HTML. It is worth honoring anyway, because the
 * underlying complaint is real. An agent that lands on a URL someone pasted has no way to ask for
 * the machine-readable version except by knowing this site's particular conventions — the `.md`
 * suffix, the Accept header, the MCP endpoint. A query parameter is the one lever available to a
 * client that can only follow a link.
 *
 * What comes back is Markdown, and for a content page it is the same Markdown the `.md` twin serves,
 * from the same cache. The homepage gets more: a summary of the site's agent-facing surface, so a
 * client that arrives at the root with `?mode=agent` learns what else is available in one request
 * rather than discovering the endpoints one at a time.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent view.
 */
class MMSAR_Agent_View {

	/**
	 * The query parameter and the value that triggers this.
	 */
	const PARAM = 'mode';
	const VALUE = 'agent';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 3: after the negotiated-markdown handler (1) and the 404 handler (2), so an
		// explicit `?mode=agent` never pre-empts either of those decisions on a URL they own.
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 3 );
	}

	/**
	 * Serve the agent view when asked for.
	 *
	 * @return void
	 */
	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector on a public page, not a state change.
		$mode = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( self::VALUE !== $mode ) {
			return;
		}
		if ( is_admin() || is_feed() || is_embed() || is_404() ) {
			return;
		}

		$markdown = self::body();
		if ( '' === $markdown ) {
			return;
		}

		// Never cached at the edge. This is the same URL a person can reach with the parameter
		// attached, and a shared cache that stored this response against the bare URL would hand
		// Markdown to readers.
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Access-Control-Allow-Origin: *' );
		MMSAR_Agent_Log::record( 'mode=agent' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * The body for the current request.
	 *
	 * @return string Markdown, or '' when this request has no agent view.
	 */
	private static function body() {
		if ( is_front_page() || is_home() ) {
			return self::site_overview();
		}

		if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return '';
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return '';
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				mmsar_store_markdown( $post->ID, $markdown );
			}
		}

		return is_string( $markdown ) ? $markdown : '';
	}

	/**
	 * The homepage's agent view: what this site is, and every machine-readable way into it.
	 *
	 * @return string Markdown.
	 */
	private static function site_overview() {
		$site_name   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = array();
		$lines[] = '# ' . $site_name . ' — agent view';
		$lines[] = '';
		if ( '' !== trim( $description ) ) {
			$lines[] = '> ' . $description;
			$lines[] = '';
		}
		$lines[] = 'URL: ' . home_url( '/' );
		$lines[] = '';
		$lines[] = 'This is the machine-readable view of the homepage. Every page on this site has one: '
			. 'add `?mode=agent` to its URL, or add `.md` to it, or send `Accept: text/markdown`.';
		$lines[] = '';

		$lines[] = '## Endpoints';
		$lines[] = '';
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$lines[] = '- **MCP server** (`' . MMSAR_MCP::endpoint_url() . '`) — Streamable HTTP, no auth, read-only. '
				. 'Tools: ' . implode( ', ', array_column( MMSAR_MCP::tools(), 'name' ) ) . '. Start here if you speak MCP.';
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$lines[] = '- **OpenAPI** (`' . MMSAR_OpenAPI::url() . '`) — every HTTP endpoint and its error shape.';
		}
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$lines[] = '- **llms.txt** (`' . home_url( '/llms.txt' ) . '`) — content index, and when this site is worth reading.';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$lines[] = '- **llms-full.txt** (`' . home_url( '/llms-full.txt' ) . '`) — the whole site in one document.';
		}
		$lines[] = '- **auth.md** (`' . MMSAR_Auth_Md::url() . '`) — how to authenticate. Short answer: you do not need to.';
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$lines[] = '- **api-catalog** (`' . home_url( '/.well-known/api-catalog' ) . '`) — every machine-readable document here.';
		}
		$sitemap = mmsar_get_sitemap_url();
		if ( $sitemap ) {
			$lines[] = '- **sitemap** (`' . $sitemap . '`) — every URL.';
		}
		$lines[] = '';

		$lines[] = '## Authentication';
		$lines[] = '';
		$lines[] = 'None required for anything you can read. See `' . MMSAR_Auth_Md::url() . '`.';
		$lines[] = '';

		// Anything an agent can actually call, as opposed to read.
		$callable = array();
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( empty( $endpoint['methods'] ) || array( 'GET' ) === $endpoint['methods'] ) {
				continue;
			}
			$callable[] = '- **' . $endpoint['title'] . '** — `' . implode( ', ', $endpoint['methods'] ) . ' ' . $endpoint['href'] . '`'
				. ( '' !== $endpoint['description'] ? "\n  " . $endpoint['description'] : '' )
				. ( '' !== $endpoint['auth'] ? "\n  Requires: " . $endpoint['auth'] : '' );
		}
		if ( $callable ) {
			$lines[] = '## Actions you can take';
			$lines[] = '';
			foreach ( $callable as $line ) {
				$lines[] = $line;
			}
			$lines[] = '';
		}

		$lines[] = '## Content';
		$lines[] = '';
		foreach ( mmsar_get_enabled_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			$counts = wp_count_posts( $post_type );
			if ( ! $object ) {
				continue;
			}
			$lines[] = '- ' . $object->labels->name . ': ' . ( isset( $counts->publish ) ? (int) $counts->publish : 0 ) . ' published';
		}

		/**
		 * Filters the homepage agent view.
		 *
		 * @param string $markdown The document.
		 */
		return (string) apply_filters( 'mmsar_agent_view_overview', implode( "\n", $lines ) . "\n" );
	}
}
