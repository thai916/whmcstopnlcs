<?php

// Exercise the discoverable migration up/down methods on a SECOND explicitly
// in-memory SQLite connection. No application DB or migration history is used.
use Illuminate\Support\Facades\Schema;

$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'migration-validation');
$migrationSchema = $db->getConnection('migration-validation')->getSchemaBuilder();
Schema::swap($migrationSchema);
$applied = [];
foreach (glob(dirname(__DIR__, 2).'/database/migrations/2026_11_17_*.php') as $migrationFile) {
    $entry = require $migrationFile;
    $entry->up();
    $applied[] = $entry;
}
$archiveTables = ['whmcs_history_runs', 'whmcs_history_records', 'whmcs_history_issues', 'whmcs_history_client_links', 'whmcs_history_owners', 'whmcs_history_attachments'];
check(array_all($archiveTables, fn ($table) => $migrationSchema->hasTable($table))
    && $migrationSchema->hasColumn('whmcs_history_runs', 'report'), 'Laravel-discoverable migration up methods install complete archive schema');
foreach (array_reverse($applied) as $entry) {
    $entry->down();
}
check(array_all($archiveTables, fn ($table) => ! $migrationSchema->hasTable($table)), 'archive migrations roll back cleanly on isolated SQLite');
