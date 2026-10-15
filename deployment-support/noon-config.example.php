<?php
// Copy to /home/CPANEL_USER/noon-config.php, OUTSIDE public_html.
return [
    'origin' => 'https://nooninteractive.com',
    'dsn' => 'mysql:host=localhost;port=3306;dbname=CPANEL_USER_noon;charset=utf8mb4',
    'db_user' => 'CPANEL_USER_noon',
    'db_password' => 'CHANGE_ME_DATABASE_PASSWORD',
    // Replace with 64 random hex characters: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => 'CHANGE_ME_RANDOM_SECRET_AT_LEAST_32_CHARACTERS',
];
