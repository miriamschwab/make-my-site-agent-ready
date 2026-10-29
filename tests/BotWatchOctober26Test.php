<?php
/**
 * The 2026-10-26 bot watch: KakaoBot, FusionDiscoverBot, agenticweb-probe and ev-crawler.
 *
 * The user-agents are the exact values in miriamschwab.me's log, except the two KakaoBot variants
 * marked below, whose full strings the report did not record.
 *
 * KakaoBot is verified by the eight /27s Kakao publishes. The other three are recognise-only, so
 * every stored shape of theirs must reach `unverifiable` and nothing else.
 * Bravebot, on hold in the same report, is pinned as not added (Miriam, 2026-10-27).
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, categories, the ev-crawler disclosure guard, KakaoBot's ranges and the verdicts.
 */
final class BotWatchOctober26Test extends TestCase {

	/** KakaoBot as it arrived on live, and as Kakao documents it. */
	private const KAKAO_UA = 'Mozilla/5.0 (compatible; KakaoBot/1.0; +https://cs.kakao.com/helps_html/1073213012)';

	/**
	 * The variant one live request carried. Only its URL was recorded; the rest is the seen form.
	 */
	private const KAKAO_DEV_UA = 'Mozilla/5.0 (compatible; KakaoBot/1.0; +https://developers.kakao.com/bot)';

	/**
	 * A Chrome-shaped form, as Kakao's page describes one. Constructed: not seen on live.
	 */
	private const KAKAO_CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 KakaoBot/1.0';

	/** FusionDiscoverBot, documented and seen identically. */
	private const FUSION_UA = 'FusionDiscoverBot/0.3 (+https://fusiondiscover.com/crawler.html)';

	/** agenticweb-probe. */
	private const AGENTICWEB_UA = 'agenticweb-probe/1 (+https://agenticweb.wiki/about/)';

	/** ev-crawler, Headline's crawler. */
	private const EV_UA = 'Mozilla/5.0 (compatible; ev-crawler/1.0; +https://headline.com/web-crawler)';

	/**
	 * Real KakaoBot addresses from live. The first is inside Kakao's published `121.53.220.224/27`;
	 * the second fetched robots.txt from outside every published block on 2026-10-27.
	 */
	private const KAKAO_IN_IP  = '121.53.220.248';
	private const KAKAO_OUT_IP = '121.53.80.5';

	/** Bravebot as stored on live, already cut. On hold, so it must stay unrecognised. */
	private const BRAVE_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Bravebot/1.0; +https://search.brave.com/help/brave-search-crawler)';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();

		// No test may make a real DNS query; both lookups are filtered seams for that reason.
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	/**
	 * Each user-agent is stored as its own name, in both shapes label_for() can produce.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Name that must be stored.
	 */
	#[DataProvider( 'labels' )]
	public function test_label( string $ua, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua ) );
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua, true ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function labels(): array {
		return array(
			'KakaoBot, live'           => array( self::KAKAO_UA, 'KakaoBot' ),
			'KakaoBot, developers URL' => array( self::KAKAO_DEV_UA, 'KakaoBot' ),
			'KakaoBot, Chrome shape'   => array( self::KAKAO_CHROME_UA, 'KakaoBot' ),
			'FusionDiscoverBot'        => array( self::FUSION_UA, 'FusionDiscoverBot' ),
			'agenticweb-probe'         => array( self::AGENTICWEB_UA, 'agenticweb-probe' ),
			'ev-crawler'               => array( self::EV_UA, 'ev-crawler' ),
		);
	}

	/**
	 * Every stored shape claims the right name and reads as the right category.
	 *
	 * Rows logged before a name was recognised keep the trimmed user-agent, so both that and the
	 * label have to resolve the same way — that is what lets Re-check reopen the earlier rows.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $name     Name that must be claimed.
	 * @param string $category Category that must be read.
	 */
	#[DataProvider( 'shapesAndCategories' )]
	public function test_stored_shapes( string $ua, string $name, string $category ): void {
		foreach ( array( $name, MMSAR_Agent_Log::trimmed_user_agent( $ua ) ) as $stored ) {
			$this->assertSame( $name, MMSAR_Agent_Log_Verify::claimed_name( $stored ) );
			$this->assertSame( $category, MMSAR_Agent_Log::crawler_category( $stored ) );
		}
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function shapesAndCategories(): array {
		return array(
			'KakaoBot is search-engine'          => array( self::KAKAO_UA, 'KakaoBot', 'search-engine' ),
			'FusionDiscoverBot is search-engine' => array( self::FUSION_UA, 'FusionDiscoverBot', 'search-engine' ),
			'agenticweb-probe is scanner'        => array( self::AGENTICWEB_UA, 'agenticweb-probe', 'scanner' ),
			// Deal sourcing for a venture capital firm.
			'ev-crawler is other'                => array( self::EV_UA, 'ev-crawler', 'other' ),
		);
	}

	/**
	 * KakaoBot is checked against Kakao's published blocks, and by nothing else.
	 */
	public function test_kakaobot_is_range_only(): void {
		$this->assertSame( 'kakao', MMSAR_Agent_Log_Verify::VERIFY_RANGES['KakaoBot'] );
		$this->assertArrayNotHasKey( 'KakaoBot', MMSAR_Agent_Log_Verify::VERIFY_HOSTS );
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'KakaoBot' ) );
	}

	/**
	 * KakaoBot from a published block is verified, and from anywhere else is failed, in both shapes.
	 *
	 * @param string $agent    Value as stored in the log's `agent` column.
	 * @param string $ip       Value as stored in the log's `ip` column.
	 * @param string $expected Expected verdict.
	 */
	#[DataProvider( 'kakaoVerdicts' )]
	public function test_kakaobot_verdict( string $agent, string $ip, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function kakaoVerdicts(): array {
		$stored = MMSAR_Agent_Log::trimmed_user_agent( self::KAKAO_UA );
		return array(
			'label, inside a block'      => array( 'KakaoBot', self::KAKAO_IN_IP, 'verified' ),
			'stored row, inside a block' => array( $stored, self::KAKAO_IN_IP, 'verified' ),
			'last address of a block'    => array( 'KakaoBot', '203.133.185.255', 'verified' ),
			'one below a block'          => array( 'KakaoBot', '121.53.90.223', 'failed' ),
			'the live robots.txt fetch'  => array( 'KakaoBot', self::KAKAO_OUT_IP, 'failed' ),
			'stored row, outside'        => array( $stored, self::KAKAO_OUT_IP, 'failed' ),
		);
	}

	/**
	 * The other three are recognise-only: recognised, and with nothing to check them against.
	 *
	 * @param string $name Agent name.
	 */
	#[DataProvider( 'recogniseOnly' )]
	public function test_recognise_only( string $name ): void {
		$this->assertContains( $name, MMSAR_Agent_Log::AGENTS );
		$this->assertArrayNotHasKey( $name, MMSAR_Agent_Log_Verify::VERIFY_HOSTS );
		$this->assertArrayNotHasKey( $name, MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( $name ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function recogniseOnly(): array {
		return array(
			'FusionDiscoverBot' => array( 'FusionDiscoverBot' ),
			'agenticweb-probe'  => array( 'agenticweb-probe' ),
			'ev-crawler'        => array( 'ev-crawler' ),
		);
	}

	/**
	 * ev-crawler is only claimed when the user-agent also discloses headline.com.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Name that must be stored.
	 */
	#[DataProvider( 'evGuard' )]
	public function test_ev_crawler_guard( string $ua, string $expected ): void {
		$this->assertSame( 'headline.com', MMSAR_Agent_Log::AGENT_DISCLOSURES['ev-crawler'] );
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function evGuard(): array {
		return array(
			'the form it sends'      => array( self::EV_UA, 'ev-crawler' ),
			'no domain, not claimed' => array( 'my-ev-crawler/0.1', 'my-ev-crawler/0.1' ),
		);
	}

	/**
	 * Bravebot is held (Miriam, 2026-10-27): Brave's own crawler page says it sends no
	 * differentiated user-agent, and the reverse-DNS pattern seen is undocumented. Delete this test
	 * if it is added.
	 */
	public function test_bravebot_is_not_added(): void {
		$this->assertNotContains( 'Bravebot', MMSAR_Agent_Log::AGENTS );
		$this->assertArrayNotHasKey( 'Bravebot', MMSAR_Agent_Log::CRAWLER_CATEGORIES );
		$this->assertSame( '', MMSAR_Agent_Log_Verify::claimed_name( MMSAR_Agent_Log::trimmed_user_agent( self::BRAVE_UA ) ) );
	}

	/**
	 * Every stored shape of the three recognise-only names reaches `unverifiable`, whatever the
	 * address.
	 *
	 * @param string $agent Value as stored in the log's `agent` column.
	 * @param string $ip    Value as stored in the log's `ip` column.
	 */
	#[DataProvider( 'verdicts' )]
	public function test_verdict( string $agent, string $ip ): void {
		$this->assertSame( 'unverifiable', MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function verdicts(): array {
		return array(
			'FusionDiscoverBot'             => array( 'FusionDiscoverBot', '198.51.100.20' ),
			'FusionDiscoverBot, stored row' => array( self::FUSION_UA, '198.51.100.20' ),
			'agenticweb-probe'              => array( 'agenticweb-probe', '2a06:98c0:3600::103' ),
			'agenticweb-probe, stored row'  => array( self::AGENTICWEB_UA, '2a06:98c0:3600::103' ),
			'ev-crawler'                    => array( 'ev-crawler', '216.218.200.5' ),
			'ev-crawler, stored row'        => array( MMSAR_Agent_Log::trimmed_user_agent( self::EV_UA ), '216.218.200.5' ),
		);
	}
}
