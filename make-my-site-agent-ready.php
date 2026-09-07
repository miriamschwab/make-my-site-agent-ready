<?php
/**
 * Plugin Name:       Make My Site Agent-Ready
 * Plugin URI:        https://miriamschwab.me/plugins/make-my-site-agent-ready
 * Description:       Makes your WordPress site ready for AI agents: .md URLs, llms.txt, llms-full.txt, an OpenAPI spec, a read-only MCP server, agent-recoverable 404s, security.txt, api-catalog, Agent Skills discovery, Link response headers, Content Signals, optional JSON-LD structured data (merges into Yoast's own schema when active), and AI crawler rules in robots.txt.
 * Version:           1.31.1
 * Author:            Miriam Schwab
 * Author URI:        https://miriamschwab.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       make-my-site-agent-ready
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMSAR_VERSION', '1.31.1' );
define( 'MMSAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMSAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MMSAR_PLUGIN_FILE', __FILE__ );

require_once MMSAR_PLUGIN_DIR . 'vendor/autoload.php';
// Loaded before the components that publish documents — every one of them reads the registry, and
// it must exist even when the features that use it are switched off, so integrations can register
// against it at any point in the load order.
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-registry.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-log.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-log-verify.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-log-page.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-log-widget.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-converter.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-negotiation-check.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-server.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-llms-txt.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-endpoints.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-skills.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-openapi.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-mcp.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-not-found.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-auth-md.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-ai-catalog.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-agent-view.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-nlweb.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-robots-allow.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-structured-data.php';
require_once MMSAR_PLUGIN_DIR . 'includes/class-mmsar-admin.php';
require_once MMSAR_PLUGIN_DIR . 'includes/abilities.php';

// No load_plugin_textdomain() call, and no Domain Path header. Both were pointing at a
// `languages/` directory that is empty and is not in the distributed zip, so they described
// something that does not exist. WordPress has loaded plugin translations just in time since 4.6
// — well below this plugin's 6.2 minimum — so if translations are ever shipped or installed into
// wp-content/languages/plugins/, they load without either of them.

/**
 * The features that can be switched off individually, and whether each is on by default.
 * Everything that shipped as always-on before 1.7.0 defaults to true — an install that upgrades
 * must behave exactly as it did before the user touches anything. Features added since default to
 * false where switching them on changes an existing response rather than adding a new one.
 */
function mmsar_get_feature_keys() {
	return array(
		'markdown'             => true,
		// Off by default, and the only feature whose failure mode lands on human readers rather
		// than on agents: a cache that ignores `Vary: Accept` can serve the markdown copy to a
		// visitor. Requires 'markdown' to be on, and has a self-check that switches it back off if
		// that condition is actually observed. See MMSAR_Negotiation_Check.
		'markdown_negotiation' => false,
		'llms_txt'             => true,
		'llms_full_txt'        => true,
		'robots_txt'           => true,
		'security_txt'         => true,
		'api_catalog'          => true,
		'agent_skills'         => true,
		// Both add a new URL without altering any existing response, so they are on by default.
		'openapi'              => true,
		'agent_404'            => true,
		// The one feature here that opens a new *interactive* surface rather than publishing a
		// document: an unauthenticated endpoint that runs database queries on request. Everything it
		// can reach is already public, and it is rate-limited, but adding an endpoint like that to
		// someone's site without being asked is not this plugin's call to make. Off by default.
		'mcp_server'           => false,
		// All four add a new URL and change no existing response.
		'auth_md'              => true,
		'ai_catalog'           => true,
		'agent_view'           => true,
		// Off by default: it is the only one of these that answers a question rather than serving a
		// document, and it adds a public endpoint that runs a query per call.
		'nlweb'                => false,
		// Off, and the only feature here that ships without having been verified end to end: no MCP
		// Apps host was available to render the template against. Everything else in this plugin was
		// tested against the thing that consumes it before it shipped.
		'mcp_ui'               => false,
		// Also off by default, and for a different reason: it writes rows into the Activity Log
		// plugin's table, which is the owner's data store rather than ours. Nothing should start
		// filling someone's log uninvited.
		'agent_log'            => false,
		// Off by default because it is the only feature that adds something a visitor can see.
		// Everything else this plugin publishes is invisible on the page.
		'llms_txt_footer_link' => false,
	);
}

/**
 * Whether a feature is switched on.
 *
 * A missing key means "never saved" — either an install predating 1.7.0 or a feature added in a
 * later version — and must fall back to the default rather than to off. Reading a missing key as
 * off would silently disable working endpoints on every existing site the moment they update.
 *
 * @param string $key Feature key.
 * @return bool Whether the feature is enabled.
 */
function mmsar_feature_enabled( $key ) {
	$defaults = mmsar_get_feature_keys();
	if ( ! isset( $defaults[ $key ] ) ) {
		return false;
	}
	$features = get_option( 'mmsar_features', array() );
	if ( ! is_array( $features ) || ! array_key_exists( $key, $features ) ) {
		return $defaults[ $key ];
	}
	return '1' === $features[ $key ];
}

/**
 * Registers an endpoint for inclusion in this site's agent-facing documents — the api-catalog,
 * llms.txt, and the Agent Skills index.
 *
 * Convenience wrapper around MMSAR_Registry::register() for integrations that would rather make a
 * direct call than add a filter. Call on `init` or earlier, and guard it with function_exists() so
 * the integration keeps working when this plugin is not installed:
 *
 *     add_action( 'init', function () {
 *         if ( ! function_exists( 'mmsar_register_endpoint' ) ) {
 *             return;
 *         }
 *         mmsar_register_endpoint( array(
 *             'id'          => 'contact-form',
 *             'title'       => 'Contact form',
 *             'href'        => rest_url( 'my-plugin/v1/contact' ),
 *             'description' => 'Send the site owner a message. Requires name, email and message.',
 *             'type'        => 'application/json',
 *             'methods'     => array( 'POST' ),
 *             'auth'        => 'none',
 *         ) );
 *     } );
 *
 * @param array $endpoint Endpoint descriptor. See the mmsar_registered_endpoints filter docblock
 *                        in class-mmsar-registry.php for every accepted key.
 * @return bool True if the descriptor is valid and was stored, false if it was rejected.
 */
function mmsar_register_endpoint( $endpoint ) {
	return MMSAR_Registry::register( $endpoint );
}

/**
 * Drops every cached document this plugin generates.
 *
 * They are all derived from the same three things — the feature toggles, the endpoint registry, and
 * the site's own content — so any of those changing invalidates the whole set, not one file. One
 * function so a new cached document is added in a single place rather than to a list of call sites
 * that then drift.
 *
 * @return void
 */
function mmsar_flush_generated_documents() {
	delete_transient( 'llmmd_llms_txt' );
	delete_transient( 'mmsar_llms_full_txt' );
	// The scoped indexes are per post type and there is no wildcard delete, so they are cleared by
	// walking the sections that exist. A section that has since been removed leaves an orphan
	// transient, which expires on its own within a day and is never served in the meantime — the
	// scoped route only answers for a post type still in the current section list.
	if ( class_exists( 'MMSAR_LLMs_Txt' ) ) {
		foreach ( MMSAR_LLMs_Txt::sections() as $section ) {
			delete_transient( 'llmmd_llms_txt_' . $section['post_type'] );
		}
	}
	if ( class_exists( 'MMSAR_OpenAPI' ) ) {
		MMSAR_OpenAPI::flush();
	}
}

/**
 * Sends the cache policy for the documents this plugin serves.
 *
 * These files describe live configuration — toggling a feature or adding an endpoint changes them
 * — so they must not inherit a host's or CDN's default cache lifetime. Left unstated, that default
 * can be very long: one real deploy had `s-maxage=604800` applied to /.well-known/api-catalog,
 * pinning a week-old copy at the edge while the origin served the correct one.
 *
 * Five minutes keeps a CDN useful for crawler traffic while letting a settings change appear
 * promptly. `s-maxage` is set alongside `max-age` because it is the shared-cache value a CDN reads,
 * and it is the one that was overridden.
 *
 * @return void
 */
function mmsar_send_cache_headers() {
	/**
	 * Filters how long, in seconds, the plugin's published documents may be cached.
	 *
	 * Return 0 to opt out of caching entirely, at the cost of every agent and crawler request
	 * reaching the origin.
	 *
	 * @param int $max_age Cache lifetime in seconds. Default 300.
	 */
	$max_age = (int) apply_filters( 'mmsar_document_max_age', 300 );

	if ( $max_age > 0 ) {
		header( sprintf( 'Cache-Control: public, max-age=%1$d, s-maxage=%1$d', $max_age ) );
		return;
	}
	header( 'Cache-Control: no-cache, max-age=0' );
}

/**
 * Mmsar get enabled post types.
 *
 * @return mixed Result.
 */
function mmsar_get_enabled_post_types() {
	// Option key kept as llmmd_settings for data continuity with prior installs.
	$settings = get_option( 'llmmd_settings', array() );
	$defaults = array( 'post', 'page' );
	return isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
		? $settings['post_types']
		: $defaults;
}

/**
 * Mmsar get root selector.
 *
 * @return mixed Result.
 */
function mmsar_get_root_selector() {
	$settings = get_option( 'llmmd_settings', array() );
	return isset( $settings['root_selector'] ) ? $settings['root_selector'] : '';
}

add_action( 'plugins_loaded', 'mmsar_check_version' );
/**
 * Mmsar check version.
 *
 * @return void
 */
function mmsar_check_version() {
	$stored = get_option( 'llmmd_version' );
	if ( MMSAR_VERSION !== $stored ) {
		mmsar_flush_generated_documents();
		update_option( 'llmmd_version', MMSAR_VERSION );
		// Any version bump may have added new rewrite rules (e.g. new /.well-known/ endpoints).
		// Updating a plugin's files in place does not re-fire register_activation_hook, so this
		// is the only reliable way new endpoints start working without a manual permalink resave.
		add_action( 'init', 'flush_rewrite_rules', 20 );
		mmsar_reset_stale_negotiation_setting();
	}
}

/**
 * Discards any stored value for the content negotiation toggle left over from 1.13.0/1.13.1.
 *
 * That version shipped the same feature key, and a site that switched it on still has `'1'` sitting
 * in the options table — the 1.15.0 removal took the key out of the code but never out of the
 * database. Reinstating the key without this would silently switch the feature back on, on exactly
 * the installs that once had it, with no check ever having run. Deleting the key rather than
 * writing `'0'` returns it to its default, which is off, and leaves the toggle telling the truth.
 *
 * Runs on every version change, which is harmless: after the first pass there is nothing to delete,
 * and a value written deliberately since is re-created the moment the settings screen is saved.
 * Guarded on the option existing so it never runs an UPDATE it does not need to.
 *
 * @return void
 */
function mmsar_reset_stale_negotiation_setting() {
	if ( get_option( 'mmsar_negotiation_reset_done' ) ) {
		return;
	}
	// Claimed with add_option(), which is an INSERT against a unique index: the one guard that is
	// atomic when two requests arrive during the same upgrade. A read-then-write check is not.
	if ( ! add_option( 'mmsar_negotiation_reset_done', '1', '', false ) ) {
		return;
	}

	$features = get_option( 'mmsar_features', array() );
	if ( ! is_array( $features ) || ! array_key_exists( 'markdown_negotiation', $features ) ) {
		return;
	}
	unset( $features['markdown_negotiation'] );
	update_option( 'mmsar_features', $features );
}

/**
 * Toggling a feature changes which rewrite rules get registered, and rewrite rules live in a
 * cached option — so the new set does not take effect until the rules are flushed. The settings
 * save happens before rules are registered on that request, so flush on the next one instead.
 */
add_action( 'wp_loaded', 'mmsar_maybe_flush_rewrites', 99 );
/**
 * Mmsar maybe flush rewrites.
 *
 * @return void
 */
function mmsar_maybe_flush_rewrites() {
	if ( get_transient( 'mmsar_flush_needed' ) ) {
		delete_transient( 'mmsar_flush_needed' );
		flush_rewrite_rules();
	}
}

/**
 * Marks the rewrite rules as needing a flush on the next request.
 *
 * Watching the option itself, not just the settings form: MMSAR_Admin::sanitize_features() only
 * runs for saves that go through the Settings API, so a write from anywhere else — WP-CLI, another
 * plugin, an ability added later — would leave the old rules in place and keep serving an endpoint
 * whose feature is now off. Being called twice for one settings save is harmless.
 *
 * The window is a day rather than a minute because the flush can only happen on a *later* request,
 * and there is no guarantee one arrives promptly: the settings page redirects immediately, but a
 * site whose features were changed by WP-CLI might see no traffic for hours, and a pending flush
 * that quietly expires is the bug this is here to prevent.
 *
 * @return void
 */
function mmsar_flag_flush_needed() {
	set_transient( 'mmsar_flush_needed', 1, DAY_IN_SECONDS );
}
add_action( 'add_option_mmsar_features', 'mmsar_flag_flush_needed' );
add_action( 'update_option_mmsar_features', 'mmsar_flag_flush_needed' );

// Prevent WordPress canonical redirect from appending trailing slashes to plugin-owned endpoints.
add_filter( 'redirect_canonical', 'mmsar_prevent_canonical_redirect' );
/**
 * Mmsar prevent canonical redirect.
 *
 * @param mixed $redirect_url Redirect url.
 * @return mixed Result.
 */
function mmsar_prevent_canonical_redirect( $redirect_url ) {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( '' === $request_uri ) {
		return $redirect_url;
	}
	$path = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! $path ) {
		return $redirect_url;
	}
	$plugin_paths = array(
		'/llms.txt',
		'/llms-full.txt',
		'/auth.md',
		'/ask',
		'/schema-map.xml',
		'/openapi.json',
		'/.well-known/ai-catalog.json',
		'/.well-known/ard.json',
		'/.well-known/mcp/server-card.json',
		'/.well-known/openapi.json',
		'/.well-known/mcp.json',
		'/.well-known/security.txt',
		'/.well-known/api-catalog',
		'/.well-known/agent-skills/index.json',
		'/.well-known/agent-skills/' . MMSAR_Agent_Skills::SKILL_NAME . '/SKILL.md',
	);
	foreach ( $plugin_paths as $p ) {
		if ( rtrim( $path, '/' ) === $p ) {
			return false;
		}
	}

	// The scoped section indexes live at /<section>/llms.txt, which is a different path on every
	// site, so they cannot be listed above. Matched by suffix instead: WordPress would otherwise
	// see a path with no trailing slash and redirect /media/llms.txt to /media/llms.txt/, which is
	// a 301 in front of a document agents are told to fetch by exact URL.
	if ( preg_match( '#/llms\.txt$#', rtrim( $path, '/' ) ) ) {
		return false;
	}

	return $redirect_url;
}

// A disabled feature registers nothing at all — no rewrite rule, no filter, no header — so the
// site behaves exactly as if that part of the plugin did not exist.
if ( mmsar_feature_enabled( 'markdown' ) ) {
	MMSAR_Server::init();
	// The JSON-LD block exists to point agents at the .md alternate, so it has nothing to say
	// once markdown URLs are off.
	MMSAR_Structured_Data::init();
}
if ( mmsar_feature_enabled( 'llms_txt' ) ) {
	MMSAR_LLMs_Txt::init();
}
if ( mmsar_feature_enabled( 'agent_skills' ) ) {
	MMSAR_Agent_Skills::init();
}
// Endpoints covers llms-full.txt, security.txt, api-catalog and the robots.txt rewrite, and gates
// each one individually inside.
MMSAR_Endpoints::init();
if ( mmsar_feature_enabled( 'openapi' ) ) {
	MMSAR_OpenAPI::init();
}
if ( mmsar_feature_enabled( 'mcp_server' ) ) {
	MMSAR_MCP::init();
}
if ( mmsar_feature_enabled( 'agent_404' ) ) {
	MMSAR_Not_Found::init();
}
if ( mmsar_feature_enabled( 'auth_md' ) ) {
	MMSAR_Auth_Md::init();
}
if ( mmsar_feature_enabled( 'ai_catalog' ) ) {
	MMSAR_AI_Catalog::init();
}
if ( mmsar_feature_enabled( 'agent_view' ) ) {
	MMSAR_Agent_View::init();
}
if ( mmsar_feature_enabled( 'nlweb' ) ) {
	MMSAR_NLWeb::init();
}
MMSAR_Negotiation_Check::init();
MMSAR_Agent_Log::init();
MMSAR_Agent_Log_Page::init();
MMSAR_Agent_Log_Widget::init();
MMSAR_Admin::init();

add_action( 'save_post', 'mmsar_on_save_post', 20, 2 );
/**
 * Mmsar on save post.
 *
 * @param mixed $post_id Post id.
 * @param mixed $post Post.
 * @return void
 */
function mmsar_on_save_post( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		delete_post_meta( $post_id, '_llmmd_content' );
		return;
	}
	// A post that has just been password-protected must not keep its cached markdown around: the
	// per-page .md endpoint 403s on it, and llms-full.txt now skips it, so leaving _llmmd_content in
	// place would only be a stale copy of protected content. Drop it and rebuild the shared indexes.
	if ( ! empty( $post->post_password ) ) {
		delete_post_meta( $post_id, '_llmmd_content' );
		delete_transient( 'llmmd_llms_txt' );
		delete_transient( 'mmsar_llms_full_txt' );
		return;
	}
	if ( ! in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
		return;
	}
	$markdown = MMSAR_Converter::convert_post( $post_id );
	update_post_meta( $post_id, '_llmmd_content', $markdown );
	delete_transient( 'llmmd_llms_txt' );
	delete_transient( 'mmsar_llms_full_txt' );
}

add_action( 'transition_post_status', 'mmsar_on_status_change', 10, 3 );
/**
 * Mmsar on status change.
 *
 * @param mixed $new_status New status.
 * @param mixed $old_status Old status.
 * @param mixed $post Post.
 * @return void
 */
function mmsar_on_status_change( $new_status, $old_status, $post ) {
	if ( $new_status !== $old_status && in_array( $post->post_type, mmsar_get_enabled_post_types(), true ) ) {
		if ( 'publish' === $old_status || 'publish' === $new_status ) {
			delete_transient( 'llmmd_llms_txt' );
			delete_transient( 'mmsar_llms_full_txt' );
		}
	}
}

/**
 * The markdown URL for the current request, or null if the current page has none.
 * Shared by the <link> tag (mmsar_alternate_link) and the Link response header
 * (mmsar_send_link_headers) so both always agree — one source of truth for the URL logic.
 */
function mmsar_get_markdown_url() {
	if ( is_front_page() && get_option( 'page_on_front' ) ) {
		return rtrim( home_url(), '/' ) . '/index.md';
	}
	if ( ! is_singular( mmsar_get_enabled_post_types() ) ) {
		return null;
	}
	return rtrim( get_permalink(), '/' ) . '.md';
}

add_action( 'wp_head', 'mmsar_alternate_link' );
/**
 * Mmsar alternate link.
 *
 * @return void
 */
function mmsar_alternate_link() {
	if ( ! mmsar_feature_enabled( 'markdown' ) ) {
		return;
	}
	$md_url = mmsar_get_markdown_url();
	if ( ! $md_url ) {
		return;
	}
	echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '">' . "\n";
}

/**
 * HTTP Link headers for agent discovery (RFC 8288), so agents that read headers without
 * parsing the HTML body can still find these resources. Hooked to template_redirect, not
 * send_headers — send_headers fires before WP_Query resolves the main query, so conditional
 * tags like is_front_page()/is_singular() are not yet reliable there. template_redirect fires
 * after the query resolves and before any template output, so headers can still be sent.
 */
add_action( 'template_redirect', 'mmsar_send_link_headers' );
/**
 * Mmsar send link headers.
 *
 * @return void
 */
function mmsar_send_link_headers() {
	// Each header advertises an endpoint. Never advertise one that is switched off — a Link header
	// pointing at a 404 is worse for an agent than no header at all.
	if ( mmsar_feature_enabled( 'api_catalog' ) ) {
		header( 'Link: <' . esc_url_raw( home_url( '/.well-known/api-catalog' ) ) . '>; rel="api-catalog"', false );
	}
	if ( mmsar_feature_enabled( 'agent_skills' ) ) {
		header( 'Link: <' . esc_url_raw( home_url( '/.well-known/agent-skills/index.json' ) ) . '>; rel="service-desc"', false );
	}
	// The OpenAPI document is the other service description, and the one an HTTP client can act on
	// without a model in the loop, so it carries its media type where the Agent Skills index does not.
	if ( mmsar_feature_enabled( 'openapi' ) && MMSAR_OpenAPI::is_serving() ) {
		header( 'Link: <' . esc_url_raw( MMSAR_OpenAPI::url() ) . '>; rel="service-desc"; type="application/vnd.oai.openapi+json"', false );
	}
	if ( mmsar_feature_enabled( 'mcp_server' ) ) {
		header( 'Link: <' . esc_url_raw( home_url( '/.well-known/mcp.json' ) ) . '>; rel="service-desc"; type="application/json"', false );
	}
	// rel="authenticate" is not registered with IANA, so auth.md goes out under service-doc: it is
	// human-and-model-readable documentation of the service, which is exactly what that relation means.
	if ( mmsar_feature_enabled( 'auth_md' ) ) {
		header( 'Link: <' . esc_url_raw( MMSAR_Auth_Md::url() ) . '>; rel="service-doc"; type="text/markdown"', false );
	}
	// rel="describedby" and type="text/plain" both match how the api-catalog already lists llms.txt,
	// so an agent reading the header and an agent reading the catalog are told the same thing.
	// llms-full.txt is deliberately not advertised here: it is the same content at full length, and
	// it is already in the catalog, so a header on every page view would cost bytes to say it twice.
	// The target is resolved per request, not fixed at the root: v2 of the proposal scopes an
	// llms.txt by path and the most specific file covering the request wins, so a page under a
	// section that publishes its own index is described by that index. Advertising the root index
	// on every page left the scoped ones findable only by an agent that had already guessed the
	// path, which is the v1 failure v2 exists to fix.
	MMSAR_LLMs_Txt::send_link_header();

	if ( ! mmsar_feature_enabled( 'markdown' ) ) {
		return;
	}
	$md_url = mmsar_get_markdown_url();
	if ( $md_url ) {
		header( 'Link: <' . esc_url_raw( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
	}
}

/**
 * The site's sitemap index URL, or '' if there isn't one worth advertising.
 *
 * Every SEO plugin uses a different filename, and WordPress core uses another one again, so
 * hardcoding any single name means advertising a URL that 404s on most sites.
 */
function mmsar_get_sitemap_url() {
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return home_url( '/sitemap_index.xml' );
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return home_url( '/sitemap.xml' );
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return home_url( '/sitemaps.xml' );
	}
	// Core sitemaps. Can be disabled via the wp_sitemaps_enabled filter, so ask rather than assume.
	// The index URL comes from WP_Sitemaps_Index::get_index_url() rather than a hardcoded
	// /wp-sitemap.xml, because sites on plain permalinks serve it as a query string instead.
	if ( function_exists( 'wp_sitemaps_get_server' ) ) {
		$server = wp_sitemaps_get_server();
		if ( $server && $server->sitemaps_enabled() && is_callable( array( $server->index, 'get_index_url' ) ) ) {
			return $server->index->get_index_url();
		}
	}
	return '';
}

if ( mmsar_feature_enabled( 'robots_txt' ) ) {
	add_filter( 'robots_txt', 'mmsar_robots_txt', 99, 2 );
	// The owner's own extra rules are appended at the very end of the chain rather than with the
	// AI-crawler rules at priority 99 — see mmsar_robots_txt_extra() for why. Registered first among
	// the PHP_INT_MAX passes so the Sitemap pass can see an owner-supplied Sitemap directive, and so
	// MMSAR_Robots_Allow's carve-outs still apply to groups the owner defined.
	add_filter( 'robots_txt', 'mmsar_robots_txt_extra', PHP_INT_MAX );
	// The Sitemap directive is added separately, at the very end of the filter chain, because
	// whether to add one at all depends on what every other plugin has already written. Yoast
	// hooks robots_txt at 99999 and Rank Math similarly late, so any check made at a normal
	// priority runs too early to see their output and would emit a second Sitemap line.
	add_filter( 'robots_txt', 'mmsar_robots_txt_sitemap', PHP_INT_MAX );
	// Endpoints this site advertises to agents are useless if robots.txt also tells them to stay
	// off the path those endpoints live on — /wp-json/ is disallowed by default by more than one
	// SEO plugin. Runs at PHP_INT_MAX for the same reason the Sitemap directive does: the rules it
	// has to override are written later in the chain than any normal priority can see.
	add_filter( 'robots_txt', array( 'MMSAR_Robots_Allow', 'filter' ), PHP_INT_MAX, 2 );
	// Registered after MMSAR_Robots_Allow so it runs after it: that filter parses the finished
	// robots.txt line by line looking for user-agent groups, and appending a non-group directive
	// before it runs would put an unfamiliar line in front of the parser for no benefit.
	add_filter( 'robots_txt', 'mmsar_robots_txt_llms', PHP_INT_MAX, 2 );
}
/**
 * Mmsar robots txt.
 *
 * @param mixed $output Output.
 * @param mixed $is_public Is public.
 * @return mixed Result.
 */
function mmsar_robots_txt( $output, $is_public ) {
	// Read here only to decide whether to auto-add a Content-Signal directive below. The text
	// itself is appended at the end of the filter chain by mmsar_robots_txt_extra().
	$extra = trim( get_option( 'mmsar_robots_txt_extra', '' ) );

	// When the site is set to discourage search engines (blog_public = 0), WordPress emits a
	// blanket Disallow: / and the admin has explicitly asked crawlers to stay away. Appending our
	// own "Allow: /" for AI bots would silently override that intent, so add none of the AI-crawler
	// rules here. The owner's own extra rules are still honored either way — that text is theirs,
	// not ours — but mmsar_robots_txt_extra() is what puts them in.
	if ( ! $is_public ) {
		return $output;
	}

	$ai_crawlers = array(
		'GPTBot',
		'ClaudeBot',
		'Anthropic-AI',
		'GoogleOther',
		'PerplexityBot',
		'FacebookBot',
	);

	// Skip auto-adding Content-Signal if the site owner already added one manually in the
	// extra-rules textarea, so we never emit two conflicting directives.
	$has_manual_signal   = ( false !== stripos( $extra, 'Content-Signal:' ) );
	$content_signal_line = $has_manual_signal ? '' : mmsar_content_signal_line();

	$rules = "\n";
	foreach ( $ai_crawlers as $bot ) {
		$rules .= "User-agent: {$bot}\n";
		$rules .= "Allow: /\n";
		if ( $content_signal_line ) {
			$rules .= $content_signal_line . "\n";
		}
		$rules .= "\n";
	}

	return $output . $rules;
}

/**
 * Appends the site owner's own extra directives, at the very end of the filter chain.
 *
 * Deliberately not written alongside the AI-crawler rules at priority 99. Anything this plugin adds
 * before the SEO plugins run is theirs to rewrite or delete, and at least one does exactly that:
 * Yoast's remove_default_robots() runs a preg_replace() against a
 * "User-agent: * / Disallow: /wp-admin/ / Allow: /wp-admin/admin-ajax.php" block — with no $limit,
 * so it strips every match in the document, not just the copy WordPress core emitted. An owner who
 * pastes those three lines into the extra-rules box in core's line order loses them from the served
 * file, while this plugin's settings preview still shows them, because Yoast's robots.txt
 * integration is gated to front-end requests and never runs in wp-admin. Silent loss plus a preview
 * that confirms the wrong thing is the worst version of this bug, and running last is what removes
 * it: text the owner typed by hand becomes the one thing in the document nothing else can take
 * away.
 *
 * Honored whether or not the site is public. blog_public = 0 is a reason to withhold rules this
 * plugin invented, not a reason to drop the owner's own.
 *
 * @param string $output The robots.txt content assembled so far.
 * @return string The robots.txt content with the owner's extra directives appended.
 */
function mmsar_robots_txt_extra( $output ) {
	$extra = trim( get_option( 'mmsar_robots_txt_extra', '' ) );
	if ( '' === $extra ) {
		return $output;
	}

	return rtrim( $output, "\n" ) . "\n\n" . $extra . "\n";
}

/**
 * Appends a Sitemap directive, but only if the finished robots.txt does not already have one.
 * Runs last in the filter chain so "already has one" is judged against the real final output.
 *
 * @param string $output The robots.txt content assembled so far.
 * @return string The robots.txt content with a Sitemap directive appended when needed.
 */
function mmsar_robots_txt_sitemap( $output ) {
	if ( false !== stripos( $output, 'Sitemap:' ) ) {
		return $output;
	}
	$sitemap_url = mmsar_get_sitemap_url();
	if ( ! $sitemap_url ) {
		return $output;
	}
	return rtrim( $output, "\n" ) . "\n\nSitemap: " . $sitemap_url . "\n";
}

/**
 * Appends an Llms-txt directive pointing at this site's /llms.txt.
 *
 * There is no ratified robots.txt directive for llms.txt — the llms.txt proposal says to link the
 * file from your homepage, and says nothing about robots.txt. This is published anyway because
 * robots.txt is the first file many agents and agent-readiness checkers fetch, and because a
 * compliant parser ignores a top-level directive it does not recognize (RFC 9309), so the line
 * cannot break crawling for anyone. It mirrors Sitemap: deliberately — same shape, same position,
 * same "one absolute URL" rule.
 *
 * @param string $output    The robots.txt content assembled so far.
 * @param bool   $is_public Whether the site is set to be indexed (blog_public).
 * @return string The robots.txt content with an Llms-txt directive appended when appropriate.
 */
function mmsar_robots_txt_llms( $output, $is_public ) {
	// A site set to discourage search engines has asked crawlers to stay away. Pointing agents at a
	// curated index of its content contradicts that, the same way the AI-crawler Allow rules would.
	if ( ! $is_public ) {
		return $output;
	}
	// Never advertise a switched-off endpoint: with llms.txt disabled the URL 404s.
	if ( ! mmsar_feature_enabled( 'llms_txt' ) ) {
		return $output;
	}
	// Any existing mention wins. The case this really guards is a site owner who added the line by
	// hand in the extra-rules textarea before the plugin published it — updating should not silently
	// give them the directive twice.
	if ( false !== stripos( $output, 'llms.txt' ) ) {
		return $output;
	}
	return rtrim( $output, "\n" ) . "\n\nLlms-txt: " . home_url( '/llms.txt' ) . "\n";
}

/**
 * Builds the Content-Signal directive line from the mmsar_content_signals option.
 * Proposed spec: https://contentsignals.org/ — Content-Signal: search=yes, ai-input=yes, ai-train=no
 */
function mmsar_content_signal_line() {
	$settings = get_option(
		'mmsar_content_signals',
		array(
			'search'   => 'yes',
			'ai_input' => 'yes',
			'ai_train' => 'no',
		)
	);
	$search   = ( isset( $settings['search'] ) && 'no' === $settings['search'] ) ? 'no' : 'yes';
	$ai_input = ( isset( $settings['ai_input'] ) && 'no' === $settings['ai_input'] ) ? 'no' : 'yes';
	$ai_train = ( isset( $settings['ai_train'] ) && 'yes' === $settings['ai_train'] ) ? 'yes' : 'no';

	return "Content-Signal: search={$search}, ai-input={$ai_input}, ai-train={$ai_train}";
}

register_activation_hook( __FILE__, 'mmsar_activate' );
/**
 * Mmsar activate.
 *
 * @return void
 */
function mmsar_activate() {
	if ( mmsar_feature_enabled( 'markdown' ) ) {
		MMSAR_Server::add_rewrite_rules();
	}
	if ( mmsar_feature_enabled( 'llms_txt' ) ) {
		MMSAR_LLMs_Txt::add_rewrite_rules();
	}
	if ( mmsar_feature_enabled( 'agent_skills' ) ) {
		MMSAR_Agent_Skills::add_rewrite_rules();
	}
	MMSAR_Endpoints::add_rewrite_rules();
	flush_rewrite_rules();
	mmsar_bulk_generate();
}

register_deactivation_hook( __FILE__, 'mmsar_deactivate' );
/**
 * Mmsar deactivate.
 *
 * @return void
 */
function mmsar_deactivate() {
	flush_rewrite_rules();
}

/**
 * Mmsar bulk generate.
 *
 * @return void
 */
function mmsar_bulk_generate() {
	$post_types = mmsar_get_enabled_post_types();
	if ( empty( $post_types ) ) {
		return;
	}
	/**
	 * Filters the maximum number of posts processed during bulk markdown generation.
	 * Set to a positive integer on large sites to avoid timeouts. Default -1 processes all posts.
	 *
	 * @param int $limit Posts per page. -1 for all.
	 */
	$limit = (int) apply_filters( 'mmsar_bulk_generate_limit', -1 );
	$posts = get_posts(
		array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
		)
	);
	foreach ( $posts as $post_id ) {
		$markdown = MMSAR_Converter::convert_post( $post_id );
		update_post_meta( $post_id, '_llmmd_content', $markdown );
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mmsar_action_links' );
/**
 * Mmsar action links.
 *
 * @param mixed $links Links.
 * @return mixed Result.
 */
function mmsar_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings', 'make-my-site-agent-ready' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'admin_post_mmsar_regenerate', 'mmsar_handle_regenerate' );
/**
 * Handle the "Regenerate content" admin-post action: regenerate all markdown and clear caches.
 *
 * @return void
 */
function mmsar_handle_regenerate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
	}
	if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_regenerate' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
	}

	mmsar_bulk_generate();
	delete_transient( 'llmmd_llms_txt' );
	delete_transient( 'mmsar_llms_full_txt' );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'make-my-site-agent-ready',
				'mmsar_regenerated' => '1',
				'_wpnonce'          => wp_create_nonce( 'mmsar_regenerate' ),
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
