<?php
/**
 * The WebMCP bridge returns failures as `{ error, code, retryable }` (1.73.0).
 *
 * Runs the real assets/webmcp.js in Node through tests/webmcp-bridge-harness.mjs, with a fake
 * document.modelContext and a fake fetch, so what is asserted is what the shipped file does. Node
 * is required: the plugin's audit runs on a machine that has it, and a missing Node is a failure
 * here rather than a skip, so the gate can never pass without these cases having run.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every failure path of the bridge, and the success paths it must leave alone.
 */
final class WebMcpErrorsTest extends TestCase {

	/**
	 * Runs the harness on one scenario and returns what the tool returned.
	 *
	 * @param array $endpoint What the MCP endpoint does.
	 * @return mixed
	 */
	private function run_bridge( array $endpoint ) {
		$node = '';
		foreach ( array( '/usr/local/bin/node', '/opt/homebrew/bin/node', '/usr/bin/node' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				$node = $candidate;
				break;
			}
		}
		$this->assertNotSame( '', $node, 'Node is needed to run the WebMCP bridge tests.' );

		$harness = __DIR__ . '/webmcp-bridge-harness.mjs';
		$pipes   = array();
		$process = proc_open( array( $node, $harness ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );

		fwrite( $pipes[0], (string) json_encode( array( 'endpoint' => $endpoint ) ) );
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$decoded = json_decode( $out, true );
		$this->assertIsArray( $decoded, 'Harness output: ' . $out . $err );
		$this->assertArrayHasKey( 'result', $decoded, 'Harness output: ' . $out . $err );
		return $decoded['result'];
	}

	/**
	 * Each failure comes back as an object with the site's message, the code and whether to retry.
	 *
	 * @param array     $endpoint  What the endpoint does.
	 * @param string    $message   Expected `error`.
	 * @param int|null  $code      Expected `code`.
	 * @param bool      $retryable Expected `retryable`.
	 * @param string    $kind      Expected `kind`.
	 */
	#[DataProvider( 'failures' )]
	public function test_failure_object( array $endpoint, string $message, ?int $code, bool $retryable, string $kind ): void {
		$this->assertSame(
			array(
				'error'     => $message,
				'code'      => $code,
				'retryable' => $retryable,
				'kind'      => $kind,
			),
			$this->run_bridge( $endpoint )
		);
	}

	/**
	 * @return array<string, array{0:array,1:string,2:int|null,3:bool,4:string}>
	 */
	public static function failures(): array {
		$rpc = static function ( int $code, string $message ): array {
			return array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			);
		};
		$tool_error = static function ( string $text ): array {
			return array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => array(
					'content' => '' === $text ? array() : array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
					'isError' => true,
				),
			);
		};
		return array(
			'rate limit (-32000, HTTP 429)'      => array(
				array(
					'status' => 429,
					'body'   => $rpc( -32000, 'Rate limit exceeded. Try again in 42s.' ),
				),
				'Rate limit exceeded. Try again in 42s.',
				-32000,
				true,
				'rpc',
			),
			// The site's message stays whole: here it lists the topics that do exist.
			'bad arguments (-32602)'             => array(
				array(
					'status' => 200,
					'body'   => $rpc( -32602, 'Unknown topic "x". Topics on this site: ai, wordpress.' ),
				),
				'Unknown topic "x". Topics on this site: ai, wordpress.',
				-32602,
				false,
				'rpc',
			),
			'unknown method (-32601)'            => array(
				array(
					'status' => 200,
					'body'   => $rpc( -32601, 'Unknown method: tools/call' ),
				),
				'Unknown method: tools/call',
				-32601,
				false,
				'rpc',
			),
			'an error with no message'           => array(
				array(
					'status' => 200,
					'body'   => array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'error'   => array( 'code' => -32602 ),
					),
				),
				'The site could not run this tool.',
				-32602,
				false,
				'rpc',
			),
			'site unreachable'                   => array(
				array( 'reject' => true ),
				'The site could not be reached, so the tool did not run.',
				null,
				true,
				'network',
			),
			'HTML error page, HTTP 503'          => array(
				array(
					'status' => 503,
					'body'   => '<html>Briefly unavailable for scheduled maintenance.</html>',
				),
				'The site answered with HTTP 503 and no result.',
				null,
				true,
				'http',
			),
			'HTML page, HTTP 404'                => array(
				array(
					'status' => 404,
					'body'   => '<html>Not found</html>',
				),
				'The site answered with HTTP 404 and no result.',
				null,
				false,
				'http',
			),
			// Tool-level failures (MCP's isError): the tool ran and the call was wrong. The message
			// says what to send instead, and stays whole.
			'unknown topic (isError)'            => array(
				array(
					'status' => 200,
					'body'   => $tool_error( "This site has no topic \"x\".\n\nTopics on this site, most used first: ai, wordpress." ),
				),
				"This site has no topic \"x\".\n\nTopics on this site, most used first: ai, wordpress.",
				null,
				false,
				'tool',
			),
			'isError with no text'               => array(
				array(
					'status' => 200,
					'body'   => $tool_error( '' ),
				),
				'The site could not run this tool.',
				null,
				false,
				'tool',
			),
		);
	}

	/**
	 * A tool error is a failure even when the result also carries structured data.
	 */
	public function test_tool_error_wins_over_structured_data(): void {
		$this->assertSame(
			array(
				'error'     => 'Provide a `query`.',
				'code'      => null,
				'retryable' => false,
				'kind'      => 'tool',
			),
			$this->run_bridge(
				array(
					'status' => 200,
					'body'   => array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => array(
							'content'           => array(
								array(
									'type' => 'text',
									'text' => 'Provide a `query`.',
								),
							),
							'structuredContent' => array( 'results' => array() ),
							'isError'           => true,
						),
					),
				)
			)
		);
	}

	/**
	 * Successful results are unchanged: text alone, or text beside structured data.
	 */
	public function test_success_unchanged(): void {
		$text = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Three posts.',
					),
				),
				'isError' => false,
			),
		);
		$this->assertSame(
			'Three posts.',
			$this->run_bridge(
				array(
					'status' => 200,
					'body'   => $text,
				)
			)
		);

		$text['result']['structuredContent'] = array( 'results' => array( array( 'title' => 'A' ) ) );
		$this->assertSame(
			array(
				'text'              => 'Three posts.',
				'structuredContent' => array( 'results' => array( array( 'title' => 'A' ) ) ),
			),
			$this->run_bridge(
				array(
					'status' => 200,
					'body'   => $text,
				)
			)
		);
	}
}
