<?php
/**
 * A JSON-LD mirror of each page at its URL plus `.jsonld`, advertised with `rel="alternate"`.
 *
 * This is the part of specification.website's Schemamap proposal that rests on registered
 * standards: the `application/ld+json` media type and the `alternate` link relation. The rest of
 * the proposal, a `/schemamap.xml` index of `<resource>` entries and a `rel="schemamap"` link, is
 * deliberately not built. Yoast SEO serves `/schemamap.xml` itself when its schema aggregation is on,
 * and `schemamap` is not a registered relation. See decisions-log *Schemamap: per-page .jsonld with
 * rel="alternate" only…*.
 *
 * The body is the graph the page itself embeds, built the same way in the same request shape: the
 * main query is pointed at the post and Yoast is asked for the current page's schema, so the plugin's
 * own injection (MMSAR_Structured_Data) runs into it exactly as it does on the page. The proposal's
 * main warning is drift between the page's graph and the endpoint's; generating both from one path
 * is the defence.
 *
 * Spec: https://specification.website/spec/agent-readiness/schemamap/
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR JSON-LD mirror.
 */
class MMSAR_JSONLD {

	/**
	 * The URL suffix, without the dot.
	 */
	const SUFFIX = 'jsonld';

	/**
	 * Rewrite pattern. Excludes `/.well-known/` and the OKF tree the same way the `.md` catch-all
	 * does, so the plugin's own documents are never shadowed.
	 */
	const REWRITE_PATTERN = '^(?!\.well-known/|okf/)(.+)\.jsonld/?$';

	/**
	 * Agent-log surface for a served graph.
	 */
	const SURFACE = 'JSON-LD (.jsonld URL)';

	/**
	 * Agent-log surface for a miss. Starts with "404" so it lands in the Not found category.
	 */
	const SURFACE_404 = '404 (JSON-LD)';

	/**
	 * Init. Called only while the `jsonld` feature is on.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_redirect' ) );
		add_action( 'wp_head', array( __CLASS__, 'render_head_link' ) );
	}

	/**
	 * Add the rewrite rule.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( self::REWRITE_PATTERN, 'index.php?mmsar_jsonld_path=$matches[1]', 'top' );
	}

	/**
	 * Add the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_jsonld_path';
		return $vars;
	}

	/**
	 * Keep WordPress's canonical redirect off `.jsonld` requests, as the `.md` mirror does.
	 *
	 * @param string $redirect_url Proposed redirect.
	 * @return string|false
	 */
	public static function prevent_redirect( $redirect_url ) {
		if ( '' !== (string) get_query_var( 'mmsar_jsonld_path' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * The `.jsonld` address for a permalink. `index` stands for the front page, as in `index.md`.
	 *
	 * @param string $permalink     The post's permalink.
	 * @param bool   $is_front_page Whether the post is the static front page.
	 * @param string $home          The site's home URL.
	 * @return string
	 */
	public static function url_for_permalink( $permalink, $is_front_page, $home ) {
		if ( $is_front_page ) {
			return rtrim( $home, '/' ) . '/index.' . self::SUFFIX;
		}
		return rtrim( $permalink, '/' ) . '.' . self::SUFFIX;
	}

	/**
	 * Whether a post may be served at all: published, no password, a covered post type.
	 *
	 * The same three tests the `.md` mirror applies. Noindex is deliberately not one of them: like
	 * `.md`, this address serves one post, it does not list it.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function post_is_servable( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& in_array( $post->post_type, mmsar_get_enabled_post_types(), true );
	}

	/**
	 * Whether anything on this site produces JSON-LD for a page: Yoast's graph, or the plugin's own
	 * opt-in node. When neither does, the page embeds no graph, so there is nothing to mirror and
	 * nothing is advertised.
	 *
	 * @return bool
	 */
	public static function has_source() {
		return self::yoast_schema_on() || ( mmsar_feature_enabled( 'markdown' ) && MMSAR_Structured_Data::is_enabled() );
	}

	/**
	 * Whether Yoast SEO is active and its schema output has not been switched off. Read the way
	 * Yoast's own presenter reads it.
	 *
	 * @return bool
	 */
	private static function yoast_schema_on() {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}
		$probe  = array( '_deprecated' => 'Please use the "wpseo_schema_*" filters to extend the Yoast SEO schema data - see the WPSEO_Schema class.' );
		$return = apply_filters( 'wpseo_json_ld_output', $probe, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO's own filter, read so this mirror goes quiet whenever Yoast's page output does.
		return array() !== $return && false !== $return;
	}

	/**
	 * The `.jsonld` URL for the current front-end request, or '' when there is none to advertise.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( ! self::has_source() ) {
			return '';
		}
		$is_front = is_front_page() && get_option( 'page_on_front' );
		if ( ! $is_front && ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! self::post_is_servable( $post instanceof WP_Post ? $post : null ) ) {
			return '';
		}
		return self::url_for_permalink( (string) get_permalink( $post ), (bool) $is_front, home_url( '/' ) );
	}

	/**
	 * The `<link rel="alternate" type="application/ld+json">` tag for a URL, or '' for none.
	 *
	 * @param string $url The `.jsonld` URL.
	 * @return string
	 */
	public static function head_link( $url ) {
		if ( '' === $url ) {
			return '';
		}
		return '<link rel="alternate" type="application/ld+json" href="' . esc_url( $url ) . '">' . "\n";
	}

	/**
	 * The matching `Link` header line for a URL, or '' for none.
	 *
	 * @param string $url The `.jsonld` URL.
	 * @return string
	 */
	public static function link_header( $url ) {
		if ( '' === $url ) {
			return '';
		}
		return 'Link: <' . esc_url_raw( $url ) . '>; rel="alternate"; type="application/ld+json"';
	}

	/**
	 * Prints the head link on a page that has a `.jsonld` mirror.
	 *
	 * @return void
	 */
	public static function render_head_link() {
		echo wp_kses(
			self::head_link( self::current_url() ),
			array(
				'link' => array(
					'rel'  => true,
					'type' => true,
					'href' => true,
				),
			)
		);
	}

	/**
	 * Sends the `Link` header on a page that has a `.jsonld` mirror. Called from
	 * mmsar_send_link_headers() while the feature is on.
	 *
	 * @return void
	 */
	public static function send_link_header() {
		$header = self::link_header( self::current_url() );
		if ( '' !== $header ) {
			header( $header, false );
		}
	}

	/**
	 * One JSON-LD document from Yoast's schema and the plugin's own node, either of which may be
	 * missing. When the page embeds both (Yoast gave this type no piece to inject into, so the
	 * plugin printed its own block), the node joins Yoast's `@graph` so the endpoint still returns
	 * one document carrying everything the page carries.
	 *
	 * @param mixed      $yoast Yoast's schema for the page: an array with `@context` and `@graph`, or anything else for none.
	 * @param array|null $node  The plugin's standalone node, or null.
	 * @return array|null The document, or null when there is nothing.
	 */
	public static function document( $yoast, $node ) {
		$has_yoast = is_array( $yoast ) && ! empty( $yoast['@graph'] ) && is_array( $yoast['@graph'] );
		if ( ! $has_yoast ) {
			return is_array( $node ) && $node ? $node : null;
		}
		if ( is_array( $node ) && $node ) {
			unset( $node['@context'] );
			$yoast['@graph'][] = $node;
		}
		return $yoast;
	}

	/**
	 * Serves `/<path>.jsonld`.
	 *
	 * @return void
	 */
	public static function serve() {
		$path = (string) get_query_var( 'mmsar_jsonld_path' );
		if ( '' === $path ) {
			return;
		}

		$post_id = MMSAR_Server::resolve_path( $path );
		if ( ! $post_id ) {
			self::maybe_redirect_renamed( $path );
			self::fail( 404, __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			self::fail( 404, __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}
		if ( '' !== (string) $post->post_password ) {
			self::fail( 403, __( 'This content is password protected.', 'make-my-site-agent-ready' ) );
		}
		if ( ! self::post_is_servable( $post ) ) {
			self::fail( 404, __( 'That page exists, but this site does not publish JSON-LD for its content type. Fetch the HTML page instead.', 'make-my-site-agent-ready' ) );
		}

		$document = self::document_for( $post );
		if ( null === $document ) {
			self::fail( 404, __( 'That page exists but carries no structured data.', 'make-my-site-agent-ready' ) );
		}

		header( 'Content-Type: application/ld+json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		mmsar_send_cache_headers();
		MMSAR_Agent_Log::record( self::SURFACE, MMSAR_Agent_Log::request_path( get_permalink( $post ) ), true );
		header( 'Link: <' . esc_url_raw( (string) get_permalink( $post ) ) . '>; rel="canonical"', false );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 200 );

		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Builds the page's graph the way the page itself does.
	 *
	 * The main query is pointed at the post, so `is_singular()`, `get_the_ID()` and Yoast's
	 * current-page context all describe it, exactly as on the HTML request. Yoast's
	 * `for_current_page()` then runs its `wpseo_schema_*` filters, which is where the plugin's own
	 * `encoding` pointer is injected; afterwards fallback_node() adds the standalone node only if
	 * the page would print one. The request ends straight after, so the swapped query never reaches
	 * a template.
	 *
	 * @param WP_Post $post The post.
	 * @return array|null
	 */
	private static function document_for( $post ) {
		$args  = ( 'page' === $post->post_type )
			? array( 'page_id' => $post->ID )
			: array(
				'p'         => $post->ID,
				'post_type' => $post->post_type,
			);
		$query = new WP_Query( $args );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- See the docblock: the page's own context is rebuilt so its graph is generated the same way.
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['post']         = $post;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$yoast = null;
		if ( self::yoast_schema_on() && function_exists( 'YoastSEO' ) ) {
			// Guarded like MMSAR_Converter::seo_description(): a Yoast failure means no Yoast graph,
			// never a fatal on a URL an agent fetched.
			try {
				$meta  = YoastSEO()->meta->for_current_page();
				$yoast = $meta ? $meta->schema : null;
			} catch ( Throwable $e ) {
				$yoast = null;
			}
		}

		// The plugin's node only exists while markdown URLs are on (MMSAR_Structured_Data is
		// initialised with them), so the same gate applies here.
		$node = mmsar_feature_enabled( 'markdown' ) ? MMSAR_Structured_Data::fallback_node() : null;

		return self::document( $yoast, $node );
	}

	/**
	 * Redirects a renamed post's old `.jsonld` address, as the `.md` mirror does. Returns when there
	 * is nowhere to send it.
	 *
	 * @param string $path Requested path, without the suffix.
	 * @return void
	 */
	private static function maybe_redirect_renamed( $path ) {
		$post_id = MMSAR_Server::resolve_renamed_post_id( $path );
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! self::post_is_servable( $post instanceof WP_Post ? $post : null ) ) {
			return;
		}
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}
		$target = self::url_for_permalink( $permalink, false, home_url( '/' ) );

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = '' === $request_uri ? '' : (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( (string) wp_parse_url( $target, PHP_URL_PATH ) === $request_path ) {
			return;
		}

		MMSAR_Agent_Log::record( 'JSON-LD (redirect)', MMSAR_Agent_Log::request_path() );
		wp_safe_redirect( $target, 301, 'Make My Site Agent-Ready' );
		exit;
	}

	/**
	 * Ends the request with a JSON error. Does not return.
	 *
	 * @param int    $status HTTP status.
	 * @param string $reason One sentence.
	 * @return void
	 */
	private static function fail( $status, $reason ) {
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// The path is caller-supplied, so it stays out of the throttle key, as on the other 404s.
		if ( 404 === $status ) {
			MMSAR_Agent_Log::record( self::SURFACE_404, MMSAR_Agent_Log::request_path() );
		}
		MMSAR_LLMs_Txt::send_link_header();
		status_header( $status );
		echo wp_json_encode(
			array(
				'error'  => array(
					'code'    => $status,
					'message' => $reason,
				),
				'how_to' => 'Take a page URL on this site, drop any trailing slash, and add .jsonld.',
			),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
		exit;
	}
}
