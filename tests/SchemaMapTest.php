<?php
/**
 * The Schema Map and its robots.txt directives (1.60.0).
 *
 * The map is checked against NLWeb's Schema Feeds spec by parsing it as XML and reading it the way
 * a conforming consumer would: sitemap `url` entries, each with a `loc` and an `sf:contentType` in
 * the spec's namespace. A string comparison would pass a map no parser could read.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_xml' ) ) {
	/**
	 * Core's esc_xml(), reduced to what matters here: markup characters become entities.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_xml( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}

if ( ! function_exists( 'mmsar_get_enabled_post_types' ) ) {
	/**
	 * Post types the plugin covers.
	 *
	 * @return string[]
	 */
	function mmsar_get_enabled_post_types() {
		return array( 'post' );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * No posts, so last_modified() falls back to the current time.
	 *
	 * @param array $args Query.
	 * @return array
	 */
	function get_posts( $args = array() ) {
		return array();
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mmsar-nlweb.php';

/**
 * Schema Map tests.
 */
final class SchemaMapTest extends TestCase {

	/**
	 * Reset stubs.
	 */
	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * Parse a map the way a consumer would.
	 *
	 * @param string $xml Map.
	 * @return array[] Each with loc, lastmod, contentType.
	 */
	private function read_map( $xml ) {
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ), 'The map must be well-formed XML.' );
		$this->assertSame( 'urlset', $doc->documentElement->localName );
		$this->assertSame( 'http://www.sitemaps.org/schemas/sitemap/0.9', $doc->documentElement->namespaceURI );

		$out = array();
		foreach ( $doc->getElementsByTagNameNS( 'http://www.sitemaps.org/schemas/sitemap/0.9', 'url' ) as $url ) {
			$types = $url->getElementsByTagNameNS( 'http://schema.org/schemas/schemafeed/0.1', 'contentType' );
			$lm    = $url->getElementsByTagNameNS( 'http://www.sitemaps.org/schemas/sitemap/0.9', 'lastmod' );
			$type = $types->length ? $types->item( 0 )->textContent : null;
			// NLWeb's crawler reads the attribute instead; both must say the same thing.
			$this->assertSame( $type, $url->getAttribute( 'contentType' ), 'The contentType attribute must match the sf:contentType element.' );
			$out[] = array(
				'loc'         => $url->getElementsByTagNameNS( 'http://www.sitemaps.org/schemas/sitemap/0.9', 'loc' )->item( 0 )->textContent,
				'lastmod'     => $lm->length ? $lm->item( 0 )->textContent : null,
				'contentType' => $type,
			);
		}
		return $out;
	}

	/**
	 * The default map lists the RSS feed, typed as the spec's RSS content type, and nothing else.
	 */
	public function test_default_map_is_spec_shaped_and_lists_only_rss() {
		$map = $this->read_map( MMSAR_NLWeb::render_schema_map( MMSAR_NLWeb::schema_map_entries() ) );

		$this->assertCount( 1, $map );
		$this->assertSame( 'https://example.com/feed/', $map[0]['loc'] );
		$this->assertSame( 'structuredData/rss', $map[0]['contentType'] );
		$this->assertNotEmpty( $map[0]['lastmod'] );
	}

	/**
	 * A filtered entry is typed, a legacy RSS entry is translated, and anything untyped or
	 * relative is dropped rather than published as a feed a reader cannot use.
	 */
	public function test_filter_entries_are_validated() {
		add_filter(
			'mmsar_schema_map_feeds',
			function ( $entries ) {
				$entries[] = array(
					'loc'          => 'https://example.com/feeds/products.jsonl',
					'content_type' => 'structuredData/schema.org',
				);
				$entries[] = array(
					'loc'  => 'https://example.com/legacy.rss',
					'type' => 'application/rss+xml',
				);
				$entries[] = array(
					'loc'  => 'https://example.com/llms-full.txt',
					'type' => 'text/plain',
				);
				$entries[] = array(
					'loc'          => '/relative.jsonl',
					'content_type' => 'structuredData/schema.org',
				);
				$entries[] = 'not an entry';
				return $entries;
			}
		);

		$map = $this->read_map( MMSAR_NLWeb::render_schema_map( MMSAR_NLWeb::schema_map_entries() ) );

		$this->assertSame(
			array( 'https://example.com/feed/', 'https://example.com/feeds/products.jsonl', 'https://example.com/legacy.rss' ),
			array_column( $map, 'loc' )
		);
		$this->assertSame( 'structuredData/schema.org', $map[1]['contentType'] );
		$this->assertSame( 'structuredData/rss', $map[2]['contentType'] );
		$this->assertNull( $map[1]['lastmod'], 'lastmod is optional and omitted when not given.' );
	}

	/**
	 * Markup in a URL is escaped, so the map stays well-formed.
	 */
	public function test_values_are_escaped() {
		$xml = MMSAR_NLWeb::render_schema_map(
			array(
				array(
					'loc'          => 'https://example.com/feed?a=1&b=<2>',
					'content_type' => 'structuredData/rss',
					'lastmod'      => '',
				),
			)
		);
		$map = $this->read_map( $xml );
		$this->assertSame( 'https://example.com/feed?a=1&b=<2>', $map[0]['loc'] );
	}

	/**
	 * Yoast's map is advertised only when its route exists and its endpoint is switched on.
	 *
	 * One method, in order, because a class cannot be undefined once declared.
	 */
	public function test_yoast_map_detection() {
		$GLOBALS['wp_stub_options']['wpseo'] = array( 'enable_schema_aggregation_endpoint' => true );
		$this->assertSame( '', MMSAR_NLWeb::yoast_schemamap_url(), 'Switched on, but no route class: not served, not advertised.' );

		if ( ! class_exists( 'Yoast\WP\SEO\Schema_Aggregator\User_Interface\Schemamap_Xml_Rewrite_Integration' ) ) {
			eval( 'namespace Yoast\WP\SEO\Schema_Aggregator\User_Interface; class Schemamap_Xml_Rewrite_Integration {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares Yoast's route class partway through the test.
		}
		$this->assertSame( 'https://example.com/schemamap.xml', MMSAR_NLWeb::yoast_schemamap_url() );

		$GLOBALS['wp_stub_options']['wpseo'] = array( 'enable_schema_aggregation_endpoint' => false );
		$this->assertSame( '', MMSAR_NLWeb::yoast_schemamap_url(), 'Switched off.' );

		$GLOBALS['wp_stub_options']['wpseo'] = array( 'enable_schema_aggregation_endpoint' => '1' );
		$this->assertSame( '', MMSAR_NLWeb::yoast_schemamap_url(), 'Yoast stores a real boolean; anything else is not on.' );

		$GLOBALS['wp_stub_options']['wpseo'] = 'corrupt';
		$this->assertSame( '', MMSAR_NLWeb::yoast_schemamap_url() );

		unset( $GLOBALS['wp_stub_options']['wpseo'] );
		$this->assertSame( '', MMSAR_NLWeb::yoast_schemamap_url(), 'Option missing.' );
	}

	/**
	 * The robots.txt lines are on unless the owner has stored an explicit '0'.
	 */
	public function test_robots_setting_defaults_on() {
		$this->assertTrue( MMSAR_NLWeb::robots_enabled_in( array() ), 'Absent: on.' );
		$this->assertTrue( MMSAR_NLWeb::robots_enabled_in( false ), 'No option at all: on.' );
		$this->assertTrue( MMSAR_NLWeb::robots_enabled_in( array( 'schemamap_robots' => '1' ) ) );
		$this->assertFalse( MMSAR_NLWeb::robots_enabled_in( array( 'schemamap_robots' => '0' ) ) );
		$this->assertFalse( MMSAR_NLWeb::robots_enabled_in( array( 'schemamap_robots' => 0 ) ) );
	}

	/**
	 * Switched off, robots.txt is left exactly as it was.
	 */
	public function test_robots_setting_off_adds_nothing() {
		$GLOBALS['wp_stub_options']['llmmd_settings'] = array( 'schemamap_robots' => '0' );
		$robots = "User-agent: *\nAllow: /\n";
		$this->assertSame( $robots, MMSAR_NLWeb::add_schemamap_directive( $robots ) );

		$GLOBALS['wp_stub_options']['llmmd_settings'] = array( 'schemamap_robots' => '1' );
		$this->assertStringContainsString( 'Schemamap: https://example.com/schema-map.xml', MMSAR_NLWeb::add_schemamap_directive( $robots ) );
	}

	/**
	 * robots.txt gets one directive per map, own first, and an existing one is not repeated.
	 */
	public function test_robots_directives() {
		add_filter(
			'mmsar_schemamap_urls',
			function ( $urls ) {
				$urls[] = 'https://example.com/other-map.xml';
				$urls[] = 'https://example.com/other-map.xml';
				$urls[] = 'relative.xml';
				return $urls;
			}
		);

		$out = MMSAR_NLWeb::add_schemamap_directive( "User-agent: *\nAllow: /\n" );
		$this->assertSame(
			"User-agent: *\nAllow: /\n\nSchemamap: https://example.com/schema-map.xml\nSchemamap: https://example.com/other-map.xml\n",
			$out
		);

		// Already listed (any case, any spacing): left alone, the other one still added.
		$out = MMSAR_NLWeb::add_schemamap_directive( "User-agent: *\nschemamap:   https://example.com/schema-map.xml\n" );
		$this->assertSame( 1, substr_count( $out, 'https://example.com/schema-map.xml' ) );
		$this->assertStringContainsString( "Schemamap: https://example.com/other-map.xml\n", $out );

		// A different map already present must not suppress this plugin's own, as it did before 1.60.0.
		$out = MMSAR_NLWeb::add_schemamap_directive( "Schemamap: https://example.com/elsewhere.xml\n" );
		$this->assertStringContainsString( 'Schemamap: https://example.com/schema-map.xml', $out );

		// Everything already listed: unchanged.
		$full = "Schemamap: https://example.com/schema-map.xml\nSchemamap: https://example.com/other-map.xml\n";
		$this->assertSame( $full, MMSAR_NLWeb::add_schemamap_directive( $full ) );
	}
}
