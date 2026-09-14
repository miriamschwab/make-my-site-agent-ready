<?php
/**
 * Crawler categories, and verdicts for the crawlers added alongside them.
 *
 * A category is derived on read from the stored `agent` value, through the same matching that
 * decides the verdict. So the rule CrawlerVerdictTest enforces for verdicts holds here too: the
 * bare canonical name and a raw user-agent for the same crawler must come out the same.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * Crawler categories and the 2026-09-14 verification entries.
 */
final class CrawlerCategoryTest extends TestCase {

	/** An address belonging to nobody in particular. */
	private const ELSEWHERE = '203.0.113.7';

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => '' );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => array() );
	}

	/**
	 * Every recognised name carries a category, and every category is a known one.
	 *
	 * A name added to AGENTS without a tag would render untagged and vanish from every category
	 * filter, reading as unrecognised when it plainly is not.
	 */
	public function test_every_recognised_name_has_a_valid_category(): void {
		foreach ( MMSAR_Agent_Log::AGENTS as $name ) {
			$this->assertArrayHasKey( $name, MMSAR_Agent_Log::CRAWLER_CATEGORIES, sprintf( '%s has no category', $name ) );
		}
		foreach ( MMSAR_Agent_Log::CRAWLER_CATEGORIES as $name => $category ) {
			$this->assertContains( $category, MMSAR_Agent_Log::crawler_categories(), sprintf( '%s has an unknown category', $name ) );
		}
	}

	/**
	 * @dataProvider categoryShapes
	 *
	 * @param string $agent    Stored agent value.
	 * @param string $expected Expected category.
	 */
	public function test_category_for_stored_value( string $agent, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::crawler_category( $agent ), sprintf( 'Wrong category for %s', $agent ) );
	}

	/**
	 * Both stored shapes, for AI and non-AI crawlers, plus the disclosure guard.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function categoryShapes(): array {
		return array(
			'label, AI training'         => array( 'ClaudeBot', 'ai-training' ),
			'label, AI assistant'        => array( 'ChatGPT-User', 'ai-assistant' ),
			'label, SEO tool'            => array( 'AhrefsBot', 'seo-tool' ),
			'raw UA logged before recognition' => array( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'seo-tool' ),
			'raw UA, search engine'      => array( 'Mozilla/5.0 (compatible; SeznamBot/4.0; +https://o-seznam.cz/napoveda/vyhledavani/en/seznambot-crawler/)', 'search-engine' ),
			'DuckDuckBot is not DuckAssistBot' => array( 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'search-engine' ),
			'DuckAssistBot stays AI search' => array( 'DuckAssistBot', 'ai-search' ),
			'name with a space'          => array( 'Screaming Frog SEO Spider/21.0', 'seo-tool' ),
			'scanner'                    => array( 'OraBot/1.0 (+https://ora.ai/bot)', 'scanner' ),
			'verifier-only name'         => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'search-engine' ),
			'linkup.com impostor has no category' => array( 'LinkUpBot (job aggregator; linkup.com)', '' ),
			'browser has no category'    => array( 'Mozilla/5.0 (Macintosh) Chrome/140', '' ),
			'OWLer is held, not recognised' => array( 'OWLer', '' ),
		);
	}

	/**
	 * @dataProvider newVerdicts
	 *
	 * @param string   $agent    Stored agent value.
	 * @param string   $ip       Stored address.
	 * @param string   $host     What the fake reverse lookup returns.
	 * @param string[] $forward  What the fake forward lookup returns.
	 * @param string   $expected Expected verdict.
	 */
	public function test_new_crawler_verdicts( string $agent, string $ip, string $host, array $forward, string $expected ): void {
		wp_stub_reset();
		add_filter( 'mmsar_agent_log_reverse_lookup', static fn() => $host );
		add_filter( 'mmsar_agent_log_forward_lookup', static fn() => $forward );
		$this->assertSame( $expected, MMSAR_Agent_Log_Verify::verdict_for( $agent, $ip ) );
	}

	/**
	 * One case per method a new entry uses, including the forged side of each.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string[],4:string}>
	 */
	public function newVerdicts(): array {
		$ahrefs = '54.36.148.1';
		return array(
			'AhrefsBot, forward-confirmed ahrefs.net'  => array( 'AhrefsBot', $ahrefs, 'proxy-de002-cip10.ahrefs.net', array( $ahrefs ), 'verified' ),
			'AhrefsBot, lookalike host'                => array( 'AhrefsBot', $ahrefs, 'ahrefs.net.attacker.example', array( $ahrefs ), 'failed' ),
			'AhrefsBot, no reverse record'             => array( 'AhrefsBot', $ahrefs, '', array(), 'nodns' ),
			'Barkrowler, forward-confirmed babbar.eu'  => array( 'Barkrowler', '217.113.194.187', 'c187.babbar.eu', array( '217.113.194.187' ), 'verified' ),
			'DuckDuckBot, listed address'              => array( 'DuckDuckBot', '20.191.45.212', '', array(), 'verified' ),
			'DuckDuckBot, unlisted address'            => array( 'DuckDuckBot', self::ELSEWHERE, '', array(), 'failed' ),
			'SeznamBot, IPv4 in range'                 => array( 'SeznamBot', '77.75.72.26', '', array(), 'verified' ),
			'MojeekBot, inside its /28'                => array( 'MojeekBot', '5.102.173.70', '', array(), 'verified' ),
			'MojeekBot, just outside its /28'          => array( 'MojeekBot', '5.102.173.80', '', array(), 'failed' ),
			'SE Ranking, bare address stored as /32'   => array( 'SERankingBacklinksBot', '37.27.55.74', '', array(), 'verified' ),
			'PetalBot is recognise-only for now'       => array( 'PetalBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'ExaSearchBot is recognise-only for now'   => array( 'ExaSearchBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'SemrushBot publishes no method'           => array( 'SemrushBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
		);
	}
}
