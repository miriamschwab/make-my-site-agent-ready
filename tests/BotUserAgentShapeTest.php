<?php
/**
 * The 2026-10-08 bot watch: the crawler-first stored shape, and the seven names added with it.
 *
 * Every stored value under "today" is the exact string sitting in miriamschwab.me's log on
 * 2026-10-08, so the first test proves two things at once: the reconstructed full user-agents
 * below are consistent with what actually arrived, and the path a browser or a script takes is
 * still byte-identical to before. The tails past character 80 are reconstructed from the
 * operators' documentation (Google's exactly; Meta's and Semrush's from their documented tokens
 * and links), since the log never kept them.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Crawler-first storage, and the 1.62.0 recognition and verification entries.
 */
final class BotUserAgentShapeTest extends TestCase {

	private const META_LINK = '+https://developers.facebook.com/docs/sharing/webmasters/web-crawlers';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	/**
	 * Full user-agents, keyed by shape.
	 *
	 * @return array<string, string>
	 */
	private static function agents(): array {
		$meta = '(compatible; meta-webindexer/1.1; ' . self::META_LINK . ')';
		return array(
			'meta-webindexer, Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 ' . $meta,
			'meta-webindexer, macOS'   => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 ' . $meta,
			'meta-webindexer, Linux'   => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 ' . $meta,
			'meta-webindexer, Edge'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0 ' . $meta,
			// Google's documented mobile user-agent, verbatim.
			'Google-GeminiNotebook'    => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-gemininotebook)',
			'SiteAuditBot, iPhone'     => 'Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A5376e Safari/8536.25 (compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html)',
		);
	}

	/**
	 * Without the crawler flag, every shape stores exactly what the live log holds.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected The value stored on live.
	 */
	#[DataProvider( 'storedToday' )]
	public function test_unflagged_path_is_unchanged( string $ua, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::trimmed_user_agent( $ua ) );
		$this->assertSame( $expected, MMSAR_Agent_Log::trimmed_user_agent( $ua, false ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function storedToday(): array {
		$a = self::agents();
		return array(
			'meta-webindexer, Windows' => array( $a['meta-webindexer, Windows'], '(Windows NT 10.0; Win64; x64) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-w' ),
			'meta-webindexer, macOS'   => array( $a['meta-webindexer, macOS'], '(Macintosh; Intel Mac OS X 10_15_7) Chrome/145.0.0.0 Safari/537.36 (compatible; ' ),
			'meta-webindexer, Linux'   => array( $a['meta-webindexer, Linux'], '(X11; Linux x86_64) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/' ),
			'meta-webindexer, Edge'    => array( $a['meta-webindexer, Edge'], '(Windows NT 10.0; Win64; x64) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0 (comp' ),
			'Google-GeminiNotebook'    => array( $a['Google-GeminiNotebook'], '(Linux; Android 10; K) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google' ),
			'SiteAuditBot, iPhone'     => array( $a['SiteAuditBot, iPhone'], '(iPhone; CPU iPhone OS 6_0 like Mac OS X) Version/6.0 Mobile/10A5376e Safari/853' ),
		);
	}

	/**
	 * With the crawler flag, the bot's own comment leads and its name survives the cut.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Expected stored value.
	 */
	#[DataProvider( 'storedCrawlerFirst' )]
	public function test_crawler_comment_leads( string $ua, string $expected ): void {
		$stored = MMSAR_Agent_Log::trimmed_user_agent( $ua, true );
		$this->assertSame( $expected, $stored );
		$this->assertLessThanOrEqual( 80, mb_strlen( $stored ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function storedCrawlerFirst(): array {
		$a    = self::agents();
		$meta = '(compatible; meta-webindexer/1.1; +https://developers.facebook.com/docs/sharing/';
		return array(
			'meta-webindexer, Windows' => array( $a['meta-webindexer, Windows'], $meta ),
			'meta-webindexer, macOS'   => array( $a['meta-webindexer, macOS'], $meta ),
			'meta-webindexer, Linux'   => array( $a['meta-webindexer, Linux'], $meta ),
			'meta-webindexer, Edge'    => array( $a['meta-webindexer, Edge'], $meta ),
			'Google-GeminiNotebook'    => array( $a['Google-GeminiNotebook'], '(compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs' ),
			// A short link leaves room for the platform, which follows in its original order.
			'SiteAuditBot, iPhone'     => array( $a['SiteAuditBot, iPhone'], '(compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html) (iPhone; CPU i' ),
			// An unknown bot, which is the case the change is for: recognised names never reach
			// this function at write time.
			'unknown bot, macOS'       => array(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; ExampleBot/2.0; +https://example.com/bot)',
				'(compatible; ExampleBot/2.0; +https://example.com/bot) (Macintosh; Intel Mac OS ',
			),
			// No `compatible` comment: the token carrying the claim moves instead.
			'bare product token at the end' => array(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0 ExampleBot/2.0',
				'ExampleBot/2.0 (Windows NT 10.0; Win64; x64) Chrome/145.0.0.0 Safari/537.36 Edg/',
			),
		);
	}

	/**
	 * Strings the flag must not change: browsers, short bots, and every bot whose comment already
	 * leads — which covers every user-agent a disclosure guard depends on, so the guard's promise
	 * that its domain sits inside the first 80 characters holds in this third shape too.
	 *
	 * @param string $ua Raw user-agent.
	 */
	#[DataProvider( 'unmoved' )]
	public function test_flag_leaves_these_alone( string $ua ): void {
		$this->assertSame( MMSAR_Agent_Log::trimmed_user_agent( $ua ), MMSAR_Agent_Log::trimmed_user_agent( $ua, true ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function unmoved(): array {
		return array(
			'plain Chrome'                => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' ),
			'plain Firefox'               => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/119.0' ),
			// The bot word matches a word ending, so a CUBOT phone reads as a claim. Its comment is
			// already first, so nothing moves even if one were ever filed as a crawler.
			'CUBOT phone'                 => array( 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36' ),
			'short bot'                   => array( 'Mozilla/5.0 (compatible; SomeBot/1.0; +https://example.com/bot)' ),
			'long bot already leading'    => array( 'Mozilla/5.0 (compatible; SomeBot/1.0; +https://example.com/a/very/long/path/that/runs/past/the/cap/for/sure) Chrome/145' ),
			'um-LN'                       => array( 'Mozilla/5.0 (compatible; um-LN/1.0; https://www.ubermetrics-technologies.com/; Windows NT 6.1; Win64; x64)' ),
			'YaK'                         => array( 'Mozilla/5.0 (compatible; YaK/1.0; http://linkfluence.com/; bot@linkfluence.com)' ),
			'LinkupBot'                   => array( 'LinkupBot/1.0 (LinkupBot for web indexing; https://linkup.so/bot; bot@linkup.so)' ),
			'SSI-Nutch'                   => array( 'SSI-Nutch/1.23 (SSI broad web crawler; https://ssi.inc/; adi@ssi.inc)' ),
			'PoweredByBot'                => array( 'PoweredByBot/1.0 (+https://poweredby.keywordseverywhere.com/bot)' ),
			'Quest'                       => array( 'Quest/1.0 (+https://plus.qwertious.org/quest; educational scraper)' ),
			'FindFiles'                   => array( 'FindFiles.net/1.0 (compatible; +https://findfiles.net/bot)' ),
			'crawl-engine'                => array( 'crawl-engine/0.1 (+https://abuse.creasource.dev/; abuse-report@creasource.dev)' ),
			// 86 characters with its link last. It opens with its own product token, so it keeps its
			// order; the first version of this rule moved the link ahead and cut the name.
			'AgentReadyScanner'           => array( 'AgentReadyScanner/0.1 (+https://github.com/elementor/apps-is-agent-ready-cf-worker)' ),
			'leading product token, long' => array( 'ExampleBot/2.0 (Windows NT 10.0; Win64; x64; +https://example.com/a/long/path/that/goes/past/the/cap)' ),
			// No claim anywhere, so the gate inside the function holds even if a caller sets the flag.
			'long string with no claim'   => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0 OPR/120.0.0.0' ),
		);
	}

	/**
	 * The new names are recognised from the full user-agent, under the right name.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Expected stored label.
	 */
	#[DataProvider( 'recognised' )]
	public function test_new_names_are_recognised( string $ua, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua ) );
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua, true ), 'The crawler flag must not change a recognised label' );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function recognised(): array {
		$a = self::agents();
		return array(
			'meta-webindexer, Windows' => array( $a['meta-webindexer, Windows'], 'meta-webindexer' ),
			'meta-webindexer, Edge'    => array( $a['meta-webindexer, Edge'], 'meta-webindexer' ),
			'meta-webindexer, bare'    => array( 'meta-webindexer/1.1', 'meta-webindexer' ),
			'meta-externalfetcher'     => array( 'meta-externalfetcher/1.1 (' . self::META_LINK . ')', 'meta-externalfetcher' ),
			'meta-externalads'         => array( 'meta-externalads/1.1', 'meta-externalads' ),
			'meta-externalagent still' => array( 'meta-externalagent/1.1 (' . self::META_LINK . ')', 'meta-externalagent' ),
			// Must not be stored as `Gemini`, which sits earlier in AGENTS and is a substring.
			'Gemini Notebook, not Gemini' => array( $a['Google-GeminiNotebook'], 'Google-GeminiNotebook' ),
			'NotebookLM, former token' => array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 (compatible; Google-NotebookLM)', 'Google-NotebookLM' ),
			'Google-Read-Aloud'        => array( 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google-Read-Aloud; +https://support.google.com/webmasters/answer/1061943)', 'Google-Read-Aloud' ),
			// Not claimed by SemrushBot, which is not a substring of it.
			'SiteAuditBot, not SemrushBot' => array( $a['SiteAuditBot, iPhone'], 'SiteAuditBot' ),
			'SemrushBot still'         => array( 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'SemrushBot' ),
			'VisionHeight'             => array( 'visionheight.com/scan Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'VisionHeight (visionheight.com)' ),
		);
	}

	/**
	 * Every stored shape of the new names reads as the right crawler: the label, the crawler-first
	 * shape, and the old cut where it kept the name — which is what the re-check pass works from.
	 *
	 * @param string $agent    Stored agent value.
	 * @param string $name     Expected claimed name, '' for none.
	 * @param string $category Expected category, '' for none.
	 */
	#[DataProvider( 'storedShapes' )]
	public function test_stored_shapes_resolve( string $agent, string $name, string $category ): void {
		$this->assertSame( $name, (string) MMSAR_Agent_Log_Verify::claimed_name( $agent ) );
		$this->assertSame( $category, MMSAR_Agent_Log::crawler_category( $agent ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function storedShapes(): array {
		return array(
			'meta-webindexer, label'            => array( 'meta-webindexer', 'meta-webindexer', 'ai-search' ),
			'meta-webindexer, crawler-first'    => array( '(compatible; meta-webindexer/1.1; +https://developers.facebook.com/docs/sharing/', 'meta-webindexer', 'ai-search' ),
			// The 25 Linux rows already in the log: the old cut kept the name.
			'meta-webindexer, old Linux row'    => array( '(X11; Linux x86_64) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/', 'meta-webindexer', 'ai-search' ),
			// The rest were cut before the token and stay unclaimed. Not retroactive.
			'old Windows row stays unclaimed'   => array( '(Windows NT 10.0; Win64; x64) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-w', '', '' ),
			'old Google row stays unclaimed'    => array( '(Linux; Android 10; K) Chrome/138.0.0.0 Mobile Safari/537.36 (compatible; Google', '', '' ),
			'meta-externalfetcher'              => array( 'meta-externalfetcher', 'meta-externalfetcher', 'ai-assistant' ),
			'meta-externalads'                  => array( 'meta-externalads', 'meta-externalads', 'other' ),
			'Gemini Notebook, label'            => array( 'Google-GeminiNotebook', 'Google-GeminiNotebook', 'ai-assistant' ),
			'Gemini Notebook, crawler-first'    => array( '(compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs', 'Google-GeminiNotebook', 'ai-assistant' ),
			'NotebookLM'                        => array( 'Google-NotebookLM', 'Google-NotebookLM', 'ai-assistant' ),
			'Read Aloud'                        => array( 'Google-Read-Aloud', 'Google-Read-Aloud', 'other' ),
			'SiteAuditBot, crawler-first'       => array( '(compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html) (iPhone; CPU i', 'SiteAuditBot', 'seo-tool' ),
			'VisionHeight, label'               => array( 'VisionHeight (visionheight.com)', 'visionheight.com', 'scanner' ),
			// What an http row from it looked like before recognition.
			'VisionHeight, old raw row'         => array( 'visionheight.com/scan (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0 Safari', 'visionheight.com', 'scanner' ),
		);
	}

	/**
	 * Verdicts. The Google fetchers are judged against the two masks Google documents for them and
	 * nothing else; the rest are recognise-only.
	 *
	 * @param string   $agent    Stored agent value.
	 * @param string   $ip       Stored address.
	 * @param string   $host     What the fake reverse lookup returns.
	 * @param string[] $forward  What the fake forward lookup returns.
	 * @param string   $expected Expected verdict.
	 */
	#[DataProvider( 'verdicts' )]
	public function test_verdicts( string $agent, string $ip, string $host, array $forward, string $expected ): void {
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => $host );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => $forward );
		$this->assertSame( $expected, MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string,3:string[],4:string}>
	 */
	public static function verdicts(): array {
		return array(
			// The four real addresses, confirmed live on 2026-10-08.
			'Gemini Notebook, google-proxy'       => array( 'Google-GeminiNotebook', '66.102.9.230', 'google-proxy-66-102-9-230.google.com', array( '66.102.9.230' ), 'verified' ),
			'Gemini Notebook, crawler-first raw'  => array( '(compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs', '74.125.208.225', 'google-proxy-74-125-208-225.google.com', array( '74.125.208.225' ), 'verified' ),
			'Gemini Notebook, gae mask'           => array( 'Google-GeminiNotebook', '34.34.1.10', '10-1-34-34.gae.googleusercontent.com', array( '34.34.1.10' ), 'verified' ),
			'NotebookLM, google-proxy'            => array( 'Google-NotebookLM', '66.102.9.231', 'google-proxy-66-102-9-231.google.com', array( '66.102.9.231' ), 'verified' ),
			'Read Aloud, google-proxy'            => array( 'Google-Read-Aloud', '74.125.208.227', 'google-proxy-74-125-208-227.google.com', array( '74.125.208.227' ), 'verified' ),
			// googlebot.com is Googlebot's, and Google does not document it for these fetchers.
			'Gemini Notebook, googlebot.com'      => array( 'Google-GeminiNotebook', '66.249.68.2', 'crawl-66-249-68-2.googlebot.com', array( '66.249.68.2' ), 'failed' ),
			'Read Aloud, googlebot.com'           => array( 'Google-Read-Aloud', '66.249.68.2', 'crawl-66-249-68-2.googlebot.com', array( '66.249.68.2' ), 'failed' ),
			'Gemini Notebook, no forward confirm' => array( 'Google-GeminiNotebook', '66.102.9.230', 'google-proxy-66-102-9-230.google.com', array( '203.0.113.9' ), 'failed' ),
			'Gemini Notebook, lookalike'          => array( 'Google-GeminiNotebook', '203.0.113.7', 'google.com.attacker.example', array( '203.0.113.7' ), 'failed' ),
			// Recognise-only.
			'meta-webindexer'                     => array( 'meta-webindexer', '57.141.0.10', '', array(), 'unverifiable' ),
			'meta-externalfetcher'                => array( 'meta-externalfetcher', '57.141.0.10', '', array(), 'unverifiable' ),
			'meta-externalads'                    => array( 'meta-externalads', '57.141.0.10', '', array(), 'unverifiable' ),
			// The decline as a test: the undocumented hostname resolves and forward-confirms, and
			// must still not verify.
			'SiteAuditBot, undocumented rDNS'     => array( 'SiteAuditBot', '85.208.98.198', 'bot.semrush.com', array( '85.208.98.198' ), 'unverifiable' ),
			// Documented, but not yet confirmed on an address from the log.
			'VisionHeight, not yet verified'      => array( 'VisionHeight (visionheight.com)', '3.80.1.1', 'scan.visionheight.com', array( '3.80.1.1' ), 'unverifiable' ),
		);
	}
}
