<?php
/**
 * The per-page `.jsonld` mirror (1.65.0): Schemamap option B.
 *
 * What is tested here is what can go wrong without a WordPress: the address a page's graph lives at
 * (front page included), the rewrite pattern staying clear of the plugin's own documents, which
 * posts may be served, the `rel="alternate"` link and header, and how Yoast's graph and the plugin's
 * own node become one document. The request path (query swap, Yoast's generator) is verified on the
 * clone, by comparing the served graph with the one the page embeds.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Just enough of WordPress for the class under test. Declared through eval() so static analysis
// keeps reading WordPress's own signatures, which the plugin code is checked against.
if ( ! class_exists( 'WP_Post' ) ) {
	eval( 'final class WP_Post { public $ID = 0; public $post_status = "publish"; public $post_password = ""; public $post_type = "post"; public function __construct( $post ) { foreach ( get_object_vars( $post ) as $k => $v ) { $this->$k = $v; } } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- See above.
}
foreach (
	array(
		'esc_url_raw'                  => 'function esc_url_raw( $url ) { return (string) $url; }',
		'esc_url'                      => 'function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, "UTF-8" ); }',
		'mmsar_get_enabled_post_types' => 'function mmsar_get_enabled_post_types() { return array( "post" ); }',
	) as $jsonld_test_fn => $jsonld_test_code
) {
	if ( ! function_exists( $jsonld_test_fn ) ) {
		eval( $jsonld_test_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- See above.
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mmsar-jsonld.php';

/**
 * JSON-LD mirror tests.
 */
final class JsonLdMirrorTest extends TestCase {

	/**
	 * Reset stubs.
	 */
	protected function setUp(): void {
		wp_stub_reset();
	}

	public function test_address_is_the_permalink_plus_jsonld(): void {
		$this->assertSame( 'https://example.com/about.jsonld', MMSAR_JSONLD::url_for_permalink( 'https://example.com/about/', false, 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/plugins/x.jsonld', MMSAR_JSONLD::url_for_permalink( 'https://example.com/plugins/x', false, 'https://example.com/' ) );
	}

	public function test_front_page_is_index_jsonld_like_index_md(): void {
		$this->assertSame( 'https://example.com/index.jsonld', MMSAR_JSONLD::url_for_permalink( 'https://example.com/', true, 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/blog/index.jsonld', MMSAR_JSONLD::url_for_permalink( 'https://example.com/blog/', true, 'https://example.com/blog/' ) );
	}

	/**
	 * Paths the rewrite rule must and must not claim.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function paths(): array {
		return array(
			'page'              => array( 'about.jsonld', 'about' ),
			'nested'            => array( 'plugins/lighthouse-scanner.jsonld', 'plugins/lighthouse-scanner' ),
			'trailing slash'    => array( 'about.jsonld/', 'about' ),
			'front page'        => array( 'index.jsonld', 'index' ),
			'markdown mirror'   => array( 'about.md', null ),
			'well-known'        => array( '.well-known/thing.jsonld', null ),
			'okf tree'          => array( 'okf/post/x.jsonld', null ),
			'suffix in the mid' => array( 'about.jsonld/more', null ),
		);
	}

	#[DataProvider( 'paths' )]
	public function test_rewrite_pattern_claims_only_page_mirrors( string $path, ?string $captured ): void {
		$matched = (bool) preg_match( '#' . MMSAR_JSONLD::REWRITE_PATTERN . '#', $path, $m );
		if ( null === $captured ) {
			$this->assertFalse( $matched, $path );
			return;
		}
		$this->assertTrue( $matched, $path );
		$this->assertSame( $captured, $m[1] );
	}

	public function test_markdown_rule_does_not_claim_jsonld(): void {
		// The .md catch-all from MMSAR_Server, copied: a change there that started matching
		// .jsonld would shadow this mirror, since both rules are registered at the top.
		$this->assertSame( 0, preg_match( '#^(?!\.well-known/|auth\.md|okf/)(.+)\.md/?$#', 'about.jsonld' ) );
	}

	public function test_only_published_unprotected_covered_posts_are_servable(): void {
		$ok = new WP_Post( (object) array( 'ID' => 1 ) );
		$this->assertTrue( MMSAR_JSONLD::post_is_servable( $ok ) );
		$this->assertFalse( MMSAR_JSONLD::post_is_servable( new WP_Post( (object) array( 'post_status' => 'draft' ) ) ) );
		$this->assertFalse( MMSAR_JSONLD::post_is_servable( new WP_Post( (object) array( 'post_password' => 'x' ) ) ) );
		$this->assertFalse( MMSAR_JSONLD::post_is_servable( new WP_Post( (object) array( 'post_type' => 'attachment' ) ) ) );
		$this->assertFalse( MMSAR_JSONLD::post_is_servable( null ) );
	}

	public function test_link_and_header_use_the_registered_alternate_relation(): void {
		$url = 'https://example.com/about.jsonld';
		$this->assertSame( '<link rel="alternate" type="application/ld+json" href="https://example.com/about.jsonld">' . "\n", MMSAR_JSONLD::head_link( $url ) );
		$this->assertSame( 'Link: <https://example.com/about.jsonld>; rel="alternate"; type="application/ld+json"', MMSAR_JSONLD::link_header( $url ) );
	}

	public function test_nothing_is_advertised_without_an_address(): void {
		$this->assertSame( '', MMSAR_JSONLD::head_link( '' ) );
		$this->assertSame( '', MMSAR_JSONLD::link_header( '' ) );
	}

	public function test_no_rel_schemamap_anywhere(): void {
		// The decision is to ship only registered relations. The class names schemamap in its
		// comments, so only code is read: every token that is not a comment.
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mmsar-jsonld.php' ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		$this->assertStringContainsString( 'rel="alternate"', $code );
		$this->assertStringNotContainsString( 'schemamap', strtolower( $code ) );
	}

	public function test_yoast_graph_is_served_as_is(): void {
		$yoast = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( array( '@type' => 'WebPage' ) ),
		);
		$this->assertSame( $yoast, MMSAR_JSONLD::document( $yoast, null ) );
	}

	public function test_plugin_node_alone_is_served_as_is(): void {
		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Article',
		);
		$this->assertSame( $node, MMSAR_JSONLD::document( null, $node ) );
		$this->assertSame( $node, MMSAR_JSONLD::document( array( '@context' => 'https://schema.org' ), $node ) );
	}

	public function test_both_become_one_graph_without_a_second_context(): void {
		$yoast = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( array( '@type' => 'Product' ) ),
		);
		$node  = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
		);
		$doc   = MMSAR_JSONLD::document( $yoast, $node );
		$this->assertSame( 'https://schema.org', $doc['@context'] );
		$this->assertCount( 2, $doc['@graph'] );
		$this->assertSame( array( '@type' => 'WebPage' ), $doc['@graph'][1] );
	}

	public function test_nothing_gives_no_document(): void {
		$this->assertNull( MMSAR_JSONLD::document( null, null ) );
		$this->assertNull( MMSAR_JSONLD::document( false, array() ) );
	}
}
