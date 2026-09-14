<?php
/**
 * Make My Site Agent-Ready — llms txt component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR LLMs Txt handler.
 */
class MMSAR_LLMs_Txt {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 20, not the default 10: the scoped section rules are derived from the registered
		// post types, and a theme registering a custom type on `init` at the default priority does
		// so *after* this plugin's own `init` callbacks — plugins load first. At priority 10 the
		// section list came back empty on every request and no scoped rule was ever registered.
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_llms_txt' ) );
		// A visible link in the page body, which is what the llms.txt proposal asks for. Its reach
		// is narrower than the first version of this comment claimed: measured against Anthropic's
		// WebFetch on a live site, that tool extracts a page's main content and discards the
		// chrome, so it sees a footer link no better than it sees the Link header — main-content
		// links and inline JSON-LD are what survive. What a footer link does reach is crawlers
		// that fetch and store raw HTML, and people. Off by default; see the decisions log entry
		// "The footer llms.txt link is described by what was measured".
		if ( mmsar_feature_enabled( 'llms_txt_footer_link' ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'render_footer_link' ) );
		}
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^llms\.txt$',
			'index.php?llmmd_llms_txt=1',
			'top'
		);

		// One scoped index per content section, so an agent chasing a single subject can fetch just
		// that slice instead of the whole manual. Only sections that exist and have content get a
		// rule — a scoped index that is always empty is another URL for an agent to waste a request on.
		foreach ( self::sections() as $slug => $section ) {
			add_rewrite_rule(
				'^' . preg_quote( $slug, '#' ) . '/llms\.txt$',
				'index.php?llmmd_llms_txt=1&llmmd_llms_section=' . rawurlencode( $section['post_type'] ),
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
		$vars[] = 'llmmd_llms_txt';
		$vars[] = 'llmmd_llms_section';
		return $vars;
	}

	/**
	 * Serve llms txt.
	 *
	 * @return void
	 */
	public static function serve_llms_txt() {
		if ( ! get_query_var( 'llmmd_llms_txt' ) ) {
			return;
		}

		$section = get_query_var( 'llmmd_llms_section' );
		if ( $section ) {
			$section = sanitize_key( $section );
			// A scoped index is only ever served for a section that still exists and still has
			// content, so a post type emptied or switched off stops answering rather than serving
			// an index of nothing.
			$valid = wp_list_pluck( self::sections(), 'post_type' );
			if ( ! in_array( $section, $valid, true ) ) {
				return;
			}
			$cache_key = 'llmmd_llms_txt_' . $section;
			$content   = get_transient( $cache_key );
			if ( false === $content ) {
				$content = self::generate_section( $section );
				set_transient( $cache_key, $content, DAY_IN_SECONDS );
			}

			mmsar_send_cache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			MMSAR_Agent_Log::record( 'llms.txt (' . $section . ')' );
			status_header( 200 );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain llms.txt, not HTML.
			echo $content;
			exit;
		}

		$content = get_transient( 'llmmd_llms_txt' );
		if ( false === $content ) {
			$content = self::generate();
			set_transient( 'llmmd_llms_txt', $content, DAY_IN_SECONDS );
		}

		// Registered endpoints are appended after the cache, not baked into it. The cached body is
		// invalidated by content changes, but the registry lives in code — an endpoint registered by
		// a plugin activated today would otherwise not appear until the day-long transient expired.
		$content .= MMSAR_Registry::llms_txt_section();

		/**
		 * Filters the complete llms.txt body before it is served.
		 *
		 * Runs on every request, after the cached content is assembled, so a filter here is free to
		 * depend on things the cache knows nothing about. Most integrations want the
		 * mmsar_registered_endpoints filter instead, which lists an endpoint here and in the
		 * api-catalog and Agent Skills index at the same time.
		 *
		 * @param string $content The llms.txt body.
		 */
		$content = (string) apply_filters( 'mmsar_llms_txt_content', $content );

		mmsar_send_cache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'llms.txt' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/plain llms.txt, not HTML.
		echo $content;
		exit;
	}

	/**
	 * Outputs the visible llms.txt link in the footer.
	 *
	 * Deliberately a plain anchor with real link text rather than a hidden or aria-hidden element:
	 * anything removed from the accessibility tree is also liable to be removed by the HTML-to-
	 * markdown conversion agents fetch through, which would defeat the entire purpose.
	 *
	 * Points at the same index the Link header advertises, resolved per request. Two links in one
	 * response claiming the same relation and disagreeing about the target is a worse answer than
	 * either of them alone, and on a page a scoped index covers, that index is the better one for
	 * a reader too — the scoped file names the section it covers and points back at the root.
	 *
	 * @return void
	 */
	public static function render_footer_link() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		$section = self::section_for_request();
		if ( $section ) {
			/* translators: %s: plural name of a content section, e.g. "Media". */
			$default = sprintf( __( '%s index for AI agents (llms.txt)', 'make-my-site-agent-ready' ), $section['label'] );
		} else {
			$default = __( 'Site index for AI agents (llms.txt)', 'make-my-site-agent-ready' );
		}

		/**
		 * Filters the link text shown in the footer.
		 *
		 * @param string     $text    Link text.
		 * @param string     $url     The llms.txt this link points at for the current request.
		 * @param array|null $section The covering section, or null when this is the root index.
		 */
		$text = (string) apply_filters( 'mmsar_llms_txt_link_text', $default, self::url_for_request(), $section );

		printf(
			'<p class="mmsar-llms-txt-link" style="text-align:center;font-size:0.8em;opacity:0.7;margin:1em 0;"><a href="%1$s" rel="describedby">%2$s</a></p>',
			esc_url( self::url_for_request() ),
			esc_html( $text )
		);
	}

	/**
	 * Generate.
	 *
	 * @return mixed Result.
	 */
	public static function generate() {
		$site_name   = self::decode( get_bloginfo( 'name' ) );
		$description = self::decode( get_bloginfo( 'description' ) );
		$home_url    = home_url( '/' );

		$lines   = array();
		$lines[] = '# ' . $site_name;
		if ( ! empty( $description ) ) {
			$lines[] = '';
			$lines[] = '> ' . $description;
		}
		foreach ( self::when_to_use_lines() as $line ) {
			$lines[] = $line;
		}

		$lines[] = '';
		$lines[] = '## Site';
		$lines[] = '- [Home](' . $home_url . ')';

		$front_page_id = get_option( 'page_on_front' );
		if ( $front_page_id ) {
			$lines[] = '- [Home (Markdown)](' . rtrim( $home_url, '/' ) . '/index.md)';
		}

		foreach ( self::agent_endpoint_lines() as $line ) {
			$lines[] = $line;
		}

		$post_types   = mmsar_get_enabled_post_types();
		$pages        = self::get_posts_by_type( 'page', $post_types );
		$posts        = self::get_posts_by_type( 'post', $post_types );
		$custom_posts = self::get_custom_type_posts( $post_types );

		if ( ! empty( $pages ) ) {
			$lines[] = '';
			$lines[] = '## Pages';
			foreach ( $pages as $page ) {
				$lines[] = self::format_entry( $page );
			}
		}

		if ( ! empty( $posts ) ) {
			$categories = get_categories( array( 'hide_empty' => true ) );

			if ( ! empty( $categories ) ) {
				foreach ( $categories as $cat ) {
					$cat_posts = self::get_posts_in_category( $cat->term_id, $post_types );
					if ( empty( $cat_posts ) ) {
						continue;
					}
					$lines[] = '';
					$lines[] = '## ' . self::decode( $cat->name );
					foreach ( $cat_posts as $p ) {
						$lines[] = self::format_entry( $p );
					}
				}
			} else {
				$lines[] = '';
				$lines[] = '## Posts';
				foreach ( $posts as $p ) {
					$lines[] = self::format_entry( $p );
				}
			}
		}

		foreach ( $custom_posts as $type_name => $type_posts ) {
			$type_obj = get_post_type_object( $type_name );
			$label    = $type_obj ? self::decode( $type_obj->labels->name ) : $type_name;
			$lines[]  = '';
			$lines[]  = '## ' . $label;
			foreach ( $type_posts as $p ) {
				$lines[] = self::format_entry( $p );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The content sections that get their own scoped llms.txt.
	 *
	 * Keyed by the URL segment the section lives under, which is the post type's own rewrite slug —
	 * so `/media/llms.txt` indexes exactly what lives at `/media/...`, and the address an agent
	 * guesses from a content URL is the one that works. Types with no rewrite slug of their own are
	 * skipped: there is no obvious address for them and inventing one would only produce a URL that
	 * matches nothing a reader has seen.
	 *
	 * @return array<string, array> Slug => [ 'post_type' => string, 'label' => string ].
	 */
	public static function sections() {
		$sections = array();

		foreach ( mmsar_get_enabled_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			$slug = '';
			if ( 'post' === $post_type ) {
				// Posts have no rewrite slug; their section is whatever page is set as the posts page.
				$posts_page = (int) get_option( 'page_for_posts' );
				if ( $posts_page ) {
					$slug = get_post_field( 'post_name', $posts_page );
				}
			} elseif ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$slug = $object->rewrite['slug'];
			}

			$slug = trim( (string) $slug, '/' );
			if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
				continue;
			}

			$counts = wp_count_posts( $post_type );
			if ( empty( $counts->publish ) ) {
				continue;
			}

			$sections[ $slug ] = array(
				'post_type' => $post_type,
				'label'     => self::decode( $object->labels->name ),
			);
		}

		/**
		 * Filters the sections that get their own scoped llms.txt.
		 *
		 * @param array $sections Slug => [ 'post_type', 'label' ].
		 */
		$filtered = apply_filters( 'mmsar_llms_txt_sections', $sections );
		return is_array( $filtered ) ? $filtered : $sections;
	}

	/**
	 * The section whose scoped llms.txt covers the current request.
	 *
	 * Version 2 of the proposal scopes a file by path — it covers the pages beneath it, and the
	 * most specific file wins. Until now every page advertised the root index, which left the scoped
	 * indexes reachable only by an agent that had already guessed the path: exactly the v1
	 * behaviour v2 set out to replace. So the relation is resolved per request instead.
	 *
	 * Matching is on the request path alone, not on the queried post type. Coverage is a property
	 * of the URL in the proposal, so `/media/llms.txt` describes everything under `/media/`,
	 * including that section's archive and its paged views. A post whose permalink does not sit
	 * under its section's slug is correctly left with the root index — the scoped file does not
	 * cover it, whatever it lists.
	 *
	 * @return array|null [ 'slug', 'post_type', 'label' ] for the covering section, or null.
	 */
	public static function section_for_request() {
		static $resolved = false;
		static $match    = null;

		if ( $resolved ) {
			return $match;
		}
		$resolved = true;

		$path = self::request_path();
		if ( '' === $path ) {
			return $match;
		}

		$best_len = 0;
		foreach ( self::sections() as $slug => $section ) {
			$slug = trim( (string) $slug, '/' );
			if ( '' === $slug ) {
				continue;
			}
			// "Beneath its path" includes that path itself, so a section archive is described by
			// its own index rather than falling back to the root one.
			if ( $path !== $slug && 0 !== strpos( $path, $slug . '/' ) ) {
				continue;
			}
			// Most specific wins. sections() rejects multi-segment slugs, so in practice there is
			// only ever one candidate — but the mmsar_llms_txt_sections filter runs after that
			// check and can add one, and "most specific wins" is the rule the proposal states, so
			// the rule is what gets implemented rather than the shape today's data happens to have.
			$len = strlen( $slug );
			if ( $len > $best_len ) {
				$best_len = $len;
				$match    = array_merge( array( 'slug' => $slug ), $section );
			}
		}

		return $match;
	}

	/**
	 * The llms.txt URL that describes the current request.
	 *
	 * @return string Absolute URL of the covering scoped index, or of the root index.
	 */
	public static function url_for_request() {
		$section = self::section_for_request();
		if ( $section ) {
			return home_url( '/' . $section['slug'] . '/llms.txt' );
		}
		return home_url( '/llms.txt' );
	}

	/**
	 * Emits the `describedby` Link header for the current request.
	 *
	 * Shared, because this now goes out from four places — the ordinary page response and the three
	 * Markdown responses — and four hand-written copies of one header is how the relation and the
	 * media type drift apart. The proposal asks for the header form specifically because it "also
	 * works for non-HTML resources, such as the markdown files themselves": those responses have no
	 * <head> to carry a <link>, so the header is the only channel they have.
	 *
	 * Sent without replacing, so it joins any Link header already set rather than evicting it. Call
	 * it *after* any header( 'Link: ...' ) that replaces, or that one will wipe this out.
	 *
	 * @return void
	 */
	public static function send_link_header() {
		if ( ! mmsar_feature_enabled( 'llms_txt' ) ) {
			return;
		}
		header( 'Link: <' . esc_url_raw( self::url_for_request() ) . '>; rel="describedby"; type="text/plain"', false );
	}

	/**
	 * The requested path, relative to the site root and without surrounding slashes.
	 *
	 * Read from REQUEST_URI rather than from the resolved query, because this has to answer on a
	 * 404 as well, where there is no queried object to ask. The home path is stripped first so the
	 * comparison still holds on an install in a subdirectory, where every request path carries
	 * that prefix and would otherwise never match a section slug.
	 *
	 * @return string Site-relative path, or '' at the site root.
	 */
	private static function request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return '';
		}

		$path = trim( rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home ) {
			if ( $path === $home ) {
				return '';
			}
			if ( 0 === strpos( $path . '/', $home . '/' ) ) {
				$path = trim( substr( $path, strlen( $home ) ), '/' );
			}
		}

		return $path;
	}

	/**
	 * A scoped llms.txt for one content section.
	 *
	 * @param string $post_type The post type to index.
	 * @return string The document.
	 */
	private static function generate_section( $post_type ) {
		$object    = get_post_type_object( $post_type );
		$label     = $object ? self::decode( $object->labels->name ) : $post_type;
		$site_name = self::decode( get_bloginfo( 'name' ) );

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'has_password'   => false,
			)
		);

		$lines   = array();
		$lines[] = '# ' . $site_name . ' — ' . $label;
		$lines[] = '';
		$lines[] = '> A scoped index covering only ' . strtolower( $label ) . ' on this site. '
			. 'For everything else, see the full index at ' . home_url( '/llms.txt' ) . '.';
		$lines[] = '';
		$lines[] = '## ' . $label;

		foreach ( $posts as $post ) {
			$lines[] = self::format_entry( $post );
		}

		$lines[] = '';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The "When to use this" section.
	 *
	 * The one piece of an llms.txt that is about judgement rather than inventory. Everything else in
	 * the file tells an agent what is here; this tells it whether any of that is worth fetching for
	 * the question in front of it. Without it an agent either reads the whole index to find out it
	 * was the wrong site, or skips a site that would have answered it.
	 *
	 * Site owners write their own on the settings page. The generated fallback is deliberately
	 * modest — it can honestly describe the shape of the site and what its endpoints are good for,
	 * but it cannot know the subject matter, so it does not pretend to.
	 *
	 * @return string[] Lines to append, or an empty array when there is nothing to say.
	 */
	private static function when_to_use_lines() {
		$custom = trim( (string) get_option( 'mmsar_when_to_use', '' ) );

		if ( '' !== $custom ) {
			$body = $custom;
		} else {
			$site_name = self::decode( get_bloginfo( 'name' ) );
			$counts    = array();
			foreach ( mmsar_get_enabled_post_types() as $post_type ) {
				$object = get_post_type_object( $post_type );
				$count  = wp_count_posts( $post_type );
				$n      = isset( $count->publish ) ? (int) $count->publish : 0;
				if ( $n > 0 && $object ) {
					$counts[] = $n . ' ' . strtolower( 1 === $n ? $object->labels->singular_name : $object->labels->name );
				}
			}

			$body = 'Use this site when a question is specifically about ' . $site_name
				. ', about what its author has written or published, or about work they have done.'
				. ( $counts ? ' It holds ' . self::human_join( $counts ) . '.' : '' )
				. "\n\n"
				. 'It is not a general reference — for anything not tied to this author or their work, look elsewhere.';
		}

		$how = array();
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$how[] = 'Connect to the MCP server at `' . MMSAR_MCP::endpoint_url() . '` and call `search_content`. That is the cheapest route for a specific question.';
		}
		$how[] = 'Otherwise, read the index below, then fetch any page as Markdown by adding `.md` to its URL.';
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$how[] = 'To take everything in one request, fetch [llms-full.txt](' . home_url( '/llms-full.txt' ) . ').';
		}

		$lines = array( '', '## When to use this', '', $body, '', '### How to call it', '' );
		foreach ( $how as $step ) {
			$lines[] = '- ' . $step;
		}

		/**
		 * Filters the "When to use this" section of llms.txt.
		 *
		 * @param string[] $lines Lines of the section, including its heading.
		 */
		$filtered = apply_filters( 'mmsar_when_to_use_lines', $lines );
		return is_array( $filtered ) ? $filtered : $lines;
	}

	/**
	 * Joins a list into "a", "a and b", or "a, b, and c".
	 *
	 * @param string[] $items Items to join.
	 * @return string Joined string.
	 */
	private static function human_join( $items ) {
		$items = array_values( $items );
		$count = count( $items );
		if ( $count <= 1 ) {
			return implode( '', $items );
		}
		if ( 2 === $count ) {
			return $items[0] . ' and ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}

	/**
	 * The section listing this site's machine-readable endpoints.
	 *
	 * This is the file an agent is most likely to fetch first, and until now it said nothing
	 * about the rest of what this site publishes — an agent could read the whole index and still
	 * not know there was an API to call or a server to connect to. Each line is gated on its own
	 * feature, so nothing here points at a switched-off endpoint.
	 *
	 * @return string[] Lines to append, or an empty array when there is nothing to list.
	 */
	private static function agent_endpoint_lines() {
		$entries = array();

		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$entries[] = '- Any page as Markdown: add `.md` to its URL — no separate fetch of the HTML needed.';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$entries[] = '- [llms-full.txt](' . home_url( '/llms-full.txt' ) . '): every page below, in full, in one document.';
		}
		if ( mmsar_feature_enabled( 'okf_bundle' ) ) {
			$entries[] = '- [OKF bundle](' . home_url( '/okf/index.md' ) . '): the same content as a tree of typed Markdown concept files (Open Knowledge Format v0.2) — one addressable file per page, for ingesting the whole corpus in one pass rather than scraping it.';
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$entries[] = '- [openapi.json](' . MMSAR_OpenAPI::url() . '): OpenAPI description of this site\'s API — how to search and filter its content over HTTP.';
		}
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$entries[] = '- MCP server (read-only, no auth): `' . MMSAR_MCP::endpoint_url() . '` — connect directly if your client speaks MCP. Described at [/.well-known/mcp.json](' . home_url( '/.well-known/mcp.json' ) . ').';
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$entries[] = '- [api-catalog](' . home_url( '/.well-known/api-catalog' ) . '): every machine-readable endpoint on this site.';
		}

		foreach ( self::sections() as $slug => $section ) {
			$entries[] = '- [' . $slug . '/llms.txt](' . home_url( '/' . $slug . '/llms.txt' ) . '): just the '
				. strtolower( $section['label'] ) . ', if that is all you need.';
		}

		if ( empty( $entries ) ) {
			return array();
		}

		return array_merge( array( '', '## For agents' ), $entries );
	}

	/**
	 * Format entry.
	 *
	 * @param mixed $post Post.
	 * @return mixed Result.
	 */
	private static function format_entry( $post ) {
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && $front_page_id === $post->ID ) {
			$url = rtrim( home_url(), '/' ) . '/index.md';
		} else {
			$url = rtrim( get_permalink( $post->ID ), '/' ) . '.md';
		}
		$title   = str_replace( array( '[', ']' ), array( '\[', '\]' ), self::decode( get_the_title( $post ) ) );
		$excerpt = self::decode( get_the_excerpt( $post ) );
		$line    = '- [' . $title . '](' . $url . ')';
		if ( ! empty( $excerpt ) ) {
			$line .= ': ' . $excerpt;
		}
		return $line;
	}

	/**
	 * Decode.
	 *
	 * @param mixed $str Str.
	 * @return mixed Result.
	 */
	private static function decode( $str ) {
		return html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Get posts by type.
	 *
	 * @param mixed $type Type.
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_posts_by_type( $type, $enabled_types ) {
		if ( ! in_array( $type, $enabled_types, true ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get custom type posts.
	 *
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_custom_type_posts( $enabled_types ) {
		$custom = array_diff( $enabled_types, array( 'post', 'page' ) );
		$result = array();
		foreach ( $custom as $type ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			if ( ! empty( $posts ) ) {
				$result[ $type ] = $posts;
			}
		}
		return $result;
	}

	/**
	 * Get posts in category.
	 *
	 * @param mixed $cat_id Cat id.
	 * @param mixed $enabled_types Enabled types.
	 * @return mixed Result.
	 */
	private static function get_posts_in_category( $cat_id, $enabled_types ) {
		if ( ! in_array( 'post', $enabled_types, true ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'has_password'   => false,
				'cat'            => $cat_id,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}
}
