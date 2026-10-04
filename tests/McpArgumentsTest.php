<?php
/**
 * MCP tool-call arguments in the agent log (1.56.0).
 *
 * Two halves. The summariser is tested with injected lookups, one case per rule in the class's
 * table: each argument is stored only after its check passes, in the form the site knows it by,
 * and dropped otherwise. The redaction is tested on the shapes that matter (contact details out,
 * ordinary queries untouched, one line whatever arrives). The recording half drives the real
 * record() against a capturing $wpdb to show the column is written and that the arguments are in
 * the throttle key only when the caller passes them there.
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
 * A $wpdb that records inserts and answers nothing.
 */
final class Mcp_Arguments_Capturing_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Never a multiple of PRUNE_EVERY, so prune() is not reached.
	 *
	 * @var int
	 */
	public $insert_id = 1;

	/**
	 * Every row passed to insert().
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Records the row.
	 *
	 * @param string $table  Table.
	 * @param array  $data   Row.
	 * @param array  $format Formats.
	 * @return int
	 */
	public function insert( $table, $data, $format = array() ) {
		$this->rows[] = $data;
		return 1;
	}

	/**
	 * Reads answer nothing.
	 *
	 * @return array
	 */
	public function get_results() {
		return array();
	}

	/**
	 * Statements pass through.
	 *
	 * @param string $query Query.
	 * @return string
	 */
	public function prepare( $query ) {
		return $query;
	}
}

/**
 * Arguments: what is stored, what is dropped, and how a query is redacted.
 */
final class McpArgumentsTest extends TestCase {

	/**
	 * Lookups standing in for the site: two enabled types, two topics (one reached by an alias
	 * spelling), and two resolvable URLs.
	 *
	 * @param bool $queries Whether query logging is on.
	 * @return array
	 */
	private function checks( bool $queries = false ): array {
		return array(
			'post_type' => static function ( $type ) {
				return in_array( $type, array( 'post', 'ms_plugin' ), true );
			},
			'topic'     => static function ( $slug ) {
				$known = array(
					'wordpress' => 'wordpress',
					'WordPress' => 'wordpress',
					'ai'        => 'ai',
				);
				return isset( $known[ $slug ] ) ? $known[ $slug ] : null;
			},
			'url'       => static function ( $url ) {
				$known = array(
					'/about/'                       => '/about/',
					'https://example.com/about.md'  => '/about/',
					'about'                         => '/about/',
				);
				return isset( $known[ $url ] ) ? $known[ $url ] : null;
			},
			'queries'   => $queries,
		);
	}

	/**
	 * Clean state per test.
	 */
	protected function setUp(): void {
		wp_stub_reset();
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Leave globals as found.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'] );
	}

	// -------------------------------------------------------------------------
	// summarize()
	// -------------------------------------------------------------------------

	/**
	 * Cases: tool, arguments, query logging on, expected stored line.
	 *
	 * @return array
	 */
	public static function calls(): array {
		return array(
			'known type and topic'               => array( 'list_content', array( 'post_type' => 'ms_plugin', 'topic' => 'wordpress' ), false, 'post_type=ms_plugin topic=wordpress' ),
			'topic stored by its canonical slug' => array( 'list_content', array( 'topic' => 'WordPress' ), false, 'topic=wordpress' ),
			'unknown type dropped'               => array( 'list_content', array( 'post_type' => 'attachment' ), false, '' ),
			'unknown topic dropped'              => array( 'list_content', array( 'topic' => '<script>' ), false, '' ),
			'limit and offset never stored'      => array( 'list_content', array( 'limit' => 5, 'offset' => 40 ), false, '' ),
			'non-string type dropped'            => array( 'list_content', array( 'post_type' => array( 'post' ) ), false, '' ),
			'search topic kept, query off'       => array( 'search_content', array( 'query' => 'mcp', 'topic' => 'ai' ), false, 'topic=ai' ),
			'query stored when on'               => array( 'search_content', array( 'query' => 'mcp server' ), true, 'query="mcp server"' ),
			'query is JSON-quoted'               => array( 'search_content', array( 'query' => 'a "b" c=d' ), true, 'query="a \"b\" c=d"' ),
			'non-string query dropped'           => array( 'search_content', array( 'query' => array( 'x' ) ), true, '' ),
			'url stored as the served path'      => array( 'get_content', array( 'url' => 'https://example.com/about.md' ), false, 'url=/about/' ),
			'bare path resolved'                 => array( 'get_content', array( 'url' => 'about' ), false, 'url=/about/' ),
			'unresolvable url dropped'           => array( 'get_content', array( 'url' => 'https://evil.example/x' ), false, '' ),
			'query on get_content ignored'       => array( 'get_content', array( 'query' => 'secret' ), true, '' ),
			'known sections kept in order'       => array( 'get_site_overview', array( 'sections' => array( 'endpoints', 'nope', 'about', 7 ) ), false, 'sections=about,endpoints' ),
			'no known sections'                  => array( 'get_site_overview', array( 'sections' => array( 'x' ) ), false, '' ),
			'topic on overview ignored'          => array( 'get_site_overview', array( 'topic' => 'ai' ), false, '' ),
			'a tool from the filter'             => array( 'book_table', array( 'post_type' => 'post', 'query' => 'x' ), true, '' ),
			'arguments not an object'            => array( 'list_content', 'post_type=post', false, '' ),
		);
	}

	/**
	 * @param string $tool      Tool.
	 * @param mixed  $arguments Arguments.
	 * @param bool   $queries   Query logging.
	 * @param string $expected  Stored line.
	 */
	#[DataProvider( 'calls' )]
	public function test_what_is_stored( string $tool, $arguments, bool $queries, string $expected ): void {
		$this->assertSame( $expected, MMSAR_MCP_Arguments::summarize( $tool, $arguments, $this->checks( $queries ) ) );
	}

	/**
	 * The url is only ever stored in the form the lookup returned, never as typed. A lookup that
	 * passes the input through would make this fail, which is the point.
	 */
	public function test_url_is_never_the_raw_input(): void {
		$line = MMSAR_MCP_Arguments::summarize( 'get_content', array( 'url' => 'https://example.com/about.md' ), $this->checks() );
		$this->assertStringNotContainsString( 'example.com', $line );
		$this->assertStringNotContainsString( '.md', $line );
	}

	/**
	 * The stored line never exceeds the column, however much the caller sends.
	 */
	public function test_line_fits_the_column(): void {
		$line = MMSAR_MCP_Arguments::summarize( 'search_content', array( 'query' => str_repeat( 'word ', 2000 ) ), $this->checks( true ) );
		$this->assertLessThanOrEqual( MMSAR_MCP_Arguments::MAX_LENGTH, mb_strlen( $line ) );
	}

	// -------------------------------------------------------------------------
	// redact_query()
	// -------------------------------------------------------------------------

	/**
	 * Cases: raw query, stored query.
	 *
	 * @return array
	 */
	public static function queries(): array {
		return array(
			'plain'                    => array( 'wordpress mcp', 'wordpress mcp' ),
			'email'                    => array( 'contact jane.doe@example.co.il about talks', 'contact [email] about talks' ),
			'international phone'      => array( 'call +972-54-123-4567 tomorrow', 'call [number] tomorrow' ),
			'us phone with brackets'   => array( '(415) 555-0132 speaker', '[number] speaker' ),
			'card number'              => array( '4111 1111 1111 1111', '[number]' ),
			'year pair kept'           => array( 'wordcamp 2025-2026', 'wordcamp 2025-2026' ),
			'version kept'             => array( 'elementor 3.31.2 mcp', 'elementor 3.31.2 mcp' ),
			'short number kept'        => array( 'top 10 plugins', 'top 10 plugins' ),
			'newline becomes a space'  => array( "a\nb\r\nc", 'a b c' ),
			'line separator'           => array( "a\u{2028}b", 'a b' ),
			'tags stripped'            => array( '<b>bold</b> <script>x()</script>move', 'bold move' ),
			'hebrew kept'              => array( 'וורדפרס בינה מלאכותית', 'וורדפרס בינה מלאכותית' ),
			'invalid utf-8'            => array( "\xC3\x28 bad", '' ),
			'whitespace only'          => array( "  \t ", '' ),
		);
	}

	/**
	 * @param string $raw      Raw query.
	 * @param string $expected Stored query.
	 */
	#[DataProvider( 'queries' )]
	public function test_redaction( string $raw, string $expected ): void {
		$this->assertSame( $expected, MMSAR_MCP_Arguments::redact_query( $raw ) );
	}

	/**
	 * A long query is cut to the cap and marked as cut.
	 */
	public function test_long_query_is_capped(): void {
		$stored = MMSAR_MCP_Arguments::redact_query( str_repeat( 'abc ', 200 ) );
		$this->assertSame( MMSAR_MCP_Arguments::MAX_QUERY, mb_strlen( $stored ) );
		$this->assertStringEndsWith( '…', $stored );
	}

	/**
	 * Query logging is off unless the option says otherwise.
	 */
	public function test_query_logging_is_off_by_default(): void {
		$this->assertFalse( MMSAR_MCP_Arguments::queries_enabled() );
		$GLOBALS['wp_stub_options'][ MMSAR_MCP_Arguments::QUERY_OPTION ] = '1';
		$this->assertTrue( MMSAR_MCP_Arguments::queries_enabled() );
	}

	// -------------------------------------------------------------------------
	// record()
	// -------------------------------------------------------------------------

	/**
	 * Sends one request from a fixed caller and returns the rows written.
	 *
	 * @param array[] $calls Each [ detail, throttle_detail, arguments ].
	 * @return array[]
	 */
	private function record_calls( array $calls ): array {
		$db                         = new Mcp_Arguments_Capturing_Wpdb();
		$GLOBALS['wpdb']            = $db;
		$_SERVER['HTTP_USER_AGENT'] = 'node';
		$_SERVER['REMOTE_ADDR']     = '3.83.8.90';
		foreach ( $calls as $call ) {
			MMSAR_Agent_Log::record( 'MCP JSON-RPC', $call[0], true, false, $call[1], $call[2] );
		}
		return $db->rows;
	}

	/**
	 * The arguments are written to their own column, and the detail is untouched.
	 */
	public function test_arguments_are_stored_in_their_own_column(): void {
		$rows = $this->record_calls( array( array( 'tools/call: list_content', 'tools/call: list_content topic=ai', 'topic=ai' ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'tools/call: list_content', $rows[0]['detail'] );
		$this->assertSame( 'topic=ai', $rows[0]['arguments'] );
	}

	/**
	 * Two calls to one tool asking for different things are two entries; the same call twice is one.
	 */
	public function test_arguments_join_the_throttle_key(): void {
		$rows = $this->record_calls(
			array(
				array( 'tools/call: list_content', 'tools/call: list_content topic=ai', 'topic=ai' ),
				array( 'tools/call: list_content', 'tools/call: list_content topic=wordpress', 'topic=wordpress' ),
				array( 'tools/call: list_content', 'tools/call: list_content topic=ai', 'topic=ai' ),
			)
		);
		$this->assertSame( array( 'topic=ai', 'topic=wordpress' ), array_column( $rows, 'arguments' ) );
	}

	/**
	 * Every other surface writes an empty arguments column.
	 */
	public function test_other_surfaces_store_no_arguments(): void {
		$db                         = new Mcp_Arguments_Capturing_Wpdb();
		$GLOBALS['wpdb']            = $db;
		$_SERVER['HTTP_USER_AGENT'] = 'node';
		$_SERVER['REMOTE_ADDR']     = '3.83.8.90';
		MMSAR_Agent_Log::record( 'llms.txt' );
		$this->assertSame( '', $db->rows[0]['arguments'] );
	}

	/**
	 * The column write is capped even if a caller skips the summariser.
	 */
	public function test_record_caps_the_column(): void {
		$rows = $this->record_calls( array( array( 'tools/call: search_content', 'k', str_repeat( 'x', 900 ) ) ) );
		$this->assertSame( 500, mb_strlen( $rows[0]['arguments'] ) );
	}
}
