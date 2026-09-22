<?php
/**
 * The Accept-header rule that decides whether an ordinary URL is answered with Markdown.
 *
 * Getting this wrong in one direction serves a person a Markdown file in place of a page, which is
 * the one way this plugin can break a site for a reader. Getting it wrong in the other hands agents
 * HTML — or, on a host whose edge converts HTML, the edge's noisier Markdown. So both directions are
 * asserted, with the headers real clients send.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Accept.
 */
final class AcceptTest extends TestCase {

	/**
	 * @dataProvider markdownHeaders
	 *
	 * @param string $header   Accept header.
	 * @param bool   $expected Whether Markdown is served.
	 */
	public function test_prefers_markdown( string $header, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_Accept::prefers_markdown( $header ), sprintf( 'Wrong answer for Accept: %s', $header ) );
	}

	/**
	 * @return array<string, array{0:string,1:bool}>
	 */
	public function markdownHeaders(): array {
		return array(
			// Browsers. None names Markdown, so none can ever match — this is the guarantee.
			'Chrome navigation'                    => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7', false ),
			'Firefox navigation'                   => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false ),
			'Safari navigation'                    => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false ),
			'fetch() default'                      => array( '*/*', false ),
			'the self-check browser probe'         => array( MMSAR_Negotiation_Check_Headers::BROWSER, false ),
			// A wildcard never counts towards Markdown, at any weight.
			'wildcard alone at full weight'        => array( '*/*;q=1.0', false ),
			'text/* is not Markdown'               => array( 'text/*', false ),
			'empty header'                         => array( '', false ),

			// The 1.46.1 change: a tie goes to Markdown when Markdown is named.
			'tie with HTML and wildcard'           => array( 'text/markdown, text/html, */*', true ),
			'tie with HTML alone'                  => array( 'text/markdown, text/html', true ),
			'tie with wildcard alone'              => array( 'text/markdown, */*', true ),
			'tie regardless of order'              => array( 'text/html, text/markdown', true ),
			'tie at equal lowered weights'         => array( 'text/markdown;q=0.8, text/html;q=0.8', true ),

			// Unchanged from before.
			'Markdown preferred'                   => array( 'text/markdown, text/html;q=0.9, */*;q=0.8', true ),
			'the self-check agent probe'           => array( MMSAR_Negotiation_Check_Headers::MARKDOWN, true ),
			'x-markdown spelling'                  => array( 'text/x-markdown', true ),
			'Markdown alone'                       => array( 'text/markdown', true ),
			'HTML outranks Markdown'               => array( 'text/html, text/markdown;q=0.9', false ),
			'wildcard outranks Markdown'           => array( '*/*, text/markdown;q=0.5', false ),
			'Markdown refused with q=0'            => array( 'text/markdown;q=0, text/html', false ),
			'Markdown at q=0 ties a q=0 wildcard'  => array( 'text/markdown;q=0, */*;q=0', false ),
		);
	}

	/**
	 * JSON keeps the strict rule: a tie still goes to HTML.
	 *
	 * axios's default Accept names JSON and ties it with a wildcard, and plenty of ordinary script
	 * traffic reads that way. Changing what those callers get on a 404 is its own decision.
	 */
	public function test_json_tie_still_goes_to_html(): void {
		$this->assertFalse( MMSAR_Accept::prefers( 'application/json, text/plain, */*', 'application/json' ) );
		$this->assertTrue( MMSAR_Accept::prefers( 'application/json', 'application/json' ) );
		$this->assertTrue( MMSAR_Accept::prefers( 'application/problem+json, */*;q=0.1', 'application/json' ) );
	}

	/**
	 * The mirrored probe headers still match what the self-check actually sends.
	 */
	public function test_probe_header_copies_match_the_source(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mmsar-negotiation-check.php' );
		$this->assertStringContainsString( "const ACCEPT_BROWSER = '" . MMSAR_Negotiation_Check_Headers::BROWSER . "';", $source );
		$this->assertStringContainsString( "const ACCEPT_MARKDOWN = '" . MMSAR_Negotiation_Check_Headers::MARKDOWN . "';", $source );
	}
}

/**
 * The two probe headers MMSAR_Negotiation_Check sends, mirrored here because that class needs a
 * running WordPress to load. A test below asserts the copies still match the source.
 */
final class MMSAR_Negotiation_Check_Headers {
	const BROWSER  = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
	const MARKDOWN = 'text/markdown,text/plain;q=0.9,text/html;q=0.8,*/*;q=0.5';
}
