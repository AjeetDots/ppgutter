<?php
/**
 * Plugin Name: PPG Lead Gateway
 * Description: REST endpoint that receives leads from third-party platforms (Yell, Checkatrade, Yelp, ...) and upserts them into HubSpot.
 * Version:     1.0.0
 *
 * Required configuration (wp-config.php constant OR environment variable):
 *   PPG_HUBSPOT_TOKEN  HubSpot Private App token (scopes: crm.objects.contacts.read/write)
 *   PPG_LEAD_SECRET    Shared secret the sender must supply in the X-PPG-Secret header
 *
 * Endpoint: POST /wp-json/ppg/v1/lead
 *   JSON body, either structured:
 *     { "source": "yell", "name": "...", "email": "...", "phone": "...", "postcode": "...", "message": "..." }
 *   or a raw forwarded email, which is parsed per source:
 *     { "source": "checkatrade", "subject": "...", "body": "..." }
 *
 * HubSpot needs a custom single-line-text contact property "lead_source_platform".
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/parsers.php';
require_once __DIR__ . '/email-poller.php';
require_once __DIR__ . '/website-forms.php';
require_once __DIR__ . '/status-page.php';

function ppg_lead_cfg( $key ) {
	if ( defined( $key ) ) {
		return constant( $key );
	}
	$env = getenv( $key );
	return false === $env ? '' : $env;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'ppg/v1', '/lead', array(
		'methods'             => 'POST',
		'callback'            => 'ppg_lead_handle',
		'permission_callback' => 'ppg_lead_authorise',
	) );
} );

function ppg_lead_authorise( WP_REST_Request $request ) {
	$secret = (string) ppg_lead_cfg( 'PPG_LEAD_SECRET' );
	if ( '' === $secret ) {
		return new WP_Error( 'ppg_not_configured', 'Lead secret not configured.', array( 'status' => 500 ) );
	}
	if ( ! hash_equals( $secret, (string) $request->get_header( 'X-PPG-Secret' ) ) ) {
		return new WP_Error( 'ppg_forbidden', 'Invalid secret.', array( 'status' => 401 ) );
	}
	return true;
}

function ppg_lead_handle( WP_REST_Request $request ) {
	$data   = $request->get_json_params();
	$source = sanitize_key( $data['source'] ?? '' );
	if ( '' === $source ) {
		return new WP_Error( 'ppg_bad_request', 'Missing "source".', array( 'status' => 400 ) );
	}

	// Raw forwarded email ({source, subject, body}): same parsers as the mailbox poller.
	if ( ! empty( $data['body'] ) ) {
		$body   = preg_match( '/<(html|body|div|p|br|table)\b/i', $data['body'] ) ? ppg_html_to_text( $data['body'] ) : (string) $data['body'];
		$parsed = ppg_parse_lead_email( $source, (string) ( $data['subject'] ?? '' ), $body );
		if ( null === $parsed ) {
			return new WP_Error( 'ppg_not_a_lead', 'Email is not a recognised new lead.', array( 'status' => 422 ) );
		}
		$data = array_merge( $parsed, array_filter( $data, function ( $v, $k ) {
			return ! in_array( $k, array( 'body', 'subject' ), true ) && '' !== $v;
		}, ARRAY_FILTER_USE_BOTH ) );
	}

	// Zapier / Make (e.g. Yelp Leads "New Lead"): flat JSON. Yelp's lead id is namespaced; Yelp's temporary
	// (masked) email is a relay address, never the customer's real one, so it is kept in its own fields and
	// is only ever stored there, even if a mapping puts it in "email".
	if ( ! empty( $data['lead_id'] ) && 0 !== strpos( (string) $data['lead_id'], $source . ':' ) ) {
		$data['lead_id'] = $source . ':' . $data['lead_id'];
	}
	$masked = (string) ( $data['masked_email'] ?? '' );
	if ( '' === $masked && ! empty( $data['email'] ) && ppg_is_platform_email( (string) $data['email'] ) ) {
		$masked = (string) $data['email'];
	}
	$fields = (array) ( $data['fields'] ?? array() );
	if ( '' !== $masked && is_email( $masked ) ) {
		$fields['yelp_masked_email'] = $masked;
		if ( ! empty( $data['masked_email_expiry'] ) ) {
			$fields['yelp_masked_email_expires'] = (string) $data['masked_email_expiry'];
		}
	}
	// Answers sent under the HubSpot property names themselves.
	foreach ( array( 'how_tall_is_your_home', 'when_do_you_require_this_service', 'are_there_any_other_details_youd_like_to_share', 'in_what_location_do_you_need_the_service' ) as $key ) {
		if ( ! empty( $data[ $key ] ) ) {
			$fields[ $key ] = (string) $data[ $key ];
		}
	}
	$data['fields'] = $fields;
	if ( empty( $data['dedupe_key'] ) && ! empty( $data['lead_id'] ) ) {
		$data['dedupe_key'] = (string) $data['lead_id'];
	}

	// Later news about a lead already imported (Yelp's "Phone Availability" trigger: the customer's phone arrives
	// minutes after the lead). Send "update": true with the same lead_id.
	if ( ! empty( $data['update'] ) ) {
		return ppg_lead_update( $source, $data );
	}

	return ppg_lead_process( $source, $data );
}

/**
 * Add late data (phone, answers, masked email) to a contact that already exists. Matched by ppg_lead_id only.
 * Never creates a contact, never touches the name or message, never adds a second note, and is not blocked
 * by the duplicate memory (that is the point). If the lead is not in HubSpot yet the caller gets a 404 to retry.
 */
function ppg_lead_update( $source, array $data ) {
	if ( empty( $data['lead_id'] ) ) {
		return new WP_Error( 'ppg_bad_request', 'An update needs a lead_id.', array( 'status' => 400 ) );
	}
	$props = array();
	$phone = preg_replace( '/[^\d+]/', '', (string) ( $data['phone'] ?? '' ) );
	if ( '' !== $phone ) {
		$props['phone'] = $phone;
	}
	$email = sanitize_email( $data['email'] ?? '' );
	if ( '' !== $email && ! ppg_is_platform_email( $email ) ) {
		$props['email'] = $email;
	}
	foreach ( (array) ( $data['fields'] ?? array() ) as $prop => $value ) {
		if ( preg_match( '/^[a-z0-9_]+$/', (string) $prop ) && '' !== trim( (string) $value ) ) {
			$props[ $prop ] = sanitize_text_field( (string) $value );
		}
	}
	if ( ! $props ) {
		return new WP_Error( 'ppg_nothing_to_update', 'No phone, email or fields to update.', array( 'status' => 422 ) );
	}
	$found = ppg_lead_hubspot( 'POST', '/crm/v3/objects/contacts/search', array(
		'filterGroups' => array( array( 'filters' => array(
			array( 'propertyName' => 'ppg_lead_id', 'operator' => 'EQ', 'value' => (string) $data['lead_id'] ),
		) ) ),
		'limit'        => 1,
	) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( empty( $found['results'][0]['id'] ) ) {
		return new WP_Error( 'ppg_lead_not_found', 'That lead is not in HubSpot (yet); retry shortly.', array( 'status' => 404 ) );
	}
	$id  = $found['results'][0]['id'];
	$res = ppg_lead_hubspot( 'PATCH', '/crm/v3/objects/contacts/' . $id, array( 'properties' => $props ) );
	if ( is_wp_error( $res ) && false !== stripos( $res->get_error_message(), 'does not exist' ) ) {
		unset( $props['yelp_masked_email'], $props['yelp_masked_email_expires'] ); // optional fields that may not exist
		$res = $props ? ppg_lead_hubspot( 'PATCH', '/crm/v3/objects/contacts/' . $id, array( 'properties' => $props ) ) : array();
	}
	return is_wp_error( $res ) ? $res : array( 'status' => 'updated', 'hubspot_id' => $id, 'fields' => array_keys( $props ) );
}

/**
 * Normalise a lead, de-duplicate it and create/update the HubSpot contact.
 *
 * $lead keys: name, email, phone, postcode, message, lead_id (platform id, optional),
 *             dedupe_key (email Message-ID or platform job id), subject.
 *
 * Email is optional. Identity (so the same lead is never created twice, and different people who
 * share a name are never merged):
 *   - with an email: HubSpot matches the contact on that email (the same customer is one contact);
 *   - without an email: the contact is upserted on the custom unique property ppg_lead_id, built from
 *     the platform's lead id where there is one, otherwise from the email Message-ID.
 * Names and phone numbers are never used to match contacts.
 *
 * Only leads that genuinely cannot be created go to the review queue: nothing identifies the lead
 * (no name, email or phone), or HubSpot permanently rejects it. Temporary failures are retried.
 */
function ppg_lead_process( $source, array $lead ) {
	$name     = sanitize_text_field( $lead['name'] ?? '' );
	$email    = sanitize_email( $lead['email'] ?? '' );
	$phone    = preg_replace( '/[^\d+]/', '', (string) ( $lead['phone'] ?? '' ) );
	$postcode = sanitize_text_field( $lead['postcode'] ?? '' );
	$message  = sanitize_textarea_field( $lead['message'] ?? '' );
	$key      = (string) ( $lead['dedupe_key'] ?? '' );

	// Never present a platform/relay address (e.g. Yelp's reply+...@messaging.yelp.com) as the customer's.
	if ( '' !== $email && ppg_is_platform_email( $email ) ) {
		$email = '';
	}

	$queue_item = array(
		'name'     => $name,
		'phone'    => $phone,
		'postcode' => $postcode,
		'message'  => $message,
		'subject'  => sanitize_text_field( $lead['subject'] ?? '' ),
		'key'      => '' !== $key ? $key : md5( $source . $name . $postcode . $message ),
	);

	if ( '' === $name && '' === $email && '' === $phone ) {
		ppg_lead_review_add( $source, $queue_item, 'Nothing identifies this lead (no name, email or phone)' );
		return new WP_Error( 'ppg_unprocessable', 'Lead has no name, email or phone; held for review.', array( 'status' => 422 ) );
	}

	// Stable id for this lead: the platform's own id if known, otherwise derived from the email Message-ID,
	// otherwise a content fingerprint.
	$lead_id = ! empty( $lead['lead_id'] )
		? (string) $lead['lead_id']
		: $source . ':' . md5( '' !== $key ? $key : $email . '|' . $phone . '|' . $name . '|' . $postcode . '|' . substr( $message, 0, 300 ) );

	// Ignore duplicate processing of the same lead: by lead id, and by content so a re-sent or repeated
	// notification does not create a second record. Remembered for two weeks (the mailbox is only
	// searched over the last three days); HubSpot's own unique matching is the permanent safeguard.
	$locks = array(
		'ppg_lead_i_' . md5( $lead_id ),
		'ppg_lead_c_' . md5( $source . $email . $phone . $name . $postcode . substr( $message, 0, 300 ) ),
	);
	foreach ( $locks as $lock ) {
		if ( get_transient( $lock ) ) {
			return array( 'status' => 'duplicate_ignored' );
		}
	}

	$parts = preg_split( '/\s+/', $name, 2 );
	$props = array_filter( array(
		'firstname'            => $parts[0] ?? '',
		'lastname'             => $parts[1] ?? '',
		'email'                => $email,
		'phone'                => $phone,
		'zip'                  => $postcode,
		'message'              => $message,
		'lead_source_platform' => $source,
	), 'strlen' );

	// Dedicated HubSpot fields. "Lead Source" (lead_page_url) names the platform, as the website forms do with
	// "PP Gutter Website"; "How can we help?" holds the customer's own description, as on the website forms.
	// Plus the customer's answers (e.g. Yelp's "How tall is your home?").
	$platform_names = array( 'yelp' => 'Yelp', 'yell' => 'Yell', 'checkatrade' => 'Checkatrade' );
	$fields         = array( 'lead_page_url' => $platform_names[ $source ] ?? ucfirst( $source ) );
	if ( '' !== trim( (string) ( $lead['description'] ?? '' ) ) ) {
		$fields['how_can_we_help'] = trim( preg_replace( '/\s+/u', ' ', (string) $lead['description'] ) ); // one line, like a form answer
	}
	foreach ( (array) ( $lead['fields'] ?? array() ) as $prop => $value ) {
		if ( preg_match( '/^[a-z0-9_]+$/', (string) $prop ) && '' !== trim( (string) $value ) ) {
			$fields[ $prop ] = (string) $value;
		}
	}
	$fields = array_map( 'sanitize_text_field', $fields );

	if ( '' !== $email ) {
		$id_prop = 'email';
		$id_val  = $email;
	} else {
		$props['ppg_lead_id'] = $lead_id;
		$id_prop              = 'ppg_lead_id';
		$id_val               = $lead_id;
	}
	$result = ppg_lead_hubspot_upsert( $props + $fields, $id_prop, $id_val );
	if ( $fields && is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'does not exist' ) ) {
		// A custom field was deleted/renamed in HubSpot: do not lose the lead; the answers are still in the
		// Message property and the timeline note.
		error_log( '[ppg-lead-gateway] a HubSpot answer field is missing; importing without the dedicated fields.' );
		$result = ppg_lead_hubspot_upsert( $props, $id_prop, $id_val );
	}
	if ( ! is_wp_error( $result ) && ! empty( $result['created'] ) ) {
		ppg_lead_hubspot_default_status( $result['id'] ); // only for a contact we just created: never resets a status your team set
	}
	if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
		ppg_lead_hubspot_add_source( $result['id'], $fields['lead_page_url'] );
	}
	if ( ! is_wp_error( $result ) && '' !== $message && ! $result['duplicate'] ) {
		ppg_lead_hubspot_note( $result['id'], $source, $message );
	}

	if ( is_wp_error( $result ) ) {
		$http = (int) ( $result->get_error_data()['http'] ?? 0 );
		if ( $http >= 400 && $http < 500 && 429 !== $http ) {
			// HubSpot permanently refuses this lead: park it so it is not retried forever.
			ppg_lead_review_add( $source, $queue_item, 'HubSpot rejected the lead: ' . substr( $result->get_error_message(), 0, 300 ) );
		} else {
			error_log( '[ppg-lead-gateway] ' . $result->get_error_message() . ' (will retry)' );
		}
		return $result;
	}

	foreach ( $locks as $lock ) {
		set_transient( $lock, 1, 2 * WEEK_IN_SECONDS );
	}
	ppg_lead_review_remove( $source, $queue_item['key'] ); // e.g. an earlier rejection that has since been fixed
	return array( 'status' => 'ok', 'hubspot_id' => $result['id'] );
}

/* ---------------------------------------------------------------------------
 * Review queue: only for leads that genuinely cannot be created in HubSpot.
 * ------------------------------------------------------------------------- */

/** Returns true when the lead was newly queued (false if it was already there). */
function ppg_lead_review_add( $source, array $item, $reason ) {
	$queue = get_option( 'ppg_lead_review', array() );
	$id    = md5( $source . $item['key'] );
	if ( isset( $queue[ $id ] ) ) {
		return false;
	}
	$queue[ $id ] = array_merge( $item, array(
		'source'   => $source,
		'reason'   => $reason,
		'received' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
	) );
	$queue = array_slice( $queue, -200, null, true ); // keep the latest 200
	update_option( 'ppg_lead_review', $queue, false );
	error_log( "[ppg-lead-gateway] $source lead held for review ($reason): {$item['name']}" );
	return true;
}

function ppg_lead_review_remove( $source, $key ) {
	$queue = get_option( 'ppg_lead_review', array() );
	$id    = md5( $source . $key );
	if ( isset( $queue[ $id ] ) ) {
		unset( $queue[ $id ] );
		update_option( 'ppg_lead_review', $queue, false );
	}
}

add_action( 'admin_menu', function () {
	add_management_page( 'PPG Lead Review', 'PPG Lead Review', 'manage_options', 'ppg-lead-review', 'ppg_lead_review_page' );
} );

function ppg_lead_review_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ppg_clear'] ) && check_admin_referer( 'ppg_clear_review' ) ) {
		delete_option( 'ppg_lead_review' );
	}
	$queue = array_reverse( get_option( 'ppg_lead_review', array() ) );
	echo '<div class="wrap"><h1>PPG Lead Review</h1>';
	echo '<p>Leads that could <strong>not</strong> be created in HubSpot (nothing identifies the lead, or HubSpot rejected it). Leads without an email address are created normally and do not appear here.</p>';
	if ( ! $queue ) {
		echo '<p>Nothing waiting for review.</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Platform</th><th>Name</th><th>Postcode</th><th>Phone</th><th>Why held</th><th>Details</th></tr></thead><tbody>';
	foreach ( $queue as $row ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><pre style="white-space:pre-wrap;margin:0">%s</pre></td></tr>',
			esc_html( $row['received'] ),
			esc_html( $row['source'] ),
			esc_html( $row['name'] ),
			esc_html( $row['postcode'] ),
			esc_html( $row['phone'] ),
			esc_html( $row['reason'] ),
			esc_html( $row['message'] )
		);
	}
	echo '</tbody></table><form method="post" style="margin-top:1em">';
	wp_nonce_field( 'ppg_clear_review' );
	echo '<button class="button" name="ppg_clear" value="1">Clear list</button></form></div>';
}

/* ---------------------------------------------------------------------------
 * Checkatrade lead delivery webhook: POST /wp-json/ppg/v1/checkatrade
 * Checkatrade does not send a secret; it authenticates by source IP only.
 * ------------------------------------------------------------------------- */

// Off by default: leads arrive by email. Define PPG_ENABLE_CHECKATRADE_WEBHOOK as true to turn it on.
add_action( 'rest_api_init', function () {
	if ( ! ppg_lead_cfg( 'PPG_ENABLE_CHECKATRADE_WEBHOOK' ) ) {
		return;
	}
	register_rest_route( 'ppg/v1', '/checkatrade', array(
		'methods'             => 'POST',
		'callback'            => 'ppg_checkatrade_handle',
		'permission_callback' => 'ppg_checkatrade_authorise',
	) );
} );

/** Production source IPs published at developers.checkatrade.com/reference/leads. */
function ppg_checkatrade_ips() {
	// New integrations only need the new IPs; the old ones are for existing trades.
	$ips = array( '35.246.15.126', '35.242.187.239', '34.39.18.129' );
	if ( 'production' !== wp_get_environment_type() ) {
		// Test payloads come from the development IPs.
		$ips = array_merge( $ips, array( '34.105.162.121', '34.89.85.173', '35.234.151.94' ) );
	}
	return $ips;
}

/**
 * Client IP. REMOTE_ADDR by default; if the site sits behind a proxy/CDN, define
 * PPG_CLIENT_IP_HEADER in wp-config.php (e.g. 'HTTP_CF_CONNECTING_IP').
 */
function ppg_client_ip() {
	$header = (string) ppg_lead_cfg( 'PPG_CLIENT_IP_HEADER' );
	if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
		return trim( explode( ',', wp_unslash( $_SERVER[ $header ] ) )[0] );
	}
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function ppg_checkatrade_authorise() {
	if ( ! in_array( ppg_client_ip(), ppg_checkatrade_ips(), true ) ) {
		return new WP_Error( 'ppg_forbidden', 'Source not allowed.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Question => answer pairs from leadEnrichment. Documented shape (developers.checkatrade.com/reference/post_leads):
 *   { "questions": [ { "questionId", "title", "format", "answers": [ { "key", "value", "url" } ] } ] }
 * answer.value may be a string, number or null; photo answers carry a url. A flat list of
 * {question|title, answer|answers|value} items is also accepted, and so is a {question: answer} map.
 */
function ppg_checkatrade_pairs( $value ) {
	$list = ( is_array( $value ) && isset( $value['questions'] ) ) ? $value['questions'] : $value;
	$out  = array();
	foreach ( (array) $list as $k => $item ) {
		if ( is_array( $item ) ) {
			$q    = trim( (string) ( $item['question'] ?? $item['title'] ?? $item['name'] ?? '' ) );
			$raw  = $item['answers'] ?? $item['answer'] ?? $item['value'] ?? '';
			$vals = array();
			foreach ( (array) $raw as $a ) {
				$v = is_array( $a ) ? ( $a['value'] ?? ( $a['url'] ?? '' ) ) : $a;
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					$vals[] = trim( (string) $v );
				}
			}
			if ( '' !== $q && $vals ) {
				$out[ $q ] = implode( ', ', $vals );
			}
		} elseif ( is_string( $k ) && is_scalar( $item ) && '' !== trim( (string) $item ) ) {
			$out[ $k ] = trim( (string) $item );
		}
	}
	return $out;
}

function ppg_checkatrade_handle( WP_REST_Request $request ) {
	$d = $request->get_json_params();
	if ( ! is_array( $d ) ) {
		// Checkatrade's published example is not valid JSON; log the size (never the personal data) to help the first test.
		error_log( '[ppg-lead-gateway] Checkatrade webhook: body is not valid JSON (' . strlen( (string) $request->get_body() ) . ' bytes).' );
		return new WP_Error( 'ppg_bad_request', 'Invalid JSON.', array( 'status' => 400 ) );
	}
	if ( empty( $d['jobId'] ) ) {
		return new WP_Error( 'ppg_bad_request', 'Missing jobId.', array( 'status' => 400 ) );
	}

	$lines = array();
	foreach ( array( 'title' => 'Job', 'category' => 'Category', 'jobDescription' => 'Description' ) as $key => $label ) {
		if ( ! empty( $d[ $key ] ) ) {
			$lines[] = "$label: " . $d[ $key ];
		}
	}
	if ( ! empty( $d['preferredStart']['option'] ) ) {
		$lines[] = 'Preferred start: ' . $d['preferredStart']['option'] . ( ! empty( $d['preferredStart']['date'] ) ? ' (' . $d['preferredStart']['date'] . ')' : '' );
	}
	if ( ! empty( $d['source'] ) ) {
		$lines[] = 'Checkatrade lead type: ' . $d['source']; // REQUEST_A_QUOTE | DIRECT_MESSAGE | QUOTE_REQUEST
	}
	foreach ( (array) ( $d['additionalInformation'] ?? array() ) as $qa ) {
		if ( is_array( $qa ) && isset( $qa['question'], $qa['answer'] ) ) {
			$lines[] = $qa['question'] . ': ' . ( is_array( $qa['answer'] ) ? implode( ', ', $qa['answer'] ) : $qa['answer'] );
		}
	}
	foreach ( (array) ( $d['media'] ?? array() ) as $m ) {
		$url = is_array( $m ) ? ( $m['url'] ?? '' ) : $m;
		if ( is_string( $url ) && '' !== $url ) {
			$lines[] = 'Image: ' . esc_url_raw( $url );
		}
	}
	// leadEnrichment: structured questions and answers (shape not documented; accept the common ones).
	foreach ( ppg_checkatrade_pairs( $d['leadEnrichment'] ?? array() ) as $question => $answer ) {
		$lines[] = $question . ': ' . $answer;
	}
	$lines[] = 'Checkatrade job ID: ' . $d['jobId'];

	$result = ppg_lead_process( 'checkatrade', array(
		'name'       => $d['customerName'] ?? '',
		'email'      => $d['customerEmail'] ?? '',
		'phone'      => $d['customerPhone'] ?? '',
		'postcode'   => $d['postcode'] ?? '',
		'message'    => implode( "\n", $lines ),
		'description' => (string) ( $d['jobDescription'] ?? '' ), // the customer's own words
		'lead_id'    => 'checkatrade:' . $d['jobId'], // Checkatrade's own job id
		'dedupe_key' => (string) $d['jobId'],
		'subject'    => 'Checkatrade webhook',
	) );

	if ( is_wp_error( $result ) ) {
		return $result; // Non-2xx: Checkatrade sees the failure.
	}
	return new WP_REST_Response( $result, 201 ); // Checkatrade expects 201 as acknowledgement.
}

function ppg_lead_hubspot( $method, $path, $body ) {
	$token = (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' );
	if ( '' === $token ) {
		return new WP_Error( 'ppg_not_configured', 'HubSpot token not configured.', array( 'status' => 500 ) );
	}
	$response = wp_remote_request( 'https://api.hubapi.com' . $path, array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => null === $body ? null : wp_json_encode( $body ),
	) );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'ppg_hubspot_down', $response->get_error_message(), array( 'status' => 502, 'http' => 0 ) );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code >= 300 ) {
		return new WP_Error( 'ppg_hubspot_error', "HubSpot $code: " . wp_remote_retrieve_body( $response ), array( 'status' => 502, 'http' => $code ) );
	}
	return $json;
}

/**
 * Create or update a contact, matched on "email" or on the custom property "ppg_lead_id".
 * Returns the HubSpot contact id, or WP_Error (data['http'] holds the HTTP status).
 *
 * HubSpot's upsert only works on a property created with "Require unique values", which is the
 * recommended setup (HubSpot then guarantees no duplicate lead id, permanently). If ppg_lead_id is NOT
 * unique, HubSpot answers 400 "non-unique property"; the plugin then falls back to search-then-create.
 * That fallback is safe in normal operation (the plugin also remembers processed leads) but HubSpot's
 * search index can lag a few seconds, so it cannot give the same hard guarantee.
 */
function ppg_lead_hubspot_upsert( array $props, $id_property, $id_value ) {
	if ( 'email' === $id_property || ! get_transient( 'ppg_upsert_unsupported' ) ) {
		$res = ppg_lead_hubspot( 'POST', '/crm/v3/objects/contacts/batch/upsert', array(
			'inputs' => array( array( 'idProperty' => $id_property, 'id' => $id_value, 'properties' => $props ) ),
		) );
		if ( ! is_wp_error( $res ) ) {
			$row = $res['results'][0] ?? array();
			// Matching on ppg_lead_id and finding an existing contact means this very lead was already imported.
			return array(
				'id'        => $row['id'] ?? '',
				'duplicate' => 'ppg_lead_id' === $id_property && false === ( $row['new'] ?? true ),
				'created'   => true === ( $row['new'] ?? false ),
			);
		}
		$data = $res->get_error_data();
		if ( 'email' === $id_property || 400 !== (int) ( $data['http'] ?? 0 ) || false === stripos( $res->get_error_message(), 'non-unique' ) ) {
			return $res;
		}
		set_transient( 'ppg_upsert_unsupported', 1, DAY_IN_SECONDS );
		error_log( '[ppg-lead-gateway] HubSpot property ' . $id_property . ' is not unique; using search-then-create. Recreate it with "Require unique values" for a hard duplicate guarantee.' );
	}

	$found = ppg_lead_hubspot( 'POST', '/crm/v3/objects/contacts/search', array(
		'filterGroups' => array( array( 'filters' => array(
			array( 'propertyName' => $id_property, 'operator' => 'EQ', 'value' => $id_value ),
		) ) ),
		'limit'        => 1,
	) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( ! empty( $found['results'][0]['id'] ) ) {
		$id  = $found['results'][0]['id'];
		$res = ppg_lead_hubspot( 'PATCH', '/crm/v3/objects/contacts/' . $id, array( 'properties' => $props ) );
		return is_wp_error( $res ) ? $res : array( 'id' => $id, 'duplicate' => true, 'created' => false );
	}
	$res = ppg_lead_hubspot( 'POST', '/crm/v3/objects/contacts', array( 'properties' => $props ) );
	return is_wp_error( $res ) ? $res : array( 'id' => $res['id'] ?? '', 'duplicate' => false, 'created' => true );
}

/**
 * Lead Status "New" (HubSpot's internal value NEW) for a contact this plugin has just created. A separate call, so a
 * problem with the status option can never stop the lead from being imported (it is only logged).
 */
function ppg_lead_hubspot_default_status( $contact_id ) {
	$res = ppg_lead_hubspot( 'PATCH', '/crm/v3/objects/contacts/' . $contact_id, array( 'properties' => array( 'hs_lead_status' => 'NEW' ) ) );
	if ( is_wp_error( $res ) ) {
		error_log( '[ppg-lead-gateway] could not set Lead Status New on contact ' . $contact_id . ': ' . $res->get_error_message() );
	}
}

/**
 * Add a platform (Yelp / Yell / Checkatrade) to the contact's "Lead Sources" checkbox property (lead_sources),
 * keeping whatever is already ticked, so the list view can be filtered by source. Separate calls, so a problem
 * here (e.g. the option does not exist in HubSpot yet) is only logged and never stops the lead being imported.
 */
function ppg_lead_hubspot_add_source( $contact_id, $label ) {
	$got = ppg_lead_hubspot( 'GET', '/crm/v3/objects/contacts/' . $contact_id . '?properties=lead_sources', null );
	if ( is_wp_error( $got ) ) {
		error_log( '[ppg-lead-gateway] could not read Lead Sources of contact ' . $contact_id . ': ' . $got->get_error_message() );
		return;
	}
	$have = array_filter( explode( ';', (string) ( $got['properties']['lead_sources'] ?? '' ) ), 'strlen' );
	if ( in_array( $label, $have, true ) ) {
		return;
	}
	$have[] = $label;
	$res    = ppg_lead_hubspot( 'PATCH', '/crm/v3/objects/contacts/' . $contact_id, array( 'properties' => array( 'lead_sources' => implode( ';', $have ) ) ) );
	if ( is_wp_error( $res ) ) {
		error_log( '[ppg-lead-gateway] could not set Lead Sources "' . $label . '" on contact ' . $contact_id . ' (add it as an option to the HubSpot property): ' . $res->get_error_message() );
	}
}

/**
 * Add the lead's details to the contact's timeline as a Note, so they are visible in the Activity feed
 * (the contact's "Message" property is real but hidden away under "View all properties"). One note per
 * new lead; a failure here is logged but never fails the lead itself.
 */
function ppg_lead_hubspot_note( $contact_id, $source, $message ) {
	$html = nl2br( htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) );
	$html = preg_replace( '#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $html );
	$res  = ppg_lead_hubspot( 'POST', '/crm/v3/objects/notes', array(
		'properties'   => array(
			'hs_timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'hs_note_body' => '<b>New ' . htmlspecialchars( ucfirst( $source ), ENT_QUOTES, 'UTF-8' ) . ' lead</b><br>' . $html,
		),
		'associations' => array( array(
			'to'    => array( 'id' => $contact_id ),
			'types' => array( array( 'associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 202 ) ), // note -> contact
		) ),
	) );
	if ( is_wp_error( $res ) ) {
		error_log( '[ppg-lead-gateway] could not add the lead note to contact ' . $contact_id . ': ' . $res->get_error_message() );
	}
}
