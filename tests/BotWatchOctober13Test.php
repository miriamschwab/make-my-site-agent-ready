<?php
/**
 * The 2026-10-13 bot watch: cl0q's three bots and its node software, SojournerBot, Sogou web
 * spider, CorpusBot and AstraBot.
 *
 * Every user-agent marked "live" is the exact string in miriamschwab.me's log on 2026-10-13, and
 * every address is the one it arrived from. cl0q-crawler and cl0q-favicon have not called yet, so
 * theirs are built on the shape cl0q documents for cl0q-discovery.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, category and verdict for the 1.63.0 names, and the collisions each one is guarded
 * against.
 */
final class BotWatchOctober13Test extends TestCase {

	/** The one address in https://cl0q.com/bot/ips.json, captured 2026-10-13. */
	private const CL0Q_IP = '143.110.230.182';

	/** The cl0q-node on live: a DigitalOcean server run by whoever enrolled it. */
	private const NODE_IP = '64.227.38.195';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	/**
	 * Full user-agents.
	 *
	 * @return array<string, string>
	 */
	private static function agents(): array {
		return array(
			// Documented by cl0q, and the live rows are this string less `Mozilla/5.0 `.
			'cl0q-discovery' => 'Mozilla/5.0 (compatible; cl0q-discovery/1.0; +https://cl0q.com/bot)',
			'cl0q-crawler'   => 'Mozilla/5.0 (compatible; cl0q-crawler/1.0; +https://cl0q.com/bot)',
			'cl0q-favicon'   => 'Mozilla/5.0 (compatible; cl0q-favicon/1.0; +https://cl0q.com/bot)',
			// Live, as stored, with the `Mozilla/5.0 ` the cut removed put back.
			'cl0q-node'      => 'Mozilla/5.0 (compatible; cl0q-node/1.0; operator=nd_996e7290; +https://cl0q.com/bot)',
			// Live.
			'SojournerBot'   => 'SojournerBot/1.0 (+https://parallaxsearch.com/bot; Interdiction Studios)',
			'Sogou'          => 'Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)',
			'CorpusBot'      => 'CorpusBot/1.0 (+https://corpus.blog/crawler)',
			'AstraBot'       => 'AstraBot/0.1 (+https://astra.junx.dev/bot; educational crawler)',
			'BeansLLM'       => 'BeansLLM-CorpusBot/1.1 (+https://scrape.beansllm.ai/bot; respects robots.txt)',
		);
	}

	/**
	 * Each name is recognised from its full user-agent, with or without the crawler flag.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Expected stored label.
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
		$a = self::agents();
		return array(
			'cl0q-discovery'                  => array( $a['cl0q-discovery'], 'cl0q-discovery' ),
			'cl0q-crawler'                    => array( $a['cl0q-crawler'], 'cl0q-crawler' ),
			'cl0q-favicon'                    => array( $a['cl0q-favicon'], 'cl0q-favicon' ),
			// Its own name, not any of the three operator-run ones.
			'cl0q-node'                       => array( $a['cl0q-node'], 'cl0q-node' ),
			'SojournerBot'                    => array( $a['SojournerBot'], 'SojournerBot' ),
			// The spaces are part of the name, as with Screaming Frog SEO Spider.
			'Sogou web spider'                => array( $a['Sogou'], 'Sogou web spider' ),
			'CorpusBot'                       => array( $a['CorpusBot'], 'CorpusBot' ),
			'AstraBot'                        => array( $a['AstraBot'], 'AstraBot' ),
			// Guarded: the name without its operator's domain is not recognised.
			'BeansLLM is not CorpusBot'       => array( $a['BeansLLM'], $a['BeansLLM'] ),
			'CorpusBot without its domain'    => array( 'CorpusBot/1.0', 'CorpusBot/1.0' ),
			'AstraBot without its domain'     => array( 'AstraBot/1.0 (+https://example.com/bot)', 'AstraBot/1.0 (+https://example.com/bot)' ),
			// A bare cl0q token names none of them.
			'bare cl0q is not recognised'     => array( 'cl0q/1.0 (+https://cl0q.com/bot)', 'cl0q/1.0 (+https://cl0q.com/bot)' ),
			// Sogou's undocumented variants stay unrecognised.
			'Sogou inst spider is not'        => array( 'Sogou inst spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)', 'Sogou inst spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)' ),
			'Sogou Pic Spider is not'         => array( 'Sogou Pic Spider/3.0(+http://www.sogou.com/docs/help/webmasters.htm#07)', 'Sogou Pic Spider/3.0(+http://www.sogou.com/docs/help/webmasters.htm#07)' ),
		);
	}

	/**
	 * None of the three operator-run names is a substring of the node's user-agent, and the node's
	 * name is in none of theirs. This is what keeps a node from ever being judged against cl0q's
	 * address.
	 */
	public function test_cl0q_names_do_not_overlap(): void {
		$node = self::agents()['cl0q-node'];
		foreach ( array( 'cl0q-crawler', 'cl0q-discovery', 'cl0q-favicon' ) as $name ) {
			$this->assertStringNotContainsStringIgnoringCase( $name, $node );
			$this->assertStringNotContainsStringIgnoringCase( 'cl0q-node', self::agents()[ $name ] );
			$this->assertArrayHasKey( $name, MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		}
		$this->assertArrayNotHasKey( 'cl0q-node', MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		$this->assertArrayNotHasKey( 'cl0q-node', MMSAR_Agent_Log_Verify::VERIFY_HOSTS );
		$this->assertNotContains( 'cl0q', MMSAR_Agent_Log::AGENTS, 'A bare cl0q token would match cl0q-node' );
	}

	/**
	 * Every stored shape resolves to the right name and category: the label written from now on,
	 * and the raw values already in the log, which the re-check pass and every read work from.
	 *
	 * @param string $agent    Stored agent value.
	 * @param string $name     Expected claimed name, '' for none.
	 * @param string $category Expected category, '' for none.
	 */
	#[DataProvider( 'storedShapes' )]
	public function test_stored_shapes( string $agent, string $name, string $category ): void {
		$this->assertSame( $name, (string) MMSAR_Agent_Log_Verify::claimed_name( $agent ) );
		$this->assertSame( $category, MMSAR_Agent_Log::crawler_category( $agent ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function storedShapes(): array {
		$a = self::agents();
		return array(
			'cl0q-crawler, label'        => array( 'cl0q-crawler', 'cl0q-crawler', 'search-engine' ),
			'cl0q-discovery, label'      => array( 'cl0q-discovery', 'cl0q-discovery', 'search-engine' ),
			'cl0q-favicon, label'        => array( 'cl0q-favicon', 'cl0q-favicon', 'search-engine' ),
			'cl0q-node, label'           => array( 'cl0q-node', 'cl0q-node', 'search-engine' ),
			// Live rows, as stored before recognition.
			'cl0q-discovery, live row'   => array( '(compatible; cl0q-discovery/1.0; +https://cl0q.com/bot)', 'cl0q-discovery', 'search-engine' ),
			'cl0q-node, live row'        => array( '(compatible; cl0q-node/1.0; operator=nd_996e7290; +https://cl0q.com/bot)', 'cl0q-node', 'search-engine' ),
			'SojournerBot, label'        => array( 'SojournerBot', 'SojournerBot', 'search-engine' ),
			'SojournerBot, live row'     => array( $a['SojournerBot'], 'SojournerBot', 'search-engine' ),
			'Sogou, label'               => array( 'Sogou web spider', 'Sogou web spider', 'search-engine' ),
			'Sogou, live row'            => array( $a['Sogou'], 'Sogou web spider', 'search-engine' ),
			'CorpusBot, label'           => array( 'CorpusBot', 'CorpusBot', 'other' ),
			'CorpusBot, live row'        => array( $a['CorpusBot'], 'CorpusBot', 'other' ),
			'AstraBot, label'            => array( 'AstraBot', 'AstraBot', 'search-engine' ),
			'AstraBot, live row'         => array( $a['AstraBot'], 'AstraBot', 'search-engine' ),
			// 66 rows of this on live in the week before. They stay unclaimed.
			'BeansLLM live row'          => array( $a['BeansLLM'], '', '' ),
			'bare cl0q token'            => array( 'cl0q/1.0 (+https://cl0q.com/bot)', '', '' ),
		);
	}

	/**
	 * Verdicts. The three cl0q bots are judged against cl0q's one address; everything else here is
	 * recognise-only, and cl0q-node in particular is never judged against that address, from
	 * outside it or inside it.
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
		$a = self::agents();
		return array(
			'cl0q-discovery, label, the address'    => array( 'cl0q-discovery', self::CL0Q_IP, '', array(), 'verified' ),
			// The two rows on live, which the re-check pass reopens.
			'cl0q-discovery, live row'              => array( '(compatible; cl0q-discovery/1.0; +https://cl0q.com/bot)', self::CL0Q_IP, '', array(), 'verified' ),
			'cl0q-crawler, the address'             => array( 'cl0q-crawler', self::CL0Q_IP, '', array(), 'verified' ),
			'cl0q-favicon, the address'             => array( 'cl0q-favicon', self::CL0Q_IP, '', array(), 'verified' ),
			'cl0q-discovery, one address over'      => array( 'cl0q-discovery', '143.110.230.183', '', array(), 'failed' ),
			'cl0q-crawler, from the node'           => array( 'cl0q-crawler', self::NODE_IP, '', array(), 'failed' ),
			// Stored at network precision: never judged.
			'cl0q-discovery, network only'          => array( 'cl0q-discovery', '143.110.230.0', '', array(), 'unverifiable' ),
			// The node, from its own server: not Spoofed, not failed.
			'cl0q-node, label, its own server'      => array( 'cl0q-node', self::NODE_IP, '', array(), 'unverifiable' ),
			'cl0q-node, live row, its own server'   => array( '(compatible; cl0q-node/1.0; operator=nd_996e7290; +https://cl0q.com/bot)', self::NODE_IP, '', array(), 'unverifiable' ),
			// And from cl0q's address, which would be a coincidence: still not judged.
			'cl0q-node, from cl0q address'          => array( 'cl0q-node', self::CL0Q_IP, '', array(), 'unverifiable' ),
			'SojournerBot, live'                    => array( 'SojournerBot', '74.215.131.121', '', array(), 'unverifiable' ),
			// The decline as a test: the undocumented hostname resolves and forward-confirms, and
			// must still not verify.
			'Sogou, undocumented rDNS'              => array( 'Sogou web spider', '223.109.255.145', 'sogouspider-223-109-255-145.crawl.sogou.com', array( '223.109.255.145' ), 'unverifiable' ),
			'Sogou, live row'                       => array( $a['Sogou'], '121.229.156.42', '', array(), 'unverifiable' ),
			'CorpusBot, live'                       => array( 'CorpusBot', '167.233.87.70', 'static.70.87.233.167.clients.your-server.de', array( '167.233.87.70' ), 'unverifiable' ),
			'AstraBot, live'                        => array( 'AstraBot', '178.83.122.78', '', array(), 'unverifiable' ),
			'BeansLLM, nothing claimed'             => array( $a['BeansLLM'], '157.157.221.34', '', array(), 'unclaimed' ),
		);
	}

	/**
	 * The two new disclosure domains sit inside the first 80 stored characters in every shape the
	 * column holds — the pre-1.45.1 cut that kept `Mozilla/5.0 `, the current cut, and the 1.62.0
	 * crawler-first one — so every stored raw row still satisfies its guard and is claimed.
	 *
	 * @param string $ua     Raw user-agent.
	 * @param string $name   Guarded name.
	 * @param string $domain Its required disclosure.
	 */
	#[DataProvider( 'disclosed' )]
	public function test_disclosure_survives_every_stored_shape( string $ua, string $name, string $domain ): void {
		$this->assertSame( $domain, MMSAR_Agent_Log::AGENT_DISCLOSURES[ $name ] );
		$shapes = array(
			'pre-1.45.1'    => mb_substr( $ua, 0, 80 ),
			'current'       => MMSAR_Agent_Log::trimmed_user_agent( $ua ),
			'crawler-first' => MMSAR_Agent_Log::trimmed_user_agent( $ua, true ),
		);
		foreach ( $shapes as $shape => $stored ) {
			$this->assertLessThanOrEqual( 80, mb_strlen( $stored ), $shape );
			$this->assertStringContainsString( $domain, $stored, $shape );
			$this->assertSame( $name, MMSAR_Agent_Log_Verify::claimed_name( $stored ), $shape );
		}
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function disclosed(): array {
		$a = self::agents();
		return array(
			'CorpusBot' => array( $a['CorpusBot'], 'CorpusBot', 'corpus.blog' ),
			'AstraBot'  => array( $a['AstraBot'], 'AstraBot', 'junx.dev' ),
		);
	}
}
