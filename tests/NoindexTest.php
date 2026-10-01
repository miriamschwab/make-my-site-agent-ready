<?php
/**
 * How a post's noindex setting is read from Yoast SEO and Rank Math, and the owner's switch.
 *
 * A wrong "noindex" quietly removes a page from every list an agent reads, and a wrong "index"
 * publishes one the owner asked to keep out of search, so each storage shape is pinned here.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Noindex's pure rules.
 */
final class NoindexTest extends TestCase {

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * @param mixed  $meta     `_yoast_wpseo_meta-robots-noindex`.
	 * @param mixed  $titles   `wpseo_titles`.
	 * @param bool   $expected Noindex.
	 */
	#[DataProvider( 'yoastShapes' )]
	public function test_yoast( $meta, $titles, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_Noindex::yoast_noindex( $meta, $titles, 'post' ) );
	}

	/**
	 * @return array<string, array{0:mixed,1:mixed,2:bool}>
	 */
	public static function yoastShapes(): array {
		$type_noindex = array( 'noindex-post' => true );
		$type_index   = array( 'noindex-post' => false );
		return array(
			'nothing set'                       => array( '', array(), false ),
			'no titles option at all'           => array( '', false, false ),
			'post says noindex'                 => array( '1', $type_index, true ),
			'post says index over type default' => array( '2', $type_noindex, false ),
			'post defers, type is noindex'      => array( '', $type_noindex, true ),
			'post defers with 0'                => array( '0', $type_noindex, true ),
			'post defers, type is index'        => array( '0', $type_index, false ),
			'another type noindex only'         => array( '', array( 'noindex-page' => true ), false ),
			'meta stored as an int'             => array( 1, array(), true ),
		);
	}

	/**
	 * @param mixed $robots   `rank_math_robots`.
	 * @param mixed $titles   `rank-math-options-titles`.
	 * @param bool  $expected Noindex.
	 */
	#[DataProvider( 'rankMathShapes' )]
	public function test_rank_math( $robots, $titles, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_Noindex::rank_math_noindex( $robots, $titles, 'post' ) );
	}

	/**
	 * @return array<string, array{0:mixed,1:mixed,2:bool}>
	 */
	public static function rankMathShapes(): array {
		$global_noindex = array( 'robots_global' => array( 'noindex' ) );
		$custom_index   = array(
			'pt_post_custom_robots' => 'on',
			'pt_post_robots'        => array( 'index' ),
			'robots_global'         => array( 'noindex' ),
		);
		return array(
			'nothing set'                         => array( '', array(), false ),
			'post says noindex'                   => array( array( 'noindex', 'nofollow' ), array(), true ),
			'post says index over global noindex' => array( array( 'index' ), $global_noindex, false ),
			'post empty, global noindex'          => array( array(), $global_noindex, true ),
			'post empty, type custom index'       => array( array(), $custom_index, false ),
			'type custom off falls to global'     => array(
				'',
				array(
					'pt_post_custom_robots' => 'off',
					'pt_post_robots'        => array( 'index' ),
					'robots_global'         => array( 'noindex' ),
				),
				true,
			),
			'type custom noindex'                 => array(
				'',
				array(
					'pt_post_custom_robots' => 'on',
					'pt_post_robots'        => array( 'noindex' ),
				),
				true,
			),
			'nofollow alone is not noindex'       => array( array( 'nofollow' ), array(), false ),
			'empty strings in the array'          => array( array( '', '' ), $global_noindex, true ),
		);
	}

	public function test_the_switch_is_on_when_never_saved(): void {
		$this->assertTrue( MMSAR_Noindex::enabled_in( array() ) );
		$this->assertTrue( MMSAR_Noindex::enabled_in( false ) );
		$this->assertTrue( MMSAR_Noindex::enabled_in( array( 'post_types' => array( 'post' ) ) ) );
	}

	public function test_the_switch_reads_what_was_saved(): void {
		$this->assertFalse( MMSAR_Noindex::enabled_in( array( 'respect_noindex' => '0' ) ) );
		$this->assertTrue( MMSAR_Noindex::enabled_in( array( 'respect_noindex' => '1' ) ) );
	}
}
