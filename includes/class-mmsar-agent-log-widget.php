<?php
/**
 * Dashboard widget for the agent log: what has been hitting the site, and what still needs an
 * identity check.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR agent log dashboard widget.
 *
 * One widget, deliberately. 1.29.0 added a second one for the identity-check backlog and its
 * buttons, which was a mistake: the two overlapped on three things — the forged-identity count, the
 * "log is switched off" notice and the link through to the full log — so a dashboard with both
 * showed the same facts twice and made the reader work out whether they were the same facts. They
 * are also read in one glance and answer one question, which is the test for whether something is
 * one widget or two.
 *
 * The surviving widget keeps the **`mmsar_agent_log` id** from before either change. Dashboard
 * widget order and visibility are stored per user against that id, so reusing it means an existing
 * install keeps whatever position and Screen Options state it already had. The 1.29.x
 * `mmsar_agent_verify` id is simply gone; a stale entry for it in a user's meta is inert.
 *
 * **Nothing here verifies anything on render, and it must stay that way.** Identity checks make DNS
 * lookups, and `gethostbyaddr()` has no timeout PHP can set and can block for seconds. The standing
 * rule is that they run from `manage_options`-gated call sites only, never on a schedule and never
 * in a path that serves content — and the dashboard is the first screen an administrator opens, so
 * resolving a trickle of addresses on every load would be the worst place of the three to do it.
 * This widget only *counts*, with plain grouped queries against the plugin's own table, and its
 * buttons post to MMSAR_Agent_Log_Page's existing gated handlers. It is not a verification call
 * site.
 */
class MMSAR_Agent_Log_Widget {

	/**
	 * How many recent entries the widget lists.
	 */
	const ENTRIES = 20;

	/**
	 * How many claimed crawlers the pending backlog names before summarising the rest.
	 */
	const BREAKDOWN = 3;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the widget.
	 *
	 * No setting controls whether it appears: WordPress already lets each user hide a dashboard
	 * widget from Screen Options, and adding a second switch for the same job would mean two places
	 * to look when it is not showing.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'mmsar_agent_log',
			__( 'Agent Log', 'make-my-site-agent-ready' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Renders the widget.
	 *
	 * Ordered by how much each part changes what the reader does: the result of a button they just
	 * pressed, then the one finding that reframes every other number on the screen, then anything
	 * waiting on a decision, and only then the list of what has been arriving. The list is the
	 * biggest block and the least urgent, so it goes last.
	 *
	 * @return void
	 */
	public static function render() {
		self::render_result_notice();

		$total = MMSAR_Agent_Log::count_entries();
		$is_on = MMSAR_Agent_Log::is_active();

		if ( $total < 1 ) {
			// Only when logging is on. With it off, render_off_note() below already says so, and
			// saying it twice is the duplication this widget was merged to remove.
			if ( $is_on ) {
				echo '<p>' . esc_html__( 'Nothing recorded yet. Agent traffic is intermittent, so leave the log on and check back.', 'make-my-site-agent-ready' ) . '</p>';
			}
			self::render_off_note( $is_on, $total );
			self::render_log_link();
			return;
		}

		$summary     = MMSAR_Agent_Log::get_verification_summary();
		$pending     = (int) $summary['pending'];
		$recheckable = MMSAR_Agent_Log::count_recheckable();
		$failed      = isset( $summary['counts'][ MMSAR_Agent_Log_Verify::FAILED ] )
			? (int) $summary['counts'][ MMSAR_Agent_Log_Verify::FAILED ]
			: 0;

		self::render_top_insight();

		// On any site running this, a non-zero forged count is the headline finding — it is the one
		// thing here that changes how every other number should be read. Shown before the entries
		// rather than as a column on them, because it is a fact about the whole log.
		if ( $failed > 0 ) {
			echo '<p style="margin:0 0 8px;padding:6px 8px;background:#fce8e6;border-left:3px solid #d63638;">';
			printf(
				/* translators: %s: number of entries */
				esc_html( _n( '%s entry forged an AI crawler identity.', '%s entries forged an AI crawler identity.', $failed, 'make-my-site-agent-ready' ) ),
				'<strong>' . esc_html( number_format_i18n( $failed ) ) . '</strong>'
			);
			echo '</p>';
		}

		self::render_identity_section( $pending, $recheckable );
		self::render_entries();
		self::render_off_note( $is_on, $total );
		self::render_log_link();
	}

	/**
	 * The identity-check state, and the buttons that act on it.
	 *
	 * @param int $pending     Rows never checked.
	 * @param int $recheckable Rows whose verdict could now come out differently.
	 * @return void
	 */
	private static function render_identity_section( $pending, $recheckable ) {
		if ( $pending < 1 && $recheckable < 1 ) {
			echo '<p style="margin:0 0 8px;color:#646970;">' . esc_html__( 'Every logged request has been identity-checked.', 'make-my-site-agent-ready' ) . '</p>';
			return;
		}

		if ( $pending > 0 ) {
			echo '<p style="margin:0 0 4px;">';
			printf(
				/* translators: %s: number of entries */
				esc_html( _n( '%s request has not been identity-checked yet.', '%s requests have not been identity-checked yet.', $pending, 'make-my-site-agent-ready' ) ),
				'<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>'
			);
			echo '</p>';
			self::render_breakdown( $pending );
		}

		// A div, not a p: a paragraph cannot contain a form, and a browser silently closes the
		// paragraph at the first one rather than nesting it.
		echo '<div style="margin:0 0 10px;">';

		if ( $pending > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 4px 0;">';
			echo '<input type="hidden" name="action" value="mmsar_verify_agent_log">';
			echo '<input type="hidden" name="mmsar_return" value="dashboard">';
			wp_nonce_field( 'mmsar_verify_agent_log', 'mmsar_nonce' );
			submit_button( __( 'Verify now', 'make-my-site-agent-ready' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		if ( $recheckable > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 0 4px;">';
			echo '<input type="hidden" name="action" value="mmsar_recheck_agent_log">';
			echo '<input type="hidden" name="mmsar_return" value="dashboard">';
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
	}

	/**
	 * The pending count broken down by the crawler each entry claimed to be.
	 *
	 * Inline and comma-joined rather than a list. In its own widget this was a table of five rows,
	 * which does not survive being merged above a twenty-row list of entries — the whole point of
	 * one widget is that it is read in a glance.
	 *
	 * @param int $pending Total pending rows.
	 * @return void
	 */
	private static function render_breakdown( $pending ) {
		$by_agent = MMSAR_Agent_Log::pending_by_agent( self::BREAKDOWN );
		if ( ! $by_agent ) {
			return;
		}

		$parts = array();
		foreach ( $by_agent as $agent => $count ) {
			$parts[] = sprintf(
				/* translators: 1: crawler name, 2: number of entries */
				__( '%1$s %2$s', 'make-my-site-agent-ready' ),
				$agent,
				number_format_i18n( $count )
			);
		}

		// Only ever a shortfall, never negative: pending_by_agent() returns the largest groups.
		$listed = array_sum( $by_agent );
		if ( $pending > $listed ) {
			$parts[] = sprintf(
				/* translators: %s: number of entries not named individually */
				__( '+%s more', 'make-my-site-agent-ready' ),
				number_format_i18n( $pending - $listed )
			);
		}

		echo '<p class="description" style="margin:0 0 8px;">' . esc_html( implode( ', ', $parts ) ) . '</p>';
	}

	/**
	 * The list of recent entries.
	 *
	 * @return void
	 */
	private static function render_entries() {
		$entries = MMSAR_Agent_Log::get_entries( self::ENTRIES, 0 );
		if ( empty( $entries ) ) {
			// Reachable with a non-empty log: the default view excludes browser page views, so a
			// log holding nothing else has rows but nothing to list here.
			echo '<p style="color:#646970;">' . esc_html__( 'No agent requests to list — everything recorded so far came from a browser.', 'make-my-site-agent-ready' ) . '</p>';
			return;
		}

		echo '<table style="width:100%;border-collapse:collapse;">';
		echo '<tbody>';

		foreach ( $entries as $entry ) {
			// Stored in UTC. Shown as elapsed time, which is what "is anything hitting the site
			// right now" needs — an exact timestamp is a click away on the full log.
			$stamp = isset( $entry['logged_at'] ) ? strtotime( $entry['logged_at'] . ' UTC' ) : 0;
			$when  = $stamp
				? sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 mins" */
					__( '%s ago', 'make-my-site-agent-ready' ),
					human_time_diff( $stamp )
				)
				: '—';

			echo '<tr style="border-bottom:1px solid #f0f0f1;">';
			echo '<td style="padding:6px 8px 6px 0;vertical-align:top;">';
			echo '<strong>' . esc_html( isset( $entry['agent'] ) ? $entry['agent'] : '—' ) . '</strong>';
			$surface = isset( $entry['surface'] ) ? (string) $entry['surface'] : '';
			$detail  = isset( $entry['detail'] ) ? (string) $entry['detail'] : '';
			if ( '' !== $detail ) {
				$surface .= ' — ' . $detail;
			}
			if ( isset( $entry['verified'] ) && MMSAR_Agent_Log_Verify::FAILED === $entry['verified'] ) {
				echo ' <span style="color:#8a1c11;font-size:11px;">' . esc_html__( '(spoofed)', 'make-my-site-agent-ready' ) . '</span>';
			}
			echo '<br><span style="color:#646970;">' . esc_html( $surface ) . '</span>';
			echo '</td>';
			echo '<td style="padding:6px 0;vertical-align:top;text-align:right;white-space:nowrap;color:#646970;">';
			echo esc_html( $when );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The "log is switched off" note.
	 *
	 * Says what switching it off did and did not do. The person most likely to assume it deleted
	 * something is the person who has just done it, and the count is the reassurance — the same
	 * wording the Agent Log screen carries.
	 *
	 * @param bool $is_on Whether logging is active.
	 * @param int  $total Entries in the log.
	 * @return void
	 */
	private static function render_off_note( $is_on, $total ) {
		if ( $is_on ) {
			return;
		}

		echo '<p class="description" style="margin:10px 0 0;">';
		printf(
			/* translators: %s: link to the plugin settings page */
			esc_html__( 'The log is switched off, so nothing new is being recorded. Turn it on under %s.', 'make-my-site-agent-ready' ),
			'<a href="' . esc_url( admin_url( 'options-general.php?page=make-my-site-agent-ready' ) ) . '">' . esc_html__( 'Settings > Agent-Ready', 'make-my-site-agent-ready' ) . '</a>'
		);
		if ( $total > 0 ) {
			echo ' ';
			printf(
				/* translators: %s: number of entries already recorded */
				esc_html( _n( 'The %s entry already recorded is kept, and can still be checked from here.', 'The %s entries already recorded are kept, and can still be checked from here.', $total, 'make-my-site-agent-ready' ) ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
		} else {
			echo ' ';
			esc_html_e( 'Nothing has been recorded.', 'make-my-site-agent-ready' );
		}
		echo '</p>';
	}

	/**
	 * The notice shown after a pass started from this widget.
	 *
	 * Same query args the log screen reads, because it is the same handler — the only difference is
	 * which screen it redirected to. Rendered inside the widget rather than as an admin notice: the
	 * button was pressed here, so the answer belongs here.
	 *
	 * @return void
	 */
	private static function render_result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_verified'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$rows = absint( wp_unslash( $_GET['mmsar_verified'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$ips = isset( $_GET['mmsar_verified_ips'] ) ? absint( wp_unslash( $_GET['mmsar_verified_ips'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$finished = isset( $_GET['mmsar_verify_done'] ) && '1' === $_GET['mmsar_verify_done'];

			echo '<p style="margin:0 0 8px;padding:6px 8px;background:#f0f6fc;border-left:3px solid #72aee6;">';
			printf(
				/* translators: 1: number of entries, 2: number of IP addresses */
				esc_html__( 'Checked %1$s entries across %2$s addresses.', 'make-my-site-agent-ready' ),
				esc_html( number_format_i18n( $rows ) ),
				esc_html( number_format_i18n( $ips ) )
			);
			echo ' ';
			echo $finished
				? esc_html__( 'Nothing is left unchecked.', 'make-my-site-agent-ready' )
				: esc_html__( 'More are still unchecked — press it again to continue.', 'make-my-site-agent-ready' );
			echo '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked redirect.
		if ( isset( $_GET['mmsar_rechecked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$reopened = absint( wp_unslash( $_GET['mmsar_rechecked'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$changed = isset( $_GET['mmsar_changed'] ) ? absint( wp_unslash( $_GET['mmsar_changed'] ) ) : 0;

			echo '<p style="margin:0 0 8px;padding:6px 8px;background:#f0f6fc;border-left:3px solid #72aee6;">';
			if ( $changed > 0 ) {
				printf(
					/* translators: 1: number of entries re-examined, 2: number whose verdict changed */
					esc_html__( 'Re-checked %1$s entries. %2$s now have a different verdict.', 'make-my-site-agent-ready' ),
					esc_html( number_format_i18n( $reopened ) ),
					esc_html( number_format_i18n( $changed ) )
				);
			} else {
				printf(
					/* translators: %s: number of entries re-examined */
					esc_html__( 'Re-checked %s entries. Every one reached the same verdict as before, so nothing changed.', 'make-my-site-agent-ready' ),
					esc_html( number_format_i18n( $reopened ) )
				);
			}
			echo '</p>';
		}
	}

	/**
	 * The one Summary finding most worth seeing, with a link to the rest.
	 *
	 * Something to do beats something to know, and a finding about being cited beats one about
	 * being read, because that is the order an owner cares in. Reads the Summary's hourly cache, so
	 * the dashboard costs a cache read on most loads and never a DNS lookup.
	 *
	 * @return void
	 */
	private static function render_top_insight() {
		$insights = MMSAR_Agent_Insights::get();
		$order    = array( 'cited', 'broken', 'read' );
		$pick     = null;
		foreach ( array( 'todo', 'meaning' ) as $kind ) {
			foreach ( $order as $question ) {
				foreach ( (array) ( $insights['questions'][ $question ] ?? array() ) as $finding ) {
					if ( $kind === $finding['kind'] ) {
						$pick = $finding;
						break 3;
					}
				}
			}
		}
		if ( null === $pick ) {
			return;
		}
		echo '<p style="margin:0 0 8px;padding:6px 8px;background:#f0f6fc;border-left:3px solid ' . esc_attr( 'todo' === $pick['kind'] ? '#dba617' : '#2271b1' ) . ';">';
		echo '<strong>' . esc_html( $pick['title'] ) . '.</strong> ' . esc_html( $pick['text'] ) . ' ';
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=' . MMSAR_Agent_Log_Page::SLUG . '&view=summary' ) ),
			esc_html__( 'See the summary', 'make-my-site-agent-ready' )
		);
		echo '</p>';
	}

	/**
	 * The link through to the full log.
	 *
	 * @return void
	 */
	private static function render_log_link() {
		printf(
			'<p style="margin:10px 0 0;"><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'options-general.php?page=' . MMSAR_Agent_Log_Page::SLUG ) ),
			esc_html__( 'View the full log', 'make-my-site-agent-ready' )
		);
	}
}
