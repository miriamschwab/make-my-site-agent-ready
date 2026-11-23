<?php
/**
 * The 1.75.0 round: Google-Agent, OpenAI's connector range, the 2026-11-23 range refresh, the
 * bots from the 2026-10-29, 2026-11-16 and 2026-11-23 watches, and the AgentReadyScanner guard.
 *
 * User-agents are the exact values in miriamschwab.me's log where the name has been seen there,
 * and the operator's documented form otherwise (Google-Agent, TinEye-bot). Addresses are from the
 * bundled lists in includes/data/crawler-ranges.php as captured on 2026-11-23, and the reverse-DNS
 * hosts are the shapes the live addresses resolved to that day.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, guards, categories, verdicts and client type for the new names and ranges.
 *
 * ethicrawl was range-verified in dev.1 and is recognise-only from dev.2.
 */
final class BotWatchNovember23Test extends TestCase {

	/** Google-Agent as Google documents it, desktop and mobile, with a real Chrome version. */
	private const GOOGLE_AGENT_UA   = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-Agent; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-agent) Chrome/142.0.0.0 Safari/537.36';
	private const GOOGLE_AGENT_M_UA = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36 (compatible; Google-Agent; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-agent)';
	/** The mobile form seen on live: the token is past character 80. */
	private const INSPECTION_UA   = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.8103.50 Mobile Safari/537.36 (compatible; Google-InspectionTool/1.0;)';
	private const SDTT_UA         = 'Mozilla/5.0 (compatible; Google-Structured-Data-Testing-Tool +https://search.google.com/structured-data/testing-tool)';
	private const SEOBILITY_UA    = 'SeobilityBot (SEO Tool; https://www.seobility.net/sites/bot.html)';
	private const KOSTO_UA        = 'KostoBot/1.0 (+https://getkosto.com/bot)';
	private const WATR_UA         = 'Mozilla/5.0 (compatible; watrsearch/0.1; +https://search.watr.lol/about#crawler)';
	private const WOWLINK_UA      = 'getwowlink-prospector/1.0 (+https://getwowlink.com/bot)';
	private const KAUPR_UA        = 'Mozilla/5.0 (compatible; Kaupr/1.0; +https://kaupr.com/about/crawler)';
	private const KAUPR_BARE_UA   = 'Mozilla/5.0 (compatible; Kaupr/1.0)';
	private const ETHICRAWL_UA    = 'ethicrawl/0.1 (Ethical crawler respecting robots and AI directives; https://ethicrawl.ai/crawler.html; contact@ethicrawl.ai)';
	private const LINKEDIN_UA     = 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)';
	private const TINEYE_UA       = 'Mozilla/5.0 (compatible; TinEye-Web/2.0.1; +http://www.tineye.com/cr.html)';
	private const T2_UA           = 'Mozilla/5.0 (compatible; T2Bot/1.0; +https://t2ind.com/bot)';
	private const CONVERTLYFT_UA  = 'ConvertlyftBot/1.0 (+https://convertlyft.com/bot)';
	private const INTERNETDATA_UA = 'Mozilla/5.0 (compatible; InternetData.io/1.0; +https://internetdata.io/scan)';
	/** Elementor's scanner: the old link and the one it sends since November 2026. */
	private const SCANNER_OLD_UA = 'AgentReadyScanner/0.1 (+https://github.com/elementor/agent-ready-scanner)';
	private const SCANNER_NEW_UA = 'AgentReadyScanner/0.1 (+https://elementor.com/checker)';

	/** Addresses from the bundled groups. */
	private const PERPLEXITY_NEW   = '3.41.218.161';
	private const CLAUDE_AGENT_NEW = '153.61.208.16';
	private const GOOGLE_AGENT_V4  = '136.121.24.17';
	private const GOOGLE_AGENT_V6  = '2001:4860:c::42';
	private const SEOBILITY_V4     = '5.75.139.32';
	private const SEOBILITY_V6_64  = '2a01:4f8:c0c:1ead::17';
	private const ETHICRAWL_IP     = '83.217.175.180';
	/** In chatgpt-connectors.json and in no other bundled group. */
	private const CONNECTOR_ONLY   = '4.7.10.113';

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
			'Google-Agent, desktop'                  => array( self::GOOGLE_AGENT_UA, 'Google-Agent' ),
			'Google-Agent, mobile'                   => array( self::GOOGLE_AGENT_M_UA, 'Google-Agent' ),
			'Google-InspectionTool past char 80'     => array( self::INSPECTION_UA, 'Google-InspectionTool' ),
			'Google-InspectionTool, desktop'         => array( 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0)', 'Google-InspectionTool' ),
			'Structured Data Testing Tool'           => array( self::SDTT_UA, 'Google-Structured-Data-Testing-Tool' ),
			'SeobilityBot'                           => array( self::SEOBILITY_UA, 'SeobilityBot' ),
			'KostoBot'                               => array( self::KOSTO_UA, 'KostoBot' ),
			'watrsearch, lowercase'                  => array( self::WATR_UA, 'watrsearch' ),
			'getwowlink-prospector'                  => array( self::WOWLINK_UA, 'getwowlink-prospector' ),
			'Kaupr'                                  => array( self::KAUPR_UA, 'Kaupr' ),
			'Kaupr, bare form'                       => array( self::KAUPR_BARE_UA, 'Kaupr' ),
			'ethicrawl, lowercase'                   => array( self::ETHICRAWL_UA, 'ethicrawl' ),
			'ethicrawl, documented form'             => array( 'ethicrawl/0.1 (+https://ethicrawl.ai/crawler.html)', 'ethicrawl' ),
			'LinkedInBot'                            => array( self::LINKEDIN_UA, 'LinkedInBot' ),
			'TinEye-Web'                             => array( self::TINEYE_UA, 'TinEye' ),
			'TinEye-bot'                             => array( 'TinEye-bot/1.31 (+http://www.tineye.com/crawler.html)', 'TinEye' ),
			'TinEye-bot-live'                        => array( 'TinEye-bot-live/1.31 (+http://www.tineye.com/crawler.html)', 'TinEye' ),
			'T2Bot'                                  => array( self::T2_UA, 'T2Bot' ),
			'ConvertlyftBot'                         => array( self::CONVERTLYFT_UA, 'ConvertlyftBot' ),
			'InternetData.io'                        => array( self::INTERNETDATA_UA, 'InternetData.io' ),
			'AgentReadyScanner, repository link'     => array( self::SCANNER_OLD_UA, 'AgentReadyScanner' ),
			'AgentReadyScanner, elementor.com link'  => array( self::SCANNER_NEW_UA, 'AgentReadyScanner' ),
		);
	}

	/**
	 * Forms that must not be recognised as the new names.
	 *
	 * @param string $ua        Raw user-agent.
	 * @param string $forbidden Name it must not be stored as.
	 */
	#[DataProvider( 'not_recognised' )]
	public function test_not_recognised( string $ua, string $forbidden ): void {
		$this->assertNotSame( $forbidden, MMSAR_Agent_Log::label_for( $ua ) );
		$this->assertNotSame( $forbidden, MMSAR_Agent_Log::label_for( $ua, true ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function not_recognised(): array {
		return array(
			// The guard still holds: no Elementor link, no claim. Nor does it fall to `AgentReady`.
			'AgentReadyScanner, bare'           => array( 'AgentReadyScanner/1.0', 'AgentReadyScanner' ),
			'AgentReadyScanner, bare, not AgentReady' => array( 'AgentReadyScanner/1.0', 'AgentReady' ),
			'AgentReadyScanner, other link'     => array( 'AgentReadyScanner/0.1 (+https://example.com/checker)', 'AgentReadyScanner' ),
			'checker link, not AgentReady'      => array( self::SCANNER_NEW_UA, 'AgentReady' ),
			// A short token, guarded by its domain.
			'T2Bot without t2ind.com'           => array( 'Mozilla/5.0 (compatible; T2Bot/1.0)', 'T2Bot' ),
			// Convertlyft's retired generic name is deliberately not recognised.
			'SEO-Spider-Pro'                    => array( 'SEO-Spider-Pro/1.0', 'ConvertlyftBot' ),
			// A plain Chrome is not Google-Agent.
			'Chrome without the token'          => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 'Google-Agent' ),
		);
	}

	/**
	 * Categories, as decided on 2026-11-23.
	 */
	public function test_categories(): void {
		$expected = array(
			'Google-Agent'                        => 'ai-assistant',
			'Google-InspectionTool'               => 'seo-tool',
			'Google-Structured-Data-Testing-Tool' => 'seo-tool',
			'SeobilityBot'                        => 'seo-tool',
			'KostoBot'                            => 'seo-tool',
			'watrsearch'                          => 'search-engine',
			'getwowlink-prospector'               => 'seo-tool',
			'Kaupr'                               => 'other',
			'ethicrawl'                           => 'ai-training',
			'LinkedInBot'                         => 'other',
			'TinEye'                              => 'search-engine',
			'T2Bot'                               => 'search-engine',
			'ConvertlyftBot'                      => 'seo-tool',
			'InternetData.io'                     => 'scanner',
		);
		foreach ( $expected as $name => $category ) {
			$this->assertContains( $name, MMSAR_Agent_Log::AGENTS, $name );
			$this->assertSame( $category, MMSAR_Agent_Log::crawler_category( $name ), $name );
		}
	}

	/**
	 * Which names have a method and which are recognise-only, and how the new groups are scoped.
	 */
	public function test_methods(): void {
		foreach ( array( 'Google-Agent', 'Google-InspectionTool', 'Google-Structured-Data-Testing-Tool', 'SeobilityBot' ) as $name ) {
			$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( $name ), $name );
		}
		foreach ( array( 'ethicrawl', 'KostoBot', 'watrsearch', 'getwowlink-prospector', 'Kaupr', 'LinkedInBot', 'TinEye', 'T2Bot', 'ConvertlyftBot', 'InternetData.io' ) as $name ) {
			$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( $name ), $name );
		}
		// Each new group verifies its one agent and nothing else.
		$this->assertSame( array( 'Google-Agent' ), array_keys( MMSAR_Agent_Log_Verify::VERIFY_RANGES, 'google-agent', true ) );
		$this->assertSame( array( 'SeobilityBot' ), array_keys( MMSAR_Agent_Log_Verify::VERIFY_RANGES, 'seobility', true ) );
		$this->assertSame( array( 'openai-outbound' ), MMSAR_Agent_Log_Verify::EXTRA_RANGES['ChatGPT-User'] );
		$this->assertNotContains( 'openai-outbound', MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		foreach ( array( 'GPTBot', 'OAI-SearchBot' ) as $name ) {
			$this->assertArrayNotHasKey( $name, MMSAR_Agent_Log_Verify::EXTRA_RANGES );
		}
		// Kaupr's, InternetData.io's and ethicrawl's lists are deliberately not bundled (ethicrawl's
		// was in dev.1 and dropped in dev.2: it did not cover the address its logged requests came from).
		$ranges = MMSAR_Agent_Log_Verify::verify_ranges();
		$this->assertArrayNotHasKey( 'ethicrawl', $ranges );
		$this->assertArrayNotHasKey( 'ethicrawl', MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		$this->assertArrayNotHasKey( 'kaupr', $ranges );
		$this->assertArrayNotHasKey( 'internetdata', $ranges );
		// Google-Agent's group carries IPv6 as well as IPv4.
		$this->assertNotEmpty( $ranges['google-agent']['v6'] );
		$this->assertNotEmpty( $ranges['seobility']['v6'] );
	}

	/**
	 * Every group was captured on 2026-11-23, the new ones included.
	 */
	public function test_capture_dates(): void {
		foreach ( MMSAR_Agent_Log_Verify::verify_ranges() as $group => $data ) {
			$this->assertSame( '2026-11-23', $data['captured'], $group );
		}
	}

	/**
	 * Verdicts for every new method and every refreshed address, both ways.
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
			// Perplexity's added /29 (perplexitybot.json, 2026-11-18).
			'PerplexityBot, new /29'                => array( 'PerplexityBot', self::PERPLEXITY_NEW, '', array(), 'verified' ),
			'PerplexityBot, new /29 last address'   => array( 'PerplexityBot', '3.41.218.167', '', array(), 'verified' ),
			'PerplexityBot, just past the /29'      => array( 'PerplexityBot', '3.41.218.168', '', array(), 'failed' ),
			'Perplexity-User, new /29'              => array( 'Perplexity-User', self::PERPLEXITY_NEW, '', array(), 'verified' ),
			// Claude-Agent's added address (SAIL file, 2026-11-14), inside the outbound /20.
			'Claude-Agent, new SAIL address'        => array( 'Claude-Agent', self::CLAUDE_AGENT_NEW, '', array(), 'verified' ),
			'Claude-Agent, next address'            => array( 'Claude-Agent', '153.61.208.17', '', array(), 'failed' ),
			'ClaudeBot from the new SAIL address'   => array( 'ClaudeBot', self::CLAUDE_AGENT_NEW, '', array(), 'failed' ),
			// Google-Agent: its own file, IPv4 and IPv6, and nothing else.
			'Google-Agent, IPv4 block'              => array( 'Google-Agent', self::GOOGLE_AGENT_V4, '', array(), 'verified' ),
			'Google-Agent, the /16'                 => array( 'Google-Agent', '136.122.200.9', '', array(), 'verified' ),
			'Google-Agent, IPv6 /124'               => array( 'Google-Agent', self::GOOGLE_AGENT_V6, '', array(), 'verified' ),
			'Google-Agent, past the IPv6 block'     => array( 'Google-Agent', '2001:4860:c::1:1', '', array(), 'failed' ),
			'Google-Agent, outside'                 => array( 'Google-Agent', '136.121.32.9', '', array(), 'failed' ),
			// No suffix is a method for it, so a Googlebot host does not rescue an unlisted address.
			'Google-Agent, googlebot.com host'      => array( 'Google-Agent', '66.249.70.103', 'crawl-66-249-70-103.googlebot.com', array( '66.249.70.103' ), 'failed' ),
			'Google-Agent, stored browser shape'    => array( '(X11; Linux x86_64) (KHTML, like Gecko; compatible; Google-Agent; +https://develop', self::GOOGLE_AGENT_V4, '', array(), 'verified' ),
			// Google's testing tools, by reverse DNS.
			'InspectionTool, googlebot.com'         => array( 'Google-InspectionTool', '66.249.70.103', 'crawl-66-249-70-103.googlebot.com', array( '66.249.70.103' ), 'verified' ),
			'InspectionTool, google.com'            => array( 'Google-InspectionTool', '64.233.172.71', 'google-proxy-64-233-172-71.google.com', array( '64.233.172.71' ), 'verified' ),
			'InspectionTool, lookalike host'        => array( 'Google-InspectionTool', '203.0.113.9', 'crawl.googlebot.com.example.net', array( '203.0.113.9' ), 'failed' ),
			'InspectionTool, forward disagrees'     => array( 'Google-InspectionTool', '66.249.70.104', 'crawl-66-249-70-103.googlebot.com', array( '66.249.70.103' ), 'failed' ),
			'InspectionTool, no PTR'                => array( 'Google-InspectionTool', self::ELSEWHERE, '', array(), 'nodns' ),
			'SDTT, google.com'                      => array( 'Google-Structured-Data-Testing-Tool', '64.233.172.71', 'google-proxy-64-233-172-71.google.com', array( '64.233.172.71' ), 'verified' ),
			'SDTT, googlebot.com is not its suffix' => array( 'Google-Structured-Data-Testing-Tool', '66.249.70.103', 'crawl-66-249-70-103.googlebot.com', array( '66.249.70.103' ), 'failed' ),
			'SDTT, stored row'                      => array( '(compatible; Google-Structured-Data-Testing-Tool +https://search.google.com/struct', '64.233.172.71', 'google-proxy-64-233-172-71.google.com', array( '64.233.172.71' ), 'verified' ),
			// Seobility: list only, both families, the /64s included.
			'SeobilityBot, IPv4 listed'             => array( 'SeobilityBot', self::SEOBILITY_V4, '', array(), 'verified' ),
			'SeobilityBot, IPv6 /64'                => array( 'SeobilityBot', self::SEOBILITY_V6_64, '', array(), 'verified' ),
			'SeobilityBot, one over'                => array( 'SeobilityBot', '5.75.139.33', '', array(), 'failed' ),
			'SeobilityBot, stored row'              => array( 'SeobilityBot (SEO Tool; https://www.seobility.net/sites/bot.html)', self::SEOBILITY_V4, '', array(), 'verified' ),
			// ethicrawl: recognise-only since dev.2, so neither its published address nor any other is a verdict.
			'ethicrawl, its published address'     => array( 'ethicrawl', self::ETHICRAWL_IP, '', array(), 'unverifiable' ),
			'ethicrawl, stored raw row'             => array( 'ethicrawl/0.1 (Ethical crawler respecting robots and AI directives; https://ethi', self::ELSEWHERE, '', array(), 'unverifiable' ),
			// OpenAI's connector range: ChatGPT-User only.
			'ChatGPT-User, connector-only address'  => array( 'ChatGPT-User', self::CONNECTOR_ONLY, '', array(), 'verified' ),
			'ChatGPT-User, connector single'        => array( 'ChatGPT-User', '3.12.200.18', '', array(), 'verified' ),
			'GPTBot, connector-only address'        => array( 'GPTBot', self::CONNECTOR_ONLY, '', array(), 'failed' ),
			'OAI-SearchBot, connector-only address' => array( 'OAI-SearchBot', self::CONNECTOR_ONLY, '', array(), 'failed' ),
			'ChatGPT-User, outside both'            => array( 'ChatGPT-User', self::ELSEWHERE, '', array(), 'failed' ),
			// Recognise-only names.
			'KostoBot'                              => array( 'KostoBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'watrsearch'                            => array( 'watrsearch', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'getwowlink-prospector'                 => array( 'getwowlink-prospector', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'Kaupr, stored bare row'                => array( '(compatible; Kaupr/1.0)', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'LinkedInBot, linkedin.com host'        => array( 'LinkedInBot', self::ELSEWHERE, 'example.linkedin.com', array( self::ELSEWHERE ), 'unverifiable' ),
			'TinEye'                                => array( 'TinEye', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'T2Bot, stored row'                     => array( '(compatible; T2Bot/1.0; +https://t2ind.com/bot)', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'ConvertlyftBot'                        => array( 'ConvertlyftBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'InternetData.io'                       => array( 'InternetData.io', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'AgentReadyScanner, new link stored'    => array( 'AgentReadyScanner/0.1 (+https://elementor.com/checker)', self::ELSEWHERE, '', array(), 'unverifiable' ),
			// Unguarded raw rows stay unclaimed.
			'T2Bot without its domain'              => array( 'Mozilla/5.0 (compatible; T2Bot/1.0)', self::ELSEWHERE, '', array(), 'unclaimed' ),
			'AgentReadyScanner, bare'               => array( 'AgentReadyScanner/1.0', self::ELSEWHERE, '', array(), 'unclaimed' ),
		);
	}

	/**
	 * Stored raw rows from live are claimed as the new names, which is what lets Re-check give
	 * them a verdict.
	 */
	public function test_stored_rows_are_claimed(): void {
		$rows = array(
			'SeobilityBot (SEO Tool; https://www.seobility.net/sites/bot.'      => 'SeobilityBot',
			'KostoBot/1.0 (+https://getkosto.com/bot)'                           => 'KostoBot',
			'(compatible; watrsearch/0.1; +https://search.watr.lol/about#'       => 'watrsearch',
			'getwowlink-prospector/1.0 (+https://getwowlink.com/bot)'            => 'getwowlink-prospector',
			'(compatible; Kaupr/1.0)'                                             => 'Kaupr',
			'(compatible; Kaupr/1.0; +https://kaupr.com/about/crawler)'          => 'Kaupr',
			'(compatible; Google-Structured-Data-Testing-Tool +https://se'       => 'Google-Structured-Data-Testing-Tool',
			'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient '       => 'LinkedInBot',
			'ConvertlyftBot/1.0 (+https://convertlyft.com/bot)'                  => 'ConvertlyftBot',
			'(compatible; InternetData.io/1.0; +https://internetdata.io/s'       => 'InternetData.io',
			'AgentReadyScanner/0.1 (+https://elementor.com/checker)'             => 'AgentReadyScanner',
		);
		foreach ( $rows as $stored => $name ) {
			$this->assertSame( $name, MMSAR_Agent_Log_Verify::claimed_name( $stored ), $stored );
		}
	}

	/**
	 * A Google-Agent visit is browser-shaped, and is filed as a crawler, not a person.
	 */
	public function test_google_agent_is_not_a_person(): void {
		$detect = new ReflectionMethod( MMSAR_Agent_Log::class, 'detect_client_type' );
		$detect->setAccessible( true );

		$navigation = array(
			'HTTP_SEC_FETCH_MODE'  => 'navigate',
			'HTTP_SEC_FETCH_DEST'  => 'document',
			'HTTP_SEC_CH_UA'       => '"Chromium";v="142", "Google Chrome";v="142"',
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
		);

		$_SERVER = array_merge( $this->server, $navigation, array( 'HTTP_USER_AGENT' => self::GOOGLE_AGENT_UA ) );
		$this->assertSame( MMSAR_Agent_Log::CLIENT_CRAWLER, $detect->invoke( null ) );

		foreach ( array( MMSAR_Agent_Log::CLIENT_CRAWLER, MMSAR_Agent_Log::CLIENT_BROWSER ) as $client ) {
			$this->assertSame( 'agents', MMSAR_Agent_Overview::mix_group( 'ai-assistant', $client, 'verified' ) );
		}
	}

	/**
	 * The disclosure helper takes one string or a list.
	 */
	public function test_carries_disclosure(): void {
		$this->assertTrue( MMSAR_Agent_Log::carries_disclosure( self::SCANNER_NEW_UA, array( 'github.com/elementor', 'elementor.com/checker' ) ) );
		$this->assertTrue( MMSAR_Agent_Log::carries_disclosure( self::SCANNER_OLD_UA, array( 'github.com/elementor', 'elementor.com/checker' ) ) );
		$this->assertFalse( MMSAR_Agent_Log::carries_disclosure( 'AgentReadyScanner/1.0', array( 'github.com/elementor', 'elementor.com/checker' ) ) );
		$this->assertTrue( MMSAR_Agent_Log::carries_disclosure( self::T2_UA, 't2ind.com' ) );
		$this->assertFalse( MMSAR_Agent_Log::carries_disclosure( 'anything', array() ) );
		$this->assertFalse( MMSAR_Agent_Log::carries_disclosure( 'anything', '' ) );
	}
}
