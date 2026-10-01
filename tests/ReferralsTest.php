<?php
/**
 * Which assistant sent a visit.
 *
 * A false match counts an ordinary visit as AI traffic, which is the number this exists to report,
 * so the near-misses are pinned as carefully as the matches.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Referrals::classify() and the script's configuration.
 */
final class ReferralsTest extends TestCase {

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * @param string $host     Referrer host.
	 * @param string $utm      utm_source.
	 * @param string $expected Assistant key, or ''.
	 */
	#[DataProvider( 'visits' )]
	public function test_classify( string $host, string $utm, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Referrals::classify( $host, $utm ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function visits(): array {
		return array(
			'nothing'                        => array( '', '', '' ),
			'chatgpt referrer'               => array( 'chatgpt.com', '', 'chatgpt' ),
			'chatgpt utm only'               => array( '', 'chatgpt.com', 'chatgpt' ),
			'old openai host'                => array( 'chat.openai.com', '', 'chatgpt' ),
			'perplexity www'                 => array( 'www.perplexity.ai', '', 'perplexity' ),
			'claude'                         => array( 'claude.ai', '', 'claude' ),
			'gemini'                         => array( 'gemini.google.com', '', 'gemini' ),
			'copilot'                        => array( 'copilot.microsoft.com', '', 'copilot' ),
			'uppercase host'                 => array( 'ChatGPT.com', '', 'chatgpt' ),
			'uppercase utm'                  => array( '', 'Perplexity', 'perplexity' ),
			'referrer wins over utm'         => array( 'claude.ai', 'chatgpt.com', 'claude' ),
			'unknown referrer, known utm'    => array( 'example.org', 'chatgpt.com', 'chatgpt' ),
			'lookalike suffix'               => array( 'notperplexity.ai', '', '' ),
			'lookalike prefix'               => array( 'chatgpt.com.evil.example', '', '' ),
			'plain google search'            => array( 'www.google.com', '', '' ),
			'plain bing search'              => array( 'www.bing.com', '', '' ),
			'other google product'           => array( 'mail.google.com', '', '' ),
			'newsletter utm'                 => array( '', 'newsletter', '' ),
			'utm partial word'               => array( '', 'chatgpt-clone', '' ),
			'whitespace around'              => array( ' claude.ai ', '', 'claude' ),
		);
	}

	public function test_every_assistant_has_a_label_and_something_to_match(): void {
		foreach ( MMSAR_Referrals::ASSISTANTS as $key => $assistant ) {
			$this->assertNotSame( '', $assistant['label'], $key );
			$this->assertNotEmpty( $assistant['hosts'], $key );
			foreach ( $assistant['hosts'] as $host ) {
				$this->assertSame( strtolower( $host ), $host, $key . ' hosts are matched lowercase' );
				$this->assertSame( $key, MMSAR_Referrals::classify( $host, '' ), $host );
			}
			foreach ( $assistant['utm'] as $utm ) {
				$this->assertSame( strtolower( $utm ), $utm, $key . ' utm values are matched lowercase' );
			}
		}
	}

	public function test_the_script_carries_the_same_lists_as_the_server(): void {
		$script = MMSAR_Referrals::script( 'https://example.com/wp-json/mmsar/v1/referral' );
		$this->assertStringContainsString( '"https:\/\/example.com\/wp-json\/mmsar\/v1\/referral"', $script );
		foreach ( MMSAR_Referrals::ASSISTANTS as $assistant ) {
			foreach ( array_merge( $assistant['hosts'], $assistant['utm'] ) as $value ) {
				$this->assertStringContainsString( '"' . $value . '"', $script );
			}
		}
		$this->assertStringNotContainsString( '</script', $script );
	}
}
