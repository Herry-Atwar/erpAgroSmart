<?php
/**
 * Language Loader
 * Loads the correct language file based on the logged-in user's preferred_language
 * stored in $_SESSION['preferred_language'].
 *
 * Usage:
 *   require_once __DIR__ . '/lang.php';
 *   echo __('dashboard');        // outputs translated string
 *   echo __('user_management');
 */

if (!function_exists('__')) {

    /**
     * Return the translated string for the given key.
     * Falls back to the key itself when no translation is found.
     */
    function __($key) {
        global $lang;
        return $lang[$key] ?? $key;
    }

}

/**
 * Load language strings into the global $lang array.
 * Supported values: 'en' (English), 'id' (Indonesian).
 * Defaults to 'en' when the session value is absent or unrecognised.
 */
$_lang_code = $_SESSION['preferred_language'] ?? 'en';
if (!in_array($_lang_code, ['en', 'id'], true)) {
    $_lang_code = 'en';
}

$_lang_file = __DIR__ . '/../lang/' . $_lang_code . '.php';
$lang = file_exists($_lang_file) ? require $_lang_file : require __DIR__ . '/../lang/en.php';
