<?php
/**
 * /auth.md — how an agent obtains credentials for this site, in prose.
 *
 * The honest version of a document that is usually aspirational. Most sites running this plugin have
 * no authorization server, no API keys and no registration flow, and the truthful answer to "how do I
 * authenticate" is "you don't". That is worth publishing rather than omitting: an agent that cannot
 * find an auth document has to assume it needs credentials it has no way to obtain, and either gives
 * up or starts probing for login endpoints. One short document ends that.
 *
 * Where a site *does* have a credentialed endpoint, the registry already records how it works — the
 * `auth` field on each endpoint descriptor — so this document reports what the site actually said
 * rather than inventing a scheme. A site with a token flow gets a walkthrough of that flow; a site
 * with none gets a clear statement that reads are open.
 *
 * Follows the section shape of the WorkOS auth.md draft (https://workos.com/auth-md), because a
 * predictable shape is most of the value in a document agents parse. Sections that do not apply say
 * so explicitly rather than being dropped — "there is no registration step" is information, and a
 * missing heading is not.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR auth.md handler.
 */
class MMSAR_Auth_Md {

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
		add_rewrite_rule( '^auth\.md$', 'index.php?mmsar_auth_md=1', 'top' );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'mmsar_auth_md';
		return $vars;
	}

	/**
	 * The public URL of the document.
	 *
	 * @return string Absolute URL.
	 */
	public static function url() {
		return home_url( '/auth.md' );
	}

	/**
	 * Serve.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( ! get_query_var( 'mmsar_auth_md' ) ) {
			return;
		}

		mmsar_send_cache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		MMSAR_Agent_Log::record( 'auth.md' );
		header( 'Access-Control-Allow-Origin: *' );
		status_header( 200 );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: serving raw markdown as text/markdown, not HTML.
		echo self::body();
		exit;
	}

	/**
	 * Endpoints in the registry that need some form of credential.
	 *
	 * An endpoint whose `auth` is empty or literally 'none' is open, and saying so is the job of the
	 * "no authentication" branch rather than of a per-endpoint walkthrough.
	 *
	 * @return array[] Endpoint descriptors requiring authentication.
	 */
	private static function credentialed_endpoints() {
		$out = array();
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			$auth = isset( $endpoint['auth'] ) ? strtolower( trim( $endpoint['auth'] ) ) : '';
			if ( '' === $auth || 'none' === $auth ) {
				continue;
			}
			$out[] = $endpoint;
		}
		return $out;
	}

	/**
	 * The document body.
	 *
	 * @return string Markdown.
	 */
	public static function body() {
		$site_name = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$gated     = self::credentialed_endpoints();

		$lines = array();
		// The H1 names the file itself, as WorkOS's own AUTH.md does (`# auth.md`): scanners such as
		// isitagentready.com identify an Auth.md document by its heading containing "auth.md"
		// (GitHub issue #1). Until 1.72.0 this read "# Authentication for <site>", which was found
		// but reported as missing the expected heading.
		$lines[] = '# auth.md for ' . $site_name;
		$lines[] = '';
		$lines[] = '> How an agent gets access to this site. Short version: everything you can read is open, '
			. 'and there are no API keys to obtain.';
		$lines[] = '';

		// --- Discover -------------------------------------------------------
		$lines[] = '## Discover';
		$lines[] = '';
		$lines[] = 'Start from these, in this order:';
		$lines[] = '';
		if ( mmsar_feature_enabled( 'llms_txt' ) ) {
			$lines[] = '- [`/llms.txt`](' . home_url( '/llms.txt' ) . ') — what this site holds, and when it is worth reading.';
		}
		if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
			$lines[] = '- [`/openapi.json`](' . MMSAR_OpenAPI::url() . ') — every HTTP endpoint, its parameters, and its error shape. '
				. 'Its root `security: []` is the machine-readable statement of what this document says in prose: no authentication is required.';
		}
		if ( mmsar_feature_enabled( 'mcp_server' ) ) {
			$lines[] = '- [`/.well-known/mcp.json`](' . home_url( '/.well-known/mcp.json' ) . ') — the MCP server, its transport, and its tools. '
				. 'Its `authentication.type` is `none`.';
		}
		if ( mmsar_feature_enabled( 'api_catalog' ) ) {
			$lines[] = '- [`/.well-known/api-catalog`](' . home_url( '/.well-known/api-catalog' ) . ') — every machine-readable document here, as a linkset.';
		}
		$lines[] = '';
		$lines[] = 'There is no `/.well-known/oauth-authorization-server` and no `/.well-known/oauth-protected-resource` on this host. '
			. 'That is not an omission: this site runs no authorization server and exposes no protected resource, so publishing either '
			. 'would point you at something that does not exist.';
		$lines[] = '';

		// --- Pick a method --------------------------------------------------
		$lines[] = '## Pick a method';
		$lines[] = '';
		$lines[] = '**Reading — no credential.** Every read surface on this site is anonymous: the HTML pages, the '
			. '`.md` twins, `llms.txt`, `llms-full.txt`, the OpenAPI document'
			. ( mmsar_feature_enabled( 'mcp_server' ) ? ', and the MCP server' : '' )
			. '. Send no `Authorization` header. You will not receive a `401`, because there is nothing here to be '
			. 'unauthorized for.';
		$lines[] = '';

		if ( $gated ) {
			$lines[] = '**Writing — per-endpoint.** This site exposes ' . count( $gated )
				. ( 1 === count( $gated ) ? ' endpoint that takes an action' : ' endpoints that take actions' )
				. ', and each states its own requirement:';
			$lines[] = '';
			foreach ( $gated as $endpoint ) {
				$lines[] = '- **' . $endpoint['title'] . '** — `' . implode( ', ', $endpoint['methods'] ? $endpoint['methods'] : array( 'POST' ) ) . ' ' . $endpoint['href'] . '`';
				$lines[] = '  - Requirement: ' . $endpoint['auth'];
				if ( ! empty( $endpoint['description'] ) ) {
					$lines[] = '  - ' . $endpoint['description'];
				}
			}
		} else {
			$lines[] = '**Writing — not available.** This site exposes no endpoint that changes anything. '
				. 'There is no method to pick.';
		}
		$lines[] = '';

		// --- Register -------------------------------------------------------
		$lines[] = '## Register';
		$lines[] = '';
		$lines[] = 'There is no registration step and no `register_uri`. No account, client id, or client secret exists to be issued, '
			. 'and nothing on this site records who is calling it beyond an ordinary request log.';
		$lines[] = '';

		// --- Claim ----------------------------------------------------------
		$lines[] = '## Claim a credential';
		$lines[] = '';
		if ( $gated ) {
			$lines[] = 'No long-lived credential is issued. Where an endpoint above requires a token, that token is '
				. 'single-use and obtained from the endpoint itself immediately before the call — `GET` the endpoint, read the '
				. 'token out of the response, and send it back with your `POST`. There is no `claim_uri` separate from the '
				. 'endpoint you are calling.';
		} else {
			$lines[] = 'Nothing to claim. There is no `claim_uri`.';
		}
		$lines[] = '';

		// --- Use ------------------------------------------------------------
		$lines[] = '## Use the credential';
		$lines[] = '';
		$lines[] = 'For reads, send an ordinary request. Two headers are worth setting:';
		$lines[] = '';
		$lines[] = '- `Accept: text/markdown` on any page URL, if you would rather have Markdown than HTML.';
		$lines[] = '- `Accept: application/json` if you want errors as JSON rather than as an HTML error page.';
		$lines[] = '- A descriptive `User-Agent`. Nothing is gated on it, but it is what shows up in this site\'s '
			. 'request log, and an identifiable agent is a welcome one.';
		$lines[] = '';
		if ( $gated ) {
			$lines[] = 'For the endpoints listed above, follow the requirement stated against each. A call that omits the token '
				. 'is refused — see Errors.';
			$lines[] = '';
		}

		// --- Errors ---------------------------------------------------------
		$lines[] = '## Errors';
		$lines[] = '';
		$lines[] = 'Every error from this site is JSON when you ask for JSON, with the same shape throughout:';
		$lines[] = '';
		$lines[] = '```json';
		$lines[] = '{ "code": "rest_forbidden", "message": "...", "data": { "status": 403 } }';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = 'Match on `code`, not on `message`. `data.status` repeats the HTTP status.';
		$lines[] = '';
		$lines[] = '- `401` — not used. No endpoint here challenges for credentials, so you will not see a '
			. '`WWW-Authenticate: Bearer` header and there is no `resource_metadata` URL to follow.';
		if ( $gated ) {
			$lines[] = '- `403` — a required single-use token was missing, already spent, or expired. Fetch a fresh one and retry once.';
		}
		$lines[] = '- `404` — wrong URL. The body carries a `links` array pointing at this site\'s index, sitemap and catalog, '
			. 'so a wrong guess is recoverable in one more request.';
		$lines[] = '- `429` — you are being rate limited. Read `RateLimit-Reset` and `Retry-After` and wait.';
		$lines[] = '';

		// --- Revocation -----------------------------------------------------
		$lines[] = '## Revocation';
		$lines[] = '';
		$lines[] = 'There is no `revocation_uri`, because there is no persistent credential to revoke. '
			. ( $gated
				? 'Single-use tokens expire on their own and cannot be reused after a successful call.'
				: 'Nothing is issued.' );
		$lines[] = '';
		$lines[] = 'Access can still be withdrawn from this end — `robots.txt` states this site\'s crawler policy, '
			. 'and it is the document to read before assuming a bulk crawl is welcome.';
		$lines[] = '';

		/**
		 * Filters the /auth.md body.
		 *
		 * @param string $markdown The document.
		 */
		$markdown = apply_filters( 'mmsar_auth_md_body', implode( "\n", $lines ) );

		return (string) $markdown;
	}
}
