<?php
/**
 * WebMCP origin-trial tokens (1.57.0).
 *
 * Tokens are built here in Chromium's layout (version byte, 64-byte signature, 4-byte big-endian
 * length, JSON payload) with a zeroed signature: the plugin reads claims and never checks the
 * signature, which is the browser's job. One case per state check_token() can return, the origin
 * rules both ways (subdomains, ports, schemes), and the sanitizer keeping only tokens that would work.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Records a settings error, as core does, for the sanitizer's notices.
	 *
	 * @param string $setting Setting.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return void
	 */
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['mmsar_test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'get_settings_errors' ) ) {
	/**
	 * The recorded settings errors for one setting.
	 *
	 * @param string $setting Setting.
	 * @return array[]
	 */
	function get_settings_errors( $setting = '' ) {
		$all = isset( $GLOBALS['mmsar_test_settings_errors'] ) ? $GLOBALS['mmsar_test_settings_errors'] : array();
		return array_values(
			array_filter(
				$all,
				static function ( $error ) use ( $setting ) {
					return '' === $setting || $error['setting'] === $setting;
				}
			)
		);
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Core's wp_date(), in UTC.
	 *
	 * @param string $format    Format.
	 * @param int    $timestamp Timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Front end, for these tests.
	 *
	 * @return bool
	 */
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Core's attribute escaping, reduced to htmlspecialchars.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

/**
 * Token decoding, checking and sanitizing.
 */
final class WebMcpTokenTest extends TestCase {

	/**
	 * A fixed "now": 2026-10-04 00:00 UTC.
	 */
	const NOW = 1791072000;

	/**
	 * Builds a token in Chromium's layout.
	 *
	 * @param array $payload Claims.
	 * @param int   $version Version byte.
	 * @return string
	 */
	private static function token( array $payload, int $version = 3 ): string {
		$json = (string) json_encode( $payload );
		return base64_encode( chr( $version ) . str_repeat( "\0", 64 ) . pack( 'N', strlen( $json ) ) . $json );
	}

	/**
	 * A token for the given origin and feature, expiring the given number of days after NOW.
	 *
	 * @param string $origin    Origin.
	 * @param int    $days      Days to expiry.
	 * @param string $feature   Feature.
	 * @param bool   $subdomain Covers subdomains.
	 * @return string
	 */
	private static function for_origin( string $origin, int $days = 100, string $feature = 'WebMCP', bool $subdomain = false ): string {
		return self::token(
			array(
				'origin'      => $origin,
				'feature'     => $feature,
				'expiry'      => self::NOW + $days * 86400,
				'isSubdomain' => $subdomain,
			)
		);
	}

	/**
	 * Clean state per test.
	 */
	protected function setUp(): void {
		wp_stub_reset();
		$GLOBALS['wp_stub_home_url']           = 'https://example.com';
		$GLOBALS['mmsar_test_settings_errors'] = array();
	}

	/**
	 * Cases: token, site URL, expected state.
	 *
	 * @return array
	 */
	public static function tokens(): array {
		return array(
			'valid, default port written out'     => array( self::for_origin( 'https://example.com:443' ), 'https://example.com/', 'valid' ),
			'valid, no port'                      => array( self::for_origin( 'https://example.com' ), 'https://example.com/', 'valid' ),
			'feature name compared case-blind'    => array( self::for_origin( 'https://example.com:443', 100, 'webmcp' ), 'https://example.com/', 'valid' ),
			'version 2 layout'                    => array( self::token( array( 'origin' => 'https://example.com:443', 'feature' => 'WebMCP', 'expiry' => self::NOW + 8640000 ) , 2 ), 'https://example.com/', 'valid' ),
			'expiring within 14 days'             => array( self::for_origin( 'https://example.com:443', 10 ), 'https://example.com/', 'expiring' ),
			'expired'                             => array( self::for_origin( 'https://example.com:443', -1 ), 'https://example.com/', 'expired' ),
			'another site'                        => array( self::for_origin( 'https://example.org:443' ), 'https://example.com/', 'wrong_origin' ),
			'http token on an https site'         => array( self::for_origin( 'http://example.com:80' ), 'https://example.com/', 'wrong_origin' ),
			'other port'                          => array( self::for_origin( 'https://example.com:8443' ), 'https://example.com/', 'wrong_origin' ),
			'subdomain without the flag'          => array( self::for_origin( 'https://example.com:443' ), 'https://staging.example.com/', 'wrong_origin' ),
			'subdomain with the flag'             => array( self::for_origin( 'https://example.com:443', 100, 'WebMCP', true ), 'https://staging.example.com/', 'valid' ),
			'flag does not cover a lookalike'     => array( self::for_origin( 'https://example.com:443', 100, 'WebMCP', true ), 'https://badexample.com/', 'wrong_origin' ),
			'parent of the token origin'          => array( self::for_origin( 'https://www.example.com:443', 100, 'WebMCP', true ), 'https://example.com/', 'wrong_origin' ),
			'another trial'                       => array( self::for_origin( 'https://example.com:443', 100, 'WebGPU' ), 'https://example.com/', 'wrong_feature' ),
			'third-party token'                   => array( self::token( array( 'origin' => 'https://example.com:443', 'feature' => 'WebMCP', 'expiry' => self::NOW + 8640000, 'isThirdParty' => true ) ), 'https://example.com/', 'third_party' ),
			'not base64'                          => array( 'not a token!', 'https://example.com/', 'invalid' ),
			'base64 but too short'                => array( base64_encode( 'short' ), 'https://example.com/', 'invalid' ),
			'unknown version'                     => array( self::token( array( 'origin' => 'https://example.com', 'feature' => 'WebMCP', 'expiry' => self::NOW + 99 ) , 9 ), 'https://example.com/', 'invalid' ),
			'length field disagrees'              => array( base64_encode( chr( 3 ) . str_repeat( "\0", 64 ) . pack( 'N', 999 ) . '{"origin":"https://example.com"}' ), 'https://example.com/', 'invalid' ),
			'payload missing expiry'              => array( self::token( array( 'origin' => 'https://example.com', 'feature' => 'WebMCP' ) ), 'https://example.com/', 'invalid' ),
		);
	}

	/**
	 * @param string $token    Token.
	 * @param string $site     Site URL.
	 * @param string $expected State.
	 */
	#[DataProvider( 'tokens' )]
	public function test_state( string $token, string $site, string $expected ): void {
		$this->assertSame( $expected, MMSAR_WebMCP::check_token( $token, $site, self::NOW )['state'] );
	}

	/**
	 * The decoded claims are what the token says.
	 */
	public function test_decoded_claims(): void {
		$claims = MMSAR_WebMCP::decode_token( self::for_origin( 'https://example.com:443', 30, 'WebMCP', true ) );
		$this->assertIsArray( $claims );
		$this->assertSame( 'https://example.com:443', $claims['origin'] );
		$this->assertSame( 'WebMCP', $claims['feature'] );
		$this->assertSame( self::NOW + 30 * 86400, $claims['expiry'] );
		$this->assertTrue( $claims['subdomain'] );
		$this->assertFalse( $claims['third_party'] );
	}

	/**
	 * Saving keeps working tokens, in order and once each, and drops the rest with one notice per reason.
	 */
	public function test_sanitizer_keeps_only_working_tokens(): void {
		$good     = self::for_origin( 'https://example.com:443', 3650 );
		$edge     = self::for_origin( 'https://example.com', 3650 );
		$other    = self::for_origin( 'https://example.org:443', 3650 );
		$other2   = self::for_origin( 'https://example.net:443', 3650 );
		$expired  = self::for_origin( 'https://example.com:443', -3650 );
		$input    = "  {$good}\n\n{$other}\r\n{$good}\n{$expired}\n{$edge}\n{$other2}\nrubbish";
		$kept     = MMSAR_WebMCP::sanitize_tokens( $input );
		$this->assertSame( array( $good, $edge ), $kept );

		$codes = array_column( get_settings_errors( MMSAR_WebMCP::TOKENS_OPTION ), 'code' );
		sort( $codes );
		$this->assertSame( array( 'mmsar_webmcp_token_expired', 'mmsar_webmcp_token_invalid', 'mmsar_webmcp_token_wrong_origin' ), $codes );
	}

	/**
	 * An array from code is sanitized the same way, and the cap holds.
	 */
	public function test_sanitizer_caps_and_accepts_arrays(): void {
		$tokens = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$tokens[] = self::for_origin( 'https://example.com:443', 3650 + $i );
		}
		$this->assertCount( MMSAR_WebMCP::MAX_TOKENS, MMSAR_WebMCP::sanitize_tokens( $tokens ) );
	}

	/**
	 * Only working tokens are printed, escaped, and nothing is printed without any.
	 */
	public function test_only_working_tokens_are_printed(): void {
		$good = self::for_origin( 'https://example.com:443', 3650 );
		$bad  = self::for_origin( 'https://example.org:443', 3650 );

		ob_start();
		MMSAR_WebMCP::print_trial_tokens();
		$this->assertSame( '', ob_get_clean() );

		$GLOBALS['wp_stub_options'][ MMSAR_WebMCP::TOKENS_OPTION ] = array( $bad, $good );
		ob_start();
		MMSAR_WebMCP::print_trial_tokens();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<meta http-equiv="origin-trial"' ) );
		$this->assertStringContainsString( $good, $html );
		$this->assertStringNotContainsString( $bad, $html );
	}
}
