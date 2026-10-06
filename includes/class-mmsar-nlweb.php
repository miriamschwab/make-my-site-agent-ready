<?php
/**
 * NLWeb: a `/ask` endpoint and a Schema Map, per Microsoft's NLWeb protocol.
 *
 * NLWeb's premise is that a site should be able to answer a question about itself at a predictable
 * address, in a predictable shape, without the caller knowing anything about how the site is built.
 * That is a reasonable thing for a content site to offer and cheap to provide honestly: the answer
 * here is a ranked list of pages, which is what a WordPress site can actually produce.
 *
 * What this deliberately does not do is pretend to synthesize an answer. There is no model behind
 * this endpoint, and `_meta.response_type` says so — callers get `list` rather than `summary`, so an
 * agent knows it is receiving retrieval results to read rather than a prose answer to quote. A
 * generated summary would need an API key, a per-request cost and a hallucination budget, none of
 * which belong in a plugin that otherwise only ever serves files.
 *
 * Spec: https://github.com/microsoft/NLWeb
 * Schema Map: https://github.com/nlweb-ai/website/blob/main/SCHEMA_SPEC.md
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR NLWeb handler.
 */
class MMSAR_NLWeb {

	/**
	 * Protocol version reported in every `_meta` block.
	 */
	const VERSION = '0.1';

	/**
	 * Largest result set returned, whatever is asked for.
	 */
	const MAX_RESULTS = 25;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'add_schemamap_directive' ), PHP_INT_MAX );
		add_action( 'init', array( __CLASS__, 'register_ask_endpoint' ), 20 );
		add_filter( 'mmsar_openapi_document', array( __CLASS__, 'describe_ask_in_openapi' ) );
	}

	/**
	 * Describes /ask in the OpenAPI document in more detail than its registry entry can carry.
	 *
	 * The registry records a path, methods and one media type, so the generated operation said
	 * nothing about the question parameter, the two request shapes, or that the endpoint can answer
	 * as an event stream. A checker reading the spec concluded /ask could not stream.
	 *
	 * @param array $document The OpenAPI document.
	 * @return array The document, with /ask's operations filled in where it lists them.
	 */
	public static function describe_ask_in_openapi( $document ) {
		$path = (string) wp_parse_url( self::ask_url(), PHP_URL_PATH );
		if ( ! is_array( $document ) || empty( $document['paths'][ $path ] ) || ! is_array( $document['paths'][ $path ] ) ) {
			return $document;
		}

		$ok = array(
			'description' => 'Ranked pages from this site. A v0.54 request gets a v0.54 `Answer` (`_meta` and `results`); any other request gets `query_id`, `query`, `results` and `_meta`.',
			'content'     => array(
				'application/json'  => array( 'schema' => array( 'type' => 'object' ) ),
				'text/event-stream' => array(
					'schema' => array(
						'type'        => 'string',
						'description' => 'Server-sent events, sent when the request asks for streaming. A v0.54 request gets unnamed `data:` events: `{"_meta": ...}` first, then one `{"results": [...]}` per page. Any other request gets `start`, `result` and `complete` events.',
					),
				),
			),
		);

		foreach ( $document['paths'][ $path ] as $method => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$operation['responses']['200'] = $ok;
			if ( 'get' === $method ) {
				$operation['parameters'] = array(
					array(
						'name'        => 'query',
						'in'          => 'query',
						'required'    => true,
						'description' => 'The question, in plain language. `q` and `question` are accepted too.',
						'schema'      => array( 'type' => 'string' ),
					),
					array(
						'name'        => 'streaming',
						'in'          => 'query',
						'required'    => false,
						'description' => 'Set to `true` for server-sent events instead of one JSON response.',
						'schema'      => array( 'type' => 'boolean' ),
					),
				);
			}
			if ( 'post' === $method ) {
				$operation['requestBody'] = array(
					'required' => true,
					'content'  => array(
						'application/json' => array(
							'schema' => array(
								'oneOf' => array(
									array(
										'title'      => 'NLWeb v0.54 request',
										'type'       => 'object',
										'required'   => array( 'query' ),
										'properties' => array(
											'query'  => array(
												'type'     => 'object',
												'required' => array( 'text' ),
												'properties' => array(
													'text' => array(
														'type' => 'string',
														'description' => 'The question.',
													),
												),
											),
											'prefer' => array(
												'type' => 'object',
												'properties' => array(
													'streaming' => array( 'type' => 'boolean' ),
												),
											),
											'meta'   => array(
												'type' => 'object',
												'properties' => array(
													'api_version' => array(
														'type'    => 'string',
														'example' => '0.54',
													),
												),
											),
										),
									),
									array(
										'title'      => 'Flat request',
										'type'       => 'object',
										'required'   => array( 'query' ),
										'properties' => array(
											'query'     => array(
												'type' => 'string',
												'description' => 'The question.',
											),
											'streaming' => array( 'type' => 'boolean' ),
										),
									),
								),
							),
						),
					),
				);
			}
			$document['paths'][ $path ][ $method ] = $operation;
		}

		return $document;
	}

	/**
	 * List /ask with the site's other endpoints, in llms.txt and the api-catalog.
	 *
	 * Until 1.60.0 the Schema Map was the only document that pointed to /ask. It was never a schema
	 * feed, so it came out of the map when the map moved to the spec format, and is listed here.
	 *
	 * @return void
	 */
	public static function register_ask_endpoint() {
		if ( ! self::is_serving() || ! function_exists( 'mmsar_register_endpoint' ) ) {
			return;
		}
		mmsar_register_endpoint(
			array(
				'id'          => 'nlweb-ask',
				'title'       => 'Ask this site (NLWeb)',
				'href'        => self::ask_url(),
				'description' => 'Search this site with a question in plain language. Send `query` as a query-string parameter, a form field or a JSON body field; NLWeb v0.54 requests (`{"query": {"text": ...}}`) get a v0.54 response. Add `prefer.streaming`, `?streaming=true` or `Accept: text/event-stream` for server-sent events. Returns a ranked list of pages to read, not a generated answer.',
				'type'        => 'application/json',
				'methods'     => array( 'GET', 'POST' ),
				'auth'        => 'none',
			)
		);
	}

	/**
	 * Whether this site should answer at /ask.
	 *
	 * A page or post already published at that slug wins. The endpoint is a convenience; someone's
	 * actual content is not, and a rewrite rule registered at the top of the stack would shadow it
	 * silently.
	 *
	 * @return bool
	 */
	public static function is_serving() {
		if ( get_page_by_path( 'ask', OBJECT, get_post_types( array( 'public' => true ) ) ) ) {
			return false;
		}
		/**
		 * Filters whether the NLWeb /ask endpoint is served.
		 *
		 * @param bool $is_serving Whether to serve it.
		 */
		return (bool) apply_filters( 'mmsar_nlweb_is_serving', true );
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		if ( self::is_serving() ) {
			add_rewrite_rule( '^ask/?$', 'index.php?mmsar_nlweb_ask=1', 'top' );
		}
		add_rewrite_rule( '^schema-map\.xml$', 'index.php?mmsar_schema_map=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_nlweb_ask';
		$vars[] = 'mmsar_schema_map';
		return $vars;
	}

	/**
	 * The /ask endpoint URL.
	 *
	 * @return string Absolute URL.
	 */
	public static function ask_url() {
		return home_url( '/ask' );
	}

	/**
	 * The Schema Map URL.
	 *
	 * @return string Absolute URL.
	 */
	public static function schema_map_url() {
		return home_url( '/schema-map.xml' );
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( get_query_var( 'mmsar_nlweb_ask' ) ) {
			self::serve_ask();
		}
		if ( get_query_var( 'mmsar_schema_map' ) ) {
			self::serve_schema_map();
		}
	}

	// -------------------------------------------------------------------------
	// /ask
	// -------------------------------------------------------------------------

	/**
	 * Answer a question about this site.
	 *
	 * @return void
	 */
	private static function serve_ask() {
		$query     = self::read_query();
		$streaming = self::wants_streaming();

		if ( self::is_v054() ) {
			self::serve_ask_v054( $query, $streaming );
		}

		if ( '' === $query ) {
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Access-Control-Allow-Origin: *' );
			status_header( 400 );
			echo wp_json_encode(
				array(
					'code'    => 'missing_query',
					'message' => 'Provide a question. Send `query` as a JSON body field, a form field, or a query-string parameter.',
					'data'    => array( 'status' => 400 ),
					'_meta'   => self::meta( 'error' ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			exit;
		}

		$results = self::search( $query );
		MMSAR_Agent_Log::record( 'nlweb /ask' );

		if ( $streaming ) {
			self::stream( $query, $results );
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		status_header( 200 );
		echo wp_json_encode(
			array(
				'query_id' => self::query_id( $query ),
				'query'    => $query,
				'results'  => $results,
				'_meta'    => self::meta( 'list' ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	/**
	 * Answer a request in NLWeb protocol v0.54's shape. Does not return.
	 *
	 * Mirrors NLWeb_Core's reference server: a JSON `Answer` is `{_meta, results}`, an error is a
	 * `Failure` with `{_meta, error: {code, message}}`, and a stream sends `{_meta}` first and then
	 * `{results}` objects as unnamed SSE `data:` events. Each result is the page's schema.org object
	 * with `grounding.source_urls`, plus this plugin's `score`, `site` and `markdown_url`.
	 *
	 * @param string $query     The question, or '' when none was sent.
	 * @param bool   $streaming Whether the caller asked for a stream.
	 * @return void
	 */
	private static function serve_ask_v054( $query, $streaming ) {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: private, no-store, max-age=0' );

		if ( '' === $query ) {
			$meta  = self::meta_v054( 'Failure' );
			$error = array(
				'code'    => 'INVALID_REQUEST',
				'message' => 'Provide a question in `query.text`.',
			);
			if ( $streaming ) {
				self::start_stream( 400 );
				self::send_data( array( '_meta' => $meta ) );
				self::send_data( array( 'error' => $error ) );
				exit;
			}
			header( 'Content-Type: application/json; charset=UTF-8' );
			status_header( 400 );
			echo wp_json_encode(
				array(
					'_meta' => $meta,
					'error' => $error,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			exit;
		}

		$results = array_map( array( __CLASS__, 'result_v054' ), self::search( $query ) );
		MMSAR_Agent_Log::record( 'nlweb /ask' );
		$meta = self::meta_v054( 'Answer', $query );

		if ( $streaming ) {
			self::start_stream( 200 );
			self::send_data( array( '_meta' => $meta ) );
			foreach ( $results as $result ) {
				self::send_data( array( 'results' => array( $result ) ) );
			}
			exit;
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		status_header( 200 );
		echo wp_json_encode(
			array(
				'_meta'   => $meta,
				'results' => $results,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	/**
	 * The v0.54 `_meta` block.
	 *
	 * @param string $response_type `Answer` or `Failure`.
	 * @param string $query         The question, for the request id. '' on a failure.
	 * @return array Meta block.
	 */
	private static function meta_v054( $response_type, $query = '' ) {
		$meta = array(
			'response_type' => $response_type,
			'version'       => '0.54',
		);
		if ( 'Answer' === $response_type ) {
			$meta['response_format'] = 'conv_search';
			$meta['request_id']      = self::query_id( $query );
		}
		$meta['site']         = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$meta['generated_by'] = 'make-my-site-agent-ready/' . MMSAR_VERSION;
		if ( 'Answer' === $response_type ) {
			$meta['note'] = 'Retrieval only. These are ranked pages from this site, not a generated answer — fetch the URLs to read them.';
		}
		return $meta;
	}

	/**
	 * One result in v0.54's shape: the schema.org object, grounded in its own URL.
	 *
	 * @param array $result A result from search().
	 * @return array v0.54 result object.
	 */
	public static function result_v054( $result ) {
		$object              = $result['schema_object'];
		$object['grounding'] = array( 'source_urls' => array( $result['url'] ) );
		$object['score']     = $result['score'];
		$object['site']      = $result['site'];
		if ( ! empty( $result['markdown_url'] ) ) {
			$object['markdown_url'] = $result['markdown_url'];
		}
		return $object;
	}

	/**
	 * The `_meta` block every response carries.
	 *
	 * `response_type` is `list` rather than `summary` on purpose — see the class docblock. Saying so
	 * in the response is what lets a caller treat these as sources to read rather than as an answer.
	 *
	 * @param string $response_type NLWeb response type.
	 * @return array Meta block.
	 */
	private static function meta( $response_type ) {
		return array(
			'response_type' => $response_type,
			'version'       => self::VERSION,
			'site'          => wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'generated_by'  => 'make-my-site-agent-ready/' . MMSAR_VERSION,
			'note'          => 'Retrieval only. These are ranked pages from this site, not a generated answer — fetch the URLs to read them.',
		);
	}

	/**
	 * A stable id for a query, so a caller can correlate a stream with its JSON response.
	 *
	 * @param string $query The query.
	 * @return string Query id.
	 */
	private static function query_id( $query ) {
		return substr( md5( $query . '|' . gmdate( 'Y-m-d' ) ), 0, 16 );
	}

	/**
	 * The question, from wherever the caller put it.
	 *
	 * NLWeb clients variously POST JSON, POST a form, or GET a query string, and all three are cheap
	 * to accept.
	 *
	 * @return string The query.
	 */
	private static function read_query() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Public read-only search endpoint; no state is changed and no nonce can exist for an external agent.
		foreach ( array( 'query', 'q', 'question' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) );
			}
			if ( isset( $_POST[ $key ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		// phpcs:enable

		return self::query_from_body( self::json_body() );
	}

	/**
	 * The question in a decoded JSON body.
	 *
	 * @param array $body Decoded body.
	 * @return string The question, or ''.
	 */
	public static function query_from_body( $body ) {
		// NLWeb protocol v0.54 nests the question: `{"query": {"text": "..."}}`.
		if ( isset( $body['query'] ) && is_array( $body['query'] ) ) {
			return isset( $body['query']['text'] ) && is_string( $body['query']['text'] )
				? trim( sanitize_text_field( $body['query']['text'] ) )
				: '';
		}
		foreach ( array( 'query', 'q', 'question' ) as $key ) {
			if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
				return trim( sanitize_text_field( $body[ $key ] ) );
			}
		}
		return '';
	}

	/**
	 * The request's JSON body, decoded once.
	 *
	 * @return array The body, or an empty array when there is none or it is not a JSON object.
	 */
	private static function json_body() {
		static $body = null;
		if ( null === $body ) {
			$raw  = file_get_contents( 'php://input' );
			$body = $raw ? json_decode( $raw, true ) : null;
			$body = is_array( $body ) ? $body : array();
		}
		return $body;
	}

	/**
	 * Whether the request uses NLWeb protocol v0.54, and so expects a v0.54 response.
	 *
	 * Protocol v0.54 (NLWeb_Core, 2025) moved the request to nested `query` / `context` / `prefer` / `meta`
	 * sections and the response to typed `Answer` / `Failure` envelopes. Its reference server rejects
	 * the older flat request, so a v0.54 client will not understand the older response either. The
	 * older shape is kept for every other caller, because changing it would break clients that
	 * already work.
	 *
	 * @return bool
	 */
	private static function is_v054() {
		return self::is_v054_body( self::json_body() );
	}

	/**
	 * Whether a decoded JSON body is an NLWeb v0.54 request.
	 *
	 * @param array $body Decoded body.
	 * @return bool
	 */
	public static function is_v054_body( $body ) {
		if ( isset( $body['query'] ) && is_array( $body['query'] ) ) {
			return true;
		}
		return isset( $body['meta'] ) && is_array( $body['meta'] ) && ( isset( $body['meta']['api_version'] ) || isset( $body['meta']['version'] ) );
	}

	/**
	 * Whether the caller asked for a stream.
	 *
	 * @return bool
	 */
	private static function wants_streaming() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only response-format selector.
		if ( isset( $_GET['streaming'] ) && 'false' !== $_GET['streaming'] ) {
			return true;
		}
		// phpcs:enable
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';
		if ( false !== strpos( $accept, 'text/event-stream' ) ) {
			return true;
		}
		// NLWeb signals streaming through the `prefer` field of a JSON body, and RFC 8594's `Prefer`
		// header is the natural HTTP spelling of the same thing. Accept either.
		$prefer = isset( $_SERVER['HTTP_PREFER'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_PREFER'] ) ) ) : '';
		if ( false !== strpos( $prefer, 'streaming' ) ) {
			return true;
		}

		$body = self::json_body();
		if ( ! empty( $body['streaming'] ) ) {
			return true;
		}
		return isset( $body['prefer'] ) && is_array( $body['prefer'] ) && ! empty( $body['prefer']['streaming'] );
	}

	/**
	 * Emit the answer as Server-Sent Events.
	 *
	 * Does not return.
	 *
	 * @param string $query   The query.
	 * @param array  $results Results.
	 * @return void
	 */
	private static function stream( $query, $results ) {
		self::start_stream( 200 );

		self::send_event(
			'start',
			array(
				'query_id' => self::query_id( $query ),
				'query'    => $query,
				'_meta'    => self::meta( 'list' ),
			)
		);
		foreach ( $results as $result ) {
			self::send_event( 'result', $result );
		}
		self::send_event(
			'complete',
			array(
				'query_id' => self::query_id( $query ),
				'count'    => count( $results ),
				'_meta'    => self::meta( 'list' ),
			)
		);
		exit;
	}

	/**
	 * Send the headers that open an event stream.
	 *
	 * @param int $status HTTP status.
	 * @return void
	 */
	private static function start_stream( $status ) {
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-store, max-age=0' );
		header( 'Connection: keep-alive' );
		// Nginx buffers event streams by default, which turns an SSE response into one delivery at
		// the end — technically the same bytes, but it defeats the point of streaming.
		header( 'X-Accel-Buffering: no' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( $status );
	}

	/**
	 * Write one unnamed SSE event, v0.54's form, and push it out.
	 *
	 * @param array $data Payload.
	 * @return void
	 */
	private static function send_data( $data ) {
		echo 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Harmless when no buffer is active; the alternative is a fatal on some SAPIs.
		}
		flush();
	}

	/**
	 * Write one SSE event and push it out.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Payload.
	 * @return void
	 */
	private static function send_event( $event, $data ) {
		echo 'event: ' . esc_html( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n";
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Harmless when no buffer is active; the alternative is a fatal on some SAPIs.
		}
		flush();
	}

	/**
	 * Rank this site's content against a query.
	 *
	 * @param string $query The query.
	 * @return array[] NLWeb result objects.
	 */
	private static function search( $query ) {
		$posts = get_posts(
			MMSAR_Noindex::exclude(
				array(
					'post_type'           => mmsar_get_enabled_post_types(),
					'post_status'         => 'publish',
					's'                   => $query,
					'posts_per_page'      => self::MAX_RESULTS,
					'has_password'        => false,
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			)
		);

		$site    = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$results = array();
		$rank    = 0;
		$total   = count( $posts );

		foreach ( $posts as $post ) {
			++$rank;
			$url   = get_permalink( $post );
			$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$excerpt = has_excerpt( $post )
				? $post->post_excerpt
				: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 45, '…' );
			$excerpt = trim( html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			$results[] = array(
				'url'           => $url,
				'name'          => $title,
				'site'          => $site,
				// Rank expressed as a descending score, because that is the shape NLWeb callers sort
				// on. It reflects WordPress's relevance ordering, not a semantic similarity — there
				// is no embedding here and a score that implied one would be misleading.
				'score'         => $total > 0 ? round( 1 - ( ( $rank - 1 ) / max( $total, 1 ) ), 4 ) : 0,
				'description'   => $excerpt,
				'schema_object' => array(
					'@context'      => 'https://schema.org',
					'@type'         => 'Article',
					'name'          => $title,
					'url'           => $url,
					'datePublished' => get_the_date( 'c', $post ),
					'dateModified'  => get_the_modified_date( 'c', $post ),
					'description'   => $excerpt,
				),
				// The plugin's own contribution to the shape: the address of this page's Markdown,
				// so a caller that wants the full text does not have to fetch and strip the HTML.
				'markdown_url'  => mmsar_feature_enabled( 'markdown' ) ? rtrim( $url, '/' ) . '.md' : null,
			);
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Schema Map
	// -------------------------------------------------------------------------

	/*
	 * Format: NLWeb's Schema Feeds specification, v0.1 draft (January 2026),
	 * https://github.com/nlweb-ai/website/blob/main/SCHEMA_SPEC.md. A Schema Map is a sitemap
	 * `<urlset>` whose entries each carry an `<sf:contentType>`; the spec defines two values, below.
	 * Before 1.60.0 this file used a `<schemamap>` root and namespace the spec does not have, and
	 * listed llms-full.txt and /ask, which are not schema feeds, so a conforming reader found nothing.
	 */

	/**
	 * Namespace of the `sf:` elements in a Schema Map.
	 */
	const SCHEMAFEED_NS = 'http://schema.org/schemas/schemafeed/0.1';

	/**
	 * Content type of a JSON Lines feed of schema.org JSON-LD objects.
	 */
	const CONTENT_TYPE_SCHEMA_ORG = 'structuredData/schema.org';

	/**
	 * Content type of an RSS 2.0 feed.
	 */
	const CONTENT_TYPE_RSS = 'structuredData/rss';

	/**
	 * Key in `llmmd_settings` for the robots.txt directives. Absent means on.
	 */
	const ROBOTS_SETTING = 'schemamap_robots';

	/**
	 * Whether the `Schemamap:` lines go into robots.txt, given the settings array.
	 *
	 * On by default because the spec makes robots.txt the way a crawler finds a Schema Map. It is a
	 * setting because Google Search Console reports the line as "Syntax not understood" (Yoast removed
	 * its own for that reason in 27.5, Yoast/wordpress-seo#23139). Google ignores the line, so the cost
	 * is a warning in a report, and an owner who would rather not see it can turn the lines off. The
	 * maps themselves are served either way.
	 *
	 * @param mixed $settings The `llmmd_settings` option.
	 * @return bool
	 */
	public static function robots_enabled_in( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings[ self::ROBOTS_SETTING ] ) ) {
			return true;
		}
		return '0' !== (string) $settings[ self::ROBOTS_SETTING ];
	}

	/**
	 * Whether the `Schemamap:` lines go into robots.txt, read from the stored setting.
	 *
	 * @return bool
	 */
	public static function robots_enabled() {
		return self::robots_enabled_in( get_option( 'llmmd_settings', array() ) );
	}

	/**
	 * Add a `schemamap:` directive to robots.txt for every Schema Map on this site.
	 *
	 * The spec allows several. Each URL is added once, and one already present (from another plugin
	 * or a static robots.txt) is left alone rather than repeated.
	 *
	 * @param string $output The robots.txt content.
	 * @return string The robots.txt content.
	 */
	public static function add_schemamap_directive( $output ) {
		if ( ! self::robots_enabled() ) {
			return $output;
		}
		$lines = array();
		foreach ( self::schemamap_urls() as $url ) {
			if ( preg_match( '/^\s*schemamap:\s*' . preg_quote( $url, '/' ) . '\s*$/mi', $output ) ) {
				continue;
			}
			$lines[] = 'Schemamap: ' . $url;
		}
		if ( ! $lines ) {
			return $output;
		}
		return rtrim( $output, "\n" ) . "\n\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * The Schema Maps robots.txt should point to: this plugin's own, then any other on the site.
	 *
	 * @return string[] Absolute URLs.
	 */
	public static function schemamap_urls() {
		$urls  = array( self::schema_map_url() );
		$yoast = self::yoast_schemamap_url();
		if ( '' !== $yoast ) {
			$urls[] = $yoast;
		}

		/**
		 * Filters the Schema Maps advertised in robots.txt.
		 *
		 * @param string[] $urls Absolute URLs. This plugin's own map comes first.
		 */
		$urls = apply_filters( 'mmsar_schemamap_urls', $urls );

		$clean = array();
		foreach ( (array) $urls as $url ) {
			if ( is_string( $url ) && preg_match( '#^https?://\S+$#i', $url ) && ! in_array( $url, $clean, true ) ) {
				$clean[] = $url;
			}
		}
		return $clean;
	}

	/**
	 * The URL of Yoast SEO's Schema Map, when its schema aggregation endpoint is switched on.
	 *
	 * Yoast serves the map at /schemamap.xml (no hyphen) and listed it in robots.txt itself until
	 * Yoast SEO 27.5, which dropped the directive. Without one, a crawler that starts from robots.txt
	 * never finds Yoast's per-type schema.org feeds. Both checks are needed: a switched-on option
	 * left behind by a Yoast that no longer serves the route must not be advertised.
	 *
	 * Read from the stored `wpseo` option, as MMSAR_Noindex reads Yoast's, rather than through
	 * Yoast's classes.
	 *
	 * @return string Absolute URL, or '' when Yoast's map is not being served.
	 */
	public static function yoast_schemamap_url() {
		if ( ! class_exists( 'Yoast\WP\SEO\Schema_Aggregator\User_Interface\Schemamap_Xml_Rewrite_Integration' ) ) {
			return '';
		}
		$options = get_option( 'wpseo', array() );
		if ( ! is_array( $options ) || true !== ( $options['enable_schema_aggregation_endpoint'] ?? false ) ) {
			return '';
		}
		return home_url( '/schemamap.xml' );
	}

	/**
	 * The feeds listed in this plugin's Schema Map.
	 *
	 * @return array[] Each with 'loc', 'content_type' and 'lastmod'.
	 */
	public static function schema_map_entries() {
		$entries = array(
			array(
				'loc'          => home_url( '/feed/' ),
				'content_type' => self::CONTENT_TYPE_RSS,
				'lastmod'      => self::last_modified(),
			),
		);

		/**
		 * Filters the feeds listed in the Schema Map.
		 *
		 * Each entry needs an absolute 'loc' and a 'content_type' (`structuredData/schema.org` for
		 * a JSON Lines feed, `structuredData/rss` for RSS 2.0); 'lastmod' is optional. An entry
		 * from before 1.60.0 that gives 'type' => 'application/rss+xml' instead is still read as
		 * RSS; any other entry without a content type is dropped.
		 *
		 * @param array[] $entries Feeds.
		 */
		$entries = apply_filters( 'mmsar_schema_map_feeds', $entries );

		$clean = array();
		foreach ( (array) $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['loc'] ) || ! is_string( $entry['loc'] ) || ! preg_match( '#^https?://\S+$#i', $entry['loc'] ) ) {
				continue;
			}
			$content_type = '';
			if ( isset( $entry['content_type'] ) && is_string( $entry['content_type'] ) ) {
				$content_type = trim( $entry['content_type'] );
			} elseif ( isset( $entry['type'] ) && 'application/rss+xml' === $entry['type'] ) {
				$content_type = self::CONTENT_TYPE_RSS;
			}
			if ( '' === $content_type ) {
				continue;
			}
			$clean[] = array(
				'loc'          => $entry['loc'],
				'content_type' => $content_type,
				'lastmod'      => isset( $entry['lastmod'] ) && is_string( $entry['lastmod'] ) ? $entry['lastmod'] : '',
			);
		}
		return $clean;
	}

	/**
	 * Render a Schema Map.
	 *
	 * @param array[] $entries Output of schema_map_entries().
	 * @return string XML, every value escaped.
	 */
	public static function render_schema_map( array $entries ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:sf="' . self::SCHEMAFEED_NS . '">' . "\n";
		foreach ( $entries as $entry ) {
			// The type is written twice on purpose. The spec reads the `sf:contentType` element below;
			// NLWeb's own crawler (nlweb-ai/crawler, code/core/master.py) reads a `contentType`
			// attribute on `<url>` and never looks for the element. Each reader ignores the other form.
			$xml .= '  <url contentType="' . esc_xml( $entry['content_type'] ) . '">' . "\n";
			$xml .= '    <loc>' . esc_xml( $entry['loc'] ) . "</loc>\n";
			if ( '' !== $entry['lastmod'] ) {
				$xml .= '    <lastmod>' . esc_xml( $entry['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= '    <sf:contentType>' . esc_xml( $entry['content_type'] ) . "</sf:contentType>\n";
			$xml .= "  </url>\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/**
	 * Serve the Schema Map.
	 *
	 * Lists the structured feeds of this site's content, so a crawler building an index can take
	 * them wholesale instead of walking every page.
	 *
	 * @return void
	 */
	private static function serve_schema_map() {
		$xml = self::render_schema_map( self::schema_map_entries() );

		mmsar_send_cache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'schema-map.xml' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );

		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML; render_schema_map() escapes every value with esc_xml().
		exit;
	}

	/**
	 * When this site's content last changed, as an ISO 8601 date.
	 *
	 * @return string Date.
	 */
	private static function last_modified() {
		$latest = get_posts(
			array(
				'post_type'      => mmsar_get_enabled_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		return $latest ? get_the_modified_date( 'c', $latest[0] ) : gmdate( 'c' );
	}
}
