<?php
/**
 * The `rel="nlweb"` discovery link and header (1.65.0).
 *
 * NLWeb's discovery signal is a `<link rel="nlweb">` in `<head>` plus a matching `Link` header.
 * Both must name the same /ask URL the endpoint registration publishes, and both must disappear
 * whenever /ask is not served: a filter switches it off, or a page already owns the /ask slug.
 * The feature toggle itself gates the callers (init() and mmsar_send_link_headers()), so with
 * NLWeb off neither method is reached.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

// Just enough of five WordPress functions for ask_url(), is_serving() and the two discovery
// builders. Declared through eval() so static analysis keeps reading WordPress's own signatures,
// which the plugin code is checked against; a plain declaration here replaces them for PHPStan.
// get_page_by_path() returns whatever a test put in $GLOBALS['nlweb_test_page_at_ask'] for `ask`.
foreach (
	array(
		'esc_url_raw'      => 'function esc_url_raw( $url ) { return (string) $url; }',
		'esc_url'          => 'function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, "UTF-8" ); }',
		'esc_attr__'       => 'function esc_attr__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES, "UTF-8" ); }',
		'get_post_types'   => 'function get_post_types( $args = array() ) { return array( "post", "page" ); }',
		'get_page_by_path' => 'function get_page_by_path( $path, $output = "OBJECT", $post_type = "page" ) { return ( "ask" === $path ) ? ( $GLOBALS["nlweb_test_page_at_ask"] ?? null ) : null; }',
	) as $nlweb_test_fn => $nlweb_test_code
) {
	if ( ! function_exists( $nlweb_test_fn ) ) {
		eval( $nlweb_test_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- See above.
	}
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

require_once dirname( __DIR__ ) . '/includes/class-mmsar-nlweb.php';

/**
 * NLWeb discovery link tests.
 */
final class NLWebDiscoveryTest extends TestCase {

	/**
	 * Reset stubs.
	 */
	protected function setUp(): void {
		wp_stub_reset();
		$GLOBALS['nlweb_test_page_at_ask'] = null;
	}

	public function test_header_names_the_ask_url_with_rel_nlweb(): void {
		$this->assertSame( 'Link: <https://example.com/ask>; rel="nlweb"', MMSAR_NLWeb::link_header() );
	}

	public function test_head_link_names_the_ask_url_with_rel_nlweb(): void {
		$tag = MMSAR_NLWeb::head_link();
		$this->assertStringStartsWith( '<link rel="nlweb" href="https://example.com/ask"', $tag );
		$this->assertStringContainsString( 'title="Ask this site"', $tag );
	}

	public function test_header_and_link_use_the_registered_endpoint_url(): void {
		$GLOBALS['wp_stub_home_url'] = 'https://example.com/blog';
		$this->assertSame( 'https://example.com/blog/ask', MMSAR_NLWeb::ask_url() );
		$this->assertStringContainsString( '<' . MMSAR_NLWeb::ask_url() . '>', MMSAR_NLWeb::link_header() );
		$this->assertStringContainsString( 'href="' . MMSAR_NLWeb::ask_url() . '"', MMSAR_NLWeb::head_link() );
	}

	public function test_nothing_is_advertised_when_the_filter_switches_ask_off(): void {
		add_filter(
			'mmsar_nlweb_is_serving',
			static function () {
				return false;
			}
		);
		$this->assertSame( '', MMSAR_NLWeb::link_header() );
		$this->assertSame( '', MMSAR_NLWeb::head_link() );
	}

	public function test_nothing_is_advertised_when_a_page_owns_the_ask_slug(): void {
		$GLOBALS['nlweb_test_page_at_ask'] = (object) array( 'ID' => 12 );
		$this->assertSame( '', MMSAR_NLWeb::link_header() );
		$this->assertSame( '', MMSAR_NLWeb::head_link() );
	}

	public function test_init_hooks_the_head_link(): void {
		MMSAR_NLWeb::init();
		$this->assertNotEmpty( $GLOBALS['wp_stub_filters']['wp_head'] ?? array() );
	}
}
