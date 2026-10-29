<?php
/**
 * The about card's contact form (1.68.0): routing, the send path, prefill.
 *
 * The send tool is the only tool on this server that changes anything, so the rules that decide
 * when it exists are tested on both sides.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_MCP contact form helpers.
 */
final class McpContactFormTest extends TestCase {

	/**
	 * A 2026-07-28 request's params, optionally declaring MCP Apps.
	 *
	 * @param bool $ui Whether to declare the extension.
	 * @return array
	 */
	private static function params( $ui ) {
		$caps = $ui ? array( 'extensions' => array( 'io.modelcontextprotocol/ui' => array( 'mimeTypes' => array( 'text/html;profile=mcp-app' ) ) ) ) : array( 'extensions' => array() );
		return array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities' => $caps,
			),
		);
	}

	/**
	 * Only a declared MCP Apps extension counts.
	 */
	public function test_client_has_ui() {
		$this->assertTrue( MMSAR_MCP::client_has_ui( self::params( true ) ) );
		$this->assertFalse( MMSAR_MCP::client_has_ui( self::params( false ) ) );
		$this->assertFalse( MMSAR_MCP::client_has_ui( array() ) );
		$this->assertFalse( MMSAR_MCP::client_has_ui( array( '_meta' => array( 'io.modelcontextprotocol/clientCapabilities' => 'x' ) ) ) );
		$this->assertFalse( MMSAR_MCP::client_has_ui( null ) );
	}

	/**
	 * Mode decisions.
	 *
	 * @return array[]
	 */
	public static function mode_cases() {
		$api   = 'https://example.com/wp-json/me/v1/contact';
		$route = '/me/v1/contact';
		return array(
			'no endpoint, no form'           => array( '', $route, true, true, '' ),
			'tool off: direct'               => array( $api, $route, false, true, 'direct' ),
			'tool on, client has UI: tool'   => array( $api, $route, true, true, 'tool' ),
			'tool on, client without UI'     => array( $api, $route, true, false, 'direct' ),
			'tool on, endpoint not on site'  => array( $api, '', true, true, 'direct' ),
		);
	}

	/**
	 * How the form sends.
	 *
	 * @param string $api      Endpoint.
	 * @param string $route    Route.
	 * @param bool   $enabled  Tool switched on.
	 * @param bool   $has_ui   Client declared UI.
	 * @param string $expected Mode.
	 */
	#[DataProvider( 'mode_cases' )]
	public function test_pick_contact_mode( $api, $route, $enabled, $has_ui, $expected ) {
		$this->assertSame( $expected, MMSAR_MCP::pick_contact_mode( $api, $route, $enabled, $has_ui ) );
	}

	/**
	 * Only this site's own REST routes can be called from inside WordPress.
	 */
	public function test_contact_route() {
		$rest = 'https://example.com/wp-json/';
		$this->assertSame( '/me/v1/contact', MMSAR_MCP::contact_route( 'https://example.com/wp-json/me/v1/contact', $rest ) );
		$this->assertSame( '/me/v1/contact', MMSAR_MCP::contact_route( 'https://example.com/wp-json/me/v1/contact/', 'https://example.com/wp-json' ) );
		$this->assertSame( '', MMSAR_MCP::contact_route( 'https://other.com/wp-json/me/v1/contact', $rest ) );
		$this->assertSame( '', MMSAR_MCP::contact_route( 'https://example.com/wp-json/', $rest ) );
		$this->assertSame( '', MMSAR_MCP::contact_route( 'https://example.com/wp-json/contact', $rest ) );
		$this->assertSame( '', MMSAR_MCP::contact_route( 'https://example.com/wp-json/me/v1/contact?x=1', $rest ) );
		$this->assertSame( '', MMSAR_MCP::contact_route( '', $rest ) );
	}

	/**
	 * The CSP origin.
	 */
	public function test_origin_of() {
		$this->assertSame( 'https://example.com', MMSAR_MCP::origin_of( 'https://Example.com/wp-json/me/v1/contact' ) );
		$this->assertSame( 'http://site.local:8080', MMSAR_MCP::origin_of( 'http://site.local:8080/x' ) );
		$this->assertSame( '', MMSAR_MCP::origin_of( 'javascript:alert(1)' ) );
		$this->assertSame( '', MMSAR_MCP::origin_of( '' ) );
	}

	/**
	 * A draft keeps known fields only, as plain capped text.
	 */
	public function test_sanitize_draft() {
		$this->assertSame(
			array(
				'name'    => 'Ada Lovelace',
				'subject' => 'Podcast',
				'message' => "Hello,\nwould you join?",
			),
			MMSAR_MCP::sanitize_draft(
				array(
					'name'    => "  Ada \t Lovelace ",
					'email'   => '',
					'subject' => "Pod\x07cast",
					'message' => "Hello,\nwould you join?  ",
					'extra'   => 'dropped',
				)
			)
		);
		$this->assertSame( 5000, mb_strlen( MMSAR_MCP::sanitize_draft( array( 'message' => str_repeat( 'é', 6000 ) ) )['message'] ) );
		$this->assertSame( array(), MMSAR_MCP::sanitize_draft( 'nope' ) );
		$this->assertSame( array(), MMSAR_MCP::sanitize_draft( array( 'name' => array( 'x' ) ) ) );
	}

	/**
	 * The log keeps which draft fields were filled in, never their text.
	 */
	public function test_draft_log_argument() {
		$this->assertSame( 'draft=subject,message', MMSAR_MCP::draft_log_argument( array( 'draft' => array( 'subject' => 'Podcast', 'message' => 'Hi', 'extra' => 'x' ) ) ) );
		$this->assertSame( 'draft=none', MMSAR_MCP::draft_log_argument( array( 'draft' => array( 'name' => '   ' ) ) ) );
		$this->assertSame( '', MMSAR_MCP::draft_log_argument( array() ) );
		$this->assertSame( '', MMSAR_MCP::draft_log_argument( 'x' ) );
	}
}
