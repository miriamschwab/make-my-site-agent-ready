<?php
/**
 * OpenAPI description of this site's agent-facing surface, served at /openapi.json.
 *
 * The documents this plugin already publishes tell an agent what exists; none of them tell it how
 * to *call* anything. api-catalog is a list of links, llms.txt is prose, and the Agent Skills
 * SKILL.md is instructions for a model rather than a machine-readable contract. OpenAPI is the one
 * format an HTTP client can turn into working requests without a human reading anything, and it is
 * what agent-readiness scanners look for at /openapi.json.
 *
 * Everything in the generated document is derived from what this site actually serves — the feature
 * toggles, the REST routes really registered on this install, and the endpoints in the registry.
 * A spec that documents an endpoint the site does not answer is worse than no spec at all, because
 * an agent will build a request from it and get a 404.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR OpenAPI document.
 */
class MMSAR_OpenAPI {

	/**
	 * OpenAPI version the document declares. 3.1.0 rather than 3.0.x because 3.1 aligns the schema
	 * dialect with JSON Schema proper, which is what tooling that feeds specs to models expects.
	 */
	const OPENAPI_VERSION = '3.1.0';

	/**
	 * Transient holding the generated document.
	 */
	const TRANSIENT = 'mmsar_openapi';

	/**
	 * Core REST routes worth documenting, in display order.
	 *
	 * A curated list rather than the whole route table on purpose. This site's full REST index is
	 * hundreds of kilobytes on a normal install with a handful of plugins, and a spec that large is
	 * useless to an agent — it blows past context limits before it says anything. These are the read
	 * routes that answer "what content is on this site", which is what an agent fetching a spec from
	 * a content site is nearly always trying to do.
	 *
	 * Each entry is only emitted when the route is genuinely registered on this install, so a site
	 * that has removed or restricted part of the REST API does not end up advertising it.
	 *
	 * @return array<string, array> Route pattern => descriptor.
	 */
	private static function core_routes() {
		return array(
			'/wp/v2/posts'               => array(
				'summary'     => 'List posts',
				'description' => 'Published posts, newest first. Supports search, pagination and filtering by category or tag.',
				'params'      => array( 'search', 'page', 'per_page', 'categories', 'tags', 'slug' ),
				'array'       => true,
			),
			'/wp/v2/posts/(?P<id>[\d]+)' => array(
				'summary'     => 'Get a single post',
				'description' => 'One post by numeric id.',
				'path_param'  => 'id',
			),
			'/wp/v2/pages'               => array(
				'summary'     => 'List pages',
				'description' => 'Published pages. Supports search, pagination and filtering by slug or parent.',
				'params'      => array( 'search', 'page', 'per_page', 'slug', 'parent' ),
				'array'       => true,
			),
			'/wp/v2/pages/(?P<id>[\d]+)' => array(
				'summary'     => 'Get a single page',
				'description' => 'One page by numeric id.',
				'path_param'  => 'id',
			),
			'/wp/v2/search'              => array(
				'summary'     => 'Search the site',
				'description' => 'Search across every searchable post type at once. Returns id, title, url and type for each hit — fetch the full text from the url with a .md suffix.',
				'params'      => array( 'search', 'page', 'per_page', 'type', 'subtype' ),
				'array'       => true,
			),
			'/wp/v2/categories'          => array(
				'summary'     => 'List categories',
				'description' => 'Every category with its slug and post count.',
				'params'      => array( 'search', 'page', 'per_page', 'slug' ),
				'array'       => true,
			),
			'/wp/v2/tags'                => array(
				'summary'     => 'List tags',
				'description' => 'Every tag with its slug and post count.',
				'params'      => array( 'search', 'page', 'per_page', 'slug' ),
				'array'       => true,
			),
		);
	}

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
	}

	/**
	 * Add rewrite rules.
	 *
	 * Two addresses for the same document. /openapi.json is where scanners and most agents look
	 * first; /.well-known/openapi.json is the tidier location and the one a site with its own
	 * root-level files may prefer. Serving both costs nothing and saves a guess.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		if ( ! self::is_serving() ) {
			return;
		}
		add_rewrite_rule( '^openapi\.json$', 'index.php?mmsar_openapi=1', 'top' );
		add_rewrite_rule( '^\.well-known/openapi\.json$', 'index.php?mmsar_openapi=1', 'top' );
	}

	/**
	 * Whether this plugin should be the one answering at /openapi.json.
	 *
	 * A rewrite rule registered at the top of the stack wins over a file on disk, so registering
	 * one unconditionally would take over a hand-written or build-generated spec that the site
	 * owner put there deliberately — and replace a real description of their API with a generated
	 * description of their blog. Whoever put a file there meant it; stand down.
	 *
	 * Checked in three places rather than one because each has a different failure: the rewrite
	 * rule (would hijack the file), the Link header (would advertise a spec as ours when it is
	 * not), and the api-catalog entry (same).
	 *
	 * @return bool True when the plugin should serve and advertise the document.
	 */
	public static function is_serving() {
		if ( file_exists( ABSPATH . 'openapi.json' ) ) {
			return false;
		}

		/**
		 * Filters whether the plugin serves its generated OpenAPI document.
		 *
		 * Return false where something else on the site already publishes a spec at that address
		 * by a route this cannot see — a server-level alias, or a plugin that hooks the request
		 * earlier.
		 *
		 * @param bool $is_serving Whether to serve the generated document.
		 */
		return (bool) apply_filters( 'mmsar_openapi_is_serving', true );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_openapi';
		return $vars;
	}

	/**
	 * The public URL of the OpenAPI document.
	 *
	 * @return string Absolute URL.
	 */
	public static function url() {
		return home_url( '/openapi.json' );
	}

	/**
	 * Serve the document.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( ! get_query_var( 'mmsar_openapi' ) || ! self::is_serving() ) {
			return;
		}

		$document = get_transient( self::TRANSIENT );
		if ( false === $document || ! is_array( $document ) ) {
			$document = self::build();
			set_transient( self::TRANSIENT, $document, HOUR_IN_SECONDS );
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'openapi.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Builds the whole document.
	 *
	 * @return array The OpenAPI document as a PHP array.
	 */
	public static function build() {
		$site_name   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$document = array(
			'openapi'    => self::OPENAPI_VERSION,
			'info'       => array(
				'title'       => $site_name . ' API',
				'version'     => MMSAR_VERSION,
				'summary'     => 'Read this site\'s content as JSON or Markdown, without parsing HTML.',
				'description' => self::info_description( $site_name, $description ),
				// The same lifecycle facts the description gives in prose, in fields a client can
				// read without parsing English. An `x-` extension because OpenAPI has no standard
				// place for a deprecation policy — the prose stays authoritative for a human, and
				// this is here so "how will I be told before something breaks" is answerable
				// mechanically. `deprecated` lists what is actually scheduled, so it is empty on a
				// site that is retiring nothing rather than describing a policy in the abstract.
				'x-lifecycle' => self::lifecycle(),
			),
			'servers'    => array(
				array(
					'url'         => untrailingslashit( home_url( '/' ) ),
					'description' => $site_name,
				),
			),
			// An empty security array is the spec's way of saying "no authentication required", and
			// saying it explicitly matters more here than it looks: an agent reading a spec with no
			// `security` field at all cannot tell whether the API is open or whether the author just
			// left it out, and the safe assumption it then makes is that it needs credentials it does
			// not have. Every endpoint documented here is public, so state it once at the root.
			'security'   => array(),
			'paths'      => self::paths(),
			'components' => self::components(),
		);

		// Point at the human-and-model-readable companions rather than duplicating them. An agent
		// that has the spec can still want the prose.
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$document['externalDocs'] = array(
				'description' => 'Plain-text index of this site\'s content, written for language models.',
				'url'         => home_url( '/llms.txt' ),
			);
		}

		/**
		 * Filters the complete OpenAPI document before it is served.
		 *
		 * The escape hatch for anything the generator does not model — extra paths, security
		 * schemes, or a hand-written description of an endpoint whose registry entry cannot carry
		 * enough detail. Runs before the document is cached.
		 *
		 * @param array $document The OpenAPI document, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_openapi_document', $document );

		return is_array( $filtered ) ? $filtered : $document;
	}

	/**
	 * The prose description in `info`.
	 *
	 * Worth writing carefully: for a model reading the spec, this is the paragraph that decides
	 * whether it uses the right endpoint for the job or falls back to scraping HTML.
	 *
	 * @param string $site_name   Site title.
	 * @param string $description Site tagline.
	 * @return string Markdown description.
	 */
	private static function info_description( $site_name, $description ) {
		$home  = home_url( '/' );
		$lines = array();

		$lines[] = trim( $description ) !== '' ? $description : 'The public API surface of ' . $site_name . '.';
		$lines[] = '';
		$lines[] = 'Everything documented here is public and requires no authentication.';
		$lines[] = '';
		$lines[] = '**Which endpoint to use:**';
		$lines[] = '';

		if ( mmsar_feature_enabled( 'markdown' ) ) {
			$lines[] = '- To read one page, append `.md` to its normal URL — `' . $home . 'about.md`. That returns clean Markdown and is cheaper than fetching the HTML and stripping tags.';
		}
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$lines[] = '- To see what content exists, fetch `/llms.txt` — one line per page, in one request.';
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$lines[] = '- To read the entire site at once, fetch `/llms-full.txt`.';
		}
		$lines[] = '- To search or filter, use the JSON routes under `/wp-json/wp/v2/`.';
		$lines[] = '';
		$lines[] = '**Errors** are always JSON, never an HTML page. Every failing request returns an object with `code`, `message` and `data.status` — see the `Error` schema. Send `Accept: application/json` to get that shape from any URL on this site, including one that is not a documented route.';
		$lines[] = '';
		$lines[] = '**Authentication:** none, anywhere. The root `security: []` says so formally; [`/auth.md`](' . MMSAR_Auth_Md::url() . ') says so in prose, including what to do about the one endpoint that uses a single-use token.';
		$lines[] = '';
		$lines[] = '**Rate limits:** only the MCP endpoint is limited. It returns `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` on every response, and `Retry-After` on a 429, so you can pace yourself rather than discover the limit by hitting it. The plain HTTP routes are unlimited.';
		$lines[] = '';
		$lines[] = '**Versioning:** this description is regenerated from the live site, so it always matches what is deployed. Paths under `/wp-json/` carry their own version segment. '
			. ( MMSAR_Deprecation::has_schedule()
				? 'Something here is scheduled for retirement: the affected responses carry `Deprecation` and `Sunset` headers (RFC 9745 / RFC 8594), and `x-lifecycle.deprecated` below lists them with their dates.'
				: 'Nothing here is currently scheduled for removal. When something is, its responses carry `Deprecation` and `Sunset` headers (RFC 9745 / RFC 8594) from the moment it is announced until it stops working, and it is listed in `x-lifecycle.deprecated` below — so a client that watches either one gets notice rather than a surprise 404.' );

		return implode( "\n", $lines );
	}

	/**
	 * Machine-readable lifecycle facts.
	 *
	 * Split out from the prose so the two cannot drift: both this and the Versioning paragraph read
	 * the same schedule, so a site that retires a route updates its spec by doing so rather than by
	 * remembering to edit a description.
	 *
	 * @return array Lifecycle object.
	 */
	private static function lifecycle() {
		$deprecated = array();

		foreach ( MMSAR_Deprecation::schedule() as $surface => $entry ) {
			$headers = MMSAR_Deprecation::headers_for( $entry );
			if ( array() === $headers ) {
				// An entry that produces no header is not a deprecation anyone can observe, so it is
				// not reported as one.
				continue;
			}

			$row = array( 'surface' => '/' . trim( (string) $surface, '/' ) );
			if ( isset( $headers['Deprecation'] ) ) {
				$row['deprecation'] = $headers['Deprecation'];
			}
			if ( isset( $headers['Sunset'] ) ) {
				$row['sunset'] = $headers['Sunset'];
			}
			if ( isset( $entry['link'] ) ) {
				$row['info'] = esc_url_raw( (string) $entry['link'] );
			}

			$deprecated[] = $row;
		}

		return array(
			'versioning'    => 'url-path',
			'signalling'    => array(
				// Named as the specs name them, including the detail that the two headers do not
				// share a format: Deprecation is a structured-field Date ("@" plus a Unix
				// timestamp), Sunset is an HTTP-date.
				'deprecation-header' => array(
					'spec'   => 'RFC 9745',
					'format' => 'structured-field Date, e.g. @1688169599',
				),
				'sunset-header'      => array(
					'spec'   => 'RFC 8594',
					'format' => 'HTTP-date, e.g. Sat, 31 Dec 2018 23:59:59 GMT',
				),
				'link-relations'     => array( 'deprecation', 'sunset' ),
			),
			'deprecated'    => $deprecated,
			'authoritative' => self::url(),
		);
	}

	/**
	 * Every path in the document.
	 *
	 * @return array Paths object.
	 */
	private static function paths() {
		$paths = array();

		$paths = array_merge( $paths, self::document_paths() );
		$paths = array_merge( $paths, self::markdown_path() );
		$paths = array_merge( $paths, self::rest_paths() );
		$paths = array_merge( $paths, self::registry_paths() );

		return $paths;
	}

	/**
	 * The plain-text and JSON documents this plugin publishes, each gated on its own feature.
	 *
	 * @return array Paths object fragment.
	 */
	private static function document_paths() {
		$paths = array();

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$paths['/llms.txt'] = self::text_get(
				'llms',
				'Content index for language models',
				'A curated, one-line-per-page index of this site, in the llms.txt format. The cheapest way to find out what exists here.',
				'text/plain'
			);
		}
		if ( mmsar_feature_enabled( 'llms_full_txt' ) ) {
			$paths['/llms-full.txt'] = self::text_get(
				'llmsFull',
				'Every page as one document',
				'The full Markdown text of every published post and page, concatenated, each entry separated by `---` with its title and URL. One request for the whole corpus.',
				'text/plain'
			);
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$paths['/.well-known/api-catalog'] = self::text_get(
				'apiCatalog',
				'Machine-readable endpoint catalog',
				'This site\'s endpoints as an RFC 9264 linkset (RFC 9727).',
				'application/linkset+json'
			);
		}
		if ( mmsar_feature_enabled( 'agent_skills' ) ) {
			$paths['/.well-known/agent-skills/index.json'] = self::text_get(
				'agentSkills',
				'Agent Skills discovery index',
				'Lists the skills this site publishes, each pointing at a SKILL.md describing how to work with the site.',
				'application/json'
			);
		}
		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			$paths['/.well-known/security.txt'] = self::text_get(
				'securityTxt',
				'Security contact',
				'How to report a security issue with this site (RFC 9116).',
				'text/plain'
			);
		}
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$paths[ '/' . ltrim( wp_parse_url( MMSAR_MCP::endpoint_url(), PHP_URL_PATH ), '/' ) ] = self::mcp_path();
		}

		return $paths;
	}

	/**
	 * A plain GET that returns a document, with no parameters.
	 *
	 * @param string $operation_id OpenAPI operationId.
	 * @param string $summary      One-line summary.
	 * @param string $description  Longer description.
	 * @param string $media_type   Media type actually returned.
	 * @return array Path item object.
	 */
	private static function text_get( $operation_id, $summary, $description, $media_type ) {
		$schema = 'text/plain' === $media_type
			? array( 'type' => 'string' )
			: array( 'type' => 'object' );

		return array(
			'get' => array(
				'operationId' => $operation_id,
				'summary'     => $summary,
				'description' => $description,
				'responses'   => array(
					'200'     => array(
						'description' => 'The document.',
						'content'     => array(
							$media_type => array( 'schema' => $schema ),
						),
					),
					// Reachable when the feature that publishes this document is switched off. The
					// site's 404 handler answers a JSON-preferring request with the same Error
					// shape as every other endpoint here, so the reference is accurate rather than
					// aspirational — see MMSAR_Not_Found.
					'404'     => array( '$ref' => '#/components/responses/Error' ),
					'5XX'     => array( '$ref' => '#/components/responses/Error' ),
					'default' => array( '$ref' => '#/components/responses/Error' ),
				),
			),
		);
	}

	/**
	 * The per-page Markdown mirror, expressed as a templated path.
	 *
	 * @return array Paths object fragment.
	 */
	private static function markdown_path() {
		if ( ! mmsar_feature_enabled( 'markdown' ) ) {
			return array();
		}

		return array(
			'/{slug}.md' => array(
				'get' => array(
					'operationId' => 'getPageMarkdown',
					'summary'     => 'Read one page as Markdown',
					'description' => 'Returns the Markdown source of a single published post or page. The path mirrors the page\'s normal URL with `.md` appended, so `' . home_url( '/about/' ) . '` is available at `' . home_url( '/about.md' ) . '`. The homepage is at `/index.md`. Prefer this over fetching and parsing the HTML.',
					'parameters'  => array(
						array(
							'name'        => 'slug',
							'in'          => 'path',
							'required'    => true,
							'description' => 'The page\'s path without leading or trailing slashes, exactly as it appears in its canonical URL. Use `index` for the homepage.',
							'schema'      => array( 'type' => 'string' ),
							'example'     => 'about',
						),
					),
					'responses'   => array(
						'200' => array(
							'description' => 'The page as Markdown.',
							'content'     => array(
								'text/markdown' => array( 'schema' => array( 'type' => 'string' ) ),
							),
						),
						// These two are the exception to the Error schema, and deliberately so: a
						// client that asked for a `.md` address wants Markdown, and gets Markdown
						// back even when the answer is "there is nothing here" — a short document
						// naming what went wrong and where to look instead.
						'403' => array(
							'description' => 'The page is password protected. Returned as Markdown, not JSON.',
							'content'     => array( 'text/markdown' => array( 'schema' => array( 'type' => 'string' ) ) ),
						),
						'404' => array(
							'description' => 'No published page at that path, or Markdown is not available for its post type. Returned as Markdown, not JSON, with links to where the content might be instead.',
							'content'     => array( 'text/markdown' => array( 'schema' => array( 'type' => 'string' ) ) ),
						),
						'5XX' => array( '$ref' => '#/components/responses/Error' ),
					),
				),
			),
		);
	}

	/**
	 * Core REST routes, filtered to the ones this install actually registers.
	 *
	 * @return array Paths object fragment.
	 */
	private static function rest_paths() {
		$registered = self::registered_rest_routes();
		if ( empty( $registered ) ) {
			return array();
		}

		// rest_url() ends in a slash and every route pattern starts with one, so the prefix has to
		// lose its own or every documented path comes out with `//` in the middle.
		$prefix = untrailingslashit( '/' . ltrim( (string) wp_parse_url( rest_url(), PHP_URL_PATH ), '/' ) );
		$paths  = array();

		foreach ( self::core_routes() as $route => $descriptor ) {
			if ( ! isset( $registered[ $route ] ) ) {
				continue;
			}

			// The route table stores WordPress's own regex form. OpenAPI wants `{id}`, so swap the
			// named capture group for the template it corresponds to.
			$path = $prefix . preg_replace( '#\(\?P<([a-z_]+)>[^)]+\)#i', '{$1}', $route );

			$parameters = array();
			if ( ! empty( $descriptor['path_param'] ) ) {
				$parameters[] = array(
					'name'     => $descriptor['path_param'],
					'in'       => 'path',
					'required' => true,
					'schema'   => array( 'type' => 'integer' ),
				);
			}
			foreach ( self::query_parameters( isset( $descriptor['params'] ) ? $descriptor['params'] : array() ) as $parameter ) {
				$parameters[] = $parameter;
			}

			$success = empty( $descriptor['array'] )
				? array( 'type' => 'object' )
				: array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				);

			$operation = array(
				'operationId' => self::operation_id( $route ),
				'summary'     => $descriptor['summary'],
				'description' => $descriptor['description'],
				'responses'   => array(
					'200'     => array(
						'description' => 'Success.',
						'content'     => array(
							'application/json' => array( 'schema' => $success ),
						),
					),
					'400'     => array( '$ref' => '#/components/responses/Error' ),
					'404'     => array( '$ref' => '#/components/responses/Error' ),
					'429'     => array( '$ref' => '#/components/responses/Error' ),
					'4XX'     => array( '$ref' => '#/components/responses/Error' ),
					'5XX'     => array( '$ref' => '#/components/responses/Error' ),
					'default' => array( '$ref' => '#/components/responses/Error' ),
				),
			);
			if ( $parameters ) {
				$operation['parameters'] = $parameters;
			}

			$paths[ $path ] = array( 'get' => $operation );
		}

		return $paths;
	}

	/**
	 * The REST routes registered on this install.
	 *
	 * Asking the REST server rather than assuming: plenty of sites restrict or remove parts of the
	 * REST API, and documenting a route that has been taken away sends agents to an error.
	 *
	 * Instantiating the server fires `rest_api_init`, which is a real cost on a plugin-heavy site.
	 * It is paid once an hour at most — the built document is cached in a transient, and this is
	 * only ever reached while building it.
	 *
	 * @return array<string, mixed> Route pattern => handlers, or an empty array if unavailable.
	 */
	private static function registered_rest_routes() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}
		$routes = rest_get_server()->get_routes();
		return is_array( $routes ) ? $routes : array();
	}

	/**
	 * Descriptions for the core query parameters worth documenting.
	 *
	 * @param string[] $names Parameter names to emit.
	 * @return array[] Parameter objects.
	 */
	private static function query_parameters( $names ) {
		$known = array(
			'search'     => array( 'string', 'Limit results to those matching this search term.' ),
			'page'       => array( 'integer', 'Page of results to return. Default 1.' ),
			'per_page'   => array( 'integer', 'Results per page, 1-100. Default 10.' ),
			'slug'       => array( 'string', 'Limit results to the item with this slug.' ),
			'categories' => array( 'string', 'Comma-separated category ids.' ),
			'tags'       => array( 'string', 'Comma-separated tag ids.' ),
			'parent'     => array( 'integer', 'Limit results to children of this page id.' ),
			'type'       => array( 'string', 'Object type to search: `post`, `term` or `post-format`.' ),
			'subtype'    => array( 'string', 'Narrow the search to one post type, e.g. `post` or `page`.' ),
		);

		$parameters = array();
		foreach ( $names as $name ) {
			if ( ! isset( $known[ $name ] ) ) {
				continue;
			}
			list( $type, $description ) = $known[ $name ];
			$parameters[]               = array(
				'name'        => $name,
				'in'          => 'query',
				'required'    => false,
				'description' => $description,
				'schema'      => array( 'type' => $type ),
			);
		}
		return $parameters;
	}

	/**
	 * A stable operationId for a core route.
	 *
	 * @param string $route Route pattern.
	 * @return string operationId.
	 */
	private static function operation_id( $route ) {
		$single = false !== strpos( $route, '(?P<' );
		$base   = preg_replace( '#/\(\?P<[a-z_]+>[^)]+\)#i', '', $route );
		$base   = str_replace( '/wp/v2/', '', $base );
		$parts  = array_map( 'ucfirst', preg_split( '#[/_-]#', $base ) );
		return ( $single ? 'get' : 'list' ) . implode( '', $parts );
	}

	/**
	 * The MCP endpoint, described as an OpenAPI path.
	 *
	 * An agent that already speaks MCP should connect over MCP rather than through this spec, so
	 * the point of documenting it here is discovery: a client that only reads OpenAPI still learns
	 * the server exists and where it lives.
	 *
	 * @return array Path item object.
	 */
	private static function mcp_path() {
		return array(
			'post' => array(
				'operationId' => 'mcp',
				'summary'     => 'Model Context Protocol endpoint',
				'description' => 'A read-only MCP server over Streamable HTTP, exposing this site\'s content as MCP tools. Requires no authentication. If your client speaks MCP, connect to this URL directly instead of using the routes above.',
				'requestBody' => array(
					'required' => true,
					'content'  => array(
						'application/json' => array(
							'schema' => array(
								'type'        => 'object',
								'description' => 'A JSON-RPC 2.0 request, as defined by the Model Context Protocol.',
							),
						),
					),
				),
				'responses'   => array(
					'200' => array(
						'description' => 'A JSON-RPC 2.0 response.',
						'content'     => array(
							'application/json' => array( 'schema' => array( 'type' => 'object' ) ),
						),
					),
					'202' => array( 'description' => 'The request was a JSON-RPC notification, which has no response.' ),
					// JSON-RPC carries its own error codes in the body; the HTTP status mirrors them
					// so a client can react without parsing.
					//
					// The 400 has two shapes, and saying so is the honest description. A body that is
					// valid JSON of the wrong type reaches this endpoint and gets a JSON-RPC -32700.
					// A body that is not valid JSON at all never reaches it: WordPress's REST server
					// rejects it with `rest_invalid_json` before any route callback runs, so that one
					// arrives in the site's own Error shape. A client must be ready for either.
					'400' => array(
						'description' => 'The request body could not be used. Either a JSON-RPC error object with code `-32700` (valid JSON, wrong type — this endpoint expects an object or an array of them), or the site\'s `Error` shape with code `rest_invalid_json` (the body was not parseable JSON, rejected by the REST server before this endpoint saw it).',
						'content'     => array(
							'application/json' => array(
								'schema' => array(
									'oneOf' => array(
										array( '$ref' => '#/components/schemas/JsonRpcError' ),
										array( '$ref' => '#/components/schemas/Error' ),
									),
								),
							),
						),
					),
					'429' => array(
						'description' => 'Rate limit exceeded. Returned as a JSON-RPC error object with code `-32000`. Read `RateLimit-Reset` and `Retry-After` before retrying.',
						'content'     => array(
							'application/json' => array(
								'schema' => array( '$ref' => '#/components/schemas/JsonRpcError' ),
							),
						),
					),
					'5XX' => array( '$ref' => '#/components/responses/Error' ),
				),
			),
		);
	}

	/**
	 * Endpoints other plugins, themes or the site owner registered.
	 *
	 * These are described by hand on the settings page or in code, so the spec can only carry what
	 * the descriptor holds: the path, the methods, one sentence, and how to authenticate. That is
	 * still enough for an agent to know the endpoint exists and to ask about it, which is the whole
	 * gap between "invisible" and "usable".
	 *
	 * Only endpoints on this site are included. A registry entry may legitimately point somewhere
	 * else, and a spec whose `servers` says this host must not list paths belonging to another.
	 *
	 * @return array Paths object fragment.
	 */
	private static function registry_paths() {
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$paths     = array();

		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( wp_parse_url( $endpoint['href'], PHP_URL_HOST ) !== $home_host ) {
				continue;
			}
			$path = wp_parse_url( $endpoint['href'], PHP_URL_PATH );
			if ( ! $path ) {
				continue;
			}

			$methods = ! empty( $endpoint['methods'] ) ? $endpoint['methods'] : array( 'GET' );
			$type    = ! empty( $endpoint['type'] ) ? $endpoint['type'] : 'application/json';

			$description = $endpoint['description'];
			if ( ! empty( $endpoint['auth'] ) && 'none' !== strtolower( $endpoint['auth'] ) ) {
				$description = trim( $description . ' Authentication: ' . $endpoint['auth'] . '.' );
			}

			foreach ( $methods as $method ) {
				$method = strtolower( $method );
				if ( ! in_array( $method, array( 'get', 'post', 'put', 'patch', 'delete' ), true ) ) {
					continue;
				}

				$operation = array(
					'operationId' => $endpoint['id'] . ( count( $methods ) > 1 ? ucfirst( $method ) : '' ),
					'summary'     => $endpoint['title'],
					'responses'   => array(
						'200'     => array(
							'description' => 'Success.',
							'content'     => array(
								$type => array( 'schema' => array( 'type' => 'object' ) ),
							),
						),
						// The 4XX range key rather than a guessed individual status: the registry
						// records what an endpoint accepts, not which codes it rejects with, and
						// naming a specific one would be inventing detail the site never supplied.
						'4XX'     => array( '$ref' => '#/components/responses/Error' ),
						'5XX'     => array( '$ref' => '#/components/responses/Error' ),
						'default' => array( '$ref' => '#/components/responses/Error' ),
					),
				);
				if ( '' !== $description ) {
					$operation['description'] = $description;
				}
				// The registry has no field for a request body's shape, so say that it takes one and
				// leave the schema open rather than inventing properties the endpoint may not accept.
				if ( in_array( $method, array( 'post', 'put', 'patch' ), true ) ) {
					$operation['requestBody'] = array(
						'required' => true,
						'content'  => array(
							'application/json' => array(
								'schema' => array(
									'type'        => 'object',
									'description' => 'Request body. This site did not publish a field-level schema for this endpoint; see its own documentation.',
								),
							),
						),
					);
				}

				if ( ! isset( $paths[ $path ] ) ) {
					$paths[ $path ] = array();
				}
				$paths[ $path ][ $method ] = $operation;
			}
		}

		return $paths;
	}

	/**
	 * Shared components — chiefly the error shape.
	 *
	 * Worth stating explicitly. "Does this API return structured JSON errors, or an HTML error
	 * page?" is one of the things that decides whether an agent can recover from a failed call,
	 * and WordPress's REST API has always answered it well; it has just never said so anywhere a
	 * machine could read.
	 *
	 * @return array Components object.
	 */
	private static function components() {
		return array(
			'schemas'   => array(
				'Error'        => array(
					'type'        => 'object',
					'title'       => 'Error',
					'description' => 'Every error from this site has this shape, including a 404 on a URL that is not part of any documented route — ask for `application/json` and that is what comes back. Errors are never returned as HTML to a client that asked for JSON.',
					'required'    => array( 'code', 'message', 'data' ),
					'properties'  => array(
						'code'    => array(
							'type'        => 'string',
							'description' => 'Stable machine-readable error code, e.g. `rest_post_invalid_id`. Match on this, not on the message.',
							'examples'    => array( 'rest_no_route', 'rest_post_invalid_id' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'Human-readable explanation of what went wrong.',
							'examples'    => array( 'Invalid post ID.' ),
						),
						'data'    => array(
							'type'        => 'object',
							'description' => 'Additional context.',
							'properties'  => array(
								'status' => array(
									'type'        => 'integer',
									'description' => 'The HTTP status code, repeated here so it survives transports that drop it.',
									'examples'    => array( 404 ),
								),
								'params' => array(
									'type'        => 'object',
									'description' => 'Present on validation failures: which parameters were rejected, and why.',
								),
							),
						),
						'links'   => array(
							'type'        => 'array',
							'description' => 'Present on 404s: where to look instead. The same destinations the response carries as `Link` headers, repeated in the body for clients that do not read headers.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'href'  => array( 'type' => 'string' ),
									'rel'   => array( 'type' => 'string' ),
									'type'  => array( 'type' => 'string' ),
									'title' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'JsonRpcError' => array(
					'type'        => 'object',
					'title'       => 'JsonRpcError',
					'description' => 'The MCP endpoint speaks JSON-RPC 2.0, so its own failures use the JSON-RPC error object rather than this site\'s `Error` shape. The HTTP status mirrors the JSON-RPC code so a client can react without parsing the body.',
					'required'    => array( 'jsonrpc', 'error' ),
					'properties'  => array(
						'jsonrpc' => array(
							'type'        => 'string',
							'description' => 'Always `2.0`.',
							'const'       => '2.0',
						),
						'id'      => array(
							'description' => 'The id of the request being answered, or `null` when the request could not be parsed well enough to recover one.',
							'type'        => array( 'string', 'integer', 'null' ),
						),
						'error'   => array(
							'type'        => 'object',
							'required'    => array( 'code', 'message' ),
							'properties'  => array(
								'code'    => array(
									'type'        => 'integer',
									'description' => 'A JSON-RPC error code. `-32700` parse error, `-32600` invalid request, `-32601` method not found, `-32602` invalid params, `-32000` implementation-defined server error (used here for rate limiting).',
									'examples'    => array( -32700, -32000 ),
								),
								'message' => array(
									'type'        => 'string',
									'description' => 'Human-readable explanation of what went wrong.',
									'examples'    => array( 'Parse error: request body is not valid JSON.' ),
								),
								'data'    => array(
									'description' => 'Additional context, when there is any.',
								),
							),
							'description' => 'The error itself. Match on `code`, not on the message.',
						),
					),
				),
			),
			'responses' => array(
				// Only the site's own Error shape gets a reusable response wrapper, because only it is
				// reused — 69 of the 71 typed error responses point at it. The JSON-RPC shape appears
				// on one endpoint whose two failure codes each need their own description, so they
				// reference the schema directly. A response component nothing references is exactly
				// the "schema defined but not referenced" smell that makes a spec harder to trust.
				'Error' => array(
					'description' => 'A structured JSON error. Read `code` to decide what to do next.',
					'content'     => array(
						'application/json' => array(
							'schema' => array( '$ref' => '#/components/schemas/Error' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Drops the cached document.
	 *
	 * The spec is derived from the feature toggles, the registry and the site's own title, so any
	 * of those changing makes the cached copy wrong.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}
}
