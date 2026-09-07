<?php
/**
 * Agent request log — records which agents fetch the surfaces this plugin publishes.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent request log.
 */
class MMSAR_Agent_Log {

	/**
	 * How long the same agent, surface and IP is suppressed for, in seconds.
	 *
	 * The question this log answers is "which agents fetch what", not "how many times". Without a
	 * throttle a single crawler looping on one URL would drown out everything else.
	 */
	const THROTTLE = 300;

	/**
	 * Schema version. Bump to trigger dbDelta on the next load.
	 */
	const DB_VERSION = 4;

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'mmsar_agent_log_db_version';

	/**
	 * Option holding the retention limit. 0 means keep everything.
	 */
	const LIMIT_OPTION = 'mmsar_agent_log_limit';

	/**
	 * Option the log used before 1.16.0, when entries lived in a capped array.
	 */
	const LEGACY_OPTION = 'mmsar_agent_log';

	/**
	 * Option used to claim the one-time migration.
	 *
	 * Created with add_option(), which is an INSERT against a unique index, so exactly one caller
	 * can succeed. That
	 * gives a lock that holds across concurrent requests, which a read-then-write guard does
	 * not: two requests arriving during the same upgrade both read the legacy option before either
	 * deleted it, and both wrote its contents into the table. Every migrated entry appeared twice.
	 */
	const MIGRATED_FLAG = 'mmsar_agent_log_migrated';

	/**
	 * How often pruning runs, as one prune per N inserts.
	 *
	 * Trimming on every insert would add a COUNT and a DELETE to requests that are already doing
	 * the useful work. The log is allowed to overshoot its limit by up to this many rows between
	 * prunes, which nobody can observe and which costs one extra row of storage each.
	 */
	const PRUNE_EVERY = 50;

	/**
	 * User-agent fragments identifying a known agent or AI crawler, matched case-insensitively.
	 *
	 * Used only when logging ordinary page views. The plugin's own endpoints do not consult this
	 * list — anything fetching those is agent traffic by definition.
	 */
	const AGENTS = array(
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'Anthropic-AI',
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'GoogleOther',
		'Gemini',
		'Applebot-Extended',
		'meta-externalagent',
		'Bytespider',
		'CCBot',
		'cohere-ai',
		'DuckAssistBot',
		'Amazonbot',
		'YouBot',
		'Diffbot',
	);

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );

		// Ordinary page views are only inspected when the owner opts in, and even then the work is
		// one pass over the user-agent. Everything else is triggered from a serve point the plugin
		// already owns, so a normal HTML request costs nothing at all.
		if ( 'off' !== self::page_view_mode() ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_record_page_view' ), 20 );
		}
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mmsar_agent_log';
	}

	/**
	 * Creates or upgrades the table when the stored schema version is behind.
	 *
	 * Runs from an option read on every load, which is a cached lookup, rather than only on
	 * activation — updating a plugin's files in place does not re-fire the activation hook, so a
	 * schema added in an update would otherwise never be created on an existing install.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one space around types.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			surface varchar(100) NOT NULL DEFAULT '',
			detail varchar(190) NOT NULL DEFAULT '',
			agent varchar(120) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			verified varchar(12) NOT NULL DEFAULT '',
			verified_at datetime DEFAULT NULL,
			client_type varchar(12) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY verified (verified),
			KEY client_type (client_type)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::migrate_legacy_entries();
	}

	/**
	 * Moves entries from the pre-1.16.0 option into the table, then removes the option.
	 *
	 * @return void
	 */
	private static function migrate_legacy_entries() {
		// Claim the migration before reading anything. Whichever request creates this option owns
		// the job; any other request racing it here stops now rather than importing the same rows
		// a second time.
		if ( ! add_option( self::MIGRATED_FLAG, time(), '', false ) ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}

		global $wpdb;
		// Oldest first, so the table's ascending ids match the order the requests happened in.
		foreach ( array_reverse( $legacy ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration into this plugin's own table.
				self::table(),
				array(
					'logged_at' => isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) : current_time( 'mysql', true ),
					'surface'   => isset( $entry['surface'] ) ? (string) $entry['surface'] : '',
					'agent'     => isset( $entry['agent'] ) ? (string) $entry['agent'] : '',
					'ip'        => isset( $entry['ip'] ) ? (string) $entry['ip'] : '',
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}

		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return mmsar_feature_enabled( 'agent_log' );
	}

	/**
	 * The retention limit. 0 means keep everything.
	 *
	 * @return int
	 */
	public static function get_limit() {
		return absint( get_option( self::LIMIT_OPTION, 0 ) );
	}

	/**
	 * Total number of recorded entries.
	 *
	 * @return int
	 */
	public static function count_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; a cached count would show a stale log.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Normalizes a filter set into the three comma-joined strings the queries take.
	 *
	 * Multi-select is done with `FIND_IN_SET` against a validated, comma-joined list rather than a
	 * generated `IN (…)`. One placeholder covers any number of selected values, which keeps the SQL
	 * a fixed string in every combination — the same reason the rest of this class avoids assembling
	 * clauses. Every value is checked against a whitelist here, and none of them contains a comma.
	 *
	 * Empty means "no filter on that axis", with one deliberate exception: an empty client list
	 * excludes browser page views. This is an agent log, and once every page view is recorded a
	 * default that lists them all answers a different question. Tick Browsers to see them.
	 *
	 * @param array $filters Keys 'verdicts', 'clients', 'categories', each an array of values.
	 * @return array{verdicts: string, clients: string, categories: string}
	 */
	private static function normalize_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();

		$verdicts = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['verdicts'] ?? array() ) ),
				array_merge( MMSAR_Agent_Log_Verify::verdicts(), array( 'pending' ) )
			)
		);

		$clients = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['clients'] ?? array() ) ),
				array_merge( self::client_types(), array( 'unrecorded' ) )
			)
		);
		if ( ! $clients ) {
			$clients = array( self::CLIENT_CRAWLER, self::CLIENT_HTTP, 'unrecorded' );
		}
		// Every client type ticked is the same as no client filter, and saying so lets the query
		// skip the test entirely.
		if ( count( $clients ) === count( self::client_types() ) + 1 ) {
			$clients = array();
		}

		$categories = array_values(
			array_intersect(
				array_map( 'strval', (array) ( $filters['categories'] ?? array() ) ),
				self::categories()
			)
		);
		if ( count( $categories ) === count( self::categories() ) ) {
			$categories = array();
		}

		return array(
			'verdicts'   => implode( ',', $verdicts ),
			'clients'    => implode( ',', $clients ),
			'categories' => implode( ',', $categories ),
		);
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param int   $per_page Rows per page.
	 * @param int   $offset   Rows to skip.
	 * @param array $filters  Filter set, as accepted by normalize_filters().
	 * @return array[] Entries as associative arrays.
	 */
	public static function get_entries( $per_page = 50, $offset = 0, $filters = array() ) {
		global $wpdb;
		$f         = self::normalize_filters( $filters );
		$like_html = $wpdb->esc_like( 'HTML page view' ) . '%';
		$like_md   = $wpdb->esc_like( 'Markdown' ) . '%';
		$like_404  = $wpdb->esc_like( '404' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, surface, detail, agent, ip, verified, verified_at, client_type
				FROM %i
				WHERE ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table(),
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$like_html,
				$like_md,
				$like_404,
				$f['categories'],
				absint( $per_page ),
				absint( $offset )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows matching a filter set.
	 *
	 * @param array $filters Filter set, as accepted by normalize_filters().
	 * @return int
	 */
	public static function count_filtered( $filters = array() ) {
		global $wpdb;
		$f         = self::normalize_filters( $filters );
		$like_html = $wpdb->esc_like( 'HTML page view' ) . '%';
		$like_md   = $wpdb->esc_like( 'Markdown' ) . '%';
		$like_404  = $wpdb->esc_like( '404' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )",
				self::table(),
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$like_html,
				$like_md,
				$like_404,
				$f['categories']
			)
		);
	}

	/**
	 * Request counts by client type across the whole log.
	 *
	 * @return array<string, int>
	 */
	public static function get_client_type_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT client_type, COUNT(*) AS requests FROM %i GROUP BY client_type', self::table() ),
			ARRAY_A
		);

		$counts               = array_fill_keys( self::client_types(), 0 );
		$counts['unrecorded'] = 0;
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['client_type'] ) && '' !== $row['client_type'] ? (string) $row['client_type'] : 'unrecorded';
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] += (int) $row['requests'];
			}
		}
		return $counts;
	}

	/**
	 * Request counts by surface category, across every client.
	 *
	 * @return array<string, int>
	 */
	public static function get_category_counts() {
		$counts = array();
		foreach ( self::categories() as $category ) {
			$counts[ $category ] = self::count_filtered(
				array(
					'clients'    => array_merge( self::client_types(), array( 'unrecorded' ) ),
					'categories' => array( $category ),
				)
			);
		}
		return $counts;
	}

	/**
	 * Whether the log table has been created yet.
	 *
	 * The table is only created once the agent log is switched on, so anything that queries it
	 * outside a serve path has to cope with it being absent. Checked with SHOW TABLES rather than
	 * by catching an error, so a site with the log off never emits a database error at all.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for this plugin's own table; nothing to cache usefully.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Distinct (ip, agent) pairs that have not been verified yet, newest first.
	 *
	 * Grouped rather than listed per row because the work is one DNS resolution per address: this
	 * site's own log is 634 addresses across 1,584 rows, and a single crawler sweep can be 41 rows
	 * from 41 different addresses. Paired with the agent because the verdict is about a *claim* —
	 * the same address arriving as ClaudeBot and as GPTBot is two claims and can be one forgery
	 * and one genuine crawl.
	 *
	 * Newest first so a freshly arrived crawler is judged while its rDNS assignment is still the
	 * one it used, which is the verdict worth the most.
	 *
	 * @param int $limit Maximum pairs to return.
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_unverified_pairs( $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would re-resolve rows already decided.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ip, agent, MAX(id) AS newest FROM %i
				WHERE verified = %s
				   OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) )
				GROUP BY ip, agent ORDER BY newest DESC LIMIT %d',
				self::table(),
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff(),
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The UTC datetime before which a `nodns` row is due another attempt.
	 *
	 * @return string `Y-m-d H:i:s`.
	 */
	private static function nodns_retry_cutoff() {
		return gmdate( 'Y-m-d H:i:s', time() - MMSAR_Agent_Log_Verify::RETRY_NODNS_AFTER );
	}

	/**
	 * Writes a verdict to every unverified row sharing an address and claimed agent.
	 *
	 * Scoped to rows still at the pending value, so a verdict reached now can never overwrite one
	 * reached earlier — an older row keeps the `verified_at` it was actually judged at, which is
	 * the whole point of storing that column.
	 *
	 * @param string $ip      Client IP.
	 * @param string $agent   Claimed agent.
	 * @param string $verdict Verdict to store.
	 * @return int Rows updated.
	 */
	public static function apply_verdict( $ip, $agent, $verdict ) {
		global $wpdb;

		// Writable states are exactly two: never judged, and judged `nodns` long enough ago to be
		// due another attempt. `verified`, `failed` and `unverifiable` are untouchable here, which
		// is what stops a later pass quietly rewriting a conclusion reached against better data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET verified = %s, verified_at = %s
				WHERE ip = %s AND agent = %s
				  AND ( verified = %s
				        OR ( verified = %s AND ( verified_at IS NULL OR verified_at < %s ) ) )',
				self::table(),
				mb_substr( (string) $verdict, 0, 12 ),
				current_time( 'mysql', true ),
				(string) $ip,
				(string) $agent,
				MMSAR_Agent_Log_Verify::PENDING,
				MMSAR_Agent_Log_Verify::NODNS,
				self::nodns_retry_cutoff()
			)
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * The verdicts a re-check reconsiders: the ones that mean "could not decide".
	 *
	 * `verified` and `failed` are deliberately absent. Both are conclusions, and re-running them
	 * risks overwriting a sound verdict reached against better data than today's — a `verified`
	 * from a live range file should not become `failed` because a bundled snapshot has since aged.
	 * The undecided two are the ones that turn into information when the suffix map or the range
	 * data improves, which is the only reason to re-check at all.
	 *
	 * @return string[]
	 */
	public static function recheckable_verdicts() {
		return array( MMSAR_Agent_Log_Verify::NODNS, MMSAR_Agent_Log_Verify::UNVERIFIABLE );
	}

	/**
	 * Distinct (ip, agent) pairs currently holding an undecided verdict.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_undecided_pairs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified IN ( %s, %s )',
				self::table(),
				MMSAR_Agent_Log_Verify::NODNS,
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Distinct (ip, agent) pairs whose verdict could actually come out differently today.
	 *
	 * Undecided is not the same as re-checkable, and conflating them offers a button that provably
	 * cannot change anything. Two cases qualify:
	 *
	 * - **`nodns`**, always. The resolver failing is a transient condition by definition, and this
	 *   is the one verdict the design promises to retry.
	 * - **`unverifiable`, but only where the claimed agent now has a method.** That verdict records
	 *   that this plugin knew no way to check the operator, so the only thing that can change it is
	 *   the plugin learning one. Where it still has not — `meta-externalagent`, `YouBot`,
	 *   `Bytespider`, `CCBot` at the time of writing — re-running produces `unverifiable` again,
	 *   every time, for as long as nobody publishes a method.
	 *
	 * The second test cannot be done in SQL: whether an operator is covered is a fact about the
	 * suffix map and the bundled range data, both of which live in PHP and are filterable. So the
	 * agents are pulled distinct and filtered here, which is a handful of rows rather than a scan.
	 *
	 * @return array[] Rows of `ip` and `agent`.
	 */
	public static function get_recheckable_pairs() {
		global $wpdb;

		// `unverifiable` only. `nodns` used to be in here, which is why a button reading
		// "Re-check 1" sat on screen permanently for an address with no reverse record: the button
		// was the only thing that ever retried that verdict. It is retried by the ordinary pass now
		// (see get_unverified_pairs), so the button is left with the one job a person is actually
		// needed for — reopening rows that became answerable because this plugin learned a new
		// operator, which is not something the plugin can detect about itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT ip, agent FROM %i WHERE verified = %s',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);

		$pairs = array();
		foreach ( (array) $rows as $pair ) {
			$agent = isset( $pair['agent'] ) ? (string) $pair['agent'] : '';
			if ( MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				$pairs[] = $pair;
			}
		}
		return $pairs;
	}

	/**
	 * Agents holding an `unverifiable` verdict that this release still cannot check.
	 *
	 * Surfaced on screen so the absence of a re-check button is explained rather than merely
	 * observed: these entries are not stuck, they are answered, and the answer is "nobody publishes
	 * a way to confirm this crawler".
	 *
	 * @return array<string, int> Agent name => row count, busiest first.
	 */
	public static function get_uncheckable_agents() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, COUNT(*) AS requests FROM %i WHERE verified = %s GROUP BY agent ORDER BY requests DESC',
				self::table(),
				MMSAR_Agent_Log_Verify::UNVERIFIABLE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agent = isset( $row['agent'] ) ? (string) $row['agent'] : '';
			if ( '' !== $agent && ! MMSAR_Agent_Log_Verify::has_method( $agent ) ) {
				$out[ $agent ] = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			}
		}
		return $out;
	}

	/**
	 * How many rows a re-check would actually reconsider.
	 *
	 * Counted per agent with a fixed set of placeholders rather than by building an `IN` list.
	 * A generated placeholder string is safe here — it is derived from a count, never from data —
	 * but it cannot be read as safe without tracing where the count comes from, and the WordPress.org
	 * review re-scans without honouring inline suppressions. The distinct re-checkable agents are a
	 * handful, so a short loop of fully static queries costs nothing and needs no explaining.
	 *
	 * @return int
	 */
	public static function count_recheckable() {
		global $wpdb;

		$total = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE verified = %s AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					$agent
				)
			);
		}
		return $total;
	}

	/**
	 * Rows still awaiting an identity check, grouped by the crawler they claimed.
	 *
	 * The verification panel on the log screen reports one total, which answers "is there a
	 * backlog" but not "a backlog of what". On a dashboard widget the second question is the more
	 * useful one: fifty pending rows all claiming one crawler is a different situation from fifty
	 * spread across twenty, and it is the difference between pressing the button and going to look.
	 *
	 * Counts rows, not addresses, so the numbers here add up to the pending total shown elsewhere
	 * rather than to the number of addresses a pass would resolve.
	 *
	 * @param int $limit How many of the most frequent agents to return.
	 * @return array<string, int> Agent name => pending rows, largest first.
	 */
	public static function pending_by_agent( $limit = 5 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached count would be stale.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, COUNT(*) AS requests FROM %i WHERE verified = %s GROUP BY agent ORDER BY requests DESC LIMIT %d',
				self::table(),
				MMSAR_Agent_Log_Verify::PENDING,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$agent = ( isset( $row['agent'] ) && '' !== $row['agent'] )
				? (string) $row['agent']
				: __( 'Unnamed', 'make-my-site-agent-ready' );

			$out[ $agent ] = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
		}
		return $out;
	}

	/**
	 * The distinct agents a re-check could answer differently.
	 *
	 * @return string[]
	 */
	private static function recheckable_agents() {
		$agents = array();
		foreach ( self::get_recheckable_pairs() as $pair ) {
			$agents[ (string) $pair['agent'] ] = true;
		}
		return array_keys( $agents );
	}

	/**
	 * Returns every undecided row to the pending state so a later pass judges it again.
	 *
	 * Scoped to the undecided verdicts, so a re-check can never disturb a `verified` or a `failed`.
	 * `verified_at` is cleared along with the verdict rather than kept: the row is about to be
	 * judged again, and leaving the old timestamp would date the new verdict to when the *previous*
	 * one was reached, which is the one thing that column exists to prevent.
	 *
	 * @return int Rows reset.
	 */
	public static function reset_undecided() {
		global $wpdb;

		$reset = 0;
		foreach ( self::recheckable_agents() as $agent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Annotating this plugin's own table.
			$rows   = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET verified = %s, verified_at = NULL WHERE verified = %s AND agent = %s',
					self::table(),
					MMSAR_Agent_Log_Verify::PENDING,
					MMSAR_Agent_Log_Verify::UNVERIFIABLE,
					$agent
				)
			);
			$reset += is_numeric( $rows ) ? (int) $rows : 0;
		}
		return $reset;
	}

	/**
	 * Counts per verdict across the whole log, plus how many rows are still undecided.
	 *
	 * `pending` is the number that stops the rest being misread. Verdict counts over a partially
	 * verified log describe the part that has been checked and nothing else, and a reader with no
	 * pending count has no way to tell a quiet result from an unfinished one.
	 *
	 * @return array{counts: array<string, int>, pending: int, verified_first: string, verified_last: string}
	 */
	public static function get_verification_summary() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT verified, COUNT(*) AS requests FROM %i GROUP BY verified', self::table() ),
			ARRAY_A
		);

		$counts  = array_fill_keys( MMSAR_Agent_Log_Verify::verdicts(), 0 );
		$pending = 0;
		foreach ( (array) $rows as $row ) {
			$verdict = isset( $row['verified'] ) ? (string) $row['verified'] : '';
			$count   = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			if ( MMSAR_Agent_Log_Verify::PENDING === $verdict ) {
				$pending += $count;
				continue;
			}
			if ( isset( $counts[ $verdict ] ) ) {
				$counts[ $verdict ] += $count;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$span = $wpdb->get_row(
			$wpdb->prepare( 'SELECT MIN(verified_at) AS first_at, MAX(verified_at) AS last_at FROM %i WHERE verified_at IS NOT NULL', self::table() ),
			ARRAY_A
		);

		return array(
			'counts'         => $counts,
			'pending'        => $pending,
			'verified_first' => isset( $span['first_at'] ) ? (string) $span['first_at'] : '',
			'verified_last'  => isset( $span['last_at'] ) ? (string) $span['last_at'] : '',
		);
	}

	/**
	 * The path of the current request, decoded and reduced to something safe to store.
	 *
	 * Shared rather than duplicated: a 404 path and a permalink path both end up in a column an
	 * administrator reads on screen and exports to CSV, and both want the same treatment. Lived in
	 * MMSAR_Not_Found until 1.24.0, which is where its reasoning is written up.
	 *
	 * The query string is dropped. It is rarely the interesting half of a URL, and leaving it out
	 * keeps arbitrary caller-supplied text — tracking parameters, injection attempts, scanner junk
	 * — out of that column.
	 *
	 * @param string $url Optional URL or path to reduce. Defaults to this request's own URI.
	 * @return string Leading-slash path, or '/' when there is nothing to report.
	 */
	public static function request_path( $url = '' ) {
		if ( '' === $url ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the request path for a log annotation, not a state change.
			$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		}
		if ( '' === $url ) {
			return '/';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '/';
		}

		// Percent-decoded so the stored value is the path as the caller meant it, then stripped of
		// control characters, which is the actual hazard: this string is rendered on an admin screen
		// and written to a CSV export, and a decoded path can carry NUL, newlines or terminal escapes.
		//
		// Only control characters. Until 1.24.0 this stripped everything outside printable ASCII,
		// which was fine while the only caller was a 404 path but is wrong now that permalinks come
		// through here: on a site with accented or non-Latin slugs it would reduce /café/ and /cafè/
		// to the same value, and two different posts would share one by_detail row. Letters are not
		// the danger — esc_html() handles the screen and csv_cell() handles the spreadsheet.
		$path  = rawurldecode( $path );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		$path  = null === $clean ? '' : $clean;

		// Percent-decoding can produce a byte sequence that is not valid UTF-8, which would be
		// rejected on the way into a utf8mb4 column and lost entirely. Fall back to ASCII-only for
		// those rather than storing nothing.
		if ( '' !== $path && ! mb_check_encoding( $path, 'UTF-8' ) ) {
			$clean = preg_replace( '/[^\x20-\x7E]/', '', $path );
			$path  = null === $clean ? '' : $clean;
		}

		return '' === $path ? '/' : '/' . ltrim( $path, '/' );
	}

	/**
	 * A batch of entries older than a given id, newest first.
	 *
	 * Used for walking the whole log — an export, or anything else that reads every row. Paging by
	 * id rather than by OFFSET matters here because the log is appended to while the walk is in
	 * progress: with OFFSET, every row inserted mid-walk shifts the window and the reader sees a
	 * row twice or skips one. An id cursor addresses rows rather than positions, so newly appended
	 * rows are simply not part of the walk, and the query stays fast at any depth.
	 *
	 * @param int   $before_id Return rows with a lower id than this. 0 starts from the newest row.
	 * @param int   $limit     Maximum rows to return.
	 * @param array $filters   Filter set, as accepted by normalize_filters(), so an export can be
	 *                         restricted to what the screen is currently showing.
	 * @return array[] Entries as associative arrays, including id.
	 */
	public static function get_entries_before( $before_id = 0, $limit = 500, $filters = array() ) {
		global $wpdb;
		$before_id = absint( $before_id );
		$limit     = absint( $limit );
		$f         = self::normalize_filters( $filters );
		$like_html = $wpdb->esc_like( 'HTML page view' ) . '%';
		$like_md   = $wpdb->esc_like( 'Markdown' ) . '%';
		$like_404  = $wpdb->esc_like( '404' ) . '%';

		// A cursor of 0 means "start from the newest", expressed as an id above anything real so the
		// same fixed statement serves the first batch and every later one.
		$cursor = $before_id > 0 ? $before_id : PHP_INT_MAX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, logged_at, surface, detail, agent, ip, verified, verified_at, client_type
				FROM %i
				WHERE id < %d
				  AND ( %s = '' OR FIND_IN_SET( IF( verified = '', 'pending', verified ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET( IF( client_type = '', 'unrecorded', client_type ), %s ) > 0 )
				  AND ( %s = '' OR FIND_IN_SET(
				        CASE WHEN surface LIKE %s THEN 'html'
				             WHEN surface LIKE %s THEN 'markdown'
				             WHEN surface LIKE %s THEN 'notfound'
				             ELSE 'docs' END, %s ) > 0 )
				ORDER BY id DESC LIMIT %d",
				self::table(),
				$cursor,
				$f['verdicts'],
				$f['verdicts'],
				$f['clients'],
				$f['clients'],
				$f['categories'],
				$like_html,
				$like_md,
				$like_404,
				$f['categories'],
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate counts over the whole log.
	 *
	 * The log answers "which agents fetch what", and answering it from a page of raw rows means
	 * reading every page and tallying by hand. These are the tallies, computed by the database in
	 * one pass each, so a caller that only wants the shape of the traffic never has to pull the
	 * rows at all.
	 *
	 * Datetimes are UTC, as stored.
	 *
	 * @param int $top  Maximum rows in the by-agent, by-surface and by-detail breakdowns.
	 * @param int $days Maximum rows in the by-day breakdown, most recent first.
	 * @return array Aggregates.
	 */
	public static function get_summary( $top = 25, $days = 60 ) {
		global $wpdb;
		$table = self::table();
		$top   = max( 1, absint( $top ) );
		$days  = max( 1, absint( $days ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would show a stale log.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COUNT(DISTINCT agent) AS unique_agents, COUNT(DISTINCT ip) AS unique_ips, MIN(logged_at) AS first_logged_at, MAX(logged_at) AS last_logged_at FROM %i',
				$table
			),
			ARRAY_A
		);

		// The verdict columns are the point of this breakdown as of 1.24.0. A `requests` figure on
		// its own is what the last analysis of this log had to work with, and it was wrong: GPTBot
		// looked like the best customer here at 74% of its requests hitting agent surfaces, and
		// most of those requests were a readiness scanner wearing its name. `verified` and `failed`
		// side by side on the same row is what makes that visible without cross-tabbing by hand.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_agent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, COUNT(*) AS requests, COUNT(DISTINCT surface) AS surfaces, COUNT(DISTINCT ip) AS unique_ips,
					SUM(CASE WHEN verified = 'verified' THEN 1 ELSE 0 END) AS verified,
					SUM(CASE WHEN verified = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN verified = 'unverifiable' THEN 1 ELSE 0 END) AS unverifiable,
					SUM(CASE WHEN verified = 'unclaimed' THEN 1 ELSE 0 END) AS unclaimed,
					SUM(CASE WHEN verified = 'nodns' THEN 1 ELSE 0 END) AS nodns,
					SUM(CASE WHEN verified = '' THEN 1 ELSE 0 END) AS pending,
					MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen
				FROM %i GROUP BY agent ORDER BY requests DESC, agent ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_surface = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT surface, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i GROUP BY surface ORDER BY requests DESC, surface ASC LIMIT %d',
				$table,
				$top
			),
			ARRAY_A
		);

		// Only rows that carry a detail, because on every other surface the surface name already is
		// the whole request and a blank row here would say nothing. Grouped by the pair rather than
		// by detail alone: "/api/v2/products" means one thing under a 404 and another under an MCP
		// call, and merging them would invent a total that describes neither.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_detail = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT surface, detail, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents, MIN(logged_at) AS first_seen, MAX(logged_at) AS last_seen FROM %i WHERE detail <> '' GROUP BY surface, detail ORDER BY requests DESC, detail ASC LIMIT %d",
				$table,
				$top
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(logged_at) AS day, COUNT(*) AS requests, COUNT(DISTINCT agent) AS agents FROM %i GROUP BY day ORDER BY day DESC LIMIT %d',
				$table,
				$days
			),
			ARRAY_A
		);

		return array(
			'total'           => isset( $totals['total'] ) ? (int) $totals['total'] : 0,
			'unique_agents'   => isset( $totals['unique_agents'] ) ? (int) $totals['unique_agents'] : 0,
			'unique_ips'      => isset( $totals['unique_ips'] ) ? (int) $totals['unique_ips'] : 0,
			'first_logged_at' => isset( $totals['first_logged_at'] ) ? (string) $totals['first_logged_at'] : '',
			'last_logged_at'  => isset( $totals['last_logged_at'] ) ? (string) $totals['last_logged_at'] : '',
			'by_agent'        => self::int_columns( $by_agent, array( 'requests', 'surfaces', 'unique_ips', 'verified', 'failed', 'unverifiable', 'unclaimed', 'nodns', 'pending' ) ),
			'by_surface'      => self::int_columns( $by_surface, array( 'requests', 'agents' ) ),
			'by_detail'       => self::int_columns( $by_detail, array( 'requests', 'agents' ) ),
			'by_day'          => self::int_columns( $by_day, array( 'requests', 'agents' ) ),
		);
	}

	/**
	 * Casts the named columns of a result set to integers.
	 *
	 * MySQL hands back counts as numeric strings. Left alone they serialize into JSON as "12"
	 * rather than 12, which is the wrong type for an output schema that says integer.
	 *
	 * @param array[]  $rows    Result rows.
	 * @param string[] $columns Column names to cast.
	 * @return array[] Rows with those columns cast.
	 */
	private static function int_columns( $rows, $columns ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			foreach ( $columns as $column ) {
				if ( isset( $row[ $column ] ) ) {
					$rows[ $index ][ $column ] = (int) $row[ $column ];
				}
			}
		}
		return $rows;
	}

	/**
	 * Deletes every entry.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying this plugin's own table on explicit request.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Drops rows beyond the retention limit, oldest first.
	 *
	 * @return void
	 */
	public static function prune() {
		$limit = self::get_limit();
		if ( $limit < 1 ) {
			return;
		}

		global $wpdb;
		// %i is the identifier placeholder, so the table name goes through prepare() like any other
		// value rather than being interpolated into the query string.
		$table = self::table();

		// The id of the newest row already outside the limit. Everything at or below it goes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This plugin's own table; a cached read would prune against a stale count.
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $limit ) );
		if ( ! $cutoff ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, (int) $cutoff ) );
	}

	/**
	 * Surface categories, for answering "did anything read the agent-facing documents".
	 *
	 * Derived from the surface name rather than stored, because the plugin generates every surface
	 * string and the three non-document families each share a fixed prefix. That keeps the SQL fully
	 * static: three `LIKE` prefixes, and documents are what is left over. A surface added later is
	 * therefore a document by default, which is the right way round.
	 */
	const CAT_DOCS     = 'docs';
	const CAT_MARKDOWN = 'markdown';
	const CAT_HTML     = 'html';
	const CAT_NOTFOUND = 'notfound';

	/**
	 * Every surface category, for schemas and filters.
	 *
	 * @return string[]
	 */
	public static function categories() {
		return array( self::CAT_DOCS, self::CAT_MARKDOWN, self::CAT_HTML, self::CAT_NOTFOUND );
	}

	/**
	 * Human-readable label for a surface category.
	 *
	 * @param string $category Category value.
	 * @return string
	 */
	public static function category_label( $category ) {
		switch ( $category ) {
			case self::CAT_DOCS:
				return __( 'Agent documents', 'make-my-site-agent-ready' );
			case self::CAT_MARKDOWN:
				return __( 'Markdown', 'make-my-site-agent-ready' );
			case self::CAT_HTML:
				return __( 'HTML pages', 'make-my-site-agent-ready' );
			case self::CAT_NOTFOUND:
				return __( 'Not found', 'make-my-site-agent-ready' );
			default:
				return __( 'All surfaces', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Client types. What kind of software made the request, as distinct from who it claimed to be.
	 */
	const CLIENT_CRAWLER = 'crawler';
	const CLIENT_BROWSER = 'browser';
	const CLIENT_HTTP    = 'http';

	/**
	 * What kind of client made this request, from the shape of the request rather than its name.
	 *
	 * **This separates browser navigations from HTTP clients. It does not separate people from
	 * machines,** and the difference matters enough to state at the top. An agent driving a real
	 * Chrome through Playwright sends everything below, because it *is* Chrome, and is
	 * indistinguishable here from a person reading the site. What this does catch is the far more
	 * common case: an agent using a fetch tool, a script, a scraper or a CLI.
	 *
	 * The signals, strongest first:
	 *
	 * - **A document navigation.** `Sec-Fetch-Mode: navigate`, or `Sec-Fetch-Dest: document`, is
	 *   what a browser sends when it loads a page — and it is a shape the Fetch API cannot ask for,
	 *   since `fetch()` rejects `mode: 'navigate'` outright. No fetch tool built on it can produce
	 *   one. Confirmed against this site: a browser navigation arrives with `Sec-Fetch-Mode:
	 *   navigate` and `Sec-Fetch-Dest: document`; a bare curl arrives with neither.
	 * - **`Sec-CH-UA`**. User-agent client hints, Chromium only, so its absence proves nothing on
	 *   Safari or Firefox and its presence is good evidence. No HTTP client library sends it.
	 * - **A self-declared bot name**, which is a claim rather than a signal, but a claim worth
	 *   taking at face value here: something calling itself `SomethingBot` or advertising
	 *   `+https://…/bot` is not a browser, whatever else it is. Tested only after the browser
	 *   shapes above, so a phone whose model name happens to end in "bot" is not caught by it.
	 * - **`Accept-Language` with an HTML-shaped `Accept`**. Weak on its own, and the tiebreak that
	 *   covers a browser too old for fetch metadata.
	 *
	 * **The mere presence of a `Sec-Fetch-*` header is not the test, and treating it as one was a
	 * bug from 1.26.0 to 1.30.1.** Node's built-in fetch (undici) sends `Sec-Fetch-Mode: cors`, so
	 * every agent built on it recorded as a browser — and browser rows are excluded from the
	 * default view, which hid exactly the traffic this log exists to show. It was found in the live
	 * log: ten `.md` fetches within seconds, from `OraBot/1.0 (+https://ora.ai/bot)` and from a bare
	 * `node` user-agent at one AWS address, all filed as `browser`. Reproduced against Node 24,
	 * which sends a wildcard `Accept`, `accept-language: *` and `sec-fetch-mode: cors`, with no
	 * `Sec-Fetch-Dest`, no `Sec-Fetch-Site` and no `Sec-CH-UA`. So `Sec-Fetch-Site` and
	 * `Sec-Fetch-User` are no longer consulted at all: neither distinguishes the two populations,
	 * and each was doing nothing but widening the false positive.
	 *
	 * None of this is proof against a client that simply chooses to send these headers. They are
	 * *forbidden headers in a browser*, which stops page JavaScript forging them; it constrains
	 * nothing outside one. This reads the shape of a request, and a shape is a claim like any other.
	 *
	 * A declared crawler name short-circuits all of it: those are already described by the `agent`
	 * column and its verification verdict, and calling ClaudeBot an "http client" would bury the
	 * more useful fact.
	 *
	 * @return string One of the CLIENT_* constants.
	 */
	private static function detect_client_type() {
		$ua = self::user_agent();
		if ( self::is_known_agent( $ua ) ) {
			return self::CLIENT_CRAWLER;
		}

		$mode = isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ) ) : '';
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) ) : '';
		if ( 'navigate' === $mode || 'document' === $dest || ! empty( $_SERVER['HTTP_SEC_CH_UA'] ) ) {
			return self::CLIENT_BROWSER;
		}

		// Not browser-shaped. A name that announces itself as a bot is the next most useful thing
		// the request carries, and it belongs with the crawlers rather than with anonymous scripts.
		if ( self::is_self_declared_bot( $ua ) ) {
			return self::CLIENT_CRAWLER;
		}

		// No navigation shape at all. Before calling it a script, allow for a browser old enough to
		// predate fetch metadata: it would still send a language and an HTML-shaped Accept listing
		// several types with quality values, which a fetch tool almost never does.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) && false !== stripos( $accept, 'text/html' ) && false !== strpos( $accept, ';q=' ) ) {
			return self::CLIENT_BROWSER;
		}

		return self::CLIENT_HTTP;
	}

	/**
	 * Human-readable label for a client type.
	 *
	 * @param string $type Stored client type.
	 * @return string
	 */
	public static function client_type_label( $type ) {
		switch ( $type ) {
			case self::CLIENT_CRAWLER:
				return __( 'Declared crawler', 'make-my-site-agent-ready' );
			case self::CLIENT_BROWSER:
				return __( 'Browser', 'make-my-site-agent-ready' );
			case self::CLIENT_HTTP:
				return __( 'Script or fetch tool', 'make-my-site-agent-ready' );
			default:
				return __( 'Not recorded', 'make-my-site-agent-ready' );
		}
	}

	/**
	 * Every client type value, for schemas and filters.
	 *
	 * @return string[]
	 */
	public static function client_types() {
		return array( self::CLIENT_CRAWLER, self::CLIENT_BROWSER, self::CLIENT_HTTP );
	}

	/**
	 * How much ordinary page-view traffic is recorded.
	 *
	 * Three states in one option, kept backwards compatible: the value was a checkbox until 1.25.0,
	 * so the stored '1' still means "recognized agents only" and an empty value still means off.
	 *
	 * @return string 'off', 'agents' or 'all'.
	 */
	public static function page_view_mode() {
		$stored = (string) get_option( 'mmsar_agent_log_pages', '' );
		if ( 'all' === $stored ) {
			return 'all';
		}
		return '1' === $stored ? 'agents' : 'off';
	}

	/**
	 * Reduces an address to its network, for storing against traffic that is probably a person.
	 *
	 * IPv4 keeps three octets, IPv6 the first four groups. That is enough to tell one visitor's
	 * session apart from another's in the log and to recognise a cloud range, and not enough to be
	 * an identifier for a household.
	 *
	 * **Applied only to page views from user-agents this plugin does not recognise as crawlers.**
	 * A request for an agent-facing endpoint keeps its full address whoever made it: those are
	 * deliberate requests for machine-readable files rather than someone reading the site, and the
	 * exact address is what made the scanner pool identifiable in the first place. Recognized
	 * crawlers keep theirs too, because verification needs it.
	 *
	 * @param string $ip Client IP.
	 * @return string Network-level address, or '' when the input will not parse.
	 */
	public static function anonymize_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$groups = explode( ':', (string) inet_ntop( inet_pton( $ip ) ) );
			return implode( ':', array_slice( $groups, 0, 4 ) ) . '::';
		}
		$octets = explode( '.', $ip );
		if ( 4 !== count( $octets ) ) {
			return '';
		}
		$octets[3] = '0';
		return implode( '.', $octets );
	}

	/**
	 * Whether a user-agent announces itself as automated software, whoever it turns out to be.
	 *
	 * The two long-standing conventions, and nothing beyond them: a `bot`, `crawler`, `spider` or
	 * `scraper` token in the name, and the `+https://example.com/bot` self-identification URL that
	 * `robots.txt` culture asks operators to put in the comment. `OraBot/1.0 (+https://ora.ai/bot)`
	 * matches on both.
	 *
	 * **A claim, not a signal**, which is the whole reason it is tested last among the positive
	 * checks in detect_client_type(): anything can say it is a bot, and anything can say it is not.
	 * It earns its place because a client that volunteers "I am a crawler" is telling the truth
	 * about the only thing this column records — what kind of software made the request — and
	 * because unlike the recognised list it needs no prior knowledge of the operator. Nothing here
	 * touches the `agent` column or the verification verdict; an unrecognised name still verifies
	 * as `unclaimed`, because there is still no claim this plugin knows how to check.
	 *
	 * The `bot` token deliberately matches at the end of a word (`SomethingBot`) rather than only
	 * as a whole one, which is how these names are actually written — at the cost of matching a
	 * device called CUBOT. That is tolerable **only** because detect_client_type() runs the browser
	 * shapes first, and a phone browser sends them.
	 *
	 * @param string $ua User-agent string.
	 * @return bool
	 */
	private static function is_self_declared_bot( $ua ) {
		return 1 === preg_match( '~(?:bot|crawler|spider|scraper)\b|\+https?://~i', (string) $ua );
	}

	/**
	 * Whether a user-agent names a crawler this plugin recognises.
	 *
	 * @param string $ua User-agent string.
	 * @return bool
	 */
	private static function is_known_agent( $ua ) {
		foreach ( self::AGENTS as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Records one agent request.
	 *
	 * Called from the plugin's serve points, which is why there is no user-agent test: a request
	 * for llms.txt or a .md URL is agent traffic whatever it calls itself, and filtering on
	 * user-agent would hide exactly the clients worth knowing about.
	 *
	 * @param string $surface             Human-readable name of what was served, e.g. 'llms.txt'.
	 * @param string $detail              What exactly was asked for within that surface — the
	 *                                    requested path on a 404, the method on an MCP call.
	 *                                    Empty for surfaces where the name is the whole answer.
	 * @param bool   $throttle_on_detail  Whether two requests differing only in $detail are two
	 *                                    entries rather than one. See below.
	 * @param bool   $anonymize           Store the caller's network rather than its full address.
	 *                                    Set for page views from user-agents this plugin does not
	 *                                    recognise as crawlers, which are mostly people. Never
	 *                                    affects the throttle, which always keys on the real
	 *                                    address; see anonymize_ip().
	 * @param string $throttle_detail     Value to use in the throttle key in place of $detail. Pass
	 *                                    a bounded, site-derived value when $detail is caller input:
	 *                                    the stored value stays faithful while the key stays safe.
	 *                                    Null uses $detail itself.
	 * @return void
	 */
	public static function record( $surface, $detail = '', $throttle_on_detail = false, $anonymize = false, $throttle_detail = null ) {
		if ( ! self::is_active() ) {
			return;
		}

		$agent  = self::agent_label();
		$ip     = self::client_ip();
		$detail = (string) $detail;

		// The throttle always keys on the real address, even when a reduced one is stored: it lives
		// in a transient for five minutes and never reaches the table, and keying it on the network
		// instead would collapse everyone behind one ISP range into a single entry.
		$stored_ip = $anonymize ? self::anonymize_ip( $ip ) : $ip;

		// Throttle before touching the database. Only reached by requests already known to be
		// agent-facing, so this never runs on an ordinary page view.
		//
		// Whether $detail belongs in this key is the whole difference between the two callers, and
		// it is a judgement about who supplies the value. An MCP method name comes from a closed
		// set behind a rate limiter, so keying on it is safe and necessary: initialize, tools/list
		// and tools/call inside one session are three facts, and collapsing them to one would lose
		// the only thing anybody wants to know about that endpoint. A 404 path is supplied by the
		// caller and unbounded, so keying on it would let anything walking a URL list write a row
		// per request. There the row was going to be written anyway and the path is an annotation
		// on it, which samples the pattern over days without handing a fuzzer a write primitive.
		// What is stored and what is throttled on are separate questions, and conflating them is why
		// this used to refuse to store a raw URL at all. The throttle needs a *bounded* value or a
		// caller can mint unlimited distinct keys and write a row per request; the stored value has
		// no such constraint, because it is only ever read. A page view therefore stores the URL as
		// requested and throttles on the page it resolved to.
		$throttle_on = $throttle_on_detail ? ( null === $throttle_detail ? $detail : (string) $throttle_detail ) : '';
		$key         = 'mmsar_al_' . md5( $agent . '|' . $surface . '|' . $throttle_on . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::THROTTLE );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Appending to this plugin's own table.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'logged_at'   => current_time( 'mysql', true ),
				'surface'     => mb_substr( $surface, 0, 100 ),
				'detail'      => self::fit_detail( $detail ),
				'agent'       => mb_substr( $agent, 0, 120 ),
				'ip'          => $stored_ip,
				'client_type' => self::detect_client_type(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Prune every so often rather than on every insert: an append is the cost this request
		// should pay, and a log a few rows over its limit between prunes is not observable.
		if ( $inserted && 0 === ( (int) $wpdb->insert_id % self::PRUNE_EVERY ) ) {
			self::prune();
		}

		self::mirror_to_activity_log( '' === $detail ? $surface : $surface . ' — ' . $detail, $agent, $ip );
	}

	/**
	 * Fits a detail value into the column without letting two different values become one.
	 *
	 * The column is varchar(190) and the values written to it are paths, which on a site with deep
	 * nesting or long slugs can exceed that. A plain truncation would be worse than lossy: two
	 * distinct posts sharing a 190-character prefix would collapse into a single `by_detail` row
	 * and report a total that belongs to neither, which is the same class of error the aggregates
	 * exist to avoid. An over-long value therefore keeps its readable head and carries a short
	 * digest of the whole original, so it stays legible and stays distinct.
	 *
	 * @param string $detail Raw detail value.
	 * @return string Value that fits the column.
	 */
	private static function fit_detail( $detail ) {
		$detail = (string) $detail;
		if ( mb_strlen( $detail ) <= 190 ) {
			return $detail;
		}
		return mb_substr( $detail, 0, 181 ) . '…' . substr( md5( $detail ), 0, 8 );
	}

	/**
	 * Copies an entry into the Activity Log plugin when its API is present.
	 *
	 * Database errors are suppressed for the duration of the call, and only for it. That plugin
	 * owns and upgrades its table on its own schedule; a site whose schema has not caught up
	 * produces an error on every insert, which with WP_DEBUG_DISPLAY on would print into a response
	 * being served. The entry is already stored above, so the mirror must never affect the page.
	 *
	 * @param string $surface What was served.
	 * @param string $agent   Requesting agent.
	 * @param string $ip      Client IP.
	 * @return void
	 */
	private static function mirror_to_activity_log( $surface, $agent, $ip ) {
		if ( ! function_exists( 'aal_insert_log' ) ) {
			return;
		}

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		aal_insert_log(
			array(
				'action'         => 'requested',
				'object_type'    => 'Agent-Ready',
				'object_subtype' => $surface,
				'object_name'    => $agent,
				'object_id'      => 0,
				'user_id'        => 0,
				'hist_ip'        => $ip,
			)
		);
		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Records a normal HTML page view, but only when the user-agent looks like a known agent.
	 *
	 * This supplies the denominator: without it the log shows only the agents that asked for an
	 * agent-facing file, and "which agents ask for markdown" cannot be answered without also
	 * knowing which ones came and did not.
	 *
	 * In 'all' mode it records every page view, not only those from a recognised crawler. That
	 * closes a blind spot which quietly distorted every share calculated from this log: an
	 * unrecognised client's agent-surface requests were recorded while its ordinary page views were
	 * not, so anything unbranded looked like it consumed nothing but agent-facing files. It also
	 * means the log now contains human traffic, which is why those rows are stored against a
	 * network rather than an address.
	 *
	 * @return void
	 */
	public static function maybe_record_page_view() {
		if ( is_admin() || is_feed() || ! self::is_active() ) {
			return;
		}

		// A 404 is recorded by the 404 surfaces, which already reason carefully about the fact that
		// the path is caller-supplied. Recording it a second time here would duplicate the row and,
		// worse, put that unbounded path into this surface's throttle key.
		if ( is_404() ) {
			return;
		}

		$ua = self::user_agent();
		if ( '' === $ua ) {
			return;
		}

		$known = self::is_known_agent( $ua );
		if ( ! $known && 'all' !== self::page_view_mode() ) {
			return;
		}

		// Unrecognised user-agents are mostly people. Their address is reduced to its network before
		// storage; a recognised crawler keeps its full one, which is what verification runs against.
		self::record(
			'HTML page view (' . self::accept_summary() . ')',
			self::requested_url(),
			true,
			! $known,
			self::page_view_path()
		);
	}

	/**
	 * The URL as the caller actually requested it, path and query string.
	 *
	 * Safe to *store* because storage is not the constraint: the value is escaped on the admin screen
	 * and run through csv_cell() on export, and control characters are stripped here. It is not safe
	 * to *throttle* on, which is a different job handled by page_view_path().
	 *
	 * The query string is kept, so an internal search is recorded as the visitor typed it. That is
	 * ordinary for a site's own logs and it is worth knowing about rather than discovering.
	 *
	 * @return string
	 */
	private static function requested_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the request line for a log annotation, not a state change.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '/';
		}

		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		$out   = rawurldecode( $path ) . ( '' !== $query ? '?' . rawurldecode( $query ) : '' );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $out );
		$out   = null === $clean ? '' : $clean;

		if ( '' !== $out && ! mb_check_encoding( $out, 'UTF-8' ) ) {
			$clean = preg_replace( '/[^\x20-\x7E]/', '', $out );
			$out   = null === $clean ? '' : $clean;
		}

		return '' === $out ? '/' : '/' . ltrim( $out, '/' );
	}

	/**
	 * The bounded page a request resolved to, used as the throttle key rather than stored.
	 *
	 * Never `REQUEST_URI`. The throttle key is what stops a caller writing a row per request: a search
	 * query or a junk querystring is unbounded caller input, so keying on it would hand anyone a way
	 * to grow the table at will. Everything below comes from WordPress resolving the request to
	 * something the site actually publishes.
	 *
	 * Since 1.27.0 this is *only* the key. The row stores requested_url() instead, so nothing about
	 * what the visitor asked for is lost. The cost of keying here is sampling rather than omission:
	 * two different URLs resolving to the same page within the throttle window produce one row, and
	 * it keeps whichever arrived first.
	 *
	 * @return string
	 */
	private static function page_view_path() {
		if ( is_singular() ) {
			$id = get_queried_object_id();
			return $id ? self::request_path( (string) get_permalink( $id ) ) : '/';
		}
		if ( is_front_page() || is_home() ) {
			return '/';
		}
		if ( is_search() ) {
			// Deliberately not the search term, which is caller-supplied and unbounded.
			return '(search)';
		}

		$queried = get_queried_object();
		if ( $queried instanceof WP_Term ) {
			$link = get_term_link( $queried );
			return is_wp_error( $link ) ? '(archive)' : self::request_path( (string) $link );
		}
		if ( $queried instanceof WP_Post_Type ) {
			return self::request_path( (string) get_post_type_archive_link( $queried->name ) );
		}
		if ( $queried instanceof WP_User ) {
			return self::request_path( (string) get_author_posts_url( $queried->ID ) );
		}
		if ( is_date() ) {
			return '(date archive)';
		}

		return '(other)';
	}

	/**
	 * A short label for the requesting agent: the matched agent name where recognized, otherwise a
	 * trimmed user-agent so unknown clients stay identifiable.
	 *
	 * @return string
	 */
	private static function agent_label() {
		$ua = self::user_agent();
		if ( '' === $ua ) {
			return 'unknown';
		}
		foreach ( self::AGENTS as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $needle;
			}
		}
		return mb_substr( $ua, 0, 80 );
	}

	/**
	 * Whether the request asked for markdown, HTML, or expressed no preference.
	 *
	 * @return string
	 */
	private static function accept_summary() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $accept ) {
			return 'no Accept';
		}
		if ( false !== stripos( $accept, 'markdown' ) ) {
			return 'asked for markdown';
		}
		if ( false !== stripos( $accept, 'text/html' ) ) {
			return 'asked for HTML';
		}
		return 'Accept: ' . mb_substr( $accept, 0, 30 );
	}

	/**
	 * User agent string for this request.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Client IP, preferring Cloudflare's header — behind a CDN, REMOTE_ADDR is the edge, so every
	 * agent would otherwise share one address and the throttle would collapse them together.
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} else {
			return '';
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
