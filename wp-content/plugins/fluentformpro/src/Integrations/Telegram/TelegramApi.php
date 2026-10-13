<?php

namespace FluentFormPro\Integrations\Telegram;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class TelegramApi
{
    private $token = '';
    private $chatId = '';
    private $parseMode = 'none';

    private $apiBase = 'https://api.telegram.org/bot';

    public function __construct($token = '', $chatId = '')
    {
        $this->token = $token;
        $this->chatId = $chatId;
    }

    public function setChatId($chatId)
    {
        $this->chatId = $chatId;
        return $this;
    }

    public function setToken($token)
    {
        $this->token = $token;
        return $this;
    }

    public function setParseMode($mode)
    {
        $this->parseMode = $mode;
        return $this;
    }

    public function sendMessage($message, $parseMode = '')
    {
        if (!$message) {
            return new \WP_Error(300, 'Message is required', []);
        }

        if (!$this->token) {
            return new \WP_Error(300, 'Token is required', []);
        }

        if (!$parseMode) {
            $parseMode = $this->parseMode;
        }

        if ($parseMode == 'none') {
            $message = $this->clearText($message);
        }

        return $this->sendRequest('sendMessage', [
            'chat_id'    => $this->chatId,
            'parse_mode' => $parseMode,
            'text'       => $message
        ]);
    }

    public function getMe()
    {
        return $this->sendRequest('getMe', []);
    }

    private function getBaseUrl()
    {
        return $this->apiBase . $this->token . '/';
    }

    private function clearText($html)
    {
        // Convert HTML line breaks to newlines
        $text = str_replace(['<br>'], "\n", $html);
        
        // Remove HTML tags but preserve newlines
        $text = wp_strip_all_tags($text);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        return $text;
    }

    public function sendRequest($endPoint, $args = [])
    {
        if(!$this->token) {
            return new \WP_Error(300, 'Token is required', []);
        }

        $url = $this->getBaseUrl() . $endPoint;

        $postData = http_build_query($args);

        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- Telegram API requires direct cURL
        $ch = curl_init();
        $optArray = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_TIMEOUT        => 30,
            // SECURITY (PRO-20): verify the Telegram API TLS certificate. Disabling verification
            // exposed the bot token (in the URL) and message contents to a network-position
            // attacker able to present any certificate. api.telegram.org has a valid public cert.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        );
        curl_setopt_array($ch, $optArray);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        // phpcs:enable

        if ($curlError) {
            return new \WP_Error(300, 'CURL Error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            $errorDetails = '';
            if ($result) {
                $decodedResult = json_decode($result, true);
                if ($decodedResult && isset($decodedResult['description'])) {
                    $errorDetails = ' - ' . $decodedResult['description'];
                }
            }
            // SECURITY (PRO-18): $url embeds the bot token (api.telegram.org/bot<TOKEN>/...) and
            // this WP_Error becomes the ff_scheduled_actions.note that is readable at
            // entries_viewer level — leaking a settings-tier secret two privilege tiers down.
            // Redact the token and drop the raw request body from the error message.
            $safeUrl = $this->token ? str_replace($this->token, '***', $url) : $url;
            return new \WP_Error($httpCode, 'HTTP Error: ' . $httpCode . $errorDetails . ' | URL: ' . $safeUrl);
        }

        $result = \json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(300, 'Invalid JSON response from Telegram API: ' . json_last_error_msg());
        }

        if (isset($result['ok'])) {
            if(!empty($result['ok'])) {
                return $result;
            }
            $errorCode = isset($result['error_code']) ? $result['error_code'] : 400;
            $description = isset($result['description']) ? $result['description'] : 'Unknown error';
            return new \WP_Error($errorCode, $description, $result);
        }

        return new \WP_Error(300, __('Unknown API error from Telegram', 'fluentformpro'), $result);
    }

}