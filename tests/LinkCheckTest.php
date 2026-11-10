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
		$this->assertStringContainsString( "\tHeader always merge Link \"<https://example.com/.well-known/api-catalog>; rel=\\\"api-catalog\\\"\"", $snippets['apache'] );
		$this->assertStringContainsString( '<IfModule mod_headers.c>', $snippets['apache'] );
		$this->assertStringContainsString( "add_header Link '<https://example.com/auth.md>; rel=\"service-doc\"; type=\"text/markdown\"' always;", $snippets['nginx'] );
		$this->assertSame( implode( ', ', $links ), $snippets['cloudflare'] );
		// A single site gets no <If> and no scope note.
		$this->assertStringNotContainsString( '<If ', $snippets['apache'] );
		$this->assertSame( '', $snippets['scope'] );
	}

	/**
	 * `Header always merge`, never plain `Header merge`, which duplicates every link on PHP-FPM
	 * (Henk van den Bor, issue #1, 2026-11-08).
	 */
	public function test_apache_lines_always_merge(): void {
		$links = MMSAR_Link_Check::parse_links( self::LIVE );
		foreach ( array( array(), self::subdomain_scope(), self::subdirectory_scope( '/site2/' ) ) as $scope ) {
			$apache = MMSAR_Link_Check::snippets( array_slice( $links, 0, 3 ), $scope )['apache'];
			$this->assertSame( 3, substr_count( $apache, 'Header always merge Link ' ) );
			$this->assertDoesNotMatchRegularExpression( '/Header merge /', $apache );
		}
	}

	/**
	 * A shortlink is per page, like canonical and alternate. Core's wp-json/ link is sitewide.
	 */
	public function test_shortlink_is_per_page_and_wp_json_is_sitewide(): void {
		$this->assertFalse( MMSAR_Link_Check::is_sitewide( '<https://example.com/?p=42>; rel=shortlink' ) );
		$this->assertFalse( MMSAR_Link_Check::is_sitewide( '<https://example.com/?p=42>; rel="shortlink"' ) );
		$this->assertTrue( MMSAR_Link_Check::is_sitewide( '<https://example.com/wp-json/>; rel="https://api.w.org/"' ) );

		// Henk's homepage: the shortlink drops out of the lines, wp-json/ stays.
		$header = '<https://example.com/wp-json/>; rel="https://api.w.org/", <https://example.com/>; rel=shortlink, <https://example.com/llms.txt>; rel="describedby"; type="text/plain"';
		$out    = MMSAR_Link_Check::evaluate( MMSAR_Link_Check::parse_links( $header ), array() );
		$this->assertSame(
			array( '<https://example.com/wp-json/>; rel="https://api.w.org/"', '<https://example.com/llms.txt>; rel="describedby"; type="text/plain"' ),
			$out['links']
		);
	}

	/**
	 * A site on its own host in a network (subdomain, or mapped to its own domain): host only.
	 */
	public function test_multisite_subdomain_is_scoped_by_host(): void {
		$links    = array_slice( MMSAR_Link_Check::parse_links( self::LIVE ), 0, 2 );
		$snippets = MMSAR_Link_Check::snippets( $links, self::subdomain_scope() );
		$expected = implode(
			"\n",
			array(
				'# BEGIN Make My Site Agent-Ready Link headers',
				'<IfModule mod_headers.c>',
				"\t<If \"%{HTTP_HOST} == 'webtaurus.nl'\">",
				"\t\tHeader always merge Link \"<https://example.com/.well-known/api-catalog>; rel=\\\"api-catalog\\\"\"",
				"\t\tHeader always merge Link \"<https://example.com/auth.md>; rel=\\\"service-doc\\\"; type=\\\"text/markdown\\\"\"",
				"\t</If>",
				'</IfModule>',
				'# END Make My Site Agent-Ready Link headers',
			)
		);
		$this->assertSame( $expected, $snippets['apache'] );
		$this->assertSame( 'webtaurus.nl', $snippets['scope'] );
		$this->assertStringContainsString( 'server block for webtaurus.nl', $snippets['nginx'] );
	}

	/**
	 * A site under a path on a shared host: host and path.
	 */
	public function test_multisite_subdirectory_site_is_scoped_by_path(): void {
		$condition = MMSAR_Link_Check::apache_condition( self::subdirectory_scope( '/site2/' ) );
		$this->assertSame( "%{HTTP_HOST} == 'example.com' && %{REQUEST_URI} =~ m#^/site2(/|\$)#", $condition );

		$snippets = MMSAR_Link_Check::snippets( array( '<https://example.com/site2/llms.txt>; rel="describedby"' ), self::subdirectory_scope( '/site2/' ) );
		$this->assertSame( 'example.com/site2', $snippets['scope'] );
		$this->assertStringContainsString( 'location /site2/', $snippets['nginx'] );

		// The pattern matches the site and nothing that merely starts with its name.
		$pattern = '#^/site2(/|$)#';
		$this->assertSame( 1, preg_match( $pattern, '/site2/' ) );
		$this->assertSame( 1, preg_match( $pattern, '/site2' ) );
		$this->assertSame( 0, preg_match( $pattern, '/site20/' ) );
	}

	/**
	 * The root site of a subdirectory network: its host, minus every other site's path.
	 */
	public function test_multisite_subdirectory_root_site_excludes_the_others(): void {
		$scope     = self::subdirectory_scope( '/' );
		$condition = MMSAR_Link_Check::apache_condition( $scope );
		$this->assertSame( "%{HTTP_HOST} == 'example.com' && %{REQUEST_URI} !~ m#^(/site2|/shop\.old)(/|\$)#", $condition );
		$this->assertSame( 'example.com', MMSAR_Link_Check::snippets( array(), $scope )['scope'] );
		$this->assertStringContainsString( 'use the Apache or Cloudflare lines', MMSAR_Link_Check::snippets( array(), $scope )['nginx'] );
	}

	/**
	 * Not a network: no condition, whatever else the scope says.
	 */
	public function test_single_site_has_no_condition(): void {
		$this->assertSame( '', MMSAR_Link_Check::apache_condition( array() ) );
		$this->assertSame(
			'',
			MMSAR_Link_Check::apache_condition(
				array(
					'multisite' => false,
					'host'      => 'example.com',
					'path'      => '/',
				)
			)
		);
	}

	/**
	 * A site on its own host, as network_scope() reports it.
	 *
	 * @return array
	 */
	private static function subdomain_scope(): array {
		return array(
			'multisite'   => true,
			'host'        => 'webtaurus.nl',
			'path'        => '/',
			'other_paths' => array(),
		);
	}

	/**
	 * A site on a subdirectory network whose other sites are /site2/ and /shop.old/.
	 *
	 * @param string $path This site's path.
	 * @return array
	 */
	private static function subdirectory_scope( string $path ): array {
		return array(
			'multisite'   => true,
			'host'        => 'example.com',
			'path'        => $path,
			'other_paths' => array_values( array_diff( array( '/site2/', '/shop.old/' ), array( $path ) ) ),
		);
	}
}
