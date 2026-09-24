<?php
/**
 * Docker config for WoWSimpleRegistration + AzerothCore.
 * Every value comes from environment variables set in docker-compose.yml / .env.
 * Based on application/config/config.php.sample (script_version 2.0.2).
 **/

$env = static function (string $key, $default = '') {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};
$bool = static fn(string $key, bool $default) => filter_var($env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);

// Basic
$config['baseurl'] = rtrim($env('BASE_URL', 'http://localhost:8080'), '/');
$config['page_title'] = $env('SITE_TITLE', 'Simple Register');
$config['contact_email'] = $env('CONTACT_EMAIL', ''); // shown on the contact page; hidden when empty
$config['language'] = $env('LANGUAGE', 'english');
$config['supported_langs'] = [
    'english' => 'English',
    'persian' => 'Persian',
    'italian' => 'Italian',
    'chinese-simplified' => 'Chinese Simplified',
    'chinese-traditional' => 'Chinese Traditional',
    'swedish' => 'Swedish',
    'french' => 'French',
    'german' => 'German',
    'spanish' => 'Spanish',
    'korean' => 'Korean',
    'russian' => 'Russian',
    'portugues' => 'Portuguese',
];
$config['debug_mode'] = $bool('DEBUG_MODE', false);

// Server info shown on the "How to connect" page
$config['realmlist'] = $env('REALMLIST', ''); // empty: use realm.conf's address, else 127.0.0.1
$config['patch_location'] = $env('PATCH_URL', '');
$config['game_version'] = '3.3.5a (12340)';
$config['expansion'] = '2'; // WotLK

// AzerothCore, SRP6 (salt + verifier written directly to acore_auth.account)
$config['server_core'] = 1;
$config['battlenet_support'] = false;
$config['srp6_support'] = true;
$config['srp6_version'] = 2;

// Features
$config['disable_top_players'] = $bool('DISABLE_TOP_PLAYERS', false);
$config['disable_online_players'] = $bool('DISABLE_ONLINE_PLAYERS', false);
$config['disable_changepassword'] = $bool('DISABLE_CHANGEPASSWORD', false);
$config['multiple_email_use'] = $bool('MULTIPLE_EMAIL_USE', false);
$config['template'] = $env('TEMPLATE', 'icecrown'); // light, advance, icecrown, kaelthas, battleforazeroth, legion, legion-advance

// SMTP - only needed for "forgot password" emails
$config['smtp_host'] = $env('SMTP_HOST', '');
$config['smtp_port'] = (int) $env('SMTP_PORT', 587);
$config['smtp_auth'] = true;
$config['smtp_user'] = $env('SMTP_USER', '');
$config['smtp_pass'] = $env('SMTP_PASS', '');
$config['smtp_secure'] = $env('SMTP_SECURE', 'tls');
$config['smtp_mail'] = $env('SMTP_FROM', 'no-reply@example.com');

// Vote system off: enabling it makes the app ALTER acore_auth.account and create a votes table
$config['vote_system'] = false;
$config['vote_sites'] = [];

// Captcha: 0 = built-in image captcha (no external account needed)
$config['captcha_type'] = (int) $env('CAPTCHA_TYPE', 0);
$config['captcha_key'] = $env('CAPTCHA_KEY', '');
$config['captcha_secret'] = $env('CAPTCHA_SECRET', '');
$config['captcha_language'] = 'en';

// SOAP not used: accounts are created directly in the auth database
$config['soap_for_register'] = false;
$config['soap_host'] = 'ac-worldserver';
$config['soap_port'] = '7878';
$config['soap_uri'] = 'urn:AC';
$config['soap_style'] = 'SOAP_RPC';
$config['soap_username'] = '';
$config['soap_password'] = '';
$config['soap_ca_command'] = 'account create {USERNAME} {PASSWORD}';
$config['2fa_support'] = false;
$config['soap_2d_command'] = 'account set 2fa {USERNAME} off';
$config['soap_2e_command'] = 'account set 2fa {USERNAME} {SECRET}';

// Databases (containers on ac-network)
$config['db_auth_host'] = $env('DB_HOST', 'ac-database');
$config['db_auth_port'] = $env('DB_PORT', '3306');
$config['db_auth_user'] = $env('DB_USER', 'wow_register');
$config['db_auth_pass'] = $env('DB_PASS', '');
$config['db_auth_dbname'] = $env('DB_AUTH_NAME', 'acore_auth');

$config['realmlists'] = [
    '1' => [
        'realmid' => (int) $env('REALM_ID', 1),
        'realmname' => $env('REALM_NAME', ''), // empty: use realm.conf's GameRealmName, else AzerothCore
        'db_host' => $config['db_auth_host'],
        'db_port' => $config['db_auth_port'],
        'db_user' => $config['db_auth_user'],
        'db_pass' => $config['db_auth_pass'],
        'db_name' => $env('DB_CHARACTERS_NAME', 'acore_characters'),
    ],
];

// mod-realm-config: the module's output directory is mounted read-only at /realm (see docker-compose.yml)
$config['realm_conf_dir'] = '/var/www/html/realm';
$config['realm_conf_base_url'] = $config['baseurl'] . '/realm';
$config['realm_key'] = $env('REALM_KEY', ''); // empty: newest *.realm.conf in the folder

$config['script_version'] = '2.0.2';
