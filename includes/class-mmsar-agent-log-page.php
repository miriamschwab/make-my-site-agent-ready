<?php
/**
 * The Agent Log admin screen.
 *
 * A page of its own rather than a section on the settings screen: a settings screen is for
 * configuration, and this is data that grows. Keeping them apart is what makes pagination, a
 * retention control and a clear button possible without the log pushing the settings off-screen.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log screen.
 */
class MMSAR_Agent_Log_Page {

	const SLUG     = 'mmsar-agent-log';
	const PER_PAGE = 50;

	/**
	 * Visits per page in the journeys view.
	 *
	 * Lower than PER_PAGE because a visit is not a row: each one opens into its own list of
	 * requests, so a page of fifty would be several hundred lines of path.
	 */
	const VISITS_PER_PAGE = 20;

	/**
	 * The two views of the same log.
	 *
	 * The list is the original and stays the default: it is the view that answers "what happened
	 * just now", it is what every existing bookmark and every link in this plugin points at, and
	 * a screen that silently changed shape under someone who knows it would be a worse screen.
	 * Journeys answer a different question — what one caller did in sequence — and that question
	 * is asked less often, from a link or a tab rather than on arrival.
	 */
	const VIEWS = array( 'list', 'journeys' );

	/**
	 * Rows read per query while streaming an export.
	 *
	 * The export walks the entire log, which can be far larger than anything the screen shows, so
	 * it is written out in batches rather than loaded into an array first. Whatever the log holds,
	 * peak memory is one batch.
	 */
	const EXPORT_BATCH = 500;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_mmsar_clear_agent_log', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_mmsar_export_agent_log', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mmsar_verify_agent_log', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_mmsar_recheck_agent_log', array( __CLASS__, 'handle_recheck' ) );
	}

	/**
	 * Add menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Agent Log', 'make-my-site-agent-ready' ),
			__( 'Agent Log', 'make-my-site-agent-ready' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'mmsar_agent_log_group',
			MMSAR_Agent_Log::LIMIT_OPTION,
			array(
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_clear_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		// The typed word is checked here as well as in the browser. A disabled button is a
		// convenience, not a guard: this endpoint is reachable directly with a valid nonce.
		$typed = isset( $_POST['mmsar_clear_confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['mmsar_clear_confirm'] ) ) ) : '';
		if ( _x( 'DELETE', 'confirmation word typed to clear the agent log', 'make-my-site-agent-ready' ) !== $typed ) {
			wp_die(
				esc_html__( 'The log was not cleared: the confirmation word did not match.', 'make-my-site-agent-ready' ),
				esc_html__( 'Confirmation required', 'make-my-site-agent-ready' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}

		MMSAR_Agent_Log::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'mmsar_cleared' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Verifies a large batch of entries on request, then comes back with a count.
	 *
	 * The trickle on render decides ten addresses at a time, which keeps a live log current but
	 * would take a hundred and fifty page loads to work through a backlog the size of this site's
	 * own. This is how that backlog actually clears: one press, a few hundred addresses, its own
	 * time budget so the request cannot hang, and a report of what it found.
	 *
	 * @return void
	 */
	public static function handle_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_verify_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		// Read here rather than in the redirect helper: the nonce check above is in this function,
		// which is what lets the sniff see a $_POST read as guarded.
		$return = isset( $_POST['mmsar_return'] ) ? sanitize_key( wp_unslash( $_POST['mmsar_return'] ) ) : '';

		$done = MMSAR_Agent_Log_Verify::run_batch(
			MMSAR_Agent_Log_Verify::BUTTON_IPS,
			MMSAR_Agent_Log_Verify::BUTTON_BUDGET
		);

		self::redirect_after_pass(
			array(
				'mmsar_verified'     => (int) $done['rows'],
				'mmsar_verified_ips' => (int) $done['ips'],
				'mmsar_verify_done'  => empty( $done['exhausted'] ) ? '0' : '1',
			),
			'dashboard' === $return
		);
	}

	/**
	 * Sends the browser back to wherever the button was pressed.
	 *
	 * The verify and re-check buttons exist in two places as of 1.29.0 — this screen and the
	 * dashboard widget — and always returning to the log screen would answer the question by
	 * moving the person who asked it.
	 *
	 * The destination is chosen from a fixed pair rather than taken from the request. A posted URL
	 * to redirect to is an open redirect waiting to happen even behind `wp_safe_redirect()`, and
	 * there are only ever two answers, so a flag is enough.
	 *
	 * @param array $args         Query args describing what the pass did.
	 * @param bool  $to_dashboard Whether the button was pressed on the dashboard widget.
	 * @return void
	 */
	private static function redirect_after_pass( $args, $to_dashboard ) {
		if ( $to_dashboard ) {
			wp_safe_redirect( add_query_arg( $args, admin_url( 'index.php' ) ) );
			exit;
		}

		$args['page'] = self::SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Reconsiders every entry whose identity could not be decided.
	 *
	 * A verdict is only as good as what this plugin knew when it was reached, and that changes: a
	 * `nodns` or an `unverifiable` means "no method available", not "the caller is a mystery
	 * forever". When an operator's suffix or range file is added, the rows it would now answer are
	 * sitting in the log holding the old non-answer. This is how they get another look.
	 *
	 * `verified` and `failed` are left alone — see MMSAR_Agent_Log::recheckable_verdicts().
	 *
	 * The cached verdicts have to go first. `unverifiable` is cached against the address for a
	 * week, so resetting the rows without clearing the cache would re-read the same stale answer
	 * and accomplish nothing at all.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_recheck_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		// See handle_verify(): read while the nonce check is still in scope.
		$return = isset( $_POST['mmsar_return'] ) ? sanitize_key( wp_unslash( $_POST['mmsar_return'] ) ) : '';

		// Both lists: get_undecided_pairs() covers the verdicts a re-check has always reopened, and
		// get_recheckable_pairs() is the set actually about to be reset — which now includes rows
		// that read `unclaimed` until this plugin learned the name they claim. Forgetting is
		// idempotent, so the overlap between them costs nothing and missing one would hand the
		// re-run a cached answer from before the name was known.
		$to_forget = array_merge( MMSAR_Agent_Log::get_undecided_pairs(), MMSAR_Agent_Log::get_recheckable_pairs() );
		foreach ( $to_forget as $pair ) {
			MMSAR_Agent_Log_Verify::forget(
				isset( $pair['agent'] ) ? $pair['agent'] : '',
				isset( $pair['ip'] ) ? $pair['ip'] : ''
			);
		}

		$before = MMSAR_Agent_Log::get_verification_summary();
		$reset  = MMSAR_Agent_Log::reset_undecided();
		$done   = MMSAR_Agent_Log_Verify::run_batch(
			MMSAR_Agent_Log_Verify::BUTTON_IPS,
			MMSAR_Agent_Log_Verify::BUTTON_BUDGET
		);

		// Report what moved, not merely what was touched. A pass that reopened rows and wrote back
		// the same verdicts is a legitimate outcome and should say so, rather than reporting a
		// count that looks like progress.
		$after   = MMSAR_Agent_Log::get_verification_summary();
		$changed = 0;
		foreach ( $after['counts'] as $verdict => $count ) {
			$was = isset( $before['counts'][ $verdict ] ) ? (int) $before['counts'][ $verdict ] : 0;
			if ( $count > $was ) {
				$changed += $count - $was;
			}
		}

		self::redirect_after_pass(
			array(
				'mmsar_rechecked'    => (int) $reset,
				'mmsar_changed'      => (int) $changed,
				'mmsar_verified_ips' => (int) $done['ips'],
			),
			'dashboard' === $return
		);
	}

	/**
	 * Streams the whole log as a CSV download.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'make-my-site-agent-ready' ) );
		}
		if ( ! isset( $_POST['mmsar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmsar_nonce'] ) ), 'mmsar_export_agent_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'make-my-site-agent-ready' ) );
		}

		// The export mirrors the screen unless asked for everything, so "export what I am looking at"
		// is the default reading of the button next to a filtered table.
		$scope   = isset( $_POST['mmsar_export_scope'] ) ? sanitize_key( wp_unslash( $_POST['mmsar_export_scope'] ) ) : 'view';
		$filters = 'all' === $scope ? array( 'clients' => array_merge( MMSAR_Agent_Log::client_types(), array( 'unrecorded' ) ) ) : self::filters_from_post();

		$filename = 'agent-log-' . ( 'all' === $scope ? 'all-' : '' ) . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to the response body, which WP_Filesystem does not address.
		$handle = fopen( 'php://output', 'w' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Could not start the export.', 'make-my-site-agent-ready' ) );
		}

		// Column names rather than the screen's labels: this file is meant to be parsed. The suffix
		// on the timestamp is not decoration — the rows are stored in UTC while the screen renders
		// them in the site's timezone, and a bare "logged_at" would leave a reader comparing an
		// exported row against the screen with no way to tell which one they were holding.
		// Appended, never reordered: the column order is documented and something is parsing it.
		fputcsv( $handle, array( 'logged_at_utc', 'agent', 'surface', 'detail', 'ip', 'verified', 'verified_at_utc', 'client_type' ) );

		$cursor = 0;
		do {
			$rows  = MMSAR_Agent_Log::get_entries_before( $cursor, self::EXPORT_BATCH, $filters );
			$count = count( $rows );
			foreach ( $rows as $row ) {
				fputcsv(
					$handle,
					array(
						self::csv_cell( isset( $row['logged_at'] ) ? $row['logged_at'] : '' ),
						self::csv_cell( isset( $row['agent'] ) ? $row['agent'] : '' ),
						self::csv_cell( isset( $row['surface'] ) ? $row['surface'] : '' ),
						self::csv_cell( isset( $row['detail'] ) ? $row['detail'] : '' ),
						self::csv_cell( isset( $row['ip'] ) ? $row['ip'] : '' ),
						self::csv_cell( isset( $row['verified'] ) ? $row['verified'] : '' ),
						self::csv_cell( isset( $row['verified_at'] ) ? (string) $row['verified_at'] : '' ),
						self::csv_cell( isset( $row['client_type'] ) ? $row['client_type'] : '' ),
					)
				);
				$cursor = (int) $row['id'];
			}
		} while ( self::EXPORT_BATCH === $count );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the response body handle opened above.
		fclose( $handle );
		exit;
	}

	/**
	 * The filter set carried on the export form, validated the same way the screen validates it.
	 *
	 * @return array{verdicts: string[], clients: string[], categories: string[]}
	 */
	private static function filters_from_post() {
		$pick = static function ( $key, $allowed ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified the export nonce before reaching this.
			$raw = isset( $_POST[ $key ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $key ] ) ) : array();
			return array_values( array_intersect( $raw, $allowed ) );
		};

		return array(
			'verdicts'   => $pick( 'verdict', array_merge( MMSAR_Agent_Log_Verify::verdicts(), array( 'pending' ) ) ),
			'clients'    => $pick( 'client', array_merge( MMSAR_Agent_Log::client_types(), array( 'unrecorded' ) ) ),
			'categories' => $pick( 'surface', MMSAR_Agent_Log::categories() ),
			'crawlers'   => $pick( 'crawler', array_merge( MMSAR_Agent_Log::crawler_categories(), array( MMSAR_Agent_Log::CRAWLER_UNRECOGNISED ) ) ),
		);
	}

	/**
	 * Neutralizes a value that a spreadsheet would read as a formula.
	 *
	 * The agent column holds a user-agent string, which is supplied by whoever made the request and
	 * is stored verbatim so unknown clients stay identifiable. A cell opening with =, +, -, @ or a
	 * control character is executed as a formula by Excel and several other spreadsheets on open,
	 * which turns a log of untrusted strings into a small remote-code path on the reader's machine.
	 * Prefixing an apostrophe makes the cell text; the spreadsheet hides the prefix, and a parser
	 * that is not a spreadsheet sees one extra leading character on the few rows that need it.
	 *
	 * @param string $value Raw cell value.
	 * @return string Value safe to write.
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		return ( false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	/**
	 * The requested page number, clamped to what exists.
	 *
	 * Kept in its own method so the request variable is read, bounded and turned into an integer in
	 * one place, well away from anything that renders — both easier to check by eye and clearer to
	 * a static analyser than the same three steps inlined among the output.
	 *
	 * @param int $pages Total number of pages.
	 * @return int Page number between 1 and $pages.
	 */
	private static function current_page( $pages ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination of an admin screen.
		$requested = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		return (int) max( 1, min( (int) $pages, $requested ) );
	}

	/**
	 * The filter set currently requested, validated against what exists.
	 *
	 * Read as arrays so several values can be selected on each axis: "agent documents and markdown",
	 * "crawlers and browsers". Everything is whitelisted here, and the query layer whitelists again.
	 *
	 * @return array{verdicts: string[], clients: string[], categories: string[]}
	 */
	private static function current_filters() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of an admin screen.
		$raw = wp_unslash( $_GET );

		$pick = static function ( $key, $allowed ) use ( $raw ) {
			$vals = isset( $raw[ $key ] ) ? (array) $raw[ $key ] : array();
			$vals = array_map( 'sanitize_key', array_map( 'strval', $vals ) );
			return array_values( array_intersect( $vals, $allowed ) );
		};

		return array(
			'verdicts'   => $pick( 'verdict', array_merge( MMSAR_Agent_Log_Verify::verdicts(), array( 'pending' ) ) ),
			'clients'    => $pick( 'client', array_merge( MMSAR_Agent_Log::client_types(), array( 'unrecorded' ) ) ),
			'categories' => $pick( 'surface', MMSAR_Agent_Log::categories() ),
			'crawlers'   => $pick( 'crawler', array_merge( MMSAR_Agent_Log::crawler_categories(), array( MMSAR_Agent_Log::CRAWLER_UNRECOGNISED ) ) ),
		);
	}

	/**
	 * Which of the two views is being asked for.
	 *
	 * Anything unrecognised falls back to the list rather than erroring: a mistyped or truncated
	 * URL should land on the screen's own default, which is the behaviour before this argument
	 * existed.
	 *
	 * @return string One of VIEWS.
	 */
	private static function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection on an admin screen.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		return in_array( $view, self::VIEWS, true ) ? $view : 'list';
	}

	/**
	 * The single address the journeys view is narrowed to, if any.
	 *
	 * Validated as an address rather than merely escaped, so the value that reaches the query is
	 * one of a closed shape whatever arrives in the URL. Both forms this log stores pass: a full
	 * address, and the reduced `1.2.3.0` / `2001:db8:1:2::` forms written for unrecognised clients
	 * are themselves valid addresses.
	 *
	 * @return string Address, or empty string.
	 */
	private static function current_ip() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of an admin screen.
		$ip = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
		return ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/**
	 * Whether the journeys view is hiding visits of a single request.
	 *
	 * On by default, and this is the setting that makes the view readable rather than a preference:
	 * most callers arrive once, take one file and leave, so a list that includes them is mostly
	 * one-hop entries with the actual journeys scattered among them — which is the flat list again,
	 * only longer. The unticked box is there because "how many callers took exactly one thing" is
	 * a real question, just not this view's default one.
	 *
	 * @return bool
	 */
	private static function current_multi_only() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering of an admin screen.
		return ! isset( $_GET['single'] ) || '1' !== $_GET['single'];
	}

	/**
	 * Whether any filter is actually narrowing the view.
	 *
	 * @param array $filters Filter set.
	 * @return bool
	 */
	private static function filters_active( $filters ) {
		return (bool) ( $filters['verdicts'] || $filters['clients'] || $filters['categories'] || $filters['crawlers'] );
	}

	/**
	 * The current filters as query arguments, for building URLs that keep them.
	 *
	 * @param array $filters Filter set.
	 * @param array $extra   Additional arguments to carry — view, ip, single. Empty values are
	 *                       dropped, so the list view's URLs stay exactly as they were before the
	 *                       second view existed.
	 * @return array
	 */
	private static function filter_args( $filters, $extra = array() ) {
		$args = array( 'page' => self::SLUG );
		foreach ( array(
			'verdict' => 'verdicts',
			'client'  => 'clients',
			'surface' => 'categories',
			'crawler' => 'crawlers',
		) as $arg => $key ) {
			if ( $filters[ $key ] ) {
				$args[ $arg ] = $filters[ $key ];
			}
		}
		foreach ( (array) $extra as $arg => $value ) {
			if ( '' !== $value && null !== $value ) {
				$args[ $arg ] = $value;
			}
		}
		return $args;
	}

	/**
	 * The view-state arguments that every URL and form on this screen has to carry.
	 *
	 * Kept in one place because forgetting one of them is a bug that reads as the screen resetting
	 * itself: paginating out of the journeys view, or losing the address a link narrowed to.
	 *
	 * @param string $view Current view.
	 * @param string $ip   Current address filter.
	 * @return array
	 */
	private static function view_args( $view, $ip ) {
		return array(
			'view'   => 'list' === $view ? '' : $view,
			'ip'     => $ip,
			'single' => ( 'journeys' === $view && ! self::current_multi_only() ) ? '1' : '',
		);
	}

	/**
	 * The small crawler-category line shown under an agent name.
	 *
	 * Empty for a row that names no recognised crawler: "Unrecognised" under every browser and
	 * script would be noise on the rows that are most of the log.
	 *
	 * @param string $agent Stored agent value.
	 * @return string Escaped HTML, or an empty string.
	 */
	private static function crawler_tag( $agent ) {
		$category = MMSAR_Agent_Log::crawler_category( $agent );
		if ( '' === $category ) {
			return '';
		}
		return '<br><span style="font-size:11px;color:#8c8f94;">' . esc_html( MMSAR_Agent_Log::crawler_category_label( $category ) ) . '</span>';
	}

	/**
	 * One checkbox group in the filter bar.
	 *
	 * @param string   $name     Query argument name.
	 * @param string   $legend   Group label.
	 * @param array    $options  value => label.
	 * @param string[] $selected Currently ticked values.
	 * @param array    $counts   Optional value => count.
	 * @param array    $titles   Optional value => hover text explaining what the option covers.
	 * @return void
	 */
	private static function render_filter_group( $name, $legend, $options, $selected, $counts = array(), $titles = array() ) {
		echo '<fieldset style="margin:0 2rem .5rem 0;display:inline-block;vertical-align:top;">';
		echo '<legend style="font-weight:600;padding:0 0 .25rem;">' . esc_html( $legend ) . '</legend>';
		foreach ( $options as $value => $label ) {
			$text = isset( $counts[ $value ] ) ? $label . ' (' . number_format_i18n( $counts[ $value ] ) . ')' : $label;
			$hint = isset( $titles[ $value ] ) ? (string) $titles[ $value ] : '';

			// The hint hangs off a span rather than the label, so the dotted underline marks the
			// words it explains instead of the whole row including the checkbox.
			$rendered = '' === $hint
				? esc_html( $text )
				: '<span title="' . esc_attr( $hint ) . '" style="border-bottom:1px dotted #787c82;cursor:help;">' . esc_html( $text ) . '</span>';

			printf(
				'<label style="display:block;white-space:nowrap;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( in_array( $value, $selected, true ), true, false ),
				wp_kses_post( $rendered )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Hover text for each Surface option, saying what the category actually contains.
	 *
	 * "Agent documents" is the one that needs it. It is the residual category, so its name cannot
	 * describe it and the answer changes as surfaces are added — which is why it is read back out
	 * of the log rather than written down here. The other three are named after exactly what they
	 * hold, so they get a fixed sentence that adds the part the name leaves out.
	 *
	 * @return array<string, string> Category value => hover text.
	 */
	private static function surface_hints() {
		$hints = array(
			MMSAR_Agent_Log::CAT_MARKDOWN => __( 'Markdown versions of your pages — a .md address, or an ordinary URL where the client asked for markdown.', 'make-my-site-agent-ready' ),
			MMSAR_Agent_Log::CAT_HTML     => __( 'Ordinary page views. Recorded as a denominator so shares can be worked out honestly, not as agent traffic.', 'make-my-site-agent-ready' ),
			MMSAR_Agent_Log::CAT_NOTFOUND => __( 'Requests for addresses that do not exist. What was asked for is listed under the table.', 'make-my-site-agent-ready' ),
		);

		$surfaces = MMSAR_Agent_Log::get_surfaces_in_category( MMSAR_Agent_Log::CAT_DOCS );
		if ( empty( $surfaces ) ) {
			$hints[ MMSAR_Agent_Log::CAT_DOCS ] = __( 'The agent-facing documents this plugin serves — llms.txt, the catalogs, the MCP and Agent Skills files, and anything else that is not a page view, a markdown response or a 404. Nothing has been requested yet.', 'make-my-site-agent-ready' );
			return $hints;
		}

		$parts = array();
		foreach ( $surfaces as $row ) {
			$parts[] = $row['surface'] . ' (' . number_format_i18n( (int) $row['total'] ) . ')';
		}
		$hints[ MMSAR_Agent_Log::CAT_DOCS ] = __( 'Everything served to agents that is not a page view, a markdown response or a 404:', 'make-my-site-agent-ready' )
			. "\n\n" . implode( " \xC2\xB7 ", $parts );

		return $hints;
	}

	/**
	 * The filter bar above the table.
	 *
	 * A plain GET form, so every view is a URL that can be bookmarked or sent to someone, and no
	 * JavaScript is needed to combine axes.
	 *
	 * @param array $filters Current filter set.
	 * @param int   $shown   Rows the current filter matches.
	 * @param int   $total   Rows in the whole log.
	 * @param array $extra   View-state arguments to carry through the form, from view_args().
	 * @return void
	 */
	private static function render_filter_bar( $filters, $shown, $total, $extra = array() ) {
		$catcounts = MMSAR_Agent_Log::get_category_counts();

		echo '<form id="mmsar-filter-form" method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" style="margin:1.5em 0 1em;padding:1rem 1.2rem;background:#fff;border:1px solid #c3c4c7;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		// The view state rides along, so ticking a filter narrows the view being looked at rather
		// than returning to the default one.
		foreach ( (array) $extra as $name => $value ) {
			if ( '' !== $value && null !== $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}

		$surface_opts = array();
		foreach ( MMSAR_Agent_Log::categories() as $cat ) {
			$surface_opts[ $cat ] = MMSAR_Agent_Log::category_label( $cat );
		}
		self::render_filter_group( 'surface', __( 'Surface', 'make-my-site-agent-ready' ), $surface_opts, $filters['categories'], $catcounts, self::surface_hints() );

		$client_opts = array();
		foreach ( MMSAR_Agent_Log::client_types() as $ct ) {
			$client_opts[ $ct ] = MMSAR_Agent_Log::client_type_label( $ct );
		}
		$client_opts['unrecorded'] = __( 'Not recorded', 'make-my-site-agent-ready' );
		self::render_filter_group( 'client', __( 'Client', 'make-my-site-agent-ready' ), $client_opts, $filters['clients'] );

		$verdict_opts = array();
		foreach ( MMSAR_Agent_Log_Verify::verdicts() as $v ) {
			$verdict_opts[ $v ] = MMSAR_Agent_Log_Verify::label( $v );
		}
		$verdict_opts['pending'] = MMSAR_Agent_Log_Verify::label( MMSAR_Agent_Log_Verify::PENDING );
		self::render_filter_group( 'verdict', __( 'Identity', 'make-my-site-agent-ready' ), $verdict_opts, $filters['verdicts'] );

		// What kind of bot the row names, which is what separates AI traffic from search and SEO
		// crawlers. Counts are requests over the whole log, like the Surface counts.
		$crawler_opts   = array();
		$crawler_counts = array();
		foreach ( MMSAR_Agent_Log::get_crawler_category_counts() as $cc => $row ) {
			$crawler_opts[ $cc ]   = MMSAR_Agent_Log::crawler_category_label( $cc );
			$crawler_counts[ $cc ] = $row['requests'];
		}
		self::render_filter_group( 'crawler', __( 'Crawler type', 'make-my-site-agent-ready' ), $crawler_opts, $filters['crawlers'], $crawler_counts );

		echo '<div style="clear:both;padding-top:.6rem;border-top:1px solid #f0f0f1;margin-top:.4rem;">';
		echo '<span id="mmsar-filter-apply">';
		submit_button( __( 'Apply filters', 'make-my-site-agent-ready' ), 'primary', 'submit', false );
		echo '</span>';
		if ( self::filters_active( $filters ) ) {
			// Resets the filters, not the view: someone pressing this in the journeys view wants
			// every journey back, not the other screen.
			$reset = add_query_arg(
				self::filter_args(
					array(
						'verdicts'   => array(),
						'clients'    => array(),
						'categories' => array(),
						'crawlers'   => array(),
					),
					$extra
				),
				admin_url( 'options-general.php' )
			);
			echo ' <a href="' . esc_url( $reset ) . '" style="margin-left:.6rem;">' . esc_html__( 'Reset', 'make-my-site-agent-ready' ) . '</a>';
		}
		echo ' <span class="description" style="margin-left:1rem;">';
		if ( self::filters_active( $filters ) ) {
			printf(
				/* translators: 1: matching entries, 2: total entries */
				esc_html__( 'Showing %1$s of %2$s entries.', 'make-my-site-agent-ready' ),
				'<strong>' . esc_html( number_format_i18n( $shown ) ) . '</strong>',
				esc_html( number_format_i18n( $total ) )
			);
		} else {
			printf(
				/* translators: 1: entries shown, 2: total entries */
				esc_html__( 'Showing %1$s of %2$s entries. Browser page views are excluded until you tick Browser; they are recorded as a denominator, not as agent traffic.', 'make-my-site-agent-ready' ),
				'<strong>' . esc_html( number_format_i18n( $shown ) ) . '</strong>',
				esc_html( number_format_i18n( $total ) )
			);
		}
		echo '</span></div>';
		echo '</form>';

		// Admin-only inline script, and an enhancement rather than the mechanism: the form is a
		// plain GET form that works exactly as before with this turned off, which is what keeps
		// every view a URL that can be bookmarked or sent to someone. All this does is press the
		// button, so the button is hidden only once the script that replaces it is running.
		//
		// Ticks are batched behind a short delay rather than submitted one at a time. Combining
		// axes means three or four clicks in a row, and reloading between each would throw away
		// the next click and land the reader somewhere they did not ask for.
		?>
		<script>
		( function () {
			var form = document.getElementById( 'mmsar-filter-form' );
			var apply = document.getElementById( 'mmsar-filter-apply' );
			if ( ! form || ! apply || ! form.addEventListener ) { return; }

			var note = document.createElement( 'span' );
			note.className = 'description';
			note.textContent = <?php echo wp_json_encode( __( 'Filters apply as you tick them.', 'make-my-site-agent-ready' ) ); ?>;
			apply.parentNode.insertBefore( note, apply );
			apply.style.display = 'none';

			var idle = note.textContent;
			var busy = <?php echo wp_json_encode( __( 'Updating…', 'make-my-site-agent-ready' ) ); ?>;
			var timer = null;

			// `form.submit()` is not callable here. The Apply button is `name="submit"`, as
			// submit_button() writes it, and a named control shadows the form method of the same
			// name — so `form.submit` is that input element and calling it throws. Going through
			// the prototype submits the form whatever the controls are called. It also leaves the
			// button's own name out of the query string, which a click would put there.
			function mmsarApply() {
				note.textContent = busy;
				try {
					HTMLFormElement.prototype.submit.call( form );
				} catch ( err ) {
					// Never leave the reader looking at "Updating…" forever: put the button back
					// and let them press it, which is the behaviour with this script absent.
					note.textContent = idle;
					apply.style.display = '';
				}
			}

			form.addEventListener( 'change', function ( e ) {
				if ( ! e.target || 'checkbox' !== e.target.type ) { return; }
				if ( timer ) { window.clearTimeout( timer ); }
				timer = window.setTimeout( mmsarApply, 450 );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * What agents asked for and did not get, most-asked-for first.
	 *
	 * The log answers "how many 404s" on its own; this answers the only part that leads anywhere,
	 * which is *what* was being looked for. A run of guesses at one URL pattern the site could
	 * support reads as noise row by row and as an obvious gap once the paths are stacked up.
	 *
	 * Opened by default when the reader is already filtering to Not found, closed otherwise — the
	 * panel is an aid to a question most visits to this screen are not asking.
	 *
	 * @param array $filters Current filter set.
	 * @return void
	 */
	private static function render_notfound_panel( $filters ) {
		$paths = MMSAR_Agent_Log::get_notfound_paths();
		if ( empty( $paths ) ) {
			return;
		}

		$open = in_array( MMSAR_Agent_Log::CAT_NOTFOUND, $filters['categories'], true ) ? ' open' : '';

		echo '<details' . esc_attr( $open ) . ' style="margin:1em 0;padding:.8rem 1.2rem;background:#fff;border:1px solid #c3c4c7;">';
		echo '<summary style="cursor:pointer;font-weight:600;">';
		printf(
			/* translators: %s: number of distinct paths */
			esc_html__( 'What agents looked for and did not find (%s addresses)', 'make-my-site-agent-ready' ),
			esc_html( number_format_i18n( count( $paths ) ) )
		);
		echo '</summary>';

		// Said before the numbers rather than after them. The throttle that keeps a URL-walking
		// crawler from writing a row per request also means a path guessed twenty times in a
		// minute is recorded once, so reading these as request counts overstates the rare and
		// understates the persistent.
		echo '<p class="description" style="margin:.6rem 0 .8rem;">'
			. esc_html__( 'Ranked by how often each address was recorded, not by how often it was asked for: one 404 per agent and address is kept every five minutes, so a crawler working through a list appears far fewer times than it called. Treat the order as the signal and the totals as a floor.', 'make-my-site-agent-ready' )
			. '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Address asked for', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:8em;">' . esc_html__( 'Recorded', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:8em;">' . esc_html__( 'Agents', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:12em;">' . esc_html__( 'Last seen', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $paths as $row ) {
			$stamp = isset( $row['last_seen'] ) ? strtotime( $row['last_seen'] . ' UTC' ) : 0;
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $row['path'] ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['total'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['agents'] ) ) . '</td>';
			echo '<td>' . esc_html( $stamp ? wp_date( 'Y-m-d H:i', $stamp ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</details>';
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Decide a few identities before reading, so simply opening this screen keeps a live log
		// current. Bounded by a wall-clock budget, so a slow resolver delays the page rather than
		// holding it: whatever is left over is picked up on the next render or by the button below.
		MMSAR_Agent_Log_Verify::run_batch();

		$filters = self::current_filters();
		$view    = self::current_view();
		$ip      = self::current_ip();
		$extra   = self::view_args( $view, $ip );
		$total   = MMSAR_Agent_Log::count_entries();
		$shown   = MMSAR_Agent_Log::count_filtered( $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Agent Log', 'make-my-site-agent-ready' ) . '</h1>';
		self::render_view_tabs( $view, $filters, $ip );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agent log cleared.', 'make-my-site-agent-ready' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_rechecked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$reopened = absint( wp_unslash( $_GET['mmsar_rechecked'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$changed = isset( $_GET['mmsar_changed'] ) ? absint( wp_unslash( $_GET['mmsar_changed'] ) ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>';
			if ( $changed > 0 ) {
				printf(
					/* translators: 1: number of entries re-examined, 2: number whose verdict changed */
					esc_html__( 'Re-checked %1$s entries. %2$s now have a different verdict.', 'make-my-site-agent-ready' ),
					'<strong>' . esc_html( number_format_i18n( $reopened ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $changed ) ) . '</strong>'
				);
			} else {
				printf(
					/* translators: %s: number of entries re-examined */
					esc_html__( 'Re-checked %s entries. Every one reached the same verdict as before, so nothing changed.', 'make-my-site-agent-ready' ),
					'<strong>' . esc_html( number_format_i18n( $reopened ) ) . '</strong>'
				);
			}
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_verified'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$rows = absint( wp_unslash( $_GET['mmsar_verified'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$ips = isset( $_GET['mmsar_verified_ips'] ) ? absint( wp_unslash( $_GET['mmsar_verified_ips'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$finished = isset( $_GET['mmsar_verify_done'] ) && '1' === $_GET['mmsar_verify_done'];
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: number of entries, 2: number of IP addresses */
				esc_html__( 'Checked %1$s entries across %2$s addresses.', 'make-my-site-agent-ready' ),
				esc_html( number_format_i18n( $rows ) ),
				esc_html( number_format_i18n( $ips ) )
			);
			echo ' ';
			echo $finished
				? esc_html__( 'Nothing is left unchecked.', 'make-my-site-agent-ready' )
				: esc_html__( 'More entries are still unchecked — press it again to continue.', 'make-my-site-agent-ready' );
			echo '</p></div>';
		}

		if ( ! MMSAR_Agent_Log::is_active() ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to the settings page */
				esc_html__( 'The agent request log is switched off, so nothing new is being recorded. Turn it on under %s.', 'make-my-site-agent-ready' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings > Agent-Ready', 'make-my-site-agent-ready' ) . '</a>'
			);
			// Switching the log off is not a deletion, and the person who has just done it is the
			// one most likely to assume it was. Say so here, with the count, rather than leaving
			// them to infer it from the table still being on screen.
			if ( $total > 0 ) {
				echo ' ';
				printf(
					/* translators: %s: number of entries already recorded */
					esc_html( _n( 'The %s entry already recorded is kept: switching the log off never deletes anything. It stays readable and exportable here until you clear it yourself.', 'The %s entries already recorded are kept: switching the log off never deletes anything. They stay readable and exportable here until you clear the log yourself.', $total, 'make-my-site-agent-ready' ) ),
					'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
				);
			}
			echo '</p></div>';
		}

		self::render_verification_panel();
		self::render_retention_form( $total, $filters, $shown );
		self::render_filter_bar( $filters, $shown, $total, $extra );

		if ( 'journeys' === $view ) {
			self::render_journeys( $filters, $ip, $extra );
			self::render_notfound_panel( $filters );
			self::render_clear_form( $total );
			echo '</div>';
			return;
		}

		$pages   = max( 1, (int) ceil( $shown / self::PER_PAGE ) );
		$paged   = self::current_page( $pages );
		$entries = MMSAR_Agent_Log::get_entries( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $filters );
		$entries = MMSAR_Agent_Log_Attribution::annotate( $entries );

		if ( empty( $entries ) ) {
			echo '<p><em>' . esc_html(
				self::filters_active( $filters )
					? __( 'No entries match these filters.', 'make-my-site-agent-ready' )
					: __( 'Nothing recorded yet. Agent traffic is intermittent, so leave the log on and check back.', 'make-my-site-agent-ready' )
			) . '</em></p>';
			self::render_notfound_panel( $filters );
			self::render_clear_form( $total );
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Requested', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Identity', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			// Stored in UTC; shown in the site's timezone, which is what the reader expects.
			$stamp = isset( $entry['logged_at'] ) ? strtotime( $entry['logged_at'] . ' UTC' ) : 0;
			echo '<tr>';
			echo '<td>' . esc_html( $stamp ? wp_date( 'Y-m-d H:i', $stamp ) : '—' ) . '</td>';
			$attributed = isset( $entry['attributed_to'] ) ? (string) $entry['attributed_to'] : '';
			echo '<td>' . esc_html( isset( $entry['agent'] ) ? $entry['agent'] : '—' );
			echo self::crawler_tag( isset( $entry['agent'] ) ? (string) $entry['agent'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html() in crawler_tag().
			if ( '' !== $attributed ) {
				// The claim stays as the cell's main text. This is appended, not substituted, because the
				// agent column's job is to say what the request claimed to be — replacing it would hide
				// the forgery that makes the row interesting.
				echo '<br><span style="font-size:11px;color:#8c8f94;">'
					/* translators: %s: name of the client a forged crawler identity is attributed to. */
					. esc_html( sprintf( __( 'spoofed by %s', 'make-my-site-agent-ready' ), $attributed ) )
					. '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( isset( $entry['surface'] ) ? $entry['surface'] : '—' ) . '</td>';
			$detail = isset( $entry['detail'] ) ? (string) $entry['detail'] : '';
			echo '<td>' . ( '' === $detail ? '<span style="color:#8c8f94;">—</span>' : '<code>' . esc_html( $detail ) . '</code>' ) . '</td>';
			$ctype = isset( $entry['client_type'] ) ? (string) $entry['client_type'] : '';
			echo '<td><span style="font-size:11px;color:' . ( MMSAR_Agent_Log::CLIENT_BROWSER === $ctype ? '#8c8f94' : 'inherit' ) . ';">'
				. esc_html( MMSAR_Agent_Log::client_type_label( $ctype ) ) . '</span></td>';
			echo '<td>' . wp_kses_post( self::verdict_badge( $entry ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::ip_cell( isset( $entry['ip'] ) ? (string) $entry['ip'] : '', $filters ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						// Built from admin_url rather than add_query_arg's default, which would
						// assemble the base from REQUEST_URI and echo unsanitized query input.
						'base'      => add_query_arg(
							array_merge( self::filter_args( $filters ), array( 'paged' => '%#%' ) ),
							admin_url( 'options-general.php' )
						),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}

		self::render_notfound_panel( $filters );
		self::render_clear_form( $total );

		echo '</div>';
	}

	/**
	 * The journeys view: the log's requests stitched back into per-caller visits.
	 *
	 * The question this answers is the one the flat list cannot: not which agents fetch what, but
	 * what one of them did in order — where it came in, what it followed, whether it took the
	 * markdown after the HTML, where it gave up. The rows are the same rows; only the grouping is
	 * new.
	 *
	 * Rendered from a bounded window of recent requests, and the summary line says so. A visit
	 * whose earlier requests fell outside that window is shown as what is known of it rather than
	 * hidden, which is why the note about truncation is unconditional once the window is full.
	 *
	 * @param array  $filters Current filter set.
	 * @param string $ip      Address to narrow to, or empty.
	 * @param array  $extra   View-state arguments, from view_args().
	 * @return void
	 */
	private static function render_journeys( $filters, $ip, $extra ) {
		$result     = MMSAR_Agent_Log::get_journeys( $filters, $ip );
		$all        = $result['visits'];
		$multi_only = self::current_multi_only();
		$single     = 0;

		foreach ( $all as $visit ) {
			if ( count( $visit['hops'] ) < 2 ) {
				++$single;
			}
		}

		$visits = $multi_only
			? array_values(
				array_filter(
					$all,
					static function ( $visit ) {
						return count( $visit['hops'] ) > 1;
					}
				)
			)
			: $all;

		self::render_journey_controls( $filters, $ip, $extra, count( $visits ), $single, $result );

		if ( ! $visits ) {
			echo '<p><em>';
			if ( ! $all ) {
				esc_html_e( 'No requests match these filters, so there are no journeys to build. Agent traffic is intermittent — leave the log on and check back.', 'make-my-site-agent-ready' );
			} elseif ( self::filters_active( $filters ) ) {
				// The likeliest way to arrive here, and it is worth naming rather than leaving the
				// reader to deduce it: a journey is interesting because it crosses surfaces, so
				// filtering down to one surface reduces most visits to a single request and empties
				// this view. The filter is doing exactly what it says; it is simply the wrong tool
				// on this tab.
				esc_html_e( 'Every visit left after these filters is a single request, so there is no sequence to show. A journey is a caller moving between surfaces, so narrowing to one surface takes the sequence apart — reset the Surface filter to see the journeys these requests belong to, or use the button above to list the visits as they are.', 'make-my-site-agent-ready' );
			} else {
				esc_html_e( 'Every visit in this window was a single request: each caller took one thing and left. Use the button above to list them.', 'make-my-site-agent-ready' );
			}
			echo '</em></p>';
			return;
		}

		$pages = max( 1, (int) ceil( count( $visits ) / self::VISITS_PER_PAGE ) );
		$paged = self::current_page( $pages );
		$page  = array_slice( $visits, ( $paged - 1 ) * self::VISITS_PER_PAGE, self::VISITS_PER_PAGE );

		// Attribution is resolved for the visits actually on screen, not for the whole window: it
		// costs a query over a time span per call, and the list view pays it for one page of rows
		// for the same reason.
		$samples = array();
		foreach ( $page as $i => $visit ) {
			$samples[ $i ] = $visit['sample'];
		}
		$samples = MMSAR_Agent_Log_Attribution::annotate( $samples );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:14%;">' . esc_html__( 'Started', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:8%;">' . esc_html__( 'Requests', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:10%;">' . esc_html__( 'Lasted', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:12%;">' . esc_html__( 'Identity', 'make-my-site-agent-ready' ) . '</th>';
		echo '<th style="width:14%;">' . esc_html__( 'IP', 'make-my-site-agent-ready' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $page as $i => $visit ) {
			$sample = isset( $samples[ $i ] ) ? $samples[ $i ] : $visit['sample'];
			self::render_journey_row( $visit, $sample, $filters, $ip );
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg(
							array_merge( self::filter_args( $filters, $extra ), array( 'paged' => '%#%' ) ),
							admin_url( 'options-general.php' )
						),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * One visit: a summary row, and the requests it was made of beneath it.
	 *
	 * A `details` element rather than a script-driven toggle, so the sequence opens with JavaScript
	 * off and the whole page still prints and searches as one document — a browser's find-in-page
	 * reaches inside a closed `details`, which is exactly what someone hunting for a path wants.
	 *
	 * @param array  $visit   Visit, from get_journeys().
	 * @param array  $sample  The visit's representative row, attribution-annotated.
	 * @param array  $filters Current filter set.
	 * @param string $ip      Current address filter.
	 * @return void
	 */
	private static function render_journey_row( $visit, $sample, $filters, $ip ) {
		$hops  = $visit['hops'];
		$count = count( $hops );

		echo '<tr>';
		echo '<td>' . esc_html( wp_date( 'Y-m-d H:i', $visit['started'] ) ) . '</td>';

		echo '<td>' . esc_html( '' !== $visit['agent'] ? $visit['agent'] : '—' );
		echo self::crawler_tag( (string) $visit['agent'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html() in crawler_tag().
		$attributed = isset( $sample['attributed_to'] ) ? (string) $sample['attributed_to'] : '';
		if ( '' !== $attributed ) {
			echo '<br><span style="font-size:11px;color:#8c8f94;">'
				/* translators: %s: name of the client a forged crawler identity is attributed to. */
				. esc_html( sprintf( __( 'spoofed by %s', 'make-my-site-agent-ready' ), $attributed ) )
				. '</span>';
		}
		echo '</td>';

		echo '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
		echo '<td>' . esc_html( self::duration_label( $visit['ended'] - $visit['started'] ) ) . '</td>';
		echo '<td>' . wp_kses_post( self::verdict_badge( $sample ) ) . '</td>';
		$addresses = isset( $visit['addresses'] ) ? (array) $visit['addresses'] : array();
		$shown_ip  = self::visit_address( $visit );
		echo '<td>' . ( '' === $ip ? wp_kses_post( self::ip_cell( $shown_ip, $filters ) ) : '<code>' . esc_html( $shown_ip ) . '</code>' );
		if ( count( $addresses ) > 1 ) {
			// Two precisions of one caller, which is worth naming rather than hiding behind whichever
			// of them the cell happened to show. The per-request addresses are in the sequence below.
			echo '<br><span style="font-size:11px;color:#8c8f94;">' . esc_html(
				sprintf(
					/* translators: %s: number of distinct addresses recorded for one visit */
					_n( '%s address recorded', '%s addresses recorded', count( $addresses ), 'make-my-site-agent-ready' ),
					number_format_i18n( count( $addresses ) )
				)
			) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '<tr><td colspan="6" style="padding:0 1rem .8rem;">';
		echo '<details><summary style="cursor:pointer;color:#2271b1;">' . esc_html(
			sprintf(
				/* translators: %s: number of requests in the visit */
				_n( 'Show the %s request', 'Show all %s requests in order', $count, 'make-my-site-agent-ready' ),
				number_format_i18n( $count )
			)
		) . '</summary>';

		echo '<ol style="margin:.6rem 0 .2rem 1.4rem;">';
		$previous    = 0;
		$show_hop_ip = count( $addresses ) > 1;
		foreach ( $hops as $hop ) {
			echo '<li style="margin-bottom:.25rem;">';
			echo '<span style="color:#8c8f94;font-variant-numeric:tabular-nums;">' . esc_html( wp_date( 'H:i:s', $hop['when'] ) ) . '</span> ';
			echo '<strong>' . esc_html( '' !== $hop['surface'] ? $hop['surface'] : '—' ) . '</strong>';
			if ( '' !== $hop['detail'] ) {
				echo ' <code>' . esc_html( $hop['detail'] ) . '</code>';
			}

			// The gap since the previous request, which is where the shape of a run shows: steady
			// intervals read as a crawl working a list, a long pause as something coming back.
			if ( $previous > 0 && $hop['when'] - $previous > 0 ) {
				echo ' <span style="font-size:11px;color:#8c8f94;">'
					. esc_html(
						sprintf(
							/* translators: %s: a length of time, e.g. "12s" */
							__( '+%s', 'make-my-site-agent-ready' ),
							self::duration_label( $hop['when'] - $previous )
						)
					)
					. '</span>';
			}
			if ( $show_hop_ip && '' !== $hop['ip'] ) {
				echo ' <span style="font-size:11px;color:#8c8f94;">' . esc_html( $hop['ip'] ) . '</span>';
			}
			echo '</li>';
			$previous = $hop['when'];
		}
		echo '</ol>';

		echo '</details>';
		echo '</td></tr>';
	}

	/**
	 * The controls and summary above the journeys table.
	 *
	 * @param array  $filters Current filter set.
	 * @param string $ip      Address narrowed to, or empty.
	 * @param array  $extra   View-state arguments.
	 * @param int    $shown   Visits after the single-request test.
	 * @param int    $single  Visits of exactly one request.
	 * @param array  $result  Raw result from get_journeys(), for the window note.
	 * @return void
	 */
	private static function render_journey_controls( $filters, $ip, $extra, $shown, $single, $result ) {
		echo '<div style="margin:1em 0;padding:.8rem 1.2rem;background:#fff;border:1px solid #c3c4c7;">';

		// The toggle is a link rather than a checkbox in a form: it is one binary state, and a link
		// keeps it a bookmarkable URL like every other control on this screen.
		$toggle_extra           = $extra;
		$toggle_extra['single'] = self::current_multi_only() ? '1' : '';
		$toggle_url             = add_query_arg( self::filter_args( $filters, $toggle_extra ), admin_url( 'options-general.php' ) );

		echo '<a href="' . esc_url( $toggle_url ) . '" class="button button-secondary">' . esc_html(
			self::current_multi_only()
				? __( 'Include single-request visits', 'make-my-site-agent-ready' )
				: __( 'Hide single-request visits', 'make-my-site-agent-ready' )
		) . '</a>';

		echo '<p class="description" style="margin:.5rem 0 0;">';
		if ( '' !== $ip ) {
			$clear       = $extra;
			$clear['ip'] = '';
			printf(
				/* translators: 1: an IP address, 2: number of visits */
				esc_html__( 'Showing %1$s and the rest of its network — %2$s visits. The network rather than the single address, because this log stores a caller at two precisions and an exact match would show only half of what it did; callers are still told apart by the name they declare.', 'make-my-site-agent-ready' ),
				'<code>' . esc_html( $ip ) . '</code>',
				'<strong>' . esc_html( number_format_i18n( $shown ) ) . '</strong>'
			);
			echo ' <a href="' . esc_url( add_query_arg( self::filter_args( $filters, $clear ), admin_url( 'options-general.php' ) ) ) . '">'
				. esc_html__( 'Show every address', 'make-my-site-agent-ready' ) . '</a>';
		} else {
			printf(
				/* translators: 1: visits shown, 2: requests they were built from */
				esc_html__( '%1$s visits, built from the most recent %2$s requests that match these filters.', 'make-my-site-agent-ready' ),
				'<strong>' . esc_html( number_format_i18n( $shown ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( (int) $result['rows'] ) ) . '</strong>'
			);
		}

		if ( self::current_multi_only() && $single > 0 ) {
			echo ' ';
			printf(
				/* translators: %s: number of hidden single-request visits */
				esc_html( _n( '%s visit of a single request is hidden.', '%s visits of a single request are hidden.', $single, 'make-my-site-agent-ready' ) ),
				'<strong>' . esc_html( number_format_i18n( $single ) ) . '</strong>'
			);
		}

		if ( ! empty( $result['window_full'] ) ) {
			echo ' ';
			esc_html_e( 'That window is full, so the oldest visit shown may have begun before it and be missing its first requests.', 'make-my-site-agent-ready' );
		}

		echo '</p>';

		// A visit is bounded by silence, and how much silence is a choice the reader should be able
		// to see rather than infer from where the groups happen to fall.
		echo '<p class="description" style="margin:.4rem 0 0;">';
		printf(
			/* translators: %s: a length of time, e.g. "30m" */
			esc_html__( 'A caller going quiet for longer than %s starts a new visit. A visit is one network and one declared agent, so a client that renames itself partway through appears twice — while one that this log records at two precisions, a full address for agent files and a network for page views, stays a single journey.', 'make-my-site-agent-ready' ),
			'<strong>' . esc_html( self::duration_label( (int) apply_filters( 'mmsar_agent_log_visit_gap', MMSAR_Agent_Log::VISIT_GAP ) ) ) . '</strong>'
		);
		echo '</p>';

		echo '</div>';
	}

	/**
	 * A span of seconds as something short enough for a table cell.
	 *
	 * Deliberately not human_time_diff(), which phrases a span relative to now ("2 hours ago") and
	 * rounds to one unit. These are durations, not distances into the past, and the difference
	 * between a nine-second visit and a nine-minute one is the whole point of the column.
	 *
	 * @param int $seconds Span in seconds.
	 * @return string
	 */
	private static function duration_label( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			/* translators: %s: a number of seconds */
			return sprintf( __( '%ss', 'make-my-site-agent-ready' ), number_format_i18n( $seconds ) );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = intdiv( $seconds, 60 );
			$rest    = $seconds % 60;
			if ( 0 === $rest ) {
				/* translators: %s: a number of minutes */
				return sprintf( __( '%sm', 'make-my-site-agent-ready' ), number_format_i18n( $minutes ) );
			}
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$sm %2$ss', 'make-my-site-agent-ready' ), number_format_i18n( $minutes ), number_format_i18n( $rest ) );
		}

		$hours = intdiv( $seconds, HOUR_IN_SECONDS );
		$rest  = intdiv( $seconds % HOUR_IN_SECONDS, 60 );
		if ( 0 === $rest ) {
			/* translators: %s: a number of hours */
			return sprintf( __( '%sh', 'make-my-site-agent-ready' ), number_format_i18n( $hours ) );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$sh %2$sm', 'make-my-site-agent-ready' ), number_format_i18n( $hours ), number_format_i18n( $rest ) );
	}

	/**
	 * The address that best represents a visit.
	 *
	 * The most specific one recorded, which is the full address whenever the visit contains any
	 * request that kept one. That is the value worth showing: it is what verification ran against
	 * and what the reader would act on, where the reduced form names a network rather than a
	 * caller. A visit whose every request was reduced shows the network, because that is all this
	 * log knows about it.
	 *
	 * @param array $visit Visit, from get_journeys().
	 * @return string
	 */
	private static function visit_address( $visit ) {
		$addresses = isset( $visit['addresses'] ) ? (array) $visit['addresses'] : array();
		foreach ( $addresses as $address ) {
			if ( MMSAR_Agent_Log::anonymize_ip( $address ) !== $address ) {
				return (string) $address;
			}
		}
		if ( $addresses ) {
			return (string) $addresses[0];
		}
		return isset( $visit['ip'] ) ? (string) $visit['ip'] : '';
	}

	/**
	 * The IP cell in the list view, linked to that address's journeys.
	 *
	 * The link is the shortest path between the two views and the reason they belong on one screen:
	 * a single interesting row in the list is almost always a question about what else that caller
	 * did, and answering it used to mean exporting the CSV and sorting it by hand.
	 *
	 * An address that will not validate is shown as plain text rather than linked. Every value this
	 * plugin writes validates, including the reduced forms; anything that does not came from
	 * somewhere else, and a link built from it would be a link to nothing.
	 *
	 * @param string $ip      Address as stored.
	 * @param array  $filters Current filter set, carried into the journeys view.
	 * @return string Escaped markup.
	 */
	private static function ip_cell( $ip, $filters ) {
		if ( '' === $ip ) {
			return '<code></code>';
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '<code>' . esc_html( $ip ) . '</code>';
		}

		$url = add_query_arg(
			self::filter_args(
				$filters,
				array(
					'view' => 'journeys',
					'ip'   => $ip,
				)
			),
			admin_url( 'options-general.php' )
		);

		return '<a href="' . esc_url( $url ) . '" title="'
			. esc_attr__( 'Show this address as journeys', 'make-my-site-agent-ready' )
			. '"><code>' . esc_html( $ip ) . '</code></a>';
	}

	/**
	 * The two view tabs.
	 *
	 * Filters are carried across, because they are a statement about which requests are interesting
	 * and that does not stop being true when the shape of the display changes. The address filter
	 * is not: it belongs to the journeys view, which is the only one that reads it, and carrying it
	 * onto a list-view tab that ignores it would show an unnarrowed list under a narrowed URL.
	 *
	 * @param string $view    Current view.
	 * @param array  $filters Current filter set.
	 * @param string $ip      Current address filter.
	 * @return void
	 */
	private static function render_view_tabs( $view, $filters, $ip ) {
		$tabs = array(
			'list'     => __( 'List', 'make-my-site-agent-ready' ),
			'journeys' => __( 'Journeys', 'make-my-site-agent-ready' ),
		);

		echo '<h2 class="nav-tab-wrapper" style="margin-bottom:0;">';
		foreach ( $tabs as $slug => $label ) {
			$args = array( 'view' => 'list' === $slug ? '' : $slug );
			if ( 'journeys' === $slug ) {
				$args['ip'] = $ip;
			}
			$url = add_query_arg( self::filter_args( $filters, $args ), admin_url( 'options-general.php' ) );
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				$slug === $view ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	/**
	 * The verification verdict for one row, as a badge.
	 *
	 * `failed` is the one verdict that has to be unmissable, because it is the only one that says
	 * something was actively misrepresented: this row's caller named a crawler whose operator it
	 * demonstrably is not. The others are shades of "checked" and "no answer", and colouring them
	 * as loudly would bury the finding among them.
	 *
	 * The title attribute carries when the verdict was reached. A verdict is only as good as its
	 * age — reverse-DNS assignments and published ranges both change — so a judgement made ten
	 * days after the request is weaker evidence than one made in the same hour, and a reader
	 * comparing old rows needs to be able to see that gap.
	 *
	 * @param array $entry Log row.
	 * @return string Escaped markup.
	 */
	private static function verdict_badge( $entry ) {
		$verdict = isset( $entry['verified'] ) ? (string) $entry['verified'] : '';
		$label   = MMSAR_Agent_Log_Verify::label( $verdict );

		$styles = array(
			MMSAR_Agent_Log_Verify::VERIFIED     => 'background:#e6f4ea;color:#0a5c2e;border:1px solid #a8d5b8;',
			MMSAR_Agent_Log_Verify::FAILED       => 'background:#fce8e6;color:#8a1c11;border:1px solid #f0a9a2;font-weight:600;',
			MMSAR_Agent_Log_Verify::CLIENT       => 'background:#eef4fb;color:#1d4f7c;border:1px solid #b5cfe8;',
			MMSAR_Agent_Log_Verify::UNVERIFIABLE => 'background:#fff4e5;color:#7a4a00;border:1px solid #f0d0a0;',
			MMSAR_Agent_Log_Verify::NODNS        => 'background:#f0f0f1;color:#50575e;border:1px solid #dcdcde;',
			MMSAR_Agent_Log_Verify::UNCLAIMED    => 'background:transparent;color:#8c8f94;border:1px solid #dcdcde;',
		);
		$style  = isset( $styles[ $verdict ] ) ? $styles[ $verdict ] : 'background:transparent;color:#8c8f94;border:1px dashed #dcdcde;';

		$when  = isset( $entry['verified_at'] ) ? (string) $entry['verified_at'] : '';
		$stamp = $when ? strtotime( $when . ' UTC' ) : 0;
		$title = $stamp
			/* translators: %s: date and time the verdict was reached */
			? sprintf( __( 'Checked %s', 'make-my-site-agent-ready' ), wp_date( 'Y-m-d H:i', $stamp ) )
			: __( 'Not checked yet', 'make-my-site-agent-ready' );

		return '<span title="' . esc_attr( $title ) . '" style="' . esc_attr( $style ) . 'display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;white-space:nowrap;">'
			. esc_html( $label ) . '</span>';
	}

	/**
	 * The crawler-identity panel.
	 *
	 * Laid out as a headline, a row of counts and a short line per idea, rather than as consecutive
	 * paragraphs. The earlier version said the same things and read as a block of text, so nothing
	 * in it stood out, including the one number that should.
	 *
	 * @return void
	 */
	private static function render_verification_panel() {
		$summary = MMSAR_Agent_Log::get_verification_summary();
		$counts  = $summary['counts'];
		$pending = (int) $summary['pending'];
		$failed  = isset( $counts[ MMSAR_Agent_Log_Verify::FAILED ] ) ? (int) $counts[ MMSAR_Agent_Log_Verify::FAILED ] : 0;

		$accent = $failed > 0 ? '#d63638' : '#72aee6';
		echo '<div style="margin:1em 0;background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . esc_attr( $accent ) . ';">';

		echo '<div style="padding:.9rem 1.1rem .2rem;">';
		echo '<h2 style="margin:0 0 .4rem;font-size:1rem;">' . esc_html__( 'Crawler identity', 'make-my-site-agent-ready' ) . '</h2>';

		if ( $failed > 0 ) {
			echo '<p style="margin:0 0 .2rem;font-size:1.05rem;">';
			printf(
				/* translators: %s: number of entries */
				esc_html( _n( '%s entry claimed an AI crawler it is not.', '%s entries claimed an AI crawler they are not.', $failed, 'make-my-site-agent-ready' ) ),
				'<strong style="color:#b3261e;">' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
			);
			echo '</p>';
			echo '<p class="description" style="margin:0 0 .6rem;">' . esc_html__( 'The user-agent was forged: the address does not belong to the operator it named.', 'make-my-site-agent-ready' ) . '</p>';
		} else {
			echo '<p style="margin:0 0 .6rem;">' . esc_html__( 'No forged crawler identities found so far.', 'make-my-site-agent-ready' ) . '</p>';
		}
		echo '</div>';

		// The counts as a row of tiles. Same numbers, but each one readable on its own.
		$tiles = array();
		foreach ( MMSAR_Agent_Log_Verify::verdicts() as $verdict ) {
			$tiles[ MMSAR_Agent_Log_Verify::label( $verdict ) ] = isset( $counts[ $verdict ] ) ? (int) $counts[ $verdict ] : 0;
		}
		if ( $pending > 0 ) {
			$tiles[ MMSAR_Agent_Log_Verify::label( MMSAR_Agent_Log_Verify::PENDING ) ] = $pending;
		}
		echo '<div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid #f0f0f1;">';
		foreach ( $tiles as $label => $count ) {
			echo '<div style="flex:1 1 7rem;padding:.6rem 1.1rem;border-right:1px solid #f0f0f1;">';
			echo '<div style="font-size:1.25rem;font-weight:600;line-height:1.2;">' . esc_html( number_format_i18n( $count ) ) . '</div>';
			echo '<div class="description" style="font-size:.78rem;">' . esc_html( $label ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<div style="padding:.7rem 1.1rem 1rem;border-top:1px solid #f0f0f1;">';

		// One idea per line, each short enough to scan.
		$notes   = array();
		$notes[] = __( 'Unverifiable and No DNS mean this plugin had no way to check, not that the caller was suspicious.', 'make-my-site-agent-ready' );
		$notes[] = __( 'No DNS entries retry themselves within a day. Verified and Spoofed are never re-checked.', 'make-my-site-agent-ready' );
		$notes[] = __( 'User-run client means software running on a person\'s own machine, such as Claude Code fetching as Claude-User. It is real agent traffic that no published method can confirm, and its address is stored at network level.', 'make-my-site-agent-ready' );

		// Rows written before 1.47.0 stored every Claude-User request under the bare name, so Claude
		// Code sessions from that period read as Spoofed and cannot be told apart now. Said here,
		// beside the number it qualifies, rather than left for a reader to discover.
		$bare_claude_failed = MMSAR_Agent_Log::count_verdict_for_agent( 'Claude-User', MMSAR_Agent_Log_Verify::FAILED );
		if ( $bare_claude_failed > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of entries */
				__( '%s Claude-User entries read Spoofed. Any logged before version 1.47.0 may be Claude Code running on someone\'s own machine: earlier versions could not tell the two apart, and the evidence was not stored.', 'make-my-site-agent-ready' ),
				number_format_i18n( $bare_claude_failed )
			);
		}

		$uncheckable = MMSAR_Agent_Log::get_uncheckable_agents();
		if ( $uncheckable ) {
			$notes[] = sprintf(
				/* translators: 1: number of entries, 2: comma-separated crawler names */
				__( '%1$s entries can never be confirmed, because no operator publishes a way to check them (%2$s).', 'make-my-site-agent-ready' ),
				number_format_i18n( array_sum( $uncheckable ) ),
				implode( ', ', array_keys( $uncheckable ) )
			);
		}

		$captured = MMSAR_Agent_Log_Verify::ranges_captured();
		if ( $captured ) {
			$notes[] = sprintf(
				/* translators: %s: capture date of the bundled IP ranges */
				__( 'Anthropic, OpenAI, Perplexity and DuckDuckGo publish no reverse-DNS for their crawlers, so those are checked against published IP ranges bundled with the plugin (captured %s).', 'make-my-site-agent-ready' ),
				$captured
			);
		}

		echo '<ul style="margin:0 0 .8rem;list-style:disc;padding-left:1.2rem;" class="description">';
		foreach ( $notes as $note ) {
			echo '<li style="margin:0 0 .2rem;">' . esc_html( $note ) . '</li>';
		}
		echo '</ul>';

		$recheckable = MMSAR_Agent_Log::count_recheckable();
		if ( $pending > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 .5rem 0 0;">';
			echo '<input type="hidden" name="action" value="mmsar_verify_agent_log">';
			wp_nonce_field( 'mmsar_verify_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Verify now', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		if ( $recheckable > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
			echo '<input type="hidden" name="action" value="mmsar_recheck_agent_log">';
			wp_nonce_field( 'mmsar_recheck_agent_log', 'mmsar_nonce' );
			submit_button(
				sprintf(
					/* translators: %s: number of entries that could be answered differently */
					__( 'Re-check %s', 'make-my-site-agent-ready' ),
					number_format_i18n( $recheckable )
				),
				'secondary',
				'submit',
				false
			);
			echo '</form>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * The retention control and the export buttons.
	 *
	 * Clear is deliberately not here any more. It used to sit inches from Export with no
	 * confirmation of any kind, so a single mis-click destroyed the whole table. It now lives at the
	 * very bottom of the screen, past the data, behind a typed confirmation.
	 *
	 * @param int   $total   Entries in the whole log.
	 * @param array $filters Current filter set, carried into the export so it can mirror the view.
	 * @param int   $shown   Entries the current filter matches.
	 * @return void
	 */
	private static function render_retention_form( $total, $filters = array(), $shown = 0 ) {
		$limit = MMSAR_Agent_Log::get_limit();

		echo '<form method="post" action="options.php" style="margin:1em 0;">';
		settings_fields( 'mmsar_agent_log_group' );
		echo '<label for="mmsar-log-limit"><strong>' . esc_html__( 'Entries to keep', 'make-my-site-agent-ready' ) . '</strong></label> ';
		echo '<input type="number" min="0" step="1" id="mmsar-log-limit" name="' . esc_attr( MMSAR_Agent_Log::LIMIT_OPTION ) . '" value="' . esc_attr( (string) $limit ) . '" class="small-text"> ';
		submit_button( __( 'Save', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
		echo '<p class="description">';
		esc_html_e( '0 keeps everything, which is the default and the right setting if the log is being used to answer a question about agent behaviour over time. Set a number to have the oldest entries dropped once the log passes it; entries are trimmed periodically rather than on every request, so the count can sit slightly above the limit between trims.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '</form>';

		if ( $total < 1 ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 1.5em;">';
		echo '<input type="hidden" name="action" value="mmsar_export_agent_log">';
		wp_nonce_field( 'mmsar_export_agent_log', 'mmsar_nonce' );

		// The active filters ride along so "this view" means what the screen is showing.
		foreach ( array(
			'verdict' => 'verdicts',
			'client'  => 'clients',
			'surface' => 'categories',
			'crawler' => 'crawlers',
		) as $arg => $key ) {
			foreach ( ( $filters[ $key ] ?? array() ) as $value ) {
				printf( '<input type="hidden" name="%1$s[]" value="%2$s">', esc_attr( $arg ), esc_attr( $value ) );
			}
		}

		if ( self::filters_active( $filters ) ) {
			printf(
				'<button type="submit" name="mmsar_export_scope" value="view" class="button button-secondary">%s</button> ',
				esc_html(
					sprintf(
						/* translators: %s: number of entries in the current view */
						__( 'Export this view (%s)', 'make-my-site-agent-ready' ),
						number_format_i18n( $shown )
					)
				)
			);
		}
		printf(
			'<button type="submit" name="mmsar_export_scope" value="all" class="button button-secondary">%s</button>',
			esc_html(
				sprintf(
					/* translators: %s: total number of entries */
					__( 'Export everything (%s)', 'make-my-site-agent-ready' ),
					number_format_i18n( $total )
				)
			)
		);
		echo '<p class="description" style="margin-top:.4rem;">';
		esc_html_e( 'CSV, timestamps in UTC. "Everything" includes browser page views even when the screen is hiding them.', 'make-my-site-agent-ready' );
		echo '</p>';
		echo '</form>';
	}

	/**
	 * The clear control, at the very bottom and behind a typed confirmation.
	 *
	 * Two gates rather than one. A browser confirm() is easy to dismiss by reflex, and this log is
	 * now months of data that cannot be recovered, so the word has to be typed and the submit button
	 * is disabled until it matches. The confirm() is a second line for anyone who types it and then
	 * changes their mind.
	 *
	 * @param int $total Entries in the log.
	 * @return void
	 */
	private static function render_clear_form( $total ) {
		if ( $total < 1 ) {
			return;
		}

		echo '<div style="margin:3em 0 1em;padding:1rem 1.2rem;border:1px solid #d63638;border-left-width:4px;background:#fff;max-width:44rem;">';
		echo '<h2 style="margin:0 0 .3rem;font-size:1rem;color:#b3261e;">' . esc_html__( 'Delete the log', 'make-my-site-agent-ready' ) . '</h2>';
		echo '<p class="description" style="margin:0 0 .8rem;">';
		printf(
			/* translators: %s: number of entries */
			esc_html__( 'Permanently deletes all %s entries. There is no undo and no backup. Export first if there is any chance you will want this data.', 'make-my-site-agent-ready' ),
			'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
		);
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="mmsar-clear-form">';
		echo '<input type="hidden" name="action" value="mmsar_clear_agent_log">';
		wp_nonce_field( 'mmsar_clear_agent_log', 'mmsar_nonce' );
		echo '<label for="mmsar-clear-confirm">';
		printf(
			/* translators: %s: the word that must be typed, already translated */
			esc_html__( 'Type %s to enable the button:', 'make-my-site-agent-ready' ),
			'<code>' . esc_html_x( 'DELETE', 'confirmation word typed to clear the agent log', 'make-my-site-agent-ready' ) . '</code>'
		);
		echo '</label> ';
		echo '<input type="text" id="mmsar-clear-confirm" name="mmsar_clear_confirm" value="" autocomplete="off" class="regular-text" style="width:9rem;"> ';
		echo '<button type="submit" id="mmsar-clear-submit" class="button button-link-delete" disabled>' . esc_html__( 'Delete all entries', 'make-my-site-agent-ready' ) . '</button>';
		echo '</form>';
		echo '</div>';

		// Admin-only inline script. The server checks the typed word too, so this is convenience
		// rather than the guard.
		?>
		<script>
		( function () {
			var word  = <?php echo wp_json_encode( _x( 'DELETE', 'confirmation word typed to clear the agent log', 'make-my-site-agent-ready' ) ); ?>;
			var msg   = <?php echo wp_json_encode( __( 'This permanently deletes every entry in the agent log. There is no undo. Continue?', 'make-my-site-agent-ready' ) ); ?>;
			var input = document.getElementById( 'mmsar-clear-confirm' );
			var btn   = document.getElementById( 'mmsar-clear-submit' );
			var form  = document.getElementById( 'mmsar-clear-form' );
			if ( ! input || ! btn || ! form ) { return; }
			input.addEventListener( 'input', function () {
				btn.disabled = ( input.value.trim() !== word );
			} );
			form.addEventListener( 'submit', function ( e ) {
				if ( input.value.trim() !== word || ! window.confirm( msg ) ) { e.preventDefault(); }
			} );
		} )();
		</script>
		<?php
	}
}
