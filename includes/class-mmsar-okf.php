<?php
/**
 * /okf/ — Open Knowledge Format (OKF) bundle.
 *
 * Publishes the site's content as an OKF v0.2 bundle: a browsable tree of typed Markdown "concept"
 * files, one per published post/page, plus a per-post-type index and a root index — so an agent can
 * ingest the whole corpus in one pass instead of scraping page by page. Spec:
 * https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 *
 * Deliberately not the same document as /llms-full.txt. That file is one flat concatenation for a
 * model to read start to finish; this is a typed, addressable tree — each concept has its own URL,
 * its own front matter, and can be fetched on its own. Both are generated from the same underlying
 * Markdown (MMSAR_Converter / the `_llmmd_content` cache), so there is nothing to keep in sync by
 * hand.
 *
 * Scope deliberately excludes two things the spec marks optional: a packaged /okf.tar.gz archive
 * (network and memory cost that scales with the whole site, for a convenience nothing here requires),
 * and a references/ directory (that exists to mirror externally cited standards, and ordinary post
 * content doesn't cite any). Provenance fields `generated` and `verified` are omitted for the same
 * reason the spec's own bundle omits them: this plugin has no per-page authorship or verification
 * record to report, and the spec is explicit that inferring one is worse than leaving it out.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR OKF bundle handler.
 */
class MMSAR_OKF {

	const OKF_VERSION = '0.2';

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_redirect' ) );
	}

	/**
	 * Every path under /okf/ already ends in .md (or is the tree root); WordPress's own canonical
	 * redirect doesn't know that and tries to add a trailing slash, same as it does for the plain
	 * post/page .md URLs — see MMSAR_Server::prevent_redirect() for the sibling case.
	 *
	 * @param string $redirect_url The URL WordPress wants to redirect to.
	 * @return string|false The original value, or false to suppress the redirect.
	 */
	public static function prevent_redirect( $redirect_url ) {
		if ( get_query_var( 'mmsar_okf_path' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Add rewrite rules.
	 *
	 * One catch-all rule; what the remainder of the path means is worked out in serve(). The generic
	 * post/page .md catch-all in MMSAR_Server excludes `okf/` from its own pattern for exactly this
	 * reason — see the negative lookahead there.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^okf/(.+)$', 'index.php?mmsar_okf_path=$matches[1]', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_okf_path';
		return $vars;
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
	public static function serve() {
		$path = get_query_var( 'mmsar_okf_path' );
		if ( empty( $path ) ) {
			return;
		}
		$path = ltrim( (string) $path, '/' );

		if ( 'index.md' === $path ) {
			self::serve_root_index();
			return;
		}
		if ( 'log.md' === $path ) {
			self::serve_log();
			return;
		}
		if ( preg_match( '#^([A-Za-z0-9_-]+)/index\.md$#', $path, $m ) ) {
			$post_type = $m[1];
			if ( in_array( $post_type, mmsar_get_enabled_post_types(), true ) ) {
				self::serve_type_index( $post_type );
				return;
			}
			self::not_found();
			return;
		}

		self::serve_concept( $path );
	}

	/**
	 * Resolves an OKF path (with the trailing .md already expected) to a published, enabled post.
	 * Mirrors MMSAR_Server's own post/page .md resolution so the same URL that works at the site
	 * root works again here, unrelated to the per-type index namespace above.
	 *
	 * @param string $md_path The path with its .md suffix still attached.
	 * @return WP_Post|null
	 */
	private static function resolve_concept_post( $md_path ) {
		if ( '.md' !== substr( $md_path, -3 ) ) {
			return null;
		}
		$path    = substr( $md_path, 0, -3 );
		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( ! $post_id ) {
			$post_id = url_to_postid( home_url( '/' . $path ) );
		}
		if ( ! $post_id ) {
			$post    = get_page_by_path( $path, OBJECT, mmsar_get_enabled_post_types() );
			$post_id = $post ? $post->ID : 0;
		}
		if ( ! $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			return null;
		}
		return $post;
	}

	// -------------------------------------------------------------------------
	// Concept files
	// -------------------------------------------------------------------------

	/**
	 * Serve one concept file.
	 *
	 * @param string $md_path The requested path, with its .md suffix.
	 * @return void
	 */
	private static function serve_concept( $md_path ) {
		$post = self::resolve_concept_post( $md_path );
		if ( ! $post ) {
			self::not_found();
			return;
		}
		// Same rule the per-page .md endpoint applies: password-protected content never leaves the
		// site as Markdown, agent-typed front matter or not.
		if ( ! empty( $post->post_password ) ) {
			mmsar_send_cache_headers();
			header( 'Content-Type: text/markdown; charset=UTF-8' );
			status_header( 403 );
			echo "# 403 Forbidden\n\nThis content is password-protected.\n";
			exit;
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				update_post_meta( $post->ID, '_llmmd_content', $markdown );
			}
		}
		if ( empty( $markdown ) ) {
			self::not_found();
			return;
		}

		$body = self::concept_front_matter( $post ) . "\n" . trim( $markdown ) . "\n";

		mmsar_send_cache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'okf' );
		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/markdown, not HTML.
		echo $body;
		exit;
	}

	/**
	 * Builds the YAML front matter for one concept file. Only the fields OKF recommends and this
	 * plugin can honestly populate: type, title, description, resource, tags, and a producer-defined
	 * `modified` timestamp. `generated`/`verified`/`sources` are omitted — see the file header.
	 *
	 * @param WP_Post $post The post.
	 * @return string The front matter block, including its closing delimiter.
	 */
	private static function concept_front_matter( $post ) {
		$title       = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url         = get_permalink( $post );
		$modified    = mysql2date( 'c', $post->post_modified_gmt, false );

		$lines   = array( '---' );
		$lines[] = 'type: ' . self::yaml_string( $post->post_type );
		$lines[] = 'title: ' . self::yaml_string( $title );
		if ( '' !== trim( $description ) ) {
			$lines[] = 'description: ' . self::yaml_string( $description );
		}
		$lines[] = 'resource: ' . self::yaml_string( $url );

		$tags = self::term_names( $post );
		if ( $tags ) {
			$lines[] = 'tags: [' . implode( ', ', array_map( array( __CLASS__, 'yaml_string' ), $tags ) ) . ']';
		}

		$lines[] = 'modified: ' . self::yaml_string( $modified );
		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Flattened, de-duplicated public term names across every taxonomy registered for the post's
	 * type — deliberately not hardcoded to category/post_tag so custom post types with their own
	 * taxonomies are represented too.
	 *
	 * @param WP_Post $post The post.
	 * @return string[] Term names, capped to a sane count.
	 */
	private static function term_names( $post ) {
		$names = array();
		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		$names = array_values( array_unique( $names ) );
		return array_slice( $names, 0, 20 );
	}

	/**
	 * Escapes a string as a double-quoted YAML scalar. Backslash and double-quote are the only two
	 * characters that mean anything special inside YAML's double-quoted style, so escaping just those
	 * two is sufficient; newlines are stripped rather than escaped, since none of the values this
	 * builds from (titles, excerpts, URLs, term names) should ever legitimately contain one.
	 *
	 * @param string $value Raw value.
	 * @return string Quoted, escaped YAML scalar.
	 */
	private static function yaml_string( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', (string) $value );
		$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
		return '"' . $value . '"';
	}

	// -------------------------------------------------------------------------
	// Root index, per-type index, log
	// -------------------------------------------------------------------------

	/**
	 * Serve root index.
	 *
	 * @return void
	 */
	private static function serve_root_index() {
		$content = get_transient( 'mmsar_okf_root_index' );
		if ( false === $content ) {
			$content = self::generate_root_index();
			set_transient( 'mmsar_okf_root_index', $content, DAY_IN_SECONDS );
		}
		self::output( $content, 'okf-index' );
	}

	/**
	 * Generate root index.
	 *
	 * @return string
	 */
	private static function generate_root_index() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = array( '---' );
		$lines[] = 'okf_version: "' . self::OKF_VERSION . '"';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . $site_name . ' — Open Knowledge Format bundle';
		$lines[] = '';
		$lines[] = '> Generated: ' . gmdate( 'Y-m-d' ) . '. One typed Markdown concept file per published post/page on this site.';
		$lines[] = '';
		$lines[] = '## Contents';
		$lines[] = '';

		foreach ( mmsar_get_enabled_post_types() as $post_type ) {
			$count = wp_count_posts( $post_type );
			$total = isset( $count->publish ) ? (int) $count->publish : 0;
			if ( 0 === $total ) {
				continue;
			}
			$obj     = get_post_type_object( $post_type );
			$label   = $obj ? $obj->labels->name : $post_type;
			$lines[] = '- [' . $label . '](' . home_url( '/okf/' . $post_type . '/index.md' ) . ') — ' . $total . ' ' . ( 1 === $total ? 'concept' : 'concepts' );
		}

		$lines[] = '';
		$lines[] = '[Change log](' . home_url( '/okf/log.md' ) . ')';
		$lines[] = '';
		$lines[] = 'Each concept file is Markdown with YAML front matter (`type`, `title`, `description`, `resource`, `tags`, `modified`). There is no manifest beyond this index and the per-type indexes it links to — browse the tree or fetch a concept file directly.';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Serve type index.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private static function serve_type_index( $post_type ) {
		$content = get_transient( 'mmsar_okf_index_' . $post_type );
		if ( false === $content ) {
			$content = self::generate_type_index( $post_type );
			set_transient( 'mmsar_okf_index_' . $post_type, $content, DAY_IN_SECONDS );
		}
		self::output( $content, 'okf-index' );
	}

	/**
	 * Generate type index.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function generate_type_index( $post_type ) {
		$obj   = get_post_type_object( $post_type );
		$label = $obj ? $obj->labels->name : $post_type;

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$lines   = array( '# ' . $label . ' — ' . count( $posts ) . ' concepts' );
		$lines[] = '';
		$lines[] = '[Bundle index](' . home_url( '/okf/index.md' ) . ')';
		$lines[] = '';

		foreach ( $posts as $post ) {
			if ( ! empty( $post->post_password ) ) {
				continue;
			}
			$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url   = self::okf_url_for( $post );
			if ( ! $url ) {
				continue;
			}
			$lines[] = '- [' . $title . '](' . $url . ')';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Serve log.
	 *
	 * @return void
	 */
	private static function serve_log() {
		$content = get_transient( 'mmsar_okf_log' );
		if ( false === $content ) {
			$content = self::generate_log();
			set_transient( 'mmsar_okf_log', $content, DAY_IN_SECONDS );
		}
		self::output( $content, 'okf-log' );
	}

	/**
	 * Generate log. Derived from each post's own modification date, newest first, rather than a
	 * hand-maintained editorial log — there is no existing changelog for arbitrary WordPress content
	 * to draw from. Capped to the 200 most recently modified concepts; older changes still exist in
	 * the bundle, they just age out of this particular list.
	 *
	 * @return string
	 */
	private static function generate_log() {
		$posts = get_posts(
			array(
				'post_type'      => mmsar_get_enabled_post_types(),
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Intentional, fixed cap on a log that only ever runs on request, not per page load; see the docblock above.
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$lines   = array( '# Change log' );
		$lines[] = '';
		$lines[] = 'Generated from each concept\'s own last-modified date, newest first — not a hand-maintained editorial log. Shows the 200 most recently modified concepts.';
		$lines[] = '';

		foreach ( $posts as $post ) {
			if ( ! empty( $post->post_password ) ) {
				continue;
			}
			$url = self::okf_url_for( $post );
			if ( ! $url ) {
				continue;
			}
			$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$date    = mysql2date( 'Y-m-d', $post->post_modified_gmt, false );
			$lines[] = '- ' . $date . ' — [' . $title . '](' . $url . ')';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The /okf/ URL for a post's concept file — same path segment its own .md URL uses.
	 *
	 * @param WP_Post $post The post.
	 * @return string The absolute /okf/... URL, or '' if the post has no path (e.g. the front page).
	 */
	private static function okf_url_for( $post ) {
		$permalink = get_permalink( $post );
		$path      = trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		return home_url( '/okf/' . $path . '.md' );
	}

	/**
	 * Output helper shared by the root index, per-type indexes and the log — all three are plain
	 * Markdown with no front matter except what generate_root_index() already inlined.
	 *
	 * @param string $content Body to send.
	 * @param string $log_as  Agent Log surface name.
	 * @return void
	 */
	private static function output( $content, $log_as ) {
		mmsar_send_cache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		MMSAR_Agent_Log::record( $log_as );
		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw text/markdown, not HTML.
		echo $content;
		exit;
	}

	/**
	 * Not found.
	 *
	 * @return void
	 */
	private static function not_found() {
		mmsar_send_cache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		status_header( 404 );
		echo "# 404 Not Found\n\nNo OKF concept exists at this path. See [/okf/index.md](" . esc_url( home_url( '/okf/index.md' ) ) . ") for the bundle contents.\n";
		exit;
	}
}
