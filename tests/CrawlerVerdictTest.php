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
			'CCBot is recognised with no verification method'
				=> array( 'CCBot control', 'CCBot', self::ELSEWHERE, 'unverifiable' ),
			'an ordinary browser claims nothing'
				=> array( 'browser control', 'Mozilla/5.0 (Macintosh) Chrome/140', self::ELSEWHERE, 'unclaimed' ),
		);
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
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'CCBot' ), 'CCBot has no method' );
		$this->assertFalse( MMSAR_Agent_Log_Verify::has_method( 'Mozilla/5.0 Chrome/140' ), 'claims nothing' );
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
