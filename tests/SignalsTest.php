<?php
/**
 * The three browser signals, and the privacy rule that rides on one of them.
 *
 * The signals are evidence, not verdicts, and each is easy to get wrong in a way that looks right:
 * a signature parser that accepts any `Signature` header counts ordinary HTTP message signatures
 * as agents; a cloud lookup that treats a stored /24 as a single address calls a residential block
 * "AWS" because it shares a /24 with a /29 somebody rents; a same-site test that matches a suffix
 * trusts `example.com.attacker.net`. Each case below is one of those shapes.
 *
 * The last group drives record() end to end against a capturing $wpdb, because the one thing that
 * must never regress here is not a signal at all: an unrecognised browser's address is reduced
 * before storage, and the cloud check — which reads the full address — must not change that.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'mmsar_feature_enabled' ) ) {
	/**
	 * The plugin's feature switch, reduced to what record() asks: is the log on.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	function mmsar_feature_enabled( $feature ) {
		return 'agent_log' === $feature;
	}
}

/**
 * A $wpdb that records inserts and does nothing else.
 */
final class Signals_Capturing_Wpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Id of the last insert. Never a multiple of PRUNE_EVERY, so prune() is not reached.
	 *
	 * @var int
	 */
	public $insert_id = 1;

	/**
	 * Every row passed to insert().
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Records the row.
	 *
	 * @param string $table  Table.
	 * @param array  $data   Row.
	 * @param array  $format Formats.
	 * @return int
	 */
	public function insert( $table, $data, $format = array() ) {
		$this->rows[] = $data;
		return 1;
	}
}

/**
 * MMSAR_Agent_Log_Signals, and record()'s use of it.
 */
final class SignalsTest extends TestCase {

	/**
	 * A Chrome document navigation's headers, which is what a real browser — or an agent driving one
	 * — sends.
	 */
	const BROWSER = array(
		'HTTP_USER_AGENT'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
		'HTTP_SEC_FETCH_MODE'  => 'navigate',
		'HTTP_SEC_FETCH_DEST'  => 'document',
		'HTTP_SEC_CH_UA'       => '"Chromium";v="152"',
		'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		'HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9',
	);

	/**
	 * The dictionary-form signature headers the current draft specifies, as ChatGPT agent sends them.
	 */
	const SIGNED = array(
		'HTTP_SIGNATURE'       => 'sig1=:jdq0SqOwHdyHr9+r5jw3iYZH6aNGKijYp/EstF4RQTQdi5N5YYKrD+mCT1HA1nZDsi6nJKuHxUi/5Syp3rLWBA==:',
		'HTTP_SIGNATURE_INPUT' => 'sig1=("@authority" "signature-agent";key="sig1");created=1790150000;expires=1790150060;keyid="ArwjqcCqtA5oxEWkzXGC-To_0A9whHmajCHtfe8I9aY";alg="ed25519";tag="web-bot-auth"',
		'HTTP_SIGNATURE_AGENT' => 'sig1="https://chatgpt.com"',
	);

	/**
	 * The $_SERVER keys any test may set, cleared around each one.
	 */
	const KEYS = array( 'HTTP_USER_AGENT', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_CH_UA', 'HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_SIGNATURE', 'HTTP_SIGNATURE_INPUT', 'HTTP_SIGNATURE_AGENT', 'HTTP_REFERER', 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP' );

	/**
	 * Clean state per test.
	 */
	protected function setUp(): void {
		wp_stub_reset();
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$GLOBALS['wpdb'] = new Signals_Capturing_Wpdb();
	}

	/**
	 * Leave $_SERVER as found.
	 */
	protected function tearDown(): void {
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// Signal 1: signatures
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider signatures
	 *
	 * @param string $signature Signature.
	 * @param string $input     Signature-Input.
	 * @param string $agent     Signature-Agent.
	 * @param string $expected  Stored value.
	 */
	public function test_signature_agent_from_headers( string $signature, string $input, string $agent, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Signals::signature_agent_from_headers( $signature, $input, $agent ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string,3:string}>
	 */
	public function signatures(): array {
		$sig   = self::SIGNED['HTTP_SIGNATURE'];
		$input = self::SIGNED['HTTP_SIGNATURE_INPUT'];
		return array(
			// Signed.
			'dictionary form, as the draft specifies'    => array( $sig, $input, 'sig1="https://chatgpt.com"', 'https://chatgpt.com' ),
			'legacy bare string, which deployments send' => array( $sig, $input, '"https://chatgpt.com"', 'https://chatgpt.com' ),
			'a directory path reduces to its origin'     => array( $sig, $input, 'sig1="https://signer.example.com/.well-known/http-message-signatures-directory";type=directory', 'https://signer.example.com' ),
			'host is lowercased'                         => array( $sig, $input, 'sig1="https://ChatGPT.com"', 'https://chatgpt.com' ),
			'a non-default port is kept'                 => array( $sig, $input, 'sig1="https://agent.example:8443"', 'https://agent.example:8443' ),
			'first usable member of several'             => array( $sig, $input, 'a="not a url", b="https://browserbase.com"', 'https://browserbase.com' ),
			'tag spelled with spaces and in capitals'    => array( $sig, 'sig1=("@authority");created=1;expires=2;keyid="k"; TAG = "Web-Bot-Auth"', 'sig1="https://chatgpt.com"', 'https://chatgpt.com' ),

			// Signed, but naming nobody usable — still counts as signed.
			'no Signature-Agent at all'                  => array( $sig, $input, '', MMSAR_Agent_Log_Signals::UNNAMED ),
			'plain http is not an operator'              => array( $sig, $input, 'sig1="http://chatgpt.com"', MMSAR_Agent_Log_Signals::UNNAMED ),
			'an unquoted value is not a string item'     => array( $sig, $input, 'sig1=https://chatgpt.com', MMSAR_Agent_Log_Signals::UNNAMED ),

			// Not a Web Bot Auth request.
			'unsigned'                                   => array( '', '', '', '' ),
			'Signature-Agent alone proves nothing'       => array( '', '', 'sig1="https://chatgpt.com"', '' ),
			'no Signature header'                        => array( '', $input, 'sig1="https://chatgpt.com"', '' ),
			'no Signature-Input header'                  => array( $sig, '', 'sig1="https://chatgpt.com"', '' ),
			'another use of HTTP message signatures'     => array( $sig, 'sig1=("@method" "@path");created=1;keyid="k"', 'sig1="https://chatgpt.com"', '' ),
			'a different tag'                            => array( $sig, 'sig1=("@authority");created=1;tag="payments"', 'sig1="https://chatgpt.com"', '' ),
		);
	}

	/**
	 * The request path reads the three headers, through sanitisation, and keeps the quotes the
	 * parser needs.
	 */
	public function test_request_signature_agent_reads_the_headers(): void {
		$this->assertSame( '', MMSAR_Agent_Log_Signals::request_signature_agent(), 'No headers is unsigned.' );

		$_SERVER = array_merge( $_SERVER, self::SIGNED );
		$this->assertSame( 'https://chatgpt.com', MMSAR_Agent_Log_Signals::request_signature_agent() );
	}

	// -------------------------------------------------------------------------
	// Signal 2: cloud network
	// -------------------------------------------------------------------------

	/**
	 * Addresses from the bundled data. These are real ranges, chosen because they are the ones the
	 * log has actually seen, so a refresh that drops one of them is worth knowing about rather than
	 * silently absorbing.
	 *
	 * @dataProvider bundledAddresses
	 *
	 * @param string $ip       Address.
	 * @param string $expected Provider key or ''.
	 */
	public function test_cloud_network_against_the_bundled_ranges( string $ip, string $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Signals::cloud_network( $ip ), $ip );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function bundledAddresses(): array {
		return array(
			'AWS EC2 — the Ora scanner pool'               => array( '3.83.8.90', 'aws' ),
			'AWS EC2 at network precision'                 => array( '3.83.8.0', 'aws' ),
			'Google Cloud — Claude desktop chat'           => array( '34.162.191.81', 'gcp' ),
			'Azure'                                        => array( '20.102.46.225', 'azure' ),
			'AWS EC2 over IPv6'                            => array( '2600:1f6a:c800::1', 'aws' ),
			'AWS EC2 over IPv6 at network precision'       => array( '2600:1f6a:c800:0::', 'aws' ),
			'a /29 of Azure, the full address'             => array( '4.191.131.5', 'azure' ),
			'the /24 around that /29: cannot tell'         => array( '4.191.131.0', MMSAR_Agent_Log_Signals::CLOUD_PARTIAL ),

			// Not cloud, and several of these are the false positives the signal is built to avoid.
			'residential broadband'                        => array( '86.209.233.10', '' ),
			'Cloudflare WARP, a real person\'s Claude Code' => array( '104.28.1.1', '' ),
			'Googlebot\'s crawl range is not Google Cloud' => array( '66.249.66.1', '' ),
			'Huawei\'s PetalBot range is not in any cloud list' => array( '114.119.144.17', '' ),
			'Anthropic\'s own crawl range is not a cloud'  => array( '216.73.216.1', '' ),
			'residential IPv6'                             => array( '2a01:cb00:1:2::5', '' ),

			// Special-purpose space. Vultr's own feed lists all of these as its own; the generator
			// drops them. 6to4 is the one that matters — its addresses embed a person's own IPv4.
			'a 6to4 address is a person, not Vultr'              => array( '2002:56d1:e90a::1', '' ),
			'documentation IPv6'                                 => array( '2001:db8::1', '' ),
			'documentation IPv4'                                 => array( '192.0.2.1', '' ),

			// Garbage in, nothing out.
			'empty'                                        => array( '', '' ),
			'not an address'                               => array( 'not-an-ip', '' ),
		);
	}

	/**
	 * The boundary logic, on synthetic ranges so every edge is placed deliberately.
	 *
	 * The case that matters most is the partial one. The log stores a reader's address as its /24,
	 * and a /24 can share space with a narrower cloud block. Calling that /24 "cloud" would label a
	 * residential neighbour of a rented /29 as a cloud visitor; calling it "not cloud" would miss the
	 * rented /29's own traffic. It is neither, and the answer says so.
	 */
	public function test_cloud_network_boundaries(): void {
		add_filter(
			'mmsar_agent_log_cloud_ranges',
			static function () {
				return array(
					'providers' => array(
						'alpha' => array(
							'label'    => 'Alpha',
							'captured' => '2026-01-01',
						),
						'beta'  => array(
							'label'    => 'Beta',
							'captured' => '2026-02-01',
						),
					),
					// Records as the bundled file packs them: start, end, provider index.
					'v4'        => pack( 'NNC', ip2long( '192.0.2.0' ), ip2long( '192.0.2.63' ), 0 )           // A /26.
						. pack( 'NNC', ip2long( '198.51.100.0' ), ip2long( '198.51.101.255' ), 1 ),              // A /23.
					'v6'        => hex2bin( '20010db800000000' ) . hex2bin( '20010db8000000ff' ) . chr( 0 ),     // A /56.
				);
			}
		);

		// A full address is exact on both sides of an edge.
		$this->assertSame( 'alpha', MMSAR_Agent_Log_Signals::cloud_network( '192.0.2.1' ) );
		$this->assertSame( 'alpha', MMSAR_Agent_Log_Signals::cloud_network( '192.0.2.63' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '192.0.2.64' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '192.0.1.255' ) );

		// A stored /24 that only partly overlaps is "cannot tell", never either answer.
		$this->assertSame( MMSAR_Agent_Log_Signals::CLOUD_PARTIAL, MMSAR_Agent_Log_Signals::cloud_network( '192.0.2.0' ) );

		// A stored /24 wholly inside a wider range is exact.
		$this->assertSame( 'beta', MMSAR_Agent_Log_Signals::cloud_network( '198.51.100.0' ) );
		$this->assertSame( 'beta', MMSAR_Agent_Log_Signals::cloud_network( '198.51.101.0' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '198.51.102.0' ) );

		// Before the first range and after the last.
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '1.1.1.1' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '223.255.255.1' ) );

		// IPv6 is decided by the upper 64 bits, full or reduced.
		$this->assertSame( 'alpha', MMSAR_Agent_Log_Signals::cloud_network( '2001:db8:0:ff::1' ) );
		$this->assertSame( 'alpha', MMSAR_Agent_Log_Signals::cloud_network( '2001:db8:0:1::' ) );
		$this->assertSame( '', MMSAR_Agent_Log_Signals::cloud_network( '2001:db8:0:100::1' ) );

		// The capture date is the oldest one, as with the crawler ranges.
		$this->assertSame( '2026-01-01', MMSAR_Agent_Log_Signals::cloud_ranges_captured() );
		$this->assertSame( 'Beta', MMSAR_Agent_Log_Signals::cloud_label( 'beta' ) );
	}

	/**
	 * The bundled file keeps the two properties the binary search depends on: sorted by start, and
	 * no two ranges overlapping. The generator refuses to write a file that breaks either; this
	 * catches a hand edit, or a filter that got it wrong.
	 */
	public function test_bundled_ranges_are_sorted_and_disjoint(): void {
		$data      = include dirname( __DIR__ ) . '/includes/data/cloud-ranges.php';
		$providers = count( $data['providers'] );
		foreach ( array(
			'v4' => 4,
			'v6' => 8,
		) as $family => $width ) {
			$records = base64_decode( $data[ $family ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the bundled data under test.
			$size    = $width * 2 + 1;
			$this->assertSame( 0, strlen( $records ) % $size, "$family is not a whole number of records" );
			$this->assertGreaterThan( 0, strlen( $records ), $family );

			$faults = array();
			$prev   = null;
			for ( $i = 0, $n = strlen( $records ) / $size; $i < $n; $i++ ) {
				$record = substr( $records, $i * $size, $size );
				$first  = substr( $record, 0, $width );
				$last   = substr( $record, $width, $width );
				if ( ord( $record[ $size - 1 ] ) >= $providers ) {
					$faults[] = "$family record $i names an unknown provider";
				}
				if ( strcmp( $first, $last ) > 0 ) {
					$faults[] = "$family record $i ends before it starts";
				}
				if ( null !== $prev && strcmp( $first, $prev ) <= 0 ) {
					$faults[] = "$family records " . ( $i - 1 ) . " and $i overlap or are out of order";
				}
				$prev = $last;
			}
			$this->assertSame( array(), $faults );
		}
		$this->assertNotSame( '', MMSAR_Agent_Log_Signals::cloud_ranges_captured() );
	}

	/**
	 * The cloud signal is assessed on browser rows only; every other client type reports null.
	 */
	public function test_for_row_assesses_cloud_on_browser_rows_only(): void {
		$browser = MMSAR_Agent_Log_Signals::for_row(
			array(
				'client_type' => 'browser',
				'ip'          => '3.83.8.0',
				'same_site'   => '1',
			)
		);
		$this->assertSame( 'aws', $browser['cloud_network'] );
		$this->assertTrue( $browser['same_site'] );
		$this->assertSame( '', $browser['signature_agent'] );

		$http = MMSAR_Agent_Log_Signals::for_row(
			array(
				'client_type'     => 'http',
				'ip'              => '3.83.8.90',
				'same_site'       => '0',
				'signature_agent' => 'https://chatgpt.com',
			)
		);
		$this->assertNull( $http['cloud_network'] );
		$this->assertFalse( $http['same_site'] );
		$this->assertSame( 'https://chatgpt.com', $http['signature_agent'] );

		$old = MMSAR_Agent_Log_Signals::for_row(
			array(
				'client_type' => 'browser',
				'ip'          => '86.209.233.0',
				'same_site'   => null,
			)
		);
		$this->assertNull( $old['same_site'], 'A row from before 1.48.0 has no Referer signal, and must not read as "no".' );
	}

	// -------------------------------------------------------------------------
	// Signal 3: came from a link on this site
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider referers
	 *
	 * @param string $referer  Referer.
	 * @param bool   $expected Same site.
	 */
	public function test_is_same_site( string $referer, bool $expected ): void {
		$this->assertSame( $expected, MMSAR_Agent_Log_Signals::is_same_site( $referer, array( 'example.com' ) ) );
	}

	/**
	 * @return array<string, array{0:string,1:bool}>
	 */
	public function referers(): array {
		return array(
			'a page on this site'                 => array( 'https://example.com/some-post/', true ),
			'the home page'                       => array( 'https://example.com/', true ),
			'host case does not matter'           => array( 'https://EXAMPLE.com/about/', true ),
			'off-site'                            => array( 'https://news.ycombinator.com/item?id=1', false ),
			'missing'                             => array( '', false ),
			'a lookalike host ending in this one' => array( 'https://example.com.attacker.net/', false ),
			'a lookalike host starting with it'   => array( 'https://notexample.com/', false ),
			'a subdomain is a different host'     => array( 'https://www.example.com/', false ),
			'not a URL'                           => array( 'example.com', false ),
		);
	}

	/**
	 * The request path compares against home_url()'s host.
	 */
	public function test_request_same_site_reads_the_referer(): void {
		$this->assertSame( 0, MMSAR_Agent_Log_Signals::request_same_site(), 'No Referer is not same-site.' );
		$_SERVER['HTTP_REFERER'] = 'https://example.com/writing/';
		$this->assertSame( 1, MMSAR_Agent_Log_Signals::request_same_site() );
		$_SERVER['HTTP_REFERER'] = 'https://elsewhere.example/';
		$this->assertSame( 0, MMSAR_Agent_Log_Signals::request_same_site() );
	}

	/**
	 * IPv6 reduction must survive compression. Before 1.48.0 an address with a zero group in its
	 * first half — `2001:db8::1` — was reduced by splitting its compressed text, and stored as
	 * `2001:db8::1::`: not an address, and still carrying the bits it existed to drop. Found by the
	 * page-view test below, on an AWS IPv6 address.
	 *
	 * @dataProvider ipv6Reductions
	 *
	 * @param string $ip       Full address.
	 * @param string $expected Reduced form.
	 */
	public function test_anonymize_ip_reduces_compressed_ipv6( string $ip, string $expected ): void {
		$reduced = MMSAR_Agent_Log::anonymize_ip( $ip );
		$this->assertSame( $expected, $reduced );
		$this->assertNotFalse( filter_var( $reduced, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ), 'A reduced address must still be an address.' );
		$this->assertSame( $reduced, MMSAR_Agent_Log::anonymize_ip( $reduced ), 'A reduced address must be its own reduction, or the verifier reads it as a full one.' );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function ipv6Reductions(): array {
		return array(
			'no zero groups: the form is unchanged' => array( '2a01:cb00:1:2::5', '2a01:cb00:1:2::' ),
			'compression inside the first half'     => array( '2001:db8::1', '2001:db8:0:0::' ),
			'a zero group then the interface ID'    => array( '2600:1f6a:c800::1234', '2600:1f6a:c800:0::' ),
			'uncompressed input'                    => array( '2a01:0e0a:0000:0000:1234:5678:9abc:def0', '2a01:e0a:0:0::' ),
			'leading zeros are dropped'             => array( '2001:0db8:000a:00b0::1', '2001:db8:a:b0::' ),
		);
	}

	// -------------------------------------------------------------------------
	// record(): what reaches the table
	// -------------------------------------------------------------------------

	/**
	 * Sends one request through record() and returns the row it wrote.
	 *
	 * @param array  $headers   $_SERVER entries.
	 * @param string $ip        Client address.
	 * @param bool   $page_view Record it the way maybe_record_page_view() does — reduced unless the
	 *                          client is a crawler — rather than as an agent-facing file.
	 * @return array Stored row.
	 */
	private function record( array $headers, string $ip, bool $page_view ): array {
		$_SERVER                = array_merge( $_SERVER, $headers );
		$_SERVER['REMOTE_ADDR'] = $ip;

		$reflect = new ReflectionMethod( MMSAR_Agent_Log::class, 'detect_client_type' );
		$reflect->setAccessible( true );
		$anonymize = $page_view && MMSAR_Agent_Log::CLIENT_CRAWLER !== $reflect->invoke( null );

		MMSAR_Agent_Log::record( $page_view ? 'HTML page view (asked for HTML)' : 'llms.txt', $page_view ? '/about/' : '', $page_view, $anonymize );

		$rows = $GLOBALS['wpdb']->rows;
		$this->assertCount( 1, $rows, 'Exactly one row should have been written.' );
		return $rows[0];
	}

	/**
	 * The guarantee this release must not weaken: an unrecognised browser's page view stores its
	 * network, from a cloud range or not. The cloud check reads the full address; the table never
	 * sees it.
	 *
	 * @dataProvider pageViewAddresses
	 *
	 * @param string $ip      Client address.
	 * @param string $stored  Expected stored address.
	 * @param string $network Expected cloud_network() of the stored value.
	 */
	public function test_browser_page_views_are_still_reduced( string $ip, string $stored, string $network ): void {
		$row = $this->record( self::BROWSER + array( 'HTTP_REFERER' => 'https://example.com/' ), $ip, true );

		$this->assertSame( $stored, $row['ip'] );
		$this->assertSame( 'browser', $row['client_type'] );
		$this->assertSame( 1, $row['same_site'] );
		$this->assertSame( '', $row['signature_agent'] );
		// And the signal is still answerable from what was stored, which is the whole design.
		$this->assertSame( $network, MMSAR_Agent_Log_Signals::cloud_network( $row['ip'] ) );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function pageViewAddresses(): array {
		return array(
			'residential'   => array( '86.209.233.10', '86.209.233.0', '' ),
			'AWS'           => array( '3.83.8.90', '3.83.8.0', 'aws' ),
			'Google Cloud'  => array( '34.162.191.81', '34.162.191.0', 'gcp' ),
			'AWS over IPv6' => array( '2600:1f6a:c800::1234', '2600:1f6a:c800:0::', 'aws' ),
		);
	}

	/**
	 * Decision 6C: an agent-facing file keeps its full address, except when the request is a real
	 * browser, unsigned, that followed a link on this site from outside every cloud range — a person
	 * clicking the footer's llms.txt link. Each case moves exactly one condition.
	 *
	 * @dataProvider agentFileRequests
	 *
	 * @param array  $headers  $_SERVER entries.
	 * @param string $ip       Client address.
	 * @param string $stored   Expected stored address.
	 */
	public function test_agent_file_address_rule( array $headers, string $ip, string $stored ): void {
		$row = $this->record( $headers, $ip, false );
		$this->assertSame( $stored, $row['ip'] );
	}

	/**
	 * @return array<string, array{0:array,1:string,2:string}>
	 */
	public function agentFileRequests(): array {
		$link   = array( 'HTTP_REFERER' => 'https://example.com/some-post/' );
		$script = array( 'HTTP_USER_AGENT' => 'node' );
		return array(
			'a person clicking the footer link: reduced' => array( self::BROWSER + $link, '86.209.233.10', '86.209.233.0' ),
			'the same person over IPv6: reduced'         => array( self::BROWSER + $link, '2a01:cb00:1:2::5', '2a01:cb00:1:2::' ),
			'from a cloud network: full address'         => array( self::BROWSER + $link, '3.83.8.90', '3.83.8.90' ),
			'signed: full address'                       => array( self::BROWSER + $link + self::SIGNED, '86.209.233.10', '86.209.233.10' ),
			'no Referer: full address'                   => array( self::BROWSER, '86.209.233.10', '86.209.233.10' ),
			'off-site Referer: full address'             => array( self::BROWSER + array( 'HTTP_REFERER' => 'https://elsewhere.example/' ), '86.209.233.10', '86.209.233.10' ),
			'a script with a same-site Referer: full'    => array( $script + $link, '86.209.233.10', '86.209.233.10' ),
		);
	}

	/**
	 * Both stored signals reach the row as the columns expect them.
	 */
	public function test_signals_are_stored(): void {
		$row = $this->record( self::BROWSER + self::SIGNED + array( 'HTTP_REFERER' => 'https://elsewhere.example/a-private-page/' ), '3.83.8.90', false );
		$this->assertSame( 'https://chatgpt.com', $row['signature_agent'] );
		$this->assertSame( 0, $row['same_site'] );
		$this->assertSame( '3.83.8.90', $row['ip'] );

		// Only the yes/no survives. Nothing of the Referer itself reaches any column.
		foreach ( $row as $column => $value ) {
			$this->assertStringNotContainsString( 'elsewhere.example', (string) $value, "The Referer leaked into $column." );
		}
	}
}
