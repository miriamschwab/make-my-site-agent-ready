<?php
/**
 * A public, read-only Model Context Protocol server for this site's content.
 *
 * Everything else this plugin publishes is a document an agent has to find, fetch and interpret.
 * MCP is the other half: a connection an agent opens once, asks "what can I do here", and then
 * calls. For a content site the answer is small and stable — search it, read a page, list what is
 * recent — which is exactly the shape MCP handles well and exactly what an agent otherwise has to
 * reconstruct by crawling.
 *
 * Three deliberate limits, because this endpoint is unauthenticated and world-reachable:
 *
 *   1. Read-only. No tool here writes anything, and none is capable of it. A site that wants agents
 *      to *do* things should expose those actions through its own authenticated endpoint and list
 *      it in the registry, where the auth requirement can be stated.
 *   2. Published content only, from the post types already enabled for Markdown, skipping anything
 *      password-protected. This exposes nothing that /llms-full.txt does not already publish.
 *   3. Rate-limited per IP, because unlike a static document these calls run queries.
 *
 * Transport is Streamable HTTP (MCP 2025-06-18): one endpoint, JSON-RPC 2.0 over POST, answering
 * with a plain JSON body. The spec permits a JSON response instead of an SSE stream when the server
 * has nothing to stream, which is always true here — every tool returns a complete result in one
 * step, so there is no progress to report and no server-initiated message to deliver.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR MCP server.
 */
class MMSAR_MCP {

	/**
	 * REST namespace and route for the MCP endpoint.
	 */
	const NAMESPACE_V1 = 'mmsar/v1';
	const ROUTE        = '/mcp';

	/**
	 * The MCP protocol revision this server implements.
	 */
	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Protocol revisions this server can speak, newest first. A client that asks for one of these
	 * gets it back; a client that asks for anything else is answered with our own version, which
	 * the spec says it may then accept or disconnect over.
	 */
	const SUPPORTED_PROTOCOLS = array( '2025-06-18', '2025-03-26' );

	/**
	 * Largest number of items any tool will return in one call, whatever the client asks for.
	 */
	const MAX_LIMIT = 50;

	/**
	 * How many topics get_site_overview lists, and an unknown-topic error names.
	 */
	const OVERVIEW_TOPICS = 40;

	/**
	 * Requests allowed per IP per window, and the window in seconds.
	 */
	const RATE_LIMIT  = 60;
	const RATE_WINDOW = 60;

	/**
	 * HTTP status for the single message just dispatched. 2026-07-28 ties statuses to some errors
	 * (400 for version and header failures, 404 for an unknown method); a legacy message always
	 * gets 200, as it always has.
	 *
	 * @var int
	 */
	private static $status = 200;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_manifest' ) );
	}

	/**
	 * The absolute URL agents connect to.
	 *
	 * @return string Absolute URL.
	 */
	public static function endpoint_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Register the JSON-RPC route.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					// Public on purpose: this is the anonymous read-only surface, and everything it
					// can reach is already published on the site's own pages.
					'permission_callback' => '__return_true',
				),
				// The Streamable HTTP transport lets a client open a GET for a server-initiated SSE
				// stream. This server never initiates anything, so it declines — which the spec
				// explicitly provides for — rather than holding a connection open forever.
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'decline_stream' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// The Server Card at the address the MCP Server Card extension (SEP-2127) recommends: the
		// Streamable HTTP URL plus `/server-card`. AI Catalog points here.
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE . '/server-card',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_spec_server_card' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// -------------------------------------------------------------------------
	// JSON-RPC plumbing
	// -------------------------------------------------------------------------

	/**
	 * Handle one JSON-RPC request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response Response.
	 */
	public static function handle( WP_REST_Request $request ) {
		$route = self::route_of( $request );
		$rate  = self::consume_rate_limit();
		if ( ! $rate['allowed'] ) {
			// Recorded, because a client being turned away is the one MCP event that leaves no
			// other trace: it never reaches dispatch(), so without this a caller hammering the
			// endpoint hard enough to be refused looks identical to a caller that never came.
			MMSAR_Agent_Log::record( $route['surface'], 'rate limited', true, $route['anonymize'] );

			// -32000 is in the JSON-RPC implementation-defined server error range. The HTTP status
			// matters too: a client that understands 429 can back off without parsing the body.
			return self::with_rate_limit_headers(
				self::error_response( null, -32000, 'Rate limit exceeded. Try again in ' . $rate['reset'] . 's.', 429 ),
				$rate
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			// Reached when the body is valid JSON of the wrong shape — a bare string or number
			// where an object belongs. A body that is not valid JSON at all never gets here: the
			// REST server rejects it with rest_invalid_json before any route callback runs, so
			// that case is outside what this endpoint can see, let alone record.
			MMSAR_Agent_Log::record( $route['surface'], 'parse error', true, $route['anonymize'] );
			return self::error_response( null, -32700, 'Parse error: request body is not valid JSON.', 400 );
		}

		// A batch is a JSON array of requests. Answering each in turn and returning an array of the
		// responses that have ids is the whole of it — notifications contribute nothing.
		if ( isset( $body[0] ) ) {
			$results = array();
			foreach ( $body as $single ) {
				$result = is_array( $single ) ? self::dispatch( $single, $route, $request ) : null;
				if ( null !== $result ) {
					$results[] = $result;
				}
			}
			// An all-notification batch has nothing to say, and the spec asks for no body at all.
			if ( empty( $results ) ) {
				return self::with_rate_limit_headers( new WP_REST_Response( null, 202 ), $rate );
			}
			return self::with_rate_limit_headers( new WP_REST_Response( $results, 200 ), $rate );
		}

		$result = self::dispatch( $body, $route, $request );
		if ( null === $result ) {
			return self::with_rate_limit_headers( new WP_REST_Response( null, 202 ), $rate );
		}
		return self::with_rate_limit_headers( new WP_REST_Response( $result, self::$status ), $rate );
	}

	/**
	 * The sentence in get_about's description that offers the card's form.
	 */
	const ABOUT_DRAFT_NOTE = ' When the person wants to send a message, pass a draft (a subject and the message in their words, plus their name and email if they gave them): a client that shows the about card puts it in a contact form for them to check and send.';

	/**
	 * How a request reached the endpoint: over MCP, or from the WebMCP bridge in a browser.
	 *
	 * The bridge marks its calls with `X-MMSAR-Surface: webmcp`. The header labels the route in the
	 * log and leaves the card's wording out of get_about, and grants nothing, so a client that
	 * forges it gains nothing but a different label and less text. A WebMCP call comes from the visitor's own browser, so its address is a person's and
	 * is stored at network level, like a page view.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array{surface: string, anonymize: bool}
	 */
	private static function route_of( WP_REST_Request $request ) {
		$webmcp = 'webmcp' === strtolower( trim( (string) $request->get_header( 'x_mmsar_surface' ) ) );
		return array(
			'surface'   => $webmcp ? MMSAR_Agent_Log::SURFACE_WEBMCP : MMSAR_Agent_Log::SURFACE_MCP,
			'anonymize' => $webmcp,
		);
	}

	/**
	 * Attach the rate-limit headers to a response.
	 *
	 * The point of publishing these is that an agent can pace itself instead of discovering the
	 * limit by hitting it. Both spellings go out: the individual `RateLimit-*` fields that most
	 * clients already read, and the single structured `RateLimit` field the IETF draft settled on.
	 * They cost a few dozen bytes and neither is universal yet.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param array            $rate     State from consume_rate_limit().
	 * @return WP_REST_Response The response.
	 */
	private static function with_rate_limit_headers( WP_REST_Response $response, $rate ) {
		if ( $rate['limit'] <= 0 ) {
			return $response;
		}

		$response->header( 'RateLimit-Limit', (string) $rate['limit'] );
		$response->header( 'RateLimit-Remaining', (string) $rate['remaining'] );
		$response->header( 'RateLimit-Reset', (string) $rate['reset'] );
		$response->header( 'RateLimit-Policy', sprintf( '"mcp";q=%d;w=%d', $rate['limit'], self::RATE_WINDOW ) );
		$response->header( 'RateLimit', sprintf( '"mcp";r=%d;t=%d', $rate['remaining'], $rate['reset'] ) );

		// Retry-After is the one a client is obliged to honor, so it goes out only when there is
		// actually something to wait for.
		if ( ! $rate['allowed'] ) {
			$response->header( 'Retry-After', (string) $rate['reset'] );
		}

		return $response;
	}

	/**
	 * Declines a GET on the MCP endpoint.
	 *
	 * @return WP_REST_Response Response.
	 */
	public static function decline_stream() {
		MMSAR_Agent_Log::record( 'MCP GET (declined)' );

		// Carries the rate-limit headers even though it refuses the request. A client — or a scanner
		// — that only ever issues a GET against this URL would otherwise never see the policy, and
		// the policy applies to it just the same.
		return self::with_rate_limit_headers(
			self::error_response( null, -32000, 'This server does not offer a server-initiated event stream. POST JSON-RPC requests to this URL instead.', 405 ),
			self::peek_rate_limit()
		);
	}

	/**
	 * The limiter's current state without consuming a request from it.
	 *
	 * Used on responses that report the policy but are not themselves a call worth counting.
	 *
	 * @return array{allowed:bool,limit:int,remaining:int,reset:int} Limiter state.
	 */
	private static function peek_rate_limit() {
		$limit = (int) apply_filters( 'mmsar_mcp_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return array(
				'allowed'   => true,
				'limit'     => 0,
				'remaining' => 0,
				'reset'     => 0,
			);
		}
		$key   = 'mmsar_mcp_rl_' . md5( MMSAR_Agent_Log::client_ip() );
		$count = (int) get_transient( $key );
		return array(
			'allowed'   => $count < $limit,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $count ),
			'reset'     => self::seconds_until_reset( $key ),
		);
	}

	/**
	 * Route one JSON-RPC message to its handler.
	 *
	 * Each message is answered in the era it speaks (see MMSAR_MCP_Era): a legacy message exactly as
	 * before, a 2026-07-28 message checked against its headers and given the result fields that
	 * revision requires.
	 *
	 * @param array                $message Decoded JSON-RPC message.
	 * @param array                $route   How the request arrived, from route_of().
	 * @param WP_REST_Request|null $request The HTTP request, for the 2026-07-28 header checks.
	 * @return array|null The response object, or null for a notification.
	 */
	private static function dispatch( $message, $route, $request = null ) {
		self::$status = 200;
		$method       = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params       = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		// A message with no id is a notification: it is acted on, but never answered. Note that
		// id 0 and id "" are both legitimate ids, so this tests for the key, not for truthiness.
		$id              = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$is_notification = ! array_key_exists( 'id', $message );

		// Logged here rather than in handle(), because handle() sees one HTTP request while this
		// sees one JSON-RPC message, and a batch is several messages in one request. Logged before
		// the switch so an unknown method is recorded too — a client asking for something this
		// server does not implement is worth knowing about, and it is the case least likely to be
		// reported any other way.
		$detail    = self::log_detail( $method, $params );
		$arguments = self::log_arguments( $detail, $params );
		// The arguments join the throttle key, so two calls to one tool asking for different things
		// are two entries. Every stored value is bounded by a check against the site except an
		// opted-in search query, and the rate limit above caps how many of those one caller can send.
		MMSAR_Agent_Log::record(
			$route['surface'],
			$detail,
			true,
			$route['anonymize'],
			'' === $arguments ? null : $detail . ' ' . $arguments,
			$arguments
		);

		$header_version = $request ? trim( (string) $request->get_header( 'mcp_protocol_version' ) ) : '';
		$modern         = '' !== MMSAR_MCP_Era::declared_version( $params ) || in_array( $header_version, MMSAR_MCP_Era::MODERN, true );
		if ( $modern && ! $is_notification ) {
			$failure = MMSAR_MCP_Era::validate(
				$method,
				$params,
				array(
					'mcp-protocol-version' => $header_version,
					'mcp-method'           => $request ? (string) $request->get_header( 'mcp_method' ) : '',
					'mcp-name'             => $request ? (string) $request->get_header( 'mcp_name' ) : '',
				)
			);
			if ( null !== $failure ) {
				// A dual-era server lists its legacy versions too, so a client can fall back to one.
				if ( MMSAR_MCP_Era::ERROR_UNSUPPORTED_VERSION === $failure['code'] ) {
					$failure['data']['supported'] = self::supported_versions();
				}
				self::$status = $failure['status'];
				return self::rpc_error( $id, $failure['code'], $failure['message'], $failure['data'] );
			}
		}

		switch ( $method ) {
			case 'initialize':
				$result = self::initialize( $params );
				break;
			case 'server/discover':
				// Answered in both eras: a dual-era client sends it first as its probe, and the
				// result's shape is the same whoever asks.
				$result = self::discover();
				break;
			case 'ping':
				$result = new stdClass();
				break;
			case 'tools/list':
				$result = array( 'tools' => self::tools() );
				// The card's send tool is listed only to a client that renders cards, and only
				// while the site owner has switched it on; see send_tool().
				if ( self::send_available( $params ) ) {
					$result['tools'][] = self::send_tool();
				}
				break;
			case 'tools/call':
				$result = self::call_tool( $params, $route );
				break;
			case 'resources/list':
				$result = array( 'resources' => self::resources() );
				break;
			case 'resources/read':
				$result = self::read_resource( $params );
				break;
			case 'prompts/list':
				$result = array( 'prompts' => array() );
				break;
			default:
				// Notifications the client sends that need no action — notifications/initialized,
				// notifications/cancelled — land here and are correctly answered with nothing.
				if ( $is_notification ) {
					return null;
				}
				if ( $modern ) {
					self::$status = 404;
				}
				return self::rpc_error( $id, -32601, 'Unknown method: ' . $method );
		}

		if ( $is_notification ) {
			return null;
		}
		if ( $result instanceof WP_Error ) {
			return self::rpc_error( $id, -32602, $result->get_error_message() );
		}
		if ( $modern && 'server/discover' !== $method ) {
			$result = MMSAR_MCP_Era::decorate( $result, $method, self::server_info(), self::cache_ttl_ms() );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * The server/discover result (MCP 2026-07-28).
	 *
	 * Carries every version this server speaks, legacy ones included, so a dual-era client can pick
	 * the newest both sides share.
	 *
	 * @return array Result.
	 */
	private static function discover() {
		$capabilities = array(
			'tools'     => new stdClass(),
			'resources' => new stdClass(),
		);
		if ( self::ui_enabled() ) {
			$capabilities['extensions'] = array(
				'io.modelcontextprotocol/ui' => array( 'mimeTypes' => array( self::UI_MIME ) ),
			);
		}

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'resultType'        => 'complete',
			'supportedVersions' => self::supported_versions(),
			'capabilities'      => $capabilities,
			'_meta'             => array( MMSAR_MCP_Era::META_SERVER_INFO => self::server_info() ),
			'instructions'      => self::instructions( $site_name ),
			'ttlMs'             => self::cache_ttl_ms(),
			'cacheScope'        => 'public',
		);
	}

	/**
	 * Every protocol version this server speaks, newest first: the modern ones, then the legacy.
	 *
	 * @return string[]
	 */
	public static function supported_versions() {
		return array_values( array_unique( array_merge( MMSAR_MCP_Era::MODERN, self::SUPPORTED_PROTOCOLS ) ) );
	}

	/**
	 * This server's name, title and version, as initialize and every modern result report them.
	 *
	 * @return array Implementation info.
	 */
	private static function server_info() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return array(
			'name'    => 'mmsar-' . sanitize_title( $site_name ),
			'title'   => $site_name,
			'version' => MMSAR_VERSION,
		);
	}

	/**
	 * How long a client may treat a tool list, resource list, resource or discovery result as fresh.
	 *
	 * @return int Milliseconds, never negative.
	 */
	private static function cache_ttl_ms() {
		/**
		 * Filters the freshness hint (`ttlMs`) on cacheable MCP results, in milliseconds.
		 *
		 * Lower it while developing, so a changed tool or card reaches a client sooner; 0 means
		 * "re-fetch every time". Only clients speaking MCP 2026-07-28 or later read it.
		 *
		 * @param int $ttl_ms Default 900000 (15 minutes).
		 */
		return max( 0, (int) apply_filters( 'mmsar_mcp_cache_ttl_ms', MMSAR_MCP_Era::DEFAULT_TTL_MS ) );
	}

	/**
	 * The log detail for one JSON-RPC message.
	 *
	 * The method alone answers most of the question, except for the method that matters most:
	 * every actual use of this server arrives as tools/call, so a log of bare method names would
	 * show that the tools were used and never which ones. The tool name is appended for that case,
	 * and only that case.
	 *
	 * Both halves come from the request body, so both are bounded before being stored: the method
	 * is trimmed to a length that fits any real one, and the tool name is only used when it matches
	 * a tool actually registered here — an unknown name is discarded rather than written through.
	 *
	 * @param string $method The JSON-RPC method.
	 * @param array  $params The message params.
	 * @return string Detail string for the log.
	 */
	private static function log_detail( $method, $params ) {
		$method = mb_substr( '' === $method ? '(none)' : $method, 0, 60 );

		if ( 'tools/call' !== $method || ! isset( $params['name'] ) || ! is_string( $params['name'] ) ) {
			return $method;
		}

		$name = $params['name'];
		if ( self::TOOL_SEND === $name ) {
			return $method . ': ' . self::TOOL_SEND;
		}
		foreach ( self::tools() as $tool ) {
			if ( isset( $tool['name'] ) && $tool['name'] === $name ) {
				return $method . ': ' . mb_substr( $name, 0, 120 );
			}
		}

		return $method . ': (unknown tool)';
	}

	/**
	 * The stored arguments for one JSON-RPC message.
	 *
	 * For a tools/call that named one of this server's own tools, which log_detail() has already
	 * established. A tool added through the `mmsar_mcp_tools` filter is logged by name only: its
	 * arguments mean nothing to the checks here, so none of them could be stored safely. For a
	 * resources/read, the address asked for, checked against the ones this server advertises.
	 *
	 * @param string $detail The detail log_detail() produced.
	 * @param array  $params The message params.
	 * @return string Stored argument line, possibly empty.
	 */
	private static function log_arguments( $detail, $params ) {
		$own = array( 'search_content', 'get_content', 'list_content', 'get_site_overview' );
		foreach ( $own as $tool ) {
			if ( 'tools/call: ' . $tool === $detail ) {
				return MMSAR_MCP_Arguments::summarize( $tool, isset( $params['arguments'] ) ? $params['arguments'] : array() );
			}
		}
		if ( 'tools/call: get_about' === $detail ) {
			return self::draft_log_argument( isset( $params['arguments'] ) ? $params['arguments'] : array() );
		}
		if ( 'resources/read' === $detail ) {
			$current = array();
			foreach ( self::resources() as $resource ) {
				$current[ self::ui_template_base( $resource['uri'] ) ] = $resource['uri'];
			}
			return self::resource_log_argument( isset( $params['uri'] ) ? $params['uri'] : '', $current );
		}
		return '';
	}

	/**
	 * The stored argument for a get_about call: which draft fields the model filled in, never their text.
	 *
	 * Recorded so the log shows whether a client uses the card's prefill at all. The draft is the
	 * person's own words about how to reach the site owner, so only the field names are kept.
	 *
	 * @param mixed $arguments The call's arguments.
	 * @return string `draft=subject,message`, `draft=none`, or '' when no draft was offered.
	 */
	public static function draft_log_argument( $arguments ) {
		if ( ! is_array( $arguments ) || ! array_key_exists( 'draft', $arguments ) ) {
			return '';
		}
		$fields = array_keys( self::sanitize_draft( $arguments['draft'] ) );
		return 'draft=' . ( $fields ? implode( ',', $fields ) : 'none' );
	}

	/**
	 * The stored argument for a resources/read: which address was asked for.
	 *
	 * Recorded so the log shows whether a client is reading a card at the address it is advertised
	 * under today, an older fingerprint it saved, or the bare address. Only addresses this server
	 * advertises are stored as sent; anything else is stored as a placeholder, so a caller cannot
	 * write text of its choosing into the log.
	 *
	 * @param mixed                $uri     The requested URI.
	 * @param array<string,string> $current Base URI => the address it is advertised under now.
	 * @return string `uri=…`, or '' when no URI was sent.
	 */
	public static function resource_log_argument( $uri, $current ) {
		if ( ! is_string( $uri ) || '' === $uri ) {
			return '';
		}
		$base = self::ui_template_base( $uri );
		if ( ! isset( $current[ $base ] ) ) {
			return 'uri=(unknown)';
		}
		if ( $uri === $current[ $base ] ) {
			return 'uri=' . $uri . ( $base === $uri ? '' : ' (current)' );
		}
		if ( $base === $uri ) {
			return 'uri=' . $uri . ' (unversioned)';
		}
		if ( 1 === preg_match( '/^\?v=[0-9a-f]{12}$/', substr( $uri, strlen( $base ) ) ) ) {
			return 'uri=' . $uri . ' (stale)';
		}
		return 'uri=' . $base . '?(other)';
	}

	/**
	 * The initialize handshake.
	 *
	 * @param array $params Client params.
	 * @return array Result object.
	 */
	private static function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::SUPPORTED_PROTOCOLS, true ) ? $requested : self::PROTOCOL_VERSION;

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				// listChanged is false on both: this server pushes nothing, so promising to notify
				// a client when the tool or resource list changes would be a promise it cannot keep.
				'tools'     => array( 'listChanged' => false ),
				'resources' => array(
					'listChanged' => false,
					'subscribe'   => false,
				),
			),
			'serverInfo'      => self::server_info(),
			'instructions'    => self::instructions( $site_name ),
		);
	}

	/**
	 * The instructions block returned at initialize.
	 *
	 * This is the one place the server can tell a model how to use it well, and it is read once per
	 * session rather than per call, so it is worth being specific about which tool suits which job.
	 *
	 * @param string $site_name Site title.
	 * @return string Instructions.
	 */
	private static function instructions( $site_name ) {
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines       = array();

		$lines[] = 'Read-only access to the published content of ' . $site_name . ' (' . home_url( '/' ) . ').';
		if ( '' !== trim( $description ) ) {
			$lines[] = '';
			$lines[] = $description;
		}
		$lines[] = '';
		$lines[] = 'Start with `search_content` when you have a topic, or `list_content` when you want to see what is here. Both take an optional `topic` (a category or tag slug, listed by `get_site_overview`) and return URLs; pass one to `get_content` to read the full text as Markdown. Do not fetch the site\'s HTML pages — `get_content` returns the same content already parsed. For who runs the site and how to reach them, call `get_about`.';
		$lines[] = '';
		$lines[] = 'Everything here is public and already published. There is no draft or private content behind this server, and nothing here can modify the site.';

		return implode( "\n", $lines );
	}

	/**
	 * Build a JSON-RPC error object.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data, such as the supported versions.
	 * @return array Error response.
	 */
	private static function rpc_error( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * A JSON-RPC error wrapped in an HTTP response with a matching status.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response Response.
	 */
	private static function error_response( $id, $code, $message, $status ) {
		return new WP_REST_Response( self::rpc_error( $id, $code, $message ), $status );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Whether this caller is still under the per-IP limit.
	 *
	 * A counter in a transient, which is the coarsest possible approach and the right one here:
	 * the point is to stop one client from running unbounded queries against a public endpoint,
	 * not to meter usage precisely. Sites that want real limits have a WAF for it.
	 *
	 * A courtesy brake rather than a security control, and worth being clear about why: the caller
	 * is identified through MMSAR_Agent_Log::client_ip(), which prefers `CF-Connecting-IP` so that a
	 * site behind Cloudflare limits real callers rather than collapsing all of them into the one
	 * edge address — and a header like that can be forged by anything not actually behind Cloudflare.
	 * That trade is acceptable here only because everything past this check is published content
	 * served from cache-friendly queries; nothing behind it needs protecting.
	 *
	 * @return array{allowed:bool,limit:int,remaining:int,reset:int} Limiter state for this request.
	 */
	private static function consume_rate_limit() {
		/**
		 * Filters the number of MCP calls allowed per IP per minute.
		 *
		 * Return 0 to disable rate limiting entirely — appropriate only where something in front
		 * of WordPress is already limiting this endpoint.
		 *
		 * @param int $limit Requests per window. Default 60.
		 */
		$limit = (int) apply_filters( 'mmsar_mcp_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return array(
				'allowed'   => true,
				'limit'     => 0,
				'remaining' => 0,
				'reset'     => 0,
			);
		}

		// Shared with the agent log rather than re-derived, so the two can never disagree about who
		// a caller is.
		$key   = 'mmsar_mcp_rl_' . md5( MMSAR_Agent_Log::client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return array(
				'allowed'   => false,
				'limit'     => $limit,
				'remaining' => 0,
				// The transient's own TTL is the honest answer to "when may I retry", rather than a
				// flat window length that would be wrong for every request after the first.
				'reset'     => self::seconds_until_reset( $key ),
			);
		}

		// The window restarts from the first request in it rather than sliding. A burst can
		// therefore straddle two windows; that is an acceptable trade for one transient write.
		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return array(
			'allowed'   => true,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - ( $count + 1 ) ),
			'reset'     => self::seconds_until_reset( $key ),
		);
	}

	/**
	 * Seconds left in the current window, read from the transient's own expiry.
	 *
	 * Falls back to the full window when the timeout option is not readable — an external object
	 * cache need not expose one. Over-reporting is the safe direction: a client waits slightly
	 * longer than it had to, rather than retrying into another 429.
	 *
	 * @param string $key Transient key.
	 * @return int Seconds.
	 */
	private static function seconds_until_reset( $key ) {
		$timeout = get_option( '_transient_timeout_' . $key );
		if ( ! $timeout ) {
			return self::RATE_WINDOW;
		}
		return max( 1, (int) $timeout - time() );
	}

	// -------------------------------------------------------------------------
	// Tools
	// -------------------------------------------------------------------------

	/**
	 * The tool definitions returned by tools/list.
	 *
	 * @return array[] Tool definitions.
	 */
	public static function tools() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$tools = array(
			array(
				'name'        => 'search_content',
				'title'       => 'Search content',
				'description' => 'Full-text search across the published posts and pages of ' . $site_name . '. Returns titles, URLs and excerpts, newest-relevant first. Use this when you know roughly what you are looking for; pass a returned url to get_content to read the whole thing.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'     => array(
							'type'        => 'string',
							'description' => 'Words to search for. Keep it to a few meaningful terms — this is a keyword search, not a semantic one, so a full sentence usually matches less than its two most specific words.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Restrict to one content type. Omit to search everything.',
							'enum'        => array_values( mmsar_get_enabled_post_types() ),
						),
						'topic'     => array(
							'type'        => 'string',
							'description' => 'Restrict to one topic: the slug of a category, tag or other topic on this site, such as `wordpress`. Call get_site_overview with sections ["topics"] to see the topics and their slugs.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'How many results to return, 1-' . self::MAX_LIMIT . '. Default 10.',
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_content',
				'title'       => 'Read a page',
				'description' => 'Returns the full text of one published post or page as Markdown, along with its title, URL and publication date. Give it a URL from search_content or list_content, or any URL on this site.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'The page\'s URL on this site. A path such as `/about/` works too.',
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'list_content',
				'title'       => 'List recent content',
				'description' => 'Lists published content newest first, without searching. Use this to see what is on the site, to find the most recent writing, to see everything on one topic, or to page through everything.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Which content type to list. Omit for all of them.',
							'enum'        => array_values( mmsar_get_enabled_post_types() ),
						),
						'topic'     => array(
							'type'        => 'string',
							'description' => 'Restrict to one topic: the slug of a category, tag or other topic on this site, such as `wordpress`. Call get_site_overview with sections ["topics"] to see the topics and their slugs.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'How many items to return, 1-' . self::MAX_LIMIT . '. Default 20.',
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
						),
						'offset'    => array(
							'type'        => 'integer',
							'description' => 'How many items to skip, for paging through the whole list.',
							'minimum'     => 0,
						),
					),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
			array(
				'name'        => 'get_site_overview',
				'title'       => 'Site overview',
				'description' => 'What this site is, who runs it, how much content it has, which topics it covers, and which machine-readable endpoints it publishes. Call this once at the start if you have no context on the site.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'sections' => array(
							'type'        => 'array',
							'description' => 'Which parts of the overview to return. Omit for all of them. Ask for a subset when you already have context and only need one thing — `endpoints` alone is a fraction of the tokens of the whole overview.',
							'items'       => array(
								'type' => 'string',
								'enum' => MMSAR_MCP_Arguments::SECTIONS,
							),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
			),
		);

		$tools[] = array(
			'name'        => 'get_about',
			'title'       => 'About this site',
			'description' => 'Who runs ' . $site_name . ' and how to reach them: name, role, a short bio, profile links and the contact page. Use this when someone asks who is behind the site, about the person or organization, or how to get in touch.' . ( self::ui_enabled() && '' !== self::contact_api() ? self::ABOUT_DRAFT_NOTE : '' ),
			'inputSchema' => self::about_input_schema(),
			'annotations' => array(
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'openWorldHint'   => false,
			),
		);

		// MCP Apps binds a tool to a template through `_meta`. Each template is attached only to the
		// tools whose results it was built for: the result list to the two list tools, the about card
		// to get_about. A single page's text and the site overview have no card, and pointing them at
		// one would give a host permission to render the wrong thing.
		if ( self::ui_enabled() ) {
			$templates = array(
				'search_content' => self::ui_uri( self::UI_RESULTS ),
				'list_content'   => self::ui_uri( self::UI_RESULTS ),
				'get_about'      => self::ui_uri( self::UI_ABOUT ),
			);
			foreach ( $tools as $index => $tool ) {
				if ( ! isset( $templates[ $tool['name'] ] ) ) {
					continue;
				}
				$tools[ $index ]['_meta'] = self::ui_tool_meta( $templates[ $tool['name'] ] );
			}
		}

		/**
		 * Filters the tools this server advertises.
		 *
		 * Adding a tool here also means handling it on `mmsar_mcp_call_tool`, which is where the
		 * dispatcher hands off anything it does not recognize. Anything added must stay read-only
		 * and unauthenticated-safe: this endpoint has no user behind it.
		 *
		 * @param array[] $tools Tool definitions.
		 */
		$filtered = apply_filters( 'mmsar_mcp_tools', $tools );
		return is_array( $filtered ) ? $filtered : $tools;
	}

	/**
	 * Dispatch a tools/call.
	 *
	 * @param array $params Call params.
	 * @param array $route  How the request arrived, from route_of().
	 * @return array|WP_Error Tool result, or an error for a malformed call.
	 */
	private static function call_tool( $params, $route = array() ) {
		$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		switch ( $name ) {
			case 'search_content':
				return self::tool_search( $arguments );
			case 'get_content':
				return self::tool_get( $arguments );
			case 'list_content':
				return self::tool_list( $arguments );
			case 'get_site_overview':
				return self::tool_overview( $arguments );
			case 'get_about':
				return self::tool_about( $arguments, $params, isset( $route['surface'] ) && MMSAR_Agent_Log::SURFACE_WEBMCP === $route['surface'] );
			case self::TOOL_SEND:
				return self::tool_send( $arguments, $params );
		}

		/**
		 * Filters the result of a tools/call this server does not handle itself.
		 *
		 * Return an MCP tool result array to handle the call. Anything else leaves it unhandled and
		 * the client gets an unknown-tool error.
		 *
		 * @param mixed  $result    Null until an integration handles the call.
		 * @param string $name      Tool name.
		 * @param array  $arguments Tool arguments.
		 */
		$result = apply_filters( 'mmsar_mcp_call_tool', null, $name, $arguments );
		if ( is_array( $result ) ) {
			return $result;
		}

		return new WP_Error( 'mmsar_unknown_tool', 'Unknown tool: ' . $name );
	}

	/**
	 * Handle the search_content tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_search( $arguments ) {
		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
		if ( '' === $query ) {
			return self::tool_error( 'Provide a `query` — one or more words to search for.' );
		}

		$topic = self::requested_topic( $arguments );
		if ( isset( $topic['error'] ) ) {
			return $topic['error'];
		}

		$args = array(
			'post_type'           => self::requested_post_types( $arguments ),
			'post_status'         => 'publish',
			's'                   => $query,
			'posts_per_page'      => self::requested_limit( $arguments, 10 ),
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( isset( $topic['tax_query'] ) ) {
			$args['tax_query'] = $topic['tax_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The topic filter is a taxonomy filter by definition; the result set is capped by MAX_LIMIT.
		}
		$posts = get_posts( MMSAR_Noindex::exclude( $args ) );

		$in_topic = isset( $topic['label'] ) ? ' in ' . $topic['label'] : '';
		if ( empty( $posts ) ) {
			return self::tool_text(
				'No published content' . $in_topic . ' matched "' . $query . '".' . "\n\n"
				. 'This is a keyword search, so try fewer or more specific words, or call list_content to see what is on the site.'
			);
		}

		$lines = array( 'Found ' . count( $posts ) . ' result' . ( 1 === count( $posts ) ? '' : 's' ) . $in_topic . ' for "' . $query . '":', '' );
		$items = array();
		foreach ( $posts as $post ) {
			$item    = self::ui_item( $post );
			$items[] = $item;
			$lines[] = '## ' . $item['title'];
			$lines[] = 'URL: ' . $item['url'];
			$lines[] = 'Type: ' . $item['type'] . ' · Published: ' . $item['date'];
			if ( '' !== $item['excerpt'] ) {
				$lines[] = '';
				$lines[] = $item['excerpt'];
			}
			$lines[] = '';
		}
		$lines[] = 'Pass any of these URLs to get_content to read the full text.';

		return self::with_ui_items( self::tool_text( implode( "\n", $lines ) ), $items );
	}

	/**
	 * Handle the get_content tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_get( $arguments ) {
		$url = isset( $arguments['url'] ) ? trim( (string) $arguments['url'] ) : '';
		if ( '' === $url ) {
			return self::tool_error( 'Provide a `url` — the address of a page on this site.' );
		}

		$post = self::resolve( $url );
		if ( ! $post ) {
			return self::tool_error(
				'No published content at ' . $url . '.' . "\n\n"
				. 'Check the URL is on this site (' . home_url( '/' ) . '), or use search_content to find the right page.'
			);
		}

		$markdown = get_post_meta( $post->ID, '_llmmd_content', true );
		if ( empty( $markdown ) ) {
			$markdown = MMSAR_Converter::convert_post( $post->ID );
			if ( ! empty( $markdown ) ) {
				mmsar_store_markdown( $post->ID, $markdown );
			}
		}
		if ( empty( $markdown ) ) {
			return self::tool_error( 'That page exists but has no readable text content.' );
		}

		// Returned as-is. The converter already opens every document with YAML frontmatter carrying
		// the title, canonical URL and dates, so adding a header here would state all of it twice —
		// and put a stray `---` immediately above the frontmatter's own opening `---`.
		return self::tool_text( trim( $markdown ) );
	}

	/**
	 * Handle the list_content tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private static function tool_list( $arguments ) {
		$offset = isset( $arguments['offset'] ) ? max( 0, (int) $arguments['offset'] ) : 0;
		$limit  = self::requested_limit( $arguments, 20 );

		$topic = self::requested_topic( $arguments );
		if ( isset( $topic['error'] ) ) {
			return $topic['error'];
		}

		$args = array(
			'post_type'           => self::requested_post_types( $arguments ),
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'offset'              => $offset,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'has_password'        => false,
			'ignore_sticky_posts' => true,
		);
		if ( isset( $topic['tax_query'] ) ) {
			$args['tax_query'] = $topic['tax_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The topic filter is a taxonomy filter by definition; the page size is capped by MAX_LIMIT.
		}
		$query = new WP_Query( MMSAR_Noindex::exclude( $args ) );

		$in_topic = isset( $topic['label'] ) ? ' in ' . $topic['label'] : '';
		if ( ! $query->have_posts() ) {
			if ( 0 !== $offset ) {
				return self::tool_text( 'No more content past that offset.' );
			}
			return self::tool_text( '' === $in_topic ? 'This site has no published content in those types.' : 'This site has no published content' . $in_topic . ' in those types.' );
		}

		$total = (int) $query->found_posts;
		$lines = array(
			'Showing ' . count( $query->posts ) . ' of ' . $total . ' published item' . ( 1 === $total ? '' : 's' ) . $in_topic
			. ( $offset ? ', starting at ' . $offset : '' ) . ':',
			'',
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$item    = self::ui_item( $post );
			$items[] = $item;
			$lines[] = '- **' . $item['title'] . '** (' . $item['type'] . ', ' . $item['date'] . ')';
			$lines[] = '  ' . $item['url'];
		}

		$shown = $offset + count( $query->posts );
		if ( $shown < $total ) {
			$lines[] = '';
			$lines[] = 'Call list_content again with offset=' . $shown . ' for the next page.';
		}

		return self::with_ui_items( self::tool_text( implode( "\n", $lines ) ), $items );
	}

	/**
	 * Handle the get_about tool.
	 *
	 * The record comes from MMSAR_About, which reads only what the site already publishes about its
	 * owner. While UI is on the same record goes out as `structuredContent` for the about card; the
	 * photo is not in it, because it is built into the card template instead (see ui_about_html()).
	 *
	 * @param array $arguments Tool arguments: an optional `draft` for the card's form.
	 * @param array $params    Request params, which say whether the client renders cards.
	 * @param bool  $browser   Whether the call came through the WebMCP bridge, where no card shows.
	 * @return array Tool result.
	 */
	private static function tool_about( $arguments = array(), $params = array(), $browser = false ) {
		$about = MMSAR_About::get();
		$text  = MMSAR_About::to_text( $about );
		$mode  = $browser ? '' : self::contact_mode( $params );
		$draft = ! $browser && isset( $arguments['draft'] ) ? self::sanitize_draft( $arguments['draft'] ) : array();
		if ( '' !== $mode ) {
			$text .= "\n\n" . self::about_contact_note( (bool) $draft, self::client_has_ui( $params ) );
		}
		$result = self::tool_text( $text );
		if ( self::ui_enabled() ) {
			$result['structuredContent'] = array(
				'about'   => $about,
				'contact' => array(
					'mode'  => $mode,
					'draft' => (object) $draft,
				),
			);
		}
		return $result;
	}

	/**
	 * What get_about tells the model about the card's contact form.
	 *
	 * Only a client that declared MCP Apps is known to show the card. Older clients have no way to
	 * declare it, so the wording has to hold whether or not a card appears.
	 *
	 * @param bool $has_draft Whether a draft was passed.
	 * @param bool $has_ui    Whether the client declared MCP Apps support.
	 * @return string
	 */
	public static function about_contact_note( $has_draft, $has_ui ) {
		$filled = $has_draft ? ', filled in with your draft' : '';
		if ( $has_ui ) {
			return 'The card shows a contact form' . $filled . '. The person reviews it and presses Send themselves; nothing is sent until they do. Do not say a message was sent unless the card tells you so.';
		}
		return 'If this client shows the about card, it has a contact form' . $filled . ': the person reviews it and presses Send themselves. If it does not, give them the contact page. Do not say a message was sent unless the card tells you so.';
	}

	/**
	 * Handle the get_site_overview tool.
	 *
	 * @param array $arguments Tool arguments. Optional 'sections' narrows what comes back.
	 * @return array Tool result.
	 */
	private static function tool_overview( $arguments = array() ) {
		$all    = MMSAR_MCP_Arguments::SECTIONS;
		$wanted = isset( $arguments['sections'] ) && is_array( $arguments['sections'] )
			? array_intersect( $all, array_map( 'strval', $arguments['sections'] ) )
			: $all;

		// An unrecognized selection would otherwise return an empty document, which reads as "this
		// site has nothing" rather than "you asked for a section that does not exist".
		if ( empty( $wanted ) ) {
			$wanted = $all;
		}

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines     = array( '# ' . $site_name, '' );

		if ( in_array( 'about', $wanted, true ) ) {
			$lines = array_merge( $lines, self::overview_about_lines() );
		}
		if ( in_array( 'content', $wanted, true ) ) {
			$lines = array_merge( $lines, self::overview_content_lines() );
		}
		if ( in_array( 'topics', $wanted, true ) ) {
			$lines = array_merge( $lines, self::overview_topic_lines() );
		}
		if ( in_array( 'endpoints', $wanted, true ) ) {
			$lines = array_merge( $lines, self::overview_endpoint_lines() );
		}
		if ( in_array( 'actions', $wanted, true ) ) {
			$lines = array_merge( $lines, self::overview_action_lines() );
		}

		return self::tool_text( rtrim( implode( "\n", $lines ) ) );
	}

	/**
	 * The overview's "about" section: what this site is and where it lives.
	 *
	 * @return string[] Lines.
	 */
	private static function overview_about_lines() {
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines = array();
		if ( '' !== trim( $description ) ) {
			$lines[] = $description;
			$lines[] = '';
		}
		$owner = self::owner_line( MMSAR_About::get() );
		if ( '' !== $owner ) {
			$lines[] = $owner;
			$lines[] = '';
		}
		$lines[] = 'URL: ' . home_url( '/' );
		$lines[] = '';

		return $lines;
	}

	/**
	 * Who is behind the site, in one line, pointing at get_about for the rest.
	 *
	 * Built from the same data get_about returns, so the overview and the card never disagree. A
	 * site with no person or organization behind it (kind `site`) gets no line: its name is already
	 * the overview's heading.
	 *
	 * @param array $about MMSAR_About::get() output.
	 * @return string Line, or ''.
	 */
	public static function owner_line( $about ) {
		$kind = isset( $about['kind'] ) ? (string) $about['kind'] : 'site';
		$name = isset( $about['name'] ) ? trim( (string) $about['name'] ) : '';
		if ( '' === $name || ! in_array( $kind, array( 'person', 'organization' ), true ) ) {
			return '';
		}
		$role = isset( $about['role'] ) ? trim( (string) $about['role'] ) : '';
		$who  = 'person' === $kind
			? 'Run by ' . $name . ( '' !== $role ? ', ' . $role : '' )
			: 'Published by ' . $name;
		return $who . '. Call get_about for a bio, profiles and how to get in touch.';
	}

	/**
	 * The overview's "content" section: how much of each type is published.
	 *
	 * @return string[] Lines.
	 */
	private static function overview_content_lines() {
		$lines = array( '## Content', '' );

		foreach ( mmsar_get_enabled_post_types() as $post_type ) {
			$object  = get_post_type_object( $post_type );
			$counts  = wp_count_posts( $post_type );
			$label   = $object ? $object->labels->name : $post_type;
			$lines[] = '- ' . $label . ': ' . ( isset( $counts->publish ) ? (int) $counts->publish : 0 ) . ' published';
		}
		$lines[] = '';

		return $lines;
	}

	/**
	 * The overview's "topics" section: the slugs the `topic` argument accepts.
	 *
	 * Busiest first and capped, because a site with hundreds of tags would otherwise spend most of
	 * the overview on the long tail. An agent that passes a slug not shown here still gets a
	 * useful answer: either the results, or an error naming the topics that do exist.
	 *
	 * @return string[] Lines.
	 */
	private static function overview_topic_lines() {
		$topics = self::topics( self::OVERVIEW_TOPICS );
		if ( empty( $topics ) ) {
			return array();
		}

		$lines = array( '## Topics', '', 'Pass a slug as `topic` to list_content or search_content.', '' );
		foreach ( $topics as $topic ) {
			$lines[] = '- `' . $topic['slug'] . '` — ' . $topic['name'] . ' (' . $topic['count'] . ')';
		}
		if ( self::OVERVIEW_TOPICS === count( $topics ) ) {
			$lines[] = '';
			$lines[] = 'The ' . self::OVERVIEW_TOPICS . ' most used are shown; other topics work too.';
		}
		$lines[] = '';

		return $lines;
	}

	/**
	 * The overview's "endpoints" section: the machine-readable documents this site publishes.
	 *
	 * @return string[] Lines.
	 */
	private static function overview_endpoint_lines() {
		$lines = array( '## Machine-readable endpoints', '' );

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$lines[] = '- ' . home_url( '/llms.txt' ) . ' — one-line index of the site';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$lines[] = '- ' . home_url( '/llms-full.txt' ) . ' — the entire site as one document';
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$lines[] = '- ' . MMSAR_OpenAPI::url() . ' — OpenAPI description of the HTTP API';
		}
		if ( mmsar_feature_enabled( 'auth_md' ) ) {
			$lines[] = '- ' . MMSAR_Auth_Md::url() . ' — how to authenticate. No credentials are needed.';
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$lines[] = '- ' . home_url( '/.well-known/api-catalog' ) . ' — endpoint catalog';
		}

		$sitemap = mmsar_get_sitemap_url();
		if ( $sitemap ) {
			$lines[] = '- ' . $sitemap . ' — XML sitemap';
		}
		$lines[] = '';

		return $lines;
	}

	/**
	 * The overview's "actions" section: endpoints an agent can call rather than only read.
	 *
	 * These are the ones an agent most wants to know about, and the registry is the only place they
	 * are described. Returns nothing when the site exposes none, rather than an empty heading.
	 *
	 * @return string[] Lines.
	 */
	private static function overview_action_lines() {
		$callable = array();

		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( empty( $endpoint['methods'] ) || array( 'GET' ) === $endpoint['methods'] ) {
				continue;
			}
			$callable[] = '- **' . $endpoint['title'] . '** — `' . implode( ', ', $endpoint['methods'] ) . ' ' . $endpoint['href'] . '`'
				. ( '' !== $endpoint['description'] ? ' — ' . $endpoint['description'] : '' );
		}

		if ( empty( $callable ) ) {
			return array();
		}

		return array_merge(
			array( '## Endpoints you can call', '', 'These are outside this MCP server — call them over plain HTTP.', '' ),
			$callable,
			array( '' )
		);
	}

	// -------------------------------------------------------------------------
	// Resources
	// -------------------------------------------------------------------------

	/**
	 * The resources returned by resources/list.
	 *
	 * The same documents the site already publishes over HTTP, offered through the connection the
	 * client already has. A client that can attach a resource to its context does not have to spend
	 * a tool call to read the site's index.
	 *
	 * @return array[] Resource descriptors.
	 */
	public static function resources() {
		$resources = array();

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$resources[] = array(
				'uri'         => home_url( '/llms.txt' ),
				'name'        => 'llms.txt',
				'title'       => 'Content index',
				'description' => 'One line per page: what exists on this site and what each page is about.',
				'mimeType'    => 'text/plain',
			);
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$resources[] = array(
				'uri'         => home_url( '/llms-full.txt' ),
				'name'        => 'llms-full.txt',
				'title'       => 'Full site text',
				'description' => 'Every published post and page, in full, as one Markdown document. Large — prefer search_content unless you need everything.',
				'mimeType'    => 'text/plain',
			);
		}

		foreach ( self::ui_resources() as $ui ) {
			$resources[] = $ui;
		}

		return $resources;
	}

	/**
	 * Handle a resources/read request.
	 *
	 * Only the URIs this server advertises are readable. Fetching an arbitrary URI on request would
	 * turn an unauthenticated public endpoint into a request proxy, which is how a public MCP
	 * server becomes an SSRF vector against whatever the site can reach.
	 *
	 * @param array $params Request params.
	 * @return array|WP_Error Result, or an error.
	 */
	private static function read_resource( $params ) {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		// ui:// templates are generated here rather than fetched — they have no HTTP address, and
		// wp_remote_get() on a ui:// scheme would simply fail. Any fingerprint is accepted and the
		// current template served: an address a client saved before the template changed still
		// works, it just gets today's card.
		$base = self::ui_template_base( $uri );
		if ( in_array( $base, self::ui_templates(), true ) && self::ui_enabled() ) {
			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => self::UI_MIME,
						'text'     => self::ui_html( $base ),
						// No `csp` key on purpose: omitting it gets the host's most restrictive
						// default (no network at all), which is exactly what this panel needs. Links
						// open through ui/open-link, so not even the site's own origin is required.
						// No host border either: every result already draws its own.
						'_meta'    => array(
							'ui' => self::ui_resource_meta( $base ),
						),
					),
				),
			);
		}

		foreach ( self::resources() as $resource ) {
			if ( $resource['uri'] !== $uri || 0 === strpos( $uri, 'ui://' ) ) {
				continue;
			}

			$response = wp_remote_get( $uri, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'mmsar_resource_unavailable', 'Could not read ' . $uri . ': ' . $response->get_error_message() );
			}

			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => $resource['mimeType'],
						'text'     => wp_remote_retrieve_body( $response ),
					),
				),
			);
		}

		return new WP_Error( 'mmsar_unknown_resource', 'Unknown resource: ' . $uri . '. Call resources/list for the readable ones.' );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Wrap text in an MCP tool result.
	 *
	 * @param string $text Result text.
	 * @return array Tool result.
	 */
	private static function tool_text( $text ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);
	}

	/**
	 * A tool result that reports a problem with the call.
	 *
	 * Returned as a successful JSON-RPC response with isError set, not as a protocol error: the
	 * distinction in MCP is that a protocol error means the call could not be made, while this
	 * means the call was made and did not find anything. Only the second is something a model can
	 * usefully react to, so it must reach the model rather than the client's error handler.
	 *
	 * @param string $text Explanation, including what to try instead.
	 * @return array Tool result.
	 */
	private static function tool_error( $text ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * The post types a call asked for, always intersected with the enabled ones.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string[] Post types.
	 */
	private static function requested_post_types( $arguments ) {
		$enabled = mmsar_get_enabled_post_types();
		if ( empty( $arguments['post_type'] ) ) {
			return $enabled;
		}
		$requested = sanitize_key( (string) $arguments['post_type'] );
		return in_array( $requested, $enabled, true ) ? array( $requested ) : $enabled;
	}

	/**
	 * The result count a call asked for, clamped.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $fallback  Default when unspecified.
	 * @return int Limit.
	 */
	private static function requested_limit( $arguments, $fallback ) {
		$limit = isset( $arguments['limit'] ) ? (int) $arguments['limit'] : $fallback;
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * The taxonomies a `topic` slug is looked up in.
	 *
	 * Every public taxonomy attached to an enabled post type that has an admin screen. The admin
	 * screen test is what leaves out `post_format`, which is public but is a display setting rather
	 * than a subject.
	 *
	 * @return string[] Taxonomy names.
	 */
	public static function topic_taxonomies() {
		$names = array();
		foreach ( get_object_taxonomies( mmsar_get_enabled_post_types(), 'objects' ) as $name => $taxonomy ) {
			if ( ! empty( $taxonomy->public ) && ! empty( $taxonomy->show_ui ) ) {
				$names[] = (string) $name;
			}
		}

		/**
		 * Filters the taxonomies the MCP `topic` argument matches against.
		 *
		 * @param string[] $names Taxonomy names.
		 */
		$filtered = apply_filters( 'mmsar_mcp_topic_taxonomies', $names );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'taxonomy_exists' ) ) : $names;
	}

	/**
	 * The terms a topic slug names, across every topic taxonomy.
	 *
	 * A slug can name a term in more than one taxonomy — a "news" category and a "news"
	 * tag — and an agent asking for the topic means both.
	 *
	 * @param string $raw Slug as the caller sent it.
	 * @return WP_Term[] Matching terms, possibly none.
	 */
	private static function topic_terms( $raw ) {
		$slug = sanitize_title( (string) $raw );
		if ( '' === $slug ) {
			return array();
		}
		$terms = array();
		foreach ( self::topic_taxonomies() as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$terms[] = $term;
			}
		}
		return $terms;
	}

	/**
	 * The canonical slug of a topic, or null when the site has no such topic.
	 *
	 * Public for the agent log, which stores a topic only once it is known to exist here.
	 *
	 * @param string $raw Slug as the caller sent it.
	 * @return string|null
	 */
	public static function topic_slug( $raw ) {
		$terms = self::topic_terms( $raw );
		return empty( $terms ) ? null : (string) $terms[0]->slug;
	}

	/**
	 * Resolve the `topic` argument for a query.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Empty when no topic was asked for; 'tax_query' and 'label' for a known one;
	 *               'error' (a tool result) for an unknown one.
	 */
	private static function requested_topic( $arguments ) {
		if ( ! isset( $arguments['topic'] ) || ! is_scalar( $arguments['topic'] ) || '' === trim( (string) $arguments['topic'] ) ) {
			return array();
		}

		$terms = self::topic_terms( (string) $arguments['topic'] );
		if ( empty( $terms ) ) {
			// An empty result would read as "nothing on that topic", which is a different and
			// misleading answer. Naming the topics that exist lets the agent correct itself in one step.
			$known = wp_list_pluck( self::topics( self::OVERVIEW_TOPICS ), 'slug' );
			return array(
				'error' => self::tool_error(
					'This site has no topic "' . sanitize_title( (string) $arguments['topic'] ) . '".'
					. ( empty( $known ) ? ' It has no topics at all; omit `topic`.' : "\n\n" . 'Topics on this site, most used first: ' . implode( ', ', $known ) . '.' )
				),
			);
		}

		$tax_query = array( 'relation' => 'OR' );
		foreach ( $terms as $term ) {
			$tax_query[] = array(
				'taxonomy' => $term->taxonomy,
				'field'    => 'term_id',
				'terms'    => array( (int) $term->term_id ),
			);
		}

		return array(
			'tax_query' => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Built here, passed to a capped query by the callers.
			'label'     => '"' . html_entity_decode( $terms[0]->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"',
		);
	}

	/**
	 * The site's topics, busiest first, merged by slug across taxonomies.
	 *
	 * Counts are WordPress's own term counts, so they can include posts this server does not list
	 * (noindex ones, for instance). They are a guide to what the site covers, not a promise of how
	 * many results a call will return.
	 *
	 * @param int $limit Most topics to return.
	 * @return array[] Each with 'slug', 'name' and 'count'.
	 */
	private static function topics( $limit ) {
		$taxonomies = self::topic_taxonomies();
		if ( empty( $taxonomies ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit * 2, // Headroom for a slug shared by two taxonomies.
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$by_slug = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			if ( isset( $by_slug[ $term->slug ] ) ) {
				$by_slug[ $term->slug ]['count'] += (int) $term->count;
				continue;
			}
			$by_slug[ $term->slug ] = array(
				'slug'  => (string) $term->slug,
				'name'  => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'count' => (int) $term->count,
			);
		}
		$topics = array_values( $by_slug );
		usort(
			$topics,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return array_slice( $topics, 0, $limit );
	}

	/**
	 * The path of the post get_content would serve for a URL, or null.
	 *
	 * Public for the agent log, which stores this rather than what the caller typed.
	 *
	 * @param string $url URL or path, as get_content accepts it.
	 * @return string|null
	 */
	public static function served_path( $url ) {
		$post = self::resolve( trim( (string) $url ) );
		if ( ! $post ) {
			return null;
		}
		$path = wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * Resolve a URL or path to a published post on this site.
	 *
	 * @param string $url URL or path.
	 * @return WP_Post|null The post, or null.
	 */
	private static function resolve( $url ) {
		// A bare path is the form a model most often produces, so accept it — but resolve it
		// against this site, which also means a URL pointing anywhere else finds nothing.
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
			return null;
		}

		// Tolerate a .md URL: it is what llms.txt and the Markdown mirror hand out, so a model that
		// read either will sometimes pass one back.
		$url = preg_replace( '#\.md(/)?$#', '$1', $url );

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			// url_to_postid() does not resolve the front page, which is a legitimate thing to ask for.
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( '' === $path || 'index' === $path ) {
				$post_id = (int) get_option( 'page_on_front' );
			}
		}
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return null;
		}
		if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * A post's title, with entities decoded.
	 *
	 * @param WP_Post $post Post.
	 * @return string Title.
	 */
	private static function title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * A short plain-text excerpt for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return string Excerpt.
	 */
	private static function excerpt( $post ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 45, '…' );
		return trim( html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	// -------------------------------------------------------------------------
	// /.well-known/mcp.json
	// -------------------------------------------------------------------------

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/mcp\.json$', 'index.php?mmsar_mcp_manifest=1', 'top' );
		add_rewrite_rule( '^\.well-known/mcp/server-card\.json$', 'index.php?mmsar_mcp_server_card=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_mcp_manifest';
		$vars[] = 'mmsar_mcp_server_card';
		return $vars;
	}

	/**
	 * Serve the discovery manifest.
	 *
	 * MCP has no ratified well-known location — the spec covers the connection, not how a client
	 * that has only a domain name finds one. /.well-known/mcp.json is the de-facto convention that
	 * agent directories and readiness scanners actually look for, so that is where this goes. The
	 * document names both the transport and the endpoint, so a client that ignores the shape
	 * entirely can still pull the URL out of it.
	 *
	 * @return void
	 */
	public static function serve_manifest() {
		if ( get_query_var( 'mmsar_mcp_server_card' ) ) {
			self::serve_server_card();
		}
		if ( ! get_query_var( 'mmsar_mcp_manifest' ) ) {
			return;
		}

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$manifest = array(
			'name'                      => 'mmsar-' . sanitize_title( $site_name ),
			'title'                     => $site_name,
			'description'               => 'Read-only access to the published content of ' . $site_name . ': search it, list it, and read any page as Markdown.',
			'version'                   => MMSAR_VERSION,
			// The newest revision spoken, then every one. `protocolVersion` stayed at 2025-06-18
			// until 1.68.0, a year behind what the endpoint actually answers.
			'protocolVersion'           => MMSAR_MCP_Era::MODERN[0],
			'supportedProtocolVersions' => self::supported_versions(),
			'transport'                 => array(
				'type' => 'streamable-http',
				'url'  => self::endpoint_url(),
			),
			// Repeated at the top level because clients and directories disagree about where the
			// endpoint lives in this document, and both spellings are cheap.
			'url'                       => self::endpoint_url(),
			'authentication'            => array(
				'type'        => 'none',
				'description' => 'Public and read-only. No credentials required.',
			),
			'capabilities'              => array(
				'tools'     => array_column( self::tools(), 'name' ),
				'resources' => array_column( self::resources(), 'uri' ),
			),
			'websiteUrl'                => home_url( '/' ),
		);

		// A directory listing this server shows whatever branding it can find, and a name with no
		// mark next to it reads as an unfinished entry. The site icon is the one image every
		// WordPress site is asked for during setup, so it is the one most likely to actually exist.
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$manifest['icons'] = array(
				array(
					'src'   => $icon,
					'sizes' => '512x512',
				),
			);
		}

		/**
		 * Filters the MCP discovery manifest before it is served.
		 *
		 * @param array $manifest The manifest, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_mcp_manifest', $manifest );
		if ( is_array( $filtered ) ) {
			$manifest = $filtered;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'mcp.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * /.well-known/mcp/server-card.json
	 *
	 * The same server described a second time, in a second shape, at a second address. Worth being
	 * clear that this is a convention rather than a ratified spec — the MCP specification covers the
	 * connection, not discovery, and this location comes from agent directories rather than from the
	 * protocol. It is published because the cost is a few hundred bytes of already-derived data and
	 * the benefit is being listed by directories that look here and nowhere else.
	 *
	 * The difference from mcp.json is the tool detail: a card carries the full tool list with
	 * descriptions, so a directory can show what the server does without opening a transport.
	 *
	 * Not the Server Card the MCP Server Card extension (SEP-2127, final) defines: that one is
	 * served at `<endpoint>/server-card` (serve_spec_server_card()), and AI Catalog points there.
	 * This older shape and address stay because readiness scanners fetch them and the WebMCP
	 * bridge registers its tools from this card's tool list.
	 *
	 * @return void
	 */
	private static function serve_server_card() {
		$card = self::server_card();

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'mcp server-card.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * A tool as the server card lists it: get_about without the card's contact form.
	 *
	 * MCP clients take their tools from tools/list. This card's readers are the WebMCP bridge,
	 * webmcp.json and scanners, and no card shows in a browser, so the draft has nowhere to go.
	 *
	 * @param array $tool Tool definition.
	 * @return array
	 */
	public static function without_card_form( $tool ) {
		if ( 'get_about' !== $tool['name'] ) {
			return $tool;
		}
		$tool['description']               = str_replace( self::ABOUT_DRAFT_NOTE, '', $tool['description'] );
		$tool['inputSchema']['properties'] = (object) array();
		return $tool;
	}

	/**
	 * The server card, filtered, exactly as /.well-known/mcp/server-card.json serves it.
	 *
	 * Public since 1.58.0, because the WebMCP bridge registers its tools from this card at run time
	 * and MMSAR_WebMCP::browser_tools() must describe the same tools: reading the card here rather
	 * than the raw tool list means a `mmsar_mcp_server_card` filter reaches both.
	 *
	 * @return array
	 */
	public static function server_card() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$tools = array();
		foreach ( self::tools() as $tool ) {
			$tool    = self::without_card_form( $tool );
			$tools[] = array(
				'name'        => $tool['name'],
				'title'       => isset( $tool['title'] ) ? $tool['title'] : $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
			// Carried since 1.57.0 because the WebMCP bridge reads tools from this card and
			// registers only the read-only ones in the browser.
			if ( isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ) {
				$tools[ count( $tools ) - 1 ]['annotations'] = $tool['annotations'];
			}
		}

		$card = array(
			'name'                      => 'mmsar-' . sanitize_title( $site_name ),
			'title'                     => $site_name,
			'description'               => 'Read-only access to the published content of ' . $site_name . ': search it, list it, and read any page as Markdown.',
			'version'                   => MMSAR_VERSION,
			'protocolVersion'           => MMSAR_MCP_Era::MODERN[0],
			'supportedProtocolVersions' => self::supported_versions(),
			'serverUrl'                 => self::endpoint_url(),
			'transport'                 => array(
				'type' => 'streamable-http',
				'url'  => self::endpoint_url(),
			),
			'authentication'            => array( 'type' => 'none' ),
			'tools'                     => $tools,
			'resources'                 => self::resources(),
			'websiteUrl'                => home_url( '/' ),
			'documentationUrl'          => mmsar_feature_enabled( 'llms_txt' ) ? home_url( '/llms.txt' ) : home_url( '/' ),
		);

		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$card['icons'] = array(
				array(
					'src'   => $icon,
					'sizes' => '512x512',
				),
			);
		}

		/**
		 * Filters the MCP server card before it is served.
		 *
		 * @param array $card The server card, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_mcp_server_card', $card );
		return is_array( $filtered ) ? $filtered : $card;
	}

	/**
	 * The schema every SEP-2127 Server Card names.
	 */
	const SERVER_CARD_SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

	/**
	 * The media type of a SEP-2127 Server Card.
	 */
	const SERVER_CARD_MIME = 'application/mcp-server-card+json';

	/**
	 * GET <endpoint>/server-card: the Server Card in the MCP Server Card extension's shape.
	 *
	 * @return WP_REST_Response
	 */
	public static function serve_spec_server_card() {
		MMSAR_Agent_Log::record( 'mcp server-card (SEP-2127)' );

		$card = self::spec_server_card_for_site();
		$body = wp_json_encode( $card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$response = new WP_REST_Response( $card, 200 );
		$response->header( 'Content-Type', self::SERVER_CARD_MIME . '; charset=UTF-8' );
		// The CORS headers SEP-2127 requires of a hosted card.
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, If-None-Match' );
		$response->header( 'Access-Control-Expose-Headers', 'ETag' );
		$response->header( 'ETag', '"' . md5( (string) $body ) . '"' );
		$response->header( 'Cache-Control', 'public, max-age=900' );
		return $response;
	}

	/**
	 * This site's SEP-2127 Server Card, filtered.
	 *
	 * @return array
	 */
	public static function spec_server_card_for_site() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$card      = self::spec_server_card(
			array(
				'host'     => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
				'title'    => $site_name,
				'version'  => MMSAR_VERSION,
				'endpoint' => self::endpoint_url(),
				'website'  => home_url( '/' ),
				'icon'     => (string) get_site_icon_url( 512 ),
				'versions' => self::supported_versions(),
			)
		);

		/**
		 * Filters the SEP-2127 Server Card served at `<endpoint>/server-card`.
		 *
		 * @param array $card The card, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_mcp_spec_server_card', $card );
		return is_array( $filtered ) ? $filtered : $card;
	}

	/**
	 * Build a Server Card in the MCP Server Card extension's shape (SEP-2127, schema v1).
	 *
	 * The extension's rules, each applied here: `name` is reverse-DNS with exactly one slash;
	 * `description` and `title` are at most 100 characters; there is no tool or resource list
	 * (those come from the live connection); protocol versions are declared per remote, and must
	 * agree with what `server/discover` answers.
	 *
	 * @param array $site `host`, `title`, `version`, `endpoint`, `website`, `icon` (may be ''),
	 *                    `versions` (newest first).
	 * @return array Card.
	 */
	public static function spec_server_card( $site ) {
		$host   = strtolower( preg_replace( '/[^a-zA-Z0-9.-]/', '', (string) $site['host'] ) );
		$labels = array_filter(
			explode( '.', $host ),
			static function ( $label ) {
				return '' !== $label;
			}
		);
		$name   = ( $labels ? implode( '.', array_reverse( $labels ) ) : 'unknown' ) . '/mmsar';
		$title  = trim( (string) $site['title'] );

		$card = array(
			'$schema'     => self::SERVER_CARD_SCHEMA,
			'name'        => $name,
			'version'     => (string) $site['version'],
			'description' => self::clip( 'Search and read the published content of ' . ( '' !== $title ? $title : $host ) . '.', 100 ),
		);
		if ( '' !== $title ) {
			$card['title'] = self::clip( $title, 100 );
		}
		$card['websiteUrl'] = (string) $site['website'];
		if ( '' !== (string) $site['icon'] ) {
			$card['icons'] = array(
				array(
					'src'   => (string) $site['icon'],
					'sizes' => array( '512x512' ),
				),
			);
		}
		$card['remotes'] = array(
			array(
				'type'                      => 'streamable-http',
				'url'                       => (string) $site['endpoint'],
				'supportedProtocolVersions' => array_values( $site['versions'] ),
			),
		);
		return $card;
	}

	/**
	 * Shorten text to a character limit, ending on an ellipsis when cut.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function clip( $text, $limit ) {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
	}

	// -------------------------------------------------------------------------
	// The about card's contact form
	// -------------------------------------------------------------------------

	/**
	 * The tool the about card's form can send through, when that path is on.
	 */
	const TOOL_SEND = 'send_message';

	/**
	 * The `_meta` key a 2026-07-28 request carries its client capabilities in.
	 */
	const META_CLIENT_CAPS = 'io.modelcontextprotocol/clientCapabilities';

	/**
	 * The MCP Apps extension identifier, as a client declares it.
	 */
	const UI_EXTENSION = 'io.modelcontextprotocol/ui';

	/**
	 * Longest draft field get_about accepts, in characters. The site's own endpoint has the final say.
	 */
	const DRAFT_LIMITS = array(
		'name'    => 100,
		'email'   => 254,
		'subject' => 200,
		'message' => 5000,
	);

	/**
	 * The get_about input: nothing required, and an optional draft for the card's form.
	 *
	 * The draft is only offered when the card has a form to put it in.
	 *
	 * @return array JSON Schema.
	 */
	private static function about_input_schema() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
		if ( ! self::ui_enabled() || '' === self::contact_api() ) {
			return $schema;
		}
		$fields = array();
		foreach ( self::DRAFT_LIMITS as $field => $max ) {
			$fields[ $field ] = array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		}
		$schema['properties'] = array(
			'draft' => array(
				'type'                 => 'object',
				'description'          => 'Optional. When the person wants to get in touch, a draft for the about card\'s contact form, where the client shows the card: their name and email if they gave them, a short subject, and the message in their words. The person reviews and edits it, and only they can send it.',
				'properties'           => $fields,
				'additionalProperties' => false,
			),
		);
		return $schema;
	}

	/**
	 * Clean a draft from get_about's arguments: known fields only, plain text, capped.
	 *
	 * @param mixed $draft Raw draft.
	 * @return array<string,string> Non-empty fields.
	 */
	public static function sanitize_draft( $draft ) {
		if ( ! is_array( $draft ) ) {
			return array();
		}
		$out = array();
		foreach ( self::DRAFT_LIMITS as $field => $max ) {
			if ( ! isset( $draft[ $field ] ) || ! is_string( $draft[ $field ] ) ) {
				continue;
			}
			$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $draft[ $field ] );
			$value = 'message' === $field ? trim( (string) $value ) : trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
			$value = mb_substr( $value, 0, $max );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * The contact endpoint the site has given the about card, or ''.
	 *
	 * @return string
	 */
	private static function contact_api() {
		$about = MMSAR_About::get();
		return isset( $about['contact_api'] ) ? (string) $about['contact_api'] : '';
	}

	/**
	 * The REST route behind a contact endpoint URL on this site, or '' when it is not one.
	 *
	 * The send tool hands the message to that route inside WordPress rather than over HTTP, so it
	 * only works for an endpoint this site's own REST API serves.
	 *
	 * @param string $api      Endpoint URL.
	 * @param string $rest_url This site's REST base URL.
	 * @return string Route such as `/miriamschwab/v1/contact`, or ''.
	 */
	public static function contact_route( $api, $rest_url ) {
		$api  = (string) $api;
		$base = rtrim( (string) $rest_url, '/' ) . '/';
		if ( '' === $api || 0 !== strpos( $api, $base ) ) {
			return '';
		}
		$route = trim( substr( $api, strlen( $base ) ), '/' );
		if ( '' === $route || 1 !== preg_match( '#^[a-z0-9_-]+(/[a-z0-9_.-]+)+$#i', $route ) ) {
			return '';
		}
		return '/' . $route;
	}

	/**
	 * Whether a request comes from a client that declared MCP Apps support.
	 *
	 * Only a 2026-07-28 request carries its capabilities; a legacy one declared them once, in an
	 * `initialize` this stateless server does not keep, so it reads as no.
	 *
	 * @param mixed $params Request params.
	 * @return bool
	 */
	public static function client_has_ui( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['_meta'][ self::META_CLIENT_CAPS ] ) || ! is_array( $params['_meta'][ self::META_CLIENT_CAPS ] ) ) {
			return false;
		}
		$caps = $params['_meta'][ self::META_CLIENT_CAPS ];
		return isset( $caps['extensions'] ) && is_array( $caps['extensions'] ) && array_key_exists( self::UI_EXTENSION, $caps['extensions'] );
	}

	/**
	 * How the card's form sends, given what is configured and who is asking.
	 *
	 * @param string $api          Contact endpoint URL, or ''.
	 * @param string $route        Its REST route on this site, or ''.
	 * @param bool   $send_enabled Whether the site owner switched the send tool on.
	 * @param bool   $has_ui       Whether the client declared MCP Apps support.
	 * @return string 'tool', 'direct', or '' for no form.
	 */
	public static function pick_contact_mode( $api, $route, $send_enabled, $has_ui ) {
		if ( '' === (string) $api ) {
			return '';
		}
		if ( $send_enabled && $has_ui && '' !== (string) $route ) {
			return 'tool';
		}
		return 'direct';
	}

	/**
	 * How the card's form sends for this request: through the site's endpoint from the person's own
	 * connection (`direct`), through this server's send tool (`tool`), or not at all ('').
	 *
	 * @param array $params Request params.
	 * @return string
	 */
	private static function contact_mode( $params ) {
		if ( ! self::ui_enabled() ) {
			return '';
		}
		$api  = self::contact_api();
		$mode = self::pick_contact_mode( $api, self::contact_route( $api, rest_url() ), mmsar_feature_enabled( 'mcp_ui_send' ), self::client_has_ui( $params ) );

		/**
		 * Filters how the about card's contact form sends.
		 *
		 * @param string $mode   'tool', 'direct' or ''.
		 * @param array  $params The request's params.
		 */
		$filtered = apply_filters( 'mmsar_mcp_contact_mode', $mode, $params );
		return in_array( $filtered, array( 'tool', 'direct', '' ), true ) ? $filtered : $mode;
	}

	/**
	 * Whether the send tool may be listed and called for this request.
	 *
	 * @param array $params Request params.
	 * @return bool
	 */
	private static function send_available( $params ) {
		return 'tool' === self::contact_mode( $params );
	}

	/**
	 * The send tool's definition.
	 *
	 * The only tool on this server that changes anything, and it is fenced three ways: it is off
	 * unless the site owner switches it on; it is listed only to a client that declared MCP Apps
	 * support, whose host MUST NOT show an app-only tool to the model (SEP-1865); and it only hands a
	 * message to the site's own contact endpoint, which applies its own token, validation and rate
	 * limits. A client that ignores the visibility rule can still reach it, which is why the
	 * endpoint's limits, not this definition, are the real guard.
	 *
	 * @return array Tool.
	 */
	private static function send_tool() {
		$fields = array();
		foreach ( self::DRAFT_LIMITS as $field => $max ) {
			$fields[ $field ] = array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		}
		$fields['idempotency_key'] = array(
			'type'      => 'string',
			'minLength' => 16,
			'maxLength' => 255,
		);
		return array(
			'name'        => self::TOOL_SEND,
			'title'       => 'Send the contact form',
			'description' => 'Sends the about card\'s contact form to the site owner. Called by the card when the person presses Send; not for the model.',
			'inputSchema' => array(
				'type'                 => 'object',
				'properties'           => $fields,
				'required'             => array( 'name', 'email', 'message', 'idempotency_key' ),
				'additionalProperties' => false,
			),
			'annotations' => array(
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => true,
			),
			'_meta'       => array(
				'ui' => array( 'visibility' => array( 'app' ) ),
			),
		);
	}

	/**
	 * Handle the send tool: pass the form to the site's contact endpoint, inside WordPress.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $params    Request params.
	 * @return array Tool result.
	 */
	private static function tool_send( $arguments, $params ) {
		if ( ! self::send_available( $params ) ) {
			return self::tool_error( 'Sending from the card is not available here. The contact page is: ' . self::contact_page_hint() );
		}
		$route = self::contact_route( self::contact_api(), rest_url() );
		$form  = self::sanitize_draft( $arguments );
		$key   = isset( $arguments['idempotency_key'] ) && is_string( $arguments['idempotency_key'] ) ? $arguments['idempotency_key'] : '';

		$token_response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$token_data     = $token_response->get_data();
		$token          = is_array( $token_data ) && isset( $token_data['submit_token'] ) ? (string) $token_data['submit_token'] : '';
		if ( '' === $token ) {
			return self::send_result( 0, array( 'message' => 'The contact endpoint did not issue a token.' ) );
		}

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		if ( '' !== $key ) {
			$request->set_header( 'idempotency_key', $key );
		}
		$request->set_body(
			(string) wp_json_encode(
				array_merge(
					$form,
					array(
						'agent'        => 'MCP App card (send_message tool)',
						'submit_token' => $token,
					)
				)
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		return self::send_result( $response->get_status(), is_array( $data ) ? $data : array() );
	}

	/**
	 * A send outcome, as text for the record and structured data for the card.
	 *
	 * @param int   $status HTTP status the contact endpoint answered with (0 when it could not be reached).
	 * @param array $data   Its response body.
	 * @return array Tool result.
	 */
	private static function send_result( $status, $data ) {
		$sent    = $status >= 200 && $status < 300;
		$message = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';
		$out     = array(
			'sent'   => $sent,
			'status' => (int) $status,
		);
		if ( '' !== $message ) {
			$out['message'] = $message;
		}
		$extra = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		foreach ( array( 'fields', 'retry_after', 'replayed' ) as $key ) {
			if ( isset( $extra[ $key ] ) ) {
				$out[ $key ] = $extra[ $key ];
			}
		}
		$result                      = $sent
			? self::tool_text( 'The message was sent.' )
			: self::tool_error( 'The message was not sent' . ( '' !== $message ? ': ' . $message : '.' ) );
		$result['structuredContent'] = $out;
		return $result;
	}

	/**
	 * Where a person can write instead, for error text.
	 *
	 * @return string
	 */
	private static function contact_page_hint() {
		$about = MMSAR_About::get();
		return '' !== $about['contact_url'] ? $about['contact_url'] : home_url( '/' );
	}

	/**
	 * The `_meta` that binds a tool to its card template, in every spelling a host may read.
	 *
	 * @param string $uri Template URI, fingerprinted.
	 * @return array
	 */
	public static function ui_tool_meta( $uri ) {
		return array(
			// MCP Apps (SEP-1865, 2026-01-26): `ui.resourceUri` is the whole binding. The
			// `preferred` flag this used to carry is in no spec and no host reads it.
			'ui'                    => array(
				'resourceUri' => $uri,
			),
			// The flat key from earlier MCP Apps drafts. The official SDK's registerAppTool()
			// still writes it beside `ui.resourceUri`, so hosts built on that SDK may read it.
			'ui/resourceUri'        => $uri,
			// The OpenAI Apps SDK spelling of the same binding, kept for hosts still on it.
			'openai/outputTemplate' => $uri,
		);
	}

	/**
	 * The `_meta.ui` of a template resource.
	 *
	 * The about card is allowed one network origin, the contact endpoint's, so its form can send
	 * directly; with no endpoint it gets none, which is the host's most restrictive default.
	 *
	 * @param string $base Unversioned template URI.
	 * @return array
	 */
	private static function ui_resource_meta( $base ) {
		$meta   = array( 'prefersBorder' => false );
		$origin = self::UI_ABOUT === $base ? self::origin_of( self::contact_api() ) : '';
		if ( '' !== $origin ) {
			$meta['csp'] = array( 'connectDomains' => array( $origin ) );
		}
		return $meta;
	}

	/**
	 * Scheme, host and port of an http(s) URL, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	// -------------------------------------------------------------------------
	// MCP Apps (ui:// resources)
	// -------------------------------------------------------------------------

	/**
	 * The URI of the search-results UI template.
	 */
	const UI_RESULTS = 'ui://mmsar/search-results.html';

	/**
	 * The URI of the get_about card template.
	 */
	const UI_ABOUT = 'ui://mmsar/about.html';

	/**
	 * The media type of an MCP Apps HTML template.
	 *
	 * SEP-1865 (MCP Apps, stable 2026-01-26) requires exactly this value. Until 1.68.0 the template
	 * was served as `text/html+skybridge`, the OpenAI Apps SDK's pre-standard type, which an MCP Apps
	 * host does not treat as a template at all.
	 */
	const UI_MIME = 'text/html;profile=mcp-app';

	/**
	 * The MCP Apps protocol revision the templates speak to their host.
	 */
	const UI_PROTOCOL = '2026-01-26';

	/**
	 * Where the footer on every card links: this plugin's page.
	 */
	const UI_PLUGIN_URL = 'https://miriamschwab.me/plugins/make-my-site-agent-ready';

	/**
	 * Each template's unversioned address.
	 *
	 * @return string[] Base URIs.
	 */
	private static function ui_templates() {
		return array( self::UI_RESULTS, self::UI_ABOUT );
	}

	/**
	 * A template's HTML, built once per request.
	 *
	 * Listing tools, listing resources and reading one all need it (the first two only for its
	 * fingerprint), and building the about card reads the photo from disk.
	 *
	 * @param string $base Unversioned template URI.
	 * @return string HTML, or '' for an unknown URI.
	 */
	private static function ui_html( $base ) {
		static $built = array();
		if ( ! isset( $built[ $base ] ) ) {
			switch ( $base ) {
				case self::UI_RESULTS:
					$built[ $base ] = self::ui_results_html();
					break;
				case self::UI_ABOUT:
					$built[ $base ] = self::ui_about_html();
					break;
				default:
					return '';
			}
		}
		return $built[ $base ];
	}

	/**
	 * The address a template is advertised under: its base URI plus a fingerprint of its contents.
	 *
	 * @param string $base Unversioned template URI.
	 * @return string Versioned URI.
	 */
	private static function ui_uri( $base ) {
		return self::ui_template_uri( $base, self::ui_html( $base ) );
	}

	/**
	 * Add a content fingerprint to a template URI.
	 *
	 * Hosts cache templates by address and do not re-read them: on 2026-10-29 Claude desktop called
	 * get_about with a new theme's card live and never sent resources/read, so it showed the old
	 * card, ignoring the 15-minute `ttlMs` on every result. A host has to fetch an address it has
	 * never seen, so the address changes whenever the HTML does, whether a plugin update or a theme's
	 * `mmsar_mcp_ui_css` changed it.
	 *
	 * @param string $base Unversioned template URI.
	 * @param string $html The template's HTML.
	 * @return string `$base?v=<12 hex>`.
	 */
	public static function ui_template_uri( $base, $html ) {
		return $base . '?v=' . substr( md5( (string) $html ), 0, 12 );
	}

	/**
	 * The unversioned template URI behind a (possibly versioned) one.
	 *
	 * @param string $uri Requested URI.
	 * @return string URI without its query string.
	 */
	public static function ui_template_base( $uri ) {
		$uri = (string) $uri;
		$q   = strpos( $uri, '?' );
		return false === $q ? $uri : substr( $uri, 0, $q );
	}

	/**
	 * Whether to advertise MCP Apps UI resources.
	 *
	 * Off unless asked for. Until 1.68.0 the template had never been rendered by a real MCP Apps
	 * host, and it turned out not to work in one: wrong media type, no structured data in the tool
	 * result, and a handshake no host speaks. 1.68.0 rebuilt it against the published spec, verified
	 * it through a host harness that follows the spec message for message, and on 2026-10-29 it
	 * rendered from the live site in the Claude desktop app. Other clients are unchecked, so the
	 * toggle stays opt-in.
	 *
	 * A host that ignores `_meta` entirely still gets the normal text result, which is why declaring
	 * the templates is safe rather than merely cheap.
	 *
	 * @return bool
	 */
	public static function ui_enabled() {
		/**
		 * Filters whether the MCP server advertises MCP Apps UI resources.
		 *
		 * @param bool $enabled Whether UI resources are advertised. Follows the `mcp_ui` feature toggle.
		 */
		return (bool) apply_filters( 'mmsar_mcp_ui_enabled', mmsar_feature_enabled( 'mcp_ui' ) );
	}

	/**
	 * One search or list result, in the shape both the text result and the template read.
	 *
	 * Built once per post so the text a model reads and the card a person sees can never disagree.
	 *
	 * @param WP_Post $post Post.
	 * @return array{title: string, url: string, type: string, date: string, excerpt: string}
	 */
	private static function ui_item( $post ) {
		return array(
			'title'   => self::title( $post ),
			'url'     => (string) get_permalink( $post ),
			'type'    => $post->post_type,
			'date'    => (string) get_the_date( 'Y-m-d', $post ),
			'excerpt' => self::excerpt( $post ),
		);
	}

	/**
	 * Add the results as `structuredContent`, for the template, when UI is switched on.
	 *
	 * MCP Apps hands a template the tool result as-is, and `structuredContent` is the part meant for
	 * a UI. Without it the template had nothing to render. Added only while UI is on, so a site that
	 * never opted in returns exactly the result it always has.
	 *
	 * @param array   $result Tool result from tool_text().
	 * @param array[] $items  Results from ui_item().
	 * @return array Tool result.
	 */
	private static function with_ui_items( $result, $items ) {
		if ( self::ui_enabled() ) {
			$result['structuredContent'] = array( 'results' => array_values( $items ) );
		}
		return $result;
	}

	/**
	 * The search-results card.
	 *
	 * @return string HTML.
	 */
	private static function ui_results_html() {
		$site_name = esc_html( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$body = '<header class="mmsar-site">' . $site_name . '</header>'
			. '<div id="out" class="mmsar-results"><p class="mmsar-empty">Waiting for results…</p></div>';

		$render = <<<'JS'
  var out = document.getElementById('out');
  function render(items) {
    if (!items.length) { out.innerHTML = '<p class="mmsar-empty">No results.</p>'; return; }
    out.innerHTML = '<ol>' + items.map(function (r) {
      var url = safeUrl(r.url);
      var name = esc(r.title || url);
      var desc = esc(r.excerpt || '');
      var meta = esc([r.type, r.date].filter(Boolean).join(' · '));
      return '<li>' + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + name + '</a>' : '<strong>' + name + '</strong>')
        + (desc ? '<p>' + desc + '</p>' : '')
        + (meta ? '<div class="mmsar-meta">' + meta + '</div>' : '')
        + '</li>';
    }).join('') + '</ol>';
  }
  function itemsFrom(result) {
    var data = result && (result.structuredContent || result);
    return data && Array.isArray(data.results) ? data.results : null;
  }
  mmsar.show = function (result) {
    var items = itemsFrom(result);
    if (items) { render(items); return; }
    out.innerHTML = '<p class="mmsar-empty">' + (result && result.isError ? 'The search did not run. Details are in the conversation.' : 'The results are in the conversation.') + '</p>';
  };
  mmsar.fromOpenAI = itemsFrom;
  mmsar.renderOpenAI = render;
JS;

		return self::ui_document( 'results', 'Results', $body, $render );
	}

	/**
	 * The get_about card.
	 *
	 * The photo is built into the template as a data URI rather than sent with each tool result: a
	 * host fetches a template once and may cache it, the card needs no network, and the photo stays
	 * out of anything a host might hand to the model.
	 *
	 * @return string HTML.
	 */
	private static function ui_about_html() {
		$photo = MMSAR_About::photo_data_uri();
		$photo = '' !== $photo
			? '<img class="mmsar-photo" src="' . esc_attr( $photo ) . '" alt="" width="96" height="96">'
			: '';

		$body = '<div class="mmsar-about">' . $photo
			. '<div class="mmsar-about-text"><h1 id="name" class="mmsar-name"></h1><p id="role" class="mmsar-role" hidden></p></div></div>'
			. '<p id="bio" class="mmsar-bio" hidden></p>'
			. '<ul id="profiles" class="mmsar-profiles" hidden></ul>'
			. '<div id="actions" class="mmsar-actions" hidden></div>'
			. '<form id="contact" class="mmsar-form" hidden novalidate>'
			. '<h2 class="mmsar-form-title">Send a message</h2>'
			. '<label>Your name<input name="name" autocomplete="name" maxlength="100" required></label>'
			. '<label>Your email<input name="email" type="email" autocomplete="email" maxlength="254" required></label>'
			. '<label>Subject<input name="subject" maxlength="200"></label>'
			. '<label>Message<textarea name="message" maxlength="5000" required></textarea></label>'
			// A button, not a submit: a host that frames the card without `allow-forms` blocks
			// form submission before the submit event fires, so the form would never send.
			. '<button type="button" class="mmsar-button">Send</button>'
			. '<p id="status" class="mmsar-status" role="status" hidden></p>'
			. '</form>'
			. '<p id="waiting" class="mmsar-empty">Loading…</p>';

		$render = <<<'JS'
  function setText(id, value) {
    var el = document.getElementById(id);
    el.textContent = value || '';
    el.hidden = !value;
  }
  var contact = { mode: '', api: '', key: '', sent: false };
  var form = document.getElementById('contact');
  var statusEl = document.getElementById('status');
  function render(about, info) {
    document.getElementById('waiting').hidden = true;
    setText('name', about.name);
    setText('role', about.role);
    setText('bio', about.bio);
    var profiles = (about.profiles || []).filter(function (p) { return safeUrl(p.url); });
    var list = document.getElementById('profiles');
    list.innerHTML = profiles.map(function (p) {
      return '<li><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.label || p.url) + '</a></li>';
    }).join('');
    list.hidden = !profiles.length;
    contact.mode = info && (info.mode === 'tool' || info.mode === 'direct') ? info.mode : '';
    contact.api = safeUrl(about.contact_api);
    if (contact.mode === 'direct' && !contact.api) contact.mode = '';
    var actions = [];
    if (safeUrl(about.contact_url)) {
      actions.push(contact.mode
        ? '<a class="mmsar-link" href="' + esc(about.contact_url) + '" target="_blank" rel="noopener">Or use the contact page</a>'
        : '<a class="mmsar-button" href="' + esc(about.contact_url) + '" target="_blank" rel="noopener">Send a message</a>');
    }
    if (safeUrl(about.website)) actions.push('<a class="mmsar-link" href="' + esc(about.website) + '" target="_blank" rel="noopener">Visit ' + esc(about.site_name || about.website) + '</a>');
    var box = document.getElementById('actions');
    box.innerHTML = actions.join('');
    box.hidden = !actions.length;
    form.hidden = !contact.mode || contact.sent;
    var draft = info && info.draft && typeof info.draft === 'object' ? info.draft : {};
    ['name', 'email', 'subject', 'message'].forEach(function (field) {
      if (typeof draft[field] === 'string' && !form.elements[field].value) form.elements[field].value = draft[field];
    });
  }
  function newKey() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var out = '';
    for (var i = 0; i < 32; i++) out += Math.floor(Math.random() * 16).toString(16);
    return out;
  }
  function setStatus(text) { statusEl.textContent = text || ''; statusEl.hidden = !text; }
  function clearFieldErrors() {
    Array.prototype.forEach.call(form.querySelectorAll('.mmsar-field-error'), function (el) { el.parentNode.removeChild(el); });
  }
  function showFieldErrors(fields) {
    Object.keys(fields || {}).forEach(function (field) {
      var input = form.elements[field];
      if (!input) return;
      var note = document.createElement('span');
      note.className = 'mmsar-field-error';
      note.textContent = String(fields[field]);
      input.parentNode.appendChild(note);
    });
  }
  function values() {
    return {
      name: form.elements.name.value.trim(),
      email: form.elements.email.value.trim(),
      subject: form.elements.subject.value.trim(),
      message: form.elements.message.value.trim()
    };
  }
  function viaTool(data) {
    var args = { name: data.name, email: data.email, message: data.message, idempotency_key: contact.key };
    if (data.subject) args.subject = data.subject;
    return request('tools/call', { name: 'send_message', arguments: args }).then(function (result) {
      var out = result && result.structuredContent ? result.structuredContent : {};
      return { sent: !!out.sent, status: out.status || 0, message: out.message || '', fields: out.fields, retryAfter: out.retry_after };
    });
  }
  function viaDirect(data) {
    return fetch(contact.api, { method: 'GET', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (desc) {
        var body = { name: data.name, email: data.email, message: data.message, agent: 'MCP App card (direct)', submit_token: desc && desc.submit_token };
        if (data.subject) body.subject = data.subject;
        return fetch(contact.api, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': contact.key },
          body: JSON.stringify(body)
        });
      })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (out) {
          var extra = out && out.data && typeof out.data === 'object' ? out.data : (out || {});
          // Only an explicit `sent: true` counts. A 200 alone can come from somewhere that is not the
          // endpoint's send path (a redirect that turned the POST into a GET answers 200 with the
          // endpoint's description), and the card must never say "Sent" for a message that wasn't.
          var confirmed = r.ok && !!out && out.sent === true;
          return { sent: confirmed, status: r.status, message: (out && out.message) || (r.ok && !confirmed ? 'the site answered but did not confirm the message was sent' : ''), fields: extra.fields, retryAfter: extra.retry_after };
        });
      });
  }
  form.addEventListener('input', function () { if (!contact.sent) contact.key = ''; });
  form.addEventListener('submit', function (event) { event.preventDefault(); });
  form.querySelector('button').addEventListener('click', function () {
    clearFieldErrors();
    var data = values();
    var missing = {};
    if (!data.name) missing.name = 'Please add your name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) missing.email = 'Please add an email address for the reply.';
    if (!data.message) missing.message = 'Please write a message.';
    if (Object.keys(missing).length) { showFieldErrors(missing); reportSize(); return; }
    if (!contact.key) contact.key = newKey();
    var button = form.querySelector('button');
    button.disabled = true;
    setStatus('Sending…');
    reportSize();
    (contact.mode === 'tool' ? viaTool(data) : viaDirect(data))
      .then(function (outcome) {
        button.disabled = false;
        if (outcome.sent) {
          contact.sent = true;
          Array.prototype.forEach.call(form.querySelectorAll('label, button, h2'), function (el) { el.hidden = true; });
          setStatus('Sent. A reply will go to ' + data.email + '.');
          request('ui/update-model-context', { content: [{ type: 'text', text: 'The person sent a message through the about card' + (data.subject ? ' (subject: ' + data.subject + ')' : '') + '. It was delivered.' }] }).catch(function () {});
        } else if (outcome.status === 429) {
          setStatus('Too many messages right now. Please try again later' + (outcome.retryAfter ? ' (about ' + Math.ceil(outcome.retryAfter / 60) + ' minutes)' : '') + ', or use the contact page.');
        } else if (outcome.fields) {
          showFieldErrors(outcome.fields);
          setStatus('Please check the highlighted fields.');
        } else {
          setStatus('The message was not sent' + (outcome.message ? ': ' + outcome.message : '.') + ' You can try again, or use the contact page.');
        }
        reportSize();
      })
      .catch(function () {
        button.disabled = false;
        setStatus('The message could not be sent from here. Please try again, or use the contact page.');
        reportSize();
      });
  });
  function aboutFrom(result) {
    var data = result && (result.structuredContent || result);
    return data && data.about && typeof data.about === 'object' ? data.about : null;
  }
  function contactFrom(result) {
    var data = result && (result.structuredContent || result);
    return data && data.contact && typeof data.contact === 'object' ? data.contact : null;
  }
  mmsar.show = function (result) {
    var about = aboutFrom(result);
    if (about) { render(about, contactFrom(result)); return; }
    var waiting = document.getElementById('waiting');
    waiting.textContent = 'The details are in the conversation.';
    waiting.hidden = false;
  };
  mmsar.fromOpenAI = function (output) { return output; };
  mmsar.renderOpenAI = function (output) { var about = aboutFrom(output); if (about) render(about, contactFrom(output)); };
JS;

		return self::ui_document( 'about', 'About', $body, $render );
	}

	/**
	 * A complete card: the shared head, styles, footer and host bridge around one card's body.
	 *
	 * Self-contained by necessity: a UI resource is rendered in a sandbox with no network. The bridge
	 * talks to the host the way SEP-1865 specifies, as JSON-RPC over postMessage: it sends
	 * `ui/initialize`, takes the theme and the host's CSS variables from the answer, sends
	 * `ui/notifications/initialized`, hands `ui/notifications/tool-result` to the card's `show()`,
	 * reports its height with `ui/notifications/size-changed`, and opens every link (results,
	 * profiles, the footer) through `ui/open-link`, because a sandboxed frame may not be allowed to
	 * open a tab itself. It also reads `window.openai.toolOutput` for hosts still on the OpenAI Apps
	 * SDK.
	 *
	 * @param string $card   Card slug, passed to the design filter and set as a class on `<body>`.
	 * @param string $title  Document title.
	 * @param string $body   Card markup. Must already be escaped.
	 * @param string $render JavaScript defining `mmsar.show( result )`, `mmsar.fromOpenAI( output )`
	 *                       and `mmsar.renderOpenAI( data )`, with `esc()` and `safeUrl()` in scope.
	 * @return string HTML.
	 */
	private static function ui_document( $card, $title, $body, $render ) {
		$app_info = wp_json_encode(
			array(
				'name'    => 'mmsar-' . $card,
				'version' => MMSAR_VERSION,
			)
		);

		/**
		 * Filters extra CSS for every MCP Apps card this server serves.
		 *
		 * This is how a theme gives the cards its own look. Fonts must be embedded (`@font-face` with a
		 * `data:` URI): the card has no network, so a font loaded from a URL will not arrive. The
		 * markup uses these classes: `mmsar-card` (body, plus `mmsar-card-results` or
		 * `mmsar-card-about`), `mmsar-site`, `mmsar-results`, `mmsar-meta`, `mmsar-empty`,
		 * `mmsar-about`, `mmsar-photo`, `mmsar-name`, `mmsar-role`, `mmsar-bio`, `mmsar-profiles`,
		 * `mmsar-actions`, `mmsar-button`, `mmsar-link`, `mmsar-powered`, and for the about card's
		 * contact form `mmsar-form`, `mmsar-form-title`, `mmsar-field-error`, `mmsar-status`. `html[data-theme]` is `light`
		 * or `dark`, following the host.
		 *
		 * @param string $css  CSS. Empty by default.
		 * @param string $card Which card: 'results' or 'about'.
		 */
		$css = (string) apply_filters( 'mmsar_mcp_ui_css', '', $card );
		// A stylesheet cannot be allowed to close its own element and start writing markup.
		$css = str_ireplace( '</style', '', $css );

		$head = <<<'HTML'
<!doctype html>
<html lang="en" data-theme="light">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- The host builds this card's real Content-Security-Policy from the resource's _meta, and with no
     csp declared that is its most restrictive default. This in-document policy is a second, stricter
     layer for any host that frames the card without one: no network except the about card's contact
     endpoint when the site gives it one, nothing external; inline markup, styles and script, and
     images and fonts only as data: URIs. -->
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; connect-src __CONNECT__; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; font-src data:; form-action 'none'; base-uri 'none'">
<style>
  :root { color-scheme: light dark; }
  [hidden] { display: none !important; }
  html, body { margin: 0; background: transparent; }
  body { font: 15px/1.55 var(--font-sans, ui-sans-serif, system-ui, -apple-system, sans-serif); padding: 12px;
         color: var(--color-text-primary, CanvasText); }
  a { color: inherit; }
  .mmsar-site { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; margin: 0 0 10px; font-weight: 600;
                color: var(--color-text-secondary, GrayText); }
  .mmsar-results ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .mmsar-results li { border: 1px solid var(--color-border-secondary, color-mix(in srgb, currentColor 18%, transparent));
                      border-radius: var(--border-radius-md, 8px); padding: 10px 12px; }
  .mmsar-results a { text-decoration: none; font-weight: 600; }
  .mmsar-results a:hover, .mmsar-results a:focus-visible { text-decoration: underline; }
  .mmsar-results p { margin: 4px 0 0; font-size: 13.5px; color: var(--color-text-secondary, inherit); }
  .mmsar-meta { margin-top: 6px; font-size: 12px; color: var(--color-text-tertiary, GrayText); }
  .mmsar-empty { font-size: 13.5px; color: var(--color-text-secondary, GrayText); margin: 0; }
  .mmsar-about { display: flex; gap: 14px; align-items: center; }
  .mmsar-photo { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; flex: none; }
  .mmsar-name { font-size: 20px; line-height: 1.25; margin: 0; }
  .mmsar-role { margin: 2px 0 0; font-size: 13.5px; color: var(--color-text-secondary, GrayText); }
  .mmsar-bio { margin: 12px 0 0; }
  .mmsar-profiles { list-style: none; margin: 12px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 13.5px; }
  .mmsar-actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .mmsar-button { display: inline-block; padding: 7px 14px; border-radius: var(--border-radius-md, 8px); text-decoration: none; font-weight: 600;
                  border: 1px solid currentColor; }
  .mmsar-link { font-size: 13.5px; }
  .mmsar-powered { margin-top: 14px; font-size: 11.5px; color: var(--color-text-tertiary, GrayText); }
  .mmsar-form { margin-top: 16px; display: grid; gap: 10px; }
  .mmsar-form label { display: grid; gap: 4px; font-size: 13px; }
  .mmsar-form input, .mmsar-form textarea { font: inherit; color: inherit; background: transparent; padding: 7px 9px;
                  border: 1px solid var(--color-border-secondary, color-mix(in srgb, currentColor 25%, transparent));
                  border-radius: var(--border-radius-md, 8px); }
  .mmsar-form textarea { min-height: 120px; resize: vertical; }
  .mmsar-form-title { margin: 0; font-size: 15px; }
  .mmsar-field-error { font-size: 12.5px; color: #b42318; }
  .mmsar-status { font-size: 13.5px; margin: 0; }
  .mmsar-form button { justify-self: start; font: inherit; cursor: pointer; }
  :where(.mmsar-form) button { background: transparent; color: inherit; }
  .mmsar-form button[disabled] { opacity: .6; cursor: default; }
</style>
HTML;

		$footer = '<footer class="mmsar-powered">Powered by <a href="' . esc_url( self::UI_PLUGIN_URL ) . '" target="_blank" rel="noopener">Make My Site Agent Ready</a>, by Miriam Schwab</footer>';

		$bridge = <<<'JS'
<script>
(function () {
  var mmsar = {};
  var nextId = 1;
  var pending = {};
  var connected = false;

  function post(message) {
    try { window.parent.postMessage(message, '*'); } catch (e) {}
  }
  function request(method, params) {
    var id = nextId++;
    post({ jsonrpc: '2.0', id: id, method: method, params: params });
    return new Promise(function (resolve, reject) { pending[id] = { resolve: resolve, reject: reject }; });
  }
  function notify(method, params) {
    post({ jsonrpc: '2.0', method: method, params: params });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
  }); }
  function safeUrl(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }

__RENDER__

  function applyContext(ctx) {
    if (!ctx) return;
    var root = document.documentElement;
    if (ctx.theme === 'light' || ctx.theme === 'dark') { root.style.colorScheme = ctx.theme; root.setAttribute('data-theme', ctx.theme); }
    var vars = ctx.styles && ctx.styles.variables;
    if (vars) {
      Object.keys(vars).forEach(function (name) {
        if (/^--[a-z0-9-]+$/.test(name) && typeof vars[name] === 'string') root.style.setProperty(name, vars[name]);
      });
    }
  }

  var lastWidth = 0, lastHeight = 0, scheduled = false;
  function reportSize() {
    if (!connected || scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      var html = document.documentElement;
      var saved = html.style.height;
      html.style.height = 'max-content';
      var height = Math.ceil(html.getBoundingClientRect().height);
      html.style.height = saved;
      var width = Math.ceil(window.innerWidth);
      if (width !== lastWidth || height !== lastHeight) {
        lastWidth = width; lastHeight = height;
        notify('ui/notifications/size-changed', { width: width, height: height });
      }
    });
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) return;
    var m = event.data;
    if (!m || m.jsonrpc !== '2.0') return;
    if (m.id != null && !m.method && pending[m.id]) {
      var p = pending[m.id];
      delete pending[m.id];
      if (m.error) { p.reject(m.error); } else { p.resolve(m.result); }
      return;
    }
    if (m.method === 'ui/notifications/tool-result') { mmsar.show(m.params); reportSize(); }
    else if (m.method === 'ui/notifications/host-context-changed') { applyContext(m.params); }
    else if (m.id != null && (m.method === 'ping' || m.method === 'ui/resource-teardown')) { post({ jsonrpc: '2.0', id: m.id, result: {} }); }
  });

  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!link || !connected) return;
    event.preventDefault();
    var url = link.getAttribute('href');
    request('ui/open-link', { url: url }).catch(function () { window.open(url, '_blank', 'noopener'); });
  });

  try {
    if (window.openai && window.openai.toolOutput) {
      var direct = mmsar.fromOpenAI(window.openai.toolOutput);
      if (direct) mmsar.renderOpenAI(direct);
    }
  } catch (e) {}

  request('ui/initialize', { appInfo: __APP_INFO__, appCapabilities: {}, protocolVersion: '__PROTOCOL__' })
    .then(function (result) {
      connected = true;
      applyContext(result && result.hostContext);
      notify('ui/notifications/initialized', {});
      reportSize();
      if (window.ResizeObserver) {
        var observer = new ResizeObserver(reportSize);
        observer.observe(document.documentElement);
        observer.observe(document.body);
      }
    })
    .catch(function () {});
})();
</script>
JS;

		$bridge = str_replace(
			array( '__RENDER__', '__APP_INFO__', '__PROTOCOL__' ),
			array( $render, (string) $app_info, self::UI_PROTOCOL ),
			$bridge
		);

		$connect = 'about' === $card ? self::origin_of( self::contact_api() ) : '';
		$head    = str_replace( '__CONNECT__', '' !== $connect ? esc_attr( $connect ) : "'none'", $head );

		return $head . "\n"
			. ( '' !== $css ? '<style>' . $css . "</style>\n" : '' )
			. '<title>' . esc_html( $title ) . "</title>\n"
			. '<body class="mmsar-card mmsar-card-' . esc_attr( $card ) . '">' . "\n"
			. $body . "\n" . $footer . "\n" . $bridge . "\n";
	}

	/**
	 * The UI resources this server exposes, when UI is switched on.
	 *
	 * @return array[] Resource descriptors.
	 */
	private static function ui_resources() {
		if ( ! self::ui_enabled() ) {
			return array();
		}
		return array(
			array(
				'uri'         => self::ui_uri( self::UI_RESULTS ),
				'name'        => 'search-results',
				'title'       => 'Search results',
				'description' => 'Renders results from search_content or list_content as a list of links.',
				'mimeType'    => self::UI_MIME,
			),
			array(
				'uri'         => self::ui_uri( self::UI_ABOUT ),
				'name'        => 'about',
				'title'       => 'About',
				'description' => 'Renders get_about as a card: name, photo, bio, profile links and how to get in touch.',
				'mimeType'    => self::UI_MIME,
			),
		);
	}
}
