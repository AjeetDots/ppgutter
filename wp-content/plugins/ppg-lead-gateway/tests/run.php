<?php
if ( 'cli' !== PHP_SAPI ) { exit; } // never runnable from the web
/**
 * Regression tests. Run:  php tests/run.php
 *
 * Fixtures are synthetic (invented names, example.org addresses) but reproduce the layouts found in
 * real notifications. No network access: HubSpot calls are recorded by a stub, never sent.
 */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PPG_HUBSPOT_TOKEN', 'test-token-not-real' );
$GLOBALS['t']     = array();
$GLOBALS['o']     = array();
$GLOBALS['calls'] = array();

class WP_Error {
	public function __construct( public $c = '', public $m = '', public $d = array() ) {}
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['hooks'] = array();
function add_action( ...$a ) { $GLOBALS['hooks'][] = array( 'action', $a[0], $a[1] ); }
function add_filter( ...$a ) { $GLOBALS['hooks'][] = array( 'filter', $a[0], $a[1] ); }
function register_deactivation_hook( ...$a ) { $GLOBALS['hooks'][] = array( 'deactivate', 'plugin', $a[1] ); }
function wp_schedule_event( ...$a ) { $GLOBALS['scheduled'][] = $a; return true; }
function wp_clear_scheduled_hook( $h ) { $GLOBALS['cleared'][] = $h; }
function add_option( $k, $v = '', $d = '', $a = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['o'] ) ) { return false; } $GLOBALS['o'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['o'][ $k ] ); return true; }
function register_rest_route( ...$a ) {}
function add_management_page( ...$a ) {}
function wp_next_scheduled( ...$a ) { return $GLOBALS['already_scheduled'] ?? true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ?: ''; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['t'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['o'][ $k ] = $v; return true; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function wp_remote_request( $u, $a ) {
	// Lead Sources housekeeping (read + tick the platform) is recorded separately so the call-order checks stay readable.
	$body_arr = json_decode( (string) $a['body'], true );
	if ( ( 'GET' === $a['method'] && false !== strpos( $u, 'properties=lead_sources' ) ) || array( 'lead_sources' ) === array_keys( $body_arr['properties'] ?? array( 'x' => 1 ) ) ) {
		$GLOBALS['source_calls'][] = array( $u, $body_arr, $a['method'] );
		return array( 'code' => 200, 'body' => json_encode( array( 'properties' => array( 'lead_sources' => $GLOBALS['existing_sources'] ?? null ) ) ) );
	}
	$GLOBALS['calls'][] = array( $u, json_decode( $a['body'], true ), $a['method'] ); // recorded, NOT sent
	if ( ! empty( $GLOBALS['script'] ) ) { // scripted responses, consumed in order
		$step = array_shift( $GLOBALS['script'] );
		return array( 'code' => $step[0], 'body' => $step[1] );
	}
	$code = $GLOBALS['next_http'] ?? 200;
	return array( 'code' => $code, 'body' => $code < 300 ? json_encode( array( 'id' => '1', 'results' => array( array( 'id' => '1', 'new' => true ) ) ) ) : '{"status":"error","message":"stub failure"}' );
}
function wp_remote_get( $u, $a = array() ) { $GLOBALS['gets'][] = $u; $step = array_shift( $GLOBALS['get_script'] ); return $step instanceof WP_Error ? $step : array( 'code' => $step[0], 'body' => $step[1] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

class WP_REST_Request {
	public function __construct( private $json = null, private $raw = '' ) {}
	public function get_json_params() { return $this->json; }
	public function get_body() { return $this->raw; }
	public function get_header( $h ) { return $GLOBALS['req_headers'][ strtolower( $h ) ] ?? null; }
}
class WP_REST_Response {
	public function __construct( public $data = null, public $status = 200 ) {}
}
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $k ) ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); }
function wp_get_environment_type() { return $GLOBALS['env_type'] ?? 'production'; }
$GLOBALS['req_headers'] = array();

function is_admin() { return true; }
require dirname( __DIR__ ) . '/ppg-lead-gateway.php';

$pass = 0;
$fail = 0;
function check( $label, $ok ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
}
function parse( $src, $subject, $body ) { return ppg_parse_lead_email( $src, $subject, $body ); }


/* ------------------------------------------------------------------ HubSpot rules */
echo "HubSpot rules (email optional; identity; duplicates; review queue)\n";
$n     = function () { return count( $GLOBALS['calls'] ); };
$last  = function () {
	foreach ( array_reverse( $GLOBALS['calls'] ) as $c ) {
		$is_status = array( 'hs_lead_status' ) === array_keys( $c[1]['properties'] ?? array( 'x' => 1 ) );
		if ( false === strpos( $c[0], '/notes' ) && ! $is_status ) { return $c[1]['inputs'][0] ?? ( $c[1] ?? array() ); }
	}
	return array();
};
$notes = function () { return array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], '/crm/v3/objects/notes' ); } ) ); };
$queue = function () { return $GLOBALS['o']['ppg_lead_review'] ?? array(); };

// 1. lead with an email
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Test Person', 'email' => 't@example.org', 'phone' => '07123 456789', 'postcode' => 'HP13 5ST', 'message' => 'x', 'dedupe_key' => 'm1' ) );
$p = $last();
check( 'lead with email: created, matched on the email property', 'ok' === ( $r['status'] ?? '' ) && 'email' === ( $p['idProperty'] ?? '' ) && 't@example.org' === ( $p['id'] ?? '' ) );
check( 'lead with email: phone added', '07123456789' === ( $p['properties']['phone'] ?? '' ) );
check( 'lead with email: does not depend on the ppg_lead_id property', ! isset( $p['properties']['ppg_lead_id'] ) );

// 2. phone-only lead
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Checkatrade enquiry: gutters', 'dedupe_key' => '<ca-y1@x>' ) );
$p = $last();
check( 'phone-only lead: created (not held)', 'ok' === ( $r['status'] ?? '' ) && 0 === count( $queue() ) );
check( 'phone-only lead: matched on ppg_lead_id, phone included, NO email property', 'ppg_lead_id' === ( $p['idProperty'] ?? '' ) && '07999000111' === ( $p['properties']['phone'] ?? '' ) && ! isset( $p['properties']['email'] ) );
check( 'phone-only lead: platform recorded', 'checkatrade' === ( $p['properties']['lead_source_platform'] ?? '' ) );

// 3. neither email nor phone
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Nora Nothing', 'postcode' => 'RG41 3AP', 'message' => "Checkatrade job request\nRespond on Checkatrade: https://clicks.checkatrade.com/f/a/abc", 'dedupe_key' => '<ca-1@x>' ) );
$p = $last();
check( 'no email and no phone: still created from name + postcode + message/link', 'ok' === ( $r['status'] ?? '' ) && 'Nora' === ( $p['properties']['firstname'] ?? '' ) && 'Nothing' === ( $p['properties']['lastname'] ?? '' ) && 'RG41 3AP' === ( $p['properties']['zip'] ?? '' ) && false !== strpos( $p['properties']['message'] ?? '', 'clicks.checkatrade.com' ) );
check( 'no email and no phone: no email/phone properties invented', ! isset( $p['properties']['email'] ) && ! isset( $p['properties']['phone'] ) );

// 4. never invent or relay an email
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Bad Email', 'email' => 'not-an-email', 'dedupe_key' => '<bad-1@x>' ) );
check( 'invalid email text is dropped; no email invented', 'ok' === ( $r['status'] ?? '' ) && ! isset( $last()['properties']['email'] ) );

// 5. duplicates and different customers
$before = $n();
$r      = ppg_lead_process( 'checkatrade', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Checkatrade enquiry: gutters', 'dedupe_key' => '<ca-y1@x>' ) );
check( 'same lead (same Message-ID) is not processed twice', 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Checkatrade enquiry: gutters', 'dedupe_key' => '<ca-y1-resent@x>' ) );
check( 'identical content re-sent under another Message-ID is not processed twice', 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );
$r  = ppg_lead_process( 'checkatrade', array( 'name' => 'John Smith', 'postcode' => 'HP1 1AA', 'message' => 'Checkatrade enquiry: first', 'dedupe_key' => '<js-1@x>' ) );
$id1 = $last()['id'];
$r  = ppg_lead_process( 'checkatrade', array( 'name' => 'John Smith', 'postcode' => 'SL6 7NZ', 'message' => 'Checkatrade enquiry: second', 'dedupe_key' => '<js-2@x>' ) );
$id2 = $last()['id'];
check( 'two different customers with the same name get different ids (never merged)', 'ok' === ( $r['status'] ?? '' ) && $id1 !== $id2 );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'John Smith', 'phone' => '07111222333', 'postcode' => 'HP1 1AA', 'message' => 'Checkatrade enquiry: third', 'dedupe_key' => '<js-3@x>' ) );
check( 'phone numbers are not used to match contacts', $last()['id'] !== $id1 );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Checkatrade Thread', 'message' => 'a', 'lead_id' => 'checkatrade:AbCdEf123456', 'dedupe_key' => '<y-a@x>' ) );
$ya = $last()['id'];
$before = $n();
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Checkatrade Thread', 'message' => 'a second email for the same conversation', 'lead_id' => 'checkatrade:AbCdEf123456', 'dedupe_key' => '<y-b@x>' ) );
check( "platform lead id (Checkatrade thread) is used as the identity; same conversation not created twice", 'checkatrade:AbCdEf123456' === $ya && 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );

// 6. review queue only when a lead genuinely cannot be created
$before = $n();
$r      = ppg_lead_process( 'checkatrade', array( 'message' => 'nothing identifies this', 'subject' => 'New enquiry from', 'dedupe_key' => '<empty-1@x>' ) );
check( 'no name, email or phone: NOT sent, held for review', is_wp_error( $r ) && 'ppg_unprocessable' === $r->get_error_code() && $n() === $before && 1 === count( $queue() ) );
ppg_lead_process( 'checkatrade', array( 'message' => 'nothing identifies this', 'dedupe_key' => '<empty-1@x>' ) );
check( 'the same held lead is not queued twice', 1 === count( $queue() ) );
$GLOBALS['next_http'] = 400;
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Rejected Rita', 'message' => 'r', 'dedupe_key' => '<rej-1@x>' ) );
check( 'HubSpot permanently rejects (HTTP 400): held for review with the reason', is_wp_error( $r ) && 2 === count( $queue() ) && false !== strpos( end( $GLOBALS['o']['ppg_lead_review'] )['reason'], 'HubSpot rejected' ) );
$GLOBALS['next_http'] = 500;
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Retry Ron', 'message' => 'r', 'dedupe_key' => '<retry-1@x>' ) );
check( 'HubSpot temporary failure (HTTP 500): NOT queued, will be retried', is_wp_error( $r ) && 2 === count( $queue() ) );
$GLOBALS['next_http'] = 200;
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Retry Ron', 'message' => 'r', 'dedupe_key' => '<retry-1@x>' ) );
check( 'the retried lead then succeeds', 'ok' === ( $r['status'] ?? '' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Rejected Rita', 'message' => 'r', 'dedupe_key' => '<rej-1@x>' ) );
check( 'a previously rejected lead leaves the queue once HubSpot accepts it', 'ok' === ( $r['status'] ?? '' ) && 1 === count( $queue() ) );

// 6b. ppg_lead_id NOT created as a unique property: HubSpot refuses the upsert -> search-then-create fallback
echo "HubSpot property without 'Require unique values' (fallback)\n";
$nonunique = '{"status":"error","message":"Unable to perform update/upsert by non-unique 0-1 property ppg_lead_id in portal ID 1"}';
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); // fresh duplicate memory
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, $nonunique ), array( 200, '{"total":0,"results":[]}' ), array( 201, '{"id":"777"}' ), array( 201, '{"id":"8"}' ) );
$r  = ppg_lead_process( 'checkatrade', array( 'name' => 'Fallback Fran', 'message' => 'm', 'lead_id' => 'checkatrade:FallbackLead0001', 'dedupe_key' => '<fb-1@x>' ) );
$m  = array_map( function ( $c ) { return $c[2] ?? 'POST'; }, $GLOBALS['calls'] );
$u  = array_map( function ( $c ) { return basename( $c[0] ); }, $GLOBALS['calls'] );
check( 'upsert refused as non-unique -> falls back to search, then creates (id returned), sets status, then adds the note', 'ok' === ( $r['status'] ?? '' ) && '777' === ( $r['hubspot_id'] ?? '' ) && array( 'upsert', 'search', 'contacts', '777', 'notes' ) === $u );
$created = $GLOBALS['calls'][2][1];
check( 'the created contact carries ppg_lead_id and no email', 'checkatrade:FallbackLead0001' === ( $created['properties']['ppg_lead_id'] ?? '' ) && ! isset( $created['properties']['email'] ) );
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, '{"total":0,"results":[]}' ), array( 201, '{"id":"778"}' ), array( 201, '{"id":"9"}' ) );
ppg_lead_process( 'checkatrade', array( 'name' => 'Second Sam', 'message' => 'm2', 'lead_id' => 'checkatrade:FallbackLead0002', 'dedupe_key' => '<fb-2@x>' ) );
check( 'once learned, later leads skip the failing upsert (search, create, status, note)', array( 'search', 'contacts', '778', 'notes' ) === array_map( function ( $c ) { return basename( $c[0] ); }, $GLOBALS['calls'] ) );
$GLOBALS['calls'] = array();
$GLOBALS['t']['ppg_upsert_unsupported'] = 1;
$GLOBALS['script'] = array( array( 200, '{"total":1,"results":[{"id":"555"}]}' ), array( 200, '{"id":"555"}' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Existing Eve', 'message' => 'm3', 'lead_id' => 'checkatrade:FallbackLead0003', 'dedupe_key' => '<fb-3@x>' ) );
check( 'lead id already in HubSpot -> existing contact is updated (PATCH), not duplicated, and NO second note', '555' === ( $r['hubspot_id'] ?? '' ) && 'PATCH' === ( end( $GLOBALS['calls'] )[2] ?? '' ) && 2 === count( $GLOBALS['calls'] ) && 0 === count( $notes() ) );
unset( $GLOBALS['script'], $GLOBALS['t']['ppg_upsert_unsupported'] );

// 6c. timeline note (the details must be easy to see in HubSpot)
echo "HubSpot timeline note\n";
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Nina Note', 'message' => "Checkatrade quote request: gutter cleaning\nHow tall is your home? 1 story\nLead on Checkatrade: https://www.checkatrade.com/leads_center/B1/leads/L1<script>", 'lead_id' => 'checkatrade:NoteLead000001', 'dedupe_key' => '<note-1@x>' ) );
$nn = $notes();
check( 'a new lead gets exactly one timeline note', 'ok' === ( $r['status'] ?? '' ) && 1 === count( $nn ) );
$np = $nn[0][1] ?? array();
check( 'the note is associated with the new contact (note -> contact, type 202)', '1' === ( $np['associations'][0]['to']['id'] ?? '' ) && 202 === ( $np['associations'][0]['types'][0]['associationTypeId'] ?? 0 ) );
check( 'the note contains every answer and a clickable lead link', false !== strpos( $np['properties']['hs_note_body'] ?? '', 'How tall is your home? 1 story' ) && false !== strpos( $np['properties']['hs_note_body'] ?? '', '<a href="https://www.checkatrade.com/leads_center/B1/leads/L1' ) );
check( 'note text is HTML-escaped (no injected markup)', false === strpos( $np['properties']['hs_note_body'] ?? '', '<script>' ) );
$before = count( $notes() );
ppg_lead_process( 'checkatrade', array( 'name' => 'Nina Note', 'message' => 'x', 'lead_id' => 'checkatrade:NoteLead000001', 'dedupe_key' => '<note-1@x>' ) );
check( 'a duplicate lead does not add another note', count( $notes() ) === $before );
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '42', 'new' => false ) ) ) ) ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Nina Note', 'message' => 'same lead after a cache flush', 'lead_id' => 'checkatrade:NoteLead000002', 'dedupe_key' => '<note-2@x>' ) );
check( 'unique ppg_lead_id already in HubSpot (new:false) -> same lead, no second note', 'ok' === ( $r['status'] ?? '' ) && count( $notes() ) === $before );
$GLOBALS['calls'] = array();
ppg_lead_process( 'checkatrade', array( 'name' => 'Emma Mail', 'email' => 'emma@example.org', 'message' => 'Checkatrade enquiry: gutters', 'dedupe_key' => '<note-3@x>' ) );
check( 'a lead with an email also gets its note (a repeat customer keeps every lead)', 1 === count( $notes() ) );
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '43', 'new' => true ) ) ) ) ), array( 403, '{"message":"missing scope"}' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Noscope Nick', 'email' => 'nick@example.org', 'message' => 'm', 'dedupe_key' => '<note-4@x>' ) );
check( 'if the note cannot be created the lead is still imported (note failure only logged)', 'ok' === ( $r['status'] ?? '' ) );
unset( $GLOBALS['script'] );

// 6d. dedicated answer fields
echo "Dedicated answer fields in HubSpot\n";
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
$fields = array( 'how_tall_is_your_home' => '1 story', 'in_what_location_do_you_need_the_service' => '60622' );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Fiona Fields', 'message' => 'm', 'lead_id' => 'checkatrade:FieldsLead00001', 'dedupe_key' => '<f-1@x>', 'fields' => $fields ) );
$p = $last();
check( 'answers are sent as their own HubSpot properties alongside the standard fields', '1 story' === ( $p['properties']['how_tall_is_your_home'] ?? '' ) && '60622' === ( $p['properties']['in_what_location_do_you_need_the_service'] ?? '' ) && 'Fiona' === ( $p['properties']['firstname'] ?? '' ) );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"Property \"how_tall_is_your_home\" does not exist"}' ), array( 200, json_encode( array( 'results' => array( array( 'id' => '66', 'new' => true ) ) ) ) ), array( 201, '{"id":"1"}' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Gina Gone', 'message' => 'm', 'lead_id' => 'checkatrade:FieldsLead00002', 'dedupe_key' => '<f-2@x>', 'fields' => $fields ) );
$uc = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) );
check( 'a deleted/renamed HubSpot field does not block the lead: retried without the dedicated fields', 'ok' === ( $r['status'] ?? '' ) && 2 === count( $uc ) && isset( $uc[0][1]['inputs'][0]['properties']['how_tall_is_your_home'] ) && ! isset( $uc[1][1]['inputs'][0]['properties']['how_tall_is_your_home'] ) );
check( 'the retry keeps the standard fields (name, platform, lead id)', 'Gina' === ( $uc[1][1]['inputs'][0]['properties']['firstname'] ?? '' ) && 'checkatrade' === ( $uc[1][1]['inputs'][0]['properties']['lead_source_platform'] ?? '' ) );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"some other validation problem"}' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Harry Held', 'message' => 'm', 'lead_id' => 'checkatrade:FieldsLead00003', 'dedupe_key' => '<f-3@x>', 'fields' => $fields ) );
check( 'any other HubSpot 400 is NOT retried blindly (held for review instead)', is_wp_error( $r ) && 1 === count( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) ) );
unset( $GLOBALS['script'] );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
ppg_lead_process( 'checkatrade', array( 'name' => 'Ivy Injection', 'message' => 'm', 'lead_id' => 'checkatrade:FieldsLead00004', 'dedupe_key' => '<f-4@x>', 'fields' => array( 'bad name;drop' => 'x', 'lifecyclestage' => 'customer', 'how_tall_is_your_home' => '2 storey' ) ) );
$pp = $last()['properties'] ?? array();
check( 'only well-formed property names are accepted', ! isset( $pp['bad name;drop'] ) && '2 storey' === ( $pp['how_tall_is_your_home'] ?? '' ) );

// 6e. "Lead Source" (lead_page_url) and "How can we help?" (the customer's own words)
echo "Lead Source and customer description\n";
$sent = function ( $source, $lead ) use ( $last ) {
	$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
	ppg_lead_process( $source, $lead + array( 'dedupe_key' => '<ls-' . $source . '-' . mt_rand() . '@x>' ) );
	return $last()['properties'] ?? array();
};
check( "Checkatrade leads: Lead Source = 'Checkatrade'", 'Checkatrade' === ( $sent( 'checkatrade', array( 'name' => 'A B', 'message' => 'm' ) )['lead_page_url'] ?? '' ) );
check( 'the internal platform tag (lead_source_platform) is still set', 'checkatrade' === ( $sent( 'checkatrade', array( 'name' => 'A B', 'message' => 'm' ) )['lead_source_platform'] ?? '' ) );
$pp = $sent( 'checkatrade', array( 'name' => 'A B', 'message' => 'm', 'description' => "Gutters leaking\non the left side" ) );
check( "customer's own words go to 'How can we help?' (as on the website forms), on one line", 'Gutters leaking on the left side' === ( $pp['how_can_we_help'] ?? '' ) );
check( 'no description => How can we help? is not sent (nothing invented)', ! isset( $sent( 'checkatrade', array( 'name' => 'A B', 'message' => 'm' ) )['how_can_we_help'] ) );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] );
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"Property \"how_can_we_help\" does not exist"}' ), array( 200, json_encode( array( 'results' => array( array( 'id' => '81', 'new' => true ) ) ) ) ), array( 201, '{"id":"1"}' ) );
$r  = ppg_lead_process( 'checkatrade', array( 'name' => 'Missing Mo', 'message' => 'm', 'description' => 'd', 'dedupe_key' => '<ls-missing@x>' ) );
$up = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) );
check( 'if a HubSpot field was deleted the lead is still imported (retried without the optional fields)', 'ok' === ( $r['status'] ?? '' ) && 2 === count( $up ) && ! isset( $up[1][1]['inputs'][0]['properties']['lead_page_url'] ) && 'checkatrade' === ( $up[1][1]['inputs'][0]['properties']['lead_source_platform'] ?? '' ) );
unset( $GLOBALS['script'] );


/* ------------------------------------------------------------------ Checkatrade (current) */
echo "Checkatrade (current format)\n";
$ca_tail = "I'm interested ( https://clicks.checkatrade.com/f/a/abc~~ ) Not for me ( https://clicks.checkatrade.com/f/a/def~~ )\nManage your jobs in the Checkatrade App\n(c) Checkatrade, Portsmouth, PO6 3EN Contact Us";
$r = parse( 'checkatrade', 'Gutter Cleaning & Clearance in RG41 3AP - respond now',
	"New job request for you\nHey Zdravko, We have a new job\nrequest that we think could be right up your street.\nGutter Cleaning & Clearance in\nRG41 3AP\nCustomer\nAnna\nTester\n Start date\nI'm flexible on start date\nAnna's message\nThis is a verified request from a customer.\n$ca_tail" );
check( 'Customer block (first/last on separate lines): name', 'Anna Tester' === $r['name'] );
check( 'no contact details in email => email and phone empty (genuinely absent)', '' === $r['email'] && '' === $r['phone'] );
check( 'postcode from subject', 'RG41 3AP' === $r['postcode'] );
check( "the \"I'm interested\" action link is NEVER stored (clicking it registers interest in a billable lead)", false === strpos( json_encode( $r ), 'clicks.checkatrade.com' ) && false !== strpos( $r['message'], 'Checkatrade app' ) );
$r = parse( 'checkatrade', 'Gutter Cleaning & Clearance in HP12 3FW - respond now',
	"New job request for you\nGutter Cleaning & Clearance in HP12 3FW\nName    Lesley  Test     Start date\nI'm flexible\n\nLesley  Test's message\nMy gutters need cleaning.\n$ca_tail" );
check( '2024 inline layout "Name X Y Start date": name', 'Lesley Test' === $r['name'] );
$r = parse( 'checkatrade', 'Commercial Cleaning in SW4 9JJ - respond now',
	"Hey Zdravko,\nCommercial Cleaning in\nSW4 9JJ\n\n     Name\nJoe\nTester    Start date\nI'm planning\n\nJoe's message\nHi, I need a quote.\n$ca_tail" );
check( '2025 split layout: name', 'Joe Tester' === $r['name'] );
$r = parse( 'checkatrade', 'Commercial Cleaning in WD18 7NB - respond now',
	"Commercial Cleaning in WD18 7NB Name\nFerkaa Tester Start date\nI'm flexible on the start date\nFerkaa Tester's message\nFerkaa Tester ferkaa@example.org 7770 446785 WD18 7NB I\u{2019}m interested ( https://clicks.checkatrade.com/f/a/xyz~~ ) Not for me" );
check( 'customer typed email + phone into message ("I\'m interested" on the same line): email', 'ferkaa@example.org' === $r['email'] );
check( 'phone typed without the leading 0 is normalised', '07770446785' === $r['phone'] );
$r = parse( 'checkatrade', 'Roof Cleaning in WD19 7QT - respond now',
	"Roof Cleaning in WD19 7QT\nName\nRenata Test Start date\nASAP\nRenata's message\nHow much for 20ft of roof? Many thanks, Renata renata@example.org I'm interested ( https://clicks.checkatrade.com/f/a/q~~ )" );
check( 'email typed inside a free-text message is found', 'renata@example.org' === $r['email'] );
check( 'verification code email is skipped', null === parse( 'checkatrade', 'Your Checkatrade one-time verification code is 123456', 'x' ) );
check( '"You have unread messages" is skipped', null === parse( 'checkatrade', 'You have unread messages', 'x' ) );
check( 'membership statement is skipped', null === parse( 'checkatrade', 'Your latest Checkatrade membership statement', 'x' ) );

/* ------------------------------------------------------------------ Checkatrade (legacy, structured contact block) */
echo "Checkatrade (legacy formats with the customer's real contact block)\n";
$callback_html = '<html><body><p>Dear Mr Test ,</p><p>We&rsquo;re delighted to inform you that a Customer is interested in your services.</p>'
	. '<p>Christian Tester</p><p>07700 900123</p><p>High Wycombe, HP13 5AF</p><p><a href="mailto:christian@example.org">christian@example.org</a></p>'
	. '<p>Their preferred form of contact is:</p><p>They have described their job as the following: Hello, I need my gutters cleared.</p><p>Kind regards Checkatrade</p>'
	. '<p>Checkatrade is a trading name of Vetted Limited. Portsmouth, PO6 3EN</p></body></html>';
$r = parse( 'checkatrade', 'Checkatrade - Customer Callback Requested', ppg_html_to_text( $callback_html ) );
check( 'Callback (HTML-only): name', 'Christian Tester' === $r['name'] );
check( 'Callback: real customer email + phone extracted', 'christian@example.org' === $r['email'] && '07700 900123' === $r['phone'] );
check( 'Callback: customer postcode, not the Checkatrade office postcode', 'HP13 5AF' === $r['postcode'] );
check( 'Callback: job description captured', false !== strpos( $r['message'], 'I need my gutters cleared' ) );
$r = parse( 'checkatrade', 'Checkatrade - Customer Message', ppg_html_to_text( str_replace( 'Callback', 'Message', $callback_html ) ) );
check( 'Customer Message: email + phone extracted', 'christian@example.org' === $r['email'] && '07700 900123' === $r['phone'] );
$job_html = '<html><body><p>New opportunity from</p><p>Federica Tester</p><p>We have a new job opportunity from Federica Tester that we think could be right up your street.</p>'
	. '<p>Here&#39;s what they need:</p><p>&ldquo;Hello, I need to clear the gutter at the back.&rdquo;</p><p>The job was submitted on 17th January at 12:09</p>'
	. '<p>Federica Tester</p><p>07700 900456</p><p>federica@example.org</p><p>London, SW4 7QJ</p><p>&copy; 2021 Checkatrade, PO6 3EN</p></body></html>';
foreach ( array( 'Checkatrade - New job from Federica Tester - respond now', 'New job from Federica Tester - respond now' ) as $subj ) {
	$r = parse( 'checkatrade', $subj, ppg_html_to_text( $job_html ) );
	check( "'$subj': email + phone + postcode", $r && 'federica@example.org' === $r['email'] && '07700 900456' === $r['phone'] && 'SW4 7QJ' === $r['postcode'] );
}


/* ------------------------------------------------------------------ 2023 layout, same-name leads, lead reference */
echo "Checkatrade 2023 layout and lead reference (telling same-name customers apart)\n";
$r = parse( 'checkatrade', 'Checkatrade - New job from John Tester - respond now',
	"Hey Mr Test,\nWe have a new job request that we think could be right up your street.\nNew job in SL7 3NN\nName\nJohn Tester\nStart date\nI'm flexible on the start date\nJob created\nThe job was submitted on 3rd July at 12:29\nJohn Tester's message\nClean block brick drive approximately 500sq. Meters\nRespond to request ( https://clicks.checkatrade.com/f/a/zzz~~ )" );
check( '2023 "New job in <postcode>" layout: name, postcode, message', $r && 'John Tester' === $r['name'] && 'SL7 3NN' === $r['postcode'] && false !== strpos( $r['message'], 'Clean block brick drive' ) );
check( '2023 layout: no email or phone is invented', $r && '' === $r['email'] && '' === $r['phone'] );
check( '2023 layout: the "Respond to request" action link is not stored', $r && false === strpos( json_encode( $r ), 'clicks.checkatrade.com' ) );

$ts = strtotime( '2026-10-18 19:57:10 UTC' ); // 20:57 in London (BST)
$a  = ppg_checkatrade_ref( $ts, 'RG4 7HL', '<msg-1@mail.example>' );
$b  = ppg_checkatrade_ref( $ts, 'RG4 7HL', '<msg-2@mail.example>' );
check( 'reference shows UK date, time and postcode: ' . $a, 1 === preg_match( '/^CT-20261018-2057-RG47HL-[0-9A-F]{5}$/', $a ) );
check( 'two different emails (same name, same postcode, same minute) get different references', $a !== $b );
check( 'the same email always gets the same reference (re-reading it never creates a second contact)', $a === ppg_checkatrade_ref( $ts, 'RG4 7HL', '<msg-1@mail.example>' ) );
check( 'a lead without a postcode still gets a reference', false !== strpos( ppg_checkatrade_ref( $ts, '', '<m>' ), '-NOPOSTCODE-' ) );
check( 'reference lines carry the reference and the UK received time', "Lead reference: $a\nEmail received (UK time): Sun 18 Oct 2026, 20:57\n" === ppg_checkatrade_reference_lines( $a, $ts ) );

$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['calls'] = array(); $GLOBALS['script'] = array();
foreach ( array( '<msg-1@mail.example>', '<msg-2@mail.example>' ) as $i => $mid ) {
	$ref = ppg_checkatrade_ref( $ts, 'RG4 7HL', $mid );
	$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => (string) ( 900 + $i ), 'new' => true ) ) ) ) ), array( 200, '{}' ), array( 200, '{"properties":{}}' ), array( 200, '{}' ), array( 201, '{"id":"1"}' ) );
	$res = ppg_lead_process( 'checkatrade', array( 'name' => 'Aled Tester', 'postcode' => 'RG4 7HL', 'message' => ppg_checkatrade_reference_lines( $ref, $ts ) . 'Quote for gutters', 'lead_id' => $ref, 'dedupe_key' => $mid ) );
	check( "same-name lead #" . ( $i + 1 ) . ' is created as its own contact', 'ok' === ( $res['status'] ?? '' ) );
	$up = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'batch/upsert' ); } ) );
	$in = end( $up )[1]['inputs'][0];
	check( "same-name lead #" . ( $i + 1 ) . ': matched on its own ppg_lead_id, never on name, and no email is sent', $ref === $in['id'] && 'ppg_lead_id' === $in['idProperty'] && $ref === $in['properties']['ppg_lead_id'] && ! isset( $in['properties']['email'] ) );
}
// Services / Start date / Post Code / Message columns
$r = parse( 'checkatrade', 'Gutter Cleaning & Clearance in RG12 1AH - respond now',
	"Hey,\nGutter Cleaning & Clearance in RG12 1AH\nCustomer\nAjeet Tester\nStart date\nI'm flexible on start date\nAjeet's message\nThis is testing form\nI'm interested ( https://clicks.checkatrade.com/f/a/x~~ )" );
check( 'fields for the HubSpot list columns: Services, Start date, Post Code', array( 'services' => 'Gutter Cleaning & Clearance', 'start_date' => "I'm flexible on start date", 'post_code' => 'RG12 1AH' ) === $r['fields'] );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '700', 'new' => true ) ) ) ) ), array( 200, '{}' ), array( 200, '{"properties":{}}' ), array( 200, '{}' ), array( 201, '{"id":"1"}' ) );
ppg_lead_process( 'checkatrade', $r + array( 'lead_id' => 'CT-X', 'dedupe_key' => '<cols@x>', 'message' => "Lead reference: CT-X\nChecktrade details" ) );
$up = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'batch/upsert' ); } ) );
$pp = end( $up )[1]['inputs'][0]['properties'];
check( 'HubSpot gets Post Code, Services, Start date', 'RG12 1AH' === ( $pp['post_code'] ?? '' ) && 'Gutter Cleaning & Clearance' === ( $pp['services'] ?? '' ) && "I'm flexible on start date" === ( $pp['start_date'] ?? '' ) );
check( "Message column holds only the customer's message", 'This is testing form' === ( $pp['message'] ?? '' ) );
check( 'Postal Code (zip) is still set too', 'RG12 1AH' === ( $pp['zip'] ?? '' ) );
unset( $GLOBALS['script'] );
unset( $GLOBALS['script'] );
$mails = ppg_email_sources();
check( 'only Checkatrade senders are read (no Yelp/Yell)', array( 'checkatrade.com' => 'checkatrade' ) === $mails );
check( 'Yelp / Yell emails are ignored by the parser', null === parse( 'yelp', 'New Lead: Reply to A\'s gutter cleaning request', 'x' ) && null === parse( 'yell', 'New enquiry from A B', 'x' ) );

echo "Default Lead Status 'New'\n";
$status_calls = function () { return array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return 'PATCH' === ( $c[2] ?? '' ) && array( 'hs_lead_status' ) === array_keys( $c[1]['properties'] ?? array( 'x' => 1 ) ); } ) ); };
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['calls'] = array();
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Status', 'email' => 'sam@example.org', 'message' => 'm', 'dedupe_key' => '<st-1@x>' ) );
$sc = $status_calls();
check( "a contact the plugin creates gets Lead Status 'New' (HubSpot value NEW)", 1 === count( $sc ) && 'NEW' === ( $sc[0][1]['properties']['hs_lead_status'] ?? '' ) && false !== strpos( $sc[0][0], '/contacts/1' ) );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '55', 'new' => false ) ) ) ) ), array( 201, '{"id":"9"}' ) );
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Status', 'email' => 'sam@example.org', 'message' => 'a second lead from a returning customer', 'dedupe_key' => '<st-2@x>' ) );
check( "a returning customer's contact is NOT reset to New (their status is left alone)", 0 === count( $status_calls() ) );
unset( $GLOBALS['script'] );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '56', 'new' => true ) ) ) ) ), array( 400, '{"message":"bad option"}' ), array( 201, '{"id":"9"}' ) );
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Opt Out', 'email' => 'opt@example.org', 'message' => 'm', 'dedupe_key' => '<st-3@x>' ) );
check( 'if HubSpot refuses the status the lead is still imported (only logged)', 'ok' === ( $r['status'] ?? '' ) );
unset( $GLOBALS['script'] );

// website forms (Fluent Forms): fluentform/hubspot_field_data filter
$ws = function ( $fields, $get ) { $GLOBALS['gets'] = array(); $GLOBALS['get_script'] = $get ? array( $get ) : array(); return ppg_website_default_lead_status( $fields, null, null, null ); };
$f  = $ws( array( 'email' => 'new@example.org', 'firstname' => 'Wanda' ), array( 404, '{}' ) );
check( 'website form, brand-new contact: Lead Status NEW is added', 'NEW' === ( $f['hs_lead_status'] ?? '' ) && 'Wanda' === $f['firstname'] );
check( 'it checks HubSpot by email first (read-only lookup)', 1 === count( $GLOBALS['gets'] ) && false !== strpos( $GLOBALS['gets'][0], 'new%40example.org' ) && false !== strpos( $GLOBALS['gets'][0], 'idProperty=email' ) );
$f = $ws( array( 'email' => 'old@example.org' ), array( 200, '{"properties":{"hs_lead_status":"IN_PROGRESS"}}' ) );
check( "website form, returning contact already 'In Progress': status is NOT added (never reset)", ! isset( $f['hs_lead_status'] ) );
$f = $ws( array( 'email' => 'blank@example.org' ), array( 200, '{"properties":{"hs_lead_status":null}}' ) );
check( 'website form, existing contact with no status: NEW is added', 'NEW' === ( $f['hs_lead_status'] ?? '' ) );
$f = $ws( array( 'email' => 'x@example.org', 'hs_lead_status' => 'OPEN' ), null );
check( 'a feed that already maps Lead Status is left exactly as set (no lookup)', 'OPEN' === $f['hs_lead_status'] && 0 === count( $GLOBALS['gets'] ) );
$f = $ws( array( 'firstname' => 'No Email' ), null );
check( 'no email: nothing added, no lookup', ! isset( $f['hs_lead_status'] ) && 0 === count( $GLOBALS['gets'] ) );
$f = $ws( array( 'email' => 'err@example.org' ), array( 500, '{}' ) );
check( 'if the HubSpot lookup fails the form is unaffected (nothing added)', ! isset( $f['hs_lead_status'] ) );
$f = $ws( array( 'email' => 'wp@example.org' ), new WP_Error( 'http', 'timeout' ) );
check( 'a network error never breaks the form submission', ! isset( $f['hs_lead_status'] ) );
check( "the filter is registered on Fluent Forms' fluentform/hubspot_field_data hook", 'ppg_website_default_lead_status' === ( function () { foreach ( $GLOBALS['hooks'] as $h ) { if ( 'filter' === $h[0] && 'fluentform/hubspot_field_data' === $h[1] ) { return $h[2]; } } return null; } )() );

/* ------------------------------------------------------------------ automatic running */
echo "Lead Sources\n";
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['source_calls'] = array(); $GLOBALS['existing_sources'] = null;
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Source', 'message' => 'm', 'dedupe_key' => '<src-1@x>' ) );
$sp = array_values( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) );
check( 'platform lead: Lead Sources gets the platform name (Checkatrade)', 1 === count( $sp ) && 'Checkatrade' === ( $sp[0][1]['properties']['lead_sources'] ?? '' ) );

$GLOBALS['source_calls'] = array(); $GLOBALS['existing_sources'] = 'Request a Quote;Checkatrade';
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Again', 'message' => 'm', 'dedupe_key' => '<src-2@x>' ) );
check( 'platform already ticked: nothing is sent', 0 === count( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) ) );
$GLOBALS['source_calls'] = array(); $GLOBALS['existing_sources'] = 'Request a Quote;Contact Form';
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Third', 'message' => 'm', 'dedupe_key' => '<src-3@x>' ) );
$sp = array_values( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) );
check( 'returning contact: the new platform is appended, existing ticks kept', 'Request a Quote;Contact Form;Checkatrade' === ( $sp[0][1]['properties']['lead_sources'] ?? '' ) );

$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['gets'] = array();
$form = (object) array( 'title' => 'Make an Appointment' );
$opts = array( 200, json_encode( array( 'options' => array( array( 'value' => 'Request a Quote' ), array( 'value' => 'Make an Appointment' ), array( 'value' => 'Contact Form' ) ) ) ) );
$GLOBALS['get_script'] = array( $opts, array( 404, '{}' ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form, new contact: Lead Sources = the form name', 'Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array( array( 200, json_encode( array( 'properties' => array( 'lead_sources' => 'Contact Form;Checkatrade' ) ) ) ) ); // options now cached
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form, returning contact: form name appended to what is already ticked', 'Contact Form;Checkatrade;Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array( array( 200, json_encode( array( 'properties' => array( 'lead_sources' => 'Make an Appointment' ) ) ) ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form: form name already ticked is not duplicated', 'Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array();
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, (object) array( 'title' => 'Unknown Form' ) );
check( 'form name that is not an option of the property: not sent (HubSpot would reject the whole push)', ! isset( $f['lead_sources'] ) );
$GLOBALS['t'] = array(); unset( $GLOBALS['o']['ppg_seen'] ); $GLOBALS['get_script'] = array( array( 500, '{}' ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'property lookup fails: the form push is untouched', ! isset( $f['lead_sources'] ) );

/* ------------------------------------------------------------------ security & robustness */
echo "Sender verification (anti-spoofing)\n";
$ar_ok   = "Received: from x\r\nAuthentication-Results: mx.google.com;\r\n       dkim=pass header.i=@checkatrade.com header.s=s1 header.b=abc;\r\n       spf=pass (google.com: domain of bounce@mail.checkatrade.com designates 1.2.3.4 as permitted sender) smtp.mailfrom=bounce@mail.checkatrade.com;\r\n       dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=checkatrade.com\r\nFrom: Checkatrade <no-reply@checkatrade.com>\r\n";
$ar_fake = "Authentication-Results: mx.google.com;\r\n       dkim=none;\r\n       spf=fail (google.com: domain of x@evil.test does not designate 5.6.7.8 as permitted sender) smtp.mailfrom=x@evil.test;\r\n       dmarc=fail (p=REJECT) header.from=checkatrade.com\r\nFrom: Checkatrade <no-reply@checkatrade.com>\r\n";
check( 'genuine Checkatrade mail (Gmail: dkim/spf/dmarc pass for checkatrade.com) is accepted', ppg_sender_verified( $ar_ok, 'checkatrade.com' ) );
check( 'forged From (Gmail: spf/dmarc fail) is rejected', ! ppg_sender_verified( $ar_fake, 'checkatrade.com' ) );
check( 'authenticated mail from ANOTHER domain claiming to be Checkatrade is rejected', ! ppg_sender_verified( "Authentication-Results: mx.google.com; dkim=pass header.i=@evil.test; spf=pass smtp.mailfrom=a@evil.test; dmarc=pass header.from=evil.test\r\n", 'checkatrade.com' ) );
check( 'look-alike domain (notcheckatrade.com) is rejected', ! ppg_sender_verified( "Authentication-Results: mx.google.com; dkim=pass header.i=@notcheckatrade.com; dmarc=pass header.from=notcheckatrade.com\r\n", 'checkatrade.com' ) );
check( 'a forged passing header placed BELOW Gmail\'s real (failing) one is ignored', ! ppg_sender_verified( $ar_fake . "Authentication-Results: mx.google.com; dkim=pass header.i=@checkatrade.com\r\n", 'checkatrade.com' ) );
check( 'a passing header not written by Gmail is ignored', ! ppg_sender_verified( "Authentication-Results: mail.evil.test; dkim=pass header.i=@checkatrade.com\r\n", 'checkatrade.com' ) );
check( 'no Authentication-Results header at all is rejected', ! ppg_sender_verified( "From: Checkatrade <no-reply@checkatrade.com>\r\n", 'checkatrade.com' ) && ! ppg_sender_verified( false, 'checkatrade.com' ) );

echo "Duplicate memory is constant-size\n";
unset( $GLOBALS['o']['ppg_seen'] );
for ( $i = 0; $i < 1600; $i++ ) {
	ppg_seen_add( array( 'x' . $i ) );
}
check( 'one option, never more than 1500 entries, however many leads arrive', count( $GLOBALS['o']['ppg_seen'] ) <= 1500 );
check( 'newest remembered, oldest trimmed', ppg_seen_has( array( 'x1599' ) ) && ! ppg_seen_has( array( 'x0' ) ) );
$GLOBALS['o']['ppg_seen'] = array( 'old' => time() - 3 * WEEK_IN_SECONDS, 'new' => time() );
check( 'entries expire after two weeks', ! ppg_seen_has( array( 'old' ) ) && ppg_seen_has( array( 'new' ) ) );
unset( $GLOBALS['o']['ppg_seen'] );

echo "Website forms never wait on a failing HubSpot\n";
$GLOBALS['t'] = array(); $GLOBALS['gets'] = array(); $GLOBALS['get_script'] = array( array( 500, '{}' ), array( 404, '{}' ) );
$f = ppg_website_default_lead_status( array( 'email' => 'b@example.org' ) );
check( 'first failure: form untouched and the circuit breaker opens', ! isset( $f['hs_lead_status'] ) && ! empty( $GLOBALS['t']['ppg_hs_down'] ) );
$f = ppg_website_default_lead_status( array( 'email' => 'c@example.org' ) );
check( 'while open, no HubSpot request is made at all (visitors are not slowed down)', 1 === count( $GLOBALS['gets'] ) && ! isset( $f['hs_lead_status'] ) );
$GLOBALS['t'] = array();

echo "Field lengths are capped\n";
$GLOBALS['script'] = array(); $GLOBALS['calls'] = array();
ppg_lead_process( 'checkatrade', array( 'name' => str_repeat( 'N', 500 ), 'message' => str_repeat( 'M', 20000 ), 'postcode' => 'AB1 2CD', 'lead_id' => 'CT-CAP-1', 'dedupe_key' => 'cap-1' ) );
$up = $GLOBALS['calls'][0][1]['inputs'][0]['properties'] ?? array();
check( 'name <= 100 and message <= 5000 characters', strlen( $up['firstname'] ?? '' ) <= 100 && strlen( $up['message'] ?? '' ) <= 5000 && strlen( $up['message'] ?? '' ) > 0 );
unset( $GLOBALS['o']['ppg_seen'] );

echo "Mailbox run (simulated Gmail)\n";
// A tiny fake IMAP server so the whole poller loop can be exercised without a real mailbox.
define( 'TYPETEXT', 0 ); define( 'ENCBASE64', 3 ); define( 'ENCQUOTEDPRINTABLE', 4 ); define( 'FT_PEEK', 2 );
define( 'PPG_IMAP_HOST', '{imap.test:993/imap/ssl}INBOX' ); define( 'PPG_IMAP_USER', 'u' ); define( 'PPG_IMAP_PASS', 'p' );
define( 'PPG_GO_LIVE', '2020-01-01 00:00:00 UTC' );
function wp_date( $f, $ts = null, $tz = null ) { return gmdate( $f, $ts ?? time() ); }
if ( ! function_exists( 'imap_open' ) ) {
	function imap_open( ...$a ) { return new stdClass(); }
	function imap_close( ...$a ) { return true; }
	function imap_last_error() { return ''; }
	function imap_utf8( $s ) { return $s; }
	function imap_search( $i, $c ) { return array_keys( $GLOBALS['mbox'] ); }
	function imap_headerinfo( $i, $n ) { $m = $GLOBALS['mbox'][ $n ]; return (object) array( 'from' => array( (object) array( 'host' => $m['host'] ) ), 'subject' => $m['subject'], 'message_id' => $m['mid'], 'udate' => $m['udate'], 'Size' => $m['size'] ?? 2000 ); }
	function imap_fetchheader( $i, $n ) { $GLOBALS['hdr_fetches'][] = $n; return $GLOBALS['mbox'][ $n ]['auth']; }
	function imap_fetchstructure( $i, $n ) { return (object) array( 'type' => 0, 'subtype' => 'PLAIN', 'encoding' => 0, 'parameters' => array() ); }
	function imap_fetchbody( $i, $n, $p, $f = 0 ) { $GLOBALS['body_fetches'][] = $n; return $GLOBALS['mbox'][ $n ]['body']; }
}
$mk = function ( $n, $auth = null, $host = 'mail.checkatrade.com', $extra = array() ) use ( $ar_ok ) {
	return array_merge( array(
		'host' => $host, 'mid' => "<m$n@test>", 'udate' => time() - 600 + $n, 'auth' => $auth ?? $ar_ok,
		'subject' => 'Gutter Cleaning & Clearance in RG41 3AP - respond now',
		'body'    => "New job request for you\nGutter Cleaning & Clearance in\nRG41 3AP\nCustomer\nPerson\nNumber$n\n Start date\nFlexible\nPerson's message\nPlease clean my gutters $n.\nI'm interested\n",
	), $extra );
};
$reset = function () { $GLOBALS['t'] = array(); $GLOBALS['o'] = array(); $GLOBALS['calls'] = array(); $GLOBALS['script'] = array(); $GLOBALS['hdr_fetches'] = array(); $GLOBALS['body_fetches'] = array(); unset( $GLOBALS['next_http'] ); };

$reset();
$GLOBALS['mbox'] = array( 1 => $mk( 1 ), 2 => $mk( 2, $ar_fake ), 3 => $mk( 3 ) );
$st = ppg_poll_mailbox_run();
check( 'run 1: 2 genuine leads imported, 1 forged email ignored (never reaches HubSpot)', 2 === $st['imported'] && 1 === $st['ignored'] && 'ok' === $st['outcome'] );
$bodies_before = count( $GLOBALS['body_fetches'] );
$calls_before  = count( $GLOBALS['calls'] );
$st = ppg_poll_mailbox_run();
check( 'run 2 (same mailbox): nothing imported twice, no email body downloaded and no HubSpot call for known leads', 0 === $st['imported'] && 2 === $st['duplicates'] && count( $GLOBALS['body_fetches'] ) === $bodies_before && count( $GLOBALS['calls'] ) === $calls_before );

$reset();
$GLOBALS['mbox'] = array( 1 => $mk( 1, null, 'mail.checkatrade.com', array( 'size' => 600000 ) ) );
$st = ppg_poll_mailbox_run();
check( 'a message over 512 KB is never opened', 0 === $st['imported'] && 0 === count( $GLOBALS['body_fetches'] ) );

$reset();
$GLOBALS['mbox'] = array(); for ( $n = 1; $n <= 60; $n++ ) { $GLOBALS['mbox'][ $n ] = $mk( $n ); }
$st = ppg_poll_mailbox_run();
check( 'flood protection: at most 40 leads per run, the rest wait for the next check', 40 === $st['imported'] && false !== strpos( $st['detail'], 'waiting' ) );
$st = ppg_poll_mailbox_run();
check( 'the next run picks up the remaining 20', 20 === $st['imported'] );

$reset();
$GLOBALS['mbox'] = array( 1 => $mk( 1 ), 2 => $mk( 2 ), 3 => $mk( 3 ) );
$GLOBALS['next_http'] = 500;
$st = ppg_poll_mailbox_run();
check( 'HubSpot down: run stops at the first failure (no hammering) and nothing is marked as done', 1 === $st['failed'] && 0 === $st['imported'] && false !== strpos( $st['detail'], 'retried' ) );
unset( $GLOBALS['next_http'] ); $GLOBALS['script'] = array();
$st = ppg_poll_mailbox_run();
check( 'HubSpot back: the same leads are retried and imported', 3 === $st['imported'] );

$reset();
$GLOBALS['mbox'] = array( 1 => $mk( 1 ), 2 => $mk( 2, $ar_fake ) );
ppg_poll_record( time(), ppg_poll_mailbox_run() );
$d = $GLOBALS['o']['ppg_poll_daily'][ gmdate( 'Y-m-d' ) ] ?? array();
check( 'daily counter keeps only two numbers: sent and ignored', array( 'sent' => 1, 'ignored' => 1 ) === $d && count( $GLOBALS['o']['ppg_poll_daily'] ) <= 2 && ! isset( $GLOBALS['o']['ppg_poll_log'] ) );
$GLOBALS['o']['ppg_poll_daily'] = array( '2020-01-01' => array( 'sent' => 1, 'ignored' => 0 ), '2020-01-02' => array( 'sent' => 1, 'ignored' => 0 ), '2020-01-03' => array( 'sent' => 1, 'ignored' => 0 ) );
ppg_poll_record( time(), array() );
check( 'only today and yesterday are ever kept', count( $GLOBALS['o']['ppg_poll_daily'] ) <= 2 );

/* ------------------------------------------------------------------ go-live */
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
