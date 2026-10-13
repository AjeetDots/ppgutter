<?php

namespace FluentForm\App\Services\WPAsync;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Form\FormDataParser;
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\Framework\Foundation\Application;
use FluentForm\App\Services\Integrations\GlobalNotificationManager;

class FluentFormAsyncRequest
{
    /**
     * $prefix The prefix for the identifier
     * @var string
     */
    protected $table = 'ff_scheduled_actions';

    /**
     * $action The action for the identifier
     * @var string
     */
    protected $action = 'fluentform_background_process';

    /**
     * $actions Actions to be fired when an async request is sent
     * @var array
     */
    protected $actions = array();

    /**
     * $app Instance of Application/Framework
     * @var \FluentForm\Framework\Foundation\Application
     */
    protected $app = null;

    static $formCache = [];
    static $entryCache = [];
    static $submissionCache = [];

    /**
     * Construct the Object
     * @param \FluentForm\Framework\Foundation\Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function queue($feed)
    {
        return wpFluent()->table($this->table)->insertGetId($feed);
    }

    public function queueFeeds($feeds)
    {
        return wpFluent()->table($this->table)
            ->insert($feeds);
    }

    public function dispatchAjax($data = [])
    {
        /* This hook is deprecated and will be removed soon */
        $sslVerify = apply_filters('fluentform_https_local_ssl_verify', false);
        
        $args = array(
            'timeout' => 0.1,
            'blocking' => false,
            'body' => $data,
            'cookies' => $_COOKIE,
            'sslverify' => apply_filters('fluentform/https_local_ssl_verify', $sslVerify),
        );

        $queryArgs = array(
            'action' => $this->action,
            'nonce' => wp_create_nonce($this->action),
        );

        $url = add_query_arg($queryArgs, Helper::getAjaxUrl());
        wp_remote_post(esc_url_raw($url), $args);
    }

    public function handleBackgroundCall()
    {
        $nonce = sanitize_text_field(wpFluentForm('request')->get('nonce', ''));
        if (!wp_verify_nonce($nonce, $this->action)) {
            die('invalid');
        }

        $originId = wpFluentForm('request')->get('origin_id', false);

        $this->processActions($originId);
        echo 'success';
        die();
    }

    public function processActions($originId = false)
    {
        $actionFeedQuery = wpFluent()->table($this->table)
                            ->where('status', 'pending');
        if($originId) {
            $actionFeedQuery = $actionFeedQuery->where('origin_id', $originId);
        }

        $actionFeeds = $actionFeedQuery->get();

        if(count($actionFeeds) === 0) {
            return;
        }

        $formCache = [];
        $submissionCache = [];
        $entryCache = [];
        $formDataCache = [];
        $feedCache = $this->loadFeedRows($actionFeeds);

        foreach ($actionFeeds as $actionFeed) {
            $action = $actionFeed->action;
            $feed = Helper::safeUnserialize($actionFeed->data);
            $feed['scheduled_action_id'] = $actionFeed->id;
            if(isset($submissionCache[$actionFeed->origin_id])) {
                $submission = $submissionCache[$actionFeed->origin_id];
            } else {
                $submission = wpFluent()->table('fluentform_submissions')->find($actionFeed->origin_id);
                if (!$submission) {
                    $this->abandonStaleRow($actionFeed, 'Skipped: the submission or form no longer exists');
                    continue;
                }
                $submissionCache[$submission->id] = $submission;
            }
            if(isset($formCache[$submission->form_id])) {
                $form = $formCache[$submission->form_id];
            } else {
                $form = wpFluent()->table('fluentform_forms')->find($submission->form_id);
                if (!$form) {
                    $this->abandonStaleRow($actionFeed, 'Skipped: the submission or form no longer exists');
                    continue;
                }
                $formCache[$form->id] = $form;
            }

            if (!isset($formDataCache[$submission->id])) {
                $formDataCache[$submission->id] = json_decode($submission->response, true);
            }
            $formData = $formDataCache[$submission->id];

            if (!$this->feedStillEnabled($actionFeed, $feedCache, $formData, $submission->id)) {
                $this->abandonStaleRow($actionFeed, 'Skipped: the feed was disabled or deleted after queueing');
                continue;
            }

            if(isset($entryCache[$submission->id])) {
                $entry = $entryCache[$submission->id];
            } else {
                $entry = $this->getEntry($submission, $form);
                $entryCache[$submission->id] = $entry;
            }

            // Same atomic claim as process(); this path is a public nopriv ajax endpoint.
            $claimed = wpFluent()->table($this->table)
                ->where('id', $actionFeed->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    'status' => 'processing',
                    'retry_count' => $actionFeed->retry_count + 1,
                    'updated_at' => current_time('mysql')
                ]);

            if (!$claimed) {
                continue;
            }

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name for async request
            do_action($action, $feed, $formData, $entry, $form);
        }

        if($originId && !empty($form) && !empty($submission)) {
            /* This hook is deprecated and will be removed soon */
            do_action('fluentform_global_notify_completed', $submission->id, $form);
            
            do_action('fluentform/global_notify_completed', $submission->id, $form);
        }
    }

    private function getEntry($submission, $form)
    {
        $formInputs = FormFieldsParser::getEntryInputs($form, ['admin_label', 'raw']);
        return FormDataParser::parseFormEntry($submission, $form, $formInputs);
    }

    public function process($queue)
    {
        if (is_numeric($queue)) {
            $queue = wpFluent()->table($this->table)->where('status', 'pending')->find($queue);
        }

        if (!$queue || empty($queue->action)) {
            return;
        }

        $action = $queue->action;
        $feed = Helper::safeUnserialize($queue->data);
        $feed['scheduled_action_id'] = $queue->id;

        if (isset(static::$submissionCache[$queue->origin_id])) {
            $submission = static::$submissionCache[$queue->origin_id];
        } else {
            $submission = wpFluent()->table('fluentform_submissions')->find($queue->origin_id);
            if (!$submission) {
                $this->abandonStaleRow($queue, 'Skipped: the submission or form no longer exists');
                return;
            }
            static::$submissionCache[$submission->id] = $submission;
        }

        if (isset(static::$formCache[$submission->form_id])) {
            $form = static::$formCache[$submission->form_id];
        } else {
            $form = wpFluent()->table('fluentform_forms')->find($submission->form_id);
            if (!$form) {
                $this->abandonStaleRow($queue, 'Skipped: the submission or form no longer exists');
                return;
            }
            static::$formCache[$form->id] = $form;
        }

        $formData = json_decode($submission->response, true);

        if (!$this->feedStillEnabled($queue, $this->loadFeedRows([$queue]), $formData, $submission->id)) {
            if ($this->abandonStaleRow($queue, 'Skipped: the feed was disabled or deleted after queueing')) {
                $this->maybeFinished($submission->id, $form);
            }
            return;
        }

        if (isset(static::$entryCache[$submission->id])) {
            $entry = static::$entryCache[$submission->id];
        } else {
            $entry = $this->getEntry($submission, $form);
            
            static::$entryCache[$submission->id] = $entry;
        }

        // Atomic claim: the cron passes a row object, so status is never re-read. Must admit
        // 'failed' or retries die, and must always change a column - wpdb reports CHANGED rows.
        $claimed = wpFluent()->table($this->table)
            ->where('id', $queue->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'processing',
                'retry_count' => $queue->retry_count + 1,
                'updated_at' => current_time('mysql')
            ]);

        if (!$claimed) {
            return;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Dynamic hook name for async request
        do_action($action, $feed, $formData, $entry, $form);

        $this->maybeFinished($submission->id, $form);
    }

    // 'skipped' is outside every consumer's selection: cron retries only 'failed', Pro's
    // failed-integration email reads 'failed'/'error', maybeFinished() counts 'pending'.
    // Same status predicate as the claim, so a row another worker owns is left alone.
    private function abandonStaleRow($row, $note)
    {
        return (bool) wpFluent()->table($this->table)
            ->where('id', $row->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status'     => 'skipped',
                'note'       => $note,
                'updated_at' => current_time('mysql'),
            ]);
    }

    // One query for every distinct feed in the batch, keyed by form_meta id.
    private function loadFeedRows($actionFeeds)
    {
        $feedIds = [];
        foreach ($actionFeeds as $actionFeed) {
            if ($actionFeed->feed_id) {
                $feedIds[(int) $actionFeed->feed_id] = true;
            }
        }
        if (!$feedIds) {
            return [];
        }

        $rows = wpFluent()->table('fluentform_form_meta')->whereIn('id', array_keys($feedIds))->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        return $byId;
    }

    // The row carries a snapshot of the feed taken at submission time; re-run the producer's
    // own enabled + condition check against the live form_meta row before dispatching it.
    private function feedStillEnabled($row, $feedRows, $formData, $submissionId)
    {
        $feedId = (int) $row->feed_id;
        if (!$feedId) {
            return true;
        }

        $meta = $feedRows[$feedId] ?? null;
        if (!$meta) {
            return false;
        }

        $manager = new GlobalNotificationManager($this->app);

        return (bool) $manager->getEnabledFeeds([$meta], $formData, $submissionId);
    }

    public function maybeFinished($originId, $form)
    {
        $pendingFeeds = wpFluent()->table($this->table)->where([
            'status'    => 'pending',
            'origin_id' => $originId
        ])->get();

        if (count($pendingFeeds) === 0) {
            do_action('fluentform/global_notify_completed', $originId, $form);
        }
    }
}
