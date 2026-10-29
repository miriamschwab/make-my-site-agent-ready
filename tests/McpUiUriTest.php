<?php
/**
 * Fingerprinted MCP Apps template addresses (1.68.0).
 *
 * Hosts cache a template by its address and do not re-read it, so the address carries a
 * fingerprint of the HTML: a changed card gets a new address the host has to fetch, and an old
 * address still resolves to the template it named.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_MCP template URIs.
 */
final class McpUiUriTest extends TestCase {

	/**
	 * The fingerprint is stable for the same HTML and changes with it.
	 */
	public function test_fingerprint_follows_content() {
		$a = MMSAR_MCP::ui_template_uri( MMSAR_MCP::UI_ABOUT, '<p>one</p>' );
		$this->assertSame( $a, MMSAR_MCP::ui_template_uri( MMSAR_MCP::UI_ABOUT, '<p>one</p>' ) );
		$this->assertNotSame( $a, MMSAR_MCP::ui_template_uri( MMSAR_MCP::UI_ABOUT, '<p>two</p>' ) );
		$this->assertMatchesRegularExpression( '#^ui://mmsar/about\.html\?v=[0-9a-f]{12}$#', $a );
	}

	/**
	 * Every form of an address leads back to its template.
	 */
	public function test_base_strips_fingerprint() {
		$versioned = MMSAR_MCP::ui_template_uri( MMSAR_MCP::UI_RESULTS, 'x' );
		$this->assertSame( MMSAR_MCP::UI_RESULTS, MMSAR_MCP::ui_template_base( $versioned ) );
		$this->assertSame( MMSAR_MCP::UI_RESULTS, MMSAR_MCP::ui_template_base( MMSAR_MCP::UI_RESULTS ) );
		$this->assertSame( MMSAR_MCP::UI_ABOUT, MMSAR_MCP::ui_template_base( MMSAR_MCP::UI_ABOUT . '?v=anything' ) );
		$this->assertSame( '', MMSAR_MCP::ui_template_base( '' ) );
	}

	/**
	 * A resources/read is logged with its address and how it relates to today's.
	 */
	public function test_resource_log_argument() {
		$current = array(
			'ui://mmsar/about.html'        => 'ui://mmsar/about.html?v=aaaaaaaaaaaa',
			'https://example.com/llms.txt' => 'https://example.com/llms.txt',
		);
		$this->assertSame( 'uri=ui://mmsar/about.html?v=aaaaaaaaaaaa (current)', MMSAR_MCP::resource_log_argument( 'ui://mmsar/about.html?v=aaaaaaaaaaaa', $current ) );
		$this->assertSame( 'uri=ui://mmsar/about.html?v=bbbbbbbbbbbb (stale)', MMSAR_MCP::resource_log_argument( 'ui://mmsar/about.html?v=bbbbbbbbbbbb', $current ) );
		$this->assertSame( 'uri=ui://mmsar/about.html (unversioned)', MMSAR_MCP::resource_log_argument( 'ui://mmsar/about.html', $current ) );
		$this->assertSame( 'uri=https://example.com/llms.txt', MMSAR_MCP::resource_log_argument( 'https://example.com/llms.txt', $current ) );
		$this->assertSame( 'uri=ui://mmsar/about.html?(other)', MMSAR_MCP::resource_log_argument( 'ui://mmsar/about.html?v=<script>', $current ) );
		$this->assertSame( 'uri=(unknown)', MMSAR_MCP::resource_log_argument( 'https://evil.example/x', $current ) );
		$this->assertSame( '', MMSAR_MCP::resource_log_argument( '', $current ) );
		$this->assertSame( '', MMSAR_MCP::resource_log_argument( array( 'x' ), $current ) );
	}
}
