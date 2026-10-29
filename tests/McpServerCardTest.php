<?php
/**
 * The SEP-2127 Server Card and the overview's owner line (1.68.0).
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_MCP::spec_server_card() and MMSAR_MCP::owner_line().
 */
final class McpServerCardTest extends TestCase {

	/**
	 * A typical site.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private static function site( $over = array() ) {
		return array_merge(
			array(
				'host'     => 'miriamschwab.me',
				'title'    => 'MiriamSchwab.me',
				'version'  => '1.68.0',
				'endpoint' => 'https://miriamschwab.me/wp-json/mmsar/v1/mcp',
				'website'  => 'https://miriamschwab.me/',
				'icon'     => 'https://miriamschwab.me/icon.png',
				'versions' => array( '2026-07-28', '2025-06-18', '2025-03-26' ),
			),
			$over
		);
	}

	/**
	 * The card follows the v1 schema's required shape.
	 */
	public function test_card_shape() {
		$card = MMSAR_MCP::spec_server_card( self::site() );
		$this->assertSame( MMSAR_MCP::SERVER_CARD_SCHEMA, $card['$schema'] );
		$this->assertSame( 'me.miriamschwab/mmsar', $card['name'] );
		$this->assertMatchesRegularExpression( '#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $card['name'] );
		$this->assertSame( '1.68.0', $card['version'] );
		$this->assertSame( 'Search and read the published content of MiriamSchwab.me.', $card['description'] );
		$this->assertSame( array( '512x512' ), $card['icons'][0]['sizes'] );
		$this->assertSame( 'streamable-http', $card['remotes'][0]['type'] );
		$this->assertSame( array( '2026-07-28', '2025-06-18', '2025-03-26' ), $card['remotes'][0]['supportedProtocolVersions'] );
		$this->assertArrayNotHasKey( 'tools', $card );
		$this->assertArrayNotHasKey( 'resources', $card );
	}

	/**
	 * Limits and odd inputs.
	 */
	public function test_card_edges() {
		$long = MMSAR_MCP::spec_server_card( self::site( array( 'title' => str_repeat( 'Long name ', 20 ) ) ) );
		$this->assertLessThanOrEqual( 100, mb_strlen( $long['description'] ) );
		$this->assertLessThanOrEqual( 100, mb_strlen( $long['title'] ) );

		$bare = MMSAR_MCP::spec_server_card( self::site( array( 'icon' => '', 'title' => '', 'host' => 'WWW.Example.co.uk' ) ) );
		$this->assertSame( 'uk.co.example.www/mmsar', $bare['name'] );
		$this->assertArrayNotHasKey( 'icons', $bare );
		$this->assertArrayNotHasKey( 'title', $bare );
		$this->assertSame( 'Search and read the published content of www.example.co.uk.', $bare['description'] );
	}

	/**
	 * Owner line wording per kind.
	 */
	public function test_owner_line() {
		$this->assertSame(
			'Run by Miriam Schwab, Head of WordPress at Elementor. Call get_about for a bio, profiles and how to get in touch.',
			MMSAR_MCP::owner_line( array( 'kind' => 'person', 'name' => 'Miriam Schwab', 'role' => 'Head of WordPress at Elementor' ) )
		);
		$this->assertSame(
			'Run by Ada. Call get_about for a bio, profiles and how to get in touch.',
			MMSAR_MCP::owner_line( array( 'kind' => 'person', 'name' => 'Ada', 'role' => '' ) )
		);
		$this->assertSame(
			'Published by Acme. Call get_about for a bio, profiles and how to get in touch.',
			MMSAR_MCP::owner_line( array( 'kind' => 'organization', 'name' => 'Acme', 'role' => 'ignored' ) )
		);
		$this->assertSame( '', MMSAR_MCP::owner_line( array( 'kind' => 'site', 'name' => 'Blog' ) ) );
		$this->assertSame( '', MMSAR_MCP::owner_line( array( 'kind' => 'person', 'name' => '' ) ) );
	}
}
