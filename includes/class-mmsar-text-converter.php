<?php
/**
 * Make My Site Agent-Ready — text-node converter for the Markdown twins.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\HTMLToMarkdown\Converter\TextConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Converts text nodes like the library does, without a literal `&amp;` or a needless `&gt;`.
 *
 * Since 1.74.0 a `>` is written as `>` too, except at the start of a text node (see
 * unescape_inner_gt()).
 *
 * League\HTMLToMarkdown 5.1's TextConverter ends with htmlspecialchars(), puts the result back in
 * the DOM as a text node, and the converter decodes the saved document only once. So every `&`
 * in body text reached the twin as `&amp;` ("Q&amp;A", "Press &amp; Talks"), while `<` and `>`
 * reached it as `&lt;` and `&gt;`.
 *
 * The `&lt;` part is right and is kept: it is what stops text that reads like a tag from
 * becoming live HTML in the Markdown. A bare `&` is literal in
 * CommonMark, so it is written as `&`. One case stays escaped: an `&` followed by something that
 * would read as an entity (text such as "&lt;" or "&copy;" written out on the page), so the twin
 * still shows those characters rather than the symbol they name.
 *
 * Backslash escapes (`\[`, `\*`, `\_`) are added by the parent before this runs, and are untouched.
 */
class MMSAR_Text_Converter extends TextConverter {

	/**
	 * An escaped ampersand that does not start something a Markdown reader would take as an
	 * entity: a name (`&copy;`), a decimal reference (`&#169;`) or a hex one (`&#xA9;`).
	 */
	const BARE_AMP = '/&amp;(?![A-Za-z][A-Za-z0-9]*;|#[0-9]+;|#[xX][0-9A-Fa-f]+;)/';

	/**
	 * Convert a text node.
	 *
	 * @param ElementInterface $element Text node.
	 * @return string Markdown.
	 */
	public function convert( ElementInterface $element ): string {
		return self::unescape_inner_gt( self::unescape_bare_ampersands( parent::convert( $element ) ) );
	}

	/**
	 * Turn `&gt;` back into `>`, except where it is the first visible character of the text node.
	 *
	 * Only `<` can open an HTML tag, and it stays `&lt;`, so a `>` can't make markup ("File > Save"
	 * reads as written). The one place `>` means something in Markdown is the start of a line, where
	 * it opens a blockquote. Text nodes have no line breaks of their own (the parent collapses
	 * whitespace), so the start of a node is the only place one can land at the start of a line,
	 * after a `<br>` or as the first thing in a block. That one stays escaped.
	 *
	 * @param string $markdown Text-node Markdown.
	 * @return string
	 */
	public static function unescape_inner_gt( $markdown ) {
		$head = preg_match( '/^\s*&gt;/', $markdown, $match ) ? $match[0] : '';
		return $head . str_replace( '&gt;', '>', (string) substr( $markdown, strlen( $head ) ) );
	}

	/**
	 * Turn `&amp;` back into `&` wherever the `&` cannot be mistaken for the start of an entity.
	 *
	 * @param string $markdown Text-node Markdown, as htmlspecialchars() left it.
	 * @return string
	 */
	public static function unescape_bare_ampersands( $markdown ) {
		return (string) preg_replace( self::BARE_AMP, '&', $markdown );
	}
}
