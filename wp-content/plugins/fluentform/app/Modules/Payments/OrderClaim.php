<?php

namespace FluentForm\App\Modules\Payments;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Self-service recovery of guest orders. A customer who registered with the same email a
 * guest purchase used can add those past orders to their account from the payments page.
 * Linking requires proof of inbox control (a one-time, account-bound, 24h link), so
 * registration alone never links orders.
 */
class OrderClaim
{
    const CLAIM_TOKEN_META = '_ff_order_claim_token';
    const CLAIM_EMAIL_META = '_ff_order_claim_email';
    const CLAIM_ISSUED_META = '_ff_order_claim_issued';

    public function init()
    {
        add_action('template_redirect', array($this, 'handleOrderClaimRequest'));
        add_action('template_redirect', array($this, 'handleOrderClaimSendRequest'));
        add_filter('do_shortcode_tag', array($this, 'maybePrependOrderClaimNotice'), 10, 2);
    }

    /**
     * Prepend the order-claim notice to the [fluentform_payments] output. Hooking
     * do_shortcode_tag (not the shortcode callback) covers both the free and the Pro
     * renderer of that shortcode from one place, without touching the Pro codebase.
     */
    public function maybePrependOrderClaimNotice($output, $tag)
    {
        if ($tag !== 'fluentform_payments' || !get_current_user_id()) {
            return $output;
        }
        return $this->orderClaimNotice(get_current_user_id()) . $output;
    }

    private function orderClaimNotice($userId)
    {
        $status = isset($_GET['ff_claim_status']) ? sanitize_key($_GET['ff_claim_status']) : '';
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return '';
        }
        $email = '<strong>' . esc_html($user->user_email) . '</strong>';

        // Post/redirect/get outcome of the "Add my past orders" button.
        $outcomes = array(
            'sent'      => sprintf(esc_html__('We\'ve emailed a secure link to %s. Open it while signed in to this account to add your past orders — check your spam folder if it is not there.', 'fluentform'), $email),
            'throttled' => esc_html__('A secure link was just emailed to you. Please check your inbox before requesting another.', 'fluentform'),
            'failed'    => esc_html__('We could not send the confirmation email. Please try again, or contact the site.', 'fluentform'),
        );
        if (isset($outcomes[$status])) {
            // On failure, offer the form again so the customer can retry (issueOrderClaim
            // cleared the token, so the retry is not throttled).
            return $this->renderClaimNotice($outcomes[$status], $status === 'failed', $status === 'failed');
        }

        if (!$this->unclaimedOrdersForEmail($user->user_email)['submission_ids']) {
            return '';
        }
        $offer = sprintf(esc_html__('You have past orders placed as a guest with %s. Add them to this account and we\'ll email you a secure link to confirm.', 'fluentform'), $email);
        return $this->renderClaimNotice($offer, false, true);
    }

    private function renderClaimNotice($message, $isError, $showForm)
    {
        wp_enqueue_style('fluentform_transactions', fluentFormMix('css/fluentform_transactions.css'), array(), FLUENTFORM_VERSION);
        $html = PaymentHelper::loadView('order_claim_notice', array(
            'message'   => $message,
            'is_error'  => $isError,
            'show_form' => $showForm,
        ));
        return apply_filters('fluentform/order_claim_notice_html', $html, $message, $isError);
    }

    /**
     * Handle the "Add my past orders" button: verify the request, mail a fresh claim link to
     * the signed-in account's own address, then redirect back with a status (post/redirect/get).
     */
    public function handleOrderClaimSendRequest()
    {
        if (empty($_POST['ff_order_claim_send']) || !is_user_logged_in()) {
            return;
        }
        $nonce = isset($_POST['ff_order_claim_nonce']) ? sanitize_text_field(wp_unslash($_POST['ff_order_claim_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'ff_order_claim_send')) {
            return;
        }
        $status = $this->issueOrderClaim(get_current_user_id());
        wp_safe_redirect(add_query_arg('ff_claim_status', $status, remove_query_arg('ff_claim_status')));
        exit;
    }

    /**
     * Guest orders — payment submissions with no owner yet — that carry a transaction whose
     * payer_email matches $email. Ownership is authoritative on the submission, so an order
     * already linked (e.g. by the registering feed in a combined checkout+register) is excluded.
     */
    private function unclaimedOrdersForEmail($email)
    {
        $transactions = \FluentForm\App\Models\Transaction::select(['id', 'submission_id'])
            ->where('payer_email', $email)
            ->get();

        $txIdsBySubmission = array();
        foreach ($transactions as $transaction) {
            if (!$transaction->submission_id) {
                continue;
            }
            $txIdsBySubmission[$transaction->submission_id][] = $transaction->id;
        }
        if (!$txIdsBySubmission) {
            return array('submission_ids' => array(), 'transaction_ids' => array());
        }

        $unowned = \FluentForm\App\Models\Submission::select(['id'])
            ->whereIn('id', array_keys($txIdsBySubmission))
            ->where(function ($query) {
                $query->whereNull('user_id')->orWhere('user_id', '');
            })
            ->get();

        $submissionIds = array();
        $transactionIds = array();
        foreach ($unowned as $submission) {
            $submissionIds[] = $submission->id;
            foreach ($txIdsBySubmission[$submission->id] as $txId) {
                $transactionIds[] = $txId;
            }
        }

        return array('submission_ids' => $submissionIds, 'transaction_ids' => $transactionIds);
    }

    /**
     * Mail a one-time claim link for the account's unclaimed guest orders to the account's own
     * address. Returns 'none' (nothing to claim), 'throttled' (a fresh link was mailed moments
     * ago), 'failed' (mail error) or 'sent'. Links nothing itself: only clicking the mailed link
     * (which proves control of the inbox) links the orders — see handleOrderClaimRequest().
     */
    public function issueOrderClaim($userId)
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return 'none';
        }
        $email = $user->user_email;

        $orders = $this->unclaimedOrdersForEmail($email);
        if (!$orders['submission_ids']) {
            return 'none';
        }

        $issuedAt = (int) get_user_meta($userId, self::CLAIM_ISSUED_META, true);
        $throttle = (int) apply_filters('fluentform/order_claim_resend_throttle', MINUTE_IN_SECONDS);
        if ($issuedAt && (time() - $issuedAt) < $throttle) {
            return 'throttled'; // a live link was just mailed — don't resend or rotate it yet
        }

        $token = wp_generate_password(40, false);
        update_user_meta($userId, self::CLAIM_TOKEN_META, wp_hash($token));
        update_user_meta($userId, self::CLAIM_EMAIL_META, $email); // bind the token to the inbox it is mailed to
        update_user_meta($userId, self::CLAIM_ISSUED_META, time()); // for the 24h expiry check at claim time

        $url = add_query_arg(
            array('ff_order_claim' => $token, 'ff_claim_uid' => $userId),
            home_url('/')
        );
        $siteName = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(__('Add your past orders to your %s account', 'fluentform'), $siteName);
        $subject = apply_filters('fluentform/order_claim_email_subject', $subject, $userId, $url);
        $body = apply_filters('fluentform/order_claim_email_body', $this->renderOrderClaimEmail($url, $siteName), $url, $userId, $siteName);
        $headers = apply_filters('fluentform/order_claim_email_headers', array('Content-Type: text/html; charset=UTF-8'), $userId);
        $sent = wp_mail($email, $subject, $body, $headers);

        if (!$sent) {
            $this->clearOrderClaim($userId); // don't leave a live token for a link that was never delivered
            return 'failed';
        }

        return 'sent';
    }

    private function renderOrderClaimEmail($url, $siteName)
    {
        return (string) wpFluentForm('view')->make('email.order_claim', array(
            'url'       => $url,
            'site_name' => $siteName,
        ));
    }

    /**
     * Front-end handler for the claim link mailed above. The token in the URL proves the
     * clicker controls the email, so the orders of that email are linked to the account.
     */
    public function handleOrderClaimRequest()
    {
        if (empty($_GET['ff_order_claim']) || empty($_GET['ff_claim_uid'])) {
            return;
        }
        $token = sanitize_text_field(wp_unslash($_GET['ff_order_claim']));
        $userId = (int) $_GET['ff_claim_uid'];

        // The link only works for the account that requested it. A logged-out visitor is
        // sent to sign in and back; a different signed-in account is refused. This stops a
        // victim who opens an attacker's link from linking their own orders to the attacker.
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg(
                array('ff_order_claim' => $token, 'ff_claim_uid' => $userId),
                home_url('/')
            )));
            exit;
        }
        if (get_current_user_id() !== $userId) {
            $ok = false;
            $message = __('This link only works for the account that requested it. Sign in to that account and open the link again.', 'fluentform');
        } else {
            $ok = $this->claimOrdersWithToken($userId, $token);
            $message = $this->claimResultMessage($ok);
        }

        // Pro renders a branded landing page and exits; free falls back to the page below.
        do_action('fluentform/render_order_claim_result', $ok, $message);

        $title = $ok ? __('Orders linked', 'fluentform') : __('Link not valid', 'fluentform');
        $heading = $ok ? __('You\'re all set', 'fluentform') : __('This link can\'t be used', 'fluentform');
        $body = '<h1 style="font-size:20px;margin:0 0 10px;color:#111827;">' . esc_html($heading) . '</h1>'
            . '<p style="font-size:15px;line-height:1.6;color:#374151;margin:0;">' . esc_html($message) . '</p>';

        wp_die(
            $body,
            esc_html($title),
            array('response' => $ok ? 200 : 403, 'back_link' => true)
        );
    }

    public function claimResultMessage($ok)
    {
        if ($ok) {
            $message = __('Your past orders have been added to your account and now appear on your payments page.', 'fluentform');
        } else {
            $message = __('This order-claim link is invalid or has already been used. If you still need your past orders linked, please contact the site.', 'fluentform');
        }
        return apply_filters('fluentform/order_claim_result_message', $message, $ok);
    }

    /**
     * Link the still-unclaimed orders of the account's email, but only for a valid, unused
     * token, redeemed by the signed-in account it was issued to, within 24h. Single-use: the
     * token is consumed on a successful link.
     */
    public function claimOrdersWithToken($userId, $token)
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }
        $stored = get_user_meta($userId, self::CLAIM_TOKEN_META, true);
        if (!$stored || !$token) {
            return false;
        }
        if (!hash_equals($stored, wp_hash($token))) {
            return false;
        }

        // The token only proves control of the inbox it was mailed to. If the account email
        // changed since issuance, that proof no longer matches this account — reject it.
        $issuedFor = get_user_meta($userId, self::CLAIM_EMAIL_META, true);
        if (!$issuedFor || $issuedFor !== $user->user_email) {
            return false;
        }

        // The link is bound to the account that requested it: only that signed-in account
        // may redeem it, so a victim who opens an attacker's link cannot hand over their orders.
        if (get_current_user_id() !== (int) $userId) {
            return false;
        }

        // Single-use and high-entropy already; also expire after 24h as defence-in-depth.
        $issued = (int) get_user_meta($userId, self::CLAIM_ISSUED_META, true);
        if (!$issued || (time() - $issued) > DAY_IN_SECONDS) {
            return false;
        }

        $orders = $this->unclaimedOrdersForEmail($user->user_email);
        if (!$orders['submission_ids']) {
            return false; // nothing unclaimed to link (e.g. already claimed) — don't burn the token
        }

        \FluentForm\App\Models\Submission::whereIn('id', $orders['submission_ids'])
            ->where(function ($query) {
                $query->whereNull('user_id')->orWhere('user_id', '');
            })
            ->update(array('user_id' => $userId, 'updated_at' => current_time('mysql')));

        \FluentForm\App\Models\Transaction::whereIn('id', $orders['transaction_ids'])
            ->where(function ($query) {
                $query->whereNull('user_id')->orWhere('user_id', '');
            })
            ->update(array('user_id' => $userId, 'updated_at' => current_time('mysql')));

        $this->clearOrderClaim($userId);

        return true;
    }

    private function clearOrderClaim($userId)
    {
        delete_user_meta($userId, self::CLAIM_TOKEN_META);
        delete_user_meta($userId, self::CLAIM_EMAIL_META);
        delete_user_meta($userId, self::CLAIM_ISSUED_META);
    }
}
