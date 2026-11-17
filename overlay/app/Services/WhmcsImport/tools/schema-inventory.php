#!/usr/bin/env php
<?php

// Read-only SQL-text analysis. No PDO, application bootstrap or SQL execution.
use App\Services\WhmcsImport\HistoryCatalog;

require_once dirname(__DIR__).'/HistoryCatalog.php';
if (PHP_SAPI !== 'cli' || ! isset($argv[1])) {
    fwrite(STDERR, "Usage: php schema-inventory.php /private/whmcs-schema.sql\n");
    exit(2);
}
$sql = file_get_contents($argv[1]);
preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=([A-Za-z0-9_]+)/s', $sql, $tables, PREG_SET_ORDER);
$catalog = array_column(HistoryCatalog::definitions(), null, 'table');
$inventory = [];
foreach ($tables as $table) {
    preg_match_all('/^\s+`([^`]+)` (.+?)(?:,\r?$|\r?$)/m', $table[2], $fields, PREG_SET_ORDER);
    $columns = [];
    foreach ($fields as $field) {
        $columns[$field[1]] = [
            'definition' => $field[2],
            'handling' => in_array($field[1], $catalog[$table[1]]['fields'] ?? [], true)
                ? 'encrypted_read_only_history' : 'excluded_pending_review',
            'native_destination' => null,
        ];
    }
    $inventory[$table[1]] = [
        'engine' => $table[3],
        'handling' => isset($catalog[$table[1]]) ? 'partial_history_supported' : 'unsupported_pending_review',
        'columns' => $columns,
        'expected_relationships_not_foreign_keys' => $catalog[$table[1]]['references'] ?? [],
    ];
}
echo json_encode(['table_count' => count($inventory), 'tables' => $inventory,
    'blockers' => HistoryCatalog::blockers()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
