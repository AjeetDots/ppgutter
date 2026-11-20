<?php
/**
 * Mailbox poller: reads Checkatrade lead-notification emails over IMAP (WP-Cron, every 5 minutes),
 * parses the lead and sends it to HubSpot.
 *
 * Config (wp-config.php constants or env vars):
 *   PPG_IMAP_HOST  e.g. {imap.example.com:993/imap/ssl}INBOX
 *   PPG_IMAP_USER, PPG_IMAP_PASS
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/parsers.php';

/** Sender domain => platform slug. Subdomains match too (services.checkatrade.com). Checkatrade only. */
function ppg_email_sources() {
	return array(
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

/**
 * Anti-spoofing. The "From" line of an email is trivially forged, so anyone could email info@ pretending to be
 * Checkatrade and fill HubSpot with junk. Gmail checks who really sent each message (SPF/DKIM/DMARC) and records the
 * verdict in the topmost Authentication-Results header; we trust only that header (lower ones can be forged by the
 * sender) and accept a message only when the check passed for the platform's own domain.
 * Emergency switch: define( 'PPG_REQUIRE_SENDER_AUTH', false ) in wp-config.php turns this off.
 */
function ppg_auth_results( $raw_headers ) {
	$raw = preg_replace( '/\r?\n[ \t]+/', ' ', (string) $raw_headers ); // unfold wrapped header lines
	return preg_match( '/^Authentication-Results:\s*(.+)$/im', $raw, $m ) ? trim( $m[1] ) : '';
}

function ppg_domain_matches( $value, $domain ) {
	$host = strtolower( trim( (string) $value, " \t\"'<>" ) );
	$at   = strrpos( $host, '@' );
	if ( false !== $at ) {
		$host = substr( $host, $at + 1 );
	}
	return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
}

function ppg_sender_verified( $raw_headers, $domain ) {
	if ( defined( 'PPG_REQUIRE_SENDER_AUTH' ) && false === PPG_REQUIRE_SENDER_AUTH ) {
		return true;
	}
	$ar = ppg_auth_results( $raw_headers );
	if ( '' === $ar || 0 !== stripos( $ar, 'mx.google.com' ) ) {
		return false; // missing, or not written by Gmail itself
	}
	foreach ( explode( ';', $ar ) as $segment ) {
		if ( ! preg_match( '/^\s*(?:dkim|spf|dmarc)=pass\b(.*)$/is', $segment, $m ) ) {
			continue;
		}
		if ( preg_match_all( '/(?:header\.(?:i|d|from)|smtp\.mailfrom)=("?)([^\s;"]+)\1/i', $m[1], $vals ) ) {
			foreach ( $vals[2] as $v ) {
				if ( ppg_domain_matches( $v, $domain ) ) {
					return true;
				}
			}
		}
	}
	return false;
}

/** Returns plain text for a message (prefers text/plain, falls back to stripped HTML). */
function ppg_email_text( $imap, $num ) {
	$structure = imap_fetchstructure( $imap, $num );
	$plain     = '';
	$html      = '';

	$walk = function ( $part, $path ) use ( &$walk, $imap, $num, &$plain, &$html ) {
		if ( substr_count( $path, '.' ) > 6 || strlen( $plain ) + strlen( $html ) > 400000 ) {
			return; // absurdly nested or huge: a lead notification is never like this
		}
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
	return substr( '' !== trim( $plain ) ? $plain : ppg_html_to_text( $html ), 0, 100000 );
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
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 );
	}
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

/**
 * Keeps the last run (the status page's heartbeat) and a tiny per-day counter for today and yesterday.
 * Nothing else is stored per run or per lead, so the database footprint never grows.
 */
function ppg_poll_record( $started, array $stats ) {
	$entry = array_merge( array( 'matched' => 0, 'considered' => 0, 'imported' => 0, 'duplicates' => 0, 'held' => 0, 'failed' => 0, 'ignored' => 0, 'detail' => '' ), $stats, array( 'started' => $started, 'finished' => time() ) );
	update_option( 'ppg_poll_status', $entry, false );

	$day     = wp_date( 'Y-m-d', time(), new DateTimeZone( 'Europe/London' ) );
	$daily   = (array) get_option( 'ppg_poll_daily', array() );
	$new_day = ! isset( $daily[ $day ] );
	$cur     = is_array( $daily[ $day ] ?? null ) ? $daily[ $day ] : array( 'sent' => (int) ( $daily[ $day ] ?? 0 ), 'ignored' => 0 );
	$daily[ $day ] = array(
		'sent'    => (int) ( $cur['sent'] ?? 0 ) + (int) $entry['imported'],
		'ignored' => (int) ( $cur['ignored'] ?? 0 ) + (int) $entry['ignored'],
	);
	krsort( $daily );
	update_option( 'ppg_poll_daily', array_slice( $daily, 0, 2, true ), false ); // WordPress skips the write when nothing changed
	if ( $new_day ) {
		delete_option( 'ppg_poll_log' ); // the old rolling run log is no longer kept
	}
}

/**
 * The mailbox pass. Returns array( outcome, detail, matched, considered, imported, duplicates, held, failed ).
 * outcome: ok | no_imap | not_configured | connect_failed | no_go_live.
 */
function ppg_poll_mailbox_run() {
	$stats = array( 'outcome' => 'ok', 'detail' => '', 'matched' => 0, 'considered' => 0, 'imported' => 0, 'duplicates' => 0, 'held' => 0, 'failed' => 0, 'ignored' => 0 );

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
		// Say which host setting THIS run saw, so a run that still uses an old cached wp-config is easy to spot.
		$seen = ( false !== stripos( $host, 'novalidate-cert' ) ? 'this run: /novalidate-cert set' : 'this run: /novalidate-cert NOT set' ) . ', ' . ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ? 'started by WP-Cron' : 'started by a button' );
		return array_merge( $stats, array( 'outcome' => 'connect_failed', 'detail' => substr( $err, 0, 140 ) . ' [' . $seen . ']' ) );
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
	$matches = array_unique( $matches );

	// Safety limits so a flood of mail can never make one run heavy: at most N leads sent to HubSpot per run and about
	// 40 seconds of work; the rest is simply picked up on the next 5-minute check.
	$max_per_run = defined( 'PPG_MAX_PER_RUN' ) ? max( 1, (int) PPG_MAX_PER_RUN ) : 40;
	$handled     = 0;
	$t0          = microtime( true );
	foreach ( $matches as $num ) {
		if ( $handled >= $max_per_run || microtime( true ) - $t0 > 40 ) {
			$stats['detail'] = 'More enquiries are waiting; they will be picked up on the next check.';
			break;
		}
		$header = imap_headerinfo( $imap, $num );
		$from   = isset( $header->from[0] ) ? strtolower( $header->from[0]->host ?? '' ) : '';
		$source     = '';
		$src_domain = '';
		foreach ( ppg_email_sources() as $domain => $slug ) {
			if ( $from === $domain || substr( $from, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				$source     = $slug;
				$src_domain = $domain;
				break;
			}
		}
		if ( '' === $source ) {
			continue; // Not a lead email; leave it unread for a human.
		}

		if ( empty( $header->udate ) || (int) $header->udate < $go_live ) {
			continue; // Received before go-live: never imported, and not counted as matched.
		}
		if ( (int) ( $header->Size ?? 0 ) > 524288 ) {
			continue; // half a megabyte: far larger than any lead notification, so not one
		}

		$subject = isset( $header->subject ) ? imap_utf8( $header->subject ) : '';

		// Cheap subject check first, so non-lead mail from these senders (login alerts, invoices,
		// verification codes, reminders, replies) is never even downloaded.
		if ( null === ppg_parse_lead_email( $source, $subject, '' ) ) {
			$stats['detail'] = 'Skipped, not a lead subject: "' . substr( $subject, 0, 80 ) . '" (' . gmdate( 'Y-m-d H:i', (int) $header->udate ) . ' UTC)';
			continue;
		}

		// Only mail Gmail confirms really came from the platform is accepted (see ppg_sender_verified()).
		if ( ! ppg_sender_verified( imap_fetchheader( $imap, $num ), $src_domain ) ) {
			$stats['ignored']++;
			continue;
		}
		$stats['matched']++; // Only lead emails received after go-live count.

		// Cheap duplicate check by Message-ID BEFORE downloading the body, so re-reading the last three days of
		// mail on every run costs almost nothing.
		$mid = isset( $header->message_id ) ? 'm' . substr( md5( (string) $header->message_id ), 0, 12 ) : '';
		if ( '' !== $mid && ppg_seen_has( array( $mid ) ) ) {
			$stats['duplicates']++;
			continue;
		}

		$body = ppg_email_text( $imap, $num );
		$lead = ppg_parse_lead_email( $source, $subject, $body );
		if ( null === $lead ) {
			$stats['detail'] = 'Skipped, lead body could not be parsed: "' . substr( $subject, 0, 80 ) . '"';
			continue;
		}
		$stats['considered']++;

		// The Message-ID means the same email is never imported twice. A lead with no customer email is
		// still created (identified by its lead id); only an unprocessable lead goes to the review queue.
		$lead['dedupe_key'] = $header->message_id ?? md5( $subject . $body );
		$lead['subject']    = $subject;

		// Checkatrade never sends the customer's email, so the contact is created without one and the team adds it
		// later. This reference (date, time, postcode) is what tells two leads with the same name apart; it is
		// unique per email and is the contact's ppg_lead_id.
		$ref                = ppg_checkatrade_ref( (int) $header->udate, $lead['postcode'], $lead['dedupe_key'] );
		$lead['lead_id']    = $ref;
		$lead['message']    = ppg_checkatrade_reference_lines( $ref, (int) $header->udate ) . $lead['message'];

		// The mailbox is never modified (no read flags). HubSpot failures are not remembered, so the next run
		// retries them. ppg_lead_process() logs failures itself and queues the ones that cannot be retried.
		$handled++;
		$result = ppg_lead_process( $source, $lead );
		if ( is_wp_error( $result ) ) {
			// Held in the review queue (nothing identifies the lead, or HubSpot refused it) versus a failure that will be retried.
			$http   = (int) ( $result->get_error_data()['http'] ?? 0 );
			$bucket = ( 'ppg_unprocessable' === $result->get_error_code() || ( $http >= 400 && $http < 500 && 429 !== $http ) ) ? 'held' : 'failed';
			$stats[ $bucket ]++;
			if ( 'failed' === $bucket ) {
				// HubSpot is down, slow or rate-limiting us: stop now instead of hammering it; retried on the next check.
				$stats['detail'] = 'HubSpot is not responding properly; the remaining enquiries will be retried on the next check.';
				break;
			}
			if ( '' !== $mid ) {
				ppg_seen_add( array( $mid ) ); // permanently refused: do not re-download it every run
			}
		} else {
			if ( 'duplicate_ignored' === ( $result['status'] ?? '' ) ) {
				$stats['duplicates']++;
			} else {
				$stats['imported']++;
			}
			if ( '' !== $mid ) {
				ppg_seen_add( array( $mid ) );
			}
		}
	}
	if ( $stats['ignored'] && '' === $stats['detail'] ) {
		$stats['detail'] = $stats['ignored'] . ' email(s) claiming to be from the platform failed sender verification and were ignored.';
	}
	imap_close( $imap );
	return $stats;
}
