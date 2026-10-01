<?php
/**
 * What IndexNow is sent: which URLs a save submits, and the shape of the request.
 *
 * The one rule worth pinning is the old URL. A post that is unpublished or moved has to send the
 * address it used to have, or the engine keeps listing a page that is gone.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_IndexNow's pure parts.
 */
final class IndexNowTest extends TestCase {

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * @param string   $before   URL before the save, '' when not listed.
	 * @param string   $after    URL after the save, '' when not listed.
	 * @param string[] $expected URLs submitted.
	 */
	#[DataProvider( 'changes' )]
	public function test_urls_for_change( string $before, string $after, array $expected ): void {
		$this->assertSame( $expected, MMSAR_IndexNow::urls_for_change( $before, $after ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string[]}>
	 */
	public static function changes(): array {
		$a = 'https://example.com/a/';
		$b = 'https://example.com/b/';
		return array(
			'draft saved'           => array( '', '', array() ),
			'first published'       => array( '', $a, array( $a ) ),
			'updated in place'      => array( $a, $a, array( $a ) ),
			'unpublished'           => array( $a, '', array( $a ) ),
			'slug changed'          => array( $a, $b, array( $b, $a ) ),
		);
	}

	/**
	 * @param mixed $key      Candidate.
	 * @param bool  $expected Valid.
	 */
	#[DataProvider( 'keys' )]
	public function test_key_validity( $key, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_IndexNow::is_valid_key( $key ) );
	}

	/**
	 * @return array<string, array{0:mixed,1:bool}>
	 */
	public static function keys(): array {
		return array(
			'32 alphanumerics'  => array( str_repeat( 'aB3', 10 ) . 'xy', true ),
			'hyphen allowed'    => array( 'abcd-1234', true ),
			'eight is the floor' => array( 'abcdefgh', true ),
			'seven is too short' => array( 'abcdefg', false ),
			'129 is too long'   => array( str_repeat( 'a', 129 ), false ),
			'underscore'        => array( 'abcd_1234', false ),
			'dot'               => array( 'abcd.1234', false ),
			'path traversal'    => array( '../../etc', false ),
			'trailing newline'  => array( "abcdefgh\n", false ),
			'empty'             => array( '', false ),
			'not a string'      => array( 12345678, false ),
		);
	}

	public function test_payload_shape(): void {
		$GLOBALS['wp_stub_options']['mmsar_indexnow_key'] = 'abcdef0123456789abcdef0123456789';

		$payload = MMSAR_IndexNow::payload( array( 'https://example.com/a/' ) );

		$this->assertSame( 'example.com', $payload['host'] );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $payload['key'] );
		$this->assertSame( 'https://example.com/abcdef0123456789abcdef0123456789.txt', $payload['keyLocation'] );
		$this->assertSame( array( 'https://example.com/a/' ), $payload['urlList'] );
	}

	public function test_another_plugin_detected_by_filter(): void {
		$this->assertSame( '', MMSAR_IndexNow::handled_elsewhere() );
		add_filter(
			'mmsar_indexnow_handled_elsewhere',
			static function () {
				return 'Some SEO plugin';
			}
		);
		$this->assertSame( 'Some SEO plugin', MMSAR_IndexNow::handled_elsewhere() );
	}

	public function test_a_plugin_that_is_active_counts(): void {
		$GLOBALS['wp_stub_options']['active_plugins'] = array( 'indexnow/indexnow-url-submission.php' );
		$this->assertSame( 'IndexNow (Microsoft)', MMSAR_IndexNow::handled_elsewhere() );
	}
}
