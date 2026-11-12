<?php

namespace FluentFormPro\classes\Chat;

defined('ABSPATH') || die;

use FluentForm\App\Models\FormMeta;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;

/**
 *  Handling Chat Field Module.
 *
 * @since 5.1.5
 */
class ChatApi
{
    const DEFAULT_MODEL = 'gpt-5.6-luna';

    protected $url = 'https://api.openai.com/v1/chat/completions';
    protected $key;

    public function __construct($key = '_fluentform_openai_settings')
    {
        $this->key = $key;
    }

    /**
     * Maybe Extract code from a response that might contain markdown code blocks
     *
     * @param string $content The content to parse
     * @return string The extracted code or original content if no code block found
     */
    public function maybeExtractCodeFromResponse($content)
    {
        // Check if the content contains HTML code blocks
        if (preg_match('/```html\s*(.+?)\s*```/s', $content, $matches)) {
            // Return only the code part
            return trim($matches[1]);
        }

        // Also check for other code blocks
        if (preg_match('/```\w*\s*(.+?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * OpenAI model sent with every Chat Completions request.
     *
     * @param int|null $formId Form the request is for; null for verification and AI form building.
     * @return string
     */
    public function getModel($formId = null)
    {
        /**
         * Filters the OpenAI model used for verification, AI form building and ChatGPT feeds.
         *
         * @param string   $model
         * @param int|null $formId Form ID for ChatGPT feeds; null for verification and AI form building.
         */
        $model = apply_filters('fluentform/openai_model', self::DEFAULT_MODEL, $formId);

        if (!is_string($model) || '' === trim($model)) {
            return self::DEFAULT_MODEL;
        }

        return trim($model);
    }

    public function makeRequest($args = [], $token = '', $formId = null)
    {
        if (!$token) {
            $token = ArrayHelper::get(get_option($this->key), 'access_token');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ];

        $message = $args;
        if (!$message) {
            $message = [
                'role'    => 'system',
                'content' => 'You are a helpful assistant.',
            ];
        }

        $bodyArgs = [
            'model'    => $this->getModel($formId),
            'messages' => [$message],
        ];

        add_filter('http_request_timeout', function () {
            return 60;
        });

        $request = wp_remote_post($this->url, [
            'headers' => $headers,
            'body'    => wp_json_encode($bodyArgs),
        ]);

        if (did_filter('http_request_timeout')) {
            add_filter('http_request_timeout', function () {
                return 5;
            });
        }

        if (is_wp_error($request)) {
            $message = $request->get_error_message();
            return new \WP_Error(423, $message);
        }

        $body = json_decode(wp_remote_retrieve_body($request), true);
        $code = wp_remote_retrieve_response_code($request);

        if (200 !== $code) {
            $error = __('Something went wrong.', 'fluentformpro');
            if (isset($body['error']['message'])) {
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Dynamic string from API/config
                $error = __($body['error']['message'], 'fluentformpro');
            }
            return new \WP_Error(423, $error);
        }

        return $body;
    }

    public function isAuthenticated($token)
    {
        $result = $this->makeRequest([], $token);
        if (is_wp_error($result)) {
            return $result;
        }
        return isset($result['id']);
    }

    public function isApiEnabled()
    {
        $settings = get_option($this->key);
        if (!$settings || empty($settings['status'])) {
            $settings = [
                'access_token' => '',
                'status' => false,
            ];
        }
        return ArrayHelper::isTrue($settings, 'status');
    }
}
