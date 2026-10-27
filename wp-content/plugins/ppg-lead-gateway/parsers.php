<?php
/**
 * Per-platform parsers for lead notification emails. Pure PHP (no WordPress functions) so they can
 * be tested offline. Built from, and validated against, the real emails in info@ppgutters.co.uk.
 *
 * What the real emails contain (verified by scanning the whole mailbox):
 *  - Checkatrade, current format: name, postcode, start date, the customer's message. No contact
 *    block. An email/phone only appears if the customer typed it into their own message.
 *  - Checkatrade, legacy formats (2020 - Apr 2022: "Customer Callback Requested", "Customer
 *    Message", "New job from X"): a structured block with the customer's real email and phone.
 *  - Yell: name (subject), description, postcode. Email/phone only if typed into the description.
 *  - Yelp: name and message (older format) or name and service only (newer format). Email/phone
 *    only if typed into the message. Yelp's reply+...@messaging.yelp.com address is a relay and is
 *    never used as the customer's email.
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

/** Addresses that belong to the platforms, our own domain, or mail relays: never the customer. */
function ppg_is_platform_email( $email ) {
	$email = strtolower( $email );
	return (bool) preg_match(
		'/@([a-z0-9.\-]*\.)?(yelp\.com|yelp\.co\.uk|yell\.com|checkatrade\.com|ppgutters\.co\.uk|sendinblue\.com|sendgrid\.net|mailgun\.org|amazonses\.com|example\.com)$/',
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

	switch ( $source ) {
		case 'yelp':
			return ppg_parse_yelp( $subject, $body );
		case 'yell':
			return ppg_parse_yell( $subject, $body );
		case 'checkatrade':
			return ppg_parse_checkatrade( $subject, $body );
	}
	return null;
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

/* ---------------------------------------------------------------- Yelp */

/**
 * Yelp's own lead id and a safe lead URL, from the links in the email.
 *
 * The "Reply on Yelp Biz" button is a passwordless LOGIN link (anyone holding it can sign in to the
 * business account), and the "I'm not interested" / "I already replied" links perform actions. None of
 * those is ever returned or stored. The login link's return_url, however, is just the plain lead page
 * (/leads_center/<business>/leads/<lead id>), which is safe because Yelp still asks for a login.
 *
 * @return array{url:string,id:string}
 */
function ppg_yelp_links( $body ) {
	$out  = array( 'url' => '', 'id' => '' );
	$body = html_entity_decode( (string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	if ( preg_match( '#/login/passwordless/redirect/[^\s)]*?[?&]return_url=([^&\s)]+)#i', $body, $m )
		&& preg_match( '#^/leads_center/[A-Za-z0-9_\-]+/leads/([A-Za-z0-9_\-]+)#', urldecode( $m[1] ), $r ) ) {
		$out['url'] = 'https://biz.yelp.co.uk' . $r[0];
		$out['id']  = 'yelp:' . $r[1];
	}
	// Older emails link straight to the conversation or lead page.
	if ( '' === $out['id'] && preg_match( '#(https://biz\.yelp\.[a-z.]+/(?:messaging/[A-Za-z0-9_\-]+/thread|leads_center/[A-Za-z0-9_\-]+/leads)/([A-Za-z0-9_\-]{8,}))#', $body, $m ) ) {
		$out['url'] = $m[1];
		$out['id']  = 'yelp:' . $m[2];
	}
	// Last resort: the conversation id (32 hex) in the mark-as-replied link. The id only, never the link.
	if ( '' === $out['id'] && preg_match( '#/messaging/mark_as_replied[^/\s]*/([0-9a-f]{32})#i', $body, $m ) ) {
		$out['id'] = 'yelp:' . strtolower( $m[1] );
	}
	return $out;
}

/**
 * HubSpot contact property for each Yelp question we have a dedicated field for. Questions not listed
 * here (other service types ask different ones) still appear in the message and the timeline note.
 */
function ppg_yelp_answer_property( $question ) {
	static $map = array(
		'how tall is your home'                           => 'how_tall_is_your_home',
		'when do you require this service'                => 'when_do_you_require_this_service',
		"are there any other details you'd like to share" => 'are_there_any_other_details_youd_like_to_share',
		'in what location do you need the service'        => 'in_what_location_do_you_need_the_service',
	);
	$key = strtolower( trim( str_replace( "\u{2019}", "'", $question ), " \t\n?" ) );
	return $map[ $key ] ?? '';
}

/** The customer's answers: question/answer pairs between the "Sent to <business>" address and the customer's name. */
function ppg_yelp_answers( $body ) {
	if ( ! preg_match( '/\nSent to [^\n]+\n[^\n]*\n(.*?)\n[ \t]*[A-Z][^\n\[\]]{0,40}?\s[A-Z]\.[ \t]*\n/su', "\n" . $body, $m ) ) {
		return array();
	}
	$pairs = array();
	$q     = null;
	foreach ( preg_split( '/\n/', trim( $m[1] ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( '?' === substr( $line, -1 ) ) {
			$q           = $line;
			$pairs[ $q ] = '';
		} elseif ( null !== $q ) {
			$pairs[ $q ] = trim( $pairs[ $q ] . ' ' . $line );
		}
	}
	return array_filter( $pairs, 'strlen' );
}

function ppg_parse_yelp( $subject, $body ) {
	// Current format: "New Lead: Reply to Ajeet's gutter cleaning request" - name, service, the customer's
	// answers to Yelp's questions (home height, timing, details, location) and the lead links.
	if ( preg_match( '/^New Lead: Reply to (.+?)\x{2019}?\'?s (.+?) request$/iu', $subject, $m ) ) {
		$name = trim( $m[1] );
		if ( preg_match( '/^\s*([A-Z][^\n\[\]]{0,40}?\s[A-Z]\.)\s*$/mu', $body, $nm ) ) {
			$name = trim( $nm[1] ); // "Ajeet S." from the body.
		}
		$links   = ppg_yelp_links( $body );
		$answers = ppg_yelp_answers( $body );

		$lines    = array( 'Yelp quote request: ' . trim( $m[2] ) );
		$location = '';
		$fields   = array();
		foreach ( $answers as $question => $answer ) {
			$lines[] = $question . ' ' . $answer;
			if ( $prop = ppg_yelp_answer_property( $question ) ) {
				$fields[ $prop ] = $answer;
			}
			if ( preg_match( '/location|postcode|where/i', $question ) ) {
				$location = $answer;
			}
		}
		if ( ! $answers ) {
			$lines[] = '(The Yelp notification has no answers, email or phone; open the lead on Yelp.)';
		}
		if ( $links['url'] ) {
			$lines[] = 'Lead on Yelp: ' . $links['url'];
		}
		$postcode = '' !== $location ? ( ppg_find_postcode( $location ) ?: ( strlen( $location ) <= 20 ? $location : '' ) ) : '';

		return ppg_lead_result( $name, implode( "\n", $answers ), $postcode, $lines, $links['id'], $fields, $fields['are_there_any_other_details_youd_like_to_share'] ?? '' );
	}

	// Older format: "New Message: Gorm is requesting a quote from PP Gutters".
	// Replies ("New Reply Message"), "Message from ..." follow-ups, logins etc. are not new leads.
	if ( ! preg_match( '/^(?:New Message:\s*)?(.+?) is requesting a quote/iu', $subject, $m ) ) {
		return null;
	}
	$message = preg_match( '/\bHello\b[ \t]*\n(.*?)\n\s*(?:---|\|)/su', $body, $mm ) ? ppg_clean_text( $mm[1] ) : '';
	$links   = ppg_yelp_links( $body );

	return ppg_lead_result( $m[1], $message, ppg_find_postcode( $message ), array(
		'Yelp quote request',
		'Message: ' . $message,
		$links['url'] ? 'Lead on Yelp: ' . $links['url'] : '',
	), $links['id'], array(), $message );
}

/* ---------------------------------------------------------------- Yell */

function ppg_parse_yell( $subject, $body ) {
	// "New enquiry from Latif Latif". "Reminder: ..." repeats an earlier enquiry and is skipped.
	if ( ! preg_match( '/^New enquiry from (.+)$/iu', $subject, $m ) ) {
		return null;
	}
	$description = preg_match( '/\nDescription\s*\n(.*?)\n\s*Postcode/su', "\n" . $body, $dm ) ? ppg_clean_text( $dm[1] ) : '';
	// The Postcode field may be empty (the next line is then the "Click the Show enquiry..." sentence), so only a
	// value that really looks like a UK postcode is accepted.
	$postcode    = preg_match( '/\nPostcode\s*\n\s*([^\n(]+)/u', "\n" . $body, $pm ) && preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', trim( $pm[1] ) ) ? strtoupper( trim( $pm[1] ) ) : '';
	$link        = preg_match( '/Show enquiry\s*\(?\s*(https?:\/\/[^\s)]+)/i', $body, $lm ) ? $lm[1] : '';

	if ( '' === $postcode ) {
		// Oldest layout (2017-2018): no Postcode field; "Hi PP Gutters Ltd <link> Town, POSTCODE" and no description.
		$head        = false !== ( $p = stripos( $body, 'Show enquiry' ) ) ? substr( $body, 0, $p ) : $body;
		$postcode    = ppg_find_postcode( $head );
		$description = '' === $description && preg_match( '/\n([^\n]*(?:sent you an enquiry|looking for)[^\n]*)/iu', "\n" . $head, $em ) ? trim( $em[1] ) : $description;
	}

	return ppg_lead_result( $m[1], $description, $postcode, array(
		'Yell enquiry',
		'Description: ' . $description,
		$link ? 'View on Yell: ' . $link : '',
	), '', array(), $description );
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
		), '', array(), $desc );
	}

	// Legacy: "Checkatrade - New job from Federica Fort - respond now".
	if ( preg_match( '/^(?:Checkatrade - )?New job from (.+?) - respond now$/iu', $subject, $m ) ) {
		$desc = preg_match( '/Here.s what they need:\s*\n?\s*[\x{201C}"](.*?)[\x{201D}"]\s*\n/isu', $body, $dm ) ? ppg_clean_text( $dm[1] ) : '';
		return ppg_lead_result( $m[1], $body, ppg_find_postcode( $body ), array(
			'Checkatrade job request',
			$desc ? 'Job: ' . $desc : '',
		), '', array(), $desc );
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
	), '', array(), $message );
}
