<?php

// Copy OUTSIDE the web root and repository; chmod 600 on Linux.
// Never use the WHMCS application's own privileged DB account.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'whmcs_restored_backup',
    'username' => 'whmcs_select_only',
    'password' => '',
    'source_is_restored_backup' => false, // Set true only after verification.
    'select_only_account_confirmed' => false,
    'per_table_limit' => 3,
];
