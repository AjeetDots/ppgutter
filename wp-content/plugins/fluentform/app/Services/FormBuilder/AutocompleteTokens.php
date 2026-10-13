<?php

namespace FluentForm\App\Services\FormBuilder;

defined('ABSPATH') || die;

/**
 * Decides the autocomplete attribute a field renders.
 *
 * A token tells the browser what a field is for, so it can autofill it. Only
 * the names in the HTML spec's autofill table work; anything else is ignored
 * by browsers, so an unrecognised value is dropped rather than rendered.
 *
 * Every rendered field passes through normalizeAttributes(), which asks:
 *
 *   1. Did the form owner pick a value in the editor? Theirs wins.
 *   2. If not, is the input type unambiguous? Only email, url and tel are.
 *   3. Is the result still a real token? Checked here because a form saved by
 *      an admin skips the save-side check (see Updater::sanitizeFields).
 *
 * 'none' is the editor's "render no attribute" choice. It is stored so the
 * choice survives a save, and dropped at step 3 so no attribute is emitted.
 *
 * @see https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#autofill
 */
class AutocompleteTokens
{
    // The only input types whose purpose is unambiguous.
    protected static $typeDefaults = [
        'email' => 'email',
        'url'   => 'url',
        'tel'   => 'tel',
    ];

    protected static $nameDefaults = [
        'first_name'  => 'given-name',
        'middle_name' => 'additional-name',
        'last_name'   => 'family-name',
    ];

    // Address autofill is opt-in ('on' on the parent field): a form can carry
    // two address blocks and the Places widget binds address_line_1, so these
    // would be wrong more often than right as a default.
    protected static $addressDefaults = [
        'address_line_1' => 'address-line1',
        'address_line_2' => 'address-line2',
        'city'           => 'address-level2',
        'state'          => 'address-level1',
        'zip'            => 'postal-code',
        'country'        => 'country',
    ];

    // cc-*, transaction-*, one-time-code and current-password are excluded:
    // card details live in the gateway iframe, so those tokens could only ever
    // autofill a secret into a plain input stored with the submission.
    protected static $allowed = [
        'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name',
        'honorific-suffix', 'nickname',
        'email', 'tel', 'tel-country-code', 'tel-national', 'tel-area-code',
        'tel-local', 'tel-local-prefix', 'tel-local-suffix', 'tel-extension',
        'impp', 'url', 'photo',
        'street-address', 'address-line1', 'address-line2', 'address-line3',
        'address-level4', 'address-level3', 'address-level2', 'address-level1',
        'country', 'country-name', 'postal-code',
        'organization', 'organization-title',
        'username', 'new-password',
        'bday', 'bday-day', 'bday-month', 'bday-year', 'sex', 'language',
    ];

    // Values that say whether to autofill without naming what the field is for,
    // so they apply to a whole field or composite block: the spec's on/off, plus
    // our own 'none' sentinel meaning "render no attribute at all".
    protected static $wholeField = ['on', 'off', 'none'];

    // The single boundary every rendered field passes through.
    public static function normalizeAttributes($attributes)
    {
        // A non-array can arrive here: attributes pass through public filters
        // (fluentform/before_render_item) before reaching buildAttributes().
        if (!is_array($attributes)) {
            return $attributes;
        }

        if (!array_key_exists('autocomplete', $attributes)) {
            return $attributes;
        }

        $token = static::sanitize($attributes['autocomplete']);

        if ('' === $token) {
            $type = strtolower((string) (isset($attributes['type']) ? $attributes['type'] : ''));
            $token = static::resolveDefault(
                isset(static::$typeDefaults[$type]) ? static::$typeDefaults[$type] : '',
                ['input_type' => $type, 'sub_field' => '']
            );
        }

        if ('' === $token || 'none' === $token) {
            unset($attributes['autocomplete']);

            return $attributes;
        }

        $attributes['autocomplete'] = $token;

        return $attributes;
    }

    // One control covers every sub-field, so only a whole-field choice applies --
    // and it overrides anything authored on a child.
    public static function forSubField($name, $parentChoice = '', $childChoice = '')
    {
        // Both entry points must normalise identically: the save-side pass is
        // skipped for unfiltered_html users, so ' OFF ' can arrive here raw.
        $parentHasKey = null !== $parentChoice;
        $parentChoice = static::sanitize($parentChoice);
        $childChoice = static::sanitize($childChoice);

        if (in_array($parentChoice, static::$wholeField, true)) {
            return 'on' === $parentChoice && isset(static::$addressDefaults[$name])
                ? static::resolveDefault(static::$addressDefaults[$name], ['input_type' => '', 'sub_field' => $name])
                : $parentChoice;
        }

        if ($childChoice) {
            return $childChoice;
        }

        return isset(static::$addressDefaults[$name]) || !$parentHasKey
            ? ''
            : static::resolveDefault(isset(static::$nameDefaults[$name]) ? static::$nameDefaults[$name] : '', ['input_type' => '', 'sub_field' => $name]);
    }

    // A site can rewrite any default through the filter, so whatever comes back
    // is validated before it is allowed near the markup.
    protected static function resolveDefault($default, $context)
    {
        /**
         * @param string $token   Derived token for a field left on Automatic, '' when none applies.
         * @param array  $context ['input_type' => e.g. 'email', 'sub_field' => e.g. 'first_name'].
         */
        return static::sanitize(apply_filters('fluentform/autocomplete_token', $default, $context));
    }

    public static function sanitize($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', strtolower(trim((string) $value)));

        if (in_array($value, static::$wholeField, true)) {
            return $value;
        }

        $parts = explode(' ', $value);
        $fieldName = array_pop($parts);

        // Filterable so a site can re-admit a spec token excluded here on policy
        // (current-password, one-time-code) without patching the class.
        $allowed = (array) apply_filters('fluentform/autocomplete_allowed_tokens', static::$allowed);

        if (!in_array($fieldName, $allowed, true)) {
            return '';
        }

        $prefix = static::validPrefix($parts, $fieldName);

        return null === $prefix ? '' : trim($prefix . ' ' . $fieldName);
    }

    // Optional section, then an address scope, then a contact scope — in that
    // order, and a contact scope only on a contact field. Null means invalid.
    protected static function validPrefix(array $parts, $fieldName)
    {
        $prefix = [];

        if ($parts && preg_match('/^section-[a-z0-9_-]{1,32}$/', $parts[0])) {
            $prefix[] = array_shift($parts);
        }

        if ($parts && in_array($parts[0], ['shipping', 'billing'], true)) {
            $prefix[] = array_shift($parts);
        }

        if ($parts && in_array($parts[0], ['home', 'work', 'mobile', 'fax', 'pager'], true)) {
            if (0 !== strpos($fieldName, 'tel') && !in_array($fieldName, ['email', 'impp'], true)) {
                return null;
            }

            $prefix[] = array_shift($parts);
        }

        return $parts ? null : implode(' ', $prefix);
    }

    public static function editorOptions()
    {
        $options = static::baseEditorOptions();

        foreach (static::$allowed as $token) {
            $options[] = ['value' => $token, 'label' => $token];
        }

        return $options;
    }

    // Address adds an opt-in choice the other composites do not need.
    public static function addressEditorOptions()
    {
        $options = static::baseEditorOptions();

        array_splice($options, 1, 0, [
            ['value' => 'on', 'label' => __('On (autofill this address)', 'fluentform')],
        ]);

        return $options;
    }

    // The choices every control starts with, and all a composite can offer --
    // see forSubField(). Note this is not $wholeField: the editor shows
    // 'Automatic' (an empty stored value) and does not offer a bare 'on'.
    public static function baseEditorOptions()
    {
        return [
            ['value' => '', 'label' => __('Automatic', 'fluentform')],
            ['value' => 'off', 'label' => 'off'],
            ['value' => 'none', 'label' => __('None', 'fluentform')],
        ];
    }
}
