<?php
/**
 * Tests for /ask's NLWeb protocol v0.54 support.
 *
 * Shapes are taken from NLWeb_Core's reference server (nlweb-ai/NLWeb_Core, docs/http_example.md
 * and packages/core/nlweb_core/baseNLWeb.py): the request nests the question in `query.text`, and an
 * Answer's results are schema.org objects grounded in `source_urls`.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mmsar-nlweb.php';

/**
 * MMSAR_NLWeb v0.54 request parsing, result shape and OpenAPI description.
 */
class AskV054Test extends TestCase {

	protected function setUp(): void {
		wp_stub_reset();
	}

	public function test_reads_question_from_v054_query_object(): void {
		$body = array(
			'query'  => array(
				'text' => 'healthy breakfast recipes',
				'site' => 'example.com',
			),
			'prefer' => array( 'streaming' => true ),
			'meta'   => array( 'api_version' => '0.54' ),
		);
		$this->assertSame( 'healthy breakfast recipes', MMSAR_NLWeb::query_from_body( $body ) );
		$this->assertTrue( MMSAR_NLWeb::is_v054_body( $body ) );
	}

	public function test_flat_string_query_still_works_and_is_not_v054(): void {
		$body = array( 'query' => 'mcp' );
		$this->assertSame( 'mcp', MMSAR_NLWeb::query_from_body( $body ) );
		$this->assertFalse( MMSAR_NLWeb::is_v054_body( $body ) );
		$this->assertSame( 'mcp', MMSAR_NLWeb::query_from_body( array( 'q' => 'mcp' ) ) );
	}

	public function test_v054_object_without_text_gives_no_question(): void {
		$body = array( 'query' => array( 'site' => 'example.com' ) );
		$this->assertSame( '', MMSAR_NLWeb::query_from_body( $body ) );
		// Still v0.54, so the caller gets a v0.54 Failure rather than the flat error.
		$this->assertTrue( MMSAR_NLWeb::is_v054_body( $body ) );
		$this->assertSame( '', MMSAR_NLWeb::query_from_body( array( 'query' => array( 'text' => array( 'x' ) ) ) ) );
	}

	public function test_meta_version_alone_marks_v054(): void {
		$this->assertTrue( MMSAR_NLWeb::is_v054_body( array( 'meta' => array( 'version' => '0.54' ) ) ) );
		$this->assertFalse( MMSAR_NLWeb::is_v054_body( array() ) );
		$this->assertFalse( MMSAR_NLWeb::is_v054_body( array( 'meta' => 'x' ) ) );
	}

	public function test_result_is_the_schema_object_grounded_in_its_url(): void {
		$result = array(
			'url'           => 'https://example.com/post/',
			'name'          => 'Post',
			'site'          => 'example.com',
			'score'         => 1,
			'description'   => 'About it.',
			'schema_object' => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Article',
				'name'        => 'Post',
				'url'         => 'https://example.com/post/',
				'description' => 'About it.',
			),
			'markdown_url'  => 'https://example.com/post.md',
		);
		$this->assertSame(
			array(
				'@context'     => 'https://schema.org',
				'@type'        => 'Article',
				'name'         => 'Post',
				'url'          => 'https://example.com/post/',
				'description'  => 'About it.',
				'grounding'    => array( 'source_urls' => array( 'https://example.com/post/' ) ),
				'score'        => 1,
				'site'         => 'example.com',
				'markdown_url' => 'https://example.com/post.md',
			),
			MMSAR_NLWeb::result_v054( $result )
		);

		$result['markdown_url'] = null;
		$this->assertArrayNotHasKey( 'markdown_url', MMSAR_NLWeb::result_v054( $result ) );
	}

	public function test_openapi_ask_operations_list_event_stream_and_request_shapes(): void {
		$generic  = array(
			'responses' => array( '200' => array( 'content' => array( 'application/json' => array() ) ) ),
		);
		$document = array(
			'paths' => array(
				'/ask'   => array(
					'get'  => $generic,
					'post' => $generic,
				),
				'/other' => array( 'get' => $generic ),
			),
		);

		$out = MMSAR_NLWeb::describe_ask_in_openapi( $document );

		foreach ( array( 'get', 'post' ) as $method ) {
			$content = $out['paths']['/ask'][ $method ]['responses']['200']['content'];
			$this->assertArrayHasKey( 'text/event-stream', $content );
			$this->assertArrayHasKey( 'application/json', $content );
		}
		$this->assertSame( 'query', $out['paths']['/ask']['get']['parameters'][0]['name'] );
		$shapes = $out['paths']['/ask']['post']['requestBody']['content']['application/json']['schema']['oneOf'];
		$this->assertSame( 'object', $shapes[0]['properties']['query']['type'] );
		$this->assertSame( 'string', $shapes[1]['properties']['query']['type'] );

		// Other paths are untouched, and a document without /ask passes through unchanged.
		$this->assertSame( $generic, $out['paths']['/other']['get'] );
		$this->assertSame( array( 'paths' => array( '/other' => array() ) ), MMSAR_NLWeb::describe_ask_in_openapi( array( 'paths' => array( '/other' => array() ) ) ) );
	}
}
