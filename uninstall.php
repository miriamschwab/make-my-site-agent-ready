<?php
/**
 * Uninstall handler for Make My Site Agent-Ready.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'llmmd_settings' );
delete_option( 'llmmd_write_abilities' );
delete_option( 'llmmd_version' );
delete_option( 'mmsar_security_txt' );
delete_option( 'mmsar_security_txt_contact' );
delete_option( 'mmsar_robots_txt_extra' );
delete_option( 'mmsar_content_signals' );
delete_option( 'mmsar_structured_data' );
delete_option( 'mmsar_features' );
delete_option( 'mmsar_endpoints' );
delete_option( 'mmsar_agent_log' );
delete_option( 'mmsar_agent_log_limit' );
delete_option( 'mmsar_agent_log_db_version' );
delete_option( 'mmsar_agent_log_migrated' );
delete_option( 'mmsar_agent_log_pages' );
delete_option( 'mmsar_negotiation_check' );
delete_option( 'mmsar_negotiation_reset_done' );
delete_option( 'mmsar_indexnow_key' );
delete_option( 'mmsar_indexnow_last' );
delete_option( 'mmsar_referrals' );
delete_option( 'mmsar_referrals_db_version' );
delete_option( 'mmsar_decline_training' );
delete_option( 'mmsar_decline_training_since' );
delete_transient( 'llmmd_llms_txt' );
delete_transient( 'mmsar_llms_full_txt' );
delete_transient( 'mmsar_flush_needed' );
delete_transient( 'mmsar_negotiation_check_pending' );
delete_transient( 'mmsar_negotiation_check_notice' );

global $wpdb;

// The agent log's per-request transients: the record() throttle (mmsar_al_) and the identity
// verdicts cached against an IP (mmsar_alv_, added 1.24.0). Both are keyed by a hash, so there is
// no finite list of names to delete and delete_transient() cannot reach them; a LIKE sweep is the
// only way. Options table only — with a persistent object cache these live in memory and expire on
// their own TTL, which is why this is best-effort rather than exhaustive.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing this plugin's own hash-keyed transients on uninstall; no core API deletes options by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mmsar_al_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mmsar_al_' ) . '%',
		$wpdb->esc_like( '_transient_mmsar_alv_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mmsar_alv_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing this plugin's own cached markdown post meta on uninstall. No core API deletes meta by key across all posts, and caching is meaningless for a one-time delete.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_llmmd_content' ) );

// The agent log's own table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mmsar_agent_log' ) );

// The AI referral counts' own table (1.55.0). Holds counts only, no visitor data.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mmsar_referrals' ) );
