<?php
/**
 * The 2026-10-19 bot watch: ibou-search, PoliasBot, Vento9 and ExaUser.
 *
 * The user-agents are the exact values in miriamschwab.me's log on 2026-10-19, and for ibou-search
 * also the form its operator documents, which differs from the one that arrived by a `www.`.
 *
 * One name is verifiable and three are not, which is the pairing worth testing together: a bot with
 * a reverse-DNS method reaches `verified`, `failed` or `nodns`, while a recognise-only bot reaches
 * `unverifiable` from every shape, and neither may ever be mistaken for the other.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, categories, the Vento9 disclosure guard and ibou-search's verdicts.
 */
final class BotWatchOctober19Test extends TestCase {

	/** ibou-search as it arrived on live. The operator's page writes `www.ibou.com`. */
	private const IBOU_SEARCH_UA = 'Mozilla/5.0 (compatible; ibou-search/2.0; +https://ibou.com/crawlers.html)';

	/** The same, as documented at https://www.ibou.com/crawlers.html. */
	private const IBOU_SEARCH_DOCS_UA = 'Mozilla/5.0 (compatible; ibou-search/2.0; +https://www.ibou.com/crawlers.html)';

	/** IbouBot, the same operator's other crawler, which must keep its own name. */
	private const IBOUBOT_UA = 'Mozilla/5.0 (compatible; IbouBot/1.0; +https://www.ibou.com/iboubot.html)';

	/** PoliasBot, documented and seen identically. */
	private const POLIAS_UA = 'PoliasBot/0.1 (+https://polias.eu/bot; hallo@polias.eu)';

	/** Vento9. The operator's page calls the bot "Vento9Bot"; this is what it sends. */
	private const VENTO9_UA = 'Vento9/1.0 (+https://vento9.com)';

	/** ExaUser, Exa's user-triggered fetcher. */
	private const EXAUSER_UA = 'Mozilla/5.0 (compatible; ExaUser/1.0; +https://crawler.exa.ai/exauser)';

	/** ExaSearchBot, Exa's automatic crawler, which keeps its own name and its own category. */
	private const EXASEARCHBOT_UA = 'Mozilla/5.0 (compatible; ExaSearchBot/1.0; +https://crawler.exa.ai/exasearchbot)';

	/**
	 * An address inside ibou's published range, with the hostname it reverse-resolves to.
	 *
	 * Real data: on 2026-10-21 every one of the 254 addresses in 188.241.38.0/24 that has a PTR
	 * resolved under ibou.io, as `c40` plus the zero-padded last octet.
	 */
	private const IBOU_IP   = '188.241.38.100';
	private const IBOU_HOST = 'c40100.ibou.io';

	/**
	 * A real Babbar crawler address, standing in for the sixth ibou-search address on live.
	 *
	 * The bot watch recorded that the sixth address reverse-resolves under `babbar.eu` and
	 * forward-confirms, but not which address it was. This is the same shape with values confirmed
	 * on 2026-10-21: a hostname that forward-confirms under a domain ibou does not own, which is
	 * the one case that must read `failed` rather than `nodns`.
	 */
	private const BABBAR_IP   = '217.113.194.187';
	private const BABBAR_HOST = 'c187.babbar.eu';

	/** A Hetzner Cloud address with no PTR, which is where PoliasBot arrived from. */
	private const NO_PTR_IP = '91.99.72.14';

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
	 * The pairs that matter are ibou-search beside IbouBot and ExaUser beside ExaSearchBot: in both
	 * the two names share an operator and neither contains the other, so a loose needle on either
	 * side would relabel the other's rows.
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
			'ibou-search, live'      => array( self::IBOU_SEARCH_UA, 'ibou-search' ),
			'ibou-search, as docs'   => array( self::IBOU_SEARCH_DOCS_UA, 'ibou-search' ),
			'IbouBot is not it'      => array( self::IBOUBOT_UA, 'IbouBot' ),
			'PoliasBot'              => array( self::POLIAS_UA, 'PoliasBot' ),
			'Vento9'                 => array( self::VENTO9_UA, 'Vento9' ),
			'ExaUser'                => array( self::EXAUSER_UA, 'ExaUser' ),
			'ExaSearchBot is not it' => array( self::EXASEARCHBOT_UA, 'ExaSearchBot' ),
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
			// ibou says it does not train AI models with the data.
			'ibou-search is search-engine' => array( self::IBOU_SEARCH_UA, 'ibou-search', 'search-engine' ),
			'PoliasBot is search-engine'   => array( self::POLIAS_UA, 'PoliasBot', 'search-engine' ),
			'Vento9 is search-engine'      => array( self::VENTO9_UA, 'Vento9', 'search-engine' ),
			// Fetched because a person asked, like ChatGPT-User — unlike the crawler that feeds the
			// same company's index.
			'ExaUser is ai-assistant'      => array( self::EXAUSER_UA, 'ExaUser', 'ai-assistant' ),
			'ExaSearchBot stays ai-search' => array( self::EXASEARCHBOT_UA, 'ExaSearchBot', 'ai-search' ),
		);
	}

	/**
	 * ibou-search is verifiable by reverse DNS, and by nothing else.
	 *
	 * The range file ibou publishes is deliberately not bundled, so there must be no range group:
	 * with one, a PTR-less address missing from a stale list would read `failed`.
	 */
	public function test_ibou_search_is_rdns_only(): void {
		$this->assertContains( 'ibou-search', MMSAR_Agent_Log::AGENTS );
		$this->assertSame( array( 'ibou.io' ), MMSAR_Agent_Log_Verify::VERIFY_HOSTS['ibou-search'] );
		$this->assertArrayNotHasKey( 'ibou-search', MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		$this->assertArrayNotHasKey( 'ibou-search', MMSAR_Agent_Log::AGENT_DISCLOSURES );
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'ibou-search' ) );

		// The same suffix IbouBot uses, which is what the operator documents for both.
		$this->assertSame(
			MMSAR_Agent_Log_Verify::VERIFY_HOSTS['IbouBot'],
			MMSAR_Agent_Log_Verify::VERIFY_HOSTS['ibou-search']
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
			'PoliasBot' => array( 'PoliasBot' ),
			'Vento9'    => array( 'Vento9' ),
			'ExaUser'   => array( 'ExaUser' ),
		);
	}

	/**
	 * Polias-KI is documented but has not been seen, and no full user-agent is published for it.
	 *
	 * Held deliberately (Miriam, 2026-10-21): guessing the matched string is how the DuckAssistBot
	 * entry went wrong in 1.24.0. Delete this test when a real sample arrives.
	 */
	public function test_polias_ki_is_not_added(): void {
		$this->assertNotContains( 'Polias-KI', MMSAR_Agent_Log::AGENTS );
		$this->assertArrayNotHasKey( 'Polias-KI', MMSAR_Agent_Log::CRAWLER_CATEGORIES );
	}

	/**
	 * Vento9 is only claimed when the user-agent also discloses vento9.com.
	 *
	 * The bare operator name is matched rather than `Vento9/`, so that both the documented
	 * "Vento9Bot" spelling and the `Vento9/1.0` form it actually sends are recognised and stored
	 * under one clean label. The guard is what keeps the bare name from being worn by accident.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Name that must be stored.
	 */
	#[DataProvider( 'vento9Guard' )]
	public function test_vento9_guard( string $ua, string $expected ): void {
		$this->assertSame( 'vento9.com', MMSAR_Agent_Log::AGENT_DISCLOSURES['Vento9'] );
		$this->assertSame( $expected, MMSAR_Agent_Log::label_for( $ua ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function vento9Guard(): array {
		return array(
			'the form it sends'      => array( self::VENTO9_UA, 'Vento9' ),
			'the documented name'    => array( 'Mozilla/5.0 Vento9Bot/2.0 (+https://vento9.com/bot.php)', 'Vento9' ),
			'no domain, not claimed' => array( 'SomeApp Vento9/3.1 (internal build)', 'SomeApp Vento9/3.1 (internal build)' ),
		);
	}

	/**
	 * Every verdict ibou-search can reach, and the one every recognise-only name reaches.
	 *
	 * @param string $agent    Value as stored in the log's `agent` column.
	 * @param string $ip       Value as stored in the log's `ip` column.
	 * @param string $host     Hostname the reverse lookup returns, '' for none.
	 * @param array  $forward  Addresses the forward lookup returns.
	 * @param string $expected Expected verdict.
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
			'ibou-search, label, confirmed'     => array(
				'ibou-search',
				self::IBOU_IP,
				self::IBOU_HOST,
				array( self::IBOU_IP ),
				'verified',
			),
			'ibou-search, stored row, confirmed' => array(
				'(compatible; ibou-search/2.0; +https://ibou.com/crawlers.html)',
				self::IBOU_IP,
				self::IBOU_HOST,
				array( self::IBOU_IP ),
				'verified',
			),
			// The sixth address's shape: a real host under a domain ibou does not own.
			'ibou-search, babbar host'         => array(
				'ibou-search',
				self::BABBAR_IP,
				self::BABBAR_HOST,
				array( self::BABBAR_IP ),
				'failed',
			),
			// Retryable, and deliberately not an accusation: the resolver said nothing.
			'ibou-search, no PTR'              => array(
				'ibou-search',
				self::NO_PTR_IP,
				'',
				array(),
				'nodns',
			),
			// A hostname under ibou.io that does not forward-confirm proves nothing, so it is the
			// claim that fails, not the lookup.
			'ibou-search, no forward match'    => array(
				'ibou-search',
				self::IBOU_IP,
				self::IBOU_HOST,
				array( '198.51.100.9' ),
				'failed',
			),
			// A suffix must be dot-bounded: a lookalike domain is not ibou's.
			'ibou-search, lookalike suffix'    => array(
				'ibou-search',
				self::IBOU_IP,
				'c40100.notibou.io',
				array( self::IBOU_IP ),
				'failed',
			),
			'PoliasBot'                        => array( 'PoliasBot', self::NO_PTR_IP, '', array(), 'unverifiable' ),
			'PoliasBot, stored row'            => array( self::POLIAS_UA, self::NO_PTR_IP, '', array(), 'unverifiable' ),
			'Vento9'                           => array( 'Vento9', '95.217.3.14', '', array(), 'unverifiable' ),
			'Vento9, stored row'               => array( self::VENTO9_UA, '95.217.3.14', '', array(), 'unverifiable' ),
			'ExaUser'                          => array( 'ExaUser', '172.245.122.33', '', array(), 'unverifiable' ),
			'ExaUser, stored row'              => array(
				'(compatible; ExaUser/1.0; +https://crawler.exa.ai/exauser)',
				'172.245.122.33',
				'',
				array(),
				'unverifiable',
			),
		);
	}
}
