<?php
/**
 * Parser for Checkatrade lead notification emails. Pure PHP (no WordPress functions) so it can be
 * tested offline. Built from, and validated against, the real emails in info@ppgutters.co.uk.
 *
 * What the real emails contain (verified by scanning the whole mailbox):
 *  - Current format: name, postcode, start date, the customer's message. No contact block. An
 *    email/phone only appears if the customer typed it into their own message.
 *  - Legacy formats (2020 - Apr 2022: "Customer Callback Requested", "Customer Message", "New job
 *    from X"): a structured block with the customer's real email and phone.
 *
 * ppg_parse_lead_email() returns null when the email is not a new lead (login/verification codes,
 * invoices, reminders, replies...), otherwise array( name, email, phone, postcode, message ).
 */

/* ---------------------------------------------------------------- text helpers */

/** HTML email -> readable text, keeping link targets as "text (url)". */
function ppg_html_to_text( $html ) {
	$html = preg_replace( '#<(script|style|head)\b.*?</\1>#is', '', (string) $html );
	$html = preg_replace_callback(
		'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
		function ( $m ) {
			return trim( strip_tags( $m[2] ) ) . ' (' . html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ')';
		},
		$html
	);
	$html = preg_replace( '#<br\s*/?>|</(p|div|tr|td|th|h[1-6]|li|table)>#i', "\n", $html );
	$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return ppg_normalise_text( $text );
}

function ppg_normalise_text( $text ) {
	$text = str_replace( "\r", '', (string) $text );
	$text = preg_replace( '/[\x{00A0}\x{200B}-\x{200F}\x{034F}\x{FEFF}\x{2007}\x{202F}]+/u', ' ', $text );
	$text = preg_replace( '/[ \t]+/u', ' ', $text );
	return trim( preg_replace( '/\n\s*\n+/u', "\n", $text ) );
}

function ppg_strip_urls( $text ) {
	return preg_replace( '#https?://[^\s)\]>]+#i', ' ', (string) $text );
}

/** Addresses that belong to Checkatrade, our own domain, or mail relays: never the customer. */
function ppg_is_platform_email( $email ) {
	$email = strtolower( $email );
	return (bool) preg_match(
		'/@([a-z0-9.\-]*\.)?(checkatrade\.com|ppgutters\.co\.uk|sendinblue\.com|sendgrid\.net|mailgun\.org|amazonses\.com|example\.com)$/',
		$email
	) || preg_match( '/^(no-?reply|donotreply|do-not-reply|bounces?\+|reply\+)/', $email );
}

/** First customer-looking email in the text (platform/relay addresses are skipped). */
function ppg_find_email( $text ) {
	$text = ppg_strip_urls( $text );
	if ( preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $text, $m ) ) {
		foreach ( $m[0] as $e ) {
			$e = rtrim( $e, '.' );
			if ( ! ppg_is_platform_email( $e ) && filter_var( $e, FILTER_VALIDATE_EMAIL ) ) {
				return strtolower( $e );
			}
		}
	}
	return '';
}

/** First UK phone number in the text. Also accepts mobiles typed without the leading 0 ("7770 446785"). */
function ppg_find_phone( $text ) {
	$text = ppg_strip_urls( $text );
	if ( preg_match( '/(?<![\d.\-])((?:\+44|0044)[\s\-]?\(?0?\)?[\s\-]?\d(?:[\s\-]?\d){8,9}|0\d(?:[\s\-]?\d){8,9})(?![\d])/', $text, $m ) ) {
		return trim( $m[1] );
	}
	if ( preg_match( '/(?<![\d.\-])(7\d{3}[\s\-]?\d{6})(?![\d])/', $text, $m ) ) {
		return '0' . preg_replace( '/[\s\-]/', '', $m[1] );
	}
	return '';
}

/** First UK postcode, ignoring addresses that appear in platform footers (Checkatrade HQ/London, PP Gutters). */
function ppg_find_postcode( $text ) {
	if ( ! preg_match_all( '/\b([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2})\b/i', ppg_strip_urls( $text ), $m ) ) {
		return '';
	}
	foreach ( $m[1] as $p ) {
		if ( ! in_array( strtoupper( str_replace( ' ', '', $p ) ), array( 'PO63EN', 'EC2Y9SS', 'HP136BA' ), true ) ) {
			return strtoupper( preg_replace( '/\s+/', ' ', trim( $p ) ) );
		}
	}
	return '';
}

function ppg_clean_text( $text ) {
	return ppg_normalise_text( $text );
}

function ppg_first_url_after( $body, $needle, $host_pattern ) {
	$pos = stripos( $body, $needle );
	if ( false !== $pos && preg_match( '#(https?://' . $host_pattern . '[^\s)\]>]*)#i', substr( $body, $pos ), $m ) ) {
		return $m[1];
	}
	return '';
}

/* ---------------------------------------------------------------- dispatcher */

function ppg_parse_lead_email( $source, $subject, $body ) {
	$subject = trim( preg_replace( '/\s+/u', ' ', $subject ) );
	$subject = preg_replace( '/^(?:(?:FW|Fwd|RE):\s*)+/i', '', $subject ); // manually forwarded copies
	$body    = ppg_normalise_text( $body );

	return 'checkatrade' === $source ? ppg_parse_checkatrade( $subject, $body ) : null;
}

function ppg_lead_result( $name, $text_for_contact, $postcode, $message_lines, $lead_id = '', $fields = array(), $description = '' ) {
	$phone = ppg_find_phone( $text_for_contact );
	return array(
		'description' => trim( preg_replace( '/\s+/u', ' ', $description ) ), // the customer's own words
		'lead_id'  => $lead_id,
		'fields'   => $fields,
		'name'     => trim( preg_replace( '/\s+/u', ' ', $name ) ),
		'email'    => ppg_find_email( $text_for_contact ),
		'phone'    => $phone,
		'postcode' => $postcode,
		'message'  => implode( "\n", array_filter( $message_lines ) ),
	);
}

/* ---------------------------------------------------------------- Checkatrade */

function ppg_parse_checkatrade( $subject, $body ) {
	// Legacy: "Checkatrade - Customer Callback Requested" / "Checkatrade - Customer Message".
	if ( preg_match( '/^Checkatrade - Customer (?:Callback Requested|Message)$/i', $subject ) ) {
		$name = preg_match( '/interested in your services\.?\s*\n\s*([^\n]+)/iu', $body, $nm ) ? trim( $nm[1] ) : '';
		$desc = preg_match( '/described their job as the\s+following:?\s*(.*?)\s*(?:You can make changes|Please get in touch|Kind regards)/isu', $body, $dm ) ? ppg_clean_text( $dm[1] ) : '';
		return ppg_lead_result( $name, $body, ppg_find_postcode( $body ), array(
			'Checkatrade enquiry (' . $subject . ')',
			$desc ? 'Job: ' . $desc : '',
		), '', ppg_checkatrade_fields( '', '', ppg_find_postcode( $body ) ), $desc );
	}

	// Legacy: "Checkatrade - New job from Federica Fort - respond now".
	if ( preg_match( '/^(?:Checkatrade - )?New job from (.+?) - respond now$/iu', $subject, $m ) ) {
		$desc = preg_match( '/Here.s what they need:\s*\n?\s*[\x{201C}"](.*?)[\x{201D}"]\s*\n/isu', $body, $dm ) ? ppg_clean_text( $dm[1] ) : '';
		$pc = ppg_find_postcode( $body );
		if ( '' === $desc && preg_match( '/\n[^\n]*?.s message\s*\n(.*?)\s*(?:Respond to request|Reply to request|I.m interested)/isu', "\n" . $body, $dm ) ) {
			// 2023 layout: "New job in SL7 3NN", Name / Start date / Job created / "<Name>'s message" / "Respond to request".
			$desc = ppg_clean_text( $dm[1] );
			$pc   = preg_match( '/New job in\s+([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2})/iu', $body, $pm ) ? strtoupper( trim( $pm[1] ) ) : $pc;
		}
		$start = preg_match( '/Start date\s*\n\s*([^\n]+)/iu', $body, $sm ) ? trim( $sm[1] ) : '';
		return ppg_lead_result( $m[1], $body, $pc, array(
			'Checkatrade job request',
			$start ? 'Start date: ' . $start : '',
			$desc ? 'Job: ' . $desc : '',
		), '', ppg_checkatrade_fields( '', $start, $pc ), $desc );
	}

	// Current: "Gutter Cleaning & Clearance in RG41 3AP - respond now" (also "New Checkatrade job in ...").
	if ( ! preg_match( '/^(.+?) in ([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}) - respond now$/iu', $subject, $m ) ) {
		return null;
	}
	// The name sits between a "Name"/"Customer" label and "Start date": inline ("Name Lesley Hatton Start
	// date"), or split over lines, depending on the year. Fall back to the "<Name>'s message" heading.
	$name = '';
	if ( preg_match( '/(?:^|\n)[ \t]*(?:Name|Customer)\b\s*((?:[^\n]*\n){0,3}?[^\n]*?)\s*Start date/iu', $body, $nm ) && mb_strlen( trim( $nm[1] ) ) <= 80 ) {
		$name = trim( $nm[1] );
	} elseif ( preg_match( '/\n\s*([^\n]{1,60}?)[\x{2019}\']s message\s*\n/u', "\n" . $body, $nm ) ) {
		$name = trim( $nm[1] );
	}
	$start   = preg_match( '/Start date\s*\n\s*([^\n]+)/iu', $body, $sm ) ? trim( $sm[1] ) : '';
	$message = preg_match( '/\n[^\n]*?.s message\s*\n(.*?)\s*I.m interested/su', "\n" . $body, $mm ) ? ppg_clean_text( $mm[1] ) : '';
	// Deliberately NO link: the only job link in the email is the "I'm interested" button, an action (it registers
	// interest in a billable lead). Storing it in HubSpot would let anyone trigger that with one click.

	return ppg_lead_result( $name, $message, strtoupper( trim( $m[2] ) ), array(
		'Checkatrade job request: ' . trim( $m[1] ),
		$start ? 'Start date: ' . $start : '',
		$message ? 'Message: ' . $message : '',
		'(Open this job in the Checkatrade app or members area to respond.)',
	), '', ppg_checkatrade_fields( $m[1], $start, $m[2] ), $message );
}

/**
 * The dedicated HubSpot contact fields the website forms also fill, so a Checkatrade lead shows the same
 * columns in the contact list: Services, Start date and Post Code (HubSpot internal names services,
 * start_date, post_code).
 */
function ppg_checkatrade_fields( $service, $start, $postcode ) {
	return array_filter( array(
		'services'   => trim( preg_replace( '/\s+/u', ' ', (string) $service ) ),
		'start_date' => trim( (string) $start ),
		'post_code'  => strtoupper( trim( (string) $postcode ) ),
	), 'strlen' );
}


/* ---------------------------------------------------------------- Lead reference */

/**
 * Human-readable unique reference for a Checkatrade lead, e.g. CT-20261018-2057-RG47HL-3F9A2:
 * UK date and time the email arrived, postcode, and 5 characters of a hash of the email's Message-ID.
 * Two customers with the same name differ in at least one of these, and the postcode, date and time are
 * also visible on the Checkatrade jobs dashboard, so the team can match a HubSpot contact to its job.
 */
function ppg_checkatrade_ref( $timestamp, $postcode, $message_id ) {
	$when = ( new DateTime( '@' . (int) $timestamp ) )->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'Ymd-Hi' );
	$pc   = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $postcode ) );
	return 'CT-' . $when . '-' . ( '' !== $pc ? $pc : 'NOPOSTCODE' ) . '-' . strtoupper( substr( md5( (string) $message_id ), 0, 5 ) );
}

/** The two lines put at the top of the contact's note/message so the team can match the lead on the Checkatrade dashboard. */
function ppg_checkatrade_reference_lines( $ref, $timestamp ) {
	$when = ( new DateTime( '@' . (int) $timestamp ) )->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'D j M Y, H:i' );
	return 'Lead reference: ' . $ref . "\n" . 'Email received (UK time): ' . $when . "\n";
}
