<?php

namespace FluentFormPro\FluentUpdater;

defined('ABSPATH') or die;

class LicenseController extends \FluentForm\App\Http\Controllers\Controller
{
    public function status()
    {
        $verify = rest_sanitize_boolean($this->request->get('verify', false));

        return $this->respond($this->settings()->provideLicenseStatus($verify));
    }

    public function activate()
    {
        $licenseKey = $this->request->get('license_key', '');
        $licenseKey = is_scalar($licenseKey) ? sanitize_text_field((string)$licenseKey) : '';

        if (!$licenseKey) {
            return $this->sendError([
                'message' => __('Please enter a License key.', 'fluentformpro'),
            ], 422);
        }

        return $this->respond(
            $this->settings()->activateLicense($licenseKey),
            422
        );
    }

    public function deactivate()
    {
        return $this->respond($this->settings()->deactivateLicense());
    }

    /**
     * @return LicenseSettings
     */
    private function settings()
    {
        return LicenseSettings::getInstance();
    }

    /**
     * @param mixed $result
     * @param int $errorCode
     * @return mixed
     */
    private function respond($result, $errorCode = 502)
    {
        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message(),
            ], $errorCode);
        }

        if (!is_array($result)) {
            return $this->sendError([
                'message' => __('The License service is unavailable.', 'fluentformpro'),
            ], 503);
        }

        return $this->sendSuccess($this->removeCredentials($result));
    }

    /**
     * Keep raw credentials out of REST responses even if future code adds them
     * to a nested response value before it reaches this boundary.
     *
     * @param array $data
     * @return array
     */
    private function removeCredentials(array $data)
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['license_key', 'activation_hash', 'hash'], true)) {
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->removeCredentials($value);
            }
        }

        return $data;
    }
}
