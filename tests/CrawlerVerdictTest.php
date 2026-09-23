<?php
/**
 * The crawler identity verdicts, one case per shape a stored log row can take.
 *
 * **Why this file exists.** Three defects in the 1.36.0–1.38.0 run were the same mistake wearing
 * different clothes: a value that looks like one thing and is sometimes another. The `agent` column
 * holds a crawler's bare canonical name when it was recognised, and its raw user-agent when it was
 * not, and both are read by the same function. A disclosure test added in 1.37.1 was correct for
 * raw user-agents and silently wrong for labels, which switched verification off for the one
 * crawler it was written to protect. It passed PHPCS, PHPStan and Semgrep at zero findings, and it
 * passed a live test on a clone — because that test drove real HTTP requests, which only ever
 * produce the raw-user-agent shape.
 *
 * So every case here names the *shape* it covers, not just the expectation. A future change that
 * handles one shape and forgets the other fails here rather than in production.
 *
 * The two cases that matter most are the linkup.com impostor and the `LinkUpBot` case variant.
 * Both are the false-accusation path — a real crawler reported as having forged someone else's
 * identity — and both were briefly reachable during that run. `failed` is the only verdict this
 * plugin treats as an accusation, and reaching it wrongly is worse than having no feature at all.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * Verdicts for every (agent, address) shape.
 */
final class CrawlerVerdictTest extends TestCase {

	/** Linkup's single published address, from includes/data/crawler-ranges.php. */
	private const LINKUP_IP = '35.198.113.100';

	/** That address reduced to its network, which is how pre-recognition page views are stored. */
	private const LINKUP_NET = '35.198.113.0';

	/** An address belonging to nobody in particular, for "not in the published range". */
	private const ELSEWHERE = '203.0.113.7';

	/** Inside Common Crawl's published 18.97.14.80/29, from includes/data/crawler-ranges.php. */
	private const CCBOT_IP = '18.97.14.84';

	/**
	 * Inside Common Crawl's published 2600:1f28:365:8000::/56.
	 *
	 * The only shape where the range file is the sole evidence: Common Crawl states that CCBot has
	 * no reverse DNS over IPv6, so nothing follows a range miss here.
	 */
	private const CCBOT_IP6 = '2600:1f28:365:8000::2b';

	/**
	 * Inside Anthropic's published 34.162.191.81/32 — a server-side claude.ai fetch.
	 *
	 * Observed, not only published: on 2026-09-23 Claude desktop chat was asked to read a unique
	 * probe URL on miriamschwab.me, and the request arrived as a bare `Claude-User` from exactly this
	 * address and verified. That is the evidence chat fetches server-side, so only Claude Code needs
	 * the user-run client verdict.
	 */
	private const ANTHROPIC_IP = '34.162.191.81';

	/** A residential address, standing in for a person running Claude Code on their own machine. */
	private const HOME_IP = '86.209.233.102';

	/** That address reduced to its network, which is how a user-run client's rows are stored. */
	private const HOME_NET = '86.209.233.0';

	/** The user-agent Claude Code 2.1.280 sends from WebFetch, read out of the shipped build. */
	private const CLAUDE_CODE_UA = 'Claude-User (claude-code/2.1.280; +https://support.anthropic.com/)';

	/** The same, run under the Agent SDK, which appends its own tokens inside the version comment. */
	private const CLAUDE_SDK_UA = 'Claude-User (claude-code/2.1.280 (agent-sdk/0.2.1, client-app/acme); +https://support.anthropic.com/)';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();

		// No test may make a real DNS query. Both lookups are filtered seams for exactly this
		// reason; leaving them live would make results depend on the network and on whoever
		// happens to hold an address today.
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	/**
	 * @dataProvider verdictShapes
	 *
	 * @param string $shape    What this row represents, for the failure message.
	 * @param string $agent    Value as stored in the log's `agent` column.
	 * @param string $ip       Value as stored in the log's `ip` column.
	 * @param string $expected Expected verdict.
	 */
	public function test_verdict_for_shape( string $shape, string $agent, string $ip, string $expected ): void {
		$this->assertSame(
			$expected,
			MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ),
			sprintf( 'Wrong verdict for: %s', $shape )
		);
	}

	/**
	 * Every shape a stored row can take, with the verdict it must reach.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string}>
	 */
	public function verdictShapes(): array {
		$linkup_raw   = 'LinkupBot/1.0 (LinkupBot for web indexing; https://linkup.so/bot; bot@linkup.so)';
		$impostor_raw = 'LinkUpBot (job aggregator; linkup.com)';
		$ssi_raw      = 'SSI-Nutch/1.23 (SSI broad web crawler; https://ssi.inc/; adi@ssi.inc)';

		return array(
			// The label shape. agent_label() writes the bare canonical name, having already applied
			// the disclosure guard and kept only its result. This is the shape 1.37.1 broke.
			'stored label, address in the published range'
				=> array( 'stored label, in range', 'LinkupBot', self::LINKUP_IP, 'verified' ),
			'stored label, address outside the published range'
				=> array( 'stored label, elsewhere', 'LinkupBot', self::ELSEWHERE, 'failed' ),

			// The reduced-address shape. A network is not inside a /32, and reporting "not inside"
			// as a forgery would accuse a real crawler on evidence discarded at storage time.
			'stored label, address reduced to its own network'
				=> array( 'reduced address', 'LinkupBot', self::LINKUP_NET, 'unverifiable' ),

			// The raw-user-agent shape, which is all a live HTTP test ever produces.
			'raw user-agent disclosing linkup.so, in range'
				=> array( 'raw UA, in range', $linkup_raw, self::LINKUP_IP, 'verified' ),

			// The false-accusation path. An unrelated crawler whose name matches case-insensitively.
			'linkup.com impostor is never accused'
				=> array( 'linkup.com impostor', $impostor_raw, self::ELSEWHERE, 'unclaimed' ),
			'bare name in a different case, no disclosure'
				=> array( 'LinkUpBot case variant', 'LinkUpBot', self::ELSEWHERE, 'unclaimed' ),

			// Recognised but unverifiable: the operator publishes no method at all.
			'SSI-Nutch stored label'
				=> array( 'SSI-Nutch label', 'SSI-Nutch', self::ELSEWHERE, 'unverifiable' ),
			'SSI-Nutch raw user-agent disclosing ssi.inc'
				=> array( 'SSI-Nutch raw UA', $ssi_raw, self::ELSEWHERE, 'unverifiable' ),
			'SSI-Nutch name without its disclosure'
				=> array( 'SSI-Nutch, no disclosure', 'SSI-Nutch/2.0 (no disclosure)', self::ELSEWHERE, 'unclaimed' ),

			// Controls: range-verified operators, a recognised operator with no method, and traffic
			// that claims nothing. These catch a change that breaks everything rather than one name.
			'GPTBot outside OpenAI ranges'
				=> array( 'GPTBot control', 'GPTBot', self::ELSEWHERE, 'failed' ),
			'ClaudeBot outside Anthropic ranges'
				=> array( 'ClaudeBot control', 'ClaudeBot', self::ELSEWHERE, 'failed' ),
			'CCBot inside Common Crawl\'s published v4 range'
				=> array( 'CCBot in range', 'CCBot', self::CCBOT_IP, 'verified' ),
			'CCBot inside the published v6 range, where rDNS does not exist'
				=> array( 'CCBot in v6 range', 'CCBot', self::CCBOT_IP6, 'verified' ),
			'CCBot from outside the range, with no reverse record'
				=> array( 'CCBot control', 'CCBot', self::ELSEWHERE, 'failed' ),
			'CCBot raw user-agent, in range'
				=> array( 'CCBot raw UA', 'CCBot/2.0 (https://commoncrawl.org/faq/)', self::CCBOT_IP, 'verified' ),
			'an ordinary browser claims nothing'
				=> array( 'browser control', 'Mozilla/5.0 (Macintosh) Chrome/140', self::ELSEWHERE, 'unclaimed' ),

			// Claude-User, which arrives three ways (1.47.0). A server-side fetch from claude.ai
			// comes from Anthropic's published range and verifies.
			'Claude-User from Anthropic\'s published range'
				=> array( 'Claude-User server-side', 'Claude-User', self::ANTHROPIC_IP, 'verified' ),

			// A bare Claude-User claim from an address Anthropic does not publish is still a
			// forgery. This is the Slackbot-attributed shape, and it must keep failing: the client
			// verdict is reached by the token, never by the address being residential.
			'bare Claude-User off Anthropic\'s range'
				=> array( 'Claude-User forged', 'Claude-User', self::HOME_IP, 'failed' ),

			// Claude Code fetches from the person's own machine and still says Claude-User, so no
			// range check can ever pass it. It announces itself with a claude-code/ token.
			'Claude Code stored label'
				=> array( 'Claude Code label', 'Claude-User (claude-code)', self::HOME_NET, 'client' ),
			'Claude Code stored label at a full address'
				=> array( 'Claude Code label, full address', 'Claude-User (claude-code)', self::HOME_IP, 'client' ),
			'Claude Code raw user-agent'
				=> array( 'Claude Code raw UA', self::CLAUDE_CODE_UA, self::HOME_IP, 'client' ),
			'Claude Code under the Agent SDK'
				=> array( 'Claude Code SDK UA', self::CLAUDE_SDK_UA, self::HOME_IP, 'client' ),

			// The token only counts beside the name it belongs to. Anywhere else it is decoration.
			'claude-code token on a GPTBot claim'
				=> array( 'token on another name', 'GPTBot claude-code/1.0', self::ELSEWHERE, 'failed' ),
			'claude-code token with no crawler named'
				=> array( 'token alone', 'python-requests/2.32 claude-code/1.0', self::ELSEWHERE, 'unclaimed' ),
		);
	}

	/**
	 * Claude Code's user-agent is stored under a label that keeps the token, and a server-side
	 * Claude-User is not.
	 *
	 * The token is the whole signal, and until 1.47.0 label_for() discarded it: every Claude-User
	 * request was written as the bare name, so a verdict derived on read could never see it. This
	 * pins the write side, which is the half that cannot be fixed backwards.
	 */
	public function test_claude_code_keeps_its_token_in_the_stored_label(): void {
		$this->assertSame( 'Claude-User (claude-code)', MMSAR_Agent_Log::label_for( self::CLAUDE_CODE_UA ) );
		$this->assertSame( 'Claude-User (claude-code)', MMSAR_Agent_Log::label_for( self::CLAUDE_SDK_UA ) );
		$this->assertSame(
			'Claude-User',
			MMSAR_Agent_Log::label_for( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)' ),
			'A server-side Claude-User carries no client token and keeps the bare name'
		);
	}

	/**
	 * A user-run client is still categorised as the assistant it is, and is never offered for
	 * re-check, because no method will ever settle it.
	 */
	public function test_user_run_client_is_categorised_and_not_rechecked(): void {
		$this->assertSame( MMSAR_Agent_Log::CRAWLER_AI_ASSISTANT, MMSAR_Agent_Log::crawler_category( 'Claude-User (claude-code)' ) );
		$this->assertSame( 'Claude-User', MMSAR_Agent_Log_Verify::claimed_name( 'Claude-User (claude-code)' ) );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'Claude-User (claude-code)' ) );
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'Claude-User' ), 'The server-side name still has its range' );
	}

	/**
	 * Only a user-run client's address is reduced; a server-side claim keeps the full one it is
	 * verified against.
	 */
	public function test_user_run_client_detection(): void {
		$this->assertTrue( MMSAR_Agent_Log::is_user_run_client( 'Claude-User (claude-code)' ) );
		$this->assertTrue( MMSAR_Agent_Log::is_user_run_client( self::CLAUDE_CODE_UA ) );
		$this->assertFalse( MMSAR_Agent_Log::is_user_run_client( 'Claude-User' ) );
		$this->assertFalse( MMSAR_Agent_Log::is_user_run_client( 'GPTBot claude-code/1.0' ) );
		$this->assertFalse( MMSAR_Agent_Log::is_user_run_client( 'python-requests/2.32 claude-code/1.0' ) );
	}

	/**
	 * The label and the raw user-agent must resolve to the same operator.
	 *
	 * This is the invariant 1.37.1 violated, stated directly rather than only implied by the table
	 * above: whatever `claimed_operator_key()` does, it must not treat the two stored forms of one
	 * crawler as different callers.
	 */
	public function test_label_and_raw_user_agent_agree(): void {
		$pairs = array(
			'LinkupBot' => 'LinkupBot/1.0 (LinkupBot for web indexing; https://linkup.so/bot; bot@linkup.so)',
			'SSI-Nutch' => 'SSI-Nutch/1.23 (SSI broad web crawler; https://ssi.inc/; adi@ssi.inc)',
			'GPTBot'    => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
		);

		foreach ( $pairs as $label => $raw ) {
			wp_stub_reset();
			add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
			add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );

			$this->assertSame(
				MMSAR_Agent_Log_Verify::verdict_for( $label, self::ELSEWHERE ),
				MMSAR_Agent_Log_Verify::verdict_for( $raw, self::ELSEWHERE ),
				sprintf( 'The stored label and the raw user-agent disagree for %s', $label )
			);
		}
	}

	/**
	 * A recognised crawler with no published method is never offered for re-check.
	 *
	 * Offering it would put a count on screen that no number of presses could clear, because the
	 * verdict cannot change until this plugin learns a method.
	 */
	public function test_has_method_is_false_without_a_published_method(): void {
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'LinkupBot' ), 'LinkupBot has a range file' );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'SSI-Nutch' ), 'SSI publishes no method' );
		$this->assertTrue( MMSAR_Agent_Log_Verify::has_method( 'CCBot' ), 'Common Crawl publishes both' );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'Mozilla/5.0 Chrome/140' ), 'claims nothing' );
	}

	/**
	 * A crawler that publishes both methods is still verified when the bundled range goes stale.
	 *
	 * The standing cost of bundling range data rather than fetching it is that a prefix added after
	 * capture reads as `failed` — a real operator accused of forging itself. For an operator that
	 * documents rDNS as well, the second method is what removes that cost, and this asserts the
	 * order that makes it work: a range miss falls through to the reverse lookup instead of
	 * deciding. CCBot is the case, because Common Crawl publishes both and its v4 ranges are small
	 * enough that a change is likely rather than hypothetical.
	 */
	public function test_both_method_crawler_survives_a_stale_range(): void {
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '18-97-99-99.crawl.commoncrawl.org' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array( self::ELSEWHERE ) );

		$this->assertSame(
			'verified',
			MMSAR_Agent_Log_Verify::verdict_for( 'CCBot', self::ELSEWHERE ),
			'A forward-confirmed crawl.commoncrawl.org host verifies an address the bundled range misses'
		);
	}

	/**
	 * Forward confirmation is still required, so the suffix alone proves nothing.
	 *
	 * Without this, anyone able to set a PTR record on their own address could claim CCBot by
	 * naming it `something.crawl.commoncrawl.org`.
	 */
	public function test_unconfirmed_commoncrawl_host_is_failed(): void {
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '18-97-99-99.crawl.commoncrawl.org' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array( '198.51.100.9' ) );

		$this->assertSame(
			'failed',
			MMSAR_Agent_Log_Verify::verdict_for( 'CCBot', self::ELSEWHERE ),
			'A PTR that does not forward-confirm is a forgery, not a verification'
		);
	}

	/**
	 * An address that is its own network is never judged, whichever family it belongs to.
	 */
	public function test_reduced_addresses_are_never_judged(): void {
		foreach ( array( '35.198.113.0', '10.0.0.0', '2001:db8:1:2::' ) as $network ) {
			$this->assertSame(
				'unverifiable',
				MMSAR_Agent_Log_Verify::verdict_for( 'LinkupBot', $network ),
				sprintf( '%s is a network, not evidence', $network )
			);
		}
	}
}
