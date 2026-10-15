<?php
/**
 * The 2026-10-15 bot watch: BitMoversBot, and the re-check rule it exposed.
 *
 * The live user-agent and address are the exact values in miriamschwab.me's log on 2026-10-15:
 * seven rows, all decided `unclaimed` before the name was recognised.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recognition, category and verdict for BitMoversBot, and which `unclaimed` rows Re-check reopens.
 */
final class BotWatchOctober15Test extends TestCase {

	/** The documented user-agent, which is also what arrived on live. */
	private const UA = 'Mozilla/5.0 (compatible; BitMoversBot/1.0; +https://bitmovers.co.uk/crawler/cfuhquku)';

	/** The stored value of the seven live rows. */
	private const LIVE_ROW = '(compatible; BitMoversBot/1.0; +https://bitmovers.co.uk/crawler/cfuhquku)';

	/** The one address on live: UK residential fibre, no PTR. */
	private const LIVE_IP = '84.18.244.40';

	/** A browser, which claims nothing. */
	private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0';

	/**
	 * The test's own $wpdb, restored after each test.
	 *
	 * @var mixed
	 */
	private $saved_wpdb;

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
		$this->saved_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		parent::tearDown();
	}

	public function test_label(): void {
		$this->assertSame( 'BitMoversBot', MMSAR_Agent_Log::label_for( self::UA ) );
		$this->assertSame( 'BitMoversBot', MMSAR_Agent_Log::label_for( self::UA, true ) );
	}

	/**
	 * Recognise-only: no suffix, no range group, no disclosure guard.
	 */
	public function test_recognise_only(): void {
		$this->assertContains( 'BitMoversBot', MMSAR_Agent_Log::AGENTS );
		$this->assertArrayNotHasKey( 'BitMoversBot', MMSAR_Agent_Log_Verify::VERIFY_HOSTS );
		$this->assertArrayNotHasKey( 'BitMoversBot', MMSAR_Agent_Log_Verify::VERIFY_RANGES );
		$this->assertArrayNotHasKey( 'BitMoversBot', MMSAR_Agent_Log::AGENT_DISCLOSURES );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'BitMoversBot' ) );
	}

	/**
	 * Both stored shapes claim the name and read as `other`.
	 *
	 * @param string $agent Stored agent value.
	 */
	#[DataProvider( 'storedShapes' )]
	public function test_stored_shapes( string $agent ): void {
		$this->assertSame( 'BitMoversBot', MMSAR_Agent_Log_Verify::claimed_name( $agent ) );
		$this->assertSame( 'other', MMSAR_Agent_Log::crawler_category( $agent ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function storedShapes(): array {
		return array(
			'label'    => array( 'BitMoversBot' ),
			'live row' => array( self::LIVE_ROW ),
		);
	}

	/**
	 * Judged, every shape comes out `unverifiable`: something was claimed and nothing can check it.
	 *
	 * @param string $agent Stored agent value.
	 * @param string $ip    Stored address.
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
			'label, live address'    => array( 'BitMoversBot', self::LIVE_IP ),
			'live row, live address' => array( self::LIVE_ROW, self::LIVE_IP ),
			'live row, network only' => array( self::LIVE_ROW, '84.18.244.0' ),
		);
	}

	/**
	 * Which rows Re-check reopens, and from which verdict.
	 *
	 * The live rows are recognise-only and `unclaimed`: reopened, at full or network precision,
	 * because the re-run answer (`unverifiable`) differs. A browser's `unclaimed` is not. A
	 * recognise-only `unverifiable` is not either, because re-running it gives the same answer.
	 * A checkable crawler's `unverifiable` keeps the old rule: full address only.
	 */
	public function test_recheckable_pairs(): void {
		$GLOBALS['wpdb'] = new BotWatchOctober15_Wpdb(
			array(
				array( 'ip' => self::LIVE_IP, 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
				array( 'ip' => '84.18.244.0', 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
				array( 'ip' => '81.2.69.160', 'agent' => self::BROWSER, 'verified' => 'unclaimed' ),
				array( 'ip' => self::LIVE_IP, 'agent' => 'BitMoversBot', 'verified' => 'unverifiable' ),
				array( 'ip' => '34.192.0.1', 'agent' => 'LinkupBot', 'verified' => 'unverifiable' ),
				array( 'ip' => '34.192.0.0', 'agent' => 'LinkupBot', 'verified' => 'unverifiable' ),
			)
		);

		$pairs = MMSAR_Agent_Log::get_recheckable_pairs();
		$this->assertSame(
			array(
				array( 'ip' => self::LIVE_IP, 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
				array( 'ip' => '84.18.244.0', 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
				array( 'ip' => '34.192.0.1', 'agent' => 'LinkupBot', 'verified' => 'unverifiable' ),
			),
			$pairs
		);
	}

	/**
	 * The count and the reset are scoped to agent *and* verdict, so an agent reopened for its
	 * `unclaimed` rows does not drag its `unverifiable` rows along.
	 */
	public function test_count_and_reset_scope_to_the_verdict(): void {
		$db              = new BotWatchOctober15_Wpdb(
			array(
				array( 'ip' => self::LIVE_IP, 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
				array( 'ip' => '84.18.244.41', 'agent' => self::LIVE_ROW, 'verified' => 'unclaimed' ),
			)
		);
		$GLOBALS['wpdb'] = $db;

		$this->assertSame( 7, MMSAR_Agent_Log::count_recheckable() );
		$this->assertSame( 7, MMSAR_Agent_Log::reset_undecided() );

		// One query each (the two pairs share an agent and a verdict), each with exactly one
		// verdict argument, and that verdict is `unclaimed`.
		$this->assertCount( 1, $db->counts );
		$this->assertCount( 1, $db->updates );
		$this->assertSame( array( 'wp_mmsar_agent_log', 'unclaimed', self::LIVE_ROW ), $db->counts[0] );
		$this->assertSame( array( 'wp_mmsar_agent_log', MMSAR_Agent_Log_Verify::PENDING, 'unclaimed', self::LIVE_ROW ), $db->updates[0] );
	}
}

/**
 * A $wpdb that serves fixed distinct pairs and records the per-agent count and reset queries.
 */
final class BotWatchOctober15_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Arguments of each COUNT query.
	 *
	 * @var array[]
	 */
	public $counts = array();

	/**
	 * Arguments of each UPDATE query.
	 *
	 * @var array[]
	 */
	public $updates = array();

	/**
	 * Distinct pairs to serve.
	 *
	 * @var array[]
	 */
	private $pairs;

	/**
	 * Constructor.
	 *
	 * @param array[] $pairs Distinct pairs, each with ip, agent and verified.
	 */
	public function __construct( array $pairs ) {
		$this->pairs = $pairs;
	}

	/**
	 * Keeps the query and its arguments together.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return array{0:string,1:array}
	 */
	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}

	/**
	 * The distinct-pairs read.
	 *
	 * @param array{0:string,1:array} $prepared Prepared query.
	 * @return array[]
	 */
	public function get_results( $prepared ) {
		return 0 === strpos( $prepared[0], 'SELECT DISTINCT ip, agent, verified' ) ? $this->pairs : array();
	}

	/**
	 * A per-agent count: seven rows, the live figure.
	 *
	 * @param array{0:string,1:array} $prepared Prepared query.
	 * @return int
	 */
	public function get_var( $prepared ) {
		$this->counts[] = $prepared[1];
		return 7;
	}

	/**
	 * A per-agent reset.
	 *
	 * @param array{0:string,1:array} $prepared Prepared query.
	 * @return int
	 */
	public function query( $prepared ) {
		$this->updates[] = $prepared[1];
		return 7;
	}
}
