<?php
/**
 * The six-month retention switch, and Re-check's narrow test of `failed` rows (1.69.0).
 *
 * Everything here is a pure decision; the SQL around it was run on the Local clone.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What is stored, what becomes of it at six months, and what Re-check may overturn.
 */
final class RetentionTest extends TestCase {

	private const PERSON_IP = '203.0.113.42';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
	}

	private function switch_on(): void {
		$GLOBALS['wp_stub_options'][ MMSAR_Agent_Log::RETAIN_OPTION ] = '1';
	}

	public function test_off_by_default(): void {
		$this->assertFalse( MMSAR_Agent_Log::retains_full() );
	}

	/**
	 * With the switch off, nothing is held and storage is what it always was.
	 */
	public function test_switch_off_reduces_as_before(): void {
		$this->assertSame(
			array(
				'ip'   => '203.0.113.0',
				'held' => 0,
			),
			MMSAR_Agent_Log::storage_address( self::PERSON_IP, true )
		);
	}

	/**
	 * With it on, a reducible address is kept and marked.
	 */
	public function test_switch_on_holds_the_address(): void {
		$this->switch_on();
		$this->assertSame(
			array(
				'ip'   => self::PERSON_IP,
				'held' => 1,
			),
			MMSAR_Agent_Log::storage_address( self::PERSON_IP, true )
		);
	}

	/**
	 * An address the log keeps anyway is never held, so the sweep can never reach it.
	 *
	 * @param bool $on Whether the switch is on.
	 */
	#[DataProvider( 'switchStates' )]
	public function test_always_kept_is_never_held( bool $on ): void {
		if ( $on ) {
			$this->switch_on();
		}
		$this->assertSame(
			array(
				'ip'   => self::PERSON_IP,
				'held' => 0,
			),
			MMSAR_Agent_Log::storage_address( self::PERSON_IP, false )
		);
	}

	/**
	 * @return array<string, array{0:bool}>
	 */
	public static function switchStates(): array {
		return array(
			'off' => array( false ),
			'on'  => array( true ),
		);
	}

	/**
	 * At six months an unidentified row is reduced, and an identified one stays.
	 *
	 * @param string $verdict  Stored verdict.
	 * @param string $expected Address afterwards.
	 */
	#[DataProvider( 'agedAddresses' )]
	public function test_aged_address( string $verdict, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::aged_address( self::PERSON_IP, $verdict ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function agedAddresses(): array {
		return array(
			'unclaimed is reduced'       => array( 'unclaimed', '203.0.113.0' ),
			'a user-run client, reduced' => array( 'client', '203.0.113.0' ),
			'never judged, reduced'      => array( '', '203.0.113.0' ),
			'verified stays'             => array( 'verified', self::PERSON_IP ),
			'failed stays'               => array( 'failed', self::PERSON_IP ),
			'unverifiable stays'         => array( 'unverifiable', self::PERSON_IP ),
			'nodns stays'                => array( 'nodns', self::PERSON_IP ),
		);
	}

	public function test_aged_ipv6_is_reduced(): void {
		$this->assertSame( '2001:db8:1:2::', MMSAR_Agent_Log::aged_address( '2001:db8:1:2:3:4:5:6', 'unclaimed' ) );
	}

	public function test_full_user_agent_is_bounded_and_printable(): void {
		$this->assertSame( 'abc', MMSAR_Agent_Log::fit_full_user_agent( "a\x00b\x7Fc\xC3\xA9" ) );
		$this->assertSame( MMSAR_Agent_Log::FULL_UA_LENGTH, strlen( MMSAR_Agent_Log::fit_full_user_agent( str_repeat( 'x', 900 ) ) ) );
	}

	/**
	 * Re-check overturns `failed` only for a full address inside the operator's published list.
	 *
	 * @param string $agent    Value in the `agent` column.
	 * @param string $ip       Value in the `ip` column.
	 * @param bool   $expected Whether the row may be overturned.
	 */
	#[DataProvider( 'failedRows' )]
	public function test_in_published_ranges( string $agent, string $ip, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Verify::in_published_ranges( $agent, $ip ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:bool}>
	 */
	public static function failedRows(): array {
		return array(
			// One of the five that read `failed` on live on 2026-10-29.
			'KakaoBot on a published single' => array( 'KakaoBot', '211.249.246.105', true ),
			'Claude-User in the extra range' => array( 'Claude-User', '160.79.106.10', true ),
			'KakaoBot off the list'          => array( 'KakaoBot', '121.53.80.5', false ),
			'forged GPTBot'                  => array( 'GPTBot', '3.83.8.90', false ),
			'a reduced address proves nothing' => array( 'KakaoBot', '211.249.246.0', false ),
			'a user-run client is never it'  => array( 'Claude-User (claude-code)', '160.79.106.10', false ),
			'no claim'                       => array( 'curl/8.7.1', '211.249.246.105', false ),
			'rDNS only, so never by range'   => array( 'ibou-search', '188.241.38.100', false ),
			'not an address'                 => array( 'KakaoBot', 'nonsense', false ),
		);
	}
}
