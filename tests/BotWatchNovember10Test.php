<?php
/**
 * The 1.73.0 round: the 2026-11-02 and 2026-11-09 bot watches, a fresh scan on 2026-11-10,
 * Claude-Agent, and Anthropic's second outbound block.
 *
 * User-agents are the exact values in miriamschwab.me's log where the name has been seen there,
 * and the operator's documented form otherwise (AdsBot-Google-Mobile, AIWebIndex-Agent,
 * Claude-Agent). Addresses are from the bundled lists in includes/data/crawler-ranges.php, and
 * the reverse-DNS hosts are the ones the live addresses resolved to on 2026-11-10.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, guards, categories, verdicts and client type for the new names.
 */
final class BotWatchNovember10Test extends TestCase {

	private const HUBSPOT_UA    = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; HubSpot Crawler; +https://www.hubspot.com) Chrome/137.0.0.0 Safari/537.36';
	private const SPIDER360_UA  = 'User-Agent:Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0; 360Spider';
	private const ADSBOT_UA     = 'AdsBot-Google (+http://www.google.com/adsbot.html)';
	private const ADSBOT_M_UA   = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 (compatible; AdsBot-Google-Mobile; +http://www.google.com/mobile/adsbot.html)';
	private const TECHLEADS_UA  = 'Mozilla/5.0 (compatible; TechLeadsBot/1.0; +https://techleads.fyi/bot)';
	private const RIVER_UA      = 'RiverBot/1.0 (+https://river.ai/bot)';
	private const AIWEB_UA      = 'AIWebIndex/2.0 (+https://lyrenth.com/bot; AI-readable web index)';
	private const AIWEB_AGENT   = 'AIWebIndex-Agent/2.0 (+https://lyrenth.com/bot; on-demand index fetch)';
	private const BAKEN_UA      = 'BakenBot/0.1 (+https://baken.dev/bakenbot)';
	private const AGENTREADY_UA = 'Mozilla/5.0 (compatible; AgentReady/1.0; +https://www.agentready.website/)';
	private const RANKZERO_UA   = 'Mozilla/5.0 (compatible; RankZeroBot/1.0; +https://rankzero.io/crawler)';
	private const KEENABLE_UA   = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; KeenableBot/1.0; +https://keenable.ai/bot)';
	/** Claude-Agent: an ordinary Chrome user-agent with the token added, as Anthropic documents. */
	private const CLAUDE_AGENT_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Claude-Agent/1.0';

	/** An address in no list, with no PTR. */
	private const ELSEWHERE = '192.0.2.77';

	/** @var array<string, mixed> */
	private array $server = array();

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		$this->server = $_SERVER;

		// No test may make a real DNS query; both lookups are filtered seams for that reason.
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		parent::tearDown();
	}

	/**
	 * Each user-agent is stored as its name, whether or not the request was filed as a crawler.
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
			'HubSpot Crawler'                  => array( self::HUBSPOT_UA, 'HubSpot Crawler' ),
			// The token is past character 80, so it has to be matched on the raw user-agent.
			'360Spider at the end of a long UA' => array( self::SPIDER360_UA, '360Spider' ),
			'AdsBot-Google'                    => array( self::ADSBOT_UA, 'AdsBot-Google' ),
			'AdsBot-Google-Mobile'             => array( self::ADSBOT_M_UA, 'AdsBot-Google' ),
			'TechLeadsBot'                     => array( self::TECHLEADS_UA, 'TechLeadsBot' ),
			'RiverBot'                         => array( self::RIVER_UA, 'RiverBot' ),
			'AIWebIndex'                       => array( self::AIWEB_UA, 'AIWebIndex' ),
			'AIWebIndex-Agent'                 => array( self::AIWEB_AGENT, 'AIWebIndex' ),
			'BakenBot'                         => array( self::BAKEN_UA, 'BakenBot' ),
			'AgentReady'                       => array( self::AGENTREADY_UA, 'AgentReady' ),
			'RankZeroBot'                      => array( self::RANKZERO_UA, 'RankZeroBot' ),
			'KeenableBot'                      => array( self::KEENABLE_UA, 'KeenableBot' ),
			'Claude-Agent inside Chrome'       => array( self::CLAUDE_AGENT_UA, 'Claude-Agent' ),
		);
	}

	/**
	 * Names left out on purpose stay unrecognised, and a guarded name needs its domain.
	 *
	 * @param string $ua Raw user-agent.
	 */
	#[DataProvider( 'notRecognised' )]
	public function test_not_recognised( string $ua ): void {
		$label = MMSAR_Agent_Log::label_for( $ua );
		$this->assertSame( MMSAR_Agent_Log::trimmed_user_agent( $ua ), $label );
		$this->assertSame( '', MMSAR_Agent_Log_Verify::claimed_name( $label ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function notRecognised(): array {
		return array(
			// The sibling Miriam did not add (2026-11-10).
			'Keenable-User'                          => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Keenable-User/1.0; +https://keenable.ai/bot)' ),
			// BakenBot's former name, deliberately not recognised. (A user-agent under the old name
			// that links https://baken.dev/bakenbot does contain `bakenbot` and is stored as BakenBot:
			// the same operator, judged by the same suffix, so that is harmless.)
			'InkaIndex'                              => array( 'InkaIndex/0.1' ),
			// A different HubSpot name, from an API range.
			'HubSpot DataHub'                        => array( 'Mozilla/5.0 (compatible; HubSpot DataHub; +https://www.hubspot.com) (Windows NT 10.0; Win64; x64)' ),
			// Guarded: no lyrenth.com.
			'AIWebIndex without its domain'          => array( 'AIWebIndex/1.0 (+https://example.com/bot)' ),
			// Guarded: no agentready.website.
			'AgentReady without its domain'          => array( 'Mozilla/5.0 (compatible; AgentReady/1.0; +https://example.com/)' ),
			// The collision the AgentReady guard exists for: AgentReadyScanner without Elementor's
			// link must not fall through to the shorter name.
			'AgentReadyScanner without its link'     => array( 'AgentReadyScanner/1.0' ),
			'AgentReadyScanner with another link'    => array( 'AgentReadyScanner/0.1 (+https://elementor.com/is-agentic)' ),
			// A plain Chrome, the control for Claude-Agent.
			'Chrome without the Claude-Agent token'  => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36' ),
		);
	}

	/**
	 * AgentReadyScanner keeps its own label when its guard is satisfied.
	 */
	public function test_agentreadyscanner_still_wins(): void {
		$ua = 'AgentReadyScanner/1.0 (+https://github.com/elementor/agent-ready-scanner)';
		$this->assertSame( 'AgentReadyScanner', MMSAR_Agent_Log::label_for( $ua ) );
		$this->assertSame( 'AgentReadyScanner', MMSAR_Agent_Log_Verify::claimed_name( MMSAR_Agent_Log::trimmed_user_agent( $ua ) ) );
	}

	/**
	 * Both stored shapes claim the right name and read as the right category.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $name     Name that must be claimed.
	 * @param string $category Category that must be read.
	 */
	#[DataProvider( 'categories' )]
	public function test_categories( string $ua, string $name, string $category ): void {
		foreach ( array( $name, MMSAR_Agent_Log::trimmed_user_agent( $ua, true ) ) as $stored ) {
			$this->assertSame( $name, MMSAR_Agent_Log_Verify::claimed_name( $stored ), $stored );
			$this->assertSame( $category, MMSAR_Agent_Log::crawler_category( $stored ), $stored );
		}
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function categories(): array {
		return array(
			'HubSpot Crawler'  => array( self::HUBSPOT_UA, 'HubSpot Crawler', 'ai-assistant' ),
			'AdsBot-Google'    => array( self::ADSBOT_UA, 'AdsBot-Google', 'other' ),
			// Miriam's call (2026-11-10), the same as BitMoversBot.
			'TechLeadsBot'     => array( self::TECHLEADS_UA, 'TechLeadsBot', 'other' ),
			'RiverBot'         => array( self::RIVER_UA, 'RiverBot', 'ai-training' ),
			'AIWebIndex'       => array( self::AIWEB_UA, 'AIWebIndex', 'ai-search' ),
			'BakenBot'         => array( self::BAKEN_UA, 'BakenBot', 'ai-search' ),
			'AgentReady'       => array( self::AGENTREADY_UA, 'AgentReady', 'scanner' ),
			'RankZeroBot'      => array( self::RANKZERO_UA, 'RankZeroBot', 'scanner' ),
			'KeenableBot'      => array( self::KEENABLE_UA, 'KeenableBot', 'ai-search' ),
		);
	}

	/**
	 * Names whose stored form is only ever the label: 360Spider's token is past the cut, and
	 * Claude-Agent is a browser string.
	 */
	public function test_label_only_categories(): void {
		$this->assertSame( 'search-engine', MMSAR_Agent_Log::crawler_category( '360Spider' ) );
		$this->assertSame( 'ai-assistant', MMSAR_Agent_Log::crawler_category( 'Claude-Agent' ) );
	}

	/**
	 * Which names have a method and which are recognise-only.
	 */
	public function test_methods(): void {
		foreach ( array( 'HubSpot Crawler', '360Spider', 'AdsBot-Google', 'RiverBot', 'AIWebIndex', 'BakenBot', 'KeenableBot', 'Claude-Agent' ) as $name ) {
			$this->assertContains( $name, MMSAR_Agent_Log::AGENTS );
			$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( $name ), $name );
		}
		foreach ( array( 'TechLeadsBot', 'AgentReady', 'RankZeroBot' ) as $name ) {
			$this->assertContains( $name, MMSAR_Agent_Log::AGENTS );
			$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( $name ), $name );
		}
		// Claude-Agent has its own group, and nothing else is judged against it.
		$this->assertSame( 'anthropic-agent', MMSAR_Agent_Log_Verify::VERIFY_RANGES['Claude-Agent'] );
		$this->assertSame( array( 'anthropic-outbound' ), MMSAR_Agent_Log_Verify::EXTRA_RANGES['Claude-User'] );
		$this->assertArrayNotHasKey( 'Claude-Agent', MMSAR_Agent_Log_Verify::EXTRA_RANGES );
		$this->assertSame( array( 'Claude-Agent' ), array_keys( MMSAR_Agent_Log_Verify::VERIFY_RANGES, 'anthropic-agent', true ) );
	}

	/**
	 * Every new group carries today's capture date, and so does every old one.
	 */
	public function test_capture_date(): void {
		$this->assertSame( '2026-11-10', MMSAR_Agent_Log_Verify::ranges_captured() );
	}

	/**
	 * Verdicts for every new method, both ways.
	 *
	 * @param string $agent    Stored agent value.
	 * @param string $ip       Stored address.
	 * @param string $host     What the reverse lookup returns.
	 * @param array  $forward  What the forward lookup returns.
	 * @param string $expected Verdict.
	 */
	#[DataProvider( 'verdicts' )]
	public function test_verdict( string $agent, string $ip, string $host, array $forward, string $expected ): void {
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => $host, 20 );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => $forward, 20 );

		$this->assertSame( $expected, MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string,3:array,4:string}>
	 */
	public static function verdicts(): array {
		return array(
			// HubSpot: range only.
			'HubSpot, a logged address in a /27'   => array( 'HubSpot Crawler', '54.174.58.229', '', array(), 'verified' ),
			'HubSpot, a single listed address'     => array( 'HubSpot Crawler', '3.125.72.185', '', array(), 'verified' ),
			'HubSpot, just past a /27'             => array( 'HubSpot Crawler', '54.174.59.1', '', array(), 'failed' ),
			// The DataHub address seen on live is HubSpot's, but not a WEB_SCRAPING one.
			'HubSpot, an API-range address'        => array( 'HubSpot Crawler', '54.174.62.129', '', array(), 'failed' ),
			// 360: range only, six /24s.
			'360Spider, a logged address'          => array( '360Spider', '180.153.236.13', '', array(), 'verified' ),
			'360Spider, the next /24'              => array( '360Spider', '180.153.237.13', '', array(), 'failed' ),
			// The Chrome version on 360's page is not an address.
			'360Spider, the page noise'            => array( '360Spider', '140.0.0.0', '', array(), 'unverifiable' ),
			// AdsBot-Google: reverse DNS on google.com only.
			'AdsBot-Google, rate-limited proxy'    => array( 'AdsBot-Google', '74.125.151.102', 'rate-limited-proxy-74-125-151-102.google.com', array( '74.125.151.102' ), 'verified' ),
			'AdsBot-Google, googlebot.com host'    => array( 'AdsBot-Google', '66.249.66.1', 'crawl-66-249-66-1.googlebot.com', array( '66.249.66.1' ), 'failed' ),
			'AdsBot-Google, no PTR'                => array( 'AdsBot-Google', self::ELSEWHERE, '', array(), 'nodns' ),
			// RiverBot: list, then reverse DNS.
			'RiverBot, listed'                     => array( 'RiverBot', '52.72.41.255', '', array(), 'verified' ),
			'RiverBot, unlisted but forward-confirmed' => array( 'RiverBot', '3.80.1.2', 'crawl-3-80-1-2.bot.river.ai', array( '3.80.1.2' ), 'verified' ),
			'RiverBot, bare river.ai host'         => array( 'RiverBot', '3.80.1.2', 'www.river.ai', array( '3.80.1.2' ), 'failed' ),
			'RiverBot, unlisted, no PTR'           => array( 'RiverBot', self::ELSEWHERE, '', array(), 'failed' ),
			// AIWebIndex: list, then reverse DNS; stored as the bare name.
			'AIWebIndex, listed'                   => array( 'AIWebIndex', '138.201.157.96', '', array(), 'verified' ),
			'AIWebIndex, unlisted but forward-confirmed' => array( 'AIWebIndex', '5.9.1.2', 'crawler-30.lyrenth.com', array( '5.9.1.2' ), 'verified' ),
			'AIWebIndex, lookalike host'           => array( 'AIWebIndex', '5.9.1.2', 'crawler.notlyrenth.com', array( '5.9.1.2' ), 'failed' ),
			// BakenBot: reverse DNS only.
			'BakenBot, its host'                   => array( 'BakenBot', '213.239.220.146', 'bakenbot.baken.dev', array( '213.239.220.146' ), 'verified' ),
			'BakenBot, forward does not confirm'   => array( 'BakenBot', '213.239.220.147', 'bakenbot.baken.dev', array( '213.239.220.146' ), 'failed' ),
			'BakenBot, no PTR'                     => array( 'BakenBot', self::ELSEWHERE, '', array(), 'nodns' ),
			// KeenableBot: range only.
			'KeenableBot, a logged address'        => array( 'KeenableBot', '35.255.22.29', '', array(), 'verified' ),
			'KeenableBot, one over'                => array( 'KeenableBot', '35.255.22.30', '', array(), 'failed' ),
			// Recognise-only names.
			'TechLeadsBot'                         => array( 'TechLeadsBot', '65.109.16.46', '', array(), 'unverifiable' ),
			'AgentReady'                           => array( 'AgentReady', '34.239.175.31', '', array(), 'unverifiable' ),
			'RankZeroBot'                          => array( 'RankZeroBot', '2.28.104.164', '', array(), 'unverifiable' ),
			// Claude-Agent: its SAIL list and nothing else.
			'Claude-Agent, in the SAIL list'       => array( 'Claude-Agent', '34.31.25.14', '', array(), 'verified' ),
			'Claude-Agent, last address listed'    => array( 'Claude-Agent', '136.71.21.147', '', array(), 'verified' ),
			'Claude-Agent, outside it'             => array( 'Claude-Agent', '34.31.25.15', '', array(), 'failed' ),
			'Claude-Agent from the crawler range'  => array( 'Claude-Agent', '216.73.216.10', '', array(), 'failed' ),
			'Claude-Agent from the outbound range' => array( 'Claude-Agent', '160.79.106.21', '', array(), 'failed' ),
			// And the SAIL list verifies nobody else.
			'Claude-User from a SAIL address'      => array( 'Claude-User', '34.31.25.14', '', array(), 'failed' ),
			'ClaudeBot from a SAIL address'        => array( 'ClaudeBot', '34.31.25.14', '', array(), 'failed' ),
			// Anthropic's second outbound block (2026-11-10), scoped to Claude-User like the first.
			'Claude-User, new /20 first address'   => array( 'Claude-User', '153.61.208.0', '', array(), 'unverifiable' ),
			'Claude-User, new /20'                 => array( 'Claude-User', '153.61.208.17', '', array(), 'verified' ),
			'Claude-User, new /20 last address'    => array( 'Claude-User', '153.61.223.255', '', array(), 'verified' ),
			'Claude-User, just past the /20'       => array( 'Claude-User', '153.61.224.1', '', array(), 'failed' ),
			'Claude-User, just below the /20'      => array( 'Claude-User', '153.61.207.255', '', array(), 'failed' ),
			'ClaudeBot from the new /20'           => array( 'ClaudeBot', '153.61.208.17', '', array(), 'failed' ),
			'Claude-SearchBot from the new /20'    => array( 'Claude-SearchBot', '153.61.208.17', '', array(), 'failed' ),
			// OpenAI's added /27 in chatgpt-user.json.
			'ChatGPT-User, added /27'              => array( 'ChatGPT-User', '51.57.158.100', '', array(), 'verified' ),
			'ChatGPT-User, just past it'           => array( 'ChatGPT-User', '51.57.158.128', '', array(), 'failed' ),
		);
	}

	/**
	 * A Claude-Agent visit is browser-shaped, and is filed as a crawler, not a person.
	 *
	 * Detection runs before the browser test, so a recognised token wins. The Summary groups by
	 * category before client type, so even a row stored as a browser would land under agents.
	 */
	public function test_claude_agent_is_not_a_person(): void {
		$detect = new ReflectionMethod( MMSAR_Agent_Log::class, 'detect_client_type' );
		$detect->setAccessible( true );

		$navigation = array(
			'HTTP_SEC_FETCH_MODE'  => 'navigate',
			'HTTP_SEC_FETCH_DEST'  => 'document',
			'HTTP_SEC_CH_UA'       => '"Chromium";v="142", "Google Chrome";v="142"',
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
		);

		$_SERVER = array_merge( $this->server, $navigation, array( 'HTTP_USER_AGENT' => self::CLAUDE_AGENT_UA ) );
		$this->assertSame( MMSAR_Agent_Log::CLIENT_CRAWLER, $detect->invoke( null ) );

		// The control: the same navigation from Chrome without the token is a browser.
		$_SERVER['HTTP_USER_AGENT'] = str_replace( ' Claude-Agent/1.0', '', self::CLAUDE_AGENT_UA );
		$this->assertSame( MMSAR_Agent_Log::CLIENT_BROWSER, $detect->invoke( null ) );

		foreach ( array( MMSAR_Agent_Log::CLIENT_CRAWLER, MMSAR_Agent_Log::CLIENT_BROWSER ) as $client ) {
			$this->assertSame( 'agents', MMSAR_Agent_Overview::mix_group( 'ai-assistant', $client, 'verified' ) );
			$this->assertSame( 'rest', MMSAR_Agent_Overview::mix_group( 'ai-assistant', $client, 'failed' ) );
		}
	}
}
