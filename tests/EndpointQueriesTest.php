<?php
/**
 * The endpoint registry's example questions (1.63.0), published as `representativeQueries` in the
 * AI catalog.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_Registry::queries() and the two storage routes that use it.
 */
final class EndpointQueriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stub_reset();
	}

	/**
	 * @param mixed    $raw      What a caller sent.
	 * @param string[] $expected What is kept.
	 */
	#[DataProvider( 'cleaned' )]
	public function test_queries_are_cleaned( $raw, array $expected ): void {
		$this->assertSame( $expected, MMSAR_Registry::queries( $raw ) );
	}

	/**
	 * @return array<string, array{0:mixed,1:string[]}>
	 */
	public static function cleaned(): array {
		return array(
			// The settings textarea, with Windows line endings and blank lines.
			'textarea lines'          => array( "send a message to Miriam\r\n\r\nask about a talk\n", array( 'send a message to Miriam', 'ask about a talk' ) ),
			// The ability and code registration.
			'array'                   => array( array( 'one question', 'another question' ), array( 'one question', 'another question' ) ),
			// Each is one line of plain text, whatever arrived.
			'tags and inner breaks'   => array( array( "<b>book</b> a\tcall" ), array( 'book a call' ) ),
			'repeats, any case'       => array( array( 'Book a call', 'book a call', 'BOOK A CALL' ), array( 'Book a call' ) ),
			'empties dropped'         => array( array( '', '   ', 'kept' ), array( 'kept' ) ),
			'non-strings dropped'     => array( array( array( 'nested' ), null, 'kept', 7 ), array( 'kept', '7' ) ),
			// ARD asks for at most five.
			'capped at five'          => array( array( 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7' ), array( 'a1', 'a2', 'a3', 'a4', 'a5' ) ),
			'length capped'           => array( array( str_repeat( 'x', 250 ) ), array( str_repeat( 'x', 200 ) ) ),
			'nothing'                 => array( '', array() ),
		);
	}

	/**
	 * The settings form and the set-endpoint ability store the same shape, and a row stored before
	 * 1.63.0, with no queries key, gets an empty list rather than an error.
	 */
	public function test_sanitize_rows_keeps_queries(): void {
		$rows = MMSAR_Registry::sanitize_rows(
			array(
				array(
					'id'      => 'contact',
					'title'   => 'Contact form',
					'href'    => 'https://example.com/wp-json/contact/v1/send',
					'queries' => "send a message to the site owner\nask a question by email",
				),
				array(
					'id'      => 'booking',
					'title'   => 'Booking',
					'href'    => 'https://example.com/book',
					'queries' => array( 'book a call', 'book a call' ),
				),
				array(
					'id'    => 'old',
					'title' => 'Stored before 1.63.0',
					'href'  => 'https://example.com/old',
				),
			)
		);
		$this->assertSame( array( 'send a message to the site owner', 'ask a question by email' ), $rows[0]['queries'] );
		$this->assertSame( array( 'book a call' ), $rows[1]['queries'] );
		$this->assertSame( array(), $rows[2]['queries'] );
	}
}
