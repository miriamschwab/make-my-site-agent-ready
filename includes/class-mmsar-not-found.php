<?php
/**
 * Makes a 404 recoverable for an agent.
 *
 * A person who lands on a 404 has a back button, a nav bar and a search box. An agent has whatever
 * is in the response, and the usual WordPress 404 gives it a themed HTML page whose only machine-
 * readable content is the status code — correct, and useless. It knows the URL was wrong; it has no
 * idea what the right one might be.
 *
 * This adds the missing half, without touching how the page looks:
 *
 *   - `Link` headers on every 404 pointing at the sitemap, llms.txt and the endpoint catalog, so a
 *     client can find its way from the headers alone and never parse the body.
 *   - The same links as `<link rel>` elements in `<head>`, for clients that read the document but
 *     not the headers.
 *   - A Markdown body, in place of the HTML page, when the request explicitly asked for Markdown —
 *     a short list of where to look next instead of a themed error page.
 *   - A JSON body, in the same shape as every other error this site returns, when the request asked
 *     for JSON. A client calling what it believes is an API and receiving a themed HTML page cannot
 *     tell a wrong URL from a broken server, and has to parse markup to find out — which is exactly
 *     the failure mode "agents can't parse HTML error pages" describes.
 *
 * Nothing here changes what a browser gets. Markdown and JSON are only ever served to a request
 * that named `text/markdown` or `application/json` and ranked it above HTML, which no browser
 * does — a browser's `Accept` leads with `text/html` and reaches JSON only through a wildcard.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR 404 handler.
 */
class MMSAR_Not_Found {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 11, which is after core's own redirect handlers rather than before them. It used
		// to be 2, and that was wrong in a way only agents could see: `wp_old_slug_redirect()` and
		// `redirect_canonical()` both run at 10 and both act on a 404, so answering at 2 meant this
		// class decided a request was unrecoverable while WordPress was still holding the recovery.
		// The visible symptom was one URL behaving two ways — a renamed post's old address returned
		// a 301 to a browser and a hard 404 to anything that asked for Markdown or JSON, because
		// only the second path reached this handler first. A redirect is always the better answer
		// than a list of places to look instead, so core goes first and this fills in what is left.
		//
		// It is still after MMSAR_Server's negotiated-markdown handler at 1. That one only ever acts
		// on a singular request, so the two cannot both fire, but ordering them explicitly keeps it
		// that way if either condition is ever loosened.
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 11 );
		add_action( 'wp_head', array( __CLASS__, 'render_head_links' ) );
	}

	/**
	 * The recovery destinations for this site, in the order an agent should try them.
	 *
	 * Only endpoints that are actually being served. Sending an agent that has already hit one 404
	 * to a second one is worse than telling it nothing.
	 *
	 * @return array[] List of [ 'href', 'rel', 'type', 'label' ].
	 */
	public static function destinations() {
		$destinations = array();

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			// Scoped the same way the Link header on a live page is: the index covering the path
			// that was asked for. On a 404 that is the more useful of the two, because the section
			// an agent was already in is where the URL it wanted most likely lives.
			$section        = MMSAR_LLMs_Txt::section_for_request();
			$destinations[] = array(
				'href'  => MMSAR_LLMs_Txt::url_for_request(),
				'rel'   => 'describedby',
				'type'  => 'text/plain',
				'label' => $section
					? 'An index of the ' . strtolower( $section['label'] ) . ' on this site, one line each — the section the URL you asked for is under. Start here.'
					: 'An index of every page on this site, one line each. Start here.',
			);
		}

		$sitemap = mmsar_get_sitemap_url();
		if ( $sitemap ) {
			$destinations[] = array(
				'href'  => $sitemap,
				'rel'   => 'sitemap',
				'type'  => 'application/xml',
				'label' => 'The XML sitemap, listing every URL that exists.',
			);
		}

		if ( mmsar_feature_enabled( 'openapi' ) ) {
			$destinations[] = array(
				'href'  => MMSAR_OpenAPI::url(),
				'rel'   => 'service-desc',
				'type'  => 'application/json',
				'label' => 'OpenAPI description of this site\'s API, including how to search it.',
			);
		}

		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$destinations[] = array(
				'href'  => home_url( '/.well-known/api-catalog' ),
				'rel'   => 'api-catalog',
				'type'  => 'application/linkset+json',
				'label' => 'Every machine-readable endpoint this site publishes.',
			);
		}

		$destinations[] = array(
			'href'  => home_url( '/' ),
			'rel'   => 'home',
			'type'  => 'text/html',
			'label' => 'The site homepage.',
		);

		/**
		 * Filters where a 404 sends an agent next.
		 *
		 * @param array[] $destinations List of [ 'href', 'rel', 'type', 'label' ].
		 */
		$filtered = apply_filters( 'mmsar_not_found_destinations', $destinations );
		return is_array( $filtered ) ? $filtered : $destinations;
	}

	/**
	 * Act on a 404.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! is_404() || is_robots() || is_feed() ) {
			return;
		}

		self::send_link_headers();

		// JSON goes out when the client asked for it, and also when the URL it asked for is an API
		// address. The second case is the one that matters in practice: a client requesting
		// /api/v1 has told you what it is by the request line alone, and answering a plainly
		// API-shaped request with a themed HTML error page is the exact failure "agents can't parse
		// HTML error pages" describes. A wrong guess costs a caller nothing — it asked for an API
		// path and got a JSON error saying there is no API there, which is the true answer.
		if ( self::prefers( 'application/json' ) || self::looks_like_api_path() ) {
			self::serve_json();
		}

		// The HTML page is what a browser, and anything that has not asked otherwise, still gets.
		if ( ! self::prefers_markdown() ) {
			return;
		}

		// Both headers matter for the same reason they do on the negotiated page responses: a shared
		// cache that ignores `Vary` would otherwise be free to hand this Markdown body to the next
		// person who mistypes the same URL.
		header( 'Vary: Accept', false );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		MMSAR_Agent_Log::record( '404 (markdown)', self::requested_path() );
		status_header( 404 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo self::body();
		exit;
	}

	/**
	 * The path that produced this 404, for the log.
	 *
	 * A count of 404s says agents are asking for something that is not there; it cannot say what,
	 * which is the only part that leads anywhere. A crawler repeatedly guessing at a URL pattern
	 * the site could support looks exactly like a crawler failing at random until the paths are
	 * visible side by side.
	 *
	 * The sanitising moved to MMSAR_Agent_Log::request_path() in 1.24.0, when the markdown surfaces
	 * started recording a path too: both write caller-influenced text into the same column, so they
	 * are bounded by the same code rather than by two copies of it that can drift.
	 *
	 * @return string Leading-slash path, or '/' when there is nothing to report.
	 */
	private static function requested_path() {
		return MMSAR_Agent_Log::request_path();
	}

	/**
	 * Whether the requested path is one only an API client would ask for.
	 *
	 * Deliberately a short, conservative list of the conventional API roots. It is not trying to
	 * guess intent from anything subtle — a human typing a URL does not arrive at `/api/v2/`, and a
	 * path under `/wp-json/` is by definition a REST call, so these are the cases where JSON is
	 * unambiguously the right error format regardless of what the client said it accepts.
	 *
	 * @return bool True when the path is an API address.
	 */
	private static function looks_like_api_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return false;
		}
		$path = strtolower( trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' ) );
		if ( '' === $path ) {
			return false;
		}

		$prefixes = array( 'api', 'wp-json', 'graphql', 'rest', 'v1', 'v2', 'v3' );

		/**
		 * Filters the path prefixes treated as API addresses for error formatting.
		 *
		 * A path equal to one of these, or beginning with one followed by a slash, gets a JSON error
		 * on a 404 even when the client did not ask for JSON.
		 *
		 * @param string[] $prefixes Lowercase path prefixes, without slashes.
		 */
		$prefixes = (array) apply_filters( 'mmsar_api_path_prefixes', $prefixes );

		foreach ( $prefixes as $prefix ) {
			$prefix = strtolower( trim( (string) $prefix, '/' ) );
			if ( '' === $prefix ) {
				continue;
			}
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * End the request with a JSON 404.
	 *
	 * Does not return.
	 *
	 * @return void
	 */
	private static function serve_json() {
		header( 'Vary: Accept', false );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		MMSAR_Agent_Log::record( '404 (JSON)', self::requested_path() );
		status_header( 404 );

		echo wp_json_encode( self::json_body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * The JSON 404 document.
	 *
	 * Deliberately the same `code` / `message` / `data.status` shape WordPress's REST API returns for
	 * every one of its own errors. A client that has learned to read one error from this site can
	 * then read all of them, and the OpenAPI document can describe the shape once and mean it
	 * everywhere — which it could not while a 404 outside /wp-json/ came back as markup.
	 *
	 * `links` carries the same destinations as the `Link` headers, in the body, for clients that
	 * parse a response but not its headers.
	 *
	 * @return array The error document.
	 */
	public static function json_body() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$links = array();
		foreach ( self::destinations() as $destination ) {
			$links[] = array(
				'href'  => $destination['href'],
				'rel'   => $destination['rel'],
				'type'  => $destination['type'],
				'title' => $destination['label'],
			);
		}

		$document = array(
			'code'    => 'not_found',
			'message' => 'There is no resource at this URL on ' . $site_name . '. See `links` for where to look instead.',
			'data'    => array( 'status' => 404 ),
			'links'   => $links,
		);

		/**
		 * Filters the JSON document served for a 404.
		 *
		 * @param array $document The error document.
		 */
		$filtered = apply_filters( 'mmsar_not_found_json', $document );
		return is_array( $filtered ) ? $filtered : $document;
	}

	/**
	 * The Markdown body served for a 404.
	 *
	 * Also used for the `.md` mirror's own not-found responses, so an agent gets the same recovery
	 * instructions whichever way it arrived at a missing page.
	 *
	 * @param string $heading Optional. Heading line, without the leading `#`.
	 * @param string $reason  Optional. One sentence on what went wrong, replacing the default.
	 * @return string Markdown.
	 */
	public static function body( $heading = '', $reason = '' ) {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines   = array();
		$lines[] = '# ' . ( '' !== $heading ? $heading : '404 Not Found' );
		$lines[] = '';
		$lines[] = '' !== $reason
			? $reason
			: 'There is no page at this URL on ' . $site_name . '. The address may be mistyped, or the page may have moved.';
		$lines[] = '';
		$lines[] = '## Where to look instead';
		$lines[] = '';

		foreach ( self::destinations() as $destination ) {
			$lines[] = '- [' . $destination['href'] . '](' . $destination['href'] . ') — ' . $destination['label'];
		}

		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$lines[] = '';
			$lines[] = 'Any page on this site is also available as Markdown: take its normal URL and add `.md`.';
		}

		/**
		 * Filters the Markdown body served for a 404.
		 *
		 * @param string $markdown The body.
		 */
		$markdown = apply_filters( 'mmsar_not_found_body', implode( "\n", $lines ) . "\n" );

		return (string) $markdown;
	}

	/**
	 * Send `Link` headers pointing at the recovery destinations.
	 *
	 * Headers rather than body content because they survive everything: a client that only issued a
	 * HEAD, one that discards a non-200 body, and one that never parses HTML all still see them.
	 *
	 * @return void
	 */
	private static function send_link_headers() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::destinations() as $destination ) {
			header(
				sprintf(
					'Link: <%1$s>; rel="%2$s"; type="%3$s"',
					esc_url_raw( $destination['href'] ),
					$destination['rel'],
					$destination['type']
				),
				false
			);
		}
	}

	/**
	 * Mirror the recovery destinations into `<head>` on the HTML 404 page.
	 *
	 * @return void
	 */
	public static function render_head_links() {
		if ( ! is_404() ) {
			return;
		}
		foreach ( self::destinations() as $destination ) {
			printf(
				'<link rel="%1$s" type="%2$s" href="%3$s" />' . "\n",
				esc_attr( $destination['rel'] ),
				esc_attr( $destination['type'] ),
				esc_url( $destination['href'] )
			);
		}
	}

	/**
	 * Whether the request explicitly asked for Markdown over HTML.
	 *
	 * @return bool True when Markdown is explicitly preferred.
	 */
	private static function prefers_markdown() {
		return self::prefers( 'text/markdown' );
	}

	/**
	 * Whether the request's `Accept` header names the given type and ranks it above HTML.
	 *
	 * The same strict test MMSAR_Server applies to page requests, generalized over the type: it has
	 * to be named explicitly and has to outrank HTML, a wildcard counts towards HTML and never
	 * towards the requested type, and a tie goes to HTML. Strictness is the whole point: a browser's
	 * Accept header leads with text/html and covers everything else with a low-q wildcard, so it
	 * names neither markdown nor JSON and can never match — which is what keeps a person from being
	 * handed a file download in place of a page.
	 *
	 * @param string $wanted Media type to test for.
	 * @return bool True when $wanted is explicitly preferred over HTML.
	 */
	private static function prefers( $wanted ) {
		$header = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $header ) {
			return false;
		}

		// The spellings each type travels under. Clients are inconsistent about all of them, and a
		// request that says `application/problem+json` has unambiguously asked for a JSON error.
		$aliases = array(
			'text/markdown'    => array( 'text/markdown', 'text/x-markdown' ),
			'application/json' => array( 'application/json', 'application/problem+json', 'text/json' ),
		);
		$names   = isset( $aliases[ $wanted ] ) ? $aliases[ $wanted ] : array( $wanted );

		$wanted_q = -1.0;
		$html_q   = -1.0;

		foreach ( explode( ',', $header ) as $part ) {
			$bits = explode( ';', $part );
			$type = strtolower( trim( array_shift( $bits ) ) );
			if ( '' === $type ) {
				continue;
			}

			$q = 1.0;
			foreach ( $bits as $param ) {
				$param = strtolower( trim( $param ) );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
				}
			}

			if ( in_array( $type, $names, true ) ) {
				$wanted_q = max( $wanted_q, $q );
			} elseif ( 'text/html' === $type || 'text/*' === $type || '*/*' === $type ) {
				$html_q = max( $html_q, $q );
			}
		}

		return $wanted_q > 0 && $wanted_q > $html_q;
	}
}
