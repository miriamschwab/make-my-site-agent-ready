<?php
/**
 * The fields a theme or plugin adds to a markdown twin's frontmatter through `mmsar_frontmatter`.
 *
 * The frontmatter is the one part of every twin that readers parse rather than read, so a bad field
 * must cost only itself: a colliding key, a malformed key, a nested value or a value carrying a
 * line break is dropped or flattened, never written in a shape that ends the block early or
 * shadows a core key.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Converter::extra_frontmatter_lines().
 */
final class FrontmatterFilterTest extends TestCase {

	/**
	 * @dataProvider fieldShapes
	 *
	 * @param mixed    $fields   What the filter returned.
	 * @param string[] $expected YAML lines.
	 */
	public function test_renders_fields( $fields, array $expected ): void {
		$this->assertSame( $expected, MMSAR_Converter::extra_frontmatter_lines( $fields ) );
	}

	/**
	 * @return array<string, array{0:mixed,1:string[]}>
	 */
	public function fieldShapes(): array {
		return array(
			'nothing'                      => array( array(), array() ),
			'not an array'                 => array( 'series: x', array() ),
			'null'                         => array( null, array() ),
			'string'                       => array( array( 'series' => 'Agent-ready' ), array( 'series: "Agent-ready"' ) ),
			'integer'                      => array( array( 'reading_time' => 7 ), array( 'reading_time: 7' ) ),
			'float'                        => array( array( 'rating' => 4.5 ), array( 'rating: 4.5' ) ),
			'whole float stays a float'    => array( array( 'rating' => 4.0 ), array( 'rating: 4.0' ) ),
			'true'                         => array( array( 'featured' => true ), array( 'featured: true' ) ),
			'false'                        => array( array( 'featured' => false ), array( 'featured: false' ) ),
			'list'                         => array(
				array( 'topics' => array( 'WordPress', 'MCP' ) ),
				array( 'topics:', '  - "WordPress"', '  - "MCP"' ),
			),
			'numbers in a list are quoted' => array( array( 'years' => array( 2025, 2026 ) ), array( 'years:', '  - "2025"', '  - "2026"' ) ),
			'order is kept'                => array(
				array(
					'b_key' => 'two',
					'a_key' => 'one',
				),
				array( 'b_key: "two"', 'a_key: "one"' ),
			),
		);
	}

	/**
	 * @dataProvider rejectedFields
	 *
	 * @param array $fields What the filter returned.
	 */
	public function test_skips_field( array $fields ): void {
		$this->assertSame( array( 'kept: "yes"' ), MMSAR_Converter::extra_frontmatter_lines( $fields + array( 'kept' => 'yes' ) ) );
	}

	/**
	 * Every field here is dropped; the valid `kept` field beside it must survive each time.
	 *
	 * @return array<string, array{0:array}>
	 */
	public function rejectedFields(): array {
		$cases = array();
		foreach ( MMSAR_Converter::CORE_FRONTMATTER_KEYS as $core ) {
			$cases[ 'core key ' . $core ] = array( array( $core => 'override' ) );
		}
		return $cases + array(
			'uppercase key'            => array( array( 'Series' => 'x' ) ),
			'uppercase core key'       => array( array( 'Title' => 'x' ) ),
			'leading digit'            => array( array( '1st' => 'x' ) ),
			'leading underscore'       => array( array( '_private' => 'x' ) ),
			'hyphen'                   => array( array( 'reading-time' => 'x' ) ),
			'colon'                    => array( array( 'a:b' => 'x' ) ),
			'space'                    => array( array( 'a b' => 'x' ) ),
			'newline in key'           => array( array( "a\nb" => 'x' ) ),
			'trailing newline in key'  => array( array( "series\n" => 'x' ) ),
			'empty key'                => array( array( '' => 'x' ) ),
			'integer key'              => array( array( 5 => 'x' ) ),
			'empty string'             => array( array( 'series' => '' ) ),
			'whitespace only'          => array( array( 'series' => "  \n\t " ) ),
			'null value'               => array( array( 'series' => null ) ),
			'object value'             => array( array( 'series' => new stdClass() ) ),
			'infinity'                 => array( array( 'rating' => INF ) ),
			'not a number'             => array( array( 'rating' => NAN ) ),
			'empty list'               => array( array( 'topics' => array() ) ),
			'list of empty strings'    => array( array( 'topics' => array( '', ' ' ) ) ),
			'associative array'        => array( array( 'topics' => array( 'a' => 'b' ) ) ),
			'list with a gap'          => array( array( 'topics' => array( 0 => 'a', 2 => 'b' ) ) ),
			'nested list'              => array( array( 'topics' => array( 'a', array( 'b' ) ) ) ),
			'bool in a list'           => array( array( 'topics' => array( 'a', true ) ) ),
			'null in a list'           => array( array( 'topics' => array( 'a', null ) ) ),
			'invalid UTF-8'            => array( array( 'series' => "bad \xC3\x28 byte" ) ),
		);
	}

	/**
	 * A value may contain anything that can be written on one quoted line. What must not survive
	 * is a line break, because `\n---\n` inside a value would close the frontmatter for any reader
	 * that splits on delimiter lines.
	 *
	 * @dataProvider escapedStrings
	 *
	 * @param string $value    Raw value.
	 * @param string $expected Rendered line.
	 */
	public function test_escapes_string( string $value, string $expected ): void {
		$this->assertSame( array( $expected ), MMSAR_Converter::extra_frontmatter_lines( array( 'series' => $value ) ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function escapedStrings(): array {
		return array(
			'double quote'             => array( 'say "hi"', 'series: "say \"hi\""' ),
			'backslash'                => array( 'C:\path', 'series: "C:\\\\path"' ),
			'entity decoded'           => array( 'Q&amp;A &#8211; notes', 'series: "Q&A – notes"' ),
			'delimiter injection'      => array( "x\n---\ntitle: forged", 'series: "x --- title: forged"' ),
			'CRLF'                     => array( "one\r\ntwo", 'series: "one two"' ),
			'encoded newline'          => array( 'one&#10;two', 'series: "one two"' ),
			'unicode line separator'   => array( "one\u{2028}two", 'series: "one two"' ),
			'next line'                => array( "one\u{85}two", 'series: "one two"' ),
			'tab'                      => array( "one\ttwo", 'series: "one two"' ),
			'surrounding space'        => array( '  padded  ', 'series: "padded"' ),
			'yaml syntax stays quoted' => array( '- [a]: {b} # c', 'series: "- [a]: {b} # c"' ),
			'non-ASCII'                => array( 'מירים', 'series: "מירים"' ),
		);
	}

	/**
	 * The SEO description line: omitted when empty, stripped of HTML, escaped and flattened like
	 * every other string.
	 *
	 * @dataProvider descriptions
	 *
	 * @param string $raw      Description as the SEO plugin or filter returned it.
	 * @param string $expected Rendered line, or '' for no line.
	 */
	public function test_description_line( string $raw, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Converter::description_line( $raw ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function descriptions(): array {
		return array(
			'plain'               => array( 'Bulk PageSpeed scans from wp-admin.', 'description: "Bulk PageSpeed scans from wp-admin."' ),
			'empty'               => array( '', '' ),
			'whitespace only'     => array( " \n ", '' ),
			'tags only'           => array( '<p></p>', '' ),
			'html stripped'       => array( '<strong>Fast</strong> <script>x()</script>scans', 'description: "Fast scans"' ),
			'quotes escaped'      => array( 'The "agent-ready" plugin', 'description: "The \\"agent-ready\\" plugin"' ),
			'entities decoded'    => array( 'Q&amp;A &#8211; notes', 'description: "Q&A – notes"' ),
			'delimiter injection' => array( "x\n---\ntitle: forged", 'description: "x --- title: forged"' ),
		);
	}

	/**
	 * List items get the same escaping as scalars.
	 */
	public function test_escapes_list_items(): void {
		$this->assertSame(
			array( 'topics:', '  - "a \"b\""', '  - "c --- d"' ),
			MMSAR_Converter::extra_frontmatter_lines( array( 'topics' => array( 'a "b"', "c\n---\nd" ) ) )
		);
	}
}
