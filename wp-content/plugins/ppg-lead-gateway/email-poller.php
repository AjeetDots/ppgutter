<?php
/**
 * Mailbox poller: reads unread lead-notification emails over IMAP (WP-Cron, every 5 minutes),
 * identifies the platform from the sender, parses the lead and sends it to HubSpot.
 *
 * Config (wp-config.php constants or env vars):
 *   PPG_IMAP_HOST  e.g. {imap.example.com:993/imap/ssl}INBOX
 *   PPG_IMAP_USER, PPG_IMAP_PASS
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/parsers.php';

/** Sender domain => platform slug. Subdomains match too (em.yell.com, services.checkatrade.com). */
function ppg_email_sources() {
	return array(
		'yelp.com'        => 'yelp',
		'yelp.co.uk'      => 'yelp',
		'yell.com'        => 'yell',
		'checkatrade.com' => 'checkatrade',
	);
}

add_filter( 'cron_schedules', function ( $s ) {
	$s['ppg_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
	return $s;
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ppg_poll_mailbox' ) ) {
		wp_schedule_event( time() + 60, 'ppg_five_minutes', 'ppg_poll_mailbox' );
	}
} );

register_deactivation_hook( dirname( __FILE__ ) . '/ppg-lead-gateway.php', function () {
	wp_clear_scheduled_hook( 'ppg_poll_mailbox' );
} );

add_action( 'ppg_poll_mailbox', 'ppg_poll_mailbox' );

/** PPG_GO_LIVE, e.g. '2026-10-20 09:00:00 Europe/London'. Returns a Unix timestamp, or 0 if unset/invalid. */
function ppg_go_live_timestamp() {
	$value = (string) ppg_lead_cfg( 'PPG_GO_LIVE' );
	$ts    = '' === $value ? false : strtotime( $value );
	return $ts ? (int) $ts : 0;
}

/** Returns plain text for a message (prefers text/plain, falls back to stripped HTML). */
function ppg_email_text( $imap, $num ) {
	$structure = imap_fetchstructure( $imap, $num );
	$plain     = '';
	$html      = '';

	$walk = function ( $part, $path ) use ( &$walk, $imap, $num, &$plain, &$html ) {
		if ( ! empty( $part->parts ) ) {
			foreach ( $part->parts as $i => $sub ) {
				$walk( $sub, '' === $path ? (string) ( $i + 1 ) : $path . '.' . ( $i + 1 ) );
			}
			return;
		}
		if ( TYPETEXT !== $part->type ) {
			return;
		}
		$raw = imap_fetchbody( $imap, $num, '' === $path ? '1' : $path, FT_PEEK );
		if ( ENCBASE64 === $part->encoding ) {
			$raw = base64_decode( $raw );
		} elseif ( ENCQUOTEDPRINTABLE === $part->encoding ) {
			$raw = quoted_printable_decode( $raw );
		}
		foreach ( (array) ( $part->parameters ?? array() ) as $param ) {
			if ( 'charset' === strtolower( $param->attribute ) && 'utf-8' !== strtolower( $param->value ) && function_exists( 'mb_convert_encoding' ) ) {
				$raw = @mb_convert_encoding( $raw, 'UTF-8', $param->value );
			}
		}
		if ( 'PLAIN' === strtoupper( $part->subtype ) ) {
			$plain .= $raw;
		} elseif ( 'HTML' === strtoupper( $part->subtype ) ) {
			$html .= $raw;
		}
	};
	$walk( $structure, '' );

	// HTML-only emails (all the older Checkatrade ones) are converted to text for the parsers.
	return '' !== trim( $plain ) ? $plain : ppg_html_to_text( $html );
}

/** Atomic lock so two overlapping cron runs cannot import the same lead twice. */
function ppg_poll_lock_acquire() {
	$now = time();
	if ( add_option( 'ppg_poll_lock', $now, '', 'no' ) ) { // add_option fails if the row already exists
		return true;
	}
	if ( $now - (int) get_option( 'ppg_poll_lock' ) > 10 * MINUTE_IN_SECONDS ) { // stale lock from a crashed run
		update_option( 'ppg_poll_lock', $now, false );
		return true;
	}
	return false;
}

/**
 * One cron run. Records what happened (option ppg_poll_status + a rolling log in ppg_poll_log) so the
 * status page (Tools > PPG Lead Status) can show whether cron is really firing and what each run did.
 */
function ppg_poll_mailbox() {
	$started = time();
	if ( ! ppg_poll_lock_acquire() ) {
		ppg_poll_record( $started, array( 'outcome' => 'locked', 'detail' => 'Another run was already in progress.' ) );
		return;
	}
	try {
		$stats = ppg_poll_mailbox_run();
	} catch ( \Throwable $e ) {
		$stats = array( 'outcome' => 'crashed', 'detail' => substr( $e->getMessage(), 0, 200 ) );
		error_log( '[ppg-lead-gateway] poll crashed: ' . $e->getMessage() );
	} finally {
		delete_option( 'ppg_poll_lock' );
	}
	ppg_poll_record( $started, $stats );
}

/** Keep the last run and the last 30 runs. */
function ppg_poll_record( $started, array $stats ) {
	$entry = array_merge( array( 'matched' => 0, 'considered' => 0, 'imported' => 0, 'duplicates' => 0, 'held' => 0, 'failed' => 0, 'detail' => '' ), $stats, array( 'started' => $started, 'finished' => time() ) );
	update_option( 'ppg_poll_status', $entry, false );
	$log   = (array) get_option( 'ppg_poll_log', array() );
	$log[] = $entry;
	update_option( 'ppg_poll_log', array_slice( $log, -30 ), false );
}

/**
 * The mailbox pass. Returns array( outcome, detail, matched, considered, imported, duplicates, held, failed ).
 * outcome: ok | no_imap | not_configured | connect_failed | no_go_live.
 */
function ppg_poll_mailbox_run() {
	$stats = array( 'outcome' => 'ok', 'detail' => '', 'matched' => 0, 'considered' => 0, 'imported' => 0, 'duplicates' => 0, 'held' => 0, 'failed' => 0 );

	if ( ! function_exists( 'imap_open' ) ) {
		error_log( '[ppg-lead-gateway] PHP imap extension is not enabled; mailbox polling skipped.' );
		return array_merge( $stats, array( 'outcome' => 'no_imap', 'detail' => 'The PHP imap extension is not enabled on this server.' ) );
	}
	$host = (string) ppg_lead_cfg( 'PPG_IMAP_HOST' );
	$user = (string) ppg_lead_cfg( 'PPG_IMAP_USER' );
	$pass = (string) ppg_lead_cfg( 'PPG_IMAP_PASS' );
	if ( '' === $host || '' === $user || '' === $pass ) {
		return array_merge( $stats, array( 'outcome' => 'not_configured', 'detail' => 'PPG_IMAP_HOST / PPG_IMAP_USER / PPG_IMAP_PASS are not all set.' ) );
	}

	$imap = @imap_open( $host, $user, $pass, 0, 1 );
	if ( ! $imap ) {
		$err = (string) imap_last_error();
		error_log( '[ppg-lead-gateway] IMAP connect failed: ' . $err );
		return array_merge( $stats, array( 'outcome' => 'connect_failed', 'detail' => substr( $err, 0, 200 ) ) );
	}

	// Import only emails received after the go-live time. Without PPG_GO_LIVE nothing is imported,
	// so the integration cannot pull in historical leads by accident.
	$go_live = ppg_go_live_timestamp();
	if ( ! $go_live ) {
		if ( ! get_transient( 'ppg_no_go_live_logged' ) ) {
			error_log( '[ppg-lead-gateway] PPG_GO_LIVE is not set (or invalid); mailbox import is disabled.' );
			set_transient( 'ppg_no_go_live_logged', 1, DAY_IN_SECONDS );
		}
		imap_close( $imap );
		return array_merge( $stats, array( 'outcome' => 'no_go_live', 'detail' => 'Mailbox login works, but PPG_GO_LIVE is not set, so nothing is imported (safe default).' ) );
	}

	// Search by sender and date, not by unread state, so a human opening the email first does not hide it from
	// us. Duplicates are prevented by the Message-ID dedupe key. Only matching senders are ever fetched.
	// IMAP SINCE is date-only (exact time is checked below). Only the last three days are searched, so a
	// long-running site does not re-read old mail; duplicate locks last two weeks.
	$since   = gmdate( 'd-M-Y', max( $go_live - DAY_IN_SECONDS, time() - 3 * DAY_IN_SECONDS ) );
	$matches = array();
	foreach ( array_keys( ppg_email_sources() ) as $domain ) {
		$found = imap_search( $imap, 'FROM "' . $domain . '" SINCE "' . $since . '"' );
		if ( $found ) {
			$matches = array_merge( $matches, $found );
		}
	}
	$matches            = array_unique( $matches );
	$stats['matched']   = count( $matches );
	foreach ( $matches as $num ) {
		$header = imap_headerinfo( $imap, $num );
		$from   = isset( $header->from[0] ) ? strtolower( $header->from[0]->host ?? '' ) : '';
		$source = '';
		foreach ( ppg_email_sources() as $domain => $slug ) {
			if ( $from === $domain || substr( $from, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				$source = $slug;
				break;
			}
		}
		if ( '' === $source ) {
			continue; // Not a lead email; leave it unread for a human.
		}

		if ( empty( $header->udate ) || (int) $header->udate < $go_live ) {
			continue; // Received before go-live: never imported.
		}

		$subject = isset( $header->subject ) ? imap_utf8( $header->subject ) : '';

		// Cheap subject check first, so non-lead mail from these senders (login alerts, invoices,
		// verification codes, reminders, replies) is never even downloaded.
		if ( null === ppg_parse_lead_email( $source, $subject, '' ) ) {
			continue;
		}

		$body = ppg_email_text( $imap, $num );
		$lead = ppg_parse_lead_email( $source, $subject, $body );
		if ( null === $lead ) {
			continue;
		}
		$stats['considered']++;

		// The Message-ID means the same email is never imported twice. A lead with no customer email is
		// still created (identified by its lead id); only an unprocessable lead goes to the review queue.
		$lead['dedupe_key'] = $header->message_id ?? md5( $subject . $body );
		$lead['subject']    = $subject;

		// The mailbox is never modified (no read flags). HubSpot failures are not remembered, so the next run
		// retries them. ppg_lead_process() logs failures itself and queues the ones that cannot be retried.
		$result = ppg_lead_process( $source, $lead );
		if ( is_wp_error( $result ) ) {
			// Held in the review queue (nothing identifies the lead, or HubSpot refused it) versus a failure that will be retried.
			$http   = (int) ( $result->get_error_data()['http'] ?? 0 );
			$bucket = ( 'ppg_unprocessable' === $result->get_error_code() || ( $http >= 400 && $http < 500 && 429 !== $http ) ) ? 'held' : 'failed';
			$stats[ $bucket ]++;
		} elseif ( 'duplicate_ignored' === ( $result['status'] ?? '' ) ) {
			$stats['duplicates']++;
		} else {
			$stats['imported']++;
		}
	}
	imap_close( $imap );
	return $stats;
}
