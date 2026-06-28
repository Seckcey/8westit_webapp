<?php
/**
 * 8 West IT RMM — configuration sample.
 *
 * COPY this file to config.php and fill in real values.
 * Keep config.php OUT of the web root and OUT of git (.gitignore covers it).
 *
 * On HostGator: create the MySQL DB + user in cPanel > MySQL Databases,
 * then paste those credentials below.
 */

return [
    // --- Database (from cPanel > MySQL Databases) ---
    'db' => [
        'host' => 'localhost',
        'port' => '',                      // leave blank for default 3306
        'name' => 'youracct_8westrmm',     // cPanel prefixes with your account name
        'user' => 'youracct_rmm',
        'pass' => 'CHANGE_ME_STRONG_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // --- App ---
    // Random 64-char secret used to sign sessions / tokens. Generate with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGE_ME_64_HEX_CHARS',

    // Public base URL of the portal (used in the agent installer config).
    'base_url' => 'https://support.8westit.com',

    // Require HTTPS for all portal + API traffic (leave true in production).
    'force_https' => true,

    // --- RustDesk self-hosted relay (your $5 VPS) ---
    // Agents are configured to use this relay; the portal deep-links into it.
    'rustdesk' => [
        'relay_host' => 'relay.8westit.com', // VPS hostname or IP running hbbs/hbbr
        'relay_key'  => 'PASTE_RUSTDESK_PUBLIC_KEY', // contents of id_ed25519.pub from the VPS
    ],
];
