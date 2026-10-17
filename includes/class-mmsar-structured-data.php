<?php
/**
 * Optional JSON-LD structured data pointing agents at the markdown alternate.
 * Opt-in, default off. When Yoast SEO is active and produces a schema piece
 * for the current page, injects the markdown pointer directly into Yoast's
 * own Article/WebPage piece instead of emitting a second, duplicate block —
 * see decisions-log.md for the full reasoning.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Structured Data handler.
 */
class MMSAR_Structured_Data {

	/**
	 * Whether the markdown pointer has already been injected into the schema graph this request.
	 *
	 * @var bool
	 */
	private static $injected = false;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Registered unconditionally rather than gated on defined('WPSEO_VERSION') —
		// plugin load order isn't guaranteed, so checking for Yoast's constant at this
		// point (top-level, during our own file's load) can run before Yoast's file has
		// loaded and defined it. If Yoast isn't active, these filters simply never fire.
		add_filter( 'wpseo_schema_article', array( __CLASS__, 'inject_into_article' ) );
		add_filter( 'wpseo_schema_webpage', array( __CLASS__, 'inject_into_webpage' ) );
		add_action( 'wp_head', array( __CLASS__, 'output_fallback' ), 25 );
	}

	/**
	 * Shared eligibility check for both the injection and fallback paths, so
	 * they can't drift. Returns the data needed to build the encoding field,
	 * or null if this page shouldn't get structured data at all.
	 */
	private static function eligible() {
		if ( ! self::is_enabled() ) {
			return null;
		}
		if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
			return null;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$markdown = get_post_meta( $post_id, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			return null;
		}

		$md_url = mmsar_get_markdown_url();
		if ( ! $md_url ) {
			return null;
		}

		return array(
			'post_id' => $post_id,
			'md_url'  => $md_url,
		);
	}

	/**
	 * Encoding field.
	 *
	 * @param mixed $md_url Md url.
	 * @return mixed Result.
	 */
	private static function encoding_field( $md_url ) {
		return array(
			'@type'          => 'MediaObject',
			'contentUrl'     => $md_url,
			'encodingFormat' => 'text/markdown',
		);
	}

	/**
	 * Only the Article piece gets the injection on a single post — Yoast
	 * emits both an Article and a WebPage piece for posts, and adding it to
	 * both would be redundant within the same graph.
	 *
	 * @param mixed $data Yoast schema piece data.
	 * @return mixed The schema piece, with the markdown pointer injected when eligible.
	 */
	public static function inject_into_article( $data ) {
		if ( ! is_array( $data ) || 'post' !== get_post_type() ) {
			return $data;
		}
		$info = self::eligible();
		if ( ! $info ) {
			return $data;
		}
		$data['encoding'] = self::encoding_field( $info['md_url'] );
		self::$injected   = true;
		return $data;
	}

	/**
	 * Skipped for posts (the Article piece already got it) — only applies to
	 * pages and other non-'post' content types.
	 *
	 * @param mixed $data Yoast schema piece data.
	 * @return mixed The schema piece, with the markdown pointer injected when eligible.
	 */
	public static function inject_into_webpage( $data ) {
		if ( ! is_array( $data ) || 'post' === get_post_type() ) {
			return $data;
		}
		$info = self::eligible();
		if ( ! $info ) {
			return $data;
		}
		$data['encoding'] = self::encoding_field( $info['md_url'] );
		self::$injected   = true;
		return $data;
	}

	/**
	 * Standalone block, only emitted if the Yoast injection above never
	 * fired for this page — no Yoast, Yoast schema disabled, or a post type
	 * Yoast gives its own distinct schema to (e.g. WooCommerce products).
	 */
	public static function output_fallback() {
		$data = self::fallback_node();
		if ( ! $data ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > (as </>) so a title containing a literal
		// "</script>" can't break out of the surrounding script tag.
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n";
	}

	/**
	 * The standalone node output_fallback() prints, or null when it prints nothing.
	 *
	 * Public since 1.65.0 so the `.jsonld` mirror (MMSAR_JSONLD) serves exactly the node the page
	 * embeds. It reads the same per-request state: call it after Yoast's graph has been generated,
	 * or the injection flag is not yet set and the node would be added to a page that has none.
	 *
	 * @return array|null
	 */
	public static function fallback_node() {
		if ( self::$injected ) {
			return null;
		}

		$info = self::eligible();
		if ( ! $info ) {
			return null;
		}

		$post_id    = $info['post_id'];
		$is_article = ( 'post' === get_post_type( $post_id ) );
		$title      = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$data                                      = array(
			'@context'      => 'https://schema.org',
			'@type'         => $is_article ? 'Article' : 'WebPage',
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'encoding'      => self::encoding_field( $info['md_url'] ),
		);
		$data[ $is_article ? 'headline' : 'name' ] = $title;

		return $data;
	}

	/**
	 * Whether the opt-in structured data setting is on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === get_option( 'mmsar_structured_data', '' );
	}
}
