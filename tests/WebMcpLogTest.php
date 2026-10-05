<?php
/**
 * WebMCP calls in the Agent Log's default view (1.57.0).
 *
 * WebMCP calls come from a browser and are stored with client type `browser`, which the default
 * view hides. They are agent tool calls, not people reading pages, so the default view lets that
 * one surface through. Like MachineSurfacesTest, this does not restate the rule in PHP: it captures
 * the client clause get_entries() actually sends, with its bound values, and evaluates it on SQLite.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'mmsar_feature_enabled' ) ) {
	/**
	 * The plugin's feature switch, reduced to what record() asks: is the log on.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	function mmsar_feature_enabled( $feature ) {
		return 'agent_log' === $feature;
	}
}

/**
 * A $wpdb that records prepared statements and returns nothing.
 */
final class WebMcp_Log_Capturing_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Every prepare() call, as [ query, args ].
	 *
	 * @var array[]
	 */
	public $prepared = array();

	/**
	 * Core's escaping for LIKE.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Records the statement and its arguments.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );
		return $query;
	}

	/**
	 * Reads return nothing.
	 *
	 * @return array
	 */
	public function get_results() {
		return array();
	}
}

/**
 * Which rows each client filter shows.
 */
final class WebMcpLogTest extends TestCase {

	/**
	 * Rows: [ client_type, surface ].
	 */
	const ROWS = array(
		'browser page view' => array( 'browser', 'HTML page view (asked for HTML)' ),
		'browser webmcp'    => array( 'browser', 'WebMCP JSON-RPC' ),
		// 1.58.0: webmcp.json requests from a browser tool are let through too.
		'browser manifest'  => array( 'browser', 'webmcp.json' ),
		// Exact names only: FIND_IN_SET is not a substring test.
		'browser lookalike' => array( 'browser', 'webmcp.json.bak' ),
		'http mcp'          => array( 'http', 'MCP JSON-RPC' ),
		// 1.59.0: Node-fetch MCP rows filed as browser by the 1.26.0–1.30.1 rule.
		'browser mcp'       => array( 'browser', 'MCP JSON-RPC' ),
		'crawler llms'      => array( 'crawler', 'llms.txt' ),
		'unrecorded'        => array( '', 'llms.txt' ),
	);

	/**
	 * Clean state per test.
	 */
	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * Leave globals as found.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * The rows a filter set shows, using the plugin's own client clause and bound values.
	 *
	 * @param array $filters Filter set.
	 * @return string[] Row labels shown.
	 */
	private function shown( array $filters ): array {
		$db              = new WebMcp_Log_Capturing_Wpdb();
		$GLOBALS['wpdb'] = $db;
		MMSAR_Agent_Log::get_entries( 50, 0, $filters );
		$this->assertNotEmpty( $db->prepared );
		list( $query, $args ) = $db->prepared[0];

		$this->assertSame( 1, preg_match( "/\( %s = '' OR FIND_IN_SET\( IF\( client_type.*?FIND_IN_SET\( surface, %s \) > 0 \) \)/s", $query, $m ), 'get_entries() should carry the client clause.' );
		// Arguments in order: %i table, the two verdict values, then this clause's four.
		$bound = array_slice( $args, 3, 4 );

		$pdo = new PDO( 'sqlite::memory:' );
		$pdo->sqliteCreateFunction(
			'FIND_IN_SET',
			static function ( $needle, $list ) {
				$pos = array_search( (string) $needle, explode( ',', (string) $list ), true );
				return false === $pos ? 0 : $pos + 1;
			},
			2
		);
		$pdo->exec( 'CREATE TABLE t ( label TEXT, client_type TEXT, surface TEXT )' );
		$insert = $pdo->prepare( 'INSERT INTO t VALUES ( ?, ?, ? )' );
		foreach ( self::ROWS as $label => $row ) {
			$insert->execute( array( $label, $row[0], $row[1] ) );
		}
		$where  = str_replace( array( 'IF(', '%s' ), array( 'iif(', '?' ), $m[0] );
		$select = $pdo->prepare( 'SELECT label FROM t WHERE ' . $where . ' ORDER BY rowid' );
		$select->execute( $bound );
		return array_map( 'strval', $select->fetchAll( PDO::FETCH_COLUMN ) );
	}

	/**
	 * Cases: filters, rows shown.
	 *
	 * @return array
	 */
	public static function views(): array {
		return array(
			'default view shows webmcp, hides page views' => array( array(), array( 'browser webmcp', 'browser manifest', 'http mcp', 'browser mcp', 'crawler llms', 'unrecorded' ) ),
			'browsers ticked shows every browser row'     => array( array( 'clients' => array( 'browser' ) ), array( 'browser page view', 'browser webmcp', 'browser manifest', 'browser lookalike', 'browser mcp' ) ),
			'http ticked is taken literally'              => array( array( 'clients' => array( 'http' ) ), array( 'http mcp' ) ),
			'crawlers ticked is taken literally'          => array( array( 'clients' => array( 'crawler' ) ), array( 'crawler llms' ) ),
		);
	}

	/**
	 * @param array    $filters  Filters.
	 * @param string[] $expected Rows shown.
	 */
	#[DataProvider( 'views' )]
	public function test_rows_shown( array $filters, array $expected ): void {
		$this->assertSame( $expected, $this->shown( $filters ) );
	}

	/**
	 * The MCP server records WebMCP calls under the surface the filter lets through.
	 */
	public function test_mcp_uses_the_same_surface_name(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mmsar-mcp.php' );
		$this->assertStringContainsString( 'MMSAR_Agent_Log::SURFACE_WEBMCP', $source );
	}

	/**
	 * webmcp.json is recorded under the surface the filter lets through.
	 */
	public function test_manifest_uses_the_same_surface_name(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mmsar-webmcp.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source in a test.
		$this->assertStringContainsString( 'MMSAR_Agent_Log::record( MMSAR_Agent_Log::SURFACE_WEBMCP_MANIFEST )', $source );
		$this->assertContains( MMSAR_Agent_Log::SURFACE_WEBMCP_MANIFEST, MMSAR_Agent_Log::default_view_browser_surfaces() );
	}
}
