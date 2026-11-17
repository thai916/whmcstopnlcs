#!/usr/bin/env php
<?php

/**
 * Supporting diagnostic only. No Laravel boot, destination connection or writes
 * to WHMCS. Run against a consistent RESTORED backup using SELECT-only grants.
 */

use App\Services\WhmcsImport\HistoryCatalog;
use App\Services\WhmcsImport\SampleSanitizer;
use App\Services\WhmcsImport\WhmcsConnector;

require_once dirname(__DIR__).'/HistorySource.php';
require_once dirname(__DIR__).'/WhmcsConnector.php';
require_once dirname(__DIR__).'/HistoryCatalog.php';
require_once dirname(__DIR__).'/SampleSanitizer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['config:', 'output:', 'help']);
if (isset($options['help']) || ! isset($options['config'])) {
    fwrite(STDOUT, "Usage: php whmcs-sample-export.php --config=/secure/whmcs-sample.php [--output=/private/migration-evidence/samples]\n");
    exit(isset($options['help']) ? 0 : 2);
}

try {
    $configPath = realpath($options['config']);
    if (! $configPath || ! is_file($configPath)) {
        throw new RuntimeException('Configuration file not found.');
    }
    $config = require $configPath;
    if (! is_array($config) || ($config['source_is_restored_backup'] ?? false) !== true
        || ($config['select_only_account_confirmed'] ?? false) !== true) {
        throw new RuntimeException('Confirm restored-backup source and SELECT-only account in protected config.');
    }
    $connector = new WhmcsConnector($config);
    $connector->requireReadOnlySession();
    $tables = $connector->tables();
    $definitions = array_column(HistoryCatalog::definitions(), null, 'table');
    $fields = [];
    $types = [];
    $sample = [];
    $report = ['schema_only_categories' => [], 'missing_tables' => [], 'excluded_columns' => [], 'truncated_child_tables' => [],
        'missing_parent_count' => 0, 'closure_limit_reached' => false,
        'blockers' => HistoryCatalog::blockers(),
        'notes' => [
            'Original dates, numeric IDs, and exact DECIMAL values are retained.',
            'Free text and business references are pseudonymized. Manual review required before sharing.',
            'Opaque mod_invoicedata and email message_data are NOT exported.',
            'No attachment bytes are read. Reference layouts are diagnostic, not naming-convention evidence.',
            'Samples seeded by id cannot guarantee paid/partial/refund examples; verify category coverage.',
        ]];
    $limit = max(1, min(10, (int) ($config['per_table_limit'] ?? 3)));
    foreach ($definitions as $table => $definition) {
        if (! in_array($table, $tables, true)) {
            $report['missing_tables'][] = $table;
            continue;
        }
        $metadata = $connector->columns($table);
        $types[$table] = array_column($metadata, 'type', 'name');
        $fields[$table] = array_values(array_intersect($definition['fields'], array_keys($types[$table])));
        $report['excluded_columns'][$table] = array_values(array_diff(array_keys($types[$table]), $fields[$table]));
        if (! in_array('id', $fields[$table], true)) {
            $report['schema_only_categories'][] = $table;
            continue;
        }
        foreach ($connector->historyPage($table, $fields[$table], '0', $limit) as $row) {
            $sample[$table][(string) $row['id']] = $row;
        }
    }
    // Include selected invoices' lines/payments and selected tickets' complete
    // conversations up to a hard limit. A full limit is conservatively reported
    // as potentially truncated, not represented as complete.
    foreach ([
        ['tblinvoiceitems', 'invoiceid', 'tblinvoices'],
        ['tblaccounts', 'invoiceid', 'tblinvoices'],
        ['tblticketreplies', 'tid', 'tbltickets'],
        ['tblticketnotes', 'ticketid', 'tbltickets'],
        ['tblusers_clients', 'client_id', 'tblclients'],
    ] as [$child, $field, $parent]) {
        if (! isset($fields[$child]) || empty($sample[$parent])) {
            continue;
        }
        $children = $connector->historyRelated($child, $fields[$child], $field, array_keys($sample[$parent]), 100);
        if (count($children) === 100) {
            $report['truncated_child_tables'][] = $child;
        }
        foreach ($children as $row) {
            $sample[$child][(string) $row['id']] = $row;
        }
    }
    // Close declared expected parent links, without inventing polymorphic links.
    $missing = [];
    for ($pass = 0; $pass < 20; $pass++) {
        $requests = [];
        foreach ($sample as $table => $rows) {
            foreach ($rows as $row) {
                foreach ($definitions[$table]['references'] as $field => $parent) {
                    $id = (string) ($row[$field] ?? '0');
                    if ($id !== '0' && $id !== '' && ! isset($sample[$parent][$id]) && ! isset($missing[$parent][$id])) {
                        $requests[$parent][$id] = $id;
                    }
                }
            }
        }
        if ($requests === []) {
            break;
        }
        foreach ($requests as $parent => $ids) {
            if (array_sum(array_map('count', $sample)) + count($ids) > 2000) {
                $report['closure_limit_reached'] = true;
                break 2;
            }
            $found = isset($fields[$parent]) ? $connector->historyByIds($parent, $fields[$parent], array_values($ids)) : [];
            foreach ($ids as $id) {
                $missing[$parent][$id] = true;
            }
            foreach ($found as $row) {
                $sample[$parent][(string) $row['id']] = $row;
                unset($missing[$parent][(string) $row['id']]);
            }
        }
        if ($pass === 19) {
            $report['closure_limit_reached'] = true;
        }
    }
    $report['missing_parent_count'] = array_sum(array_map('count', $missing));
    $report['unsupported_tables'] = array_values(array_diff($tables, array_keys($definitions)));
    $coverage = ['paid_invoice' => false, 'unpaid_invoice' => false, 'credit_applied' => false,
        'refund_outflow' => false, 'multiple_payments' => false, 'ticket_note' => ! empty($sample['tblticketnotes']),
        'email_history' => ! empty($sample['tblemails']), 'user_permissions' => ! empty($sample['tblusers_clients'])];
    foreach ($sample['tblinvoices'] ?? [] as $row) {
        $coverage['paid_invoice'] |= strtolower($row['status'] ?? '') === 'paid';
        $coverage['unpaid_invoice'] |= strtolower($row['status'] ?? '') === 'unpaid';
        $coverage['credit_applied'] |= preg_match('/[1-9]/', (string) ($row['credit'] ?? '0')) === 1;
    }
    $payments = [];
    foreach ($sample['tblaccounts'] ?? [] as $row) {
        $coverage['refund_outflow'] |= preg_match('/[1-9]/', (string) ($row['amountout'] ?? '0')) === 1;
        if (($row['invoiceid'] ?? 0) && preg_match('/[1-9]/', (string) ($row['amountin'] ?? '0'))) {
            $payments[(string) $row['invoiceid']] = ($payments[(string) $row['invoiceid']] ?? 0) + 1;
        }
    }
    $coverage['multiple_payments'] = array_any($payments, fn ($n) => $n > 1);
    $report['missing_sample_categories'] = array_keys(array_filter($coverage, fn ($present) => ! $present));
    $report['manual_verification_required'] = [
        'partial_payment' => 'Multiple payments do not establish a partially paid balance; confirm financial semantics.',
        'timezone' => 'Compare a known displayed payment with its raw DATETIME value.',
        'invoice_snapshot' => 'Provide a separately reviewed mod_invoicedata example; automatic decoding disabled.',
        'email_payload' => 'Provide a separately reviewed message_data example; automatic decoding disabled.',
        'destination' => 'Verify actual destination migrations and schema before enabling native projection.',
    ];
    $report['counts'] = [];
    $sanitizer = new SampleSanitizer(bin2hex(random_bytes(32)));
    foreach ($definitions as $table => $_) {
        $report['counts'][$table] = count($sample[$table] ?? []);
        foreach ($sample[$table] ?? [] as $id => $row) {
            $sample[$table][$id] = $sanitizer->row($row, $types[$table]);
        }
    }
    $base = $options['output'] ?? getcwd().'/migration-evidence/samples';
    $base = str_replace('\\', '/', $base);
    if (! str_ends_with(rtrim($base, '/'), '/migration-evidence/samples')) {
        throw new RuntimeException('Output must end in migration-evidence/samples.');
    }
    if (preg_match('~/(public|public_html|www|htdocs)(/|$)~i', $base) || str_contains($base, '..')) {
        throw new RuntimeException('Output must be private and outside web roots.');
    }
    // Refuse symlink ancestors rather than trusting the apparent path alone.
    $ancestor = rtrim($base, '/');
    while ($ancestor !== '' && dirname($ancestor) !== $ancestor) {
        if (is_link($ancestor)) {
            throw new RuntimeException('Output ancestors must not be symlinks.');
        }
        $resolved = realpath($ancestor);
        if ($resolved && preg_match('~/(public|public_html|www|htdocs)(/|$)~i', str_replace('\\', '/', $resolved))) {
            throw new RuntimeException('Resolved output must be outside web roots.');
        }
        $ancestor = dirname($ancestor);
    }
    umask(0077);
    $directory = rtrim($base, '/').'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Could not create private output directory.');
    }
    foreach (['samples.json' => $sample, 'report.json' => $report] as $file => $data) {
        $handle = fopen($directory.'/'.$file, 'xb');
        if (! $handle) {
            throw new RuntimeException('Could not create evidence output.');
        }
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Incomplete evidence write.');
        }
        fclose($handle);
    }
    fwrite(STDOUT, "Sanitized diagnostic evidence saved. Review report.json before sharing.\n");
} catch (Throwable $e) {
    // Never emit source connection exceptions or payloads.
    fwrite(STDERR, "Export failed. Verify private config, SELECT-only grants, schema and output permissions. No database writes were requested.\n");
    exit(1);
}
