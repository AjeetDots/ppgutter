<?php
namespace FluentFormPro\Components;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Models\Form;
use FluentForm\App\Services\FormBuilder\Components\BaseComponent;
use FluentForm\Framework\Helpers\ArrayHelper;

class ActionHook extends BaseComponent
{
	/**
	 * Compile and echo the html element
	 * @param  array $data [element data]
	 * @param  stdClass $form [Form Object]
	 * @return void
	 */
	public function compile($data, $form)
	{
        $elementName = $data['element'];
        
        $data = apply_filters_deprecated(
            'fluentform_rendering_field_data_' . $elementName,
            [
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_data_' . $elementName,
            'Use fluentform/rendering_field_data_' . $elementName . ' instead of fluentform_rendering_field_data_' . $elementName
        );

        $data = apply_filters('fluentform/rendering_field_data_' . $elementName, $data, $form);

        $hasConditions = $this->hasConditions($data) ? 'has-conditions ' : '';
		
		$data['attributes']['class'] = trim(
			$this->getDefaultContainerClass()
			.' '. @$data['attributes']['class']
			.' '. $hasConditions
		);

		$atts = $this->buildAttributes(
			\FluentForm\Framework\Helpers\ArrayHelper::except($data['attributes'], 'name')
		);

		ob_start();
		echo "<div {$atts}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped via buildAttributes
		// SECURITY (PRO-36): hook_name is free text set in the builder and is dispatched on every
		// anonymous render, so it must not be able to invoke an arbitrary registered action. Fire only:
		//   - the dedicated 'fluentform/action_hook/' sub-namespace reserved for this field, OR
		//   - a NON-namespaced custom hook a TRUSTED user approved when saving (hook_name_approved,
		//     stamped server-side by gateHookApproval(); a manager cannot forge it), OR
		//   - an exact name trusted site code adds via the filter, OR
		//   - a LEGACY field saved before approval-gating existed (no hook_name_approved key at all):
		//     grandfather it so upgrading the plugin never silently drops an existing custom hook
		//     (existing-user flow). Any re-save runs it through gateHookApproval(), replacing this
		//     transitional state with an explicit true/false — a manager cannot reach it on a new save.
		// The plugin's own hooks (fluentform/submission_inserted, payment lifecycle, etc.) are NEVER
		// fired here (even legacy/approved): invoking one on a public render with only $form can fatal
		// on PHP 8 arg count or run lifecycle logic out of context.
		$hookName = (string) ArrayHelper::get($data, 'settings.hook_name');

		$allowedHooks = (array) apply_filters('fluentform/action_hook_field_allowed_hooks', [], $hookName, $form);

		$settings = (array) ArrayHelper::get($data, 'settings', []);
		$isApproved = !empty($settings['hook_name_approved']);
		$isLegacyUngated = !array_key_exists('hook_name_approved', $settings);
		$isReservedNamespace = strpos($hookName, 'fluentform/action_hook/') === 0;
		// The internal plugin namespace = fluentform/* except the reserved action-hook sub-namespace.
		$isInternalNamespace = strpos($hookName, 'fluentform/') === 0 && !$isReservedNamespace;

		// COMPAT (PRO-36 review #243): the filter is the documented escape hatch and can only be
		// written in site PHP naming an exact hook — a form manager cannot reach it. So it is
		// authoritative and overrides the internal-namespace block too. Without this, a field saved
		// before upgrade with a `fluentform/`-prefixed custom name (e.g. fluentform/my_thing, outside
		// the reserved sub-namespace) is dead permanently: re-saving cannot revive it, and the one
		// mechanism advertised for re-enabling a hook could not actually re-enable it.
		$isFilterAllowed = in_array($hookName, $allowedHooks, true);

		$shouldFire = $hookName
			&& (
				$isFilterAllowed
				|| (!$isInternalNamespace && ($isReservedNamespace || $isApproved || $isLegacyUngated))
			);

		if ($shouldFire) {
			do_action($hookName, $form);
		}
		echo"</div>";
		$html = ob_get_clean();
        
        $html = apply_filters_deprecated(
            'fluentform_rendering_field_html_' . $elementName,
            [
                $html,
                $data,
                $form
            ],
            FLUENTFORM_FRAMEWORK_UPGRADE,
            'fluentform/rendering_field_html_' . $elementName,
            'Use fluentform/rendering_field_html_' . $elementName . ' instead of fluentform_rendering_field_html_'.$elementName
        );

        echo apply_filters('fluentform/rendering_field_html_' . $elementName, $html, $data, $form); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form field HTML rendering
	}

	/**
	 * SECURITY (PRO-36): stamp each Action Hook field with a server-authoritative approval flag on
	 * save. A non-namespaced custom hook (which fires on public render) is approved only when the
	 * saving user is trusted (admin / full-access) — or was already approved on this form, so a
	 * later edit by a lower-privilege manager does not disable a legit hook. The client-supplied
	 * flag is always overwritten here, so a manager cannot self-approve. Registered on
	 * fluentform/form_fields_update; receives and returns the fields JSON string.
	 *
	 * @param string $formFields
	 * @param int    $formId
	 * @return string
	 */
	public static function gateHookApproval($formFields, $formId)
	{
		if (empty($formFields) || !is_string($formFields)) {
			return $formFields;
		}

		$decoded = json_decode($formFields, true);
		if (!is_array($decoded) || empty($decoded['fields']) || !is_array($decoded['fields'])) {
			return $formFields;
		}

		// Registering an arbitrary action that fires on anonymous render is close to arbitrary-code
		// trust, so gate it high. Filterable for sites with a bespoke role model.
		$canApprove = current_user_can('manage_options') || current_user_can('fluentform_full_access');
		$canApprove = (bool) apply_filters('fluentform/action_hook_field_trusted_user', $canApprove, $formId);

		$previouslyApproved = self::collectApprovedHookNames($formId);

		$decoded['fields'] = self::stampHookApproval($decoded['fields'], $canApprove, $previouslyApproved);

		return wp_json_encode($decoded);
	}

	private static function stampHookApproval($fields, $canApprove, $previouslyApproved)
	{
		foreach ($fields as $i => $field) {
			if (!is_array($field)) {
				continue;
			}

			if ('action_hook' === ArrayHelper::get($field, 'element')) {
				$hookName = trim((string) ArrayHelper::get($field, 'settings.hook_name'));
				$approved = false;
				if (strpos($hookName, 'fluentform/action_hook/') === 0) {
					$approved = true; // dedicated reserved namespace — always render-safe
				} elseif (strpos($hookName, 'fluentform/') === 0) {
					$approved = false; // internal plugin namespace — never fire from render
				} elseif ('' !== $hookName && ($canApprove || in_array($hookName, $previouslyApproved, true))) {
					$approved = true;
				}
				$fields[$i]['settings']['hook_name_approved'] = $approved;
			}

			if (!empty($field['columns']) && is_array($field['columns'])) {
				foreach ($field['columns'] as $c => $column) {
					if (!empty($column['fields']) && is_array($column['fields'])) {
						$fields[$i]['columns'][$c]['fields'] = self::stampHookApproval($column['fields'], $canApprove, $previouslyApproved);
					}
				}
			}

			if (!empty($field['fields']) && is_array($field['fields'])) {
				$fields[$i]['fields'] = self::stampHookApproval($field['fields'], $canApprove, $previouslyApproved);
			}
		}

		return $fields;
	}

	private static function collectApprovedHookNames($formId)
	{
		$approved = [];
		$form = Form::find($formId);
		if (!$form || empty($form->form_fields)) {
			return $approved;
		}
		$decoded = json_decode($form->form_fields, true);
		if (!is_array($decoded) || empty($decoded['fields']) || !is_array($decoded['fields'])) {
			return $approved;
		}
		self::walkApprovedHookNames($decoded['fields'], $approved);
		return $approved;
	}

	private static function walkApprovedHookNames($fields, &$approved)
	{
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			if ('action_hook' === ArrayHelper::get($field, 'element')) {
				$settings = (array) ArrayHelper::get($field, 'settings', []);
				// Already-approved OR legacy (no flag yet) hooks are grandfathered, so a later edit —
				// even by a lower-privilege manager — does not disable a hook the form already used.
				// Internal fluentform/* names are never re-approved: the save gate blocks them first.
				$grandfathered = !array_key_exists('hook_name_approved', $settings)
					|| true === ($settings['hook_name_approved'] ?? null);
				if ($grandfathered) {
					$name = trim((string) ArrayHelper::get($settings, 'hook_name'));
					if ('' !== $name) {
						$approved[] = $name;
					}
				}
			}
			if (!empty($field['columns']) && is_array($field['columns'])) {
				foreach ($field['columns'] as $column) {
					if (!empty($column['fields']) && is_array($column['fields'])) {
						self::walkApprovedHookNames($column['fields'], $approved);
					}
				}
			}
			if (!empty($field['fields']) && is_array($field['fields'])) {
				self::walkApprovedHookNames($field['fields'], $approved);
			}
		}
	}
}
