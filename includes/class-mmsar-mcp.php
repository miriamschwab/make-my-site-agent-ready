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
	 * Requests allowed per IP per window, and the window in seconds.
	 */
	const RATE_LIMIT  = 60;
	const RATE_WINDOW = 60;

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
		$rate = self::consume_rate_limit();
		if ( ! $rate['allowed'] ) {
			// Recorded, because a client being turned away is the one MCP event that leaves no
			// other trace: it never reaches dispatch(), so without this a caller hammering the
			// endpoint hard enough to be refused looks identical to a caller that never came.
			MMSAR_Agent_Log::record( 'MCP JSON-RPC', 'rate limited', true );

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
			MMSAR_Agent_Log::record( 'MCP JSON-RPC', 'parse error', true );
			return self::error_response( null, -32700, 'Parse error: request body is not valid JSON.', 400 );
		}

		// A batch is a JSON array of requests. Answering each in turn and returning an array of the
		// responses that have ids is the whole of it — notifications contribute nothing.
		if ( isset( $body[0] ) ) {
			$results = array();
			foreach ( $body as $single ) {
				$result = is_array( $single ) ? self::dispatch( $single ) : null;
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

		$result = self::dispatch( $body );
		if ( null === $result ) {
			return self::with_rate_limit_headers( new WP_REST_Response( null, 202 ), $rate );
		}
		return self::with_rate_limit_headers( new WP_REST_Response( $result, 200 ), $rate );
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
	 * @param array $message Decoded JSON-RPC message.
	 * @return array|null The response object, or null for a notification.
	 */
	private static function dispatch( $message ) {
		$method = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		// A message with no id is a notification: it is acted on, but never answered. Note that
		// id 0 and id "" are both legitimate ids, so this tests for the key, not for truthiness.
		$id              = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$is_notification = ! array_key_exists( 'id', $message );

		// Logged here rather than in handle(), because handle() sees one HTTP request while this
		// sees one JSON-RPC message, and a batch is several messages in one request. Logged before
		// the switch so an unknown method is recorded too — a client asking for something this
		// server does not implement is worth knowing about, and it is the case least likely to be
		// reported any other way.
		MMSAR_Agent_Log::record( 'MCP JSON-RPC', self::log_detail( $method, $params ), true );

		switch ( $method ) {
			case 'initialize':
				$result = self::initialize( $params );
				break;
			case 'ping':
				$result = new stdClass();
				break;
			case 'tools/list':
				$result = array( 'tools' => self::tools() );
				break;
			case 'tools/call':
				$result = self::call_tool( $params );
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
				return self::rpc_error( $id, -32601, 'Unknown method: ' . $method );
		}

		if ( $is_notification ) {
			return null;
		}
		if ( $result instanceof WP_Error ) {
			return self::rpc_error( $id, -32602, $result->get_error_message() );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
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
		foreach ( self::tools() as $tool ) {
			if ( isset( $tool['name'] ) && $tool['name'] === $name ) {
				return $method . ': ' . mb_substr( $name, 0, 120 );
			}
		}

		return $method . ': (unknown tool)';
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
			'serverInfo'      => array(
				'name'    => 'mmsar-' . sanitize_title( $site_name ),
				'title'   => $site_name,
				'version' => MMSAR_VERSION,
			),
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
		$lines[] = 'Start with `search_content` when you have a topic, or `list_content` when you want to see what is here. Both return URLs; pass one to `get_content` to read the full text as Markdown. Do not fetch the site\'s HTML pages — `get_content` returns the same content already parsed.';
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
	 * @return array Error response.
	 */
	private static function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
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
				'description' => 'Lists published content newest first, without searching. Use this to see what is on the site, to find the most recent writing, or to page through everything.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Which content type to list. Omit for all of them.',
							'enum'        => array_values( mmsar_get_enabled_post_types() ),
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
				'description' => 'What this site is, who runs it, how much content it has, and which machine-readable endpoints it publishes. Call this once at the start if you have no context on the site.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'sections' => array(
							'type'        => 'array',
							'description' => 'Which parts of the overview to return. Omit for all of them. Ask for a subset when you already have context and only need one thing — `endpoints` alone is a fraction of the tokens of the whole overview.',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'about', 'content', 'endpoints', 'actions' ),
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

		// MCP Apps binds a tool to a template through `_meta`. Attached only to the two tools that
		// return a list of things — a template that renders result cards has nothing to do with a
		// single page's text or a site overview, and pointing every tool at it would give a host
		// permission to render the wrong thing.
		if ( self::ui_enabled() ) {
			foreach ( $tools as $index => $tool ) {
				if ( ! in_array( $tool['name'], array( 'search_content', 'list_content' ), true ) ) {
					continue;
				}
				$tools[ $index ]['_meta'] = array(
					'ui'                    => array(
						'resourceUri' => self::UI_RESULTS,
						'preferred'   => true,
					),
					// The OpenAI Apps SDK spelling of the same binding. Hosts read one or the other.
					'openai/outputTemplate' => self::UI_RESULTS,
				);
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
	 * @return array|WP_Error Tool result, or an error for a malformed call.
	 */
	private static function call_tool( $params ) {
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

		$posts = get_posts(
			array(
				'post_type'           => self::requested_post_types( $arguments ),
				'post_status'         => 'publish',
				's'                   => $query,
				'posts_per_page'      => self::requested_limit( $arguments, 10 ),
				'has_password'        => false,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		if ( empty( $posts ) ) {
			return self::tool_text(
				'No published content matched "' . $query . '".' . "\n\n"
				. 'This is a keyword search, so try fewer or more specific words, or call list_content to see what is on the site.'
			);
		}

		$lines = array( 'Found ' . count( $posts ) . ' result' . ( 1 === count( $posts ) ? '' : 's' ) . ' for "' . $query . '":', '' );
		foreach ( $posts as $post ) {
			$lines[] = '## ' . self::title( $post );
			$lines[] = 'URL: ' . get_permalink( $post );
			$lines[] = 'Type: ' . $post->post_type . ' · Published: ' . get_the_date( 'Y-m-d', $post );
			$excerpt = self::excerpt( $post );
			if ( '' !== $excerpt ) {
				$lines[] = '';
				$lines[] = $excerpt;
			}
			$lines[] = '';
		}
		$lines[] = 'Pass any of these URLs to get_content to read the full text.';

		return self::tool_text( implode( "\n", $lines ) );
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
				update_post_meta( $post->ID, '_llmmd_content', $markdown );
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

		$query = new WP_Query(
			array(
				'post_type'           => self::requested_post_types( $arguments ),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'offset'              => $offset,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'has_password'        => false,
				'ignore_sticky_posts' => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return self::tool_text( 0 === $offset ? 'This site has no published content in those types.' : 'No more content past that offset.' );
		}

		$total = (int) $query->found_posts;
		$lines = array(
			'Showing ' . count( $query->posts ) . ' of ' . $total . ' published item' . ( 1 === $total ? '' : 's' )
			. ( $offset ? ', starting at ' . $offset : '' ) . ':',
			'',
		);
		foreach ( $query->posts as $post ) {
			$lines[] = '- **' . self::title( $post ) . '** (' . $post->post_type . ', ' . get_the_date( 'Y-m-d', $post ) . ')';
			$lines[] = '  ' . get_permalink( $post );
		}

		$shown = $offset + count( $query->posts );
		if ( $shown < $total ) {
			$lines[] = '';
			$lines[] = 'Call list_content again with offset=' . $shown . ' for the next page.';
		}

		return self::tool_text( implode( "\n", $lines ) );
	}

	/**
	 * Handle the get_site_overview tool.
	 *
	 * @param array $arguments Tool arguments. Optional 'sections' narrows what comes back.
	 * @return array Tool result.
	 */
	private static function tool_overview( $arguments = array() ) {
		$all    = array( 'about', 'content', 'endpoints', 'actions' );
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
		$lines[] = 'URL: ' . home_url( '/' );
		$lines[] = '';

		return $lines;
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
		// wp_remote_get() on a ui:// scheme would simply fail.
		if ( self::UI_RESULTS === $uri && self::ui_enabled() ) {
			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'text/html+skybridge',
						'text'     => self::ui_results_html(),
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
			'name'            => 'mmsar-' . sanitize_title( $site_name ),
			'title'           => $site_name,
			'description'     => 'Read-only access to the published content of ' . $site_name . ': search it, list it, and read any page as Markdown.',
			'version'         => MMSAR_VERSION,
			'protocolVersion' => self::PROTOCOL_VERSION,
			'transport'       => array(
				'type' => 'streamable-http',
				'url'  => self::endpoint_url(),
			),
			// Repeated at the top level because clients and directories disagree about where the
			// endpoint lives in this document, and both spellings are cheap.
			'url'             => self::endpoint_url(),
			'authentication'  => array(
				'type'        => 'none',
				'description' => 'Public and read-only. No credentials required.',
			),
			'capabilities'    => array(
				'tools'     => array_column( self::tools(), 'name' ),
				'resources' => array_column( self::resources(), 'uri' ),
			),
			'websiteUrl'      => home_url( '/' ),
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
	 * @return void
	 */
	private static function serve_server_card() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$tools = array();
		foreach ( self::tools() as $tool ) {
			$tools[] = array(
				'name'        => $tool['name'],
				'title'       => isset( $tool['title'] ) ? $tool['title'] : $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
		}

		$card = array(
			'name'             => 'mmsar-' . sanitize_title( $site_name ),
			'title'            => $site_name,
			'description'      => 'Read-only access to the published content of ' . $site_name . ': search it, list it, and read any page as Markdown.',
			'version'          => MMSAR_VERSION,
			'protocolVersion'  => self::PROTOCOL_VERSION,
			'serverUrl'        => self::endpoint_url(),
			'transport'        => array(
				'type' => 'streamable-http',
				'url'  => self::endpoint_url(),
			),
			'authentication'   => array( 'type' => 'none' ),
			'tools'            => $tools,
			'resources'        => self::resources(),
			'websiteUrl'       => home_url( '/' ),
			'documentationUrl' => mmsar_feature_enabled( 'llms_txt' ) ? home_url( '/llms.txt' ) : home_url( '/' ),
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
		if ( is_array( $filtered ) ) {
			$card = $filtered;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'mcp server-card.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// -------------------------------------------------------------------------
	// MCP Apps (ui:// resources)
	// -------------------------------------------------------------------------

	/**
	 * The URI of the search-results UI template.
	 */
	const UI_RESULTS = 'ui://mmsar/search-results.html';

	/**
	 * Whether to advertise MCP Apps UI resources.
	 *
	 * Off unless asked for, and the reason is worth stating plainly: the MCP Apps extension is young,
	 * the host side of the contract varies between implementations, and this template has never been
	 * rendered by a real MCP Apps host — there was none available to test against. Every other
	 * surface in this plugin was verified end to end before shipping; this one cannot honestly claim
	 * that, so it does not turn itself on.
	 *
	 * A host that ignores `_meta` entirely still gets the normal text result, which is why declaring
	 * the template is safe rather than merely cheap.
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
	 * The HTML for the search-results template.
	 *
	 * Self-contained by necessity — a UI resource is rendered in a sandbox with no network — and
	 * defensive about how it receives its data, because the host APIs disagree. It tries the OpenAI
	 * Apps SDK global, the MCP Apps bridge, and a postMessage handshake, then renders whichever
	 * arrives. With none of them it shows a plain message rather than an empty frame, so a host that
	 * implements none of these produces something legible instead of a blank panel.
	 *
	 * @return string HTML.
	 */
	private static function ui_results_html() {
		$site_name = esc_html( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		// The origin of this site's MCP endpoint, derived rather than assumed: rest_url() can point
		// at another host or subdomain, and a policy naming the wrong origin is worse than none.
		$parts      = wp_parse_url( self::endpoint_url() );
		$mcp_origin = ( isset( $parts['scheme'], $parts['host'] ) )
			? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			: '';

		// connect-src is named explicitly even though this panel fetches nothing. `default-src
		// 'none'` already denies it, but silence is ambiguous to a reader — an audit cannot tell a
		// panel that needs no network from one whose author forgot the directive. Naming the MCP
		// origin states the only place this panel could ever legitimately call, and keeps the
		// declaration true if a future revision does call back to the server it belongs to.
		$connect_src = ( '' !== $mcp_origin ) ? esc_attr( $mcp_origin ) : "'none'";

		return implode(
			"\n",
			array(
				'<!doctype html>',
				'<meta charset="utf-8">',
				'<meta name="viewport" content="width=device-width,initial-scale=1">',
				'<!-- Declared in the document because a ui:// resource is delivered as a string over the MCP',
				'     connection rather than as an HTTP response, so there is no header to carry a policy. The panel',
				'     needs nothing from the network: its markup, styles and script are all inline, and the only',
				'     external thing it references is the href of a result link the viewer may click. Everything',
				'     else is denied, so a result title that somehow carried markup still cannot fetch or execute.',
				'     frame-ancestors stays open on purpose: the panel is framed by whichever MCP host rendered it,',
				'     and that host list is not knowable here. Pinning it to the two hosts that happen to be popular',
				'     would break every other client for no security gain the sandbox does not already provide. -->',
				'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; connect-src ' . $connect_src . '; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'; img-src data:; form-action \'none\'; base-uri \'none\'; frame-ancestors *">',
				'<title>Results</title>',
				'<style>',
				'  :root { color-scheme: light dark; }',
				'  body { font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, sans-serif; margin: 0; padding: 12px;',
				'         background: transparent; color: #111; }',
				'  @media (prefers-color-scheme: dark) { body { color: #eee; } }',
				'  h1 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; opacity: .6; margin: 0 0 10px; font-weight: 600; }',
				'  ol { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }',
				'  li { border: 1px solid color-mix(in srgb, currentColor 18%, transparent); border-radius: 8px; padding: 10px 12px; }',
				'  a { color: inherit; text-decoration: none; font-weight: 600; }',
				'  a:hover { text-decoration: underline; }',
				'  p { margin: 4px 0 0; opacity: .72; font-size: 13.5px; }',
				'  .meta { margin-top: 6px; font-size: 12px; opacity: .55; }',
				'  .empty { opacity: .6; font-size: 13.5px; }',
				'</style>',
				"<h1>{$site_name}</h1>",
				'<div id="out"><p class="empty">Waiting for results…</p></div>',
				'<script>',
				'(function () {',
				'  var out = document.getElementById(\'out\');',
				'',
				'  function esc(s) { return String(s == null ? \'\' : s).replace(/[&<>"]/g, function (c) {',
				'    return ({ \'&\': \'&amp;\', \'<\': \'&lt;\', \'>\': \'&gt;\', \'"\': \'&quot;\' })[c];',
				'  }); }',
				'',
				'  function render(items) {',
				'    if (!items || !items.length) { out.innerHTML = \'<p class="empty">No results.</p>\'; return; }',
				'    out.innerHTML = \'<ol>\' + items.map(function (r) {',
				'      var url = esc(r.url || r.link || \'\');',
				'      var name = esc(r.name || r.title || url);',
				'      var desc = esc(r.description || r.excerpt || \'\');',
				'      var meta = esc([r.type, r.date].filter(Boolean).join(\' · \'));',
				'      return \'<li><a href="\' + url + \'" target="_blank" rel="noopener">\' + name + \'</a>\'',
				'        + (desc ? \'<p>\' + desc + \'</p>\' : \'\')',
				'        + (meta ? \'<div class="meta">\' + meta + \'</div>\' : \'\')',
				'        + \'</li>\';',
				'    }).join(\'\') + \'</ol>\';',
				'  }',
				'',
				'  // Hosts disagree about how tool output reaches a UI resource, so try each known shape and take',
				'  // whichever answers. None of these throws if the global is absent.',
				'  function fromAny(payload) {',
				'    if (!payload) return null;',
				'    if (Array.isArray(payload)) return payload;',
				'    return payload.results || payload.items || payload.structuredContent || null;',
				'  }',
				'',
				'  try {',
				'    if (window.openai && window.openai.toolOutput) {',
				'      var direct = fromAny(window.openai.toolOutput);',
				'      if (direct) { render(direct); return; }',
				'    }',
				'  } catch (e) {}',
				'',
				'  try {',
				'    if (window.mcp && typeof window.mcp.getToolOutput === \'function\') {',
				'      Promise.resolve(window.mcp.getToolOutput()).then(function (o) {',
				'        var r = fromAny(o); if (r) render(r);',
				'      }).catch(function () {});',
				'    }',
				'  } catch (e) {}',
				'',
				'  window.addEventListener(\'message\', function (event) {',
				'    var data = event && event.data;',
				'    if (!data) return;',
				'    var r = fromAny(data.toolOutput || data.structuredContent || data);',
				'    if (r) render(r);',
				'  });',
				'',
				'  try { window.parent && window.parent.postMessage({ type: \'mcp-ui-ready\' }, \'*\'); } catch (e) {}',
				'})();',
				'</script>',
			)
		);
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
				'uri'         => self::UI_RESULTS,
				'name'        => 'search-results',
				'title'       => 'Search results',
				'description' => 'Renders results from search_content or list_content as a list of links.',
				// The media type MCP Apps hosts look for on an inline UI template.
				'mimeType'    => 'text/html+skybridge',
			),
		);
	}
}
