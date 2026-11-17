<?php
/**
 * The meta watcher's pure parts (1.74.0): which requests and fields it watches, how it counts,
 * and when it reports a field.
 *
 * The hooks themselves need WordPress and are checked on the Local clone.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Meta_Watch.
 */
final class MetaWatchTest extends TestCase {

	/**
	 * Request flags => reported context ('' means not watched).
	 *
	 * @return array<string, array{0: array<string, bool>, 1: string}>
	 */
	public static function contexts(): array {
		return array(
			'page view'          => array( array(), '' ),
			'page view, all off' => array(
				array(
					'cli'   => false,
					'cron'  => false,
					'rest'  => false,
					'ajax'  => false,
					'admin' => false,
				),
				'',
			),
			'wp-admin'           => array( array( 'admin' => true ), 'admin' ),
			'admin-ajax'         => array(
				array(
					'admin' => true,
					'ajax'  => true,
				),
				'ajax',
			),
			'REST'               => array( array( 'rest' => true ), 'rest' ),
			'cron'               => array( array( 'cron' => true ), 'cron' ),
			'WP-CLI over admin'  => array(
				array(
					'cli'   => true,
					'admin' => true,
				),
				'cli',
			),
			'XML-RPC'            => array( array( 'xmlrpc' => true ), 'xmlrpc' ),
		);
	}

	/**
	 * Only non-page-view requests are watched, and the most specific type is reported.
	 *
	 * @param array<string, bool> $flags    Flags.
	 * @param string              $expected Context.
	 */
	#[DataProvider( 'contexts' )]
	public function test_context( array $flags, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Meta_Watch::context_from( $flags ) );
	}

	/**
	 * Housekeeping fields and the plugin's own are ignored; content fields are not.
	 */
	public function test_ignored_keys(): void {
		$list = MMSAR_Meta_Watch::IGNORED_KEYS;
		foreach ( array( '_edit_lock', '_edit_last', '_wp_old_slug', '_llmmd_content', '_mmsar_anything' ) as $key ) {
			$this->assertTrue( MMSAR_Meta_Watch::is_ignored( $key, $list ), $key );
		}
		foreach ( array( 'media_link', '_media_link', '_yoast_wpseo_metadesc', '_thumbnail_id', 'edit_lock', '_llmmd' ) as $key ) {
			$this->assertFalse( MMSAR_Meta_Watch::is_ignored( $key, $list ), $key );
		}
		$this->assertTrue( MMSAR_Meta_Watch::is_ignored( 'post_views_count', array_merge( $list, array( 'post_views_count' ) ) ) );
	}

	/**
	 * One request's results land in the day and in each field, by outcome and context.
	 */
	public function test_record_counts(): void {
		$stats = MMSAR_Meta_Watch::record(
			array(),
			'2026-11-17',
			'rest',
			array(
				array(
					'post'    => 10,
					'keys'    => array( 'media_link', '_media_link' ),
					'outcome' => 'rebuilt',
				),
				array(
					'post'    => 11,
					'keys'    => array( 'views' ),
					'outcome' => 'unchanged',
				),
				array(
					'post'    => 12,
					'keys'    => array( '_yoast_wpseo_metadesc' ),
					'outcome' => 'after_save',
				),
			),
			true,
			1000
		);
		$this->assertSame(
			array(
				'checked'    => 3,
				'rebuilt'    => 1,
				'unchanged'  => 1,
				'after_save' => 1,
				'flushes'    => 1,
			),
			$stats['days']['2026-11-17']
		);
		$this->assertSame( 1, $stats['keys']['media_link']['days']['2026-11-17']['rebuilt'] );
		$this->assertSame( 1, $stats['keys']['_media_link']['days']['2026-11-17']['rebuilt'] );
		$this->assertSame( 1, $stats['keys']['views']['days']['2026-11-17']['unchanged'] );
		$this->assertSame( array( 'rest' => 1 ), $stats['keys']['views']['contexts'] );
		$this->assertSame( 11, $stats['keys']['views']['last_post'] );
		$this->assertSame( 1000, $stats['keys']['views']['last_seen'] );

		// A second request on the same day adds to it, with its own context.
		$stats = MMSAR_Meta_Watch::record(
			$stats,
			'2026-11-17',
			'cron',
			array(
				array(
					'post'    => 11,
					'keys'    => array( 'views' ),
					'outcome' => 'unchanged',
				),
			),
			false,
			2000
		);
		$this->assertSame( 4, $stats['days']['2026-11-17']['checked'] );
		$this->assertSame( 1, $stats['days']['2026-11-17']['flushes'] );
		$this->assertSame( 2, $stats['keys']['views']['days']['2026-11-17']['unchanged'] );
		$this->assertSame(
			array(
				'rest' => 1,
				'cron' => 1,
			),
			$stats['keys']['views']['contexts']
		);
	}

	/**
	 * Days older than the kept window drop out, and a field left with no days drops with them.
	 */
	public function test_record_prunes_old_days(): void {
		$one   = array(
			array(
				'post'    => 1,
				'keys'    => array( 'old_field' ),
				'outcome' => 'unchanged',
			),
		);
		$stats = MMSAR_Meta_Watch::record( array(), '2026-11-01', 'admin', $one, false, 1 );
		$stats = MMSAR_Meta_Watch::record(
			$stats,
			'2026-11-17',
			'admin',
			array(
				array(
					'post'    => 1,
					'keys'    => array( 'new_field' ),
					'outcome' => 'unchanged',
				),
			),
			false,
			2
		);
		$this->assertSame( array( '2026-11-17' ), array_keys( $stats['days'] ) );
		$this->assertArrayNotHasKey( 'old_field', $stats['keys'] );
		$this->assertArrayHasKey( 'new_field', $stats['keys'] );

		// The edge of the window (KEEP_DAYS days including today) is kept.
		$edge  = MMSAR_Meta_Watch::record( array(), '2026-11-04', 'admin', $one, false, 1 );
		$edge  = MMSAR_Meta_Watch::record( $edge, '2026-11-17', 'admin', $one, false, 2 );
		$this->assertSame( array( '2026-11-04', '2026-11-17' ), array_keys( $edge['days'] ) );
	}

	/**
	 * Past MAX_KEYS distinct fields, new ones are counted together, so the option can't grow without
	 * bound when a plugin writes per-item field names.
	 */
	public function test_record_caps_fields(): void {
		$results = array();
		for ( $i = 0; $i < MMSAR_Meta_Watch::MAX_KEYS + 5; $i++ ) {
			$results[] = array(
				'post'    => 1,
				'keys'    => array( 'field_' . $i ),
				'outcome' => 'unchanged',
			);
		}
		$stats = MMSAR_Meta_Watch::record( array(), '2026-11-17', 'admin', $results, false, 1 );
		$this->assertCount( MMSAR_Meta_Watch::MAX_KEYS + 1, $stats['keys'] );
		$this->assertSame( 5, $stats['keys'][ MMSAR_Meta_Watch::OTHER_KEY ]['days']['2026-11-17']['unchanged'] );
	}

	/**
	 * Field names are stored as printable ASCII, capped.
	 */
	public function test_clean_key(): void {
		$this->assertSame( 'media_link', MMSAR_Meta_Watch::clean_key( 'media_link' ) );
		$this->assertSame( 'a?b', MMSAR_Meta_Watch::clean_key( "a\nb" ) );
		$this->assertSame( 191, strlen( MMSAR_Meta_Watch::clean_key( str_repeat( 'x', 300 ) ) ) );
		$this->assertSame( '(empty)', MMSAR_Meta_Watch::clean_key( '' ) );
	}

	/**
	 * A field is reported only from the threshold up, by its worst day, as noise or churn; after_save
	 * checks never count.
	 */
	public function test_findings(): void {
		$day = static function ( $unchanged, $rebuilt, $after_save = 0 ) {
			return array(
				'checked'    => $unchanged + $rebuilt + $after_save,
				'rebuilt'    => $rebuilt,
				'unchanged'  => $unchanged,
				'after_save' => $after_save,
			);
		};
		$stats = array(
			'keys' => array(
				'views'     => array(
					'days'     => array(
						'2026-11-16' => $day( 10, 0 ),
						'2026-11-17' => $day( MMSAR_Meta_Watch::NOISE_THRESHOLD + 30, 0 ),
					),
					'contexts' => array( 'cron' => 90 ),
				),
				'price'     => array(
					'days'     => array( '2026-11-17' => $day( 0, MMSAR_Meta_Watch::CHURN_THRESHOLD ) ),
					'contexts' => array( 'rest' => 50 ),
				),
				'quiet'     => array(
					'days'     => array( '2026-11-17' => $day( MMSAR_Meta_Watch::NOISE_THRESHOLD - 1, MMSAR_Meta_Watch::CHURN_THRESHOLD - 1 ) ),
					'contexts' => array( 'admin' => 98 ),
				),
				'yoast'     => array(
					'days'     => array( '2026-11-17' => $day( 0, 0, 500 ) ),
					'contexts' => array( 'admin' => 500 ),
				),
			),
		);
		$found = MMSAR_Meta_Watch::findings( $stats );
		$this->assertCount( 2, $found );
		$this->assertSame( 'views', $found[0]['key'] );
		$this->assertSame( 'noise', $found[0]['kind'] );
		$this->assertSame( '2026-11-17', $found[0]['day'] );
		$this->assertSame( MMSAR_Meta_Watch::NOISE_THRESHOLD + 30, $found[0]['count'] );
		$this->assertSame( array( 'cron' => 90 ), $found[0]['contexts'] );
		$this->assertSame( 'price', $found[1]['key'] );
		$this->assertSame( 'churn', $found[1]['kind'] );
		$this->assertSame( array(), MMSAR_Meta_Watch::findings( array() ) );
	}
}
