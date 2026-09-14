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
	 * @dataProvider trimmedAgents
	 *
	 * The stored label for an unrecognised caller must keep the part that tells two callers apart.
	 * Truncating from the left kept only what every browser of a family sends identically: on the
	 * site this was found on, one 80-character label covered 725 requests from 339 addresses.
	 *
	 * @param string $ua       Raw user-agent.
	 * @param string $expected Expected stored label.
	 */
	public function test_trimmed_user_agent( string $ua, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log::trimmed_user_agent( $ua ), sprintf( 'Wrong stored label for %s', $ua ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function trimmedAgents(): array {
		$chrome  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
		$firefox = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/119.0';
		return array(
			// The regression, and the reason for the change: the Chrome version survives the cut.
			'Chrome keeps its version and platform' => array( $chrome, '(Macintosh; Intel Mac OS X 10_15_7) Chrome/141.0.0.0 Safari/537.36' ),
			'Firefox loses only the Mozilla token'  => array( $firefox, '(Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/119.0' ),
			// A bot name inside a comment is never touched, so a name added to AGENTS later still
			// matches rows stored before it was recognised.
			'bot name in a comment survives'        => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0', 'compatible; ShapBot/0.1.0' ),
			'self-identifying bot is untouched'     => array( 'Mozilla/5.0 (compatible; SomeBot/1.0; +https://example.com/bot)', '(compatible; SomeBot/1.0; +https://example.com/bot)' ),
			'a bare product token is left alone'    => array( 'FeedBurner/1.0 (http://www.FeedBurner.com)', 'FeedBurner/1.0 (http://www.FeedBurner.com)' ),
			// Nothing but boilerplate: keep the original rather than storing an empty string and
			// losing the row's only evidence.
			'all-boilerplate falls back to raw'     => array( 'Mozilla/5.0', 'Mozilla/5.0' ),
			'empty stays empty'                     => array( '', '' ),
			// Still capped at 80 after trimming — the cap did not move.
			'still capped at 80 characters'         => array( 'Mozilla/5.0 (' . str_repeat( 'x', 200 ) . ')', '(' . str_repeat( 'x', 79 ) ),
		);
	}

	/**
	 * @dataProvider attributorNames
	 *
	 * An attribution names who was behind a forged crawler identity, so a name that identifies
	 * nobody is worse than no name at all. The live log carried rows reading "spoofed by Mozilla":
	 * the old parser took the first token before a slash, which is right for a bare `OraBot/1.0`
	 * and wrong for `Mozilla/5.0 (compatible; SomeBot/1.0; +https://example.com/bot)` — the shape
	 * most crawlers actually use.
	 *
	 * @param string $agent    Raw user-agent.
	 * @param string $expected Expected display name, '' for no usable name.
	 */
	public function test_attributor_short_name( string $agent, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Attribution::short_name( $agent ), sprintf( 'Wrong attributor name for %s', $agent ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function attributorNames(): array {
		return array(
			'bare product token'              => array( 'OraBot/1.0 (+https://ora.ai/bot)', 'OraBot' ),
			'name inside compatible comment'  => array( 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'SemrushBot' ),
			'canonical label on its own'      => array( 'CCBot', 'CCBot' ),
			'product token beats the url'     => array( 'LinkupBot/1.0 (LinkupBot for web indexing; https://linkup.so/bot; bot@linkup.so)', 'LinkupBot' ),
			// No automation token — "Scanner" is not "scraper" — so this qualified as a bot through
			// its self-identification URL, and the host is what names the operator.
			'falls back to the declared host' => array( 'Mozilla/5.0 (compatible; AgentReadinessScanner/1.0; +https://isitagentready.com)', 'isitagentready.com' ),
			'www is stripped from the host'   => array( 'Mozilla/5.0 (compatible; Somebody/1.0; +https://www.example.com/x)', 'example.com' ),
			// The regression. Every token here is browser boilerplate; the old parser answered
			// "Mozilla" and a laxer fallback answered "Intel", out of "(Macintosh; Intel Mac OS X)".
			'plain browser names nobody'      => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36', '' ),
			'empty user-agent'                => array( '', '' ),
		);
	}

	/**
	 * Anything with a verification method is also recognised for page views.
	 *
	 * These are two separate lists doing two jobs, and the second one is easy to forget: AGENTS is
	 * what decides whether a caller's address is *kept*, and a reduced address can never be checked
	 * against a published range afterwards. So a name that has a method but is missing from AGENTS
	 * produces rows that are permanently unverifiable while looking, from the code, fully supported.
	 *
	 * That is not hypothetical — Googlebot, bingbot and Applebot each had a reverse-DNS suffix and a
	 * category from the day they were added and sat outside AGENTS until 1.43.0, so every page view
	 * from the three largest search crawlers on the web was stored at network precision, and under
	 * the `agents` page-view mode was not stored at all.
	 */
	public function test_every_verifiable_name_is_recognised_for_page_views(): void {
		$methods = array_unique(
			array_merge(
				array_keys( MMSAR_Agent_Log_Verify::VERIFY_HOSTS ),
				array_keys( MMSAR_Agent_Log_Verify::VERIFY_RANGES )
			)
		);
		foreach ( $methods as $name ) {
			$this->assertContains(
				$name,
				MMSAR_Agent_Log::AGENTS,
				sprintf( '%s can be verified but is not in AGENTS, so its page views are stored at network precision and can never be verified', $name )
			);
		}
	}

	/**
	 * A name that is a prefix of another must not be listed before it.
	 *
	 * agent_label() and is_known_agent() both return the first match walking AGENTS in order, and
	 * matching is a case-insensitive substring test. So listing `Applebot` above `Applebot-Extended`
	 * would relabel every Applebot-Extended row as plain Applebot — silently, and in the stored
	 * column rather than only on screen, which makes it unrecoverable.
	 *
	 * Appending new names is what keeps this safe, and this test is here so the next person adding
	 * one does not have to know that.
	 */
	public function test_shorter_names_never_shadow_longer_ones(): void {
		$agents = MMSAR_Agent_Log::AGENTS;
		foreach ( $agents as $i => $short ) {
			foreach ( $agents as $j => $long ) {
				if ( $i === $j || false === stripos( $long, $short ) ) {
					continue;
				}
				$this->assertGreaterThan(
					$j,
					$i,
					sprintf( '%s is listed before %s and would match it first, relabelling every %s row', $short, $long, $long )
				);
			}
		}
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
			'FeedBurner, bare label'                   => array( 'FeedBurner', MMSAR_Agent_Log::CRAWLER_OTHER ),
			'FeedBurner, raw user-agent'               => array( 'FeedBurner/1.0 (http://www.FeedBurner.com)', MMSAR_Agent_Log::CRAWLER_OTHER ),
			'Feedbin, bare label'                      => array( 'Feedbin', MMSAR_Agent_Log::CRAWLER_OTHER ),
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
			// Was 'PetalBot is recognise-only for now', asserting `unverifiable` for any address.
			// 1.44.0 gave PetalBot a method, so an address with no PTR is now `nodns` — retryable,
			// which is the honest answer for a resolver that returned nothing. The shape is covered
			// by 'PetalBot, no reverse record' below; this line is kept as the record of why the
			// expectation changed rather than deleted silently.
			'PetalBot, outside any suffix'             => array( 'PetalBot', self::ELSEWHERE, 'host.attacker.example', array( self::ELSEWHERE ), 'failed' ),
			'ExaSearchBot is recognise-only for now'   => array( 'ExaSearchBot', self::ELSEWHERE, '', array(), 'unverifiable' ),
			'SemrushBot publishes no method'           => array( 'SemrushBot', self::ELSEWHERE, '', array(), 'unverifiable' ),

			// PetalBot and YouBot, added 1.44.0. Both confirmed against the operator's own
			// documentation and a real full address from the live log.
			//
			// PetalBot is rDNS-only — Huawei publishes no ranges — and carries two documented
			// suffixes. The live address arrived under the newer one, which is why both are tested:
			// dropping `aspiegel.com` would look harmless right up until a caller Huawei still
			// vouches for is accused of forgery.
			'PetalBot, forward-confirmed petalsearch'  => array( 'PetalBot', '114.119.144.17', 'petalbot-114-119-144-17.petalsearch.com', array( '114.119.144.17' ), 'verified' ),
			'PetalBot, legacy aspiegel.com suffix'     => array( 'PetalBot', '114.119.144.17', 'petalbot-114-119-144-17.aspiegel.com', array( '114.119.144.17' ), 'verified' ),
			'PetalBot, lookalike host'                 => array( 'PetalBot', '114.119.144.17', 'petalsearch.com.attacker.example', array( '114.119.144.17' ), 'failed' ),
			'PetalBot, reverse without forward'        => array( 'PetalBot', '114.119.144.17', 'petalbot-114-119-144-17.petalsearch.com', array( '203.0.113.9' ), 'failed' ),
			'PetalBot, no reverse record'              => array( 'PetalBot', '114.119.144.17', '', array(), 'nodns' ),

			// YouBot has both methods, so the range decides first and rDNS is the fallback. The
			// forged case is real traffic: 34.139.213.96 claimed YouBot *and* cohere-ai in the same
			// window from Google Cloud, and read `unverifiable` until this release.
			'YouBot, inside the published /24'         => array( 'YouBot', '68.67.112.111', '', array(), 'verified' ),
			'YouBot, in range and rDNS agrees'         => array( 'YouBot', '68.67.112.111', 'youbot-68-67-112-111.search.you.com', array( '68.67.112.111' ), 'verified' ),
			'YouBot, forged from Google Cloud'         => array( 'YouBot', '34.139.213.96', '96.213.139.34.bc.googleusercontent.com', array( '34.139.213.96' ), 'failed' ),
			'YouBot, outside /24 with no rDNS'         => array( 'YouBot', self::ELSEWHERE, '', array(), 'failed' ),
			'YouBot, lookalike host outside range'     => array( 'YouBot', self::ELSEWHERE, 'search.you.com.attacker.example', array( self::ELSEWHERE ), 'failed' ),

			// Confirmed negatives, kept as assertions so a future "why is this unverifiable?"
			// does not get re-researched from scratch. Majestic states it cannot restrict MJ12bot
			// to fixed addresses and offers a pre-arranged CRAWLER-IDENT header instead; Semrush
			// states it uses no consecutive IP blocks; Meta documents no verification at all; and
			// ByteDance publishes nothing official, so the crawl.bytedance.com convention is
			// community-reported only. In every case a live PTR exists for *some* addresses, which
			// is exactly the trap: an undocumented convention turns a genuine caller without it
			// into a forgery accusation.
			'MJ12bot is distributed by design'         => array( 'MJ12bot', '46.105.38.210', 'crawl-oiddkd.mj12bot.com', array( '46.105.38.210' ), 'unverifiable' ),
			'meta-externalagent has no method'         => array( 'meta-externalagent', '34.143.175.34', '34.175.143.34.bc.googleusercontent.com', array( '34.143.175.34' ), 'unverifiable' ),
			'Bytespider has no official method'        => array( 'Bytespider', '47.128.114.111', 'ec2-47-128-114-111.ap-southeast-1.compute.amazonaws.com', array( '47.128.114.111' ), 'unverifiable' ),

			// Recognise-only, added 1.45.0. Both are named so their addresses stop being reduced;
			// neither operator documents a method for the token it sends, so both must stay
			// `unverifiable` rather than being judged against a convention nobody promised.
			//
			// FeedBurner is the one worth asserting hardest. Its traffic really does arrive from
			// Google's documented user-triggered-fetcher range and really does resolve under
			// google.com — so the tempting shortcut is to reuse the Google suffixes already in
			// VERIFY_HOSTS. That would verify this row and, the first time Google routes FeedBurner
			// anywhere else, call a genuine Google fetcher a forgery.
			'FeedBurner stays recognise-only'          => array( 'FeedBurner', '66.249.83.10', 'google-proxy-66-249-83-10.google.com', array( '66.249.83.10' ), 'unverifiable' ),
			'FeedBurner raw user-agent, same verdict'  => array( 'FeedBurner/1.0 (http://www.FeedBurner.com)', '66.249.83.10', 'google-proxy-66-249-83-10.google.com', array( '66.249.83.10' ), 'unverifiable' ),
			'Feedbin stays recognise-only'             => array( 'Feedbin', '64.71.157.20', '', array(), 'unverifiable' ),
		);
	}
}
