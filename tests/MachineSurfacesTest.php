<?php
/**
 * robots.txt and feeds: recorded under surfaces of their own, and categorised as neither agent
 * documents nor HTML page views (1.49.0).
 *
 * Two halves. The recording half drives the real hooks — maybe_record_page_view(),
 * maybe_record_robots(), maybe_record_feed() — against stubbed conditional tags and a capturing
 * $wpdb. The category half does not restate the category rule in PHP: it captures the CASE
 * expression the plugin actually sends to the database, with the values it binds, and evaluates it
 * on SQLite. A PHP copy of the rule would pass while the SQL was wrong, which is the one failure
 * this has to catch.
 *
 * @package Make_My_Site_Agent_Ready
 */

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

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Just enough of a term for the feed-path branch that reads one. Declared through eval() so static
// analysis keeps reading WordPress's own WP_Term, which the plugin code is checked against.
if ( ! class_exists( 'WP_Term' ) ) {
	eval( 'final class WP_Term { public $term_id = 0; public $taxonomy = ""; public function __construct( $term ) { foreach ( get_object_vars( $term ) as $k => $v ) { $this->$k = $v; } } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- See above.
}

/*
 * Conditional tags and link builders, answering from one array a test sets. These are the query
 * state, which is what the code under test branches on; every value defaults to false/empty.
 */
$GLOBALS['mmsar_test_query'] = array();

/**
 * One query-state value.
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function mmsar_test_q( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['mmsar_test_query'] ) ? $GLOBALS['mmsar_test_query'][ $key ] : $default;
}

foreach ( array( 'is_admin', 'is_feed', 'is_robots', 'is_favicon', 'is_404', 'is_singular', 'is_front_page', 'is_home', 'is_search', 'is_date', 'is_archive', 'is_comment_feed' ) as $mmsar_tag ) {
	if ( ! function_exists( $mmsar_tag ) ) {
		eval( "function {$mmsar_tag}() { return (bool) mmsar_test_q( '{$mmsar_tag}' ); }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test stubs for twelve identical conditional tags.
	}
}
unset( $mmsar_tag );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Stubs of core functions, documented in core.
function get_queried_object() {
	return mmsar_test_q( 'queried_object', null );
}
function get_queried_object_id() {
	return (int) mmsar_test_q( 'queried_object_id', 0 );
}
function get_permalink( $post = 0, $leavename = false ) {
	return 'https://example.com/about/';
}
function get_query_var( $name ) {
	return 'feed' === $name ? (string) mmsar_test_q( 'feed', '' ) : '';
}
function get_default_feed() {
	return 'rss2';
}
// Mirrors core on a site using /%postname%/ permalinks: the default format is left out of the path.
function mmsar_test_feed_suffix( $feed ) {
	return ( '' === $feed || 'rss2' === $feed ) ? 'feed/' : 'feed/' . $feed . '/';
}
function get_feed_link( $feed = '' ) {
	if ( 0 === strpos( $feed, 'comments_' ) ) {
		return 'https://example.com/comments/' . mmsar_test_feed_suffix( substr( $feed, 9 ) );
	}
	return 'https://example.com/' . mmsar_test_feed_suffix( $feed );
}
function get_term_feed_link( $term_id, $taxonomy = 'category', $feed = '' ) {
	return 'https://example.com/' . $taxonomy . '/ai/' . mmsar_test_feed_suffix( $feed );
}
function get_author_feed_link( $author_id, $feed = '' ) {
	return 'https://example.com/author/miriam/' . mmsar_test_feed_suffix( $feed );
}
function get_post_comments_feed_link( $post_id = 0, $feed = '' ) {
	return 'https://example.com/about/' . mmsar_test_feed_suffix( $feed );
}
function get_post_type_archive_feed_link( $post_type, $feed = '' ) {
	return 'https://example.com/' . $post_type . '/' . mmsar_test_feed_suffix( $feed );
}
// phpcs:enable

/**
 * A $wpdb that records inserts and prepared statements, and returns nothing from reads.
 */
final class Machine_Capturing_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Id of the last insert. Never a multiple of PRUNE_EVERY, so prune() is not reached.
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
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );
		return $query;
	}

	/**
	 * Every read returns nothing.
	 *
	 * @return array
	 */
	public function get_results() {
		return array();
	}

	/**
	 * The one scalar read reached here is table_exists(), which should say yes.
	 *
	 * @return string
	 */
	public function get_var() {
		return '1';
	}

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
}

/**
 * robots.txt and feed recording, and the categories they land in.
 */
final class MachineSurfacesTest extends TestCase {

	/**
	 * The capturing $wpdb, held typed so its extra properties are visible to analysis.
	 *
	 * @var Machine_Capturing_Wpdb
	 */
	private $db;

	const CLAUDEBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
	const MINIFLUX  = 'Mozilla/5.0 (compatible; Miniflux/2.2.3; +https://miniflux.app)';
	const CHROME    = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

	/**
	 * A residential address outside every bundled cloud range, and one inside AWS's.
	 */
	const HOME = '86.209.233.10';
	const AWS  = '3.83.8.90';

	/**
	 * The $_SERVER keys any test may set, cleared around each one.
	 */
	const KEYS = array( 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_CH_UA', 'HTTP_REFERER', 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'REQUEST_URI' );

	/**
	 * Clean state per test: log on, every page view recorded.
	 */
	protected function setUp(): void {
		wp_stub_reset();
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$this->db                                       = new Machine_Capturing_Wpdb();
		$GLOBALS['wpdb']                                = $this->db;
		$GLOBALS['mmsar_test_query']                    = array();
		$GLOBALS['wp_rewrite']                          = (object) array( 'feeds' => array( 'feed', 'rdf', 'rss', 'rss2', 'atom' ) );
		$GLOBALS['wp_stub_options']['mmsar_agent_log_pages'] = 'all';
	}

	/**
	 * Leave globals as found.
	 */
	protected function tearDown(): void {
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		MMSAR_Agent_Log::record_feed_response( 0 ); // Clears anything a failed test left noted.
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_rewrite'] );
		$GLOBALS['mmsar_test_query'] = array();
	}

	/**
	 * Sets up one request.
	 *
	 * @param string $ua    User-agent.
	 * @param string $ip    Client address.
	 * @param array  $query Query state.
	 * @param array  $extra Further $_SERVER entries.
	 */
	private function request( string $ua, string $ip, array $query, array $extra = array() ): void {
		$_SERVER['HTTP_USER_AGENT']  = $ua;
		$_SERVER['REMOTE_ADDR']      = $ip;
		$_SERVER                     = array_merge( $_SERVER, $extra );
		$GLOBALS['mmsar_test_query'] = $query;
	}

	/**
	 * Sends the current request through the feed hook, then answers it with a status.
	 *
	 * @param int $status HTTP status the response went out with.
	 */
	private function feed( int $status ): void {
		MMSAR_Agent_Log::maybe_record_feed( array() );
		MMSAR_Agent_Log::record_feed_response( $status );
	}

	/**
	 * Rows written so far.
	 *
	 * @return array[]
	 */
	private function rows(): array {
		return $this->db->rows;
	}

	// -------------------------------------------------------------------------
	// Categories, evaluated from the SQL the plugin sends
	// -------------------------------------------------------------------------

	/**
	 * Categorises rows with the plugin's own CASE expression and bound values, on SQLite.
	 *
	 * Captures the statement get_surfaces_in_category() prepares, cuts the CASE … END expression out
	 * of it, and binds the values that precede the category argument. Nothing about the rule is
	 * restated here.
	 *
	 * @param array[] $rows Rows of [ surface, detail ].
	 * @return string[] Category per row, in order.
	 */
	private function categorise( array $rows ): array {
		MMSAR_Agent_Log::get_surfaces_in_category( 'html' );
		$prepared = $this->db->prepared;
		$captured = end( $prepared );
		$this->assertIsArray( $captured, 'get_surfaces_in_category() should prepare a statement.' );
		list( $query, $args ) = $captured;
		$this->assertIsArray( $args );

		$this->assertSame( 1, preg_match( '/CASE .*? END/s', $query, $m ), 'The statement should carry a CASE expression.' );
		$case = $m[0];

		// Arguments in order: %i table, the CASE's own values, then the category and the limit.
		$needed    = substr_count( $case, '%s' );
		$case_args = array_slice( $args, 1, $needed );
		$this->assertCount( $needed, $case_args );

		$pdo = new PDO( 'sqlite::memory:' );
		$pdo->exec( 'CREATE TABLE t ( n INTEGER, surface TEXT, detail TEXT )' );
		$insert = $pdo->prepare( 'INSERT INTO t VALUES ( ?, ?, ? )' );
		foreach ( array_values( $rows ) as $i => $row ) {
			$insert->execute( array( $i, $row[0], $row[1] ) );
		}

		$select = $pdo->prepare( 'SELECT ' . str_replace( '%s', '?', $case ) . ' AS category FROM t ORDER BY n' );
		$select->execute( $case_args );
		return array_map( 'strval', $select->fetchAll( PDO::FETCH_COLUMN ) );
	}

	/**
	 * @dataProvider categorisedRows
	 *
	 * @param string $surface  Stored surface.
	 * @param string $detail   Stored detail.
	 * @param string $expected Category.
	 */
	public function test_category_of_a_stored_row( string $surface, string $detail, string $expected ): void {
		$this->assertSame( array( $expected ), $this->categorise( array( array( $surface, $detail ) ) ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function categorisedRows(): array {
		return array(
			'robots.txt, 1.49.0 onward'           => array( 'robots.txt', '', 'robots' ),
			'robots.txt stored as a page view'    => array( 'HTML page view (asked for HTML)', '/robots.txt', 'robots' ),
			'… under a wildcard Accept'           => array( 'HTML page view (Accept: */*)', '/robots.txt', 'robots' ),
			'… with no Accept'                    => array( 'HTML page view (no Accept)', '/robots.txt', 'robots' ),
			'… with a query string'               => array( 'HTML page view (no Accept)', '/robots.txt?x=1', 'robots' ),
			'a page view is still html'           => array( 'HTML page view (asked for HTML)', '/about/', 'html' ),
			'a longer name is not robots.txt'     => array( 'HTML page view (asked for HTML)', '/robots.txt.bak', 'html' ),
			'robots.txt below the root is a page' => array( 'HTML page view (asked for HTML)', '/blog/robots.txt', 'html' ),
			'the homepage is html'                => array( 'HTML page view (no Accept)', '/', 'html' ),
			'a feed'                              => array( 'Feed', '/feed/', 'feed' ),
			'a comment feed'                      => array( 'Feed', '/comments/feed/', 'feed' ),
			'llms.txt is still a document'        => array( 'llms.txt', '', 'docs' ),
			'MCP is still a document'             => array( 'MCP JSON-RPC', 'initialize', 'docs' ),
			'markdown is still markdown'          => array( 'Markdown (.md URL)', '/about/', 'markdown' ),
			'a 404 is still a 404'                => array( '404 (markdown)', '/robots.txt', 'notfound' ),
		);
	}

	/**
	 * The CASE is written out in every query that filters or groups by category, because each
	 * statement has to be a fixed string. Five copies of one rule is only safe if they are the same
	 * rule, and category_patterns() only supplies the right values if every copy asks for them in
	 * the same order.
	 */
	public function test_every_category_case_is_the_same_expression(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mmsar-agent-log.php' );
		preg_match_all( "/CASE WHEN surface .*?ELSE 'docs' END/s", $source, $m );

		$this->assertCount( 5, $m[0], 'Five queries categorise surfaces; a sixth needs adding here and to this rule.' );
		$normalised = array_unique( array_map( static fn( $c ) => preg_replace( '/\s+/', ' ', $c ), $m[0] ) );
		$this->assertCount( 1, $normalised, 'Every copy of the category CASE should be identical.' );
	}

	/**
	 * Every category has a label, and the two new ones are neither docs nor html.
	 */
	public function test_categories_include_robots_and_feed(): void {
		$categories = MMSAR_Agent_Log::categories();
		$this->assertContains( 'robots', $categories );
		$this->assertContains( 'feed', $categories );
		$this->assertSame( count( $categories ), count( array_unique( array_map( array( MMSAR_Agent_Log::class, 'category_label' ), $categories ) ) ) );
	}

	// -------------------------------------------------------------------------
	// robots.txt
	// -------------------------------------------------------------------------

	/**
	 * The bug: robots.txt reached maybe_record_page_view() and was stored as an HTML page view.
	 */
	public function test_robots_txt_is_not_recorded_as_a_page_view(): void {
		$this->request( self::CLAUDEBOT, '160.79.104.10', array( 'is_robots' => true ), array( 'HTTP_ACCEPT' => 'text/html' ) );
		MMSAR_Agent_Log::maybe_record_page_view();
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * It is recorded under its own surface instead, with no Accept summary, and categorised as
	 * robots.txt rather than html.
	 */
	public function test_robots_txt_is_recorded_as_robots(): void {
		$this->request( self::CLAUDEBOT, '160.79.104.10', array( 'is_robots' => true ), array( 'HTTP_ACCEPT' => 'text/html' ) );
		MMSAR_Agent_Log::maybe_record_robots();

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'robots.txt', $rows[0]['surface'] );
		$this->assertSame( '', $rows[0]['detail'] );
		$this->assertSame( '160.79.104.10', $rows[0]['ip'], 'A crawler keeps its full address, as on a page view.' );
		$this->assertSame( array( 'robots' ), $this->categorise( array( array( $rows[0]['surface'], $rows[0]['detail'] ) ) ) );
	}

	/**
	 * A script that is not a crawler is stored against its network, the page-view rule.
	 */
	public function test_robots_txt_from_a_script_is_reduced(): void {
		$this->request( 'curl/8.7.1', self::HOME, array( 'is_robots' => true ) );
		MMSAR_Agent_Log::maybe_record_robots();
		$this->assertSame( '86.209.233.0', $this->rows()[0]['ip'] );
	}

	/**
	 * maybe_record_robots() is registered for every request; on anything else it does nothing.
	 */
	public function test_robots_hook_ignores_other_requests(): void {
		$this->request( self::CLAUDEBOT, '160.79.104.10', array( 'is_singular' => true, 'queried_object_id' => 5 ) );
		MMSAR_Agent_Log::maybe_record_robots();
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * The favicon is not a page either.
	 */
	public function test_favicon_is_not_a_page_view(): void {
		$this->request( self::CHROME, self::HOME, array( 'is_favicon' => true ) );
		MMSAR_Agent_Log::maybe_record_page_view();
		$this->assertSame( array(), $this->rows() );
	}

	// -------------------------------------------------------------------------
	// An ordinary page view is unchanged
	// -------------------------------------------------------------------------

	/**
	 * The control: a normal page view still lands, still as an HTML page view, still html.
	 */
	public function test_a_normal_page_view_still_counts_as_html(): void {
		$this->request(
			self::CLAUDEBOT,
			'160.79.104.10',
			array( 'is_singular' => true, 'queried_object_id' => 5 ),
			array( 'HTTP_ACCEPT' => 'text/html', 'REQUEST_URI' => '/about/' )
		);
		MMSAR_Agent_Log::maybe_record_page_view();

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'HTML page view (asked for HTML)', $rows[0]['surface'] );
		$this->assertSame( '/about/', $rows[0]['detail'] );
		$this->assertSame( array( 'html' ), $this->categorise( array( array( $rows[0]['surface'], $rows[0]['detail'] ) ) ) );
	}

	// -------------------------------------------------------------------------
	// Feeds
	// -------------------------------------------------------------------------

	/**
	 * A feed is still not a page view.
	 */
	public function test_a_feed_is_not_a_page_view(): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'feed' ) );
		MMSAR_Agent_Log::maybe_record_page_view();
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A feed fetch is now recorded, under Feed, with the feed's path, and categorised as a feed. The
	 * hook is a filter, so it must hand the headers back untouched.
	 */
	public function test_a_feed_fetch_is_recorded_as_a_feed(): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'feed' ) );
		$headers = array( 'Content-Type' => 'application/rss+xml; charset=UTF-8' );

		$this->assertSame( $headers, MMSAR_Agent_Log::maybe_record_feed( $headers ) );
		MMSAR_Agent_Log::record_feed_response( 200 );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Feed', $rows[0]['surface'] );
		$this->assertSame( '/feed/', $rows[0]['detail'] );
		$this->assertSame( 'Miniflux', $rows[0]['agent'] );
		$this->assertSame( array( 'feed' ), $this->categorise( array( array( $rows[0]['surface'], $rows[0]['detail'] ) ) ) );
	}

	/**
	 * The detail is the canonical path of the feed that was served, whatever the request line said.
	 *
	 * @dataProvider feedPaths
	 *
	 * @param array  $query    Query state.
	 * @param string $expected Stored detail.
	 */
	public function test_feed_detail( array $query, string $expected ): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true ) + $query, array( 'REQUEST_URI' => '/?feed=rss2&s=private+words&cachebust=81723' ) );
		$this->feed( 200 );
		$this->assertSame( $expected, $this->rows()[0]['detail'] );
	}

	/**
	 * @return array<string, array{0:array,1:string}>
	 */
	public function feedPaths(): array {
		$term = new WP_Term(
			(object) array(
				'term_id'  => 3,
				'taxonomy' => 'category',
			)
		);
		return array(
			'main feed'            => array( array( 'feed' => 'feed' ), '/feed/' ),
			'main feed, rss2'      => array( array( 'feed' => 'rss2' ), '/feed/' ),
			'main feed, atom'      => array( array( 'feed' => 'atom' ), '/feed/atom/' ),
			'site comments'        => array( array( 'feed' => 'feed', 'is_comment_feed' => true ), '/comments/feed/' ),
			'?feed=comments-atom'  => array( array( 'feed' => 'comments-atom', 'is_comment_feed' => true ), '/comments/feed/atom/' ),
			'one post\'s comments' => array( array( 'feed' => 'feed', 'is_comment_feed' => true, 'is_singular' => true, 'queried_object_id' => 5 ), '/about/feed/' ),
			'a category'           => array( array( 'feed' => 'feed', 'is_archive' => true, 'queried_object' => $term ), '/category/ai/feed/' ),
			'a search, never its term' => array( array( 'feed' => 'rss2', 'is_search' => true ), '(search feed)' ),
			'a date archive'       => array( array( 'feed' => 'feed', 'is_archive' => true, 'is_date' => true ), '(date feed)' ),
			'an unresolved archive' => array( array( 'feed' => 'feed', 'is_archive' => true ), '(other feed)' ),
			'an unregistered type' => array( array( 'feed' => 'evil<script>' ), '(other feed)' ),
		);
	}

	/**
	 * The throttle keys on the resolved feed, so cache-busting query strings cannot mint rows — and
	 * two different feeds are still two facts.
	 */
	public function test_feed_throttle_is_bounded(): void {
		foreach ( array( 1, 2, 3 ) as $n ) {
			$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'feed' ), array( 'REQUEST_URI' => '/feed/?cachebust=' . $n ) );
			$this->feed( 200 );
		}
		$this->assertCount( 1, $this->rows() );

		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'atom' ) );
		$this->feed( 200 );
		$this->assertCount( 2, $this->rows() );
	}

	/**
	 * At wp_headers nobody knows yet whether a feed will be served, so nothing is written there.
	 */
	public function test_nothing_is_recorded_until_the_response_is_known(): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'feed' ) );
		MMSAR_Agent_Log::maybe_record_feed( array() );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A poll that found nothing new is still a poll — the case this hook exists for.
	 */
	public function test_a_304_is_recorded(): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'feed' ) );
		$this->feed( 304 );
		$this->assertSame( 'Feed', $this->rows()[0]['surface'] );
	}

	/**
	 * A feed request that something redirected — Yoast's crawl cleanup sends /feed/atom/ and
	 * /comments/feed/ to the homepage, after wp_headers has run — was never served a feed, and
	 * neither was one that errored.
	 *
	 * @dataProvider unservedStatuses
	 *
	 * @param int $status HTTP status.
	 */
	public function test_a_feed_that_was_not_served_is_not_recorded( int $status ): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'feed' => 'atom' ) );
		$this->feed( $status );
		$this->assertSame( array(), $this->rows() );

		// And the note does not survive to be recorded by a later response.
		MMSAR_Agent_Log::record_feed_response( 200 );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * @return array<string, array{0:int}>
	 */
	public function unservedStatuses(): array {
		return array(
			'301 redirect' => array( 301 ),
			'302 redirect' => array( 302 ),
			'410 gone'     => array( 410 ),
			'500 error'    => array( 500 ),
		);
	}

	/**
	 * A feed that 404s is left to the 404 surfaces, like a page.
	 */
	public function test_a_feed_404_is_not_recorded(): void {
		$this->request( self::MINIFLUX, self::AWS, array( 'is_feed' => true, 'is_404' => true, 'feed' => 'feed' ) );
		$this->feed( 200 );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Everything that is not a feed passes straight through.
	 */
	public function test_feed_hook_ignores_other_requests(): void {
		$this->request( self::CLAUDEBOT, '160.79.104.10', array( 'is_singular' => true ) );
		$this->assertSame( array( 'X' => '1' ), MMSAR_Agent_Log::maybe_record_feed( array( 'X' => '1' ) ) );
		MMSAR_Agent_Log::record_feed_response( 200 );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Decision 6B. The page-view address rule, except that a self-hosted reader keeps its full
	 * address only from inside a cloud range.
	 *
	 * @dataProvider feedAddresses
	 *
	 * @param string $ua     User-agent.
	 * @param string $ip     Client address.
	 * @param array  $extra  Further headers.
	 * @param string $stored Expected stored address.
	 */
	public function test_feed_address_rule( string $ua, string $ip, array $extra, string $stored ): void {
		$this->request( $ua, $ip, array( 'is_feed' => true, 'feed' => 'feed' ), $extra );
		$this->feed( 200 );
		$this->assertSame( $stored, $this->rows()[0]['ip'] );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:array,3:string}>
	 */
	public function feedAddresses(): array {
		$browser = array(
			'HTTP_SEC_FETCH_MODE' => 'navigate',
			'HTTP_SEC_FETCH_DEST' => 'document',
		);
		return array(
			'Miniflux on a home server is reduced'  => array( self::MINIFLUX, self::HOME, array(), '86.209.233.0' ),
			'Miniflux in a cloud keeps its address' => array( self::MINIFLUX, self::AWS, array(), self::AWS ),
			'Feedbin, a service, keeps its address' => array( 'Feedbin feed-id:1234 - 5 subscribers', self::HOME, array(), self::HOME ),
			'Feedly announces itself, and keeps it' => array( 'Feedly/1.0 (+http://www.feedly.com/fetcher.html; 12 subscribers)', self::HOME, array(), self::HOME ),
			'a desktop reader is reduced'           => array( 'NetNewsWire (RSS Reader; https://netnewswire.com/)', self::HOME, array(), '86.209.233.0' ),
			'a browser is reduced'                  => array( self::CHROME, self::AWS, $browser, '3.83.8.0' ),
		);
	}
}
