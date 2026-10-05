<?php
/**
 * The Summary's findings: when each one appears, what it counts, and what it never counts.
 *
 * The findings layer is only useful if it stays quiet when there is nothing to say and never
 * counts a forged identity, so both are pinned here alongside what each finding says.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Agent_Insights::compute() and its helpers.
 */
final class InsightsTest extends TestCase {

	const NOW = 1790000000;

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * A grouped read row.
	 */
	private static function read( $name, $category, $n, $kind = 'html', $verified = 'verified', $path = '/a', $last = '2026-09-10 00:00:00', $after = false ) {
		return array(
			'name'     => $name,
			'category' => $category,
			'verified' => $verified,
			'kind'     => $kind,
			'path'     => $path,
			'n'        => $n,
			'last'     => $last,
			'after'    => $after,
		);
	}

	/**
	 * Context with everything off.
	 */
	private static function ctx( array $over = array() ) {
		return array_merge(
			array(
				'now'               => self::NOW,
				'features'          => array(),
				'ai_train'          => 'no',
				'declined'          => false,
				'declined_since'    => 0,
				'training_tokens'   => array( 'gptbot', 'claudebot', 'ccbot', 'amazonbot' ),
				'referrals'         => array( 'enabled' => false ),
				'forged'            => 0,
				'forged_names'      => array(),
				'unmatched_missing' => 0,
			),
			$over
		);
	}

	/**
	 * Finding ids per question.
	 */
	private static function ids( array $result ) {
		$out = array();
		foreach ( $result['questions'] as $q => $list ) {
			$out[ $q ] = array_column( $list, 'id' );
		}
		return $out;
	}

	/**
	 * One finding by id, or null.
	 */
	private static function find( array $result, $id ) {
		foreach ( $result['questions'] as $list ) {
			foreach ( $list as $f ) {
				if ( $id === $f['id'] ) {
					return $f;
				}
			}
		}
		return null;
	}

	public function test_nothing_in_nothing_out(): void {
		$r = MMSAR_Agent_Insights::compute( array(), array(), array(), self::ctx() );
		$this->assertSame( array( 'read' => array(), 'cited' => array(), 'broken' => array() ), self::ids( $r ) );
		$this->assertSame( array(), $r['info'] );
	}

	public function test_uptake_waits_for_enough_reads(): void {
		$few = array( self::read( 'GPTBot', 'ai-training', 30, 'md' ), self::read( 'PerplexityBot', 'ai-search', 19 ) );
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( $few, array(), array(), self::ctx( array( 'ai_train' => 'yes' ) ) ), 'uptake' ) );

		$enough = array( self::read( 'GPTBot', 'ai-training', 30, 'md' ), self::read( 'GPTBot', 'ai-training', 10 ), self::read( 'PerplexityBot', 'ai-search', 20 ) );
		$f      = self::find( MMSAR_Agent_Insights::compute( $enough, array(), array(), self::ctx( array( 'ai_train' => 'yes' ) ) ), 'uptake' );
		$this->assertNotNull( $f );
		$this->assertStringContainsString( 'AI training 75%', $f['text'] );
		$this->assertStringContainsString( 'AI search 0%', $f['text'] );
		$this->assertStringContainsString( 'mostly feeds the crawlers that train models', $f['text'] );
	}

	public function test_a_forged_identity_is_never_counted(): void {
		$reads = array( self::read( 'ChatGPT-User', 'ai-assistant', 40, 'html', 'failed' ) );
		$r     = MMSAR_Agent_Insights::compute( $reads, array(), array(), self::ctx() );
		$this->assertSame( array(), $r['questions']['cited'] );
	}

	public function test_assistant_fetches_name_the_pages_and_flag_the_owner_possibility(): void {
		$posts = array(
			'/a' => array(
				'id'       => 1,
				'title'    => 'About',
				'modified' => '2026-01-01 00:00:00',
			),
		);
		$reads = array(
			self::read( 'ChatGPT-User', 'ai-assistant', 5 ),
			self::read( 'Claude-User', 'ai-assistant', 2, 'md', 'client' ),
			self::read( 'Claude-User', 'ai-assistant', 4, 'html', 'unverifiable' ),
		);
		$f     = self::find( MMSAR_Agent_Insights::compute( $reads, array(), $posts, self::ctx() ), 'assistants' );
		$this->assertNotNull( $f );
		$this->assertSame( 'About', $f['items'][0]['label'] );
		$this->assertStringContainsString( '7 times', $f['text'] );
		$this->assertStringContainsString( 'may have been you', $f['text'] );
		$this->assertStringContainsString( 'Plus 4 more', $f['text'] );
	}

	public function test_referrals_offer_to_switch_on_only_when_assistants_read(): void {
		$reads = array( self::read( 'ChatGPT-User', 'ai-assistant', 5 ) );
		$f     = self::find( MMSAR_Agent_Insights::compute( $reads, array(), array(), self::ctx() ), 'referrals' );
		$this->assertSame( 'todo', $f['kind'] );
		$this->assertSame( 'settings#referrals', $f['action']['url'] );

		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( array(), array(), array(), self::ctx() ), 'referrals' ) );

		$on = self::ctx( array( 'referrals' => array( 'enabled' => true, 'total' => 0 ) ) );
		$this->assertSame( 'meaning', self::find( MMSAR_Agent_Insights::compute( array(), array(), array(), $on ), 'referrals' )['kind'] );
	}

	public function test_training_despite_no_training_points_at_the_decline_setting(): void {
		$reads = array( self::read( 'GPTBot', 'ai-training', 15 ), self::read( 'Amazonbot', 'ai-training', 10 ), self::read( 'SSI-Nutch', 'ai-training', 500 ) );
		$f     = self::find( MMSAR_Agent_Insights::compute( $reads, array(), array(), self::ctx() ), 'training' );
		$this->assertSame( 'todo', $f['kind'] );
		$this->assertSame( 'settings#decline-training', $f['action']['url'] );
		$this->assertStringContainsString( '25 pages', $f['text'], 'SSI-Nutch is not a declinable token, so it is not counted' );
	}

	public function test_training_is_quiet_when_training_is_allowed(): void {
		$reads = array( self::read( 'GPTBot', 'ai-training', 50 ) );
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( $reads, array(), array(), self::ctx( array( 'ai_train' => 'yes' ) ) ), 'training' ) );
	}

	public function test_after_declining_only_later_reads_count(): void {
		$since = self::NOW - 10 * DAY_IN_SECONDS;
		$ctx   = self::ctx(
			array(
				'declined'       => true,
				'declined_since' => $since,
			)
		);
		$before = array( self::read( 'GPTBot', 'ai-training', 50 ) );
		$f      = self::find( MMSAR_Agent_Insights::compute( $before, array(), array(), $ctx ), 'training' );
		$this->assertSame( 'meaning', $f['kind'], 'reads before the decline are not a violation' );

		$after = array( self::read( 'GPTBot', 'ai-training', 50 ), self::read( 'GPTBot', 'ai-training', 3, 'html', 'verified', '/a', '2026-09-20 00:00:00', true ) );
		$f     = self::find( MMSAR_Agent_Insights::compute( $after, array(), array(), $ctx ), 'training' );
		$this->assertSame( 'todo', $f['kind'] );
		$this->assertStringContainsString( 'read 3 pages after you declined', $f['text'] );
	}

	public function test_nothing_is_said_inside_the_grace_period(): void {
		$ctx = self::ctx(
			array(
				'declined'       => true,
				'declined_since' => self::NOW - DAY_IN_SECONDS,
			)
		);
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( array( self::read( 'GPTBot', 'ai-training', 50, 'html', 'verified', '/a', '2026-09-20', true ) ), array(), array(), $ctx ), 'training' ) );
	}

	/**
	 * @param string $path     Requested path.
	 * @param string $expected Standard id.
	 */
	#[DataProvider( 'paths' )]
	public function test_standard_for_path( string $path, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Insights::standard_for_path( $path ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function paths(): array {
		return array(
			'a2a card'          => array( '/.well-known/agent-card.json', 'a2a' ),
			'case and query'    => array( '/.Well-Known/Agent-Card.json?x=1', 'a2a' ),
			'acp prefix'        => array( '/agentic_commerce/delegate_payment', 'commerce' ),
			'acp checkout'      => array( '/checkout_sessions/abc', 'commerce' ),
			'openapi guessed'   => array( '/api/openapi.json', 'openapi' ),
			'mcp trailing'      => array( '/.well-known/mcp/', 'mcp' ),
			'security probe'    => array( '/api/proc/self/environ', '' ),
			'env file'          => array( '/.env', '' ),
			'graphql'           => array( '/graphql', '' ),
			'prefix lookalike'  => array( '/agentic_commerce_info', '' ),
			'root'              => array( '/', '' ),
		);
	}

	public function test_standards_say_what_can_be_switched_on(): void {
		$missing = array(
			array( 'path' => '/.well-known/mcp.json', 'n' => 3, 'agents' => 2 ),
			array( 'path' => '/.well-known/agent-card.json', 'n' => 8, 'agents' => 3 ),
			array( 'path' => '/.well-known/ai-plugin.json', 'n' => 1, 'agents' => 1 ),
			array( 'path' => '/api/env', 'n' => 40, 'agents' => 9 ),
		);
		$f       = self::find( MMSAR_Agent_Insights::compute( array(), $missing, array(), self::ctx() ), 'standards' );
		$this->assertSame( 'todo', $f['kind'] );
		$labels = array_column( $f['items'], 'label' );
		$this->assertContains( 'MCP server discovery', $labels );
		$this->assertContains( 'A2A agent card', $labels );
		$this->assertNotContains( 'ChatGPT plugin manifest', $labels, 'below the threshold' );
		$this->assertSame( 'mcp_server', $f['items'][ array_search( 'MCP server discovery', $labels, true ) ]['feature'] );

		$on = self::find( MMSAR_Agent_Insights::compute( array(), $missing, array(), self::ctx( array( 'features' => array( 'mcp_server' => true ) ) ) ), 'standards' );
		$this->assertSame( 'meaning', $on['kind'] );
	}

	public function test_stale_pages(): void {
		$day   = static function ( $n ) {
			return gmdate( 'Y-m-d H:i:s', self::NOW - $n * DAY_IN_SECONDS );
		};
		$posts = array(
			'/edited'      => array( 'id' => 1, 'title' => 'Edited, not re-read', 'modified' => $day( 5 ) ),
			'/reread'      => array( 'id' => 2, 'title' => 'Edited and re-read', 'modified' => $day( 5 ) ),
			'/just-edited' => array( 'id' => 3, 'title' => 'Edited yesterday', 'modified' => $day( 1 ) ),
			'/old'         => array( 'id' => 4, 'title' => 'Edited long ago', 'modified' => $day( 90 ) ),
		);
		$reads = array(
			self::read( 'PerplexityBot', 'ai-search', 1, 'html', 'verified', '/edited', $day( 6 ) ),
			self::read( 'PerplexityBot', 'ai-search', 1, 'html', 'verified', '/reread', $day( 4 ) ),
			self::read( 'PerplexityBot', 'ai-search', 1, 'html', 'verified', '/just-edited', $day( 2 ) ),
		);
		$f = self::find( MMSAR_Agent_Insights::compute( $reads, array(), $posts, self::ctx() ), 'stale' );
		$this->assertSame( array( 'Edited, not re-read' ), array_column( $f['items'], 'label' ) );
		$this->assertSame( 'settings#indexnow', $f['action']['url'] );

		$on = self::find( MMSAR_Agent_Insights::compute( $reads, array(), $posts, self::ctx( array( 'features' => array( 'indexnow' => true ) ) ) ), 'stale' );
		$this->assertSame( '', $on['action']['url'] );
	}

	public function test_coverage_only_when_low(): void {
		$posts = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$posts[ '/p' . $i ] = array( 'id' => $i, 'title' => 'P' . $i, 'modified' => '2026-01-01 00:00:00' );
		}
		$most = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$most[] = self::read( 'GPTBot', 'ai-training', 1, 'html', 'verified', '/p' . $i );
		}
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( $most, array(), $posts, self::ctx( array( 'ai_train' => 'yes' ) ) ), 'coverage' ) );

		$few = array_slice( $most, 0, 3 );
		$f   = self::find( MMSAR_Agent_Insights::compute( $few, array(), $posts, self::ctx( array( 'ai_train' => 'yes' ) ) ), 'coverage' );
		$this->assertStringContainsString( '7 of your 10', $f['text'] );
	}

	/**
	 * @param string $detail   Stored detail.
	 * @param string $expected Page key.
	 */
	#[DataProvider( 'details' )]
	public function test_page_key( string $detail, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Insights::page_key( $detail ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function details(): array {
		return array(
			'html with slash'   => array( '/about/', '/about' ),
			'html with query'   => array( '/about/?utm_source=chatgpt.com', '/about' ),
			'markdown'          => array( '/about.md', '/about' ),
			'nested markdown'   => array( '/plugins/x.md', '/plugins/x' ),
			'front page'        => array( '/', '/' ),
			'front page query'  => array( '/?s=x', '/' ),
			'index.md'          => array( '/index.md', '/' ),
		);
	}

	// -------------------------------------------------------------------------
	// WebMCP use (1.58.0)
	// -------------------------------------------------------------------------

	/**
	 * WebMCP context: rows shaped like the live log's (detail, arguments, n).
	 *
	 * @param array[] $calls    Rows.
	 * @param int     $networks Distinct networks.
	 * @param int     $manifest webmcp.json fetches.
	 */
	private static function webmcp( array $calls, $networks = 1, $manifest = 0 ) {
		return self::ctx(
			array(
				'webmcp' => array(
					'calls'    => $calls,
					'networks' => $networks,
					'manifest' => $manifest,
				),
			)
		);
	}

	/**
	 * The one row live held on 2026-10-05: Claude's test call from HeadlessChrome. One call is
	 * below the threshold, so the owner's own check never shows up as use.
	 */
	public function test_webmcp_silent_on_a_single_test_call(): void {
		$ctx = self::webmcp(
			array(
				array(
					'detail'    => 'tools/call: get_content',
					'arguments' => 'url=/contact/',
					'n'         => 1,
				),
			),
			1,
			1
		);
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( array(), array(), array(), $ctx ), 'webmcp' ) );
	}

	public function test_webmcp_silent_with_no_data(): void {
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( array(), array(), array(), self::ctx() ), 'webmcp' ) );
	}

	/**
	 * Methods other than tools/call are not use: a client listing tools has not used one.
	 */
	public function test_webmcp_counts_tool_calls_only(): void {
		$ctx = self::webmcp(
			array(
				array(
					'detail'    => 'tools/list',
					'arguments' => '',
					'n'         => 9,
				),
				array(
					'detail'    => 'tools/call: search_content',
					'arguments' => 'query="ai"',
					'n'         => 2,
				),
			)
		);
		$this->assertNull( self::find( MMSAR_Agent_Insights::compute( array(), array(), array(), $ctx ), 'webmcp' ) );
	}

	public function test_webmcp_names_tools_pages_and_questions(): void {
		$posts = array(
			'/contact' => array(
				'id'       => 1,
				'title'    => 'Contact',
				'modified' => '2026-09-01 00:00:00',
			),
		);
		$ctx   = self::webmcp(
			array(
				array(
					'detail'    => 'tools/call: search_content',
					'arguments' => 'topic=ai query="wordpress mcp"',
					'n'         => 4,
				),
				array(
					'detail'    => 'tools/call: get_content',
					'arguments' => 'url=/contact/',
					'n'         => 2,
				),
				array(
					'detail'    => 'tools/call: get_content',
					'arguments' => 'url=/about/',
					'n'         => 1,
				),
			),
			3,
			5
		);
		$f     = self::find( MMSAR_Agent_Insights::compute( array(), array(), $posts, $ctx ), 'webmcp' );
		$this->assertNotNull( $f );
		$this->assertSame( 'meaning', $f['kind'] );
		$this->assertStringContainsString( '7 times, from 3 networks (search_content 4, get_content 3)', $f['text'] );
		$this->assertStringContainsString( '"wordpress mcp"', $f['text'] );
		$this->assertStringContainsString( '"ai"', $f['text'] );
		$this->assertStringContainsString( 'webmcp.json, the list of these tools, was fetched 5 times', $f['text'] );
		$this->assertSame( 'Contact', $f['items'][0]['label'] );
		$this->assertSame( '/contact', $f['items'][0]['path'] );
		$this->assertSame( '/about', $f['items'][1]['label'] );
		$this->assertSame( array( 'surface' => array( 'webmcp' ) ), $f['evidence'] );
		$this->assertContains( 'webmcp', self::ids( MMSAR_Agent_Insights::compute( array(), array(), $posts, $ctx ) )['read'] );
	}

	/**
	 * @param string $line     Stored arguments.
	 * @param array  $expected Parsed.
	 */
	#[DataProvider( 'argumentLines' )]
	public function test_parse_arguments( string $line, array $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Insights::parse_arguments( $line ) );
	}

	/**
	 * Shapes MMSAR_MCP_Arguments writes.
	 *
	 * @return array<string, array{0:string,1:array}>
	 */
	public static function argumentLines(): array {
		return array(
			'empty'               => array( '', array() ),
			'url'                 => array( 'url=/contact/', array( 'url' => '/contact/' ) ),
			'several'             => array( 'post_type=post topic=ai', array( 'post_type' => 'post', 'topic' => 'ai' ) ),
			'quoted query'        => array( 'topic=ai query="a b=c"', array( 'topic' => 'ai', 'query' => 'a b=c' ) ),
			'escaped quote'       => array( 'query="say \"hi\""', array( 'query' => 'say "hi"' ) ),
			'unicode'             => array( 'query="וורדפרס"', array( 'query' => 'וורדפרס' ) ),
		);
	}
}
