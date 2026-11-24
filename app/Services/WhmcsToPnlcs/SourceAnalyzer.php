<?php

namespace App\Services\WhmcsToPnlcs;

use Throwable;

/** Read-only discovery of the WHMCS source and the PNLCS destination. */
class SourceAnalyzer
{
    /** Tables preserved in the archive when they have no native equivalent. */
    public const EXTRA_ARCHIVE_TABLES = [
        'emailtemplates', 'knowledgebasecats', 'knowledgebase', 'announcements',
        'downloadcategories', 'downloads', 'ticketspamfilters', 'bannedips',
        'bannedemails', 'todoitems', 'calendar', 'quotes', 'quoteitems',
    ];

    public function analyze(WhmcsConnector $connector, SourceCatalog $catalog, string $prefix = 'tbl'): array
    {
        $tables = $connector->tables();
        $grants = $connector->grants();
        $timezone = $connector->timezone();
        $version = $connector->version($tables);

        $entities = [];
        $counts = [];
        $unreadable = [];
        $schemaIssues = [];

        foreach ($catalog->all() as $entity => $definition) {
            $table = $definition['source'] ? $catalog->sourceTable($entity, $prefix) : null;
            if ($table === null || ! in_array($table, $tables, true)) {
                continue;
            }

            // Probe readability first: on partial grants COUNT(*) can be
            // forbidden even when the table itself is listed.
            if (! $connector->readable($table)) {
                $unreadable[] = $table;
                $counts[$entity] = 0;

                continue;
            }

            $entities[] = $entity;
            $schemaIssues = array_merge($schemaIssues, $this->checkSchema($connector, $table, $entity, $definition));
            try {
                $counts[$entity] = $connector->count($table);
            } catch (Throwable) {
                $counts[$entity] = 0;
                $unreadable[] = $table;
            }
        }

        $extra = [];
        foreach (self::EXTRA_ARCHIVE_TABLES as $name) {
            $prefixed = $prefix.$name;
            if (in_array($prefixed, $tables, true)) {
                try {
                    $count = $connector->readable($prefixed) ? $connector->count($prefixed) : 0;
                } catch (Throwable) {
                    $count = 0;
                }
                if ($count > 0) {
                    $extra[] = ['table' => $prefixed, 'count' => $count];
                }
            }
        }

        $coverage = $this->grantCoverage($grants, $tables, $unreadable);

        return [
            'version' => $version,
            'server' => $connector->serverInfo(),
            'diagnostics' => $connector->diagnostics(),
            'tables' => $tables,
            'table_count' => count($tables),
            'available_entities' => $entities,
            'counts' => $counts,
            'extra_archive_tables' => $extra,
            'grants' => $grants,
            'grant_coverage' => $coverage,
            'timezone' => $timezone,
            'unreadable_tables' => $unreadable,
            'schema_issues' => array_values(array_unique($schemaIssues)),
            'fingerprint' => $connector->fingerprint($tables),
        ];
    }

    /**
     * Read-only list of WHMCS currencies for the first wizard page.
     *
     * WHMCS versions differ on whether the flag column is called `default`.
     * Fall back to the first row so the selector is still usable on sources
     * without a flag; the administrator can override it explicitly.
     *
     * @return list<array{id: int, code: string, rate: float, is_default: bool}>
     */
    public function currencies(WhmcsConnector $connector, string $prefix = 'tbl'): array
    {
        $table = $prefix.'currencies';
        try {
            if (! in_array($table, $connector->tables(), true) || ! $connector->readable($table)) {
                return [];
            }
            $columns = array_map(
                static fn (array $column): string => strtolower((string) $column['name']),
                $connector->columns($table)
            );
            $rows = $connector->all($table);
        } catch (Throwable) {
            return [];
        }

        $hasDefault = in_array('default', $columns, true);
        $options = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $options[] = [
                'id' => $id,
                'code' => (string) ($row['code'] ?? ('Currency '.$id)),
                'rate' => (float) ($row['rate'] ?? 0),
                'is_default' => $hasDefault && ! empty($row['default']),
            ];
        }

        if ($options !== [] && ! array_filter($options, static fn (array $row): bool => $row['is_default'])) {
            $options[0]['is_default'] = true;
        }

        return $options;
    }

    /**
     * A catalog entry that names a source column the source table does not have
     * silently imports NULLs. Surface that during analysis instead.
     *
     * @return list<string>
     */
    protected function checkSchema(WhmcsConnector $connector, string $table, string $entity, array $definition): array
    {
        try {
            $actual = array_map(
                static fn (array $column): string => strtolower((string) $column['name']),
                $connector->columns($table)
            );
        } catch (Throwable) {
            return [];
        }

        $declared = [];
        foreach ($definition['fields'] ?? [] as $source) {
            if (is_string($source) && $source !== '' && ! str_starts_with($source, '_')) {
                $declared[] = $source;
            }
        }
        foreach ($definition['source_refs'] ?? [] as $source) {
            if (is_string($source) && $source !== '') {
                $declared[] = $source;
            }
        }
        if (isset($definition['rel_id']['column'])) {
            $declared[] = (string) $definition['rel_id']['column'];
        }
        if (isset($definition['rel_id']['by_type'])) {
            $declared[] = (string) $definition['rel_id']['by_type'];
        }

        $issues = [];
        foreach (array_unique($declared) as $column) {
            if (! in_array(strtolower($column), $actual, true)) {
                $issues[] = $entity.'.'.$column.' is missing from source table '.$table;
            }
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    protected function grantCoverage(array $grants, array $tables, array $unreadable): array
    {
        if ($grants['database_wide_select'] || $grants['all_privileges']) {
            return [
                'status' => 'full',
                'label' => 'Database-wide SELECT grant recognised. All source tables are covered.',
            ];
        }

        if ($grants['inconclusive']) {
            return [
                'status' => 'inconclusive',
                'label' => 'Privilege inspection is not available on this host; actual table readability was checked instead.',
            ];
        }

        $tableSelect = array_map('strtolower', $grants['table_select']);
        $granted = array_values(array_filter($tables, fn (string $table) => in_array(strtolower($table), $tableSelect, true)));

        if ($unreadable !== []) {
            return [
                'status' => 'partial',
                'label' => 'Some required source tables are not readable. They will be skipped and reported.',
                'unreadable' => $unreadable,
            ];
        }

        return [
            'status' => 'readable',
            'label' => 'Table-level SELECT grants found and every discovered source table passed a read probe.',
            'granted_tables' => $granted,
        ];
    }

    /** @return array<string, int> */
    public function destination(): array
    {
        $result = [];
        $tables = [
            'clients' => 'clients', 'products' => 'products', 'services' => 'services',
            'domains' => 'domains', 'orders' => 'orders', 'invoices' => 'invoices',
            'transactions' => 'transactions', 'tickets' => 'tickets',
            'currencies' => 'currencies', 'servers' => 'servers', 'contacts' => 'contacts',
        ];

        foreach ($tables as $key => $table) {
            try {
                $result[$key] = \Illuminate\Support\Facades\DB::table($table)->count();
            } catch (Throwable) {
                $result[$key] = 0;
            }
        }

        return $result;
    }
}
