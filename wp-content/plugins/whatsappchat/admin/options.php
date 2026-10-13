<?php
/**
 * Whatsapp Chat Options Page
 */
add_action('admin_menu', 'whatsappchat_add_admin_menu');
add_action('admin_init', 'whatsappchat_settings_init');

function whatsappchat_add_admin_menu() {
    add_options_page(
        'Whatsapp Chat',
        'Whatsapp Chat',
        'manage_options',
        'whatsapp_chat',
        'whatsappchat_options_page'
    );
}

function whatsappchat_settings_init() {
register_setting('plugin_page', 'whatsappchat_settings');

	add_settings_section(
        'whatsappchat_plugin_page_section',
        __('Your section description', 'whatsappchat'),
        'whatsappchat_settings_section_callback',
        'plugin_page'
    );

	add_settings_field(
        'whatsappchat_phone_number',
        __('Active Whatsapp Number', 'whatsappchat'),
        'whatsappchat_phone_number_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

	add_settings_field(
        'whatsappchat_avatar_url',
        __('Avatar URL', 'whatsappchat'),
        'whatsappchat_avatar_url_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

	add_settings_field(
        'whatsappchat_company_name',
        __('Company Name', 'whatsappchat'),
        'whatsappchat_company_name_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

	add_settings_field(
        'whatsappchat_status',
        __('Status/tagline', 'whatsappchat'),
        'whatsappchat_status_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

	add_settings_field(
        'whatsappchat_init_text',
        __('Initial message text', 'whatsappchat'),
        'whatsappchat_init_text_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

	add_settings_field(
        'whatsappchat_message_text',
        __('Widget ready message text', 'whatsappchat'),
        'whatsappchat_message_text_render',
        'plugin_page',
        'whatsappchat_plugin_page_section'
    );

    // Register validation callback for phone number field
    register_setting(
        'plugin_page',
        'whatsappchat_settings',
        'whatsappchat_phone_number_validation'
    );
}

function whatsappchat_phone_number_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <input id="whatsappchat_phone_number" type='text' name='whatsappchat_settings[whatsappchat_phone_number]' value='<?= $options['whatsappchat_phone_number'] ?? ''; ?>' placeholder="e.g. +447786364040" style="width:100%;max-width:500px">
    <?php
        $errors = get_settings_errors('whatsappchat_settings');
        if (isset($errors)) foreach ($errors as $error) if (array_search('invalid_phone_number', $error)) $has_error = true;
        if ($has_error ?? false) printf('<div style="font-size:small;padding-left:8px;color:red">%s</div>', __('Must be a valid format for Whatsapp, e.g. +447786364040', 'whatsappchat'));
    ?>
    <?php
}

function whatsappchat_avatar_url_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <input type='text' name='whatsappchat_settings[whatsappchat_avatar_url]' value='<?= $options['whatsappchat_avatar_url'] ?? ''; ?>' placeholder="paste the url to a square/round image" style="width:100%;max-width:500px">
    <?php
}

function whatsappchat_company_name_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <input type='text' name='whatsappchat_settings[whatsappchat_company_name]' value='<?= $options['whatsappchat_company_name'] ?? ''; ?>' placeholder="Your company's name" style="width:100%;max-width:500px">
    <?php
}


function whatsappchat_status_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <input type='text' name='whatsappchat_settings[whatsappchat_status]' value='<?= $options['whatsappchat_status'] ?? ''; ?>' placeholder="We typically respond in a few minutes" style="width:100%;max-width:500px">
    <?php
}


function whatsappchat_init_text_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <textarea rows='5' name='whatsappchat_settings[whatsappchat_init_text]' placeholder="The initial test when the chat expands" style="width:100%;max-width:500px"><?= $options['whatsappchat_init_text'] ?? ''; ?></textarea>
    <?php
}


function whatsappchat_message_text_render() {
    $options = get_option('whatsappchat_settings');
    ?>
    <textarea rows='10' name='whatsappchat_settings[whatsappchat_message_text]' style="width:100%;max-width:500px"><?= $options['whatsappchat_message_text'] ?? ''; ?></textarea>
    <?php
}

// Validation callback function for phone number
function whatsappchat_phone_number_validation($options) {
    $number = $options['whatsappchat_phone_number'] ?? '';
    // Validate for `tel:` href format: starts with +, has digits, no spaces or special chars
    if (!preg_match('/^\+[\d]+$/', $number)) {
        add_settings_error(
            'whatsappchat_settings',
            'invalid_phone_number',
            __('Please enter a valid phone number formatted for a Whatsapp link'),
        );
        unset($options['whatsappchat_phone_number']); // Remove invalid number
    }

    // Additional validation checks (optional)
    return $options; // Return the validated value
}

function whatsappchat_settings_section_callback() {
    //echo __('The section description', 'whatsappchat');
}

function whatsappchat_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>Whatsapp Chat</h2>
        <?php
        settings_fields('plugin_page');
        do_settings_sections('plugin_page');
        submit_button();
        ?>
    </form>
    <?php
}
