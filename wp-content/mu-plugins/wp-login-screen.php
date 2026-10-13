<?php
/**
 * @wordpress-plugin
 * Plugin Name:       WP Login Screen
 * Plugin URI:        https://metapixels.co.uk/
 * Description:       Customization of the default WordPress login screen.
 * Version:           1.0.0
 * Author:            Darko Dimitrovski
 * Author URI:        https://metapixels.co.uk/
 * Network:           False
 */

//add_action('login_enqueue_scripts', function() { //add scripts & styles here }, 1);

add_filter('login_headerurl', function () {
    return home_url();
}, 1);

add_filter('login_headertext', function () {
    return '<img src="/wp-content/uploads/2023/12/pp-gutters-logo-new.svg" alt="PP Gutters" />';
}, 1);

add_action('login_footer', function() {
    ?>
<style type="text/css">
    :root {
        --primary: #54b66c;
        --secondary: #fff;
        --light: #fff;
        --dark: #333;
        --success: #23c40e;
        --border: #dedede;
        --font-size: 14px;
        --font-size-small: 14px;
    }
    
    body.login { position: relative; display: flex; flex-direction: column; font-size: var(--font-size); background: var(--primary); }
    
    .login #login { padding-top: 0 !important; }

    .login #login h1 { margin-bottom: 20px; background-color: var(--light); padding: 1rem 2rem; border-radius: 5px; }
    .login #login h1 a { position: relative; width: 100%; height: 130px; margin-bottom: 0; background-image: none; }
    .login #login h1 img { position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; object-fit: contain; }

    .login #login #loginform,
    .login #login #lostpasswordform,
    .login .admin-email-confirm-form { background: var(--light); border-width: 0; border-radius: 5px; }

    .login #login #loginform label { font-size: var(--font-size-small); color: var(--dark); }
    .login #login #loginform input:not([type="submit"]),
    .login #login #lostpasswordform input:not([type="submit"]) { font-size: var(--font-size); color: var(--dark); background-color: var(--light); border-color: var(--primary); border-radius: 5px; }
    .login #login #loginform input:not([type="submit"]):focus,
    .login #login #lostpasswordform input:not([type="submit"]):focus { outline: 1px solid var(--primary) !important; }
    .login #login #loginform input[type="checkbox"],
    .login #login #lostpasswordform input[type="checkbox"] { width: 1.5rem; height: 1.5rem; font-size: 1.5rem; border: 1px solid var(--primary); }
    .login #login #loginform input:checked::before,
    .login #login #lostpasswordform input:checked::before { display: none !important; }
    .login #login #loginform input[type="checkbox"]:checked,
    .login #login #lostpasswordform input[type="checkbox"]:checked { background: radial-gradient(var(--secondary) 30%, var(--primary) 33%); }

    .login #login #loginform .button-primary,
    .login #login #lostpasswordform .button-primary,
    .login .admin-email-confirm-form .button-primary { font-size: var(--font-size); color: var(--light); background-color: var(--primary); border-color: var(--primary); text-transform: uppercase; text-decoration: none; text-shadow: none; box-shadow: none; padding: 0.15rem 1.5rem; border-radius: 5px; }
    .login #login #loginform .button-primary:hover,
    .login #login #lostpasswordform .button-primary:hover,
    .login .admin-email-confirm-form .button-primary:hover { color: var(--dark); background-color: var(--light); border-color: var(--primary); cursor: pointer; }
    .login #login #loginform .button-primary:active,
    .login #login #loginform .button-primary:active,
    .login .admin-email-confirm-form .button-primary:active { color: var(--light); background-color: var(--primary); }

    .admin-email__actions-primary > .button-large { padding: 0.15rem 1.5rem !important; border-radius: 5px; font-size: 14px; text-transform: uppercase; background-color: var(--light); }
    .admin-email__actions-primary > .button-large:hover { background-color: var(--dark); color: var(--light); }

    .login #login #backtoblog a,
    .login #login #nav a { color: var(--secondary); text-decoration: none; }
    .login #login #backtoblog a:hover,
    .login #login #nav a:hover,
    .login #login h1 a:hover { color: var(--secondary); }
    .login #login #backtoblog a:hover,
    .login #login #nav a:hover { text-decoration: underline; }
    .login #login #loginform .privacy-policy-page-link { color: var(--light); text-align: center; }

    .wp-core-ui .button-primary { color: var(--light); background: var(--primary); border-color: var(--primary); box-shadow: none; text-decoration: none; text-shadow: none; }
    
    .login .notice,
    .login #lostpasswordform { border-radius: 5px; }
    .login #backtoblog, 
    .login #nav { padding: 0; } 
    .login .privacy-policy-page-link,
    .login .language-switcher { display: none; }
</style>
    <?php
}, 0);