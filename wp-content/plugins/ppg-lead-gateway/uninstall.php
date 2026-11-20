<?php
/** Removes everything this plugin stored in the database when it is deleted from the Plugins screen. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ppg_poll_status', 'ppg_poll_daily', 'ppg_poll_log', 'ppg_poll_totals', 'ppg_poll_lock', 'ppg_seen', 'ppg_lead_review' ) as $ppg_option ) {
	delete_option( $ppg_option );
}
foreach ( array( 'ppg_hs_down', 'ppg_upsert_unsupported', 'ppg_no_go_live_logged', 'ppg_lead_sources_options' ) as $ppg_transient ) {
	delete_transient( $ppg_transient );
}
wp_clear_scheduled_hook( 'ppg_poll_mailbox' );
