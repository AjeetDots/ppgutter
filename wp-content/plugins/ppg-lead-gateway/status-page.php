<?php
/**
 * Tools > PPG Lead Status: shows whether the plugin is set up and whether cron is really firing, what each run
 * did, and has buttons to run the poll once, test the mailbox login and test the HubSpot token. Also: wp ppg poll
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'PPG Lead Status', 'PPG Lead Status', 'manage_options', 'ppg-lead-status', 'ppg_status_page' );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ppg poll', function () {
		ppg_poll_mailbox();
		WP_CLI::line( wp_json_encode( get_option( 'ppg_poll_status' ) ) );
		WP_CLI::success( 'Poll finished.' );
	} );
}

/** "5 minutes ago", "never" ... */
function ppg_status_ago( $ts, $now = null ) {
	if ( ! $ts ) {
		return 'never';
	}
	$d = ( $now ?? time() ) - (int) $ts;
	if ( $d < 0 ) {
		return 'in ' . max( 1, round( -$d / 60 ) ) . ' minutes';
	}
	if ( $d < 90 ) {
		return $d . ' seconds ago';
	}
	if ( $d < 5400 ) {
		return round( $d / 60 ) . ' minutes ago';
	}
	if ( $d < 172800 ) {
		return round( $d / 3600 ) . ' hours ago';
	}
	return round( $d / 86400 ) . ' days ago';
}

/** @return array<int, array{0:string,1:string,2:string}> label, state (ok|warn|bad), detail */
function ppg_status_checks() {
	$c   = array();
	$now = time();

	$imap = function_exists( 'imap_open' );
	$c[]  = array( 'PHP imap extension', $imap ? 'ok' : 'bad', $imap ? 'enabled' : 'NOT enabled: switch it on in the hosting PHP extensions, otherwise no email can be read' );

	$host = (string) ppg_lead_cfg( 'PPG_IMAP_HOST' );
	$user = (string) ppg_lead_cfg( 'PPG_IMAP_USER' );
	$pass = (string) ppg_lead_cfg( 'PPG_IMAP_PASS' );
	if ( '' === $host || '' === $user || '' === $pass ) {
		$c[] = array( 'Mailbox settings', 'bad', 'PPG_IMAP_HOST, PPG_IMAP_USER and PPG_IMAP_PASS must all be set in wp-config.php' );
	} else {
		$folder = false !== strpos( $host, '}' ) ? substr( $host, strpos( $host, '}' ) + 1 ) : $host;
		$c[]    = array( 'Mailbox settings', false !== stripos( $folder, 'INBOX' ) ? 'warn' : 'ok', $user . ' / folder "' . $folder . '"' . ( false !== stripos( $folder, 'INBOX' ) ? ' (INBOX misses archived mail: use [Gmail]/All Mail)' : '' ) );
	}

	$c[] = array( 'HubSpot token', '' !== (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' ) ? 'ok' : 'bad', '' !== (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' ) ? 'set (use the button below to test it)' : 'PPG_HUBSPOT_TOKEN is not set' );

	$secret = (string) ppg_lead_cfg( 'PPG_LEAD_SECRET' );
	$weak   = '' === $secret || 0 === stripos( $secret, 'CHANGE-ME' ) || strlen( $secret ) < 24;
	$c[]    = array( 'Zapier/lead secret', $weak ? 'bad' : 'ok', $weak ? 'PPG_LEAD_SECRET is missing, a placeholder or shorter than 24 characters' : 'set' );

	$go = ppg_go_live_timestamp();
	if ( ! $go ) {
		$c[] = array( 'Go-live (PPG_GO_LIVE)', 'warn', 'not set: importing is OFF (the safe default). Set it to the time the import should start' );
	} else {
		$c[] = array( 'Go-live (PPG_GO_LIVE)', $go > $now ? 'warn' : 'ok', gmdate( 'Y-m-d H:i', $go ) . ' UTC' . ( $go > $now ? ' (in the future: nothing imports until then)' : ': only emails received after this are imported' ) );
	}

	$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
	$c[] = array( 'Environment type', 'ok', $env . ( 'production' === $env ? '' : ' (Checkatrade test IPs are accepted)' ) );

	$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$c[]      = array( 'WP-Cron trigger', $disabled ? 'warn' : 'ok', $disabled ? 'DISABLE_WP_CRON is on: a real server cron job MUST call wp-cron.php every 5 minutes' : 'runs when someone visits the site (add a real server cron job for reliability)' );

	$next = wp_next_scheduled( 'ppg_poll_mailbox' );
	if ( ! $next ) {
		$c[] = array( 'Scheduled event', 'bad', 'ppg_poll_mailbox is not scheduled: load any page of the site, or deactivate and reactivate the plugin' );
	} else {
		$next = (int) $next;
		$c[]  = array( 'Scheduled event', $now - $next > 15 * MINUTE_IN_SECONDS ? 'bad' : 'ok', $next > $now ? 'next run due ' . gmdate( 'H:i:s', $next ) . ' UTC' : 'overdue by ' . round( ( $now - $next ) / 60 ) . ' min' . ( $now - $next > 15 * MINUTE_IN_SECONDS ? ': cron is not firing' : '' ) );
	}

	$last = get_option( 'ppg_poll_status' );
	if ( ! is_array( $last ) || empty( $last['finished'] ) ) {
		$c[] = array( 'Last poll', 'bad', 'it has not run yet (give cron up to 5 minutes, or press "Run poll now")' );
	} else {
		$stale = $now - (int) $last['finished'] > 15 * MINUTE_IN_SECONDS;
		$good  = in_array( $last['outcome'], array( 'ok', 'no_go_live' ), true );
		$c[]   = array( 'Last poll', $stale ? 'bad' : ( 'ok' === $last['outcome'] ? 'ok' : 'warn' ), ppg_status_ago( (int) $last['finished'] ) . ' - ' . $last['outcome'] . ( $stale ? ' - TOO OLD: cron is not firing every 5 minutes' : '' ) . ( ! $good && $last['detail'] ? ': ' . $last['detail'] : '' ) );
	}

	$held = count( (array) get_option( 'ppg_lead_review', array() ) );
	$c[]  = array( 'Review queue', $held ? 'warn' : 'ok', $held ? $held . ' lead(s) held (Tools > PPG Lead Review)' : 'empty' );
	$c[]  = array( 'Checkatrade webhook', 'ok', ppg_lead_cfg( 'PPG_ENABLE_CHECKATRADE_WEBHOOK' ) ? 'enabled: /wp-json/ppg/v1/checkatrade' : 'off (email route only)' );
	return $c;
}

/** @return array{0:bool,1:string} */
function ppg_status_test_mailbox() {
	if ( ! function_exists( 'imap_open' ) ) {
		return array( false, 'The PHP imap extension is not enabled.' );
	}
	$host = (string) ppg_lead_cfg( 'PPG_IMAP_HOST' );
	$imap = @imap_open( $host, (string) ppg_lead_cfg( 'PPG_IMAP_USER' ), (string) ppg_lead_cfg( 'PPG_IMAP_PASS' ), OP_READONLY, 1 );
	if ( ! $imap ) {
		return array( false, 'Login failed: ' . imap_last_error() );
	}
	imap_close( $imap );
	return array( true, 'Mailbox login works (read-only).' );
}

/** @return array{0:bool,1:string} */
function ppg_status_test_hubspot() {
	$token = (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' );
	if ( '' === $token ) {
		return array( false, 'PPG_HUBSPOT_TOKEN is not set.' );
	}
	$res = wp_remote_get( 'https://api.hubapi.com/crm/v3/objects/contacts?limit=1', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
	if ( is_wp_error( $res ) ) {
		return array( false, 'Could not reach HubSpot: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	return 200 === $code ? array( true, 'HubSpot accepted the token (read test, nothing was written).' ) : array( false, 'HubSpot answered HTTP ' . $code . ( 401 === $code ? ' - the token is wrong or expired.' : '.' ) );
}

function ppg_status_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['ppg_action'] ) && check_admin_referer( 'ppg_status_action' ) ) {
		$action = sanitize_key( wp_unslash( $_POST['ppg_action'] ) );
		if ( 'run' === $action ) {
			ppg_poll_mailbox();
			$s      = (array) get_option( 'ppg_poll_status' );
			$notice = 'Poll finished: ' . ( $s['outcome'] ?? '?' ) . ' - matched ' . ( $s['matched'] ?? 0 ) . ', new leads ' . ( $s['considered'] ?? 0 ) . ', imported ' . ( $s['imported'] ?? 0 ) . ', duplicates ' . ( $s['duplicates'] ?? 0 ) . ', held ' . ( $s['held'] ?? 0 ) . ', failed ' . ( $s['failed'] ?? 0 ) . ( ! empty( $s['detail'] ) ? ' (' . $s['detail'] . ')' : '' );
		} elseif ( 'mailbox' === $action ) {
			$notice = ppg_status_test_mailbox()[1];
		} elseif ( 'hubspot' === $action ) {
			$notice = ppg_status_test_hubspot()[1];
		}
	}
	$icons = array( 'ok' => '&#10004;', 'warn' => '&#9888;', 'bad' => '&#10006;' );
	$cols  = array( 'ok' => '#1a7f37', 'warn' => '#b08800', 'bad' => '#cf222e' );
	echo '<div class="wrap"><h1>PPG Lead Status</h1>';
	if ( $notice ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>';
	}
	echo '<table class="widefat striped" style="max-width:980px"><tbody>';
	foreach ( ppg_status_checks() as $row ) {
		printf( '<tr><td style="width:30px;color:%s;font-weight:bold">%s</td><td style="width:220px"><strong>%s</strong></td><td>%s</td></tr>', esc_attr( $cols[ $row[1] ] ), $icons[ $row[1] ], esc_html( $row[0] ), esc_html( $row[2] ) );
	}
	echo '</tbody></table><form method="post" style="margin:1em 0">';
	wp_nonce_field( 'ppg_status_action' );
	echo '<button class="button button-primary" name="ppg_action" value="run">Run poll now</button> ';
	echo '<button class="button" name="ppg_action" value="mailbox">Test mailbox login</button> ';
	echo '<button class="button" name="ppg_action" value="hubspot">Test HubSpot token</button></form>';
	echo '<h2>Recent runs</h2>';
	$log = array_reverse( (array) get_option( 'ppg_poll_log', array() ) );
	if ( ! $log ) {
		echo '<p>No runs recorded yet.</p></div>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>When (UTC)</th><th>Outcome</th><th>Matched</th><th>New leads</th><th>Imported</th><th>Duplicates</th><th>Held</th><th>Failed</th><th>Detail</th></tr></thead><tbody>';
	foreach ( array_slice( $log, 0, 20 ) as $r ) {
		printf( '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( gmdate( 'Y-m-d H:i:s', (int) $r['started'] ) ), esc_html( $r['outcome'] ), $r['matched'], $r['considered'], $r['imported'], $r['duplicates'], $r['held'], $r['failed'], esc_html( $r['detail'] ) );
	}
	echo '</tbody></table></div>';
}
