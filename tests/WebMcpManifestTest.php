<?php
/**
 * /.well-known/webmcp.json (1.58.0).
 *
 * The file has to list exactly what a page registers, so the tests hold three things to that: the
 * tool list built from the server card matches the bridge's own shaping (read-only only, both
 * hints, get_content's url optional), the text the bridge adds is the text the file says, and the
 * file is served only when every switch it depends on is on. Real HTTP (served, 404 when off) is
 * checked on the clone; see the handoff.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'mmsar_feature_enabled' ) ) {
	/**
	 * The plugin's feature switch, reduced to what record() asks: is the log on.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	function mmsar_feature_enabled( $feature ) {
		return 'agent_log' === $feature;
	}
}

if ( ! defined( 'MMSAR_VERSION' ) ) {
	define( 'MMSAR_VERSION', '9.9.9-test' );
}

/**
 * The WebMCP tool list and its document.
 */
final class WebMcpManifestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
	}

	/**
	 * A server card shaped like the real one, plus the cases the bridge must skip.
	 *
	 * @return array
	 */
	private static function card(): array {
		return array(
			'tools' => array(
				array(
					'name'        => 'search_content',
					'title'       => 'Search content',
					'description' => 'Search.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'query' => array( 'type' => 'string' ) ),
						'required'   => array( 'query' ),
					),
					'annotations' => array( 'readOnlyHint' => true ),
				),
				array(
					'name'        => 'get_content',
					'title'       => 'Get content',
					'description' => 'Read a page.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'url'      => array( 'type' => 'string' ),
							'sections' => array( 'type' => 'string' ),
						),
						'required'   => array( 'url', 'sections' ),
					),
					'annotations' => array( 'readOnlyHint' => true ),
				),
				// Not read-only: the bridge never registers it, so the file must not list it.
				array(
					'name'        => 'send_message',
					'description' => 'Writes.',
					'inputSchema' => array( 'type' => 'object' ),
					'annotations' => array( 'readOnlyHint' => false ),
				),
				// No annotations at all: skipped by the bridge too.
				array(
					'name'        => 'bare_tool',
					'description' => 'Bare.',
					'inputSchema' => array( 'type' => 'object' ),
				),
				// A truthy value that is not `true`: the bridge tests `=== true`.
				array(
					'name'        => 'stringly',
					'description' => 'Odd.',
					'annotations' => array( 'readOnlyHint' => 'yes' ),
				),
			),
		);
	}

	public function test_only_tools_the_bridge_registers_are_listed(): void {
		$names = array_column( MMSAR_WebMCP::tools_from_card( self::card() ), 'name' );
		$this->assertSame( array( 'search_content', 'get_content' ), $names );
	}

	public function test_each_tool_is_shaped_as_registered(): void {
		$tools = MMSAR_WebMCP::tools_from_card( self::card() );
		$this->assertSame(
			array(
				'name'        => 'search_content',
				'title'       => 'Search content',
				'description' => 'Search.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
					'required'   => array( 'query' ),
				),
				'annotations' => array(
					'readOnlyHint'         => true,
					'untrustedContentHint' => true,
				),
			),
			$tools[0]
		);
	}

	public function test_get_content_url_is_optional_in_the_browser(): void {
		$tools = MMSAR_WebMCP::tools_from_card( self::card() );
		$this->assertSame( array( 'sections' ), $tools[1]['inputSchema']['required'] );
		$this->assertSame( 'Read a page.' . MMSAR_WebMCP::GET_CONTENT_NOTE, $tools[1]['description'] );
	}

	/**
	 * The bridge writes `required` even when the card has none, and so must the file.
	 */
	public function test_get_content_without_required_gets_an_empty_list(): void {
		$card                                       = self::card();
		$card['tools'][1]['inputSchema']['required'] = null;
		unset( $card['tools'][1]['inputSchema']['required'] );
		$tools = MMSAR_WebMCP::tools_from_card( $card );
		$this->assertSame( array(), $tools[1]['inputSchema']['required'] );
	}

	public function test_a_card_without_tools_lists_none(): void {
		$this->assertSame( array(), MMSAR_WebMCP::tools_from_card( array() ) );
	}

	/**
	 * The PHP shaping restates assets/webmcp.js. These are the strings the two must share.
	 */
	public function test_the_bridge_script_shapes_tools_the_same_way(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/webmcp.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own script in a test.
		$this->assertStringContainsString( "'" . MMSAR_WebMCP::GET_CONTENT_NOTE . "'", $js );
		$this->assertStringContainsString( 'untrustedContentHint: true', $js );
		$this->assertStringContainsString( 'tool.annotations.readOnlyHint === true', $js );
		$this->assertStringContainsString( "key !== 'url'", $js );
	}

	public function test_document_lists_the_tools_and_where_they_come_from(): void {
		$tools = MMSAR_WebMCP::tools_from_card( self::card() );
		$doc   = MMSAR_WebMCP::build_manifest( $tools, 'Example Site', 'mmsar-example-site' );

		$this->assertSame( $tools, $doc['tools'] );
		$this->assertSame( 'mmsar-example-site', $doc['name'] );
		$this->assertSame( 'Example Site', $doc['title'] );
		$this->assertSame( MMSAR_VERSION, $doc['version'] );
		$this->assertSame( 'document.modelContext', $doc['webmcp']['api'] );
		$this->assertSame( MMSAR_WebMCP::script_url(), $doc['webmcp']['script'] );
		$this->assertStringContainsString( '/mmsar-webmcp/' . MMSAR_VERSION . '/bridge', $doc['webmcp']['script'] );
		$this->assertSame( MMSAR_WebMCP::SPEC_URL, $doc['webmcp']['specification'] );
		$this->assertStringEndsWith( '/.well-known/mcp/server-card.json', $doc['mcpServerCard'] );
		$this->assertStringContainsString( 'not part of the WebMCP specification', $doc['description'] );
	}

	public function test_document_is_valid_json_with_object_schemas(): void {
		$doc  = MMSAR_WebMCP::build_manifest( MMSAR_WebMCP::tools_from_card( self::card() ), 'Example', 'mmsar-example' );
		$json = wp_json_encode( $doc );
		$this->assertIsString( $json );
		$back = json_decode( $json, true );
		$this->assertSame( 'object', $back['tools'][0]['inputSchema']['type'] );
	}

	public function test_filter_can_change_the_document(): void {
		add_filter(
			'mmsar_webmcp_manifest',
			static function ( $doc ) {
				$doc['extra'] = 1;
				return $doc;
			}
		);
		$doc = MMSAR_WebMCP::build_manifest( array(), 'Example', 'mmsar-example' );
		$this->assertSame( 1, $doc['extra'] );
	}

	/**
	 * @param bool $webmcp   WebMCP feature.
	 * @param bool $mcp      MCP server feature.
	 * @param bool $manifest webmcp.json feature.
	 * @param bool $served   Expected.
	 */
	#[DataProvider( 'switches' )]
	public function test_served_only_with_every_switch_on( bool $webmcp, bool $mcp, bool $manifest, bool $served ): void {
		$this->assertSame( $served, MMSAR_WebMCP::manifest_served( $webmcp, $mcp, $manifest ) );
	}

	/**
	 * @return array<string, array{0:bool,1:bool,2:bool,3:bool}>
	 */
	public static function switches(): array {
		return array(
			'all on'                 => array( true, true, true, true ),
			'file switched off'      => array( true, true, false, false ),
			'WebMCP off'             => array( false, true, true, false ),
			'MCP server off'         => array( true, false, true, false ),
			'everything off'         => array( false, false, false, false ),
			'only the file on'       => array( false, false, true, false ),
		);
	}

	/**
	 * With WebMCP off (the test feature stub has only the log on), the address is not claimed: no
	 * query var, so WordPress answers it exactly as before.
	 */
	public function test_off_registers_no_query_var(): void {
		$this->assertFalse( MMSAR_WebMCP::manifest_enabled() );
		$this->assertNotContains( MMSAR_WebMCP::MANIFEST_QUERY_VAR, MMSAR_WebMCP::add_query_vars( array() ) );
	}

	public function test_requests_are_logged_under_their_own_surface(): void {
		$this->assertSame( 'webmcp.json', MMSAR_Agent_Log::SURFACE_WEBMCP_MANIFEST );
	}
}
