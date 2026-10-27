<?php
/**
 * Regression tests. Run:  php tests/run.php
 *
 * Fixtures are synthetic (invented names, example.org addresses) but reproduce the layouts found in
 * the real mailbox. No network access: HubSpot calls are recorded by a stub, never sent.
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
define( 'PPG_LEAD_SECRET', 'unit-test-secret' );

require dirname( __DIR__ ) . '/ppg-lead-gateway.php';

$pass = 0;
$fail = 0;
function check( $label, $ok ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
}
function parse( $src, $subject, $body ) { return ppg_parse_lead_email( $src, $subject, $body ); }

/* ------------------------------------------------------------------ Yell */
echo "Yell\n";
$yell = function ( $desc, $postcode = 'HP13 5ST' ) {
	return "You have a new enquiry\nHi PP Gutters Ltd\nYou have been chosen to receive a Job enquiry from Yell.com.\nTest is looking for some help with the following enquiry\nDescription\n$desc\nPostcode\n $postcode\nClick the Show enquiry below to view the details and respond if you can assist.\nShow enquiry http://eml.yell.com/ls/click?upn=abc123\nIf you have any queries, please contact us or call 0800 555 444\nPlease add customerenquiry@em.yell.com to your address book.";
};
$r = parse( 'yell', 'New enquiry from Tina Test', $yell( "Need gutters fixed.\nPlease contact on tina@example.org or 07123 456789\nThanks" ) );
check( 'description with email + phone: both extracted', $r['email'] === 'tina@example.org' && $r['phone'] === '07123 456789' );
check( 'name from subject, postcode from field, link kept', $r['name'] === 'Tina Test' && $r['postcode'] === 'HP13 5ST' && false !== strpos( $r['message'], 'eml.yell.com' ) );
$r = parse( 'yell', 'New enquiry from Tina Test', $yell( 'Call me on 07123 456789 please' ) );
check( 'phone only: phone extracted, email empty', $r['phone'] === '07123 456789' && '' === $r['email'] );
$r = parse( 'yell', 'New enquiry from Tina Test', $yell( 'Gutter leaking, can you quote?' ) );
check( "Yell's own footer number (0800 555 444) and sender address are NOT taken as customer details", '' === $r['phone'] && '' === $r['email'] );
check( 'Reminder emails are skipped', null === parse( 'yell', 'Reminder: New enquiry from Tina Test', $yell( 'x' ) ) );
check( 'Yell invoice email is skipped', null === parse( 'yell', 'Your latest Yell invoice is available', 'x' ) );
$r = parse( 'yell', 'New enquiry from Mr Test', "You have a new enquiry\nHi PP Gutters Ltd http://eml.yell.com/wf/click?upn=zzz High Wycombe, HP11 2SS\nmr has sent you an enquiry and is looking for your help ASAP\nShow enquiry http://eml.yell.com/x" );
check( 'oldest layout (no Postcode field): postcode taken from greeting line', 'HP11 2SS' === $r['postcode'] );
$r = parse( 'yell', 'New enquiry from Demo Person', "You have a new enquiry\nHi PP Gutters Ltd\nDemo is looking for some help with the following enquiry\nDescription\ni need gutter cleaning it is a test\nPostcode\nClick the Show enquiry below to view the details and respond if you can assist.\nShow enquiry http://eml.yell.com/ls/click?upn=abc123\nplease call 0800 555 444" );
check( 'empty Postcode field: the next sentence is NOT taken as the postcode', '' === $r['postcode'] && 'Demo Person' === $r['name'] );
$r = parse( 'yell', 'New enquiry from Tina Test', $yell( 'x', 'Hp135st' ) );
check( 'postcodes typed without a space or in lower case are still accepted', 'HP135ST' === $r['postcode'] );
$r = parse( 'yell', 'FW: New enquiry from Tina Test', $yell( 'Email me tina@example.org' ) );
check( 'forwarded subject (FW:) still parses', null !== $r && 'tina@example.org' === $r['email'] );

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

/* ------------------------------------------------------------------ Yelp */
echo "Yelp\n";
$yelp_new = "Tess requested a quote from PP Gutters for a gutter cleaning. Reply to stay eligible for leads and keep your response rate up!\n## You have a new gutter cleaning request.\n"
	. "[Reply to Tess on Yelp Biz](https://biz.yelp.co.uk/login/passwordless/redirect/SECRETLOGINTOKEN0123456789abcdef?return_url=%2Fleads_center%2FBizId0123456789abcd%2Fleads%2FLeadId_0123456789ab&amp;ytl_=1&amp;utm_medium=email)\n"
	. "Sent to PP Gutters\n13 Queens Court High Wycombe HP13 6BA\nHow tall is your home?\n1 story\nWhen do you require this service?\nAs soon as possible\n"
	. "Are there any other details you'd like to share?\nPlease call 07700 900321 or email tess@example.org after 5pm\nIn what location do you need the service?\nSL6 7NZ\nTess T.\n0\n0\n0\n"
	. "[Reply to Tess on Yelp Biz](https://biz.yelp.co.uk/login/passwordless/redirect/SECRETLOGINTOKEN0123456789abcdef?return_url=%2Fleads_center%2FBizId0123456789abcd%2Fleads%2FLeadId_0123456789ab)\n"
	. "[I'm not interested](https://biz.yelp.co.uk/messaging/mark_as_replied_autosubmit/cd85596fe5ac4f69a4bbecd9c1c1a73e?reply_type=not_interested)\n"
	. "Or reply directly to this email reply+cd85596fe5ac4f69a4bbecd9c1c1a73e@messaging.yelp.com\nThis email was sent to [info@ppgutters.co.uk](mailto:info@ppgutters.co.uk)";
$r = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", $yelp_new );
check( 'current format: name and service', 'Tess T.' === $r['name'] && false !== strpos( $r['message'], 'Yelp quote request: gutter cleaning' ) );
check( "current format: the customer's answers are kept in the message", false !== strpos( $r['message'], 'How tall is your home? 1 story' ) && false !== strpos( $r['message'], 'When do you require this service? As soon as possible' ) );
check( 'current format: location answer becomes the postcode', 'SL6 7NZ' === $r['postcode'] );
check( 'current format: phone and email typed into the answers are extracted', '07700 900321' === $r['phone'] && 'tess@example.org' === $r['email'] );
check( "Yelp lead id comes from the lead page in the login link's return_url", 'yelp:LeadId_0123456789ab' === $r['lead_id'] );
check( 'safe lead URL (login-protected page) is stored', false !== strpos( $r['message'], 'https://biz.yelp.co.uk/leads_center/BizId0123456789abcd/leads/LeadId_0123456789ab' ) );
check( 'passwordless-login token, mark-as-replied link and tracking params are NEVER stored', ! preg_match( '/SECRETLOGINTOKEN|passwordless|mark_as_replied|ytl_|utm_/i', json_encode( $r ) ) );
check( "Yelp relay address (reply+...@messaging.yelp.com) and our own address are NOT the customer's email", 'tess@example.org' === $r['email'] );
$r = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", str_replace( array( "Please call 07700 900321 or email tess@example.org after 5pm", 'SL6 7NZ' ), array( 'Just a quote please', '60622' ), $yelp_new ) );
check( 'no contact typed by the customer: email and phone empty (nothing invented); non-UK location kept as given', '' === $r['email'] && '' === $r['phone'] && '60622' === $r['postcode'] );
$only_hex = str_replace( 'return_url=%2Fleads_center%2FBizId0123456789abcd%2Fleads%2FLeadId_0123456789ab', 'x=1', $yelp_new );
$r        = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", $only_hex );
check( 'fallback lead id: 32-hex conversation id from the mark-as-replied link (id only, not the link)', 'yelp:cd85596fe5ac4f69a4bbecd9c1c1a73e' === $r['lead_id'] && false === strpos( $r['message'], 'mark_as_replied' ) );
$thin = "Himanshu B.\nSent to PP Gutters\n";
$r    = parse( 'yelp', "New Lead: Reply to Himanshu's gutter services request", "Sent to PP Gutters\n13 Queens Court High Wycombe HP13 6BA\nHimanshu B.\n" );
check( '2024-style thin email (no answers): name + service only, no postcode', 'Himanshu B.' === $r['name'] && '' === $r['postcode'] && false !== strpos( $r['message'], 'no answers' ) );
$yelp_old = "The clock is ticking!\nGorm is interested in working with you!\nSent to PP Gutters at 13 Queens Court, High Wycombe HP13 6BA\nHello\nGarage gutter has fallen off at 27 Test Road, Maidstone SL6 7NZ. Call 07700 900789 or email gorm@example.org\nGorm\n---\n---\n[ Respond Now ](https://biz.yelp.co.uk/messaging/abc/thread/xyz?ytl_=1)";
$r = parse( 'yelp', 'New Message: Gorm is requesting a quote from PP Gutters', $yelp_old );
check( 'older format: message phone + email + postcode', '07700 900789' === $r['phone'] && 'gorm@example.org' === $r['email'] && 'SL6 7NZ' === $r['postcode'] );
check( 'Yelp reply / follow-up / login emails are skipped',
	null === parse( 'yelp', 'New Reply Message from Gorm', $yelp_old ) && null === parse( 'yelp', 'Message from Gorm for PP Gutters', $yelp_old ) && null === parse( 'yelp', 'New login to your Yelp account', 'x' ) );
check( 'forwarded Yelp subject (FW:) parses', null !== parse( 'yelp', "FW: New Lead: Reply to Tess's gutter cleaning request", $yelp_new ) );

echo "Yelp answers -> dedicated HubSpot fields\n";
$r = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", $yelp_new );
check( 'the four known Yelp questions map to their HubSpot properties', array(
	'how_tall_is_your_home'                          => '1 story',
	'when_do_you_require_this_service'               => 'As soon as possible',
	'are_there_any_other_details_youd_like_to_share' => 'Please call 07700 900321 or email tess@example.org after 5pm',
	'in_what_location_do_you_need_the_service'       => 'SL6 7NZ',
) === $r['fields'] );
$other = str_replace( 'How tall is your home?', 'What colour is your roof?', $yelp_new );
$r2    = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", $other );
check( 'an unknown question gets no dedicated field (still in the message and note)', ! isset( $r2['fields']['how_tall_is_your_home'] ) && false !== strpos( $r2['message'], 'What colour is your roof? 1 story' ) && 3 === count( $r2['fields'] ) );
check( "curly apostrophe in \"you\u{2019}d like\" still maps", 'x' === ppg_yelp_answer_property( "Are there any other details you\u{2019}d like to share?" ) ? false : '' !== ppg_yelp_answer_property( "Are there any other details you\u{2019}d like to share?" ) );

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
$r = ppg_lead_process( 'yell', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Yell enquiry: gutters', 'dedupe_key' => '<yell-1@x>' ) );
$p = $last();
check( 'phone-only lead: created (not held)', 'ok' === ( $r['status'] ?? '' ) && 0 === count( $queue() ) );
check( 'phone-only lead: matched on ppg_lead_id, phone included, NO email property', 'ppg_lead_id' === ( $p['idProperty'] ?? '' ) && '07999000111' === ( $p['properties']['phone'] ?? '' ) && ! isset( $p['properties']['email'] ) );
check( 'phone-only lead: platform recorded', 'yell' === ( $p['properties']['lead_source_platform'] ?? '' ) );

// 3. neither email nor phone
$r = ppg_lead_process( 'checkatrade', array( 'name' => 'Nora Nothing', 'postcode' => 'RG41 3AP', 'message' => "Checkatrade job request\nRespond on Checkatrade: https://clicks.checkatrade.com/f/a/abc", 'dedupe_key' => '<ca-1@x>' ) );
$p = $last();
check( 'no email and no phone: still created from name + postcode + message/link', 'ok' === ( $r['status'] ?? '' ) && 'Nora' === ( $p['properties']['firstname'] ?? '' ) && 'Nothing' === ( $p['properties']['lastname'] ?? '' ) && 'RG41 3AP' === ( $p['properties']['zip'] ?? '' ) && false !== strpos( $p['properties']['message'] ?? '', 'clicks.checkatrade.com' ) );
check( 'no email and no phone: no email/phone properties invented', ! isset( $p['properties']['email'] ) && ! isset( $p['properties']['phone'] ) );

// 4. never invent or relay an email
$r = ppg_lead_process( 'yelp', array( 'name' => 'Yelp Person', 'email' => 'reply+19486ec34fff4212@messaging.yelp.com', 'dedupe_key' => '<yelp-1@x>' ) );
$p = $last();
check( "Yelp relay address is dropped, not used as the customer's email", 'ok' === ( $r['status'] ?? '' ) && ! isset( $p['properties']['email'] ) && 'ppg_lead_id' === $p['idProperty'] );
$r = ppg_lead_process( 'yell', array( 'name' => 'Bad Email', 'email' => 'not-an-email', 'dedupe_key' => '<bad-1@x>' ) );
check( 'invalid email text is dropped; no email invented', 'ok' === ( $r['status'] ?? '' ) && ! isset( $last()['properties']['email'] ) );

// 5. duplicates and different customers
$before = $n();
$r      = ppg_lead_process( 'yell', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Yell enquiry: gutters', 'dedupe_key' => '<yell-1@x>' ) );
check( 'same lead (same Message-ID) is not processed twice', 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );
$r = ppg_lead_process( 'yell', array( 'name' => 'Phil Phone', 'phone' => '07999 000111', 'postcode' => 'HP13 5ST', 'message' => 'Yell enquiry: gutters', 'dedupe_key' => '<yell-1-resent@x>' ) );
check( 'identical content re-sent under another Message-ID is not processed twice', 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );
$r  = ppg_lead_process( 'yell', array( 'name' => 'John Smith', 'postcode' => 'HP1 1AA', 'message' => 'Yell enquiry: first', 'dedupe_key' => '<js-1@x>' ) );
$id1 = $last()['id'];
$r  = ppg_lead_process( 'yell', array( 'name' => 'John Smith', 'postcode' => 'SL6 7NZ', 'message' => 'Yell enquiry: second', 'dedupe_key' => '<js-2@x>' ) );
$id2 = $last()['id'];
check( 'two different customers with the same name get different ids (never merged)', 'ok' === ( $r['status'] ?? '' ) && $id1 !== $id2 );
$r = ppg_lead_process( 'yell', array( 'name' => 'John Smith', 'phone' => '07111222333', 'postcode' => 'HP1 1AA', 'message' => 'Yell enquiry: third', 'dedupe_key' => '<js-3@x>' ) );
check( 'phone numbers are not used to match contacts', $last()['id'] !== $id1 );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Yelp Thread', 'message' => 'a', 'lead_id' => 'yelp:AbCdEf123456', 'dedupe_key' => '<y-a@x>' ) );
$ya = $last()['id'];
$before = $n();
$r = ppg_lead_process( 'yelp', array( 'name' => 'Yelp Thread', 'message' => 'a second email for the same conversation', 'lead_id' => 'yelp:AbCdEf123456', 'dedupe_key' => '<y-b@x>' ) );
check( "platform lead id (Yelp thread) is used as the identity; same conversation not created twice", 'yelp:AbCdEf123456' === $ya && 'duplicate_ignored' === ( $r['status'] ?? '' ) && $n() === $before );

// 6. review queue only when a lead genuinely cannot be created
$before = $n();
$r      = ppg_lead_process( 'yell', array( 'message' => 'nothing identifies this', 'subject' => 'New enquiry from', 'dedupe_key' => '<empty-1@x>' ) );
check( 'no name, email or phone: NOT sent, held for review', is_wp_error( $r ) && 'ppg_unprocessable' === $r->get_error_code() && $n() === $before && 1 === count( $queue() ) );
ppg_lead_process( 'yell', array( 'message' => 'nothing identifies this', 'dedupe_key' => '<empty-1@x>' ) );
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
$GLOBALS['t'] = array(); // fresh duplicate memory
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, $nonunique ), array( 200, '{"total":0,"results":[]}' ), array( 201, '{"id":"777"}' ), array( 201, '{"id":"8"}' ) );
$r  = ppg_lead_process( 'yelp', array( 'name' => 'Fallback Fran', 'message' => 'm', 'lead_id' => 'yelp:FallbackLead0001', 'dedupe_key' => '<fb-1@x>' ) );
$m  = array_map( function ( $c ) { return $c[2] ?? 'POST'; }, $GLOBALS['calls'] );
$u  = array_map( function ( $c ) { return basename( $c[0] ); }, $GLOBALS['calls'] );
check( 'upsert refused as non-unique -> falls back to search, then creates (id returned), sets status, then adds the note', 'ok' === ( $r['status'] ?? '' ) && '777' === ( $r['hubspot_id'] ?? '' ) && array( 'upsert', 'search', 'contacts', '777', 'notes' ) === $u );
$created = $GLOBALS['calls'][2][1];
check( 'the created contact carries ppg_lead_id and no email', 'yelp:FallbackLead0001' === ( $created['properties']['ppg_lead_id'] ?? '' ) && ! isset( $created['properties']['email'] ) );
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, '{"total":0,"results":[]}' ), array( 201, '{"id":"778"}' ), array( 201, '{"id":"9"}' ) );
ppg_lead_process( 'yelp', array( 'name' => 'Second Sam', 'message' => 'm2', 'lead_id' => 'yelp:FallbackLead0002', 'dedupe_key' => '<fb-2@x>' ) );
check( 'once learned, later leads skip the failing upsert (search, create, status, note)', array( 'search', 'contacts', '778', 'notes' ) === array_map( function ( $c ) { return basename( $c[0] ); }, $GLOBALS['calls'] ) );
$GLOBALS['calls'] = array();
$GLOBALS['t']['ppg_upsert_unsupported'] = 1;
$GLOBALS['script'] = array( array( 200, '{"total":1,"results":[{"id":"555"}]}' ), array( 200, '{"id":"555"}' ) );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Existing Eve', 'message' => 'm3', 'lead_id' => 'yelp:FallbackLead0003', 'dedupe_key' => '<fb-3@x>' ) );
check( 'lead id already in HubSpot -> existing contact is updated (PATCH), not duplicated, and NO second note', '555' === ( $r['hubspot_id'] ?? '' ) && 'PATCH' === ( end( $GLOBALS['calls'] )[2] ?? '' ) && 2 === count( $GLOBALS['calls'] ) && 0 === count( $notes() ) );
unset( $GLOBALS['script'], $GLOBALS['t']['ppg_upsert_unsupported'] );

// 6c. timeline note (the details must be easy to see in HubSpot)
echo "HubSpot timeline note\n";
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
$r = ppg_lead_process( 'yelp', array( 'name' => 'Nina Note', 'message' => "Yelp quote request: gutter cleaning\nHow tall is your home? 1 story\nLead on Yelp: https://biz.yelp.co.uk/leads_center/B1/leads/L1<script>", 'lead_id' => 'yelp:NoteLead000001', 'dedupe_key' => '<note-1@x>' ) );
$nn = $notes();
check( 'a new lead gets exactly one timeline note', 'ok' === ( $r['status'] ?? '' ) && 1 === count( $nn ) );
$np = $nn[0][1] ?? array();
check( 'the note is associated with the new contact (note -> contact, type 202)', '1' === ( $np['associations'][0]['to']['id'] ?? '' ) && 202 === ( $np['associations'][0]['types'][0]['associationTypeId'] ?? 0 ) );
check( 'the note contains every answer and a clickable lead link', false !== strpos( $np['properties']['hs_note_body'] ?? '', 'How tall is your home? 1 story' ) && false !== strpos( $np['properties']['hs_note_body'] ?? '', '<a href="https://biz.yelp.co.uk/leads_center/B1/leads/L1' ) );
check( 'note text is HTML-escaped (no injected markup)', false === strpos( $np['properties']['hs_note_body'] ?? '', '<script>' ) );
$before = count( $notes() );
ppg_lead_process( 'yelp', array( 'name' => 'Nina Note', 'message' => 'x', 'lead_id' => 'yelp:NoteLead000001', 'dedupe_key' => '<note-1@x>' ) );
check( 'a duplicate lead does not add another note', count( $notes() ) === $before );
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '42', 'new' => false ) ) ) ) ) );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Nina Note', 'message' => 'same lead after a cache flush', 'lead_id' => 'yelp:NoteLead000002', 'dedupe_key' => '<note-2@x>' ) );
check( 'unique ppg_lead_id already in HubSpot (new:false) -> same lead, no second note', 'ok' === ( $r['status'] ?? '' ) && count( $notes() ) === $before );
$GLOBALS['calls'] = array();
ppg_lead_process( 'yell', array( 'name' => 'Emma Mail', 'email' => 'emma@example.org', 'message' => 'Yell enquiry: gutters', 'dedupe_key' => '<note-3@x>' ) );
check( 'a lead with an email also gets its note (a repeat customer keeps every lead)', 1 === count( $notes() ) );
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '43', 'new' => true ) ) ) ) ), array( 403, '{"message":"missing scope"}' ) );
$r = ppg_lead_process( 'yell', array( 'name' => 'Noscope Nick', 'email' => 'nick@example.org', 'message' => 'm', 'dedupe_key' => '<note-4@x>' ) );
check( 'if the note cannot be created the lead is still imported (note failure only logged)', 'ok' === ( $r['status'] ?? '' ) );
unset( $GLOBALS['script'] );

// 6d. dedicated answer fields
echo "Dedicated answer fields in HubSpot\n";
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
$fields = array( 'how_tall_is_your_home' => '1 story', 'in_what_location_do_you_need_the_service' => '60622' );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Fiona Fields', 'message' => 'm', 'lead_id' => 'yelp:FieldsLead00001', 'dedupe_key' => '<f-1@x>', 'fields' => $fields ) );
$p = $last();
check( 'answers are sent as their own HubSpot properties alongside the standard fields', '1 story' === ( $p['properties']['how_tall_is_your_home'] ?? '' ) && '60622' === ( $p['properties']['in_what_location_do_you_need_the_service'] ?? '' ) && 'Fiona' === ( $p['properties']['firstname'] ?? '' ) );
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"Property \"how_tall_is_your_home\" does not exist"}' ), array( 200, json_encode( array( 'results' => array( array( 'id' => '66', 'new' => true ) ) ) ) ), array( 201, '{"id":"1"}' ) );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Gina Gone', 'message' => 'm', 'lead_id' => 'yelp:FieldsLead00002', 'dedupe_key' => '<f-2@x>', 'fields' => $fields ) );
$uc = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) );
check( 'a deleted/renamed HubSpot field does not block the lead: retried without the dedicated fields', 'ok' === ( $r['status'] ?? '' ) && 2 === count( $uc ) && isset( $uc[0][1]['inputs'][0]['properties']['how_tall_is_your_home'] ) && ! isset( $uc[1][1]['inputs'][0]['properties']['how_tall_is_your_home'] ) );
check( 'the retry keeps the standard fields (name, platform, lead id)', 'Gina' === ( $uc[1][1]['inputs'][0]['properties']['firstname'] ?? '' ) && 'yelp' === ( $uc[1][1]['inputs'][0]['properties']['lead_source_platform'] ?? '' ) );
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"some other validation problem"}' ) );
$r = ppg_lead_process( 'yelp', array( 'name' => 'Harry Held', 'message' => 'm', 'lead_id' => 'yelp:FieldsLead00003', 'dedupe_key' => '<f-3@x>', 'fields' => $fields ) );
check( 'any other HubSpot 400 is NOT retried blindly (held for review instead)', is_wp_error( $r ) && 1 === count( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) ) );
unset( $GLOBALS['script'] );
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
ppg_lead_process( 'yelp', array( 'name' => 'Ivy Injection', 'message' => 'm', 'lead_id' => 'yelp:FieldsLead00004', 'dedupe_key' => '<f-4@x>', 'fields' => array( 'bad name;drop' => 'x', 'lifecyclestage' => 'customer', 'how_tall_is_your_home' => '2 storey' ) ) );
$pp = $last()['properties'] ?? array();
check( 'only well-formed property names are accepted', ! isset( $pp['bad name;drop'] ) && '2 storey' === ( $pp['how_tall_is_your_home'] ?? '' ) );

// 6e. "Lead Source" (lead_page_url) and "How can we help?" (the customer's own words)
echo "Lead Source and customer description\n";
$sent = function ( $source, $lead ) use ( $last ) {
	$GLOBALS['t'] = array();
	ppg_lead_process( $source, $lead + array( 'dedupe_key' => '<ls-' . $source . '-' . mt_rand() . '@x>' ) );
	return $last()['properties'] ?? array();
};
check( "Yelp leads: Lead Source = 'Yelp'", 'Yelp' === ( $sent( 'yelp', array( 'name' => 'A B', 'message' => 'm' ) )['lead_page_url'] ?? '' ) );
check( "Yell leads: Lead Source = 'Yell'", 'Yell' === ( $sent( 'yell', array( 'name' => 'A B', 'message' => 'm' ) )['lead_page_url'] ?? '' ) );
check( "Checkatrade leads: Lead Source = 'Checkatrade'", 'Checkatrade' === ( $sent( 'checkatrade', array( 'name' => 'A B', 'message' => 'm' ) )['lead_page_url'] ?? '' ) );
check( 'the internal platform tag (lead_source_platform) is still set', 'yell' === ( $sent( 'yell', array( 'name' => 'A B', 'message' => 'm' ) )['lead_source_platform'] ?? '' ) );
$pp = $sent( 'yell', array( 'name' => 'A B', 'message' => 'm', 'description' => "Gutters leaking\non the left side" ) );
check( "customer's own words go to 'How can we help?' (as on the website forms), on one line", 'Gutters leaking on the left side' === ( $pp['how_can_we_help'] ?? '' ) );
check( 'no description => How can we help? is not sent (nothing invented)', ! isset( $sent( 'yell', array( 'name' => 'A B', 'message' => 'm' ) )['how_can_we_help'] ) );
$rr = parse( 'yell', 'New enquiry from Tina Test', $yell( 'Need gutters fixed please' ) );
check( 'Yell parser: description = what the customer wrote', 'Need gutters fixed please' === $rr['description'] );
$rr = parse( 'checkatrade', 'Roof Cleaning in WD19 7QT - respond now', "Roof Cleaning in WD19 7QT\nName\nRenata Test Start date\nASAP\nRenata's message\nHow much for 20ft of roof?\n$ca_tail" );
check( "Checkatrade parser: description = the customer's message", 'How much for 20ft of roof?' === $rr['description'] );
$rr = parse( 'yelp', "New Lead: Reply to Tess's gutter cleaning request", $yelp_new );
check( "Yelp parser: description = the answer to 'any other details'", 'Please call 07700 900321 or email tess@example.org after 5pm' === $rr['description'] );
$rr = parse( 'yelp', 'New Message: Gorm is requesting a quote from PP Gutters', $yelp_old );
check( 'older Yelp format: description = the customer message', false !== strpos( $rr['description'], 'Garage gutter has fallen off' ) );
$GLOBALS['t'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 400, '{"message":"Property \"how_can_we_help\" does not exist"}' ), array( 200, json_encode( array( 'results' => array( array( 'id' => '81', 'new' => true ) ) ) ) ), array( 201, '{"id":"1"}' ) );
$r  = ppg_lead_process( 'yell', array( 'name' => 'Missing Mo', 'message' => 'm', 'description' => 'd', 'dedupe_key' => '<ls-missing@x>' ) );
$up = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return false !== strpos( $c[0], 'upsert' ); } ) );
check( 'if a HubSpot field was deleted the lead is still imported (retried without the optional fields)', 'ok' === ( $r['status'] ?? '' ) && 2 === count( $up ) && ! isset( $up[1][1]['inputs'][0]['properties']['lead_page_url'] ) && 'yell' === ( $up[1][1]['inputs'][0]['properties']['lead_source_platform'] ?? '' ) );
unset( $GLOBALS['script'] );

// 7. parsers feed the same rules
$yl = ppg_parse_lead_email( 'yelp', "New Lead: Reply to Himanshu's gutter services request", "Himanshu B.\n[Reply to Himanshu on Yelp Biz](https://biz.yelp.co.uk/messaging/BizId1234567/thread/ThreadId_456789)" );
check( 'older Yelp emails: conversation id from the plain thread link', 'yelp:ThreadId_456789' === $yl['lead_id'] );

/* ------------------------------------------------------------------ Checkatrade webhook */
echo "Checkatrade lead delivery webhook (customer email + phone)\n";
$payload = array(
	'jobId' => 'CA-1001', 'category' => 'Gutter Cleaning', 'title' => 'Gutter Cleaning in RG41 3AP', 'jobDescription' => "Gutters overflowing at the front.\nPlease quote.",
	'source' => 'REQUEST_A_QUOTE', 'dateCreated' => '2026-10-20T09:00:00Z', 'postcode' => 'RG41 3AP',
	'customerName' => 'Wendy Webhook', 'customerPhone' => '07700 900555', 'customerEmail' => 'wendy@example.org',
	'preferredStart' => array( 'option' => 'ASAP', 'date' => '2026-10-28' ),
	'additionalInformation' => array( array( 'question' => 'Property type', 'answer' => 'Semi-detached' ) ),
	'leadEnrichment' => array( 'questions' => array(
		array( 'questionId' => 'q1', 'title' => 'How many storeys?', 'format' => 'SINGLE_SELECT', 'answers' => array( array( 'key' => '2', 'value' => 'Two' ) ) ),
		array( 'questionId' => 'q2', 'title' => 'Which sides?', 'format' => 'MULTI_SELECT', 'answers' => array( array( 'value' => 'Front' ), array( 'value' => 'Back' ) ) ),
		array( 'questionId' => 'q3', 'title' => 'Photos', 'format' => 'PHOTO_UPLOAD', 'answers' => array( array( 'value' => null, 'url' => 'https://img.example.org/p1.jpg' ) ) ),
		array( 'questionId' => 'q4', 'title' => 'Budget', 'format' => 'TEXT', 'answers' => array( array( 'value' => 250 ) ) ),
	) ),
	'media' => array( array( 'url' => 'https://images.example.org/a.jpg' ) ),
	'someFutureField' => 'ignored',
);
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$res = ppg_checkatrade_handle( new WP_REST_Request( $payload ) );
$p   = $last();
check( 'delivery is acknowledged with HTTP 201', $res instanceof WP_REST_Response && 201 === $res->status );
check( "the customer's real email is used: contact matched on email", 'email' === ( $p['idProperty'] ?? '' ) && 'wendy@example.org' === ( $p['id'] ?? '' ) );
check( "the customer's real phone is added", '07700900555' === ( $p['properties']['phone'] ?? '' ) );
check( 'name, postcode, platform, Lead Source are set', 'Wendy' === ( $p['properties']['firstname'] ?? '' ) && 'RG41 3AP' === ( $p['properties']['zip'] ?? '' ) && 'Checkatrade' === ( $p['properties']['lead_page_url'] ?? '' ) && 'checkatrade' === ( $p['properties']['lead_source_platform'] ?? '' ) );
check( "the customer's own words go to 'How can we help?'", 'Gutters overflowing at the front. Please quote.' === ( $p['properties']['how_can_we_help'] ?? '' ) );
$msg = $p['properties']['message'] ?? '';
check( 'message holds the job details: Q&A, enrichment, start date, job id', false !== strpos( $msg, 'Property type: Semi-detached' ) && false !== strpos( $msg, 'How many storeys?: Two' ) && false !== strpos( $msg, 'Preferred start: ASAP' ) && false !== strpos( $msg, 'Checkatrade job ID: CA-1001' ) );
check( 'documented leadEnrichment {questions:[{title,answers:[{value,url}]}]} is read: single, multi-select, photo url, numeric value', false !== strpos( $msg, 'How many storeys?: Two' ) && false !== strpos( $msg, 'Which sides?: Front, Back' ) && false !== strpos( $msg, 'Photos: https://img.example.org/p1.jpg' ) && false !== strpos( $msg, 'Budget: 250' ) );
check( 'start date and lead type (source enum) are recorded', false !== strpos( $msg, 'Preferred start: ASAP (2026-10-28)' ) && false !== strpos( $msg, 'Checkatrade lead type: REQUEST_A_QUOTE' ) );
check( 'a timeline note is added for the new lead', 1 === count( $notes() ) );
check( 'unknown extra fields are tolerated (Checkatrade may add fields)', 'ok' === ( json_decode( json_encode( $res->data ), true )['status'] ?? '' ) );
$before = count( $GLOBALS['calls'] );
$res    = ppg_checkatrade_handle( new WP_REST_Request( $payload ) );
check( 'the same job delivered twice: still 201 (so Checkatrade does not retry) and nothing new is written', 201 === $res->status && count( $GLOBALS['calls'] ) === $before );
unset( $payload['customerEmail'], $payload['customerPhone'] );
$payload['jobId'] = 'CA-1002';
$GLOBALS['calls'] = array();
$res = ppg_checkatrade_handle( new WP_REST_Request( $payload ) );
check( 'a job with no email/phone is still created (matched on ppg_lead_id = checkatrade:<jobId>)', 201 === $res->status && 'ppg_lead_id' === ( $last()['idProperty'] ?? '' ) && 'checkatrade:CA-1002' === ( $last()['id'] ?? '' ) );
$res = ppg_checkatrade_handle( new WP_REST_Request( array( 'customerName' => 'No Id' ) ) );
check( 'missing jobId is rejected (400)', is_wp_error( $res ) && 400 === ( $res->get_error_data()['status'] ?? 0 ) );
$res = ppg_checkatrade_handle( new WP_REST_Request( null, '{"broken": ' ) );
check( 'invalid JSON is rejected (400), not crashed on', is_wp_error( $res ) && 400 === ( $res->get_error_data()['status'] ?? 0 ) );
$GLOBALS['script'] = array( array( 500, '{"message":"hubspot down"}' ) );
$res = ppg_checkatrade_handle( new WP_REST_Request( array( 'jobId' => 'CA-1003', 'customerName' => 'Down Dave', 'customerEmail' => 'dave@example.org' ) ) );
check( 'if HubSpot is down the webhook returns an error (not 201), so Checkatrade can retry', is_wp_error( $res ) );
unset( $GLOBALS['script'] );
// source-IP check (Checkatrade authenticates by IP only)
$_SERVER['REMOTE_ADDR'] = '35.246.15.126';
check( 'a published Checkatrade PRODUCTION IP is allowed', true === ppg_checkatrade_authorise() );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$deny = ppg_checkatrade_authorise();
check( 'any other IP is refused (403)', is_wp_error( $deny ) && 403 === ( $deny->get_error_data()['status'] ?? 0 ) );
$_SERVER['REMOTE_ADDR'] = '34.105.162.121';
check( 'a Checkatrade TEST IP is refused on a production site', is_wp_error( ppg_checkatrade_authorise() ) );
$GLOBALS['env_type'] = 'staging';
check( 'a Checkatrade TEST IP is accepted on a staging site (for the test payload)', true === ppg_checkatrade_authorise() );
$GLOBALS['env_type'] = 'production';
$_SERVER['REMOTE_ADDR'] = '34.142.46.71';
check( 'the old/legacy Checkatrade IPs are not allowed (new integrations only)', is_wp_error( ppg_checkatrade_authorise() ) );

/* ------------------------------------------------------------------ /lead for Zapier (Yelp Leads) */
echo "/lead endpoint for Zapier (Yelp Leads)\n";
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$zap = array( 'source' => 'yelp', 'name' => 'Zoe Zap', 'lead_id' => 'DxbqixfnYwVdh852eUy-kg', 'masked_email' => 'abc123@messaging.yelp.com', 'masked_email_expiry' => '2026-11-18T09:42:00Z', 'message' => 'Gutter cleaning request', 'description' => 'Two storey semi', 'how_tall_is_your_home' => '2 storey', 'in_what_location_do_you_need_the_service' => 'SL6 7NZ' );
$res = ppg_lead_handle( new WP_REST_Request( $zap ) );
$p   = $last();
check( 'Zapier lead: created, Yelp lead id is namespaced and used as the identity', 'ok' === ( $res['status'] ?? '' ) && 'yelp:DxbqixfnYwVdh852eUy-kg' === ( $p['id'] ?? '' ) );
check( "Yelp's masked email is stored ONLY in its own fields (with its expiry)", 'abc123@messaging.yelp.com' === ( $p['properties']['yelp_masked_email'] ?? '' ) && '2026-11-18T09:42:00Z' === ( $p['properties']['yelp_masked_email_expires'] ?? '' ) );
check( "the masked address is NEVER used as the customer's email", ! isset( $p['properties']['email'] ) && 'ppg_lead_id' === ( $p['idProperty'] ?? '' ) );
check( 'Yelp answers sent under their HubSpot property names are stored', '2 storey' === ( $p['properties']['how_tall_is_your_home'] ?? '' ) && 'SL6 7NZ' === ( $p['properties']['in_what_location_do_you_need_the_service'] ?? '' ) );
$n1 = count( $GLOBALS['calls'] );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'name' => 'Zoe Zap', 'lead_id' => 'DxbqixfnYwVdh852eUy-kg', 'masked_email' => 'abc123@messaging.yelp.com' ) ) );
check( 'the Zap firing twice for the same Yelp lead is ignored', 'duplicate_ignored' === ( $res['status'] ?? '' ) || count( $GLOBALS['calls'] ) === $n1 );
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$zap2 = array( 'source' => 'yelp', 'name' => 'Mis Mapped', 'lead_id' => 'yelp:MisMappedLead0001', 'email' => 'xyz@messaging.yelp.com' );
ppg_lead_handle( new WP_REST_Request( $zap2 ) );
$p = $last();
check( 'if the Zap maps the masked address into "email" by mistake, it still goes to the masked field, not Email', ! isset( $p['properties']['email'] ) && 'xyz@messaging.yelp.com' === ( $p['properties']['yelp_masked_email'] ?? '' ) );
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'name' => 'Real Rita', 'email' => 'rita@example.org', 'lead_id' => 'RitaLead0000001' ) ) );
check( 'a real customer email sent by the Zap is used normally (matched on email)', 'email' === ( $last()['idProperty'] ?? '' ) && 'rita@example.org' === ( $last()['id'] ?? '' ) );
$GLOBALS['req_headers']['x-ppg-secret'] = 'wrong';
$perm = ppg_lead_authorise( new WP_REST_Request( $zap ) );
check( '/lead refuses a wrong secret (401)', is_wp_error( $perm ) && 401 === ( $perm->get_error_data()['status'] ?? 0 ) );
$GLOBALS['req_headers']['x-ppg-secret'] = 'unit-test-secret';
check( '/lead accepts the right secret', true === ppg_lead_authorise( new WP_REST_Request( $zap ) ) );

/* ------------------------------------------------------------------ late phone (Yelp "Phone Availability") */
echo "Late data for an existing lead (Yelp Phone Availability)\n";
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, '{"total":1,"results":[{"id":"900"}]}' ), array( 200, '{"id":"900"}' ) );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'update' => true, 'lead_id' => 'LateLead000000001', 'phone' => '07700 900777' ) ) );
$cc  = $GLOBALS['calls'];
check( 'a late phone is added to the existing contact (search by ppg_lead_id, then PATCH)', 'updated' === ( $res['status'] ?? '' ) && 2 === count( $cc ) && 'PATCH' === ( $cc[1][2] ?? '' ) && 'yelp:LateLead000000001' === ( $cc[0][1]['filterGroups'][0]['filters'][0]['value'] ?? '' ) );
check( 'only the phone is written: name and message are untouched, no new note, nothing created', array( 'phone' => '07700900777' ) === ( $cc[1][1]['properties'] ?? null ) && 0 === count( $notes() ) );
$GLOBALS['script'] = array( array( 200, '{"total":1,"results":[{"id":"900"}]}' ), array( 200, '{"id":"900"}' ) );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'update' => true, 'lead_id' => 'LateLead000000001', 'phone' => '07700 900888' ) ) );
check( 'a second update for the same lead is not blocked by the duplicate memory', 'updated' === ( $res['status'] ?? '' ) );
$GLOBALS['script'] = array( array( 200, '{"total":0,"results":[]}' ) );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'update' => true, 'lead_id' => 'NotThereYet0000001', 'phone' => '07700 900999' ) ) );
check( 'if the lead is not in HubSpot yet: 404 (retry later), and no contact is created', is_wp_error( $res ) && 404 === ( $res->get_error_data()['status'] ?? 0 ) );
$GLOBALS['calls'] = array(); unset( $GLOBALS['script'] );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'update' => true, 'phone' => '07700900000' ) ) );
check( 'an update without a lead_id is rejected (400)', is_wp_error( $res ) && 400 === ( $res->get_error_data()['status'] ?? 0 ) && 0 === count( $GLOBALS['calls'] ) );
$res = ppg_lead_handle( new WP_REST_Request( array( 'source' => 'yelp', 'update' => true, 'lead_id' => 'X1234567890' ) ) );
check( 'an update with nothing to update is rejected (422)', is_wp_error( $res ) && 422 === ( $res->get_error_data()['status'] ?? 0 ) );

/* ------------------------------------------------------------------ default Lead Status = New */
echo "Default Lead Status 'New'\n";
$status_calls = function () { return array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return 'PATCH' === ( $c[2] ?? '' ) && array( 'hs_lead_status' ) === array_keys( $c[1]['properties'] ?? array( 'x' => 1 ) ); } ) ); };
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
ppg_lead_process( 'yell', array( 'name' => 'Sam Status', 'email' => 'sam@example.org', 'message' => 'm', 'dedupe_key' => '<st-1@x>' ) );
$sc = $status_calls();
check( "a contact the plugin creates gets Lead Status 'New' (HubSpot value NEW)", 1 === count( $sc ) && 'NEW' === ( $sc[0][1]['properties']['hs_lead_status'] ?? '' ) && false !== strpos( $sc[0][0], '/contacts/1' ) );
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '55', 'new' => false ) ) ) ) ), array( 201, '{"id":"9"}' ) );
ppg_lead_process( 'yell', array( 'name' => 'Sam Status', 'email' => 'sam@example.org', 'message' => 'a second lead from a returning customer', 'dedupe_key' => '<st-2@x>' ) );
check( "a returning customer's contact is NOT reset to New (their status is left alone)", 0 === count( $status_calls() ) );
unset( $GLOBALS['script'] );
$GLOBALS['t'] = array(); $GLOBALS['calls'] = array();
$GLOBALS['script'] = array( array( 200, json_encode( array( 'results' => array( array( 'id' => '56', 'new' => true ) ) ) ) ), array( 400, '{"message":"bad option"}' ), array( 201, '{"id":"9"}' ) );
$r = ppg_lead_process( 'yell', array( 'name' => 'Opt Out', 'email' => 'opt@example.org', 'message' => 'm', 'dedupe_key' => '<st-3@x>' ) );
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
echo "Automatic running (WP-Cron)\n";
$hooked = function ( $type, $name ) { foreach ( $GLOBALS['hooks'] as $h ) { if ( $h[0] === $type && $h[1] === $name ) { return $h[2]; } } return null; };
check( "the cron hook 'ppg_poll_mailbox' runs ppg_poll_mailbox", 'ppg_poll_mailbox' === $hooked( 'action', 'ppg_poll_mailbox' ) );
$sched = $hooked( 'filter', 'cron_schedules' );
check( 'a 5-minute schedule is registered', is_callable( $sched ) && 300 === ( $sched( array() )['ppg_five_minutes']['interval'] ?? 0 ) );
$init = $hooked( 'action', 'init' );
$GLOBALS['already_scheduled'] = false;
$GLOBALS['scheduled'] = array();
$init();
check( 'on any page load the event is scheduled if it is missing', 1 === count( $GLOBALS['scheduled'] ) && 'ppg_five_minutes' === $GLOBALS['scheduled'][0][1] && 'ppg_poll_mailbox' === $GLOBALS['scheduled'][0][2] );
$GLOBALS['already_scheduled'] = 123456;
$GLOBALS['scheduled'] = array();
$init();
check( 'and not scheduled twice when it already exists', 0 === count( $GLOBALS['scheduled'] ) );
check( 'deactivating the plugin removes the scheduled event', is_callable( $hooked( 'deactivate', 'plugin' ) ) );
unset( $GLOBALS['o']['ppg_poll_lock'] );
check( 'run lock: the first run gets it', true === ppg_poll_lock_acquire() );
check( 'run lock: an overlapping run is refused', false === ppg_poll_lock_acquire() );
$GLOBALS['o']['ppg_poll_lock'] = time() - 3600;
check( 'run lock: a stale lock from a crashed run is taken over', true === ppg_poll_lock_acquire() );
delete_option( 'ppg_poll_lock' );
check( 'run lock is released after a run (even with import disabled)', ( ppg_poll_mailbox() || true ) && ! isset( $GLOBALS['o']['ppg_poll_lock'] ) );

/* ------------------------------------------------------------------ status page and run recording */
echo "Status page and run recording (how you check cron on staging)\n";
$GLOBALS['o'] = array();
ppg_poll_mailbox();
$st = $GLOBALS['o']['ppg_poll_status'] ?? array();
check( 'a cron run is recorded (here: no imap extension in the test environment)', 'no_imap' === ( $st['outcome'] ?? '' ) && ! empty( $st['started'] ) && ! empty( $st['finished'] ) );
check( 'the record carries the counters the status page shows', isset( $st['matched'], $st['considered'], $st['imported'], $st['duplicates'], $st['held'], $st['failed'] ) );
check( 'the run is also appended to the rolling log', 1 === count( $GLOBALS['o']['ppg_poll_log'] ?? array() ) );
for ( $i = 0; $i < 40; $i++ ) { ppg_poll_mailbox(); }
check( 'the log keeps only the latest 30 runs', 30 === count( $GLOBALS['o']['ppg_poll_log'] ) );
$GLOBALS['o']['ppg_poll_lock'] = time();
ppg_poll_mailbox();
check( 'an overlapping run is recorded as "locked" and does nothing', 'locked' === $GLOBALS['o']['ppg_poll_status']['outcome'] );
unset( $GLOBALS['o']['ppg_poll_lock'] );
$state = function ( $label ) { foreach ( ppg_status_checks() as $row ) { if ( $label === $row[0] ) { return $row[1]; } } return null; };
$GLOBALS['already_scheduled'] = time() + 120;
check( "check: 'PHP imap extension' is red when the extension is missing", 'bad' === $state( 'PHP imap extension' ) );
check( "check: 'Mailbox settings' is red when the IMAP settings are not set", 'bad' === $state( 'Mailbox settings' ) );
check( "check: 'HubSpot token' is green when set", 'ok' === $state( 'HubSpot token' ) );
check( "check: 'Zapier/lead secret' is red for a short/placeholder secret", 'bad' === $state( 'Zapier/lead secret' ) );
check( "check: 'Go-live' is amber while PPG_GO_LIVE is unset (importing off = safe)", 'warn' === $state( 'Go-live (PPG_GO_LIVE)' ) );
check( "check: 'Scheduled event' is green when the event is scheduled", 'ok' === $state( 'Scheduled event' ) );
$GLOBALS['already_scheduled'] = false;
check( "check: 'Scheduled event' is red when the event is missing", 'bad' === $state( 'Scheduled event' ) );
$GLOBALS['already_scheduled'] = time() - 3600;
check( "check: 'Scheduled event' is red when it is an hour overdue (cron not firing)", 'bad' === $state( 'Scheduled event' ) );
$GLOBALS['o']['ppg_poll_status'] = array( 'outcome' => 'ok', 'detail' => '', 'finished' => time() - 60, 'started' => time() - 62 );
check( "check: 'Last poll' is green when a run finished a minute ago", 'ok' === $state( 'Last poll' ) );
$GLOBALS['o']['ppg_poll_status']['finished'] = time() - 3600;
check( "check: 'Last poll' is red when the last run was an hour ago (cron is not firing)", 'bad' === $state( 'Last poll' ) );
$GLOBALS['o']['ppg_poll_status']['finished'] = time() - 60;
$GLOBALS['o']['ppg_poll_status']['outcome']  = 'connect_failed';
$GLOBALS['o']['ppg_poll_status']['detail']   = 'Login failed';
check( "check: 'Last poll' is amber when it ran but the mailbox login failed", 'warn' === $state( 'Last poll' ) );
unset( $GLOBALS['o']['ppg_poll_status'] );
check( "check: 'Last poll' is red when it has never run", 'bad' === $state( 'Last poll' ) );
$GLOBALS['already_scheduled'] = true;
check( 'ago helper: "never", seconds, minutes, hours, days', 'never' === ppg_status_ago( 0 ) && '30 seconds ago' === ppg_status_ago( 970, 1000 ) && '10 minutes ago' === ppg_status_ago( 400, 1000 ) && '3 hours ago' === ppg_status_ago( 1000 - 10800, 1000 ) && '2 days ago' === ppg_status_ago( 1000 - 172800, 1000 ) );
$GLOBALS['get_script'] = array( array( 200, '{"results":[]}' ) );
check( 'HubSpot test button: a 200 means the token works', true === ppg_status_test_hubspot()[0] );
$GLOBALS['get_script'] = array( array( 401, '{}' ) );
$tr = ppg_status_test_hubspot();
check( 'HubSpot test button: a 401 says the token is wrong or expired', false === $tr[0] && false !== strpos( $tr[1], '401' ) );
check( 'mailbox test button: explains the missing imap extension instead of crashing', false === ppg_status_test_mailbox()[0] );
check( "the page is registered under Tools > 'PPG Lead Status'", null !== ( function () { foreach ( $GLOBALS['hooks'] as $h ) { if ( 'action' === $h[0] && 'admin_menu' === $h[1] ) { return true; } } return null; } )() );

/* ------------------------------------------------------------------ Lead Sources (checkbox) */
echo "Lead Sources\n";
$GLOBALS['t'] = array(); $GLOBALS['source_calls'] = array(); $GLOBALS['existing_sources'] = null;
ppg_lead_process( 'yelp', array( 'name' => 'Sam Source', 'message' => 'm', 'dedupe_key' => '<src-1@x>' ) );
$sp = array_values( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) );
check( 'platform lead: Lead Sources gets the platform name (Yelp)', 1 === count( $sp ) && 'Yelp' === ( $sp[0][1]['properties']['lead_sources'] ?? '' ) );

$GLOBALS['source_calls'] = array(); $GLOBALS['existing_sources'] = 'Request a Quote;Yell';
ppg_lead_process( 'yell', array( 'name' => 'Sam Again', 'message' => 'm', 'dedupe_key' => '<src-2@x>' ) );
check( 'platform already ticked: nothing is sent', 0 === count( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) ) );
$GLOBALS['source_calls'] = array();
ppg_lead_process( 'checkatrade', array( 'name' => 'Sam Third', 'message' => 'm', 'dedupe_key' => '<src-3@x>' ) );
$sp = array_values( array_filter( $GLOBALS['source_calls'], function ( $c ) { return 'PATCH' === $c[2]; } ) );
check( 'returning contact: the new platform is appended, existing ticks kept', 'Request a Quote;Yell;Checkatrade' === ( $sp[0][1]['properties']['lead_sources'] ?? '' ) );

$GLOBALS['t'] = array(); $GLOBALS['gets'] = array();
$form = (object) array( 'title' => 'Make an Appointment' );
$opts = array( 200, json_encode( array( 'options' => array( array( 'value' => 'Request a Quote' ), array( 'value' => 'Make an Appointment' ), array( 'value' => 'Contact Form' ) ) ) ) );
$GLOBALS['get_script'] = array( $opts, array( 404, '{}' ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form, new contact: Lead Sources = the form name', 'Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array( array( 200, json_encode( array( 'properties' => array( 'lead_sources' => 'Contact Form;Yelp' ) ) ) ) ); // options now cached
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form, returning contact: form name appended to what is already ticked', 'Contact Form;Yelp;Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array( array( 200, json_encode( array( 'properties' => array( 'lead_sources' => 'Make an Appointment' ) ) ) ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'website form: form name already ticked is not duplicated', 'Make an Appointment' === ( $f['lead_sources'] ?? '' ) );
$GLOBALS['get_script'] = array();
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, (object) array( 'title' => 'Unknown Form' ) );
check( 'form name that is not an option of the property: not sent (HubSpot would reject the whole push)', ! isset( $f['lead_sources'] ) );
$GLOBALS['t'] = array(); $GLOBALS['get_script'] = array( array( 500, '{}' ) );
$f = ppg_website_lead_source( array( 'email' => 'w@example.org' ), null, null, $form );
check( 'property lookup fails: the form push is untouched', ! isset( $f['lead_sources'] ) );

/* ------------------------------------------------------------------ go-live */
echo "Go-live\n";
check( 'PPG_GO_LIVE unset => import disabled (0)', 0 === ppg_go_live_timestamp() );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
