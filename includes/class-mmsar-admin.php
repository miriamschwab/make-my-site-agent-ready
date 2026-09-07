<?php
/**
 * Make My Site Agent-Ready — admin component.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR Admin handler.
 */
class MMSAR_Admin {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'static_robots_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'structured_data_conflict_notice' ) );
	}

	/**
	 * Structured data conflict notice.
	 *
	 * @return void
	 */
	public static function structured_data_conflict_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '1' !== get_option( 'mmsar_structured_data', '' ) ) {
			return;
		}
		// Yoast is handled: the markdown pointer merges into Yoast's own schema piece
		// instead of duplicating it, so no warning needed for Yoast specifically.
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Make My Site Agent-Ready: JSON-LD structured data is enabled, and an SEO plugin (RankMath) that already emits its own structured data is active. This plugin\'s block is intentionally minimal and separate (no shared @id), but consider whether you need both, or whether your SEO plugin\'s existing output already covers this.', 'make-my-site-agent-ready' );
		echo '</p></div>';
	}

	/**
	 * Static robots notice.
	 *
	 * @return void
	 */
	public static function static_robots_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Once the user has opted out of robots.txt handling, their physical file is being served
		// as they intended — warning about it would be nagging about a problem they just solved.
		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
			return;
		}
		$robots_file = ABSPATH . 'robots.txt';
		if ( ! file_exists( $robots_file ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: path to robots.txt file */
			esc_html__( 'Make My Site Agent-Ready: A physical robots.txt file was found at %s. WordPress rewrite rules override it on Apache, but some hosts or CDNs (e.g. Cloudflare) may serve the static file directly, bypassing the AI crawler rules added by this plugin. Consider deleting the static file so WordPress generates robots.txt dynamically.', 'make-my-site-agent-ready' ),
			'<code>' . esc_html( $robots_file ) . '</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Add menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ),
			__( 'Agent-Ready', 'make-my-site-agent-ready' ),
			'manage_options',
			'make-my-site-agent-ready',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Label and "what you lose" copy for each toggleable feature, in display order.
	 */
	public static function get_feature_labels() {
		return array(
			'markdown'             => array(
				__( 'Markdown URLs (.md)', 'make-my-site-agent-ready' ),
				__( 'Serves a plain-markdown version of each post and page at its URL plus .md, and points agents at it via a <link> tag and Link header. Turning this off also disables the JSON-LD structured data below, which exists only to advertise these URLs.', 'make-my-site-agent-ready' ),
			),
			'markdown_negotiation' => array(
				__( 'Markdown from the normal page URL', 'make-my-site-agent-ready' ),
				__( 'Answers a request for an ordinary page with its markdown version when the request asks for markdown in its Accept header — which is how AI clients actually ask, rather than only at the .md address. Requires Markdown URLs above. Watch for one symptom: a visitor opens a page in a browser and gets a markdown file, or a download prompt, instead of the page. That means a cache between your site and your readers is ignoring the Vary: Accept header and handing out the markdown copy — Cloudflare is known to do this. Switch this off the moment you see it, and leave it off if your site sits behind a CDN you cannot configure. Whether that happens depends on infrastructure this plugin cannot see, so run the check below rather than taking its word for it. Off by default.', 'make-my-site-agent-ready' ),
			),
			'llms_txt'             => array(
				__( 'llms.txt', 'make-my-site-agent-ready' ),
				__( 'An index of your site at /llms.txt, so an agent can see what content exists in one request.', 'make-my-site-agent-ready' ),
			),
			'llms_txt_footer_link' => array(
				__( 'Link to llms.txt in the footer', 'make-my-site-agent-ready' ),
				__( 'Adds a small visible link to your llms.txt in the site footer, which is what the llms.txt proposal asks for. Whether it helps depends on the client: crawlers that fetch and store your raw HTML will see it, while tools that extract only a page\'s main content discard headers and footers — Anthropic\'s WebFetch was measured doing exactly that, so a footer link does not reach it. Off by default, because it is the only thing this plugin adds that a visitor can see.', 'make-my-site-agent-ready' ),
			),
			'llms_full_txt'        => array(
				__( 'llms-full.txt', 'make-my-site-agent-ready' ),
				__( 'The full markdown text of your content in a single file at /llms-full.txt.', 'make-my-site-agent-ready' ),
			),
			'robots_txt'           => array(
				__( 'robots.txt AI crawler rules', 'make-my-site-agent-ready' ),
				__( 'Adds explicit Allow rules for AI crawlers, a Content-Signal directive, and a Sitemap line.', 'make-my-site-agent-ready' ),
			),
			'security_txt'         => array(
				__( 'security.txt', 'make-my-site-agent-ready' ),
				__( 'Publishes a security contact at /.well-known/security.txt (RFC 9116).', 'make-my-site-agent-ready' ),
			),
			'api_catalog'          => array(
				__( 'api-catalog', 'make-my-site-agent-ready' ),
				__( 'Lists your site\'s machine-readable endpoints at /.well-known/api-catalog (RFC 9727).', 'make-my-site-agent-ready' ),
			),
			'openapi'              => array(
				__( 'OpenAPI specification', 'make-my-site-agent-ready' ),
				__( 'Publishes a machine-readable description of this site\'s API at /openapi.json, generated from what the site actually serves: the endpoints above, the REST routes really registered on this install, and anything listed in Endpoints below. The other files here tell an agent what exists; this is the one that tells an HTTP client how to call it, and it is what agent-readiness scanners look for. Skipped automatically if a real openapi.json file already sits in your site root.', 'make-my-site-agent-ready' ),
			),
			'mcp_server'           => array(
				__( 'MCP server (read-only)', 'make-my-site-agent-ready' ),
				__( 'Opens a Model Context Protocol endpoint that AI clients — Claude, ChatGPT, agent frameworks — can connect to directly, with four tools: search the site, list content, read a page as Markdown, and get an overview. Strictly read-only and limited to the published content in the post types above, so it exposes nothing that llms-full.txt does not already publish, and it is rate-limited to 60 calls a minute per IP. Also publishes a discovery manifest at /.well-known/mcp.json. Off by default: unlike everything else here it answers requests by running queries rather than serving a file, and adding a public endpoint like that is your decision to make.', 'make-my-site-agent-ready' ),
			),
			'auth_md'              => array(
				__( 'auth.md', 'make-my-site-agent-ready' ),
				__( 'Publishes /auth.md, a plain-language walkthrough of how an agent gets access to your site. For most sites the honest answer is "you don\'t need credentials", and that is worth saying out loud — an agent that cannot find an auth document has to assume it needs a key it has no way to get, and either gives up or starts probing for login endpoints. Endpoints you listed under Endpoints below with an authentication requirement get their own section, generated from what you wrote there.', 'make-my-site-agent-ready' ),
			),
			'ai_catalog'           => array(
				__( 'Agentic Resource Discovery catalog', 'make-my-site-agent-ready' ),
				__( 'Publishes /.well-known/ai-catalog.json listing your agentic resources — MCP server, API, skills, content index — as typed entries with stable identifiers. Overlaps with api-catalog above by design: that one is a list of links for a client following relations, this one is a typed inventory for a directory building a listing.', 'make-my-site-agent-ready' ),
			),
			'agent_view'           => array(
				__( 'Agent view (?mode=agent)', 'make-my-site-agent-ready' ),
				__( 'Adds ?mode=agent to every page, returning that page as Markdown, and the homepage as a summary of every machine-readable surface your site has. A convention rather than a standard, but it fills a real gap: a client handed a bare URL has no way to ask for the machine-readable version unless it already knows your site\'s conventions, and a query parameter is the one lever it always has.', 'make-my-site-agent-ready' ),
			),
			'nlweb'                => array(
				__( 'NLWeb /ask endpoint', 'make-my-site-agent-ready' ),
				__( 'Answers questions about your site at /ask, in Microsoft\'s NLWeb shape, with optional SSE streaming — plus a Schema Map at /schema-map.xml and a Schemamap directive in robots.txt. Retrieval only: it returns ranked pages from your site, not a generated answer, and says so in every response. Off by default, because unlike the documents above it runs a query on each call.', 'make-my-site-agent-ready' ),
			),
			'mcp_ui'               => array(
				__( 'MCP Apps UI (experimental)', 'make-my-site-agent-ready' ),
				__( 'Lets an MCP client render search and listing results as a card list inside the conversation, instead of as plain text, by exposing a ui:// resource and pointing the two list tools at it. Requires the MCP server above. Marked experimental for one honest reason: no MCP Apps host was available to render this template against, so unlike everything else here it has not been verified against the thing that consumes it. A client that ignores the metadata still gets the normal text result, so switching it on cannot break an existing one. Off by default.', 'make-my-site-agent-ready' ),
			),
			'agent_404'            => array(
				__( 'Agent-recoverable 404s', 'make-my-site-agent-ready' ),
				__( 'A normal 404 tells an agent its URL was wrong and nothing else. This adds Link headers and <link> tags pointing at your sitemap, llms.txt and endpoint catalog, so it can find its way to the right page instead of giving up — and returns a short Markdown list of those destinations, in place of the themed error page, to clients that explicitly asked for Markdown. Your 404 page looks exactly the same to visitors.', 'make-my-site-agent-ready' ),
			),
			'agent_log'            => array(
				__( 'Agent request log', 'make-my-site-agent-ready' ),
				__( 'Records which agents fetch the files above, and what they asked for. Entries appear on the Agent Log screen under Settings, which has its own retention setting, and are also copied to the Activity Log plugin when it is available. On its own it records only requests for these agent-facing files; the Agent request log section below can extend it to ordinary page views as well. Switching this off stops new entries being recorded and nothing else — everything already logged is kept, and stays readable and exportable until you clear it yourself on that screen. Off by default.', 'make-my-site-agent-ready' ),
			),
			'agent_skills'         => array(
				__( 'Agent Skills discovery', 'make-my-site-agent-ready' ),
				__( 'Publishes an Agent Skills index at /.well-known/agent-skills/ describing how agents can work with this site.', 'make-my-site-agent-ready' ),
			),
		);
	}

	/**
	 * Sanitize features.
	 *
	 * @param mixed $input Input.
	 * @return mixed Result.
	 */
	public static function sanitize_features( $input ) {
		$out = array();
		// Write every key explicitly. An unchecked checkbox posts nothing, so a key missing from
		// $input means "off" here — unlike mmsar_feature_enabled(), where missing means "never saved".
		foreach ( array_keys( mmsar_get_feature_keys() ) as $key ) {
			$out[ $key ] = ( isset( $input[ $key ] ) && '1' === $input[ $key ] ) ? '1' : '0';
		}
		// Switching content negotiation on schedules a self-check for the next request, because
		// this is the one feature whose safety depends on infrastructure in front of WordPress.
		// It cannot run here: the new value is not stored until this callback returns, so the
		// feature would still be off when the probes went out.
		if ( '1' === $out['markdown_negotiation'] && ! mmsar_feature_enabled( 'markdown_negotiation' ) ) {
			MMSAR_Negotiation_Check::schedule();
		}
		// Enabling or disabling a feature adds or removes rewrite rules, which only take effect
		// after a flush. Flagged through the shared helper so this path and the option hooks that
		// catch non-Settings-API writes can never drift to different flags or lifetimes.
		mmsar_flush_generated_documents();
		mmsar_flag_flush_needed();
		return $out;
	}

	/**
	 * Render features section.
	 *
	 * @return void
	 */
	public static function render_features_section() {
		echo '<p>';
		esc_html_e( 'Everything this plugin publishes is listed here. Almost all of it is on by default — switch off anything you already handle elsewhere, and the plugin will stop touching it entirely. Anything marked off by default changes an existing response rather than adding a new one, so it waits for you to turn it on.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * The public URL each feature serves, for the "View" link next to its toggle. Only features that
	 * have a single fixed endpoint are here — markdown is per-page, so it has no one URL to link to.
	 */
	public static function get_feature_urls() {
		return array(
			'llms_txt'      => '/llms.txt',
			'llms_full_txt' => '/llms-full.txt',
			'robots_txt'    => '/robots.txt',
			'security_txt'  => '/.well-known/security.txt',
			'api_catalog'   => '/.well-known/api-catalog',
			'agent_skills'  => '/.well-known/agent-skills/index.json',
			'openapi'       => '/openapi.json',
			// The manifest rather than the endpoint itself: the endpoint only answers POST, so a
			// "View" link to it would open a browser tab on an error.
			'mcp_server'    => '/.well-known/mcp.json',
			'auth_md'       => '/auth.md',
			'ai_catalog'    => '/.well-known/ai-catalog.json',
			'nlweb'         => '/schema-map.xml',
		);
	}

	/**
	 * Features whose own admin screen lives elsewhere in wp-admin, and the label for the link.
	 *
	 * Separate from get_feature_urls(), which lists public URLs on the front end. This one is
	 * always shown, on or off — someone switching the log on wants to know where to read it, and
	 * someone switching it off may still want the entries already collected.
	 */
	public static function get_feature_admin_links() {
		return array(
			'agent_log' => array(
				admin_url( 'options-general.php?page=' . MMSAR_Agent_Log_Page::SLUG ),
				__( 'View the log', 'make-my-site-agent-ready' ),
			),
		);
	}

	/**
	 * Features that also have a settings section further down the page, and the anchor id that jumps
	 * to it (set as a before_section wrapper in register_settings). Lets a toggle say "there's more to
	 * configure below" without the user having to scroll and hunt for the matching section.
	 */
	public static function get_feature_section_anchors() {
		return array(
			'markdown'             => 'mmsar-section-markdown',
			'markdown_negotiation' => 'mmsar-section-negotiation',
			'robots_txt'           => 'mmsar-section-robots',
			'security_txt'         => 'mmsar-section-security',
		);
	}

	/**
	 * Render features field.
	 *
	 * @return void
	 */
	public static function render_features_field() {
		$urls        = self::get_feature_urls();
		$anchors     = self::get_feature_section_anchors();
		$admin_links = self::get_feature_admin_links();

		foreach ( self::get_feature_labels() as $key => $labels ) {
			list( $label, $description ) = $labels;
			$checked                     = mmsar_feature_enabled( $key ) ? 'checked' : '';
			echo '<div style="margin-bottom:14px;">';
			echo '<label style="font-weight:600;">';
			echo '<input type="checkbox" name="mmsar_features[' . esc_attr( $key ) . ']" value="1" ' . esc_attr( $checked ) . '> ';
			echo esc_html( $label );
			echo '</label>';
			echo '<p class="description" style="margin-left:24px;">' . esc_html( $description ) . '</p>';

			// Action links under the description: a live "View" link to the served file (only when the
			// feature is on, so we never link to a 404), and a jump link to its settings section below.
			$links = array();
			if ( isset( $urls[ $key ] ) && mmsar_feature_enabled( $key ) ) {
				$view_url = home_url( $urls[ $key ] );
				$links[]  = '<a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">'
					. esc_html( $urls[ $key ] ) . ' <span aria-hidden="true">&#8599;</span></a>';
			}
			if ( isset( $admin_links[ $key ] ) ) {
				list( $admin_url, $admin_label ) = $admin_links[ $key ];
				$links[]                         = '<a href="' . esc_url( $admin_url ) . '">'
					. esc_html( $admin_label ) . ' <span aria-hidden="true">&#8594;</span></a>';
			}
			if ( isset( $anchors[ $key ] ) ) {
				$links[] = '<a href="#' . esc_attr( $anchors[ $key ] ) . '">'
					. esc_html__( 'Configure below', 'make-my-site-agent-ready' )
					. ' <span aria-hidden="true">&#8595;</span></a>';
			}
			if ( $links ) {
				echo '<p style="margin-left:24px;margin-top:4px;">'
					. wp_kses_post( implode( ' &nbsp;·&nbsp; ', $links ) )
					. '</p>';
			}

			echo '</div>';
		}
	}

	/**
	 * Human labels for the three documents an endpoint can be listed in.
	 */
	public static function get_surface_labels() {
		return array(
			'api_catalog'  => __( 'api-catalog', 'make-my-site-agent-ready' ),
			'llms_txt'     => __( 'llms.txt', 'make-my-site-agent-ready' ),
			'agent_skills' => __( 'Agent Skills', 'make-my-site-agent-ready' ),
		);
	}

	/**
	 * Intro copy for the endpoints section.
	 *
	 * @return void
	 */
	public static function render_endpoints_section() {
		echo '<p>';
		esc_html_e( 'Made something on your site usable by agents — a contact form, a booking API, a product feed? Add it here and it gets listed in the documents agents actually read, so they can find it without being told it exists.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p class="description">';
		esc_html_e( 'Fill in the empty row at the bottom to add one. Save, and a fresh empty row appears.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * The editable endpoint rows, plus one blank row for adding another.
	 *
	 * No JavaScript repeater: one blank row per save is a little slower than cloning rows in the
	 * browser, but it works identically with JS disabled or broken, and there is no hidden state to
	 * get out of step with what is actually stored.
	 *
	 * @return void
	 */
	public static function render_endpoints_field() {
		$rows = MMSAR_Registry::get_stored();
		// The blank row is just one more row with an index past the end of the stored ones.
		$rows[] = array();

		foreach ( $rows as $index => $row ) {
			self::render_endpoint_row( $index, $row, count( $rows ) - 1 === $index );
		}
	}

	/**
	 * One endpoint row.
	 *
	 * @param int   $index    Row index, used in the field names.
	 * @param array $row      Stored row values.
	 * @param bool  $is_blank Whether this is the trailing "add another" row.
	 * @return void
	 */
	private static function render_endpoint_row( $index, $row, $is_blank ) {
		$name  = 'mmsar_endpoints[' . $index . ']';
		$get   = function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? $row[ $key ] : '';
		};
		$id    = $get( 'id' );
		$title = $get( 'title' );

		echo '<fieldset style="border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin-bottom:16px;max-width:760px;background:#fff;">';
		echo '<legend style="padding:0 6px;font-weight:600;">';
		echo $is_blank
			? esc_html__( 'Add an endpoint', 'make-my-site-agent-ready' )
			: esc_html( '' !== $title ? $title : __( 'Untitled endpoint', 'make-my-site-agent-ready' ) );
		echo '</legend>';

		if ( '' !== $id ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[id]" value="' . esc_attr( $id ) . '">';
		}

		// Name + URL: the only two required fields, so they lead.
		echo '<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;">' . esc_html__( 'Name', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '[title]" value="' . esc_attr( $title ) . '" placeholder="' . esc_attr__( 'Contact form', 'make-my-site-agent-ready' ) . '"></p>';

		echo '<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;">' . esc_html__( 'URL', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="large-text code" name="' . esc_attr( $name ) . '[href]" value="' . esc_attr( $get( 'href' ) ) . '" placeholder="' . esc_attr( home_url( '/wp-json/my-plugin/v1/thing' ) ) . '"></p>';

		echo '<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;">' . esc_html__( 'Description', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="large-text" name="' . esc_attr( $name ) . '[description]" value="' . esc_attr( $get( 'description' ) ) . '" placeholder="' . esc_attr__( 'What it does, and anything a caller must know before using it.', 'make-my-site-agent-ready' ) . '">';
		echo '<span class="description">' . esc_html__( 'One sentence. This is what an agent reads to decide whether to use it — mention any required first step.', 'make-my-site-agent-ready' ) . '</span></p>';

		// Where it gets listed.
		$surfaces = isset( $row['surfaces'] ) && is_array( $row['surfaces'] ) ? $row['surfaces'] : array_keys( self::get_surface_labels() );
		echo '<p style="margin:0 0 10px;"><span style="display:block;font-weight:600;">' . esc_html__( 'List it in', 'make-my-site-agent-ready' ) . '</span>';
		foreach ( self::get_surface_labels() as $key => $label ) {
			$checked  = in_array( $key, $surfaces, true ) ? ' checked' : '';
			$disabled = mmsar_feature_enabled( $key ) ? '' : ' disabled';
			echo '<label style="margin-right:16px;"><input type="checkbox" name="' . esc_attr( $name ) . '[surfaces][]" value="' . esc_attr( $key ) . '"' . esc_attr( $checked ) . esc_attr( $disabled ) . '> ' . esc_html( $label );
			if ( ! mmsar_feature_enabled( $key ) ) {
				echo ' <span class="description">' . esc_html__( '(switched off above)', 'make-my-site-agent-ready' ) . '</span>';
			}
			echo '</label>';
		}
		echo '</p>';

		// The optional technical details, folded away so the common case stays short.
		echo '<details style="margin:0 0 10px;"><summary style="cursor:pointer;">' . esc_html__( 'Technical details (optional)', 'make-my-site-agent-ready' ) . '</summary>';
		echo '<div style="padding-top:10px;">';

		echo '<p style="margin:0 0 8px;"><label style="display:block;">' . esc_html__( 'Methods', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '[methods]" value="' . esc_attr( is_array( $get( 'methods' ) ) ? implode( ', ', $get( 'methods' ) ) : '' ) . '" placeholder="GET, POST"></p>';

		echo '<p style="margin:0 0 8px;"><label style="display:block;">' . esc_html__( 'Content type', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="regular-text code" name="' . esc_attr( $name ) . '[type]" value="' . esc_attr( $get( 'type' ) ) . '" placeholder="application/json">';
		echo '<span class="description">' . esc_html__( 'Only if you know it. A wrong type is worse than none, so this is left out when empty.', 'make-my-site-agent-ready' ) . '</span></p>';

		echo '<p style="margin:0 0 8px;"><label style="display:block;">' . esc_html__( 'Authentication', 'make-my-site-agent-ready' ) . '</label>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '[auth]" value="' . esc_attr( $get( 'auth' ) ) . '" placeholder="' . esc_attr__( 'none', 'make-my-site-agent-ready' ) . '"></p>';

		$rel = '' !== $get( 'rel' ) ? $get( 'rel' ) : 'item';
		echo '<p style="margin:0;"><label style="display:block;">' . esc_html__( 'Link relation (api-catalog)', 'make-my-site-agent-ready' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '[rel]">';
		foreach ( MMSAR_Registry::RELATIONS as $relation ) {
			echo '<option value="' . esc_attr( $relation ) . '"' . selected( $rel, $relation, false ) . '>' . esc_html( $relation ) . '</option>';
		}
		echo '</select>';
		echo '<span class="description" style="display:block;">' . esc_html__( 'Leave as "item" unless you know you want otherwise. Use "service-desc" when fetching the URL returns a description of the API itself.', 'make-my-site-agent-ready' ) . '</span></p>';

		echo '</div></details>';

		if ( ! $is_blank ) {
			// Say plainly whether this row is live, and why not when it isn't. A row that silently
			// fails to publish is the worst outcome — the owner believes it is out there.
			self::render_endpoint_status( $row );
			echo '<p style="margin:10px 0 0;"><label style="color:#b32d2e;"><input type="checkbox" name="' . esc_attr( $name ) . '[remove]" value="1"> ' . esc_html__( 'Remove this endpoint when I save', 'make-my-site-agent-ready' ) . '</label></p>';
		}

		echo '</fieldset>';
	}

	/**
	 * The "where is this actually published" line under a stored row.
	 *
	 * @param array $row Stored row values.
	 * @return void
	 */
	private static function render_endpoint_status( $row ) {
		$labels    = self::get_surface_labels();
		$published = MMSAR_Registry::normalize( $row );

		if ( null === $published ) {
			echo '<p style="margin:10px 0 0;color:#b32d2e;">';
			echo esc_html__( 'Not being published. It needs a name, a URL starting with http:// or https://, and at least one document ticked above.', 'make-my-site-agent-ready' );
			echo '</p>';
			return;
		}

		// A ticked document still publishes nothing while its feature is switched off further up
		// the page, so report what is actually happening rather than what was asked for.
		$live = array();
		foreach ( $published['surfaces'] as $surface ) {
			if ( mmsar_feature_enabled( $surface ) && isset( $labels[ $surface ] ) ) {
				$live[] = $labels[ $surface ];
			}
		}

		echo '<p style="margin:10px 0 0;">';
		if ( $live ) {
			printf(
				/* translators: %s: comma-separated list of document names */
				esc_html__( 'Currently listed in: %s', 'make-my-site-agent-ready' ),
				'<strong>' . esc_html( implode( ', ', $live ) ) . '</strong>'
			);
		} else {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'Not being published — every document it is ticked for is switched off above.', 'make-my-site-agent-ready' ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * The published endpoints that did not come from this settings page — i.e. those a plugin or the
	 * theme registered in code. Identified by elimination against the stored ids, so an integration
	 * cannot claim to be owner-managed just by picking a matching id.
	 *
	 * @return array[] Normalized endpoints registered in code.
	 */
	public static function get_code_registered_endpoints() {
		$stored_ids = wp_list_pluck( MMSAR_Registry::get_stored(), 'id' );

		$from_code = array();
		foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
			if ( ! in_array( $endpoint['id'], $stored_ids, true ) ) {
				$from_code[] = $endpoint;
			}
		}
		return $from_code;
	}

	/**
	 * Lists endpoints added by other plugins or the theme in code, so the site owner can see what is
	 * being published on their behalf. Read-only: this page does not own those entries.
	 *
	 * @return void
	 */
	public static function render_code_endpoints_section() {
		$labels    = self::get_surface_labels();
		$from_code = self::get_code_registered_endpoints();
		if ( empty( $from_code ) ) {
			return;
		}

		echo '<p>';
		esc_html_e( 'A plugin or your theme registered these in code. They are shown here so you know what is being published — to change or remove one, edit the plugin or theme that added it.', 'make-my-site-agent-ready' );
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Endpoint', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Listed in', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $from_code as $endpoint ) {
			$listed = array();
			foreach ( $endpoint['surfaces'] as $surface ) {
				if ( mmsar_feature_enabled( $surface ) && isset( $labels[ $surface ] ) ) {
					$listed[] = $labels[ $surface ];
				}
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $endpoint['title'] ) . '</strong>';
			if ( '' !== $endpoint['description'] ) {
				echo '<br><span class="description">' . esc_html( $endpoint['description'] ) . '</span>';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( $endpoint['href'] ) . '</code></td>';
			echo '<td>' . ( $listed
				? esc_html( implode( ', ', $listed ) )
				: '<span class="description">' . esc_html__( 'Nowhere — every document it asked for is switched off', 'make-my-site-agent-ready' ) . '</span>' );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Intro copy for the agent log section, including whether it can actually write anywhere.
	 *
	 * @return void
	 */
	public static function render_agent_log_section() {
		$total = MMSAR_Agent_Log::count_entries();

		echo '<p>';
		printf(
			/* translators: 1: number of recorded entries, 2: link to the Agent Log screen */
			esc_html__( 'Requests agents make to the files above are recorded. %1$s so far — they are listed on the %2$s screen, which has its own retention setting.', 'make-my-site-agent-ready' ),
			'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>',
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . MMSAR_Agent_Log_Page::SLUG ) ) . '">' . esc_html__( 'Agent Log', 'make-my-site-agent-ready' ) . '</a>'
		);
		echo '</p>';

		echo '<p class="description">';
		esc_html_e( 'The same agent, file and IP is recorded at most once every five minutes, so a crawler looping on one URL cannot flood the log. Nothing is recorded on an ordinary page view unless the option below is ticked. Two kinds of request carry a detail alongside the file: a 404 records the path the agent asked for, and an MCP call records the method it invoked — including which tool, so the log shows whether the MCP server is being used or only discovered.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * The one sub-option: whether to also log HTML page views from known agents.
	 *
	 * @return void
	 */
	public static function render_agent_log_pages_field() {
		$mode    = MMSAR_Agent_Log::page_view_mode();
		$choices = array(
			'off'    => __( 'Don\'t record page views', 'make-my-site-agent-ready' ),
			'agents' => __( 'Only from recognized AI agents and crawlers', 'make-my-site-agent-ready' ),
			'all'    => __( 'Every page view, including people', 'make-my-site-agent-ready' ),
		);
		$notes   = array(
			'off'    => __( 'You see only the clients that fetched an agent-facing file.', 'make-my-site-agent-ready' ),
			'agents' => __( 'Adds the denominator for recognized crawlers: who came and ignored the agent-facing files.', 'make-my-site-agent-ready' ),
			'all'    => __( 'Closes the blind spot: without this, an unrecognized client\'s requests for agent-facing files are recorded while its ordinary page views are not, so anything unbranded looks as though it reads nothing else. The log still opens on agent traffic; browser page views are recorded as the denominator and filtered out of the default view.', 'make-my-site-agent-ready' ),
		);

		echo '<fieldset>';
		foreach ( $choices as $value => $label ) {
			// The note sits under the option, not inside the label. Inline, it ran straight on from
			// the option text with no separator and switched font mid-sentence.
			printf(
				'<label style="display:block;"><input type="radio" name="mmsar_agent_log_pages" value="%1$s" %2$s> %3$s</label>
				<p class="description" style="margin:.1em 0 .8em 1.75em;">%4$s</p>',
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $label ),
				esc_html( $notes[ $value ] )
			);
		}
		echo '</fieldset>';

		echo '<p class="description">';
		esc_html_e( 'Recording every page view means the log holds human traffic too. Those rows are stored against a network rather than a full address (203.0.113.4 becomes 203.0.113.0), and against the page they resolved to, never the raw URL a visitor typed. A recognized crawler keeps its full address, which is what identity verification runs against.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p class="description">';
		esc_html_e( 'One entry per visitor, per page, per five minutes. Keeping everything is a reasonable choice if the log is being used to answer a question about agent behaviour over time, and the retention limit on the Agent Log screen is there if the table ever outgrows its usefulness.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * Sanitizes the page-view mode, keeping the pre-1.25.0 checkbox value meaningful.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_page_view_mode( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( 'all' === $value ) {
			return 'all';
		}
		// '1' is what the old checkbox stored, and it still means "recognized agents only".
		return ( 'agents' === $value || '1' === $value ) ? '1' : '';
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		// Feature toggles.
		register_setting(
			'mmsar_settings_group',
			'mmsar_features',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_features' ),
			)
		);

		add_settings_section(
			'mmsar_features',
			__( 'Features', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_features_section' ),
			'make-my-site-agent-ready',
			// Anchor wrapper so sections further down can point back at the toggle that controls
			// them — the negotiation section has no checkbox of its own and needs to say where it is.
			array(
				'before_section' => '<div id="mmsar-section-features">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field(
			'mmsar_features_enabled',
			__( 'Enabled Features', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_features_field' ),
			'make-my-site-agent-ready',
			'mmsar_features'
		);

		// Your endpoints: the editable list, stored as an option.
		register_setting(
			'mmsar_settings_group',
			MMSAR_Registry::OPTION,
			array(
				'sanitize_callback' => array( 'MMSAR_Registry', 'sanitize_rows' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'mmsar_endpoints',
			__( 'Your Endpoints', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_endpoints_section' ),
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_endpoints_rows',
			__( 'Endpoints', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_endpoints_field' ),
			'make-my-site-agent-ready',
			'mmsar_endpoints'
		);

		// Endpoints registered in code by another plugin or the theme. Read-only, and registered at
		// all only when there are some — add_settings_section prints its heading regardless of what
		// the callback does, so an empty section would leave a bare title on most sites.
		if ( self::get_code_registered_endpoints() ) {
			add_settings_section(
				'mmsar_code_endpoints',
				__( 'Added by Plugins', 'make-my-site-agent-ready' ),
				array( __CLASS__, 'render_code_endpoints_section' ),
				'make-my-site-agent-ready'
			);
		}

		// Main settings (option key kept as llmmd_settings for data continuity).
		register_setting(
			'mmsar_settings_group',
			'llmmd_settings',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'mmsar_main',
			__( 'Markdown Endpoints', 'make-my-site-agent-ready' ),
			'__return_false',
			'make-my-site-agent-ready',
			// Anchor wrapper so the "Markdown URLs (.md)" toggle above can jump straight here.
			array(
				'before_section' => '<div id="mmsar-section-markdown">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field(
			'mmsar_post_types',
			__( 'Enabled Post Types', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_post_types_field' ),
			'make-my-site-agent-ready',
			'mmsar_main'
		);

		add_settings_field(
			'mmsar_root_selector',
			__( 'Content Root Selector', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_root_selector_field' ),
			'make-my-site-agent-ready',
			'mmsar_main'
		);

		// Content negotiation. No settings of its own — the section exists to hold the self-check,
		// which is the part that makes the feature safe to offer at all.
		add_settings_section(
			'mmsar_negotiation',
			__( 'Markdown Content Negotiation', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_negotiation_section' ),
			'make-my-site-agent-ready',
			array(
				'before_section' => '<div id="mmsar-section-negotiation">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field(
			'mmsar_negotiation_check',
			__( 'Check this site', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_negotiation_check_field' ),
			'make-my-site-agent-ready',
			'mmsar_negotiation'
		);

		// robots.txt settings.
		register_setting(
			'mmsar_settings_group',
			'mmsar_robots_txt_extra',
			array(
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);

		add_settings_section(
			'mmsar_robots_txt',
			__( 'robots.txt', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_robots_txt_section' ),
			'make-my-site-agent-ready',
			// Anchor wrapper so the "robots.txt AI crawler rules" toggle above can jump straight here.
			array(
				'before_section' => '<div id="mmsar-section-robots">',
				'after_section'  => '</div>',
			)
		);

		// These two only make sense while the plugin is actually generating robots.txt. When it
		// isn't, the section shows the opt-out explanation on its own.
		if ( mmsar_feature_enabled( 'robots_txt' ) ) {
			add_settings_field(
				'mmsar_robots_txt_preview',
				__( 'Current Content', 'make-my-site-agent-ready' ),
				array( __CLASS__, 'render_robots_txt_preview_field' ),
				'make-my-site-agent-ready',
				'mmsar_robots_txt'
			);

			add_settings_field(
				'mmsar_robots_txt_extra',
				__( 'Additional Rules', 'make-my-site-agent-ready' ),
				array( __CLASS__, 'render_robots_txt_field' ),
				'make-my-site-agent-ready',
				'mmsar_robots_txt'
			);
		}

		// Agent log: the one sub-option, kept on its own because it is the only setting that makes
		// the plugin inspect ordinary page requests rather than just its own endpoints.
		register_setting(
			'mmsar_settings_group',
			'mmsar_agent_log_pages',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_page_view_mode' ),
				'default'           => '',
			)
		);

		if ( mmsar_feature_enabled( 'agent_log' ) ) {
			add_settings_section(
				'mmsar_agent_log',
				__( 'Agent Request Log', 'make-my-site-agent-ready' ),
				array( __CLASS__, 'render_agent_log_section' ),
				'make-my-site-agent-ready'
			);

			add_settings_field(
				'mmsar_agent_log_pages',
				__( 'Also log normal page views', 'make-my-site-agent-ready' ),
				array( __CLASS__, 'render_agent_log_pages_field' ),
				'make-my-site-agent-ready',
				'mmsar_agent_log'
			);
		}

		// Content Signals settings.
		register_setting(
			'mmsar_settings_group',
			'mmsar_content_signals',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_content_signals' ),
				'default'           => array(
					'search'   => 'yes',
					'ai_input' => 'yes',
					'ai_train' => 'no',
				),
			)
		);

		add_settings_section(
			'mmsar_content_signals',
			__( 'Content Signals', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_content_signals_section' ),
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_content_signals_values',
			__( 'AI Usage Preferences', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_content_signals_field' ),
			'make-my-site-agent-ready',
			'mmsar_content_signals'
		);

		// Structured data (JSON-LD) settings.
		register_setting(
			'mmsar_settings_group',
			'mmsar_structured_data',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'mmsar_structured_data',
			__( 'Structured Data', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_structured_data_section' ),
			'make-my-site-agent-ready'
		);

		add_settings_field(
			'mmsar_structured_data_enabled',
			__( 'JSON-LD', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_structured_data_field' ),
			'make-my-site-agent-ready',
			'mmsar_structured_data'
		);

		// security.txt settings.
		register_setting(
			'mmsar_settings_group',
			'mmsar_security_txt_contact',
			array(
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'mmsar_settings_group',
			'mmsar_security_txt',
			array(
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);

		add_settings_section(
			'mmsar_security_txt',
			__( 'security.txt', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_security_txt_section' ),
			'make-my-site-agent-ready',
			// Anchor wrapper so the "security.txt" toggle above can jump straight here.
			array(
				'before_section' => '<div id="mmsar-section-security">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field(
			'mmsar_security_txt_contact',
			__( 'Security Contact', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_security_txt_contact_field' ),
			'make-my-site-agent-ready',
			'mmsar_security_txt'
		);

		add_settings_field(
			'mmsar_security_txt_content',
			__( 'Full Content (advanced)', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render_security_txt_field' ),
			'make-my-site-agent-ready',
			'mmsar_security_txt'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Input.
	 * @return mixed Result.
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized['post_types'] = array_map( 'sanitize_key', $input['post_types'] );
		} else {
			$sanitized['post_types'] = array();
		}

		if ( isset( $input['root_selector'] ) ) {
			$sanitized['root_selector'] = mb_substr( sanitize_text_field( $input['root_selector'] ), 0, 500 );
		} else {
			$sanitized['root_selector'] = '';
		}

		mmsar_flush_generated_documents();

		return $sanitized;
	}

	/**
	 * Render post types field.
	 *
	 * @return void
	 */
	public static function render_post_types_field() {
		$settings   = get_option( 'llmmd_settings', array() );
		$enabled    = isset( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$checked = in_array( $pt->name, $enabled, true ) ? 'checked' : '';
			echo '<label style="display:block;margin-bottom:6px;">';
			echo '<input type="checkbox" name="llmmd_settings[post_types][]" value="' . esc_attr( $pt->name ) . '" ' . esc_attr( $checked ) . '> ';
			echo esc_html( $pt->labels->name ) . ' <code>' . esc_html( $pt->name ) . '</code>';
			echo '</label>';
		}
	}

	/**
	 * Render root selector field.
	 *
	 * @return void
	 */
	public static function render_root_selector_field() {
		$settings = get_option( 'llmmd_settings', array() );
		$value    = isset( $settings['root_selector'] ) ? $settings['root_selector'] : '';
		echo '<input type="text" name="llmmd_settings[root_selector]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="main, article, .entry-content">';
		echo '<p class="description">' . esc_html__( 'CSS selector(s) to extract content from. Leave empty to use the full post content. Comma-separated for multiple selectors.', 'make-my-site-agent-ready' ) . '</p>';
	}

	/**
	 * Sanitize content signals.
	 *
	 * @param mixed $input Input.
	 * @return mixed Result.
	 */
	public static function sanitize_content_signals( $input ) {
		$valid = array( 'yes', 'no' );
		// ai_train defaults to 'no' to match the registered default and mmsar_content_signal_line() —
		// falling back to 'yes' for a missing/invalid value would silently opt content into training.
		$defaults = array(
			'search'   => 'yes',
			'ai_input' => 'yes',
			'ai_train' => 'no',
		);
		$out      = array();
		foreach ( array( 'search', 'ai_input', 'ai_train' ) as $key ) {
			$val         = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = in_array( $val, $valid, true ) ? $val : $defaults[ $key ];
		}
		return $out;
	}

	/**
	 * Render content signals section.
	 *
	 * @return void
	 */
	public static function render_content_signals_section() {
		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'Content Signals are published as a directive inside robots.txt, which is switched off in Features above. These settings are saved but have no effect until robots.txt handling is switched back on.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
		echo '<p>';
		esc_html_e( 'Content Signals (Content-Signal directives in robots.txt) declare how AI crawlers may use this content: for search indexing, for live retrieval when answering a query, and/or for training a model. This is an emerging, not-yet-ratified proposal — most crawlers do not honor it yet, but validators like isitagentready.com already check for it.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * Render content signals field.
	 *
	 * @return void
	 */
	public static function render_content_signals_field() {
		$settings = get_option(
			'mmsar_content_signals',
			array(
				'search'   => 'yes',
				'ai_input' => 'yes',
				'ai_train' => 'no',
			)
		);

		$fields = array(
			'search'   => array(
				__( 'Search', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be indexed by search engines.', 'make-my-site-agent-ready' ),
			),
			'ai_input' => array(
				__( 'AI Input', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be fetched as live input to an AI system (e.g. an assistant answering a question by reading this page).', 'make-my-site-agent-ready' ),
			),
			'ai_train' => array(
				__( 'AI Train', 'make-my-site-agent-ready' ),
				__( 'Allow this content to be included in a model training corpus.', 'make-my-site-agent-ready' ),
			),
		);

		foreach ( $fields as $key => $labels ) {
			list( $label, $description ) = $labels;
			$value                       = isset( $settings[ $key ] ) ? $settings[ $key ] : 'yes';
			echo '<p style="margin-bottom:14px;">';
			echo '<label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html( $label ) . '</label>';
			echo '<select name="mmsar_content_signals[' . esc_attr( $key ) . ']">';
			echo '<option value="yes"' . selected( $value, 'yes', false ) . '>' . esc_html__( 'Yes', 'make-my-site-agent-ready' ) . '</option>';
			echo '<option value="no"' . selected( $value, 'no', false ) . '>' . esc_html__( 'No', 'make-my-site-agent-ready' ) . '</option>';
			echo '</select>';
			echo '<p class="description">' . esc_html( $description ) . '</p>';
			echo '</p>';
		}
	}

	/**
	 * Sanitize checkbox.
	 *
	 * @param mixed $input Input.
	 * @return mixed Result.
	 */
	public static function sanitize_checkbox( $input ) {
		return ( '1' === $input ) ? '1' : '';
	}

	/**
	 * Render structured data section.
	 *
	 * @return void
	 */
	public static function render_structured_data_section() {
		if ( ! mmsar_feature_enabled( 'markdown' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'This structured data exists only to point agents at the .md version of a page, and Markdown URLs are switched off in Features above, so it has nothing to advertise. Switch Markdown URLs back on to use it.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
		echo '<p>';
		printf(
			/* translators: %s: link to validator.schema.org */
			esc_html__( 'Adds a pointer to the .md alternate (Article/WebPage type, dates, and a markdown link) to each enabled post/page. Off by default. If Yoast SEO is active and produces structured data for the page, the pointer merges directly into Yoast\'s own schema — no duplicate block. Otherwise (no Yoast, or Yoast doesn\'t cover this page type), a standalone JSON-LD block is added instead; if a different SEO plugin like RankMath is active, you may not need both. Validate the output at %s before relying on it.', 'make-my-site-agent-ready' ),
			'<a href="https://validator.schema.org/" target="_blank">validator.schema.org</a>'
		);
		echo '</p>';
	}

	/**
	 * Render structured data field.
	 *
	 * @return void
	 */
	public static function render_structured_data_field() {
		$checked = ( '1' === get_option( 'mmsar_structured_data', '' ) ) ? 'checked' : '';
		echo '<label>';
		echo '<input type="checkbox" name="mmsar_structured_data" value="1" ' . esc_attr( $checked ) . '> ';
		esc_html_e( 'Add JSON-LD structured data pointing agents at the markdown alternate.', 'make-my-site-agent-ready' );
		echo '</label>';
	}

	/**
	 * Render robots txt section.
	 *
	 * @return void
	 */
	public static function render_robots_txt_section() {
		$url = home_url( '/robots.txt' );

		if ( ! mmsar_feature_enabled( 'robots_txt' ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>';
			esc_html_e( 'robots.txt handling is switched off.', 'make-my-site-agent-ready' );
			echo '</strong></p><p>';
			esc_html_e( 'This plugin is not touching your robots.txt at all — whatever served it before (a static file, your SEO plugin, or WordPress itself) is serving it unchanged. Because of that, your site is not publishing:', 'make-my-site-agent-ready' );
			echo '</p><ul style="list-style:disc;margin-left:22px;">';
			echo '<li>' . esc_html__( 'Explicit Allow rules for AI crawlers (GPTBot, ClaudeBot, Anthropic-AI, GoogleOther, PerplexityBot, FacebookBot). Without them, these crawlers fall back to your general rules, which may be more restrictive than you intend.', 'make-my-site-agent-ready' ) . '</li>';
			echo '<li>' . esc_html__( 'The Content-Signal directive declaring how AI systems may use your content. The Content Signals settings below have no effect while this is off, because those directives are written into robots.txt.', 'make-my-site-agent-ready' ) . '</li>';
			echo '<li>' . esc_html__( 'A Sitemap directive, if nothing else on your site already adds one.', 'make-my-site-agent-ready' ) . '</li>';
			echo '<li>' . esc_html__( 'An Llms-txt directive pointing at your llms.txt, for agents that read robots.txt first.', 'make-my-site-agent-ready' ) . '</li>';
			echo '</ul><p>';
			esc_html_e( 'If you manage AI crawler rules yourself, that is fine — nothing is broken. Add the rules to your own robots.txt, or switch this feature back on in Features above.', 'make-my-site-agent-ready' );
			echo '</p></div>';
			return;
		}

		echo '<p>';
		printf(
			/* translators: %s: robots.txt URL */
			esc_html__( 'This plugin appends explicit Allow rules for AI crawlers (GPTBot, ClaudeBot, etc.), a Content-Signal directive, a Sitemap directive, and an Llms-txt directive to %s.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( $url ) . '" target="_blank"><code>robots.txt</code></a>'
		);
		echo '</p>';
		echo '<p>';
		esc_html_e( 'The Llms-txt directive points agents at your llms.txt from the first file most of them fetch. It is not a ratified standard — the llms.txt proposal says nothing about robots.txt — but parsers ignore directives they do not recognize, so it cannot affect crawling, and agent-readiness checkers do look for it. It is skipped if llms.txt is switched off in Features above, or if the finished robots.txt already mentions llms.txt (so a line you added by hand below is left alone rather than duplicated).', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p>';
		esc_html_e( 'It appends rather than replaces, so it works alongside any robots.txt that WordPress generates on the fly — including one produced by an SEO plugin such as Yoast, Rank Math or All in One SEO. Their rules stay exactly as they are, and the AI crawler rules are added underneath.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p>';
		esc_html_e( 'If any rule in the finished robots.txt disallows a path one of your published endpoints lives on — several SEO plugins disallow /wp-json/ by default — an Allow line for that individual endpoint is added above the rule blocking it, in the same user-agent group. Crawlers apply the more specific rule, so the endpoint you advertise in the api-catalog, llms.txt and Agent Skills index stays reachable while the rest of that path stays disallowed. Endpoints nothing blocks are left alone.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p>';
		esc_html_e( 'It also tries to route /robots.txt through WordPress so these rules still apply on sites that have a physical robots.txt file in the site root. Whether that succeeds depends on your server: it works on Apache, but nginx and most CDNs serve an existing file directly without ever asking WordPress, so the physical file keeps winning. If you maintain that file deliberately, switch this feature off in Features above and the plugin will stop trying.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * Render robots txt preview field.
	 *
	 * @return void
	 */
	public static function render_robots_txt_preview_field() {
		$public  = (int) get_option( 'blog_public' );
		$content = "User-agent: *\n";
		if ( ! $public ) {
			$content .= "Disallow: /\n";
		} else {
			$content .= "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		}
		// Core's own filter, invoked on purpose. The preview is only truthful if it runs the exact
		// chain that builds the served robots.txt — every SEO plugin hooks this one, and a prefixed
		// hook of our own would preview nothing but our own contribution.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional: reproducing core's robots.txt filter chain for the preview.
		$content = apply_filters( 'robots_txt', $content, $public );
		echo '<textarea readonly rows="18" class="large-text code" style="background:#f6f7f7;color:#3c434a;">' . esc_textarea( $content ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Read-only preview of the robots.txt output. Some plugins only modify robots.txt on front-end requests, so the served file can differ slightly from this preview — open /robots.txt above to see the real thing.', 'make-my-site-agent-ready' ) . '</p>';
	}

	/**
	 * Render robots txt field.
	 *
	 * @return void
	 */
	public static function render_robots_txt_field() {
		$value = get_option( 'mmsar_robots_txt_extra', '' );
		echo '<textarea name="mmsar_robots_txt_extra" rows="5" class="large-text code" placeholder="# e.g. User-agent: Bingbot&#10;# Allow: /">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional extra directives appended to robots.txt. Leave empty if you only need the default AI crawler rules.', 'make-my-site-agent-ready' ) . '</p>';
	}

	/**
	 * Render security txt section.
	 *
	 * @return void
	 */
	public static function render_security_txt_section() {
		if ( ! mmsar_feature_enabled( 'security_txt' ) ) {
			echo '<p class="description"><em>';
			esc_html_e( 'security.txt is switched off in Features above, so nothing is served at /.well-known/security.txt. These settings are saved but inactive.', 'make-my-site-agent-ready' );
			echo '</em></p>';
			return;
		}
		$url = home_url( '/.well-known/security.txt' );
		echo '<p>';
		printf(
			/* translators: %s: security.txt URL */
			esc_html__( 'Serves a security contact file at %s per RFC 9116. This is where a security researcher looks first when they find a vulnerability on your site and want to report it responsibly.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( $url ) . '" target="_blank"><code>/.well-known/security.txt</code></a>'
		);
		echo '</p>';
	}

	/**
	 * Render security txt contact field.
	 *
	 * @return void
	 */
	public static function render_security_txt_contact_field() {
		$value = get_option( 'mmsar_security_txt_contact', '' );
		echo '<input type="text" name="mmsar_security_txt_contact" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="/contact">';
		echo '<p class="description">';
		esc_html_e( 'Where should someone report a security issue? Usually your contact page. Paste the full URL of that page, or just the path.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p class="description">';
		printf(
			/* translators: 1: full URL example, 2: path example, 3: email example */
			esc_html__( 'All of these work: %1$s, %2$s, or an email address like %3$s.', 'make-my-site-agent-ready' ),
			'<code>' . esc_html( home_url( '/contact' ) ) . '</code>',
			'<code>/contact</code>',
			'<code>security@example.com</code>'
		);
		echo '</p>';

		$resolved = MMSAR_Endpoints::normalize_contact( $value );
		if ( '' === $resolved ) {
			echo '<p class="description"><strong>';
			printf(
				/* translators: %s: site admin email address */
				esc_html__( 'Nothing set, so the file currently falls back to your admin email (%s). Setting a contact page is usually better.', 'make-my-site-agent-ready' ),
				'<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
			);
			echo '</strong></p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: %s: the resolved Contact line that will be published */
				esc_html__( 'Will publish: %s', 'make-my-site-agent-ready' ),
				'<code>Contact: ' . esc_html( $resolved ) . '</code>'
			);
			echo '</p>';
		}
	}

	/**
	 * Render security txt field.
	 *
	 * @return void
	 */
	public static function render_security_txt_field() {
		$value       = get_option( 'mmsar_security_txt', '' );
		$placeholder = MMSAR_Endpoints::default_security_txt();
		echo '<textarea name="mmsar_security_txt" rows="6" class="large-text code" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional. Leave this empty unless you need extra fields such as Encryption, Acknowledgments or Policy — the Security Contact above is enough for most sites. Anything entered here replaces the generated file entirely, including the Contact line, so it must contain both Contact and Expires.', 'make-my-site-agent-ready' ) . '</p>';
	}

	/**
	 * Intro copy for the content negotiation section.
	 *
	 * @return void
	 */
	public static function render_negotiation_section() {
		echo '<p>';
		esc_html_e( 'When an AI client fetches one of your pages it usually asks for markdown in the same request, using the Accept header, rather than looking for a separate .md address. Switching this on answers that request with your markdown instead of the HTML page. It is what the Accept and Vary headers exist for, and on most sites it simply works.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '<p>';
		esc_html_e( 'The risk is not in your site, it is in whatever caches it. A CDN that stores the markdown response without noticing it was asked for specifically will hand that copy to the next person who opens the page in a browser, who gets a file download instead of your site. This plugin asks caches not to store it, but cannot make them listen, and at least one host has been seen rewriting that instruction on the way out. So rather than promise, it measures.', 'make-my-site-agent-ready' );
		echo '</p>';
	}

	/**
	 * The self-check: what the last run found, and a button to run it again.
	 *
	 * @return void
	 */
	public static function render_negotiation_check_field() {
		$result = MMSAR_Negotiation_Check::get_result();

		if ( ! mmsar_feature_enabled( 'markdown' ) ) {
			echo '<p class="description">';
			esc_html_e( 'Markdown URLs are switched off, so there is no markdown for this to serve. Turn that on first.', 'make-my-site-agent-ready' );
			echo '</p>';
			return;
		}

		echo '<p class="description">';
		esc_html_e( 'Asks this site for one of its own pages twice — once the way an AI client asks, then the same URL the way a browser asks — and reports which version came back each time. If the browser-style request is answered with markdown, a cache is serving the wrong copy to your readers and negotiation is switched back off automatically. A throwaway URL is used, so the check can never leave a markdown copy of a real page sitting in a cache.', 'make-my-site-agent-ready' );
		echo '</p>';

		if ( $result ) {
			$notices                 = array(
				'pass'     => array( 'notice-success', __( 'Working', 'make-my-site-agent-ready' ) ),
				'warn'     => array( 'notice-warning', __( 'Working, with one protection missing', 'make-my-site-agent-ready' ) ),
				'foreign'  => array( 'notice-warning', __( 'Something else is answering — not this plugin', 'make-my-site-agent-ready' ) ),
				'fail'     => array( 'notice-error', __( 'Not safe on this site — switched off', 'make-my-site-agent-ready' ) ),
				'inactive' => array( 'notice-warning', __( 'Not taking effect', 'make-my-site-agent-ready' ) ),
				'error'    => array( 'notice-warning', __( 'Could not be checked', 'make-my-site-agent-ready' ) ),
			);
			$status                  = isset( $result['status'] ) ? $result['status'] : 'error';
			list( $class, $heading ) = isset( $notices[ $status ] ) ? $notices[ $status ] : $notices['error'];

			echo '<div class="notice ' . esc_attr( $class ) . ' inline" style="margin:12px 0;padding:8px 12px;">';
			echo '<p><strong>' . esc_html( $heading ) . '</strong></p>';
			echo '<p>' . esc_html( isset( $result['message'] ) ? $result['message'] : '' ) . '</p>';

			if ( ! empty( $result['details'] ) && is_array( $result['details'] ) ) {
				echo '<p class="description">' . esc_html( self::format_check_details( $result['details'] ) ) . '</p>';
			}

			if ( ! empty( $result['time'] ) ) {
				echo '<p class="description">';
				printf(
					/* translators: %s: human-readable time difference, e.g. "5 mins" */
					esc_html__( 'Checked %s ago.', 'make-my-site-agent-ready' ),
					esc_html( human_time_diff( (int) $result['time'] ) )
				);
				echo '</p>';
			}
			echo '</div>';
		}

		echo '<p><a class="button button-secondary" href="' . esc_url( MMSAR_Negotiation_Check::run_url() ) . '">'
			. esc_html__( 'Run the check', 'make-my-site-agent-ready' ) . '</a></p>';

		if ( ! mmsar_feature_enabled( 'markdown_negotiation' ) ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: link to the Features list at the top of the page */
				esc_html__( 'Content negotiation is currently off. The switch for it is %s at the top of this page, named "Markdown from the normal page URL" — tick it and save, and the check runs by itself. Running the check while it is off still tells you something: if markdown comes back anyway, a CDN is answering these requests instead of your site.', 'make-my-site-agent-ready' ),
				'<a href="#mmsar-section-features">' . esc_html__( 'in the Features list', 'make-my-site-agent-ready' ) . '</a>'
			);
			echo '</p>';
		}
	}

	/**
	 * The observed headers, as one readable line.
	 *
	 * Shown verbatim rather than interpreted: when this feature goes wrong it goes wrong in
	 * somebody else's infrastructure, and the person who has to take it up with their host needs
	 * the actual values, not this plugin's reading of them.
	 *
	 * @param array $details Observed values from the check.
	 * @return string
	 */
	private static function format_check_details( $details ) {
		$labels = array(
			'markdown_type' => __( 'Agent request returned', 'make-my-site-agent-ready' ),
			'browser_type'  => __( 'Browser request returned', 'make-my-site-agent-ready' ),
			'cache_control' => __( 'Cache-Control received', 'make-my-site-agent-ready' ),
			'vary'          => __( 'Vary received', 'make-my-site-agent-ready' ),
		);
		$parts  = array();
		foreach ( $labels as $key => $label ) {
			$value   = ( isset( $details[ $key ] ) && '' !== $details[ $key ] )
				? $details[ $key ]
				: __( '(none)', 'make-my-site-agent-ready' );
			$parts[] = $label . ': ' . $value;
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'mmsar_settings_group' );
				do_settings_sections( 'make-my-site-agent-ready' );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Regenerate Markdown', 'make-my-site-agent-ready' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Regenerate cached markdown for all published content. This happens automatically when posts are saved.', 'make-my-site-agent-ready' ); ?></p>
			<?php
			if ( isset( $_GET['mmsar_regenerated'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'mmsar_regenerate' ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'All markdown content has been regenerated.', 'make-my-site-agent-ready' ) . '</p></div>';
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mmsar_regenerate">
				<?php wp_nonce_field( 'mmsar_regenerate', 'mmsar_nonce' ); ?>
				<?php submit_button( __( 'Regenerate All', 'make-my-site-agent-ready' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

