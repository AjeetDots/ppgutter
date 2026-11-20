<?php
/**
 * Plugin Name: PPG Lead Gateway
 * Description: Reads Checkatrade lead emails from the info@ mailbox and creates HubSpot contacts (also adds Lead Status / Lead Source to website form pushes).
 * Version:     3.0.0
 * Author:      Dotsquares
 * Author URI:  https://www.dotsquares.com/
 *
 * Required configuration (wp-config.php constant OR environment variable):
 *   PPG_HUBSPOT_TOKEN                  HubSpot Private App token (scopes: crm.objects.contacts.read/write)
 *   PPG_IMAP_HOST, PPG_IMAP_USER, PPG_IMAP_PASS   the mailbox to read, e.g. {imap.gmail.com:993/imap/ssl}[Gmail]/All Mail
 *   PPG_GO_LIVE                        e.g. '2026-11-05 09:00:00 Europe/London'; only emails after this are imported
 *
 * HubSpot needs custom contact properties "lead_source_platform" and "ppg_lead_id" (single-line text,
 * "Require unique values" on ppg_lead_id).
 */

defined( 'ABSPATH' ) || exit;

// Light by design: the website-form hooks load on every request (they are tiny); the mailbox poller, parsers and
// admin pages only load where they are used (WP-Cron, wp-admin, WP-CLI), so normal visitors pay nothing for them.
require_once __DIR__ . '/website-forms.php';
if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once __DIR__ . '/parsers.php';
	require_once __DIR__ . '/email-poller.php';
	require_once __DIR__ . '/status-page.php';
}

function ppg_lead_cfg( $key ) {
	if ( defined( $key ) ) {
		return constant( $key );
	}
	$env = getenv( $key );
	return false === $env ? '' : $env;
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
function ppg_cut( $s, $n ) {
	return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $n ) : substr( (string) $s, 0, $n );
}

function ppg_lead_process( $source, array $lead ) {
	$name     = ppg_cut( sanitize_text_field( $lead['name'] ?? '' ), 100 );
	$email    = ppg_cut( sanitize_email( $lead['email'] ?? '' ), 254 );
	$phone    = ppg_cut( preg_replace( '/[^\d+]/', '', (string) ( $lead['phone'] ?? '' ) ), 20 );
	$postcode = ppg_cut( sanitize_text_field( $lead['postcode'] ?? '' ), 12 );
	$message  = ppg_cut( sanitize_textarea_field( $lead['message'] ?? '' ), 5000 );
	$key      = (string) ( $lead['dedupe_key'] ?? '' );

	$queue_item = array(
		'name'     => $name,
		'phone'    => $phone,
		'postcode' => $postcode,
		'message'  => ppg_cut( $message, 1000 ), // the review queue keeps only a short copy
		'subject'  => ppg_cut( sanitize_text_field( $lead['subject'] ?? '' ), 200 ),
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
	// notification does not create a second record. Remembered for two weeks;
	// HubSpot's own unique matching is the permanent safeguard.
	$locks = array(
		'i' . substr( md5( $lead_id ), 0, 12 ),
		'c' . substr( md5( $source . $email . $phone . $name . $postcode . substr( $message, 0, 300 ) ), 0, 12 ),
	);
	if ( ppg_seen_has( $locks ) ) {
		return array( 'status' => 'duplicate_ignored' );
	}

	$parts = preg_split( '/\s+/', $name, 2 );
	$props = array_filter( array(
		'firstname'            => $parts[0] ?? '',
		'lastname'             => $parts[1] ?? '',
		'email'                => $email,
		'phone'                => $phone,
		'zip'                  => $postcode,
		// "Message" column = the customer's own words (as on the website forms); the full details, with the
		// lead reference, go to the timeline note.
		'message'              => '' !== trim( (string) ( $lead['description'] ?? '' ) ) ? sanitize_textarea_field( $lead['description'] ) : $message,
		'lead_source_platform' => $source,
	), 'strlen' );

	// Dedicated HubSpot fields. "Lead Source" (lead_page_url) names the platform, as the website forms do with
	// "PP Gutter Website"; "How can we help?" holds the customer's own description, as on the website forms.
	// Plus any extra answers passed in $lead['fields'].
	$platform_names = array( 'checkatrade' => 'Checkatrade' );
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

	ppg_seen_add( $locks );
	ppg_lead_review_remove( $source, $queue_item['key'] ); // e.g. an earlier rejection that has since been fixed
	return array( 'status' => 'ok', 'hubspot_id' => $result['id'] );
}

/* ---------------------------------------------------------------------------
 * Duplicate memory. One option holding short hashes (never customer data), capped in size and age, so the
 * database footprint is constant no matter how many leads arrive. HubSpot's unique matching stays the
 * permanent safeguard; this only avoids re-sending leads we already handled.
 * ------------------------------------------------------------------------- */

function ppg_seen_has( array $hashes ) {
	$seen = (array) get_option( 'ppg_seen', array() );
	$now  = time();
	foreach ( $hashes as $h ) {
		if ( isset( $seen[ $h ] ) && $now - (int) $seen[ $h ] < 2 * WEEK_IN_SECONDS ) {
			return true;
		}
	}
	return false;
}

function ppg_seen_add( array $hashes ) {
	$seen = (array) get_option( 'ppg_seen', array() );
	$now  = time();
	foreach ( $hashes as $h ) {
		unset( $seen[ $h ] ); // re-insert at the end so the oldest entries are the ones trimmed
		$seen[ $h ] = $now;
	}
	if ( count( $seen ) > 1500 ) {
		$seen = array_slice( $seen, -1200, null, true );
	}
	update_option( 'ppg_seen', $seen, false );
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
	error_log( "[ppg-lead-gateway] $source lead held for review ($reason)" ); // no customer details in server logs
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

function ppg_lead_hubspot( $method, $path, $body ) {
	$token = (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' );
	if ( '' === $token ) {
		return new WP_Error( 'ppg_not_configured', 'HubSpot token not configured.', array( 'status' => 500 ) );
	}
	$response = wp_remote_request( 'https://api.hubapi.com' . $path, array(
		'method'              => $method,
		'timeout'             => 15,
		'redirection'         => 0,
		'limit_response_size' => 1048576,
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
 * Add a platform (Checkatrade) to the contact's "Lead Sources" checkbox property (lead_sources),
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
