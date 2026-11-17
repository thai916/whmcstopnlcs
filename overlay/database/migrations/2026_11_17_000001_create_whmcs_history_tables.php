<?php

// Keep the original migration as the single schema definition used by the
// standalone SQLite harness and Laravel. This does not execute an import.
return require __DIR__.'/../../app/Services/WhmcsImport/database/2026_11_17_000001_create_whmcs_history_tables.php';
