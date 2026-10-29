<?php
/**
 * Which era of MCP a request speaks, and what that era requires of the answer.
 *
 * MCP 2026-07-28 removed the `initialize` handshake. A "modern" request carries its protocol
 * version, client identity and capabilities in `params._meta` on every call; a "legacy" request
 * (2025-11-25 and earlier) relies on the handshake. Following the plugin's rule for protocols that
 * change shape, each caller is answered in the shape it sent: a legacy request gets exactly the
 * response it always got, and a modern one gets what 2026-07-28 requires.
 *
 * | Concern               | Legacy (no `_meta` version)    | Modern (2026-07-28)                              |
 * |-----------------------|--------------------------------|--------------------------------------------------|
 * | Version               | `initialize`                   | `_meta` + `MCP-Protocol-Version`, every request  |
 * | Unsupported version   | answered with ours             | 400, -32022, `data.supported`                    |
 * | Headers               | not checked                    | `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` |
 * |                       |                                | must match the body; else 400, -32020            |
 * | Unknown method        | 200, -32601                    | 404, -32601                                      |
 * | Results               | unchanged                      | `resultType`, `_meta` serverInfo, and on the     |
 * |                       |                                | cacheable ones `ttlMs` + `cacheScope`            |
 *
 * `server/discover` is answered in both eras: a dual-era client sends it first as its probe, and a
 * server that answered "unknown method" there was telling every such client it speaks only the
 * old protocol.
 *
 * `ttlMs` is the part that matters most in practice. Without it, a client decides for itself how
 * long to keep a tool list and the MCP Apps templates; Claude was seen keeping both across a
 * plugin update, so a new tool never appeared until the connector was re-added.
 *
 * Everything here is pure and tested (`tests/McpEraTest.php`); MMSAR_MCP supplies the request.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP protocol eras.
 */
class MMSAR_MCP_Era {

	/**
	 * Modern revisions this server speaks, newest first.
	 */
	const MODERN = array( '2026-07-28' );

	/**
	 * The `_meta` keys 2026-07-28 defines.
	 */
	const META_VERSION     = 'io.modelcontextprotocol/protocolVersion';
	const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

	/**
	 * Error codes from 2026-07-28's reserved range.
	 */
	const ERROR_HEADER_MISMATCH     = -32020;
	const ERROR_UNSUPPORTED_VERSION = -32022;
	const ERROR_METHOD_NOT_FOUND    = -32601;

	/**
	 * Methods whose results carry caching hints in 2026-07-28.
	 */
	const CACHEABLE = array( 'server/discover', 'tools/list', 'prompts/list', 'resources/list', 'resources/templates/list', 'resources/read' );

	/**
	 * Methods whose `Mcp-Name` header must mirror a body field, and which field.
	 */
	const NAME_FIELDS = array(
		'tools/call'     => 'name',
		'prompts/get'    => 'name',
		'resources/read' => 'uri',
	);

	/**
	 * Default freshness for cacheable results: 15 minutes.
	 *
	 * Short enough that a plugin update reaches a client within the quarter hour, long enough that a
	 * client is not re-reading an unchanged tool list on every turn of a conversation.
	 */
	const DEFAULT_TTL_MS = 900000;

	/**
	 * The protocol version a message declares in `params._meta`, or '' for a legacy message.
	 *
	 * @param mixed $params The message's params.
	 * @return string Version, or ''.
	 */
	public static function declared_version( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['_meta'] ) || ! is_array( $params['_meta'] ) ) {
			return '';
		}
		$version = isset( $params['_meta'][ self::META_VERSION ] ) ? $params['_meta'][ self::META_VERSION ] : '';
		return is_string( $version ) ? $version : '';
	}

	/**
	 * Decode an `Mcp-Name` / `Mcp-Param-*` header value: `=?base64?…?=` is Base64 of the UTF-8 text.
	 *
	 * @param string $value Raw header value.
	 * @return string|null Decoded value, or null when the sentinel wraps invalid Base64.
	 */
	public static function decode_header_value( $value ) {
		$value = (string) $value;
		if ( 0 !== strpos( $value, '=?base64?' ) || '?=' !== substr( $value, -2 ) || strlen( $value ) < 11 ) {
			return $value;
		}
		$decoded = base64_decode( substr( $value, 9, -2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The spec's own header encoding.
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Check a modern request against its headers.
	 *
	 * @param string $method  The message's method.
	 * @param array  $params  The message's params.
	 * @param array  $headers Lower-cased header name => value (`mcp-protocol-version`, `mcp-method`,
	 *                        `mcp-name`). Missing headers are absent or ''.
	 * @return array|null Null when the request is acceptable; otherwise
	 *                    `array( 'code' => int, 'message' => string, 'status' => int, 'data' => array|null )`.
	 */
	public static function validate( $method, $params, $headers ) {
		$version = self::declared_version( $params );
		$header  = isset( $headers['mcp-protocol-version'] ) ? trim( (string) $headers['mcp-protocol-version'] ) : '';

		if ( ! in_array( $version, self::MODERN, true ) ) {
			return array(
				'code'    => self::ERROR_UNSUPPORTED_VERSION,
				'message' => 'Unsupported protocol version',
				'status'  => 400,
				'data'    => array(
					'supported' => self::MODERN,
					'requested' => $version,
				),
			);
		}
		if ( $header !== $version ) {
			return self::mismatch( '' === $header ? 'MCP-Protocol-Version header is missing' : 'MCP-Protocol-Version header does not match the body' );
		}

		$header_method = isset( $headers['mcp-method'] ) ? (string) $headers['mcp-method'] : '';
		if ( $header_method !== $method ) {
			return self::mismatch( '' === $header_method ? 'Mcp-Method header is missing' : 'Mcp-Method header does not match the body' );
		}

		if ( isset( self::NAME_FIELDS[ $method ] ) ) {
			$field = self::NAME_FIELDS[ $method ];
			$body  = isset( $params[ $field ] ) && is_string( $params[ $field ] ) ? $params[ $field ] : '';
			$raw   = isset( $headers['mcp-name'] ) ? (string) $headers['mcp-name'] : '';
			if ( '' === $raw ) {
				return self::mismatch( 'Mcp-Name header is missing' );
			}
			if ( self::decode_header_value( $raw ) !== $body ) {
				return self::mismatch( 'Mcp-Name header does not match the body' );
			}
		}

		return null;
	}

	/**
	 * A HeaderMismatch failure.
	 *
	 * @param string $why What did not match.
	 * @return array Failure.
	 */
	private static function mismatch( $why ) {
		return array(
			'code'    => self::ERROR_HEADER_MISMATCH,
			'message' => 'Header mismatch: ' . $why . '.',
			'status'  => 400,
			'data'    => null,
		);
	}

	/**
	 * Add what 2026-07-28 requires to a successful result.
	 *
	 * @param array  $result      Result as the legacy code built it.
	 * @param string $method      The method it answers.
	 * @param array  $server_info `array( 'name' => …, 'version' => … )`.
	 * @param int    $ttl_ms      Freshness for cacheable results, in milliseconds.
	 * @return array Result.
	 */
	public static function decorate( $result, $method, $server_info, $ttl_ms ) {
		$result               = (array) $result;
		$result['resultType'] = 'complete';

		$meta = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();

		$meta[ self::META_SERVER_INFO ] = $server_info;
		$result['_meta']                = $meta;

		if ( in_array( $method, self::CACHEABLE, true ) ) {
			$result['ttlMs']      = max( 0, (int) $ttl_ms );
			$result['cacheScope'] = 'public';
		}
		return $result;
	}
}
