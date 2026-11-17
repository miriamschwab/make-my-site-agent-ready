<?php
/**
 * Ampersands and `>` in Markdown twin bodies (1.74.0).
 *
 * The HTML-to-Markdown library escaped text nodes once more than it decoded them, so every `&` in
 * body text reached the twin as `&amp;`, and every `>` as `&gt;`. The fix writes a plain `&`, and a
 * plain `>` except at the start of a text node, where it would open a blockquote. `<` from text
 * stays escaped so text never becomes live HTML, written-out entities stay literal, and backslash
 * escapes, code and link URLs are unchanged.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Converter::html_to_markdown() and MMSAR_Text_Converter.
 */
final class MarkdownEntitiesTest extends TestCase {

	/**
	 * HTML in, exact Markdown out.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		return array(
			'paragraph'                  => array( '<p>Great questions during the Q&amp;A</p>', 'Great questions during the Q&A' ),
			'heading'                    => array( '<h2>Press &amp; Talks</h2>', '## Press & Talks' ),
			'list item'                  => array( '<ul><li>Item &amp; one</li></ul>', '- Item & one' ),
			'inside strong'              => array( '<p><strong>R&amp;D</strong></p>', '**R&D**' ),
			'link text, query string'    => array( '<p><a href="/x?a=1&amp;b=2">Q&amp;A link</a></p>', '[Q&A link](/x?a=1&b=2)' ),
			'div text'                   => array( '<div>Div text &amp; stuff</div>', 'Div text & stuff' ),
			'wptexturize numeric amp'    => array( '<p>AT&amp;T &#038; more</p>', 'AT&T & more' ),
			'text tags stay escaped'     => array( '<p>Use &lt;script&gt; tags &amp; more</p>', 'Use &lt;script> tags & more' ),
			'tag-like text in div'       => array( '<div>&lt;b&gt;bold&lt;/b&gt;</div>', '&lt;b>bold&lt;/b>' ),
			'menu path'                  => array( '<p>Go to Elementor &gt; Elementor MCP</p>', 'Go to Elementor > Elementor MCP' ),
			'menu path, bold'            => array( '<p>Go to <strong>File &gt; Save</strong></p>', 'Go to **File > Save**' ),
			'arrow'                      => array( '<p>Settings -&gt; Security Headers</p>', 'Settings -> Security Headers' ),
			'leading > stays escaped'    => array( '<p>&gt; not a quote</p>', '&gt; not a quote' ),
			'leading > after spaces'     => array( '<p>  &gt; not a quote &gt; still text</p>', '&gt; not a quote > still text' ),
			'leading > after br'         => array( '<p>Line one<br>&gt; not a quote</p>', "Line one  \n&gt; not a quote" ),
			'leading > after nbsp'       => array( '<p>&nbsp;&gt; not a quote</p>', '&gt; not a quote' ),
			'leading > in list item'     => array( '<ul><li>&gt; not a quote</li></ul>', '- &gt; not a quote' ),
			'written-out gt'             => array( '<p>Type &amp;gt; here</p>', 'Type &amp;gt; here' ),
			'written-out entity'         => array( '<p>Type &amp;lt; or &amp;copy; or &amp;#169; or &amp;#xA9;</p>', 'Type &amp;lt; or &amp;copy; or &amp;#169; or &amp;#xA9;' ),
			'amp before a word, no semi' => array( '<p>Tom &amp;Jerry; and &amp;nbsp</p>', 'Tom &amp;Jerry; and &nbsp' ),
			'backslash escapes kept'     => array( '<p>[brackets] *star* &amp; _under_</p>', '\\[brackets\\] \\*star\\* & \\_under\\_' ),
			'inline code'                => array( '<p><code>a &amp;&amp; b &lt;x&gt;</code></p>', '`a && b <x>`' ),
			'fenced code'                => array( '<pre><code>if (a &amp;&amp; b) { x &lt; 1 }</code></pre>', "```\nif (a && b) { x < 1 }\n```" ),
			'image alt'                  => array( '<p><img src="a.png" alt="Cats &amp; dogs"></p>', '![Cats & dogs](a.png)' ),
		);
	}

	/**
	 * Each case converts exactly.
	 *
	 * @param string $html     Input.
	 * @param string $expected Markdown, without the trailing newline.
	 */
	#[DataProvider( 'cases' )]
	public function test_converts( string $html, string $expected ): void {
		$this->assertSame( $expected . "\n", MMSAR_Converter::html_to_markdown( $html ) );
	}

	/**
	 * No raw `<` from body text survives, and no line opens with a raw `>`.
	 */
	public function test_text_never_becomes_html(): void {
		$md = MMSAR_Converter::html_to_markdown( '<p>&amp;lt;script&amp;gt; &lt;img src=x onerror=alert(1)&gt; &amp;&lt;b&gt;</p><p>&gt; quoted?</p><p>a<br>&gt;b</p>' );
		$this->assertStringNotContainsString( '<', $md );
		// No line may open with a raw `>`, which would turn text into a blockquote.
		$this->assertDoesNotMatchRegularExpression( '/^\s*>/m', $md );
	}

	/**
	 * The helpers on their own: only bare escaped ampersands, and `&gt;` past the node's start, change.
	 */
	public function test_helper(): void {
		$this->assertSame( 'Q&A &lt; &amp;lt; &amp;#38; &amp;#x26; &', MMSAR_Text_Converter::unescape_bare_ampersands( 'Q&amp;A &lt; &amp;lt; &amp;#38; &amp;#x26; &amp;' ) );
		$this->assertSame( ' &gt;a > b >> c', MMSAR_Text_Converter::unescape_inner_gt( ' &gt;a &gt; b &gt;&gt; c' ) );
		$this->assertSame( 'a > b', MMSAR_Text_Converter::unescape_inner_gt( 'a &gt; b' ) );
		$this->assertSame( '', MMSAR_Text_Converter::unescape_inner_gt( '' ) );
	}
}
