<?php
/**
 * The Summary's numbers: what is counted, which period a day falls in, and when a change is shown.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Agent_Overview::compute() and its helpers.
 */
final class OverviewTest extends TestCase {

	const TODAY = '2026-11-02';

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * A grouped row.
	 */
	private static function row( $name, $category, $verified, $day, $n ) {
		return array(
			'name'     => $name,
			'category' => $category,
			'verified' => $verified,
			'day'      => $day,
			'n'        => $n,
		);
	}

	/**
	 * Context with a previous period unless told otherwise.
	 */
	private static function ctx( array $over = array() ) {
		return array_merge(
			array(
				'today'        => self::TODAY,
				'has_previous' => true,
				'referrals'    => array(
					'now'      => 0,
					'previous' => 0,
					'shown'    => false,
				),
			),
			$over
		);
	}

	public function test_thirty_days_end_today_and_today_is_partial(): void {
		$out = MMSAR_Agent_Overview::compute( array(), self::ctx() );
		$this->assertCount( 30, $out['daily'] );
		$this->assertSame( '2026-10-04', $out['daily'][0]['day'] );
		$this->assertSame( self::TODAY, $out['daily'][29]['day'] );
		$this->assertTrue( $out['daily'][29]['partial'] );
		$this->assertFalse( $out['daily'][28]['partial'] );
	}

	public function test_days_fall_in_the_right_period(): void {
		$rows = array(
			self::row( 'GPTBot', 'ai-training', 'verified', '2026-10-04', 5 ), // First day of this period.
			self::row( 'GPTBot', 'ai-training', 'verified', '2026-10-03', 7 ), // Last day of the previous.
			self::row( 'GPTBot', 'ai-training', 'verified', '2026-09-04', 11 ), // First day of the previous.
			self::row( 'GPTBot', 'ai-training', 'verified', '2026-09-03', 100 ), // Before both.
		);
		$out  = MMSAR_Agent_Overview::compute( $rows, self::ctx() );
		$this->assertSame( 5, $out['totals']['requests']['now'] );
		$this->assertSame( 18, $out['totals']['requests']['previous'] );
		$this->assertSame( 5, $out['daily'][0]['n'] );
	}

	public function test_spoofed_is_counted_apart_and_never_as_the_bot(): void {
		$rows = array(
			self::row( 'GPTBot', 'ai-training', 'verified', self::TODAY, 4 ),
			self::row( 'GPTBot', 'ai-training', 'failed', self::TODAY, 9 ),
			self::row( 'ClaudeBot', 'ai-training', 'failed', self::TODAY, 2 ),
		);
		$out  = MMSAR_Agent_Overview::compute( $rows, self::ctx() );
		$this->assertSame( 4, $out['totals']['requests']['now'] );
		$this->assertSame( 11, $out['totals']['spoofed']['now'] );
		$this->assertSame( 1, $out['totals']['agents']['now'] );
		$this->assertSame( 4, $out['daily'][29]['n'] );
		$this->assertSame( array( 'GPTBot' ), array_column( $out['agents'], 'name' ) );
		$this->assertSame( array( 'ai-training' => 4 ), $out['purposes'] );
	}

	public function test_unrecognised_rows_are_ignored(): void {
		$rows = array(
			self::row( 'Mozilla/5.0 (Macintosh)', MMSAR_Agent_Log::CRAWLER_UNRECOGNISED, '', self::TODAY, 50 ),
			self::row( 'curl', '', '', self::TODAY, 3 ),
		);
		$out  = MMSAR_Agent_Overview::compute( $rows, self::ctx() );
		$this->assertSame( 0, $out['totals']['requests']['now'] );
		$this->assertSame( array(), $out['agents'] );
	}

	public function test_confirmed_counts_verified_and_user_run_only(): void {
		$rows = array(
			self::row( 'GPTBot', 'ai-training', 'verified', self::TODAY, 3 ),
			self::row( 'Claude-User', 'ai-assistant', 'client', self::TODAY, 2 ),
			self::row( 'OraBot', 'ai-search', 'unverifiable', self::TODAY, 5 ),
		);
		$out  = MMSAR_Agent_Overview::compute( $rows, self::ctx() );
		$this->assertSame( 10, $out['totals']['requests']['now'] );
		$this->assertSame( 5, $out['totals']['confirmed']['now'] );
	}

	public function test_no_previous_period_means_no_previous_values(): void {
		$rows = array( self::row( 'GPTBot', 'ai-training', 'verified', '2026-09-20', 7 ) );
		$out  = MMSAR_Agent_Overview::compute(
			$rows,
			self::ctx(
				array(
					'has_previous' => false,
					'referrals'    => array(
						'now'      => 3,
						'previous' => 1,
						'shown'    => true,
					),
				)
			)
		);
		$this->assertFalse( $out['has_previous'] );
		foreach ( array( 'requests', 'confirmed', 'spoofed', 'agents', 'referrals' ) as $key ) {
			$this->assertNull( $out['totals'][ $key ]['previous'], $key );
		}
		$this->assertSame( 3, $out['totals']['referrals']['now'] );
	}

	public function test_agents_are_ranked_capped_and_carry_their_previous_count(): void {
		$rows = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$rows[] = self::row( 'Bot' . $i, 'seo', 'unverifiable', self::TODAY, $i );
		}
		$rows[] = self::row( 'Bot10', 'seo', 'unverifiable', '2026-09-10', 4 );
		$rows[] = self::row( 'Gone', 'seo', 'verified', '2026-09-10', 40 );
		$out    = MMSAR_Agent_Overview::compute( $rows, self::ctx() );
		$this->assertCount( MMSAR_Agent_Overview::TOP_AGENTS, $out['agents'] );
		$this->assertSame( 'Bot10', $out['agents'][0]['name'] );
		$this->assertSame( 4, $out['agents'][0]['previous'] );
		$this->assertSame( 2, $out['more_agents'] );
		$this->assertNotContains( 'Gone', array_column( $out['agents'], 'name' ) );
		$this->assertSame( 2, $out['totals']['agents']['previous'] );
	}

	/**
	 * Identity cases.
	 */
	public static function identities(): array {
		return array(
			'all verified'         => array( array( 'verified' => 10 ), 10, 'verified' ),
			'90% verified'         => array( array( 'verified' => 9, 'nodns' => 1 ), 10, 'verified' ),
			'89% verified'         => array( array( 'verified' => 89, 'nodns' => 11 ), 100, 'partly' ),
			'user-run'             => array( array( 'client' => 7, 'verified' => 3 ), 10, 'client' ),
			'equal halves'         => array( array( 'client' => 5, 'verified' => 5 ), 10, 'verified' ),
			'mostly uncheckable'   => array( array( 'unverifiable' => 6, '' => 4 ), 10, 'uncheckable' ),
			'half uncheckable'     => array( array( 'unverifiable' => 5, '' => 5 ), 10, 'unconfirmed' ),
			'pending only'         => array( array( '' => 3 ), 3, 'unconfirmed' ),
			'nothing'              => array( array(), 0, 'unconfirmed' ),
		);
	}

	#[DataProvider( 'identities' )]
	public function test_identity( array $verdicts, int $total, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Overview::identity( $verdicts, $total ) );
	}

	public function test_change(): void {
		$this->assertSame( 50, MMSAR_Agent_Overview::change( 150, 100 ) );
		$this->assertSame( -25, MMSAR_Agent_Overview::change( 75, 100 ) );
		$this->assertNull( MMSAR_Agent_Overview::change( 10, 0 ) );
		$this->assertNull( MMSAR_Agent_Overview::change( 10, null ) );
	}

	/**
	 * Mix group cases: category, client type, verdict, expected group.
	 */
	public static function mix_groups(): array {
		return array(
			'AI training crawler'           => array( 'ai-training', 'crawler', 'verified', 'agents' ),
			'AI search'                     => array( 'ai-search', 'crawler', '', 'agents' ),
			'user-run assistant'            => array( 'ai-assistant', 'http', 'client', 'agents' ),
			'AI crawler that can\'t be checked' => array( 'ai-training', 'crawler', 'unverifiable', 'agents' ),
			'spoofed AI crawler'            => array( 'ai-training', 'crawler', 'failed', 'rest' ),
			'search engine'                 => array( 'search-engine', 'crawler', 'verified', 'known' ),
			'SEO tool'                      => array( 'seo-tool', 'crawler', '', 'known' ),
			'scanner'                       => array( 'scanner', 'crawler', '', 'known' ),
			'monitoring'                    => array( 'monitoring', 'crawler', '', 'known' ),
			'spoofed search engine'         => array( 'search-engine', 'crawler', 'failed', 'rest' ),
			'named bot in a browser'        => array( 'search-engine', 'browser', '', 'known' ),
			'unrecognised browser'          => array( 'unrecognised', 'browser', '', 'people' ),
			'unrecognised, empty'           => array( '', 'browser', '', 'people' ),
			'unrecognised script'           => array( 'unrecognised', 'http', '', 'rest' ),
			'unrecognised crawler'          => array( '', 'crawler', '', 'rest' ),
			'client type not recorded'      => array( '', '', '', 'rest' ),
		);
	}

	#[DataProvider( 'mix_groups' )]
	public function test_mix_group( string $category, string $client, string $verified, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Overview::mix_group( $category, $client, $verified ) );
	}

	public function test_mix_totals_by_day_and_group(): void {
		$mix = array(
			array( 'day' => self::TODAY, 'group' => 'people', 'n' => 10 ),
			array( 'day' => self::TODAY, 'group' => 'agents', 'n' => 4 ),
			array( 'day' => '2026-10-04', 'group' => 'known', 'n' => 3 ),
			array( 'day' => self::TODAY, 'group' => 'rest', 'n' => 2 ),
			array( 'day' => '2026-10-03', 'group' => 'agents', 'n' => 99 ), // Before the period.
			array( 'day' => self::TODAY, 'group' => 'bogus', 'n' => 7 ),
		);
		$out = MMSAR_Agent_Overview::compute( array(), self::ctx( array( 'people_logged' => true ) ), $mix )['mix'];
		$this->assertSame( array( 'people', 'agents', 'known', 'rest' ), $out['groups'] );
		$this->assertSame( 19, $out['total'] );
		$this->assertSame( array( 'people' => 10, 'agents' => 4, 'known' => 3, 'rest' => 2 ), $out['totals'] );
		$this->assertSame( 10, $out['daily'][ self::TODAY ]['people'] );
		$this->assertSame( 3, $out['daily']['2026-10-04']['known'] );
		$this->assertCount( 30, $out['daily'] );
	}

	public function test_people_are_dropped_when_page_views_from_people_are_not_logged(): void {
		$mix = array(
			array( 'day' => self::TODAY, 'group' => 'people', 'n' => 2 ),
			array( 'day' => self::TODAY, 'group' => 'agents', 'n' => 5 ),
		);
		$out = MMSAR_Agent_Overview::compute( array(), self::ctx( array( 'people_logged' => false ) ), $mix )['mix'];
		$this->assertSame( array( 'agents', 'known', 'rest' ), $out['groups'] );
		$this->assertArrayNotHasKey( 'people', $out['totals'] );
		$this->assertSame( 5, $out['total'] );
	}

	public function test_fetched_groups_surface_categories_with_robots_on_its_own(): void {
		$fetched = array(
			array( 'day' => self::TODAY, 'category' => 'docs', 'n' => 5 ),
			array( 'day' => self::TODAY, 'category' => 'markdown', 'n' => 4 ),
			array( 'day' => self::TODAY, 'category' => 'mcp', 'n' => 3 ),
			array( 'day' => self::TODAY, 'category' => 'webmcp', 'n' => 1 ),
			array( 'day' => self::TODAY, 'category' => 'robots', 'n' => 6 ),
			array( 'day' => self::TODAY, 'category' => 'html', 'n' => 7 ),
			array( 'day' => '2026-10-10', 'category' => 'feed', 'n' => 2 ),
			array( 'day' => self::TODAY, 'category' => 'notfound', 'n' => 8 ),
			array( 'day' => self::TODAY, 'category' => 'bogus', 'n' => 50 ),
			array( 'day' => '2026-09-01', 'category' => 'html', 'n' => 50 ), // Before the period.
		);
		$out = MMSAR_Agent_Overview::compute( array(), self::ctx(), array(), $fetched )['fetched'];
		$this->assertSame( array( 'files', 'robots', 'pages', 'notfound' ), $out['groups'] );
		$this->assertSame( array( 'files' => 13, 'robots' => 6, 'pages' => 9, 'notfound' => 8 ), $out['totals'] );
		$this->assertSame( 36, $out['total'] );
		$this->assertSame( 2, $out['daily']['2026-10-10']['pages'] );
	}

	public function test_every_surface_category_has_a_fetch_group(): void {
		foreach ( MMSAR_Agent_Log::categories() as $category ) {
			$this->assertArrayHasKey( $category, MMSAR_Agent_Overview::FETCH_OF_CATEGORY, "Surface category {$category} needs a fetch group." );
		}
	}

	public function test_agent_categories_are_real_crawler_categories(): void {
		foreach ( MMSAR_Agent_Overview::AGENT_CATEGORIES as $category ) {
			$this->assertContains( $category, MMSAR_Agent_Log::crawler_categories() );
		}
	}
}
