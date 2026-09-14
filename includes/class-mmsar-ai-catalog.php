<?php
/**
 * /.well-known/ai-catalog.json — Agentic Resource Discovery.
 *
 * The fourth discovery document this plugin publishes, and the question worth answering up front is
 * why a fourth is not redundant. Each one answers a different question in a different vocabulary:
 * api-catalog (RFC 9727) is a linkset of URLs, llms.txt is prose for a model, the Agent Skills index
 * describes skills. ARD asks specifically "what agentic resources does this domain operate" — MCP
 * servers, agents, skills, APIs — as typed, identified entries. An agent platform building a
 * directory reads this one because it is the only one whose entries carry a stable identifier and a
 * declared resource type.
 *
 * Spec: https://agenticresourcediscovery.org/
 *
 * Every entry is generated from a feature that is actually switched on, so the catalog never
 * advertises a resource this site does not serve.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Agentic Resource Discovery catalog.
 */
class MMSAR_AI_Catalog {

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
	 * @return void
	 */
	public static function add_rewrite_rules() {
		// Two addresses for one document: the specification names /.well-known/ard.json, while
		// directories and readiness scanners look for /.well-known/ai-catalog.json. Serving both
		// costs nothing and means neither reader has to guess.
		add_rewrite_rule( '^\.well-known/ai-catalog\.json$', 'index.php?mmsar_ai_catalog=1', 'top' );
		add_rewrite_rule( '^\.well-known/ard\.json$', 'index.php?mmsar_ai_catalog=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_ai_catalog';
		return $vars;
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( ! get_query_var( 'mmsar_ai_catalog' ) ) {
			return;
		}

		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$entries   = array();

		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$entries[] = self::entry(
				'server',
				'mcp',
				$site_name . ' content (MCP)',
				'application/mcp-server-card+json',
				home_url( '/.well-known/mcp/server-card.json' ),
				'Read-only MCP server over Streamable HTTP: search this site, list its content, and read any page as Markdown. No authentication.',
				array_column( MMSAR_MCP::tools(), 'name' ),
				array(
					'what has ' . $site_name . ' published about a given topic',
					'read the full text of a page on ' . $site_name,
				)
			);
		}

		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$entries[] = self::entry(
				'api',
				'http',
				$site_name . ' HTTP API',
				'application/vnd.oai.openapi+json',
				MMSAR_OpenAPI::url(),
				'OpenAPI 3.1 description of every public endpoint on this site, including its error shape. No authentication.',
				array( 'searchContent', 'listContent', 'readPageAsMarkdown' ),
				array( 'search ' . $site_name . ' for a keyword', 'list the most recent posts on ' . $site_name )
			);
		}

		if ( mmsar_feature_enabled( 'agent_skills' ) ) {
			$entries[] = self::entry(
				'skill',
				'markdown',
				$site_name . ' agent skills',
				'application/json',
				home_url( '/.well-known/agent-skills/index.json' ),
				'Agent Skills index describing how to fetch this site\'s content as Markdown.',
				array( 'fetchContentAsMarkdown' ),
				array( 'how do I read ' . $site_name . ' as markdown' )
			);
		}

		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$entries[] = self::entry(
				'document',
				'index',
				$site_name . ' content index',
				'text/plain',
				home_url( '/llms.txt' ),
				'Plain-text index of this site written for language models, including when the site is worth reading.',
				array( 'listContent' ),
				array( 'what is on ' . $site_name )
			);
		}

		if ( mmsar_feature_enabled( 'okf_bundle' ) ) {
			$entries[] = self::entry(
				'document',
				'okf',
				$site_name . ' OKF bundle',
				'text/markdown',
				home_url( '/okf/index.md' ),
				'Open Knowledge Format (v0.2) bundle: this site\'s content as a browsable tree of typed Markdown concept files, one per post/page, for ingesting the whole corpus in one pass.',
				array( 'listContent', 'fetchContentAsMarkdown' ),
				array( 'ingest the whole ' . $site_name . ' knowledge base in one fetch' )
			);
		}

		// Endpoints the site owner or another plugin registered that do something, as opposed to
		// simply being readable — ARD is a catalog of capabilities rather than of pages.
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( empty( $endpoint['methods'] ) || array( 'GET' ) === $endpoint['methods'] ) {
				continue;
			}
			$entries[] = self::entry(
				'api',
				$endpoint['id'],
				$endpoint['title'],
				$endpoint['type'] ? $endpoint['type'] : 'application/json',
				$endpoint['href'],
				$endpoint['description'],
				$endpoint['methods'],
				array()
			);
		}

		$catalog = array(
			// Ora's validator requires specVersion; the specification itself says non-ARD top-level
			// members are ignored, so declaring it satisfies one without violating the other.
			'specVersion' => '1.0',
			'publisher'   => array(
				'name' => $site_name,
				'url'  => home_url( '/' ),
			),
			'updatedAt'   => gmdate( 'c' ),
			'entries'     => $entries,
		);

		$contact = MMSAR_Endpoints::normalize_contact( get_option( 'mmsar_security_txt_contact', '' ) );
		if ( '' !== $contact ) {
			$catalog['publisher']['contact'] = $contact;
		}

		/**
		 * Filters the ARD catalog before it is served.
		 *
		 * @param array $catalog The catalog document, as a PHP array.
		 */
		$filtered = apply_filters( 'mmsar_ai_catalog', $catalog );
		if ( is_array( $filtered ) ) {
			$catalog = $filtered;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'ai-catalog.json' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );
		echo wp_json_encode( $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Build one ARD entry.
	 *
	 * `type` is an IANA media type describing the artifact at `url`, not a category word — that is
	 * the field's actual definition in the spec, and an earlier version of this file got it wrong by
	 * putting a resource kind there. Exactly one of `url` or `data` is permitted; everything here
	 * has a URL.
	 *
	 * @param string   $urn_namespace URN namespace segment, e.g. 'server', 'api', 'skill'.
	 * @param string   $name       URN name segment.
	 * @param string   $label      Human-readable name.
	 * @param string   $media_type IANA media type of the artifact at $url.
	 * @param string   $url        Absolute URL of the artifact.
	 * @param string   $desc       One sentence on what it is.
	 * @param string[] $caps       Capability tokens.
	 * @param string[] $queries    Representative natural-language queries.
	 * @return array The entry.
	 */
	private static function entry( $urn_namespace, $name, $label, $media_type, $url, $desc, $caps, $queries ) {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$entry = array(
			'identifier'    => 'urn:air:' . $host . ':' . $urn_namespace . ':' . sanitize_key( $name ),
			'displayName'   => $label,
			'type'          => $media_type,
			'url'           => $url,
			// The domain binding every entry carries. `same-origin` is the honest description of what
			// is actually proven here: this catalog is served over HTTPS from the domain it names, so
			// a reader who fetched it has already verified the binding. Claiming a signature or a
			// third-party attestation would be asserting a check nobody performed.
			'trustManifest' => array(
				'identity' => array(
					'principal'          => $host,
					'verificationMethod' => 'same-origin',
					'evidence'           => home_url( '/.well-known/ai-catalog.json' ),
				),
			),
		);

		if ( '' !== trim( (string) $desc ) ) {
			$entry['description'] = $desc;
		}
		if ( $caps ) {
			$entry['capabilities'] = array_values( $caps );
		}
		if ( $queries ) {
			$entry['representativeQueries'] = array_values( $queries );
		}

		if ( mmsar_feature_enabled( 'security_txt' ) ) {
			$entry['trustManifest']['trustSchema'] = array(
				'governanceUri'       => home_url( '/.well-known/security.txt' ),
				'verificationMethods' => array( 'same-origin' ),
			);
		}

		return $entry;
	}
}
