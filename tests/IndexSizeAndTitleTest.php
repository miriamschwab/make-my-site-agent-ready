<?php
/**
 * Tests for the Markdown title heading and the root llms.txt size budget.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Converter title heading, and MMSAR_LLMs_Txt::fit_to_budget().
 */
class IndexSizeAndTitleTest extends TestCase {

	protected function setUp(): void {
		wp_stub_reset();
	}

	/**
	 * A post-like object with a title.
	 *
	 * @param string $title Title.
	 * @return object
	 */
	private function post( $title ) {
		return (object) array( 'post_title' => $title );
	}

	/**
	 * Calls a private static method.
	 *
	 * @param string $class  Class.
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( $class, $method, array $args ) {
		$ref = new ReflectionMethod( $class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	public function test_adds_title_heading_to_body(): void {
		$this->assertSame(
			"# Hi, I’m Miriam\n\nFirst paragraph.\n",
			$this->call( 'MMSAR_Converter', 'with_title_heading', array( "First paragraph.\n", $this->post( 'Hi, I&#8217;m Miriam' ) ) )
		);
	}

	public function test_keeps_existing_level_one_heading(): void {
		$body = "# Written by the author\n\nText.\n";
		$this->assertSame( $body, $this->call( 'MMSAR_Converter', 'with_title_heading', array( $body, $this->post( 'Title' ) ) ) );
	}

	public function test_adds_heading_above_a_level_two_heading(): void {
		$this->assertSame(
			"# Title\n\n## Section\n",
			$this->call( 'MMSAR_Converter', 'with_title_heading', array( "## Section\n", $this->post( 'Title' ) ) )
		);
	}

	public function test_leaves_empty_body_and_untitled_post_alone(): void {
		$this->assertSame( '', $this->call( 'MMSAR_Converter', 'with_title_heading', array( '', $this->post( 'Title' ) ) ) );
		$this->assertSame( "Text.\n", $this->call( 'MMSAR_Converter', 'with_title_heading', array( "Text.\n", $this->post( '' ) ) ) );
	}

	public function test_title_heading_strips_tags_and_decodes(): void {
		$this->assertSame( '# A & B', MMSAR_Converter::title_heading( $this->post( '<em>A</em> &amp; B' ) ) );
	}

	public function test_without_title_heading_removes_only_the_added_heading(): void {
		$post = $this->post( 'Title' );
		$doc  = "---\ntitle: \"Title\"\n---\n# Title\n\nBody.\n";
		$this->assertSame( "---\ntitle: \"Title\"\n---\nBody.\n", MMSAR_Converter::without_title_heading( $doc, $post ) );

		// A different first heading is the author's, and stays.
		$authored = "---\ntitle: \"Title\"\n---\n# Something else\n\nBody.\n";
		$this->assertSame( $authored, MMSAR_Converter::without_title_heading( $authored, $post ) );

		// A stored document from before the heading existed is unchanged.
		$old = "---\ntitle: \"Title\"\n---\nBody.\n";
		$this->assertSame( $old, MMSAR_Converter::without_title_heading( $old, $post ) );

		// A longer title that starts with this one is not a match.
		$longer = "---\ntitle: \"Title\"\n---\n# Title two\n\nBody.\n";
		$this->assertSame( $longer, MMSAR_Converter::without_title_heading( $longer, $post ) );
	}

	/**
	 * A synthetic block of a given size.
	 *
	 * @param int    $size   Size in bytes.
	 * @param string $scoped Scoped index URL, or ''.
	 * @return array
	 */
	private function block( $size, $scoped ) {
		return array(
			'lines'     => array( '## X' ),
			'short'     => '' === $scoped ? array() : array( '## X', '- [X index](' . $scoped . ')' ),
			'scoped'    => $scoped,
			'collapsed' => false,
			'size'      => $size,
		);
	}

	/**
	 * Which blocks fit_to_budget() collapsed.
	 *
	 * @param array[] $blocks Blocks.
	 * @param int     $fixed  Fixed bytes.
	 * @return bool[]
	 */
	private function collapsed( array $blocks, $fixed ) {
		return array_column( $this->call( 'MMSAR_LLMs_Txt', 'fit_to_budget', array( $blocks, $fixed ) ), 'collapsed' );
	}

	public function test_under_budget_collapses_nothing(): void {
		$blocks = array( $this->block( 10000, 'https://example.com/media/llms.txt' ), $this->block( 5000, '' ) );
		$this->assertSame( array( false, false ), $this->collapsed( $blocks, 2000 ) );
	}

	public function test_collapses_largest_scoped_section_first_and_stops_when_it_fits(): void {
		$blocks = array(
			$this->block( 6000, '' ),
			$this->block( 4000, 'https://example.com/plugins/llms.txt' ),
			$this->block( 14000, 'https://example.com/media/llms.txt' ),
		);
		// 2,000 + 24,000 = 26,000 over a 25,000 budget: the 14,000 section alone is enough.
		add_filter( 'mmsar_llms_txt_budget', fn() => 25000 );
		$this->assertSame( array( false, false, true ), $this->collapsed( $blocks, 2000 ) );
	}

	public function test_never_collapses_a_section_without_its_own_index(): void {
		$blocks = array( $this->block( 40000, '' ), $this->block( 3000, 'https://example.com/media/llms.txt' ) );
		// Still over budget afterwards; served as is rather than dropping unindexed entries.
		$this->assertSame( array( false, true ), $this->collapsed( $blocks, 1000 ) );
	}

	public function test_budget_of_zero_turns_it_off(): void {
		add_filter( 'mmsar_llms_txt_budget', fn() => 0 );
		$blocks = array( $this->block( 50000, 'https://example.com/media/llms.txt' ) );
		$this->assertSame( array( false ), $this->collapsed( $blocks, 1000 ) );
	}
}
