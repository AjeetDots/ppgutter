<?php
/**
 * Website forms (Fluent Forms -> HubSpot): default Lead Status "New".
 *
 * Fluent Forms creates the HubSpot contact itself; its feed settings live in the database, one feed per form.
 * This filter adds hs_lead_status = NEW to every website form push, so no feed needs editing.
 *
 * Safe for returning customers: Fluent Forms can overwrite an existing contact when "Enable Contact Update" is on,
 * which would reset a status your team has moved on (e.g. "In Progress"). So NEW is only added when the contact
 * does not exist yet, or exists without any status. If the lookup fails, nothing is added and the form is not
 * affected in any way.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'fluentform/hubspot_field_data', 'ppg_website_default_lead_status', 10, 4 );
add_filter( 'fluentform/hubspot_field_data', 'ppg_website_lead_source', 20, 4 );

/**
 * "Lead Sources" (checkbox property lead_sources): tick the name of the form that was submitted, keeping what is
 * already ticked on a returning contact. Only sent when the form name is an existing option of the property, since
 * HubSpot would reject the whole push for an unknown option. Any lookup problem leaves the push untouched.
 */
function ppg_website_lead_source( $fields, $feed = null, $entry = null, $form = null ) {
	$title = is_object( $form ) && isset( $form->title ) ? trim( (string) $form->title ) : '';
	$token = (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' );
	if ( ! is_array( $fields ) || '' === $title || '' === $token || ! empty( $fields['lead_sources'] ) ) {
		return $fields;
	}
	$args = array( 'timeout' => 8, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) );

	$options = get_transient( 'ppg_lead_sources_options' );
	if ( ! is_array( $options ) ) {
		$response = wp_remote_get( 'https://api.hubapi.com/crm/v3/properties/contacts/lead_sources', $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $fields;
		}
		$prop    = json_decode( wp_remote_retrieve_body( $response ), true );
		$options = array_column( (array) ( $prop['options'] ?? array() ), 'value' );
		set_transient( 'ppg_lead_sources_options', $options, 10 * MINUTE_IN_SECONDS );
	}
	if ( ! in_array( $title, $options, true ) ) {
		error_log( '[ppg-lead-gateway] form "' . $title . '" is not an option of the HubSpot property Lead Sources; add it there.' );
		return $fields;
	}

	$have  = array();
	$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';
	if ( '' !== $email && is_email( $email ) ) {
		$response = wp_remote_get( 'https://api.hubapi.com/crm/v3/objects/contacts/' . rawurlencode( $email ) . '?idProperty=email&properties=lead_sources', $args );
		if ( is_wp_error( $response ) ) {
			return $fields;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$have = array_filter( explode( ';', (string) ( $body['properties']['lead_sources'] ?? '' ) ), 'strlen' );
		} elseif ( 404 !== $code ) {
			return $fields;
		}
	}
	if ( ! in_array( $title, $have, true ) ) {
		$have[] = $title;
	}
	$fields['lead_sources'] = implode( ';', $have );
	return $fields;
}

function ppg_website_default_lead_status( $fields, $feed = null, $entry = null, $form = null ) {
	if ( ! is_array( $fields ) || ! empty( $fields['hs_lead_status'] ) ) {
		return $fields; // a feed already maps Lead Status itself
	}
	$email = isset( $fields['email'] ) ? (string) $fields['email'] : '';
	$token = (string) ppg_lead_cfg( 'PPG_HUBSPOT_TOKEN' );
	if ( '' === $email || ! is_email( $email ) || '' === $token ) {
		return $fields;
	}

	$response = wp_remote_get(
		'https://api.hubapi.com/crm/v3/objects/contacts/' . rawurlencode( $email ) . '?idProperty=email&properties=hs_lead_status',
		array( 'timeout' => 8, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
	);
	if ( is_wp_error( $response ) ) {
		return $fields;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 404 === $code ) {
		$fields['hs_lead_status'] = 'NEW'; // brand-new contact
	} elseif ( 200 === $code ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['properties']['hs_lead_status'] ) ) {
			$fields['hs_lead_status'] = 'NEW'; // exists but has no status yet
		}
	}
	return $fields;
}
