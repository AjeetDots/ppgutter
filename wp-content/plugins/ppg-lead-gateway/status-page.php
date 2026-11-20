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
		$c[]    = array( 'Mailbox certificate check', 'ok', false !== stripos( $host, 'novalidate-cert' ) ? 'OFF (host has /novalidate-cert; use only if the server cannot send SNI)' : 'on (add /novalidate-cert to PPG_IMAP_HOST if you get "No SNI provided")' );
	}

	$c[] = array( 'HubSpot token', '' !== (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' ) ? 'ok' : 'bad', '' !== (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' ) ? 'set (use the button below to test it)' : 'PPG_HUBSPOT_TOKEN is not set' );

	$go = ppg_go_live_timestamp();
	if ( ! $go ) {
		$c[] = array( 'Go-live (PPG_GO_LIVE)', 'warn', 'not set: importing is OFF (the safe default). Set it to the time the import should start' );
	} else {
		$c[] = array( 'Go-live (PPG_GO_LIVE)', $go > $now ? 'warn' : 'ok', gmdate( 'Y-m-d H:i', $go ) . ' UTC' . ( $go > $now ? ' (in the future: nothing imports until then)' : ': only emails received after this are imported' ) );
	}

	$auth_off = defined( 'PPG_REQUIRE_SENDER_AUTH' ) && false === PPG_REQUIRE_SENDER_AUTH;
	$c[]      = array( 'Sender verification', $auth_off ? 'warn' : 'ok', $auth_off ? 'OFF (PPG_REQUIRE_SENDER_AUTH is false): a fake email could create a lead' : 'on: only emails Gmail confirms really came from Checkatrade are accepted' );

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

/** Date/time in UK time (Europe/London), e.g. "17 Nov 2026, 13:30". */
function ppg_status_uk( $ts, $fmt = 'j M Y, H:i' ) {
	return wp_date( $fmt, (int) $ts, new DateTimeZone( 'Europe/London' ) );
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
			$notice = 'Inbox check finished: found ' . (int) ( $s['matched'] ?? 0 ) . ' new enquiries, sent ' . (int) ( $s['imported'] ?? 0 ) . ' to HubSpot.' . ( ! empty( $s['detail'] ) ? ' Note: ' . $s['detail'] : '' );
		} elseif ( 'mailbox' === $action ) {
			$notice = ppg_status_test_mailbox()[1];
		} elseif ( 'hubspot' === $action ) {
			$notice = ppg_status_test_hubspot()[1];
		}
	}

	$now    = time();
	$cols   = array( 'ok' => '#1a7f37', 'warn' => '#9a6700', 'bad' => '#cf222e' );
	$bgs    = array( 'ok' => '#dafbe1', 'warn' => '#fff8c5', 'bad' => '#ffebe9' );
	$icons  = array( 'ok' => '&#10004;', 'warn' => '&#9888;', 'bad' => '&#10006;' );
	$checks = ppg_status_checks();
	$bad    = count( array_filter( $checks, function ( $r ) { return 'bad' === $r[1]; } ) );
	$pill   = function ( $st, $text ) use ( $cols, $bgs ) {
		return '<span class="ppgs-pill" style="color:' . esc_attr( $cols[ $st ] ) . ';background:' . esc_attr( $bgs[ $st ] ) . '">' . esc_html( $text ) . '</span>';
	};
	$last   = get_option( 'ppg_poll_status' );
	$last   = is_array( $last ) && ! empty( $last['finished'] ) ? $last : null;
	$go     = ppg_go_live_timestamp();
	$queue  = count( (array) get_option( 'ppg_lead_review', array() ) );
	$daily  = (array) get_option( 'ppg_poll_daily', array() );
	$day_n  = function ( $ymd, $key ) use ( $daily ) { // counters are stored as array( sent, ignored ); older installs stored a plain number
		$d = $daily[ $ymd ] ?? 0;
		return is_array( $d ) ? (int) ( $d[ $key ] ?? 0 ) : ( 'sent' === $key ? (int) $d : 0 );
	};
	$ign_today = $day_n( ppg_status_uk( time(), 'Y-m-d' ), 'ignored' );

	// Plain-English health banner for the client.
	$why = array(
		'connect_failed' => 'We cannot sign in to the enquiries inbox.',
		'not_configured' => 'The enquiries inbox has not been connected yet.',
		'no_imap'        => 'The website server is missing a feature needed to read the inbox.',
		'crashed'        => 'The last inbox check hit an unexpected error.',
	);
	if ( $bad ) {
		$h_state = 'bad';
		$h_title = 'Needs attention';
		$h_text  = ( $last && isset( $why[ $last['outcome'] ] ) ? $why[ $last['outcome'] ] : 'Enquiries may not be reaching HubSpot.' ) . ' Please send a screenshot of this page to your developer.';
	} elseif ( ! $go ) {
		$h_state = 'warn';
		$h_title = 'Not switched on yet';
		$h_text  = 'The plugin is installed and connected, but no start time has been set, so no enquiries are being collected yet.';
	} elseif ( $go > $now ) {
		$h_state = 'warn';
		$h_title = 'Ready, starts ' . ppg_status_uk( $go, 'j M \a\t H:i' );
		$h_text  = 'Everything is connected. It will start collecting enquiries received after ' . ppg_status_uk( $go, 'j M Y \a\t H:i' ) . ' (UK time).';
	} elseif ( $ign_today ) {
		$h_state = 'warn';
		$h_title = 'Working, ' . $ign_today . ' suspicious email' . ( 1 === $ign_today ? '' : 's' ) . ' ignored today';
		$h_text  = 'Emails pretending to be from Checkatrade failed our sender check and were ignored. This is usually spam and is nothing to worry about. If real Checkatrade enquiries are missing, tell your developer.';
	} elseif ( $queue ) {
		$h_state = 'warn';
		$h_title = 'Working, but ' . $queue . ' enquir' . ( 1 === $queue ? 'y needs' : 'ies need' ) . ' a look';
		$h_text  = 'Most enquiries are being sent to HubSpot automatically. A few could not be added and are waiting for you in PPG Lead Review.';
	} else {
		$h_state = 'ok';
		$h_title = 'Working normally';
		$h_text  = 'The inbox is checked every 5 minutes and new enquiries are sent to HubSpot automatically. Nothing needs doing.';
	}

	$tip = function ( $name, $text, $right = false ) {
		return esc_html( $name ) . ' <span class="ppgs-i' . ( $right ? ' r' : '' ) . '" tabindex="0" aria-label="' . esc_attr( $text ) . '" data-tip="' . esc_attr( $text ) . '">i</span>';
	};

	echo '<style>
	.ppgs{max-width:1000px}
	.ppgs h1{margin-bottom:14px}
	.ppgs h1 .sub-t{font-size:14px;font-weight:400;color:#646970;margin-left:8px}
	.ppgs-hero{display:flex;align-items:center;gap:18px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-left-width:6px;border-radius:10px;padding:18px 22px;margin:14px 0 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
	.ppgs-hero .ic{flex:none;width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700}
	.ppgs-hero .tx{flex:1;min-width:260px}
	.ppgs-hero .t{font-size:20px;font-weight:600;color:#1d2327;margin:0 0 3px}
	.ppgs-hero .d{margin:0;color:#50575e;font-size:14px;line-height:1.5}
	.ppgs-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.5;white-space:nowrap}
	.ppgs-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
	.ppgs-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 18px}
	.ppgs-card .k{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
	.ppgs-card .v{font-size:26px;font-weight:600;margin-top:4px;color:#1d2327;line-height:1.2}
	.ppgs-card .s{font-size:12px;color:#646970;margin-top:3px}
	.ppgs-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;margin-bottom:16px}
	.ppgs-panel>summary,.ppgs-panel>.ppgs-ph{padding:14px 18px;font-weight:600;font-size:15px;display:flex;align-items:center;gap:10px}
	.ppgs-panel>summary{cursor:pointer}
	.ppgs-panel>.ppgs-ph{border-bottom:1px solid #f0f0f1}
	.ppgs-panel[open]>summary{border-bottom:1px solid #f0f0f1}
	.ppgs-panel .sub{font-weight:400;font-size:12px;color:#646970}
	.ppgs-panel .pad{padding:14px 18px}
	.ppgs table{width:100%;border-collapse:collapse}
	.ppgs th{background:#f6f7f7;text-align:left;font-size:12px;color:#50575e;text-transform:uppercase;letter-spacing:.03em;padding:10px 18px;white-space:nowrap}
	.ppgs td{padding:11px 18px;border-top:1px solid #f0f0f1;font-size:14px;vertical-align:middle}
	.ppgs tbody tr:hover td{background:#fafafa}
	.ppgs td.n{font-variant-numeric:tabular-nums}
	.ppgs td.z{color:#a7aaad}
	.ppgs .empty{padding:22px 18px;color:#646970;text-align:center}
	.ppgs ol.how{margin:0;padding-left:20px;line-height:1.7;color:#3c434a}
	.ppgs .devbtn{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
	.ppgs-i{position:relative;display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:4px;border-radius:50%;background:#dcdcde;color:#50575e;font:italic 700 10px/1 Georgia,serif;text-transform:none;letter-spacing:0;cursor:help;vertical-align:middle}
	.ppgs-i:hover,.ppgs-i:focus{background:#2271b1;color:#fff;outline:0}
	.ppgs-i::after{content:attr(data-tip);position:absolute;top:calc(100% + 8px);left:-8px;z-index:30;width:270px;padding:10px 12px;border-radius:8px;background:#1d2327;color:#fff;font:400 12.5px/1.5 -apple-system,"Segoe UI",sans-serif;text-transform:none;letter-spacing:0;white-space:pre-line;text-align:left;box-shadow:0 6px 18px rgba(0,0,0,.28);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .12s}
	.ppgs-i.r::after{left:auto;right:-8px}
	.ppgs-i:hover::after,.ppgs-i:focus::after{opacity:1;visibility:visible}
	</style>';

	echo '<div class="wrap ppgs"><h1>PPG Lead Gateway <span class="sub-t">Checkatrade enquiries &rarr; HubSpot</span></h1><hr class="wp-header-end">';
	if ( $notice ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>';
	}

	// 1. Health banner.
	printf(
		'<div class="ppgs-hero" style="border-left-color:%s"><div class="ic" style="color:%s;background:%s">%s</div><div class="tx"><p class="t">%s</p><p class="d">%s</p></div><form method="post">%s<button class="button button-primary button-hero" name="ppg_action" value="run">Check inbox now</button></form></div>',
		esc_attr( $cols[ $h_state ] ),
		esc_attr( $cols[ $h_state ] ),
		esc_attr( $bgs[ $h_state ] ),
		$icons[ $h_state ],
		esc_html( $h_title ),
		esc_html( $h_text ),
		wp_nonce_field( 'ppg_status_action', '_wpnonce', true, false )
	);

	// 2. Key numbers.
	echo '<div class="ppgs-cards">';
	// Proof it is running: exact time of the last check (turns red if it is overdue) and when the next one is due.
	$stale = ! $last || $now - (int) $last['finished'] > 15 * MINUTE_IN_SECONDS;
	$next  = (int) wp_next_scheduled( 'ppg_poll_mailbox' );
	printf(
		'<div class="ppgs-card"><div class="k">' . $tip( 'Last inbox check', 'The last time the website looked in the Checkatrade enquiries inbox. This should always be within the last 5 minutes. If the time turns red, the automatic check has stopped, so tell your developer.' ) . '</div><div class="v" style="color:%s">%s</div><div class="s">%s</div></div>',
		$stale ? '#cf222e' : '#1d2327',
		$last ? esc_html( ppg_status_uk( (int) $last['finished'], 'H:i:s' ) ) : '&mdash;',
		$last ? esc_html( ppg_status_ago( (int) $last['finished'] ) . ', ' . ppg_status_uk( (int) $last['finished'], 'j M Y' ) . ' (UK time)' ) . ( $stale ? ' <strong style="color:#cf222e">&middot; overdue, it should run every 5 minutes</strong>' : '' ) : 'it has not run yet'
	);
	printf(
		'<div class="ppgs-card"><div class="k">' . $tip( 'Next inbox check', 'When the website will look in the inbox again. This happens by itself about every 5 minutes, so there is nothing for you to do.' ) . '</div><div class="v">%s</div><div class="s">%s</div></div>',
		$next ? esc_html( ppg_status_uk( $next, 'H:i:s' ) ) : '&mdash;',
		$next ? ( $next > $now ? 'in about ' . max( 1, (int) round( ( $next - $now ) / 60 ) ) . ' min (UK time)' : 'due now' ) : 'not scheduled'
	);
	printf( '<div class="ppgs-card"><div class="k">' . $tip( 'Need a look', 'Enquiries that could not be added to HubSpot automatically, for example because details were missing. This is normally 0. If it is above 0, open PPG Lead Review to see them. An enquiry that was sent to HubSpot successfully never appears here.', true ) . '</div><div class="v" style="color:%s">%d</div><div class="s"><a href="%s">Open PPG Lead Review</a></div></div>', $queue ? '#9a6700' : '#1d2327', $queue, esc_url( admin_url( 'tools.php?page=ppg-lead-review' ) ) );
	echo '</div>';

	// 3. Counts for the last two days only. No customer data is kept on this website.
	$days = array(
		'Today'     => ppg_status_uk( $now, 'Y-m-d' ),
		'Yesterday' => ppg_status_uk( $now - DAY_IN_SECONDS, 'Y-m-d' ),
	);
	echo '<div class="ppgs-panel"><div class="ppgs-ph">' . $tip( 'Enquiries sent to HubSpot', 'How many Checkatrade enquiries were added to HubSpot as contacts, for today and yesterday. For the customer names and details, open the contact in HubSpot.' ) . ' <span class="sub">last 2 days, UK time</span></div>';
	echo '<p class="pad" style="margin:0;color:#50575e;font-size:13px;border-bottom:1px solid #f0f0f1">Plugin: <strong>PPG Lead Gateway</strong> &middot; Source: <strong>Checkatrade only</strong>. Enquiries from the Checkatrade notification emails in the info inbox are added to HubSpot automatically.</p>';
	echo '<table><thead><tr><th>' . $tip( 'Day', 'Today and yesterday, by UK date. Older days are not kept here, use HubSpot for history.' ) . '</th><th>' . $tip( 'Sent to HubSpot', 'Checkatrade enquiries that were added to HubSpot as contacts that day. Each enquiry is counted once.', true ) . '</th><th>' . $tip( 'Ignored', 'Emails pretending to be from Checkatrade that failed our sender check (usually spam). They are never sent to HubSpot. Normally 0.', true ) . '</th></tr></thead><tbody>';
	foreach ( $days as $label => $ymd ) {
		$n = $day_n( $ymd, 'sent' );
		$i = $day_n( $ymd, 'ignored' );
		printf( '<tr><td><strong>%s</strong> <span class="sub">%s</span></td><td class="n%s" style="font-size:18px;font-weight:600">%d</td><td class="n%s">%d</td></tr>', esc_html( $label ), esc_html( ppg_status_uk( strtotime( $ymd . ' 12:00:00 UTC' ), 'j M' ) ), $n ? '' : ' z', $n, $i ? '' : ' z', $i );
	}
	echo '</tbody></table><p class="pad" style="margin:0;border-top:1px solid #f0f0f1;color:#646970;font-size:13px">Customer details are sent straight to HubSpot and are not kept on this website, apart from any held under <em>Need a look</em> until someone handles them. This page only shows counts.</p></div>';

	// 5. How to check an enquiry.
	echo '<details class="ppgs-panel"><summary>How to check an enquiry <span class="sub">click to show</span></summary><div class="pad"><ol class="how">';
	echo '<li>The numbers above update within about 5 minutes of a new enquiry arriving.</li>';
	echo '<li>To see <strong>which</strong> customers were added: open <strong>HubSpot &rarr; Contacts</strong>, then filter by <strong>Lead Sources</strong> is <em>Checkatrade</em> (the only source this plugin reads) and <strong>Create date</strong> is <em>Today</em>. The number of contacts should match &ldquo;Today&rdquo; above.</li>';
	echo '<li>Open a contact to see their details. Their message is saved in a note on the contact and the Lead Status is <em>New</em>.</li>';
	echo '<li>If <strong>Need a look</strong> is above 0, open <strong>Tools &rarr; PPG Lead Review</strong> to deal with those enquiries by hand.</li>';
	echo '<li>If the banner is red, or the last inbox check is more than 15 minutes old, send a screenshot of this page to your developer.</li>';
	echo '</ol></div></details>';

	// 6. Developer tools, collapsed.
	echo '<details class="ppgs-panel"><summary>Developer tools <span class="sub">technical checks, for your developer</span></summary><div class="pad"><form method="post" class="devbtn">';
	wp_nonce_field( 'ppg_status_action' );
	echo '<button class="button" name="ppg_action" value="mailbox">Test inbox login</button>';
	echo '<button class="button" name="ppg_action" value="hubspot">Test HubSpot connection</button></form><table><tbody>';
	foreach ( $checks as $row ) {
		printf( '<tr><td style="width:34px;color:%s;font-weight:bold;text-align:center;padding:8px">%s</td><td style="width:220px;padding:8px"><strong>%s</strong></td><td style="padding:8px">%s</td></tr>', esc_attr( $cols[ $row[1] ] ), $icons[ $row[1] ], esc_html( $row[0] ), esc_html( $row[2] ) );
	}
	echo '</tbody></table></div></details>';
	echo '</div>';
}
