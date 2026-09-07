<?php
/**
 * WordPress Abilities API integration for Make My Site Agent-Ready.
 * Requires WP 6.9+ (Abilities API). Does nothing on older versions.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

add_action( 'wp_abilities_api_categories_init', 'mmsar_register_ability_category' );
/**
 * Mmsar register ability category.
 *
 * @return void
 */
function mmsar_register_ability_category() {
	// Guarded separately from the wp_register_ability() check at the top of this file: it is a
	// different function, and the category API landed alongside but not identically to it. The
	// callback is only reachable on WP 6.9+ in practice, but the guard makes that true by
	// construction rather than by the file-level return happening to have run first.
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'make-my-site-agent-ready',
		array(
			'label'       => __( 'Make My Site Agent-Ready', 'make-my-site-agent-ready' ),
			'description' => __( 'Inspect plugin settings, read the agent request log, and trigger content regeneration.', 'make-my-site-agent-ready' ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'mmsar_register_abilities' );
/**
 * Register the plugin's abilities with the WordPress Abilities API.
 *
 * @return void
 */
function mmsar_register_abilities() {

	wp_register_ability(
		'make-my-site-agent-ready/get-settings',
		array(
			'label'               => __( 'Get Settings', 'make-my-site-agent-ready' ),
			'description'         => __( 'Retrieve plugin settings: which features are enabled, enabled post types, and content root selector.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'features'           => array(
						'type'                 => 'object',
						'description'          => 'Which outputs this plugin is publishing. A feature set to false is switched off and its endpoint is not served.',
						'additionalProperties' => array( 'type' => 'boolean' ),
					),
					'enabled_post_types' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Post types for which markdown files are generated.',
					),
					'root_selector'      => array(
						'type'        => 'string',
						'description' => 'CSS selector used to extract content. Empty string means full post content.',
					),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				$features = array();
				foreach ( array_keys( mmsar_get_feature_keys() ) as $key ) {
					$features[ $key ] = mmsar_feature_enabled( $key );
				}
				return array(
					'features'           => $features,
					'enabled_post_types' => mmsar_get_enabled_post_types(),
					'root_selector'      => mmsar_get_root_selector(),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/list-endpoints',
		array(
			'label'               => __( 'List Endpoints', 'make-my-site-agent-ready' ),
			'description'         => __( 'List the endpoints published in this site\'s agent-facing documents (api-catalog, llms.txt, Agent Skills), showing which are managed on the settings page and which a plugin or theme registered in code, and where each one is actually appearing.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'endpoints' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'string' ),
								'title'        => array( 'type' => 'string' ),
								'href'         => array( 'type' => 'string' ),
								'description'  => array( 'type' => 'string' ),
								'type'         => array( 'type' => 'string' ),
								'rel'          => array( 'type' => 'string' ),
								'methods'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'auth'         => array( 'type' => 'string' ),
								'managed_here' => array(
									'type'        => 'boolean',
									'description' => 'True when it is stored in this plugin\'s settings and can be changed with set-endpoint. False when a plugin or theme registered it in code, in which case it can only be changed by editing that plugin or theme.',
								),
								'published_in' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => 'Documents it is actually appearing in right now. Empty when every document it asked for is switched off.',
								),
							),
						),
					),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				$stored_ids = wp_list_pluck( MMSAR_Registry::get_stored(), 'id' );
				$out        = array();
				foreach ( MMSAR_Registry::get_endpoints() as $endpoint ) {
					// Report where it is really appearing, not where it asked to appear: a document
					// whose feature is switched off publishes nothing regardless of the entry.
					$published = array();
					foreach ( $endpoint['surfaces'] as $surface ) {
						if ( mmsar_feature_enabled( $surface ) ) {
							$published[] = $surface;
						}
					}
					$out[] = array(
						'id'           => $endpoint['id'],
						'title'        => $endpoint['title'],
						'href'         => $endpoint['href'],
						'description'  => $endpoint['description'],
						'type'         => $endpoint['type'],
						'rel'          => $endpoint['rel'],
						'methods'      => $endpoint['methods'],
						'auth'         => $endpoint['auth'],
						'managed_here' => in_array( $endpoint['id'], $stored_ids, true ),
						'published_in' => $published,
					);
				}
				return array( 'endpoints' => $out );
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/set-endpoint',
		array(
			'label'               => __( 'Add or Update Endpoint', 'make-my-site-agent-ready' ),
			'description'         => __( 'Add an endpoint to this site\'s agent-facing documents, or update one already managed on the settings page. Passing an existing id updates that entry; omitting it creates a new one. Cannot change endpoints a plugin or theme registered in code.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'input_schema'        => array(
				'type'       => 'object',
				// title and href are required to create but not to update: marking them required
				// here would reject "change just the description on this id", which is the most
				// natural way to use this. The callback enforces them on the create path instead.
				'properties' => array(
					'id'          => array(
						'type'        => 'string',
						'description' => 'Id of an existing entry to update. Omit to create a new one. When updating, send only the fields you want changed.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Short human-readable name, e.g. "Contact form".',
					),
					'href'        => array(
						'type'        => 'string',
						'description' => 'Absolute http(s) URL of the endpoint.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'One sentence on what it does and anything a caller must do first. This is what an agent reads to decide whether to use it.',
					),
					'type'        => array(
						'type'        => 'string',
						'description' => 'Media type the endpoint really returns, e.g. "application/json". Leave out if unsure — a wrong type is worse than none, and an unstated one is simply omitted.',
					),
					'rel'         => array(
						'type'        => 'string',
						'enum'        => MMSAR_Registry::RELATIONS,
						'description' => 'api-catalog link relation. Defaults to "item". Use "service-desc" when fetching the URL returns a description of the API itself.',
					),
					'methods'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'HTTP methods it accepts, e.g. ["GET","POST"].',
					),
					'auth'        => array(
						'type'        => 'string',
						'description' => 'How to authenticate, e.g. "none".',
					),
					'surfaces'    => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => MMSAR_Registry::SURFACES,
						),
						'description' => 'Which documents to appear in. Defaults to all three.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'id'           => array( 'type' => 'string' ),
					'created'      => array( 'type' => 'boolean' ),
					'published_in' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function ( $input ) {
				$rows = MMSAR_Registry::get_stored();
				$id   = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';

				// Refuse to shadow a code-registered endpoint. Writing a stored row with the same id
				// would not change the code entry, it would sit alongside it and be silently dropped
				// as a duplicate — so fail loudly instead of reporting a success that did nothing.
				if ( '' !== $id ) {
					$stored_ids = wp_list_pluck( $rows, 'id' );
					foreach ( MMSAR_Registry::get_endpoints() as $existing ) {
						if ( $existing['id'] === $id && ! in_array( $id, $stored_ids, true ) ) {
							return new WP_Error(
								'mmsar_endpoint_not_editable',
								__( 'That endpoint was registered in code by a plugin or theme, so it cannot be changed here. Edit the plugin or theme that added it.', 'make-my-site-agent-ready' ),
								array( 'status' => 409 )
							);
						}
					}
				}

				$fields = array();
				foreach ( array( 'title', 'href', 'description', 'type', 'rel', 'methods', 'auth', 'surfaces' ) as $key ) {
					if ( isset( $input[ $key ] ) ) {
						$fields[ $key ] = $input[ $key ];
					}
				}

				$created = true;
				if ( '' !== $id ) {
					foreach ( $rows as $index => $row ) {
						if ( isset( $row['id'] ) && $row['id'] === $id ) {
							// Merge, so a caller updating one field does not blank the others.
							$rows[ $index ] = array_merge( $row, $fields );
							$created        = false;
							break;
						}
					}
				}
				if ( $created ) {
					// An omitted `surfaces` means different things on the two routes into storage:
					// the settings form omits the key when every box is unticked (publish nowhere),
					// while a caller here simply did not express a preference. Fill in the documented
					// default explicitly, so the two callers cannot be confused for one another.
					if ( ! isset( $fields['surfaces'] ) ) {
						$fields['surfaces'] = MMSAR_Registry::SURFACES;
					}

					// Creating needs both; updating needs neither, since the stored row supplies them.
					if ( empty( $fields['title'] ) || empty( $fields['href'] ) ) {
						return new WP_Error(
							'mmsar_endpoint_incomplete',
							'' !== $id
								? __( 'No endpoint with that id is managed on the settings page. To create a new one, send a title and href (and omit the id).', 'make-my-site-agent-ready' )
								: __( 'A new endpoint needs both a title and an href.', 'make-my-site-agent-ready' ),
							array( 'status' => 400 )
						);
					}
					$fields['id'] = $id;
					$rows[]       = $fields;
				}

				// Same sanitizer the settings form uses, so both routes store identical shapes.
				$clean = MMSAR_Registry::sanitize_rows( $rows );
				update_option( MMSAR_Registry::OPTION, $clean );

				// Report the entry as saved, and separately whether it is fit to publish — a URL the
				// validator rejects is stored but never appears, and the caller must be told that.
				// A new row is appended last and sanitize_rows preserves order, so it is the tail;
				// an updated row is found by its id, which sanitize_rows keeps stable.
				$saved = null;
				if ( $created ) {
					$saved = $clean ? end( $clean ) : null;
				} else {
					foreach ( $clean as $row ) {
						if ( $row['id'] === $id ) {
							$saved = $row;
							break;
						}
					}
				}
				if ( ! $saved ) {
					return new WP_Error( 'mmsar_endpoint_not_saved', __( 'The endpoint could not be saved.', 'make-my-site-agent-ready' ), array( 'status' => 500 ) );
				}

				$published = array();
				$valid     = MMSAR_Registry::normalize( $saved );
				if ( $valid ) {
					foreach ( $valid['surfaces'] as $surface ) {
						if ( mmsar_feature_enabled( $surface ) ) {
							$published[] = $surface;
						}
					}
				}

				return array(
					'success'      => true,
					'id'           => $saved['id'],
					'created'      => $created,
					'published_in' => $published,
					'message'      => $published
						/* translators: %s: comma-separated list of document names */
						? sprintf( __( 'Saved. Now listed in: %s', 'make-my-site-agent-ready' ), implode( ', ', $published ) )
						: __( 'Saved, but not being published: it needs a valid http(s) URL and at least one document that is switched on.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/delete-endpoint',
		array(
			'label'               => __( 'Delete Endpoint', 'make-my-site-agent-ready' ),
			'description'         => __( 'Remove an endpoint managed on the settings page, so it stops appearing in this site\'s agent-facing documents. Cannot remove endpoints a plugin or theme registered in code.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id' => array(
						'type'        => 'string',
						'description' => 'Id of the endpoint to remove, as returned by list-endpoints.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function ( $input ) {
				$id   = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
				$rows = MMSAR_Registry::get_stored();

				$remaining = array();
				$found     = false;
				foreach ( $rows as $row ) {
					if ( isset( $row['id'] ) && $row['id'] === $id ) {
						$found = true;
						continue;
					}
					$remaining[] = $row;
				}

				if ( ! $found ) {
					// Distinguish "no such endpoint" from "exists but is not yours to delete", so the
					// caller knows whether to fix the id or go and edit the plugin that owns it.
					foreach ( MMSAR_Registry::get_endpoints() as $existing ) {
						if ( $existing['id'] === $id ) {
							return new WP_Error(
								'mmsar_endpoint_not_editable',
								__( 'That endpoint was registered in code by a plugin or theme, so it cannot be removed here. Edit the plugin or theme that added it.', 'make-my-site-agent-ready' ),
								array( 'status' => 409 )
							);
						}
					}
					return new WP_Error(
						'mmsar_endpoint_not_found',
						__( 'No endpoint with that id is managed on the settings page.', 'make-my-site-agent-ready' ),
						array( 'status' => 404 )
					);
				}

				update_option( MMSAR_Registry::OPTION, $remaining );

				return array(
					'success' => true,
					'message' => __( 'Endpoint removed. It no longer appears in the agent-facing documents.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/regenerate-files',
		array(
			'label'               => __( 'Regenerate Markdown Files', 'make-my-site-agent-ready' ),
			'description'         => __( 'Regenerate cached markdown for all published posts across all enabled post types. On large sites this may take several seconds.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function () {
				mmsar_bulk_generate();
				mmsar_flush_generated_documents();
				return array(
					'success' => true,
					'message' => __( 'Markdown files regenerated for all published content.', 'make-my-site-agent-ready' ),
				);
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'make-my-site-agent-ready/get-agent-log',
		array(
			'label'               => __( 'Get Agent Log', 'make-my-site-agent-ready' ),
			'description'         => __( 'Read the agent request log: which agents fetched which of this site\'s agent-facing surfaces, when, and whether the crawler identity each one claimed is genuine. Returns aggregate counts by agent, by surface, by requested detail and by day over the whole log, plus a verification breakdown and one page of individual entries. All datetimes are UTC. Ask for summary_only when the shape of the traffic is the question, which is most of the time — the aggregates cover every entry, while entries only ever cover the page requested. Read the verification block before quoting any per-agent number: the agent column is self-declared, forging it is common, and an unverified count is a claim rather than a measurement.', 'make-my-site-agent-ready' ),
			'category'            => 'make-my-site-agent-ready',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 500,
						'default'     => 50,
						'description' => 'How many individual entries to return, newest first. Ignored when summary_only is true.',
					),
					'offset'       => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Entries to skip before returning any, for paging back through the log.',
					),
					'summary_only' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return the aggregates and omit individual entries. The aggregates carry no IP addresses, so this is also the way to read the log without handling them.',
					),
					'surface'      => array(
						'type'        => 'string',
						'enum'        => array( '', 'docs', 'markdown', 'html', 'notfound' ),
						'default'     => '',
						'description' => 'Restrict entries by what kind of surface was requested. "docs" is the agent-facing documents: llms.txt and its scoped variants, api-catalog, the MCP descriptors, Agent Skills and SKILL.md, openapi.json, auth.md, ai-catalog, schema-map, nlweb and ?mode=agent. "markdown" is the .md mirrors and content-negotiated Markdown. "html" is ordinary page views. "notfound" is the agent 404s. Empty string is all of them. Combine with client="crawler" or a verified filter to ask the question this log exists for: did anything real read the agent-facing documents.',
					),
					'client'       => array(
						'type'        => 'string',
						'enum'        => array( '', 'crawler', 'browser', 'http', 'all' ),
						'default'     => '',
						'description' => 'Restrict entries by what kind of client made the request, judged from the shape of the request rather than its name. "crawler" named a recognised crawler, or announced itself as a bot. "browser" made a document navigation, which is a shape the Fetch API cannot ask for. "http" is a script, CLI or agent fetch tool, which is what an agent using a fetch tool looks like. Empty string is the default and returns everything except browsers, because this is an agent log and browser page views are recorded as a denominator rather than as agent traffic; pass "all" to include them. A browser signature identifies the software, not a person: an agent driving a headless Chrome is indistinguishable from a human reader here.',
					),
					'verified'     => array(
						'type'        => 'string',
						'enum'        => array( '', 'verified', 'failed', 'unverifiable', 'unclaimed', 'nodns', 'pending' ),
						'default'     => '',
						'description' => 'Restrict the returned entries to one verification verdict. Empty string means no filter; use "pending" for entries not yet checked. Applies to entries only — the aggregates always cover the whole log. Asking for "failed" is the direct way to list the requests that forged a crawler identity.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'logging_enabled'     => array(
						'type'        => 'boolean',
						'description' => 'False when the agent log feature is switched off. Existing entries are still readable; nothing new is being recorded, so a quiet recent period may mean the log was off rather than that no agents called.',
					),
					'page_views_recorded' => array(
						'type'        => 'string',
						'enum'        => array( 'off', 'agents', 'all' ),
						'description' => 'How much ordinary HTML page-view traffic is recorded, which decides what a share of this log can legitimately be compared against. "off": only requests for agent-facing files appear, so no share is meaningful. "agents": page views are recorded for recognized AI crawlers only — a correct denominator for those, and a **badly skewed one for everybody else**, because an unrecognized client\'s agent-file requests are recorded while its page views are not, making anything unbranded look as though it reads nothing but agent-facing files. "all": every page view is recorded, including human traffic, so shares are comparable across every client. Check this before computing any percentage from these counts.',
					),
					'retention_limit'     => array(
						'type'        => 'integer',
						'description' => 'Entries kept before the oldest are dropped. 0 means everything is kept, so the first entry is the true beginning of the record.',
					),
					'total'               => array( 'type' => 'integer' ),
					'unique_agents'       => array( 'type' => 'integer' ),
					'unique_ips'          => array( 'type' => 'integer' ),
					'first_logged_at'     => array(
						'type'        => 'string',
						'description' => 'UTC datetime of the oldest entry, "Y-m-d H:i:s". Empty when the log is empty.',
					),
					'last_logged_at'      => array(
						'type'        => 'string',
						'description' => 'UTC datetime of the newest entry, "Y-m-d H:i:s". Empty when the log is empty.',
					),
					'throttle_seconds'    => array(
						'type'        => 'integer',
						'description' => 'The same agent, surface and IP is recorded at most once per this many seconds. Counts are therefore a lower bound on requests and should be read as reach, not volume.',
					),
					'surface_categories'  => array(
						'type'        => 'object',
						'description' => 'Request counts by what kind of surface was asked for, over the whole log and across every client. "docs" is the agent-facing documents, "markdown" the .md mirrors and negotiated Markdown, "html" ordinary page views, "notfound" the agent 404s. Reading docs and markdown against html is the headline this log exists to produce, but do it per client class rather than in aggregate: pass surface with client="crawler", or with a verified filter, since unbranded and forged traffic behave nothing like real crawlers.',
						'properties'  => array(
							'docs'     => array( 'type' => 'integer' ),
							'markdown' => array( 'type' => 'integer' ),
							'html'     => array( 'type' => 'integer' ),
							'notfound' => array( 'type' => 'integer' ),
						),
					),
					'client_types'        => array(
						'type'        => 'object',
						'description' => 'Request counts by what kind of software made them, over the whole log. "crawler" named a recognised crawler or announced itself as a bot; "browser" made a document navigation, a shape only a real browser engine produces; "http" is a script, CLI or agent fetch tool; "unrecorded" predates the check and cannot be classified retroactively, because the headers were never stored. Rows recorded between 1.26.0 and 1.30.1 under-count "http": any agent using Node\'s built-in fetch was filed as "browser" until 1.31.4, and cannot be reclassified. **The browser count is a denominator, not agent traffic.** It exists so shares can be computed honestly, and those entries are excluded from the list unless client is "browser" or "all". A browser signature identifies software, not a person: an agent driving a headless Chrome sends exactly what a reader does.',
						'properties'  => array(
							'crawler'    => array( 'type' => 'integer' ),
							'browser'    => array( 'type' => 'integer' ),
							'http'       => array( 'type' => 'integer' ),
							'unrecorded' => array( 'type' => 'integer' ),
						),
					),
					'verification'        => array(
						'type'        => 'object',
						'description' => 'Whether the crawler identity each entry claimed is genuine, counted over the whole log. The agent column is a self-declared user-agent string and forging it is common rather than exotic: on the site this feature was built for, three separate addresses each rotated through five or more AI-crawler identities within seconds, and every 404 attributed to GPTBot came from an address OpenAI does not publish. Read this block before quoting any per-agent figure.',
						'properties'  => array(
							'verified'        => array(
								'type'        => 'integer',
								'description' => 'Entries that claimed a known crawler and proved it: either the address falls inside the range the operator publishes, or reverse DNS forward-confirms to a hostname under a domain that operator owns.',
							),
							'failed'          => array(
								'type'        => 'integer',
								'description' => 'Entries that claimed a known crawler and are not it. **This is the spoofing count.** The user-agent named an operator, and the address neither appears in that operator\'s published ranges nor reverse-resolves into its domain. Any per-agent number that includes these rows is inflated by them.',
							),
							'unverifiable'    => array(
								'type'        => 'integer',
								'description' => 'Entries that claimed a crawler whose operator publishes no verification method this release knows about. **Not an accusation of anything** — it records that the plugin cannot check, not that the caller lied. Do not read it as a softer "failed".',
							),
							'unclaimed'       => array(
								'type'        => 'integer',
								'description' => 'Entries whose user-agent names no known crawler, so there was no claim to check and no lookup was made. Most unbranded traffic lands here, including ordinary browsers and any client using a generic user-agent. It says nothing either way about who they were.',
							),
							'nodns'           => array(
								'type'        => 'integer',
								'description' => 'Entries where the resolver returned nothing usable, for a crawler verified by reverse DNS. Retryable, unlike the other verdicts — a later pass re-checks these rather than leaving them decided.',
							),
							'pending'         => array(
								'type'        => 'integer',
								'description' => 'Entries not yet checked. **Above zero means every count in this block is provisional and covers only the checked part of the log**, so a low "failed" figure may mean nothing was found or may mean nothing has been looked at. Verification runs in small batches when an administrator opens the Agent Log screen or calls this ability, and in a large batch from the "Verify now" button on that screen; it deliberately never runs while a page is being served to a visitor.',
							),
							'ranges_captured' => array(
								'type'        => 'string',
								'description' => 'The date the bundled published-IP-range data was captured, "Y-m-d". Anthropic, OpenAI and Perplexity publish no reverse-DNS records for their crawlers, so those operators are checked against the ranges they publish, shipped with the plugin rather than fetched. A genuine crawler arriving from a range added after this date reads as "failed" until the plugin is updated — so weigh a failed verdict against these three operators by how old this date is.',
							),
							'first_checked'   => array(
								'type'        => 'string',
								'description' => 'UTC datetime of the earliest verdict reached, "Y-m-d H:i:s".',
							),
							'last_checked'    => array(
								'type'        => 'string',
								'description' => 'UTC datetime of the most recent verdict reached, "Y-m-d H:i:s".',
							),
						),
					),
					'by_agent'            => array(
						'type'        => 'array',
						'description' => 'Busiest agents first. An agent is the matched crawler name where recognized, otherwise the raw user-agent string, truncated. Each row carries its own verdict counts, which is what makes a forged identity visible without cross-tabbing by hand: a row whose "failed" is close to its "requests" is a name being worn by something else, and its request total should not be attributed to the operator it names.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'agent'        => array( 'type' => 'string' ),
								'requests'     => array( 'type' => 'integer' ),
								'surfaces'     => array( 'type' => 'integer' ),
								'unique_ips'   => array( 'type' => 'integer' ),
								'verified'     => array( 'type' => 'integer' ),
								'failed'       => array( 'type' => 'integer' ),
								'unverifiable' => array( 'type' => 'integer' ),
								'unclaimed'    => array( 'type' => 'integer' ),
								'nodns'        => array( 'type' => 'integer' ),
								'pending'      => array( 'type' => 'integer' ),
								'first_seen'   => array( 'type' => 'string' ),
								'last_seen'    => array( 'type' => 'string' ),
							),
						),
					),
					'by_surface'          => array(
						'type'        => 'array',
						'description' => 'Most-requested surfaces first, e.g. "llms.txt", "Markdown (.md URL)", "api-catalog".',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'surface'    => array( 'type' => 'string' ),
								'requests'   => array( 'type' => 'integer' ),
								'agents'     => array( 'type' => 'integer' ),
								'first_seen' => array( 'type' => 'string' ),
								'last_seen'  => array( 'type' => 'string' ),
							),
						),
					),
					'by_detail'           => array(
						'type'        => 'array',
						'description' => 'What was asked for within a surface, busiest first, for the surfaces that record it. The 404 surfaces carry the path an agent asked for and did not find; "MCP JSON-RPC" carries the method called — "initialize", "tools/list", "tools/call: <tool name>"; and as of 1.24.0 the Markdown surfaces carry the permalink path of the post that was served, so a crawler that swept the whole corpus and one that wanted a single article are no longer the same row. Both Markdown surfaces record the same value for the same post, so a `.md` fetch and a content-negotiated fetch of one article aggregate together rather than splitting. This is the breakdown that answers whether the MCP server is being used rather than merely discovered, which articles agents actually want in Markdown, and whether agents are guessing at URLs the site could support. Surfaces whose name is already the whole request are absent.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'surface'    => array( 'type' => 'string' ),
								'detail'     => array( 'type' => 'string' ),
								'requests'   => array( 'type' => 'integer' ),
								'agents'     => array( 'type' => 'integer' ),
								'first_seen' => array( 'type' => 'string' ),
								'last_seen'  => array( 'type' => 'string' ),
							),
						),
					),
					'by_day'              => array(
						'type'        => 'array',
						'description' => 'Most recent UTC day first.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'day'      => array( 'type' => 'string' ),
								'requests' => array( 'type' => 'integer' ),
								'agents'   => array( 'type' => 'integer' ),
							),
						),
					),
					'entries'             => array(
						'type'        => 'array',
						'description' => 'Individual entries, newest first. Omitted when summary_only is true.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'logged_at'   => array( 'type' => 'string' ),
								'agent'       => array( 'type' => 'string' ),
								'surface'     => array( 'type' => 'string' ),
								'detail'      => array(
									'type'        => 'string',
									'description' => 'What was asked for within the surface — a 404 path, an MCP method, or the permalink path of the post served on a Markdown surface. Empty string on surfaces where the surface name is the whole request.',
								),
								'ip'          => array( 'type' => 'string' ),
								'client_type' => array(
									'type'        => 'string',
									'enum'        => array( '', 'crawler', 'browser', 'http' ),
									'description' => 'What kind of software made the request. Empty on entries recorded before 1.26.0.',
								),
								'verified'    => array(
									'type'        => 'string',
									'enum'        => array( '', 'verified', 'failed', 'unverifiable', 'unclaimed', 'nodns' ),
									'description' => 'This entry\'s verification verdict. "failed" means the claimed crawler identity was forged. "unverifiable" means this release has no way to check that operator and is not an accusation. "unclaimed" means no crawler was named. "nodns" means the resolver gave no answer and the entry will be retried. An empty string means it has not been checked yet, so it is not evidence of anything.',
								),
								'verified_at' => array(
									'type'        => 'string',
									'description' => 'UTC datetime the verdict was reached, "Y-m-d H:i:s", or null when unchecked. Compare it against logged_at: reverse-DNS assignments and published ranges both change, so a verdict reached days after the request is weaker evidence than one reached in the same hour, and a "failed" verdict on an old entry is suggestive rather than proof.',
								),
							),
						),
					),
					'returned'            => array(
						'type'        => 'integer',
						'description' => 'How many entries this call returned.',
					),
					'limit'               => array( 'type' => 'integer' ),
					'offset'              => array( 'type' => 'integer' ),
					'verified'            => array(
						'type'        => 'string',
						'description' => 'The verification filter that was applied to entries, echoed back. Empty string means none was.',
					),
					'surface'             => array(
						'type'        => 'string',
						'description' => 'The surface-category filter that was applied to entries, echoed back.',
					),
				),
			),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'execute_callback'    => function ( $input = null ) {
				$input        = is_array( $input ) ? $input : array();
				$summary_only = ! empty( $input['summary_only'] );
				$limit        = isset( $input['limit'] ) ? absint( $input['limit'] ) : 50;
				$limit        = max( 1, min( 500, $limit ) );
				$offset       = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
				$verified     = isset( $input['verified'] ) ? sanitize_key( $input['verified'] ) : '';
				$client       = isset( $input['client'] ) ? sanitize_key( $input['client'] ) : '';
				$category     = isset( $input['surface'] ) ? sanitize_key( $input['surface'] ) : '';

				// Decide a few identities before reading, so a caller that keeps asking gradually
				// verifies the log rather than being told forever that everything is pending. Same
				// bounded batch the admin screen runs, and for the same reason it is here rather
				// than in record(): a DNS lookup must never sit in front of a response to a visitor.
				MMSAR_Agent_Log_Verify::run_batch();

				$summary      = MMSAR_Agent_Log::get_summary();
				$verification = MMSAR_Agent_Log::get_verification_summary();

				$result = array(
					'logging_enabled'     => MMSAR_Agent_Log::is_active(),
					'page_views_recorded' => MMSAR_Agent_Log::page_view_mode(),
					'retention_limit'     => MMSAR_Agent_Log::get_limit(),
					'throttle_seconds'    => MMSAR_Agent_Log::THROTTLE,
					'total'               => $summary['total'],
					'unique_agents'       => $summary['unique_agents'],
					'unique_ips'          => $summary['unique_ips'],
					'first_logged_at'     => $summary['first_logged_at'],
					'last_logged_at'      => $summary['last_logged_at'],
					'verification'        => array(
						'verified'        => (int) $verification['counts'][ MMSAR_Agent_Log_Verify::VERIFIED ],
						'failed'          => (int) $verification['counts'][ MMSAR_Agent_Log_Verify::FAILED ],
						'unverifiable'    => (int) $verification['counts'][ MMSAR_Agent_Log_Verify::UNVERIFIABLE ],
						'unclaimed'       => (int) $verification['counts'][ MMSAR_Agent_Log_Verify::UNCLAIMED ],
						'nodns'           => (int) $verification['counts'][ MMSAR_Agent_Log_Verify::NODNS ],
						'pending'         => (int) $verification['pending'],
						'ranges_captured' => MMSAR_Agent_Log_Verify::ranges_captured(),
						'first_checked'   => $verification['verified_first'],
						'last_checked'    => $verification['verified_last'],
					),
					'client_types'        => MMSAR_Agent_Log::get_client_type_counts(),
					'surface_categories'  => MMSAR_Agent_Log::get_category_counts(),
					'by_agent'            => $summary['by_agent'],
					'by_surface'          => $summary['by_surface'],
					'by_detail'           => $summary['by_detail'],
					'by_day'              => $summary['by_day'],
				);

				if ( $summary_only ) {
					return $result;
				}

				$entries            = MMSAR_Agent_Log::get_entries(
					$limit,
					$offset,
					array(
						'verdicts'   => '' === $verified ? array() : array( $verified ),
						'clients'    => 'all' === $client ? array_merge( MMSAR_Agent_Log::client_types(), array( 'unrecorded' ) ) : ( '' === $client ? array() : array( $client ) ),
						'categories' => '' === $category ? array() : array( $category ),
					)
				);
				$result['entries']  = $entries;
				$result['returned'] = count( $entries );
				$result['limit']    = $limit;
				$result['offset']   = $offset;
				$result['verified'] = $verified;
				$result['surface']  = $category;

				return $result;
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}
