<?php

namespace FluentForm\App\Modules\AgentReady;

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;
use FluentForm\Framework\Support\Str;

/**
 * Emits the WebMCP declarative form attributes so agentic browsers can
 * synthesize a tool from the rendered form instead of guessing at the DOM.
 *
 * Lives outside App\Modules\MCP deliberately: that module is a server-side MCP
 * endpoint whose whole surface is gated behind WP auth and PermissionGate. This
 * is public markup rendered for anonymous visitors and shares no code, transport
 * or trust boundary with it — only an unfortunate acronym.
 *
 * @see https://github.com/webmachinelearning/webmcp/blob/main/declarative-api-explainer.md
 */
class WebMCPModule
{
    /**
     * App instance
     *
     * @var \FluentForm\Framework\Foundation\Application
     */
    protected $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register()
    {
        $this->app->addFilter('fluentform/html_attributes', [$this, 'addFormToolAttributes'], 10, 2);
        $this->app->addFilter('fluentform/before_render_item', [$this, 'addFieldToolAttributes'], 10, 2);
    }

    public function addFormToolAttributes($attributes, $form)
    {
        if (!$this->isEnabledForForm($form)) {
            return $attributes;
        }

        // Spec attribute names are all-lowercase and unprefixed; not a typo.
        $attributes['toolname'] = $this->getToolName($form);
        $attributes['tooldescription'] = $this->getToolDescription($form);

        return $attributes;
    }

    public function addFieldToolAttributes($item, $form)
    {
        if (!$this->isEnabledForForm($form)) {
            return $item;
        }

        // Composite fields (name, address) render each sub-field's own
        // attributes, so anything set on the parent lands on a wrapper element
        // instead of an input. Describe the sub-fields themselves.
        if (ArrayHelper::get($item, 'fields')) {
            foreach ($item['fields'] as $key => $subField) {
                $item['fields'][$key] = $this->addParamAttributes($subField);
            }

            return $item;
        }

        return $this->addParamAttributes($item);
    }

    protected function addParamAttributes($field)
    {
        if (!ArrayHelper::get($field, 'attributes.name')) {
            return $field;
        }

        $description = $this->getParamDescription($field);

        if ($description) {
            $field['attributes']['toolparamdescription'] = $description;
        }

        // No native `required`: it forces `novalidate`, which drops the browser's email/number/step checks.
        return $field;
    }

    protected function isEnabledForForm($form)
    {
        $settings = Helper::getFormMeta($form->id, 'formSettings', []);

        // TransferService imports write formSettings verbatim, so 'yes'/'no'
        // reach this gate as strings — and rest_sanitize_boolean(),
        // wp_validate_boolean() and ArrayHelper::isTrue() all read 'no' as true.
        $isEnabled = (bool) Str::toBool(ArrayHelper::get($settings, 'webmcp.status'));

        return apply_filters('fluentform/webmcp_enabled', $isEnabled, $form);
    }

    protected function getToolName($form)
    {
        $settings = Helper::getFormMeta($form->id, 'formSettings', []);
        $name = ArrayHelper::get($settings, 'webmcp.tool_name');

        return sanitize_title($name ? $name : $form->title);
    }

    protected function getToolDescription($form)
    {
        $settings = Helper::getFormMeta($form->id, 'formSettings', []);

        $description = ArrayHelper::get($settings, 'webmcp.tool_description');

        return $description ? $description : $form->title;
    }

    protected function getParamDescription($item)
    {
        $candidates = [
            ArrayHelper::get($item, 'settings.help_message'),
            ArrayHelper::get($item, 'settings.label'),
            ArrayHelper::get($item, 'attributes.placeholder'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return wp_strip_all_tags($candidate);
            }
        }

        return '';
    }
}
