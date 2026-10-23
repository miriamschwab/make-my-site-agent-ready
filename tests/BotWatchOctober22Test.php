<?php
/**
 * The 2026-10-22 bot watch: AlpostBot, ElevenlabsCrawler and ElevenlabsBot.
 *
 * AlpostBot's and ElevenlabsCrawler's user-agents are the exact values in miriamschwab.me's log,
 * which match their operators' documentation. ElevenlabsBot has not been seen; its user-agent is
 * the one documented at https://elevenlabs.io/docs/bots.
 *
 * One name is verifiable by a single published address and two are not. The address case is the
 * one worth pinning: a range-only bot has no reverse-DNS fallback, so any other address is `failed`
 * whatever its PTR says.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, categories and verdicts for the three names.
 */
final class BotWatchOctober22Test extends TestCase {

	/** AlpostBot, documented and seen identically. */
	private const ALPOST_UA = 'Mozilla/5.0 (compatible; AlpostBot/0.1; +https://alpost.ch/en/bot/)';

	/** ElevenlabsCrawler, documented and seen identically. */
	private const ELEVENLABS_CRAWLER_UA = 'Mozilla/5.0 (compatible; ElevenlabsCrawler/1.0; +https://elevenlabs.io/docs/bots)';

	/** ElevenlabsBot as documented: a bare product token, no URL. */
	private const ELEVENLABS_BOT_UA = 'ElevenlabsBot/1.0';

	/** The one address Alpost documents, from includes/data/crawler-ranges.php. */
	private const ALPOST_IP = '46.105.236.247';

	/** A budget-VPS address with no PTR, the shape ElevenlabsCrawler arrived in. */
	private const NO_PTR_IP = '192.3.81.14';

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
	 * The two ElevenLabs names share an operator and neither contains the other, so each must keep
	 * its own label rather than one swallowing the other's rows.
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
			'AlpostBot'         => array( self::ALPOST_UA, 'AlpostBot' ),
			'ElevenlabsCrawler' => array( self::ELEVENLABS_CRAWLER_UA, 'ElevenlabsCrawler' ),
			'ElevenlabsBot'     => array( self::ELEVENLABS_BOT_UA, 'ElevenlabsBot' ),
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
			'AlpostBot is search-engine'        => array( self::ALPOST_UA, 'AlpostBot', 'search-engine' ),
			'ElevenlabsCrawler is ai-training'  => array( self::ELEVENLABS_CRAWLER_UA, 'ElevenlabsCrawler', 'ai-training' ),
			// Fetched because a customer added the page to an agent, not a crawl — the same split as
			// ChatGPT-User beside GPTBot.
			'ElevenlabsBot is ai-assistant'     => array( self::ELEVENLABS_BOT_UA, 'ElevenlabsBot', 'ai-assistant' ),
		);
	}

	/**
	 * AlpostBot is verifiable by its one published address, and by nothing else.
	 *
	 * No suffix: the operator documents a forward record, not a reverse-DNS method, and the
	 * address's PTR moved from bot.alpost.ch to mx2.alpost.ch between 2026-10-22 and 2026-10-23.
	 */
	public function test_alpost_is_range_only(): void {
		$this->assertContains( 'AlpostBot', MMSAR_Agent_Log::AGENTS );
		$this->assertSame( 'alpost', MMSAR_Agent_Log_Verify::VERIFY_RANGES['AlpostBot'] );
		$this->assertArrayNotHasKey( 'AlpostBot', MMSAR_Agent_Log_Verify::VERIFY_HOSTS );
		$this->assertArrayNotHasKey( 'AlpostBot', MMSAR_Agent_Log::AGENT_DISCLOSURES );
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'AlpostBot' ) );
	}

	/**
	 * The two ElevenLabs names are recognise-only: recognised, and with nothing to check them against.
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
			'ElevenlabsCrawler' => array( 'ElevenlabsCrawler' ),
			'ElevenlabsBot'     => array( 'ElevenlabsBot' ),
		);
	}

	/**
	 * Every verdict AlpostBot can reach, and the one both ElevenLabs names reach.
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
			'AlpostBot, the published address'  => array( 'AlpostBot', self::ALPOST_IP, '', array(), 'verified' ),
			'AlpostBot, stored row'             => array(
				'(compatible; AlpostBot/0.1; +https://alpost.ch/en/bot/)',
				self::ALPOST_IP,
				'',
				array(),
				'verified',
			),
			'AlpostBot, one address over'       => array( 'AlpostBot', '46.105.236.248', '', array(), 'failed' ),
			// A forward-confirmed name under alpost.ch is not a method here, so it cannot rescue
			// an address the operator does not publish.
			'AlpostBot, alpost.ch host elsewhere' => array(
				'AlpostBot',
				'46.105.236.248',
				'bot.alpost.ch',
				array( '46.105.236.248' ),
				'failed',
			),
			'ElevenlabsCrawler'                 => array( 'ElevenlabsCrawler', self::NO_PTR_IP, '', array(), 'unverifiable' ),
			'ElevenlabsCrawler, stored row'     => array(
				'(compatible; ElevenlabsCrawler/1.0; +https://elevenlabs.io/docs/bots)',
				self::NO_PTR_IP,
				'',
				array(),
				'unverifiable',
			),
			'ElevenlabsBot'                     => array( 'ElevenlabsBot', self::NO_PTR_IP, '', array(), 'unverifiable' ),
			'ElevenlabsBot, stored row'         => array( self::ELEVENLABS_BOT_UA, self::NO_PTR_IP, '', array(), 'unverifiable' ),
		);
	}
}
