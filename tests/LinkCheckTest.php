<?php
/**
 * The Link header check's reading of the two responses (GitHub issue #1).
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Link_Check's pure parts: parsing, judging and the server lines.
 */
final class LinkCheckTest extends TestCase {

	/**
	 * The homepage's header as live sent it on 2026-10-31, trimmed to four links.
	 */
	const LIVE = '<https://example.com/.well-known/api-catalog>; rel="api-catalog", <https://example.com/auth.md>; rel="service-doc"; type="text/markdown", <https://example.com/llms.txt>; rel="describedby"; type="text/plain", <https://example.com/index.md>; rel="alternate"; type="text/markdown"';

	public function test_parses_every_link_in_a_joined_header(): void {
		$links = MMSAR_Link_Check::parse_links( self::LIVE );
		$this->assertSame(
			array(
				'<https://example.com/.well-known/api-catalog>; rel="api-catalog"',
				'<https://example.com/auth.md>; rel="service-doc"; type="text/markdown"',
				'<https://example.com/llms.txt>; rel="describedby"; type="text/plain"',
				'<https://example.com/index.md>; rel="alternate"; type="text/markdown"',
			),
			$links
		);
	}

	public function test_a_comma_inside_a_url_does_not_split_it(): void {
		$links = MMSAR_Link_Check::parse_links( '<https://example.com/a,b>; rel="service-desc", <https://example.com/c>; rel="api-catalog"' );
		$this->assertCount( 2, $links );
		$this->assertStringStartsWith( '<https://example.com/a,b>', $links[0] );
	}

	public function test_an_empty_header_has_no_links(): void {
		$this->assertSame( array(), MMSAR_Link_Check::parse_links( '' ) );
	}

	public function test_per_page_links_are_not_sitewide(): void {
		$this->assertFalse( MMSAR_Link_Check::is_sitewide( '<https://example.com/index.md>; rel="alternate"; type="text/markdown"' ) );
		$this->assertFalse( MMSAR_Link_Check::is_sitewide( '<https://example.com/>; rel="canonical"' ) );
		$this->assertTrue( MMSAR_Link_Check::is_sitewide( '<https://example.com/.well-known/api-catalog>; rel="api-catalog"' ) );
	}

	public function test_all_links_on_both_requests_passes(): void {
		$links = MMSAR_Link_Check::parse_links( self::LIVE );
		$out   = MMSAR_Link_Check::evaluate( $links, $links );
		$this->assertSame( 'pass', $out['status'] );
		$this->assertCount( 3, $out['links'], 'The alternate link is left out of what is judged.' );
		$this->assertSame( array(), $out['missing'] );
	}

	public function test_a_cache_that_drops_every_header_fails(): void {
		$out = MMSAR_Link_Check::evaluate( MMSAR_Link_Check::parse_links( self::LIVE ), array() );
		$this->assertSame( 'fail', $out['status'] );
		$this->assertCount( 3, $out['missing'] );
	}

	public function test_a_missing_per_page_link_alone_still_passes(): void {
		$control = MMSAR_Link_Check::parse_links( self::LIVE );
		$plain   = array_slice( $control, 0, 3 );
		$this->assertSame( 'pass', MMSAR_Link_Check::evaluate( $control, $plain )['status'] );
	}

	public function test_some_missing_is_partial(): void {
		$control = MMSAR_Link_Check::parse_links( self::LIVE );
		$out     = MMSAR_Link_Check::evaluate( $control, array( $control[0] ) );
		$this->assertSame( 'partial', $out['status'] );
		$this->assertSame( array( $control[1], $control[2] ), $out['missing'] );
	}

	public function test_reordered_parameters_still_match(): void {
		$control = array( '<https://example.com/auth.md>; rel="service-doc"; type="text/markdown"' );
		$plain   = array( '<https://example.com/auth.md>; type="text/markdown"; rel="service-doc"' );
		$this->assertSame( 'pass', MMSAR_Link_Check::evaluate( $control, $plain )['status'] );
	}

	public function test_no_discovery_links_is_nothing_to_check(): void {
		$out = MMSAR_Link_Check::evaluate( array( '<https://example.com/index.md>; rel="alternate"; type="text/markdown"' ), array() );
		$this->assertSame( 'none', $out['status'] );
	}

	public function test_server_lines_carry_every_link(): void {
		$links    = array_slice( MMSAR_Link_Check::parse_links( self::LIVE ), 0, 2 );
		$snippets = MMSAR_Link_Check::snippets( $links );
		$this->assertStringContainsString( 'Header merge Link "<https://example.com/.well-known/api-catalog>; rel=\"api-catalog\""', $snippets['apache'] );
		$this->assertStringContainsString( '<IfModule mod_headers.c>', $snippets['apache'] );
		$this->assertStringContainsString( "add_header Link '<https://example.com/auth.md>; rel=\"service-doc\"; type=\"text/markdown\"' always;", $snippets['nginx'] );
		$this->assertSame( implode( ', ', $links ), $snippets['cloudflare'] );
	}
}
