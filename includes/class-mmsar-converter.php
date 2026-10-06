<?php
/**
 * Make My Site Agent-Ready — converter component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\HTMLToMarkdown\HtmlConverter;

/**
 * MMSAR Converter handler.
 */
class MMSAR_Converter {

	/**
	 * Frontmatter keys the plugin writes itself. The `mmsar_frontmatter` filter cannot set these.
	 *
	 * @var string[]
	 */
	const CORE_FRONTMATTER_KEYS = array( 'title', 'date', 'modified', 'author', 'url', 'markdown_url', 'type', 'excerpt', 'description', 'categories', 'tags' );

	/**
	 * Convert post.
	 *
	 * @param mixed $post_id Post id.
	 * @return mixed Result.
	 */
	public static function convert_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return '';
		}

		$frontmatter = self::build_frontmatter( $post );
		$content     = self::get_rendered_content( $post );
		$markdown    = self::with_title_heading( self::html_to_markdown( $content ), $post );

		return $frontmatter . $markdown;
	}

	/**
	 * Opens the body with the post title as a level-one heading, unless it already has one.
	 *
	 * WordPress renders the title from the theme, outside post_content, so a converted body starts
	 * mid-thought at its first paragraph. The title is in the frontmatter, but a reader that skips
	 * frontmatter (most Markdown renderers, and agents that treat it as metadata) sees a document
	 * with no title, and checkers that expect a Markdown file to open with `# ` flag it.
	 *
	 * @param string  $markdown Converted body.
	 * @param WP_Post $post     The post.
	 * @return string Body, with the heading when one was missing.
	 */
	private static function with_title_heading( $markdown, $post ) {
		if ( '' === trim( $markdown ) || 0 === strpos( ltrim( $markdown ), '# ' ) ) {
			return $markdown;
		}
		$title = self::title_heading( $post );
		if ( '' === $title ) {
			return $markdown;
		}
		return $title . "\n\n" . $markdown;
	}

	/**
	 * The level-one heading the converter puts at the top of a post's body.
	 *
	 * @param WP_Post $post The post.
	 * @return string `# Title`, or '' when the post has no title.
	 */
	public static function title_heading( $post ) {
		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' === $title ? '' : '# ' . $title;
	}

	/**
	 * Removes the title heading from a stored document's body, for wrappers that print their own.
	 *
	 * Only the heading this converter adds is removed: the first line after the frontmatter, and only
	 * when it is exactly that post's title heading. A heading the author wrote is left alone.
	 *
	 * @param string  $markdown Stored document, frontmatter included.
	 * @param WP_Post $post     The post it belongs to.
	 * @return string The document without its title heading.
	 */
	public static function without_title_heading( $markdown, $post ) {
		$heading = self::title_heading( $post );
		if ( '' === $heading ) {
			return $markdown;
		}
		$pattern = '/^(---\n.*?\n---\n)' . preg_quote( $heading, '/' ) . '\n\n?/s';
		$result  = preg_replace( $pattern, '$1', $markdown, 1 );
		return is_string( $result ) ? $result : $markdown;
	}

	/**
	 * Build frontmatter.
	 *
	 * @param mixed $post Post.
	 * @return mixed Result.
	 */
	private static function build_frontmatter( $post ) {
		$author = get_userdata( $post->post_author );
		$url    = get_permalink( $post );

		$lines   = array();
		$lines[] = '---';
		$lines[] = 'title: "' . self::escape_yaml( get_the_title( $post ) ) . '"';
		$lines[] = 'date: ' . get_the_date( 'Y-m-d', $post );
		$lines[] = 'modified: ' . get_the_modified_date( 'Y-m-d', $post );
		if ( $author ) {
			$lines[] = 'author: "' . self::escape_yaml( $author->display_name ) . '"';
		}
		$lines[]       = 'url: "' . $url . '"';
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && $front_page_id === $post->ID ) {
			$lines[] = 'markdown_url: "' . rtrim( $url, '/' ) . '/index.md"';
		} else {
			$lines[] = 'markdown_url: "' . rtrim( $url, '/' ) . '.md"';
		}
		$lines[] = 'type: ' . $post->post_type;

		// Both summary lines can be switched off at Settings > Agent-Ready. They stay core keys either
		// way, so the mmsar_frontmatter filter cannot write them in when the owner has turned them off.
		if ( mmsar_frontmatter_includes( 'excerpt' ) ) {
			$excerpt = self::excerpt( $post );
			if ( $excerpt ) {
				$lines[] = 'excerpt: "' . self::escape_yaml( $excerpt ) . '"';
			}
		}

		if ( mmsar_frontmatter_includes( 'description' ) ) {
			$description = self::description_line( self::seo_description( $post ) );
			if ( '' !== $description ) {
				$lines[] = $description;
			}
		}

		$categories = get_the_category( $post->ID );
		if ( ! empty( $categories ) ) {
			$lines[] = 'categories:';
			foreach ( $categories as $cat ) {
				$lines[] = '  - "' . self::escape_yaml( $cat->name ) . '"';
			}
		}

		$tags = get_the_tags( $post->ID );
		if ( ! empty( $tags ) ) {
			$lines[] = 'tags:';
			foreach ( $tags as $tag ) {
				$lines[] = '  - "' . self::escape_yaml( $tag->name ) . '"';
			}
		}

		/**
		 * Filters extra fields for a post's markdown frontmatter.
		 *
		 * Return an associative array of key => value. Values may be a string, an integer or
		 * float, a boolean, or a flat list of strings; the plugin writes the YAML. Keys must match
		 * `^[a-z][a-z0-9_]*$`. The core keys (see CORE_FRONTMATTER_KEYS) cannot be overridden or
		 * removed: a colliding key is ignored. Anything invalid or empty is skipped rather than
		 * written. Extra fields appear after the core ones, in the order given.
		 *
		 * The result is stored with the post's markdown, so a change to what this filter returns
		 * shows on a post the next time it is saved or when Settings > Agent-Ready > Regenerate All
		 * is pressed.
		 *
		 * @since 1.51.0
		 *
		 * @param array   $fields Extra fields. Empty by default.
		 * @param WP_Post $post   The post being converted.
		 */
		$extra = apply_filters( 'mmsar_frontmatter', array(), $post );
		$lines = array_merge( $lines, self::extra_frontmatter_lines( $extra ) );

		$lines[] = '---';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * The post's meta description as the site's SEO plugin resolves it, or '' when there is none.
	 *
	 * Yoast only, read through its surface API rather than `_yoast_wpseo_metadesc`, so a
	 * description that comes from a post-type template (with its replacement variables resolved) is
	 * included. Rank Math, All in One SEO and SEOPress are not read: none could be verified against a
	 * real install, and `mmsar_frontmatter_description` is the way to supply one from any source.
	 *
	 * @param WP_Post $post Post.
	 * @return string Raw description, unescaped.
	 */
	public static function seo_description( $post ) {
		$description = '';
		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$meta = YoastSEO()->meta->for_post( $post->ID );
				if ( $meta && is_string( $meta->description ) ) {
					$description = $meta->description;
				}
			} catch ( Throwable $e ) {
				$description = '';
			}
		}

		/**
		 * Filters the meta description written to a post's markdown frontmatter as `description:`.
		 *
		 * Receives what the SEO plugin resolved (Yoast only, '' otherwise). Return a string to use
		 * instead, or '' to leave the key out. HTML is stripped and line breaks become spaces.
		 *
		 * @since 1.52.0
		 *
		 * @param string  $description The description, or ''.
		 * @param WP_Post $post        The post being converted.
		 */
		$description = apply_filters( 'mmsar_frontmatter_description', $description, $post );

		return is_string( $description ) ? $description : '';
	}

	/**
	 * The post's excerpt at the site's own excerpt length, wherever the conversion runs.
	 *
	 * WordPress 7.0's post-excerpt block adds an `excerpt_length` filter returning 101 at
	 * PHP_INT_MAX whenever is_admin() is true, so its editor setting can trim auto-excerpts itself.
	 * Left alone, markdown rebuilt from wp-admin (Regenerate All, a classic-editor save) got
	 * ~100-word excerpts while the same post rebuilt from the block editor, WP-CLI or the front end
	 * got the site's length. The core filter is lifted for this one call and put back at the
	 * priority it had. Hand-written excerpts are not trimmed by excerpt_length, so they are
	 * unaffected either way.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function excerpt( $post ) {
		global $wp_filter;

		// Read the registration out of the hook table rather than re-adding by name, so exactly what
		// was there goes back: priority and accepted_args included. On versions before 7.0 nothing
		// matches and nothing is touched.
		$core   = 'block_core_post_excerpt_excerpt_length';
		$lifted = array();
		if ( isset( $wp_filter['excerpt_length'] ) && $wp_filter['excerpt_length'] instanceof WP_Hook ) {
			foreach ( $wp_filter['excerpt_length']->callbacks as $priority => $entries ) {
				foreach ( $entries as $entry ) {
					if ( $core === $entry['function'] ) {
						$lifted[] = array( $priority, $entry );
					}
				}
			}
		}
		foreach ( $lifted as $item ) {
			remove_filter( 'excerpt_length', $item[1]['function'], $item[0] );
		}

		$excerpt = get_the_excerpt( $post );

		foreach ( $lifted as $item ) {
			add_filter( 'excerpt_length', $item[1]['function'], $item[0], $item[1]['accepted_args'] );
		}

		return $excerpt;
	}

	/**
	 * Renders a description as its frontmatter line, or '' when there is nothing to write.
	 *
	 * @param string $description Raw description.
	 * @return string The `description:` line, or ''.
	 */
	public static function description_line( $description ) {
		$escaped = self::yaml_line( wp_strip_all_tags( $description ) );
		return '' === $escaped ? '' : 'description: "' . $escaped . '"';
	}

	/**
	 * Renders the fields returned by the `mmsar_frontmatter` filter as YAML lines.
	 *
	 * Each field is validated on its own and dropped if it cannot be written safely, so one bad
	 * value never costs the document. Strings are flattened to a single line before quoting: a
	 * newline inside a quoted value is legal YAML, but a value carrying `\n---\n` would end the
	 * frontmatter early for any reader that splits on delimiter lines, which most do.
	 *
	 * @param mixed $fields The filter's return value.
	 * @return string[] YAML lines, without delimiters.
	 */
	public static function extra_frontmatter_lines( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$lines = array();
		foreach ( $fields as $key => $value ) {
			// \z, not $: `$` also matches before a trailing newline, which would let "series\n" through.
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]*\z/', $key ) || in_array( $key, self::CORE_FRONTMATTER_KEYS, true ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$lines[] = $key . ': ' . ( $value ? 'true' : 'false' );
			} elseif ( is_int( $value ) ) {
				$lines[] = $key . ': ' . $value;
			} elseif ( is_float( $value ) ) {
				if ( is_finite( $value ) ) {
					$lines[] = $key . ': ' . wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
				}
			} elseif ( is_string( $value ) ) {
				$scalar = self::yaml_line( $value );
				if ( '' !== $scalar ) {
					$lines[] = $key . ': "' . $scalar . '"';
				}
			} elseif ( is_array( $value ) ) {
				$items = self::yaml_list_items( $value );
				if ( $items ) {
					$lines[] = $key . ':';
					foreach ( $items as $item ) {
						$lines[] = '  - "' . $item . '"';
					}
				}
			}
		}

		return $lines;
	}

	/**
	 * Escapes a list for the frontmatter, or rejects it. Only a flat, sequential list qualifies:
	 * an associative array or a nested one would be a mapping, which this filter does not write.
	 * Numbers are accepted and written as strings; empty items are dropped.
	 *
	 * @param array $values Candidate list.
	 * @return string[] Escaped items, empty if the list was rejected or had nothing in it.
	 */
	private static function yaml_list_items( $values ) {
		if ( array_values( $values ) !== $values ) {
			return array();
		}
		$items = array();
		foreach ( $values as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) && ! is_float( $item ) ) {
				return array();
			}
			$escaped = self::yaml_line( (string) $item );
			if ( '' !== $escaped ) {
				$items[] = $escaped;
			}
		}
		return $items;
	}

	/**
	 * Escapes a string for a double-quoted YAML scalar and flattens it to one line. Control
	 * characters and the Unicode line breaks YAML recognises become spaces. Invalid UTF-8 yields an
	 * empty string, which the caller treats as nothing to write.
	 *
	 * @param string $str Raw value.
	 * @return string Escaped value, trimmed.
	 */
	private static function yaml_line( $str ) {
		$flat = preg_replace( '/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]+/u', ' ', self::escape_yaml( $str ) );
		return null === $flat ? '' : trim( $flat );
	}

	/**
	 * Escape yaml.
	 *
	 * @param mixed $str Str.
	 * @return mixed Result.
	 */
	private static function escape_yaml( $str ) {
		$str = html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$str = str_replace( '\\', '\\\\', $str );
		$str = str_replace( '"', '\\"', $str );
		return $str;
	}

	/**
	 * Get rendered content.
	 *
	 * @param mixed $post Post.
	 * @return mixed Result.
	 */
	private static function get_rendered_content( $post ) {
		$content = $post->post_content;

		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}
		$content = do_shortcode( $content );
		$content = wpautop( $content );
		$content = wptexturize( $content );

		$root_selector = mmsar_get_root_selector();
		if ( ! empty( $root_selector ) ) {
			$content = self::extract_root( $content, $root_selector );
		}

		$content = apply_filters( 'mmsar_rendered_content', $content, $post );

		return $content;
	}

	/**
	 * Extract root.
	 *
	 * @param mixed $html Html.
	 * @param mixed $selector Selector.
	 * @return mixed Result.
	 */
	private static function extract_root( $html, $selector ) {
		if ( empty( trim( $html ) ) ) {
			return $html;
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath     = new DOMXPath( $doc );
		$selectors = array_map( 'trim', explode( ',', $selector ) );

		foreach ( $selectors as $sel ) {
			$xp    = self::css_to_xpath( $sel );
			$nodes = $xpath->query( $xp );
			if ( $nodes && $nodes->length > 0 ) {
				$output = '';
				foreach ( $nodes as $node ) {
					$output .= $doc->saveHTML( $node );
				}
				return $output;
			}
		}

		return $html;
	}

	/**
	 * Css to xpath.
	 *
	 * @param mixed $selector Selector.
	 * @return mixed Result.
	 */
	private static function css_to_xpath( $selector ) {
		$selector = trim( $selector );
		if ( strpos( $selector, '#' ) === 0 ) {
			$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', substr( $selector, 1 ) );
			return "//*[@id='" . $id . "']";
		}
		if ( strpos( $selector, '.' ) === 0 ) {
			$class = preg_replace( '/[^a-zA-Z0-9_-]/', '', substr( $selector, 1 ) );
			return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
		}
		$tag = preg_replace( '/[^a-zA-Z0-9]/', '', $selector );
		return '//' . $tag;
	}

	/**
	 * Html to markdown.
	 *
	 * @param mixed $html Html.
	 * @return mixed Result.
	 */
	private static function html_to_markdown( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/si', '', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/si', '', $html );
		$html = preg_replace( '/<iframe\b[^>]*>.*?<\/iframe>/si', '', $html );
		$html = preg_replace( '/<nav\b[^>]*>.*?<\/nav>/si', '', $html );

		try {
			$converter = new HtmlConverter(
				array(
					'strip_tags'              => true,
					'remove_nodes'            => 'script style iframe',
					'hard_break'              => false,
					'header_style'            => 'atx',
					'strip_placeholder_links' => true,
				)
			);

			$markdown = $converter->convert( $html );
		} catch ( \Exception $e ) {
			$markdown = wp_strip_all_tags( $html );
		}

		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		$markdown = trim( $markdown );

		return $markdown . "\n";
	}
}
