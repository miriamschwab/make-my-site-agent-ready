<?php
/**
 * Make My Site Agent-Ready — server component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Server handler.
 */
class MMSAR_Server {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_markdown' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_redirect' ) );

		// Content negotiation is a separate opt-in on top of the .md URLs, because it changes what
		// an existing URL returns rather than adding a new one. Registered only when switched on,
		// so a site that leaves it off never runs any of it.
		if ( mmsar_feature_enabled( 'markdown_negotiation' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_negotiated' ), 1 );
		}
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		// Negative lookahead excludes /.well-known/ paths and /auth.md — this broad catch-all is for
		// post/page .md URLs only, and would otherwise also match (and shadow) the plugin's own
		// markdown documents: /.well-known/agent-skills/*/SKILL.md and /auth.md. Relying on
		// registration order instead would be fragile, because every one of these is registered
		// 'top' and the winner is then whichever class happened to hook `init` last.
		add_rewrite_rule(
			'^(?!\.well-known/|auth\.md)(.+)\.md/?$',
			'index.php?llmmd_path=$matches[1]&llmmd_serve=1',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param mixed $vars Vars.
	 * @return mixed Result.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'llmmd_path';
		$vars[] = 'llmmd_serve';
		return $vars;
	}

	/**
	 * Prevent WordPress's canonical redirect from interfering with markdown-serving requests.
	 *
	 * @param string $redirect_url The redirect URL WordPress proposes.
	 * @return string|false The redirect URL, or false to cancel the redirect for our requests.
	 */
	public static function prevent_redirect( $redirect_url ) {
		if ( get_query_var( 'llmmd_serve' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Serve markdown.
	 *
	 * @return void
	 */
	public static function serve_markdown() {
		if ( ! get_query_var( 'llmmd_serve' ) ) {
			return;
		}

		$post_id = self::resolve_post_id();
		if ( ! $post_id ) {
			// The path resolves to nothing *now*. Before calling it a 404, ask whether it used to
			// resolve to something — a renamed post leaves its old address behind, and WordPress
			// already redirects the HTML one. See maybe_redirect_renamed(); it exits when it finds
			// a target and returns when it does not.
			self::maybe_redirect_renamed();
			self::not_found( __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}

		$post = get_post( $post_id );

		// Defense in depth: only ever serve markdown for a published post. resolve_post_id() can reach
		// a post through url_to_postid() or get_page_by_path() (which returns any status), so a draft,
		// pending, private or trashed post could otherwise slip through even though its markdown is
		// never cached. Treat anything not published as not found.
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::not_found( __( 'There is no published page at this path.', 'make-my-site-agent-ready' ) );
		}

		if ( ! empty( $post->post_password ) ) {
			status_header( 403 );
			echo '# 403 Forbidden' . "\n\n";
			echo esc_html__( 'This content is password protected.', 'make-my-site-agent-ready' ) . "\n";
			exit;
		}

		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			self::not_found( __( 'That page exists, but this site does not publish Markdown for its content type. Fetch the HTML page instead.', 'make-my-site-agent-ready' ) );
		}

		$markdown = get_post_meta( $post_id, '_llmmd_content', true );

		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post_id );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post_id, '_llmmd_content', $markdown );
			}
		}

		if ( empty( $markdown ) ) {
			self::not_found( __( 'That page exists but has no text content to return.', 'make-my-site-agent-ready' ) );
		}

		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// The resolved permalink path, not REQUEST_URI. Every alias that reached this post — the
		// `.md` suffix, content negotiation on the canonical URL, a trailing-slash variant — now
		// records one identical value, so a sweep shows up as one row per post rather than one row
		// per spelling. It is also drawn from the site's own published content rather than from the
		// caller, which is what makes it safe to key the throttle on; see record()'s docblock for
		// why a 404 path is not.
		MMSAR_Agent_Log::record( 'Markdown (.md URL)', MMSAR_Agent_Log::request_path( get_permalink( $post_id ) ), true );
		header( 'Link: <' . esc_url( get_permalink( $post_id ) ) . '>; rel="canonical"' );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * End the request with a Markdown 404 that says where to look instead.
	 *
	 * A client that asked for `/nothing-here.md` has already shown it prefers Markdown and is
	 * probably not a person, so the body is worth spending on recovery links rather than on the
	 * single sentence this used to return. The body comes from MMSAR_Not_Found so a missing `.md`
	 * URL and a missing HTML URL give an agent the same instructions.
	 *
	 * Does not return.
	 *
	 * @param string $reason One sentence on why there is nothing here.
	 * @return void
	 */
	private static function not_found( $reason ) {
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		// A missing `.md` URL was recorded nowhere at all until 1.24.0. 1.23.0 added path logging to
		// both branches of MMSAR_Not_Found, but this is a third branch: a `.md` request that
		// resolves to no published post exits here, before the general 404 handler ever runs. So the
		// one 404 an agent is most likely to generate against this plugin — guessing at a `.md`
		// address — was the one it could not see. The path is caller-supplied, so it is not part of
		// the throttle key, exactly as the other two 404 surfaces do it.
		MMSAR_Agent_Log::record( '404 (markdown)', MMSAR_Agent_Log::request_path() );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 404 );

		// The recovery links are the 404 feature's job, and it can be switched off. Fall back to the
		// bare message rather than to nothing, so this response is never empty.
		if ( mmsar_feature_enabled( 'agent_404' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
			echo MMSAR_Not_Found::body( '404 Not Found', $reason );
		} else {
			echo '# 404 Not Found' . "\n\n";
			echo esc_html( $reason ) . "\n";
		}
		exit;
	}

	/**
	 * Redirects a `.md` URL whose post has since been renamed, and does not return when it does.
	 *
	 * The bug this fixes is the mirror falling out of step with what it mirrors. Rename a post and
	 * WordPress keeps the old address working: it writes the old slug to `_wp_old_slug` post meta,
	 * and `wp_old_slug_redirect()` turns the old HTML URL into a 301 to the new one. That handler
	 * only ever runs on a WordPress 404, and a `.md` request is not one — it matched this plugin's
	 * own rewrite rule, so `is_404()` is false and core never looks. The old `.md` address was
	 * therefore the one part of a renamed post that stayed permanently broken, while every other
	 * spelling of the same page quietly kept working.
	 *
	 * That asymmetry is worse for an agent than a plain 404 would be, because this site tells
	 * agents in llms.txt, in SKILL.md and on every 404 body to take a page's URL and add `.md`. An
	 * agent holding a pre-rename URL — from its own index, or from a link in another post that was
	 * never updated — follows that instruction and lands on a hard 404, when the very same URL
	 * without the suffix would have redirected it to the right page. It is the plugin's own advice
	 * that turns a working redirect into a dead end, so it is the plugin's job to honour it.
	 *
	 * It reads the same `_wp_old_slug` meta core does and applies core's own
	 * `old_slug_redirect_post_id` filter, so anything already extending WordPress's slug redirect
	 * extends this too and the two surfaces cannot disagree about where a renamed post went. What
	 * it deliberately does not do is ask the site over HTTP where `/old-path/` actually ends up.
	 * That would additionally catch redirect plugins and server rules, and it would put a loopback
	 * request on the one code path a crawler guessing at addresses hits hardest — a self-inflicted
	 * request per bogus `.md` URL. `mmsar_md_redirect_post_id` is the extension point for those
	 * cases instead.
	 *
	 * Two limits are inherited from core on purpose, because matching the HTML is the whole point:
	 * only the last path segment is matched, so a renamed *parent* page is not followed (core does
	 * not follow it either), and `_wp_old_date` is not consulted, which matters only for permalink
	 * structures that carry a date.
	 *
	 * @return void
	 */
	private static function maybe_redirect_renamed() {
		$path = (string) get_query_var( 'llmmd_path' );
		if ( '' === $path ) {
			return;
		}

		$post_id = self::resolve_renamed_post_id( $path );
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		// The same published-and-enabled test serve_markdown() applies below. A renamed post that
		// has since been unpublished, or whose type no longer publishes markdown, has no markdown
		// address to send anyone to — redirecting to one would just move the 404 one hop away.
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}
		$target = rtrim( $permalink, '/' ) . '.md';

		// Never redirect a URL to itself. resolve_post_id() has already failed for this path, so
		// this should be unreachable; a filtered post id that resolves back to the requested
		// address would otherwise hand the client an endless loop.
		if ( self::request_url_path() === (string) wp_parse_url( $target, PHP_URL_PATH ) ) {
			return;
		}

		// The requested path, not the target: the point of the row is to show which retired
		// addresses agents are still holding, which is what says whether a stale link needs
		// chasing. Caller-supplied, so it stays out of the throttle key exactly as the 404
		// surfaces keep theirs out.
		MMSAR_Agent_Log::record( 'Markdown (redirect)', MMSAR_Agent_Log::request_path() );

		wp_safe_redirect( $target, 301, 'Make My Site Agent-Ready' );
		exit;
	}

	/**
	 * The post a retired `.md` path used to belong to, or 0.
	 *
	 * The query is the one `_find_post_by_old_slug()` runs, written out rather than called: that
	 * function is private to core, it reads the slug from `get_query_var( 'name' )` — which a `.md`
	 * request does not set — and reaching it would mean mutating the main query mid-request. This
	 * is fully static SQL with a single placeholder, which is also what keeps it clear of
	 * `InterpolatedNotPrepared`.
	 *
	 * @param string $path Requested path, without the `.md` suffix or surrounding slashes.
	 * @return int Post ID, or 0.
	 */
	private static function resolve_renamed_post_id( $path ) {
		global $wpdb;

		// Core matches an old slug, never an old path, so the comparison is on the last segment.
		$segments = explode( '/', trim( $path, '/' ) );
		$slug     = (string) end( $segments );
		if ( '' === $slug ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core exposes no public API for an old-slug lookup outside a 404, and this runs only on a path that already failed to resolve.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = '_wp_old_slug'
				  AND m.meta_value = %s
				  AND p.post_status = 'publish'
				ORDER BY p.ID DESC
				LIMIT 1",
				$slug
			)
		);

		/**
		 * Filters the post ID a renamed URL redirects to.
		 *
		 * This is core's own filter, applied here for the same decision it governs there, so a site
		 * that has already taught WordPress where its renamed posts went does not have to teach this
		 * plugin separately.
		 *
		 * @param int $post_id Post ID found for the old slug, or 0.
		 */
		$post_id = (int) apply_filters( 'old_slug_redirect_post_id', $post_id );

		/**
		 * Filters the post ID a retired `.md` URL redirects to.
		 *
		 * The extension point for redirects this plugin cannot see by itself — a redirect plugin's
		 * own rules, a manual map, an import that changed every address at once. Return 0 to let the
		 * request 404 as it does today.
		 *
		 * @param int    $post_id Post ID resolved so far, or 0.
		 * @param string $path    Requested path, without the `.md` suffix.
		 */
		$post_id = (int) apply_filters( 'mmsar_md_redirect_post_id', $post_id, $path );

		return $post_id > 0 ? $post_id : 0;
	}

	/**
	 * The path of the current request, for the redirect-loop guard.
	 *
	 * @return string
	 */
	private static function request_url_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return '' === $request_uri ? '' : (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	}

	/**
	 * Resolve post id.
	 *
	 * @return mixed Result.
	 */
	private static function resolve_post_id() {
		$path = get_query_var( 'llmmd_path' );
		if ( empty( $path ) ) {
			return 0;
		}

		if ( 'index' === $path ) {
			$front_page = get_option( 'page_on_front' );
			return $front_page ? (int) $front_page : 0;
		}

		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post_id = url_to_postid( home_url( '/' . $path ) );
		if ( $post_id ) {
			return $post_id;
		}

		$post = get_page_by_path( $path, OBJECT, mmsar_get_enabled_post_types() );
		if ( $post ) {
			return $post->ID;
		}

		return 0;
	}

	// -------------------------------------------------------------------------
	// Content negotiation on the canonical URL
	// -------------------------------------------------------------------------

	/**
	 * Serves markdown from the canonical post URL when the request asks for it via `Accept`.
	 *
	 * This is the surface agents actually reach. A crawler or fetch tool requesting a page sends
	 * one request to the canonical URL; the `.md` mirror only helps something that already knows
	 * the mirror exists. Anthropic's WebFetch, for one, sends an `Accept` header preferring
	 * Markdown precisely so a server can answer in Markdown without a second round trip.
	 *
	 * What this method can and cannot guarantee is the whole story of this feature. It declares
	 * `Vary: Accept` on both representations and marks the markdown one uncacheable, which is
	 * exactly what the standards provide for; whether anything between here and the reader honors
	 * either header is not observable from inside a request. It is observable from outside one,
	 * which is what MMSAR_Negotiation_Check exists to do.
	 *
	 * @return void
	 */
	public static function maybe_serve_negotiated() {
		if ( is_admin() || is_feed() || is_embed() || ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}

		// The HTML representation has to advertise that it varies too, or a shared cache is entitled
		// to serve a stored HTML copy to a Markdown-preferring request and vice versa. Sent on both
		// branches, and before the decision, so it goes out either way.
		header( 'Vary: Accept', false );

		if ( ! self::prefers_markdown() ) {
			return;
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post->ID, '_llmmd_content', $markdown );
			}
		}
		// Nothing to serve is not an error here: fall through and let WordPress render the page
		// normally, rather than turning a working HTML request into a 404.
		if ( empty( $markdown ) ) {
			return;
		}

		// The self-check's own requests are traffic this site made to itself. Recording them would
		// put a row in the owner's log for every check they run, attributed to their own server.
		if ( ! self::is_self_check_request() ) {
			MMSAR_Agent_Log::record( 'Markdown (content negotiation)', MMSAR_Agent_Log::request_path( get_permalink( $post->ID ) ), true );
		}

		// Ask that no shared cache store this representation. `Vary: Accept` is advisory, and a
		// cache that ignores it hands a stored markdown response to the next visitor who asks for
		// the same URL — a person gets a file download instead of the page. This header is the
		// second line of defence against that, and it is a request, not a guarantee: at least one
		// host has been observed rewriting it in transit, so what the origin sends is not
		// necessarily what reaches the edge. The check screen reports what actually arrives.
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Link: <' . esc_url( get_permalink( $post->ID ) ) . '>; rel="canonical"' );
		MMSAR_LLMs_Txt::send_link_header();
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * Whether this request is one of the negotiation self-check's own probes.
	 *
	 * The check appends a query argument both as a cache-buster — it must never fill a shared
	 * cache with a markdown copy of a URL real visitors use — and as a marker, so the response it
	 * triggers can be kept out of the agent log.
	 *
	 * @return bool
	 */
	private static function is_self_check_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Front-end request marker, not a state change.
		return isset( $_GET[ MMSAR_Negotiation_Check::ARG ] );
	}

	/**
	 * Whether the request's `Accept` header prefers Markdown over HTML.
	 *
	 * Deliberately strict, because getting this wrong serves Markdown to a browser. Markdown must be
	 * named explicitly and outrank HTML: a wildcard counts towards HTML but never towards Markdown,
	 * so the browsers and bots that accept anything keep getting HTML, and a tie goes to HTML
	 * because that is the representation a human reader expects.
	 *
	 * @return bool True when Markdown is explicitly preferred over HTML.
	 */
	private static function prefers_markdown() {
		$header = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $header ) {
			return false;
		}

		$markdown_q = -1.0;
		$html_q     = -1.0;

		foreach ( explode( ',', $header ) as $part ) {
			$bits = explode( ';', $part );
			$type = strtolower( trim( array_shift( $bits ) ) );
			if ( '' === $type ) {
				continue;
			}

			$q = 1.0;
			foreach ( $bits as $param ) {
				$param = strtolower( trim( $param ) );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
				}
			}

			if ( 'text/markdown' === $type || 'text/x-markdown' === $type ) {
				$markdown_q = max( $markdown_q, $q );
			} elseif ( 'text/html' === $type || 'text/*' === $type || '*/*' === $type ) {
				$html_q = max( $html_q, $q );
			}
		}

		return $markdown_q > 0 && $markdown_q > $html_q;
	}
}
