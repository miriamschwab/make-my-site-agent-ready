<?php
/**
 * MCP protocol eras (1.68.0).
 *
 * The rules MMSAR_MCP uses to tell a 2026-07-28 request from a legacy one and to answer each in its
 * own shape: which message is modern, the header checks 2026-07-28 makes mandatory (version, method,
 * name, including the Base64 header encoding), and what is added to a modern result. The header
 * checks are a security boundary in the spec (they stop a gateway and the server acting on different
 * values), so each is tested on its failing side as well as its passing one.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_MCP_Era rules.
 */
final class McpEraTest extends TestCase {

	/**
	 * A modern message's params.
	 *
	 * @param array  $extra   Further params.
	 * @param string $version Declared version.
	 * @return array
	 */
	private static function params( $extra = array(), $version = '2026-07-28' ) {
		return array_merge(
			$extra,
			array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion' => $version,
					'io.modelcontextprotocol/clientInfo'      => array(
						'name'    => 'Test',
						'version' => '1',
					),
				),
			)
		);
	}

	/**
	 * Only a version in `params._meta` makes a message modern.
	 */
	public function test_declared_version() {
		$this->assertSame( '2026-07-28', MMSAR_MCP_Era::declared_version( self::params() ) );
		$this->assertSame( '', MMSAR_MCP_Era::declared_version( array( 'protocolVersion' => '2026-07-28' ) ) );
		$this->assertSame( '', MMSAR_MCP_Era::declared_version( array() ) );
		$this->assertSame( '', MMSAR_MCP_Era::declared_version( array( '_meta' => 'x' ) ) );
		$this->assertSame( '', MMSAR_MCP_Era::declared_version( array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => 7 ) ) ) );
		$this->assertSame( '', MMSAR_MCP_Era::declared_version( null ) );
	}

	/**
	 * Header values in the spec's Base64 sentinel form are decoded; anything else passes through.
	 *
	 * @return array[]
	 */
	public static function encoding_cases() {
		return array(
			'plain'               => array( 'search_content', 'search_content' ),
			'spec example'        => array( '=?base64?SGVsbG8sIOS4lueVjA==?=', 'Hello, 世界' ),
			'encoded sentinel'    => array( '=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?=', '=?base64?literal?=' ),
			'uppercase marker'    => array( '=?BASE64?eA==?=', '=?BASE64?eA==?=' ),
			'invalid base64'      => array( '=?base64?***?=', null ),
			'empty'               => array( '', '' ),
		);
	}

	/**
	 * Decoding `Mcp-Name`.
	 *
	 * @param string      $raw      Header value.
	 * @param string|null $expected Decoded value.
	 */
	#[DataProvider( 'encoding_cases' )]
	public function test_decode_header_value( $raw, $expected ) {
		$this->assertSame( $expected, MMSAR_MCP_Era::decode_header_value( $raw ) );
	}

	/**
	 * Header and version checks, one case per rule.
	 *
	 * @return array[]
	 */
	public static function validation_cases() {
		$ok = array(
			'mcp-protocol-version' => '2026-07-28',
			'mcp-method'           => 'tools/call',
			'mcp-name'             => 'get_about',
		);
		return array(
			'all present and matching'   => array( 'tools/call', array( 'name' => 'get_about' ), $ok, null ),
			'encoded name'               => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-name' => '=?base64?' . base64_encode( 'get_about' ) . '?=' ) ), null ),
			'list needs no name'         => array( 'tools/list', array(), array( 'mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list' ), null ),
			'read checks uri'            => array( 'resources/read', array( 'uri' => 'ui://mmsar/about.html' ), array( 'mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'ui://mmsar/about.html' ), null ),
			'version header missing'     => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-protocol-version' => '' ) ), -32020 ),
			'version header differs'     => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-protocol-version' => '2025-06-18' ) ), -32020 ),
			'method header missing'      => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-method' => '' ) ), -32020 ),
			'method header differs'      => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-method' => 'tools/list' ) ), -32020 ),
			'method case differs'        => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-method' => 'Tools/Call' ) ), -32020 ),
			'name header missing'        => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-name' => '' ) ), -32020 ),
			'name header differs'        => array( 'tools/call', array( 'name' => 'get_about' ), array_merge( $ok, array( 'mcp-name' => 'search_content' ) ), -32020 ),
			'read uri differs'           => array( 'resources/read', array( 'uri' => 'ui://mmsar/about.html' ), array( 'mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'ui://mmsar/search-results.html' ), -32020 ),
		);
	}

	/**
	 * A modern request is accepted only when its headers mirror its body.
	 *
	 * @param string   $method  Method.
	 * @param array    $extra   Params besides `_meta`.
	 * @param array    $headers Headers.
	 * @param int|null $code    Expected error code, or null for acceptance.
	 */
	#[DataProvider( 'validation_cases' )]
	public function test_validate( $method, $extra, $headers, $code ) {
		$failure = MMSAR_MCP_Era::validate( $method, self::params( $extra ), $headers );
		if ( null === $code ) {
			$this->assertNull( $failure );
			return;
		}
		$this->assertSame( $code, $failure['code'] );
		$this->assertSame( 400, $failure['status'] );
	}

	/**
	 * An unsupported version is refused with the versions this server does support.
	 */
	public function test_unsupported_version() {
		$failure = MMSAR_MCP_Era::validate( 'tools/list', self::params( array(), '2099-01-01' ), array( 'mcp-protocol-version' => '2099-01-01', 'mcp-method' => 'tools/list' ) );
		$this->assertSame( -32022, $failure['code'] );
		$this->assertSame( 400, $failure['status'] );
		$this->assertSame(
			array(
				'supported' => array( '2026-07-28' ),
				'requested' => '2099-01-01',
			),
			$failure['data']
		);
	}

	/**
	 * Every modern result says it is complete and names the server; only cacheable ones carry a TTL.
	 */
	public function test_decorate() {
		$info = array(
			'name'    => 'mmsar-x',
			'version' => '1.0',
		);

		$list = MMSAR_MCP_Era::decorate( array( 'tools' => array() ), 'tools/list', $info, 900000 );
		$this->assertSame( 'complete', $list['resultType'] );
		$this->assertSame( $info, $list['_meta']['io.modelcontextprotocol/serverInfo'] );
		$this->assertSame( 900000, $list['ttlMs'] );
		$this->assertSame( 'public', $list['cacheScope'] );

		$call = MMSAR_MCP_Era::decorate(
			array(
				'content' => array(),
				'_meta'   => array( 'keep' => 1 ),
			),
			'tools/call',
			$info,
			900000
		);
		$this->assertSame( 'complete', $call['resultType'] );
		$this->assertSame( 1, $call['_meta']['keep'] );
		$this->assertArrayNotHasKey( 'ttlMs', $call );
		$this->assertArrayNotHasKey( 'cacheScope', $call );

		$negative = MMSAR_MCP_Era::decorate( array(), 'server/discover', $info, -5 );
		$this->assertSame( 0, $negative['ttlMs'] );
	}
}
