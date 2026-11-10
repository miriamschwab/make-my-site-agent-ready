<?php
/**
 * A list cannot judge a request made before it existed (1.73.0-dev.3).
 *
 * KeenableBot crawled this site from other Google Cloud addresses before Keenable published its
 * list on 2026-10-09, and 1.73.0-dev.2's Re-check judged those rows `failed`. These tests run the
 * plugin's own SQL (apply_verdict(), the schema-10 correction and the Re-check filter) on SQLite,
 * through a small $wpdb that executes what it is given, so what is asserted is the query that
 * ships rather than a PHP restatement of it.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * A $wpdb that runs prepared queries on an in-memory SQLite table.
 */
final class ListSinceSqliteWpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var PDO */
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_mmsar_agent_log ( id INTEGER PRIMARY KEY, logged_at TEXT, agent TEXT, ip TEXT, verified TEXT, verified_at TEXT )' );
	}

	/**
	 * Substitutes %i, %s and %d the way $wpdb does, quoting through PDO.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$i = 0;
		return (string) preg_replace_callback(
			'/%([isd])/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				if ( 'i' === $m[1] ) {
					return '"' . str_replace( '"', '', (string) $value ) . '"';
				}
				return 'd' === $m[1] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	/**
	 * @param string $sql Prepared query.
	 * @return int
	 */
	public function query( $sql ) {
		return (int) $this->pdo->exec( $sql );
	}

	/**
	 * @param string $sql Prepared query.
	 * @return array[]
	 */
	public function get_results( $sql ) {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * @param string $sql Prepared query.
	 * @return string[]
	 */
	public function get_col( $sql ) {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
	}

	/**
	 * Seeds a row.
	 *
	 * @param string $logged_at UTC time.
	 * @param string $agent     Agent.
	 * @param string $ip        Address.
	 * @param string $verified  Verdict.
	 * @return void
	 */
	public function add( $logged_at, $agent, $ip, $verified ) {
		$st = $this->pdo->prepare( 'INSERT INTO wp_mmsar_agent_log ( logged_at, agent, ip, verified ) VALUES ( ?, ?, ?, ? )' );
		$st->execute( array( $logged_at, $agent, $ip, $verified ) );
	}

	/**
	 * @return array<string, string> Verdict by logged_at.
	 */
	public function verdicts() {
		return $this->pdo->query( 'SELECT logged_at, verified FROM wp_mmsar_agent_log ORDER BY logged_at' )->fetchAll( PDO::FETCH_KEY_PAIR );
	}
}

/**
 * KeenableBot before and after its list, and the guarantees around it.
 */
final class ListSinceTest extends TestCase {

	/** A KeenableBot row as stored before 1.73.0 recognised the name. */
	private const RAW = 'AppleWebKit/537.36 (KHTML, like Gecko; compatible; KeenableBot/1.0; +https://kee';

	/** An address KeenableBot used in September, not on its list. */
	private const OLD_IP = '34.172.22.14';

	private ListSinceSqliteWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
		$this->db        = new ListSinceSqliteWpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	public function test_since_applies_to_a_list_only_agent_with_a_date(): void {
		$this->assertSame( '2026-10-09 12:04:36', MMSAR_Agent_Log_Verify::list_since( 'KeenableBot' ) );
		$this->assertSame( '2026-10-09 12:04:36', MMSAR_Agent_Log_Verify::list_since( self::RAW ) );
		// Lists without a date, and agents with a reverse-DNS fallback, are judged as before.
		$this->assertSame( '', MMSAR_Agent_Log_Verify::list_since( 'HubSpot Crawler' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Verify::list_since( 'ClaudeBot' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Verify::list_since( 'RiverBot' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Verify::list_since( 'Mozilla/5.0' ) );
	}

	public function test_a_dated_rdns_agent_keeps_its_failed(): void {
		add_filter(
			'mmsar_agent_log_verify_ranges',
			static function ( $ranges ) {
				$ranges['riverbot']['since'] = '2026-10-28 00:00:00';
				return $ranges;
			}
		);
		$this->assertSame( '', MMSAR_Agent_Log_Verify::list_since( 'RiverBot' ) );
	}

	public function test_verdict_for_row(): void {
		$since = '2026-10-09 12:04:36';
		$this->assertSame( 'unverifiable', MMSAR_Agent_Log_Verify::verdict_for_row( 'failed', $since, '2026-10-09 12:04:35' ) );
		$this->assertSame( 'failed', MMSAR_Agent_Log_Verify::verdict_for_row( 'failed', $since, '2026-10-09 12:04:36' ) );
		$this->assertSame( 'verified', MMSAR_Agent_Log_Verify::verdict_for_row( 'verified', $since, '2026-09-01 00:00:00' ) );
		$this->assertSame( 'failed', MMSAR_Agent_Log_Verify::verdict_for_row( 'failed', '', '2026-09-01 00:00:00' ) );
	}

	/**
	 * A pending pair split at the list's date: before it withheld, from it failed.
	 */
	public function test_apply_verdict_splits_at_the_list_date(): void {
		$this->db->add( '2026-09-18 08:07:59', self::RAW, self::OLD_IP, '' );
		$this->db->add( '2026-10-09 12:04:36', self::RAW, self::OLD_IP, '' );
		$this->db->add( '2026-11-01 00:00:00', self::RAW, self::OLD_IP, '' );
		// A row from the same address under another name is not touched.
		$this->db->add( '2026-09-18 09:00:00', 'Googlebot', self::OLD_IP, '' );

		$this->assertSame( 'failed', MMSAR_Agent_Log_Verify::verdict_for( self::RAW, self::OLD_IP ) );
		$this->assertSame( 3, MMSAR_Agent_Log::apply_verdict( self::OLD_IP, self::RAW, 'failed' ) );
		$this->assertSame(
			array(
				'2026-09-18 08:07:59' => 'unverifiable',
				'2026-09-18 09:00:00' => '',
				'2026-10-09 12:04:36' => 'failed',
				'2026-11-01 00:00:00' => 'failed',
			),
			$this->db->verdicts()
		);
	}

	/**
	 * Undated agents and other verdicts are written to every row, as before.
	 */
	public function test_apply_verdict_unchanged_elsewhere(): void {
		$this->db->add( '2026-09-18 08:00:00', self::RAW, '35.255.22.29', '' );
		$this->db->add( '2026-09-18 08:00:01', 'HubSpot Crawler', '54.174.59.1', '' );
		$this->assertSame( 1, MMSAR_Agent_Log::apply_verdict( '35.255.22.29', self::RAW, 'verified' ) );
		$this->assertSame( 1, MMSAR_Agent_Log::apply_verdict( '54.174.59.1', 'HubSpot Crawler', 'failed' ) );
		$this->assertSame(
			array(
				'2026-09-18 08:00:00' => 'verified',
				'2026-09-18 08:00:01' => 'failed',
			),
			$this->db->verdicts()
		);
	}

	/**
	 * The schema-10 correction touches only dated agents' `failed` rows before the date.
	 */
	public function test_correction_withholds_only_pre_list_failed_rows(): void {
		$this->db->add( '2026-09-15 07:42:01', self::RAW, self::OLD_IP, 'failed' );
		$this->db->add( '2026-10-08 20:05:00', 'KeenableBot', '136.116.102.34', 'failed' );
		$this->db->add( '2026-10-10 00:00:00', 'KeenableBot', '136.116.102.34', 'failed' );
		$this->db->add( '2026-10-01 00:00:00', 'KeenableBot', '35.255.22.29', 'verified' );
		$this->db->add( '2026-09-15 00:00:00', 'ClaudeBot', '153.61.208.18', 'failed' );
		$this->db->add( '2026-09-15 00:00:01', 'HubSpot Crawler', '54.174.62.129', 'failed' );

		$this->assertSame( 2, MMSAR_Agent_Log::withhold_before_lists() );
		$this->assertSame(
			array(
				'2026-09-15 00:00:00' => 'failed',
				'2026-09-15 00:00:01' => 'failed',
				'2026-09-15 07:42:01' => 'unverifiable',
				'2026-10-01 00:00:00' => 'verified',
				'2026-10-08 20:05:00' => 'unverifiable',
				'2026-10-10 00:00:00' => 'failed',
			),
			$this->db->verdicts()
		);
		// Running it twice changes nothing.
		$this->assertSame( 0, MMSAR_Agent_Log::withhold_before_lists() );
	}

	/**
	 * Re-check does not offer withheld pre-list rows, which it could never change, and a full
	 * reset-and-judge cycle leaves them withheld.
	 */
	public function test_recheck_leaves_withheld_rows_alone(): void {
		$this->db->add( '2026-09-15 07:42:01', self::RAW, self::OLD_IP, 'unverifiable' );
		$this->db->add( '2026-09-16 07:42:01', 'TechLeadsBot', '65.109.16.46', 'unverifiable' );
		$pairs = MMSAR_Agent_Log::get_recheckable_pairs();
		$this->assertSame( array(), $pairs, 'TechLeadsBot has no method, and the Keenable row predates its list.' );

		// A pair with a row after the date is still offered.
		$this->db->add( '2026-10-20 00:00:00', self::RAW, self::OLD_IP, 'unverifiable' );
		$this->assertCount( 1, MMSAR_Agent_Log::get_recheckable_pairs() );

		// Reset that pair and judge it again: the pre-list rows come back withheld, the later one failed.
		MMSAR_Agent_Log::reset_undecided();
		MMSAR_Agent_Log::apply_verdict( self::OLD_IP, self::RAW, MMSAR_Agent_Log_Verify::verdict_for( self::RAW, self::OLD_IP ) );
		$this->assertSame(
			array(
				'2026-09-15 07:42:01' => 'unverifiable',
				'2026-09-16 07:42:01' => 'unverifiable',
				'2026-10-20 00:00:00' => 'failed',
			),
			$this->db->verdicts()
		);
	}
}
