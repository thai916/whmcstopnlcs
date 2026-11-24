<?php

namespace App\Services\WhmcsToPnlcs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one source row into its native PNLCS table (or the read-only archive).
 *
 * Writes go through the query builder, not Eloquent models: imported records
 * must not fire model events, hooks, provisioning, emails or payment actions.
 */
class EntityImporter
{
    /** @var array<string, list<string>> */
    protected array $required = [
        'currencies' => ['code'],
        'client_groups' => ['name'],
        'clients' => ['email', 'first_name', 'last_name'],
        'contacts' => ['client_id', 'email'],
        'custom_fields' => ['field_name'],
        'custom_field_values' => ['field_id'],
        'ticket_departments' => ['name'],
        'product_groups' => ['name', 'slug'],
        'products' => ['name', 'group_id'],
        'pricing' => ['type', 'rel_id'],
        'product_addons' => ['name'],
        'config_option_groups' => ['name'],
        'config_options' => ['group_id', 'option_name'],
        'config_option_subs' => ['config_id', 'option_name'],
        'config_option_links' => ['group_id', 'product_id'],
        'servers' => ['name', 'hostname'],
        'server_groups' => ['name'],
        'server_group_links' => ['server_group_id', 'server_id'],
        'orders' => ['date'],
        'services' => ['client_id'],
        'domains' => ['client_id', 'domain'],
        'invoices' => ['client_id', 'date'],
        'invoice_items' => ['invoice_id'],
        'transactions' => ['client_id', 'date'],
        'credits' => ['client_id'],
        'tickets' => ['title'],
        'ticket_replies' => ['ticket_id', 'message'],
        'ticket_notes' => ['ticket_id', 'message'],
        'client_notes' => ['client_id'],
        'cancellation_requests' => ['service_id'],
        'promotions' => ['code'],
        'affiliates' => ['client_id'],
        'activity_logs' => ['description'],
        'payment_methods' => ['client_id'],
    ];

    /** @var array<string, list<string>> */
    protected array $keys = [
        'currencies' => ['code'],
        'client_groups' => ['name'],
        'clients' => ['email'],
        'contacts' => ['client_id', 'email'],
        'custom_fields' => ['type', 'field_name'],
        'custom_field_values' => ['field_id', 'rel_id'],
        'ticket_departments' => ['name'],
        'product_groups' => ['name'],
        'products' => ['name', 'group_id'],
        'pricing' => ['type', 'currency_id', 'rel_id'],
        'product_addons' => ['name'],
        'config_option_groups' => ['name'],
        'config_options' => ['group_id', 'option_name'],
        'config_option_subs' => ['config_id', 'option_name'],
        'config_option_links' => ['group_id', 'product_id'],
        'servers' => ['hostname'],
        'server_groups' => ['name'],
        'server_group_links' => ['server_group_id', 'server_id'],
        'services' => ['whmcs_service_id'],
        'domains' => ['client_id', 'domain'],
        'invoices' => ['source_invoice_id'],
        'invoice_items' => ['invoice_id', 'description', 'amount'],
        'transactions' => ['whmcs_transaction_id'],
        'credits' => ['client_id', 'date', 'description', 'amount'],
        'tickets' => ['tid'],
        'ticket_replies' => ['ticket_id', 'message', 'created_at'],
        'ticket_notes' => ['ticket_id', 'message', 'created_at'],
        'client_notes' => ['client_id', 'note', 'created_at'],
        'cancellation_requests' => ['service_id', 'reason'],
        'promotions' => ['code'],
        'affiliates' => ['client_id'],
        'activity_logs' => ['date', 'description'],
        'payment_methods' => ['client_id', 'description'],
    ];

    /** @var array<string, list<string>> */
    protected array $columnsCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $metaCache = [];

    /**
     * @return array{status: string, target_id: int|null, error: string|null}
     */
    public function import(string $entity, array $row, ImportContext $ctx, SourceCatalog $catalog): array
    {
        $definition = $catalog->get($entity);
        if ($definition === null) {
            return $this->result('failed', null, 'Unknown entity '.$entity);
        }

        $sourceId = $ctx->sourceId($row['id'] ?? null);
        $targetTable = (string) ($definition['target'] ?? '');

        // Unsupported records are archived verbatim, never fabricated natively.
        if (($definition['mode'] ?? 'native') === 'archive' || $targetTable === '') {
            $ctx->archive($entity, $sourceId, $row, $row['date'] ?? $row['datecreated'] ?? null);

            return $this->result($ctx->dryRun() ? 'would_archive' : 'archived', null, null);
        }

        try {
            $columns = $this->buildColumns($entity, $definition, $row, $ctx);
            $columns = $this->protectUniqueness($entity, $columns, $sourceId, $ctx);

            // An unresolved NOT NULL reference must never be coerced to 0.
            // Archive the whole source row with the reason so the relationship
            // can be repaired by an administrator instead of becoming a bogus FK.
            $referenceFields = array_keys($definition['references'] ?? []);
            $unresolved = [];
            foreach ($referenceFields as $field) {
                if (array_key_exists($field, $columns) && $columns[$field] === null) {
                    $meta = $this->columnMeta($targetTable)[$field] ?? null;
                    if ($meta === null || ! $meta['nullable']) {
                        $unresolved[] = $field;
                    }
                }
            }
            if ($unresolved !== []) {
                $archival = $row;
                $archival['_wtp_unresolved_references'] = implode(', ', $unresolved);
                $ctx->archive($entity, $sourceId, $archival, $row['date'] ?? $row['datecreated'] ?? null);

                return $this->result($ctx->dryRun() ? 'would_archive' : 'archived', null, null);
            }

            foreach ($this->required[$entity] ?? [] as $field) {
                if (! array_key_exists($field, $columns) || $columns[$field] === null || $columns[$field] === '') {
                    return $this->result('failed', null, 'Missing required field: '.$field);
                }
            }
        } catch (Throwable $e) {
            return $this->result('failed', null, $e->getMessage());
        }

        if ($ctx->dryRun()) {
            $existing = $this->findExisting($entity, $targetTable, $columns, $ctx);
            $mapped = $ctx->targetId($entity, $sourceId);

            return $this->result(
                ($existing !== null || $mapped !== null) ? 'would_skip' : 'would_import',
                $existing !== null ? (int) $existing->id : $mapped,
                null
            );
        }

        try {
            $mappedId = $ctx->targetId($entity, $sourceId);
            $existing = $mappedId !== null
                ? $this->findById($targetTable, $mappedId)
                : $this->findExisting($entity, $targetTable, $columns, $ctx);

            if ($existing !== null && ($ctx->options['duplicate_strategy'] ?? 'skip') === 'skip') {
                $ctx->remember($entity, $sourceId, (int) $existing->id, $targetTable, $this->hash($columns));

                return $this->result('skipped', (int) $existing->id, 'Already present');
            }

            if ($existing !== null) {
                unset($columns['created_at']);
                $columns['updated_at'] = now()->format('Y-m-d H:i:s');
                DB::table($targetTable)->where('id', $existing->id)->update($columns);
                $targetId = (int) $existing->id;
                $status = 'updated';
            } else {
                $columns['created_at'] ??= now()->format('Y-m-d H:i:s');
                if ($this->hasColumn($targetTable, 'updated_at')) {
                    $columns['updated_at'] = $columns['updated_at'] ?? $columns['created_at'];
                }
                $targetId = (int) DB::table($targetTable)->insertGetId($columns);
                $status = 'imported';
            }

            $ctx->remember($entity, $sourceId, $targetId, $targetTable, $this->hash($columns));

            if ($entity === 'pricing') {
                try {
                    $this->importShopPricingRow($row, $columns, $ctx, $targetId);
                } catch (Throwable $e) {
                    // The original source-currency price is already imported;
                    // a failed derived copy must not fail the source row.
                    $ctx->warn('Shop-currency price for pricing row '.$sourceId.' was not created: '.$e->getMessage());
                }
            }

            return $this->result($status, $targetId, null);
        } catch (Throwable $e) {
            return $this->result('failed', null, $this->safeError($e));
        }
    }

    /** @return array<string, mixed> */
    protected function buildColumns(string $entity, array $definition, array $row, ImportContext $ctx): array
    {
        $columns = [];
        $table = (string) $definition['target'];

        foreach ($definition['fields'] ?? [] as $target => $source) {
            if ($source === null) {
                continue;
            }

            // A virtual field like `_product_name` is metadata, not a column.
            if ($source === '_product_name') {
                continue;
            }

            $value = $row[$source] ?? null;

            if (isset($definition['transforms'][$target])) {
                $value = $ctx->transform($definition['transforms'][$target], $value);
            }

            if (in_array($target, $definition['zero_dates'] ?? [], true)) {
                $value = $ctx->dateOrNull($value);
            }

            if ($target === 'password' || $target === 'access_hash') {
                // Never copy source authentication secrets into active records.
                continue;
            }

            $columns[$target] = $value;
        }

        foreach ($definition['defaults'] ?? [] as $target => $value) {
            if ($value !== null || ! array_key_exists($target, $columns)) {
                $columns[$target] = $value;
            }
        }

        foreach ($definition['references'] ?? [] as $targetField => $referenceEntity) {
            $sourceColumn = $definition['source_refs'][$targetField] ?? $targetField;
            $sourceValue = $row[$sourceColumn] ?? null;
            $columns[$targetField] = $sourceValue === null || $sourceValue === ''
                ? null
                : $ctx->targetId($referenceEntity, $sourceValue);
        }

        // WHMCS polymorphic relid -> the target entity named by the type word.
        if (isset($definition['rel_id'])) {
            $rel = $definition['rel_id'];
            $type = isset($rel['by_type']) ? (string) ($row[$rel['by_type']] ?? '') : (string) ($row['type'] ?? '');
            $relEntity = $rel['entities'][$type] ?? null;
            $raw = $row[$rel['column']] ?? null;
            $columns['rel_id'] = $relEntity && $raw
                ? ($ctx->targetId($relEntity, $raw) ?? 0)
                : 0;
        }

        if ($entity === 'clients') {
            $columns['uuid'] = $ctx->uuid('clients', $row['id'] ?? '');
            if (trim((string) ($columns['first_name'] ?? '')) === '') {
                $columns['first_name'] = trim((string) ($columns['company_name'] ?? '')) ?: '-';
            }
            if (trim((string) ($columns['last_name'] ?? '')) === '') {
                $columns['last_name'] = trim((string) ($columns['company_name'] ?? '')) ?: '-';
            }
        }

        if ($entity === 'services') {
            // Keep the original service ID as the stable identity. Without it
            // several distinct WHMCS services can collapse onto one PNLCS row.
            $columns['whmcs_service_id'] = $row['id'] ?? null;
            $productId = $ctx->targetId('products', $row['packageid'] ?? null);
            if ($productId !== null) {
                $columns['whmcs_product_name'] = DB::table('products')->where('id', $productId)->value('name');
            }

            // WHMCS stores the service price in the client's currency. Convert
            // it into the target currency chosen on the first wizard page (or
            // the PNLCS default when no explicit pair was selected), while
            // retaining the original value for audit.
            $sourceCurrencyId = DB::table('clients')
                ->where('id', $columns['client_id'] ?? null)
                ->value('currency_id');
            $selectedSourceId = $this->selectedWhmcsCurrencyId($ctx);
            $selectedSourceTargetId = $selectedSourceId !== null
                ? $ctx->targetId('currencies', $selectedSourceId)
                : null;
            if ($sourceCurrencyId === null && $selectedSourceTargetId !== null) {
                $sourceCurrencyId = $selectedSourceTargetId;
            }
            if ($sourceCurrencyId !== null) {
                $columns['whmcs_currency_id'] = $sourceCurrencyId;
                $columns['whmcs_original_amount'] = $columns['amount'] ?? null;
                $columns['whmcs_original_first_payment_amount'] = $columns['first_payment_amount'] ?? null;

                $conversion = $this->conversionRate($ctx, (int) $sourceCurrencyId);
                if ($conversion !== null) {
                    foreach (['amount', 'first_payment_amount'] as $field) {
                        if (($columns[$field] ?? null) !== null) {
                            $columns[$field] = round((float) $columns[$field] * $conversion['rate'], 2);
                        }
                    }
                }
            }
        }

        if ($entity === 'pricing') {
            // Keep the source currency next to each imported price. A converted
            // copy for the shop currency is added after the row is written.
            $columns['whmcs_source_currency_id'] = $row['currency'] ?? null;
        }

        if ($entity === 'invoices') {
            // WHMCS displays the row ID as the invoice number when invoicenum is
            // blank. Persist both so blank numbers cannot collapse distinct rows.
            $columns['source_invoice_id'] = $row['id'] ?? $columns['source_invoice_id'] ?? null;
            if (trim((string) ($columns['invoice_num'] ?? '')) === '') {
                $columns['invoice_num'] = (string) ($row['id'] ?? '');
            }
        }

        if ($entity === 'transactions') {
            // WHMCS transid is often blank or reused; the source row ID is the
            // only reliable identity for distinct financial events.
            $columns['whmcs_transaction_id'] = $row['id'] ?? null;
        }

        if ($entity === 'product_groups' || $entity === 'products') {
            $name = (string) ($columns['name'] ?? '');
            $columns['slug'] = Str::slug($name).'-'.substr(md5((string) ($row['id'] ?? $name)), 0, 6);
        }

        if (in_array($entity, ['ticket_replies', 'ticket_notes'], true)
            && array_key_exists('message', $columns)
            && trim((string) $columns['message']) === '') {
            // A blank source body must not lose the reply/note entirely. Keep
            // the row with an explicit placeholder so the timeline stays whole.
            $ctx->warn('Blank '.$entity.' body for source row '.($row['id'] ?? '?').'; stored as placeholder.');
            $columns['message'] = '(no message)';
        }

        if ($entity === 'orders' && empty($columns['order_num'])) {
            $columns['order_num'] = 'WHMCS-'.($row['id'] ?? 'unknown');
        }

        if ($entity === 'tickets' && empty($columns['tid'])) {
            $columns['tid'] = 'WHMCS-'.($row['id'] ?? 'unknown');
        }

        // Only columns that really exist in the target table can be inserted.
        $columns = array_intersect_key($columns, array_flip($this->columns($table)));

        return $this->applyColumnDefaults(
            $entity, $table, $columns,
            array_keys($definition['references'] ?? []),
            (array) ($definition['zero_dates'] ?? [])
        );
    }

    protected function selectedWhmcsCurrencyId(ImportContext $ctx): ?int
    {
        $id = $ctx->options['whmcs_currency_id'] ?? null;

        return empty($id) ? null : (int) $id;
    }

    protected function selectedPnlcsCurrencyId(ImportContext $ctx): ?int
    {
        $id = $ctx->options['pnlcs_currency_id'] ?? null;

        return empty($id) ? null : (int) $id;
    }

    /** @return object{id: int, rate: float}|null */
    protected function targetCurrency(ImportContext $ctx): ?object
    {
        $selected = $this->selectedPnlcsCurrencyId($ctx);
        if ($selected !== null) {
            $row = DB::table('currencies')->where('id', $selected)->first(['id', 'rate']);
            if ($row !== null) {
                return $row;
            }
        }

        return DB::table('currencies')->where('is_default', 1)->orderBy('id')->first(['id', 'rate']);
    }

    /**
     * Rate from a mapped PNLCS currency row to the selected PNLCS currency.
     *
     * PNLCS stores every rate against its default currency, so converting
     * between two rows is target_rate / source_rate. Considering the selected
     * WHMCS currency here is done by the caller through the persistent import
     * mapping; raw WHMCS IDs are never used as PNLCS currency IDs.
     *
     * @return array{target_id: int, rate: float}|null
     */
    protected function conversionRate(ImportContext $ctx, int $sourceTargetCurrencyId): ?array
    {
        $source = DB::table('currencies')->where('id', $sourceTargetCurrencyId)->first(['id', 'rate']);
        $target = $this->targetCurrency($ctx);

        if (! $source || ! $target || (float) $source->rate <= 0 || (float) $target->rate <= 0) {
            return null;
        }

        return [
            'target_id' => (int) $target->id,
            'rate' => (float) $target->rate / (float) $source->rate,
        ];
    }

    /**
     * The admin product form reads prices in the shop currency only. Keep the
     * original source-currency row and add a clearly marked converted copy so
     * imported prices are visible without changing their historical values.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $columns
     */
    protected function importShopPricingRow(array $row, array $columns, ImportContext $ctx, int $sourceTargetId): void
    {
        // WHMCS currency IDs are not PNLCS currency IDs. The imported row's
        // currency_id is the mapped PNLCS row whose rate must be used; keep the
        // raw WHMCS ID only as provenance.
        $sourceCurrencyId = (int) ($row['currency'] ?? 0);
        $sourceTargetCurrencyId = (int) ($columns['currency_id'] ?? 0);
        if ($sourceCurrencyId === 0 || $sourceTargetCurrencyId === 0 || empty($columns['rel_id'])) {
            return;
        }

        // When an administrator selected a specific WHMCS currency on page 1,
        // only rows in that currency get a converted copy. Without a selection,
        // the previous behaviour is preserved for every non-target currency.
        $selectedSourceId = $this->selectedWhmcsCurrencyId($ctx);
        if ($selectedSourceId !== null && $sourceCurrencyId !== $selectedSourceId) {
            return;
        }

        $conversion = $this->conversionRate($ctx, $sourceTargetCurrencyId);
        if ($conversion === null || $sourceTargetCurrencyId === $conversion['target_id']) {
            return;
        }

        $derived = $columns;
        $derived['currency_id'] = $conversion['target_id'];
        $derived['whmcs_source_currency_id'] = $sourceCurrencyId;
        $derived['whmcs_converted_from_id'] = $sourceTargetId;

        $amountFields = [
            'monthly_setup', 'quarterly_setup', 'semiannually_setup',
            'annually_setup', 'biennially_setup', 'triennially_setup',
            'monthly', 'quarterly', 'semiannually', 'annually',
            'biennially', 'triennially',
        ];
        foreach ($amountFields as $field) {
            if (! array_key_exists($field, $derived) || $derived[$field] === null) {
                continue;
            }
            $value = (float) $derived[$field];
            // -1 is PNLCS's "cycle not sold" marker, not a price to convert.
            if ($value < 0) {
                continue;
            }
            $derived[$field] = round($value * $conversion['rate'], 2);
        }

        $derived = array_intersect_key($derived, array_flip($this->columns('pricing')));
        unset($derived['id']);

        $candidates = DB::table('pricing')
            ->where('type', $derived['type'] ?? null)
            ->where('currency_id', $derived['currency_id'])
            ->where('rel_id', $derived['rel_id'])
            ->get();
        $existing = $candidates->first(
            fn ($row) => (int) ($row->whmcs_converted_from_id ?? 0) === $sourceTargetId
        );
        $collision = $candidates->first(
            fn ($row) => (int) ($row->whmcs_converted_from_id ?? 0) !== $sourceTargetId
        );
        if ($existing === null && $collision !== null) {
            // PNLCS pricing is unique per (type, currency, rel_id). Never
            // overwrite a price already entered in the shop currency.
            $ctx->warn('Shop-currency price for pricing row '.$ctx->sourceId($row['id'] ?? '')
                .' was not created: that currency already has a price for this product.');

            return;
        }

        if ($existing !== null) {
            unset($derived['created_at']);
            $derived['updated_at'] = now()->format('Y-m-d H:i:s');
            $derivedId = (int) $existing->id;
            DB::table('pricing')->where('id', $derivedId)->update($derived);
        } else {
            $derived['created_at'] ??= now()->format('Y-m-d H:i:s');
            $derived['updated_at'] = $derived['updated_at'] ?? $derived['created_at'];
            $derivedId = (int) DB::table('pricing')->insertGetId($derived);
        }

        $ctx->remember(
            'pricing_shop',
            $ctx->sourceId($row['id'] ?? '').'@target'.$conversion['target_id'],
            $derivedId,
            'pricing',
            $this->hash($derived)
        );
    }

    /**
     * A source NULL must not override a PNLCS column default: doing so turns a
     * nullable-in-practice field into a hard integrity error. Where a NOT NULL
     * column has no default and is not a required field for that entity, a
     * harmless type-appropriate empty value is used so one blank source cell
     * cannot abort a whole batch. Reference fields are deliberately excluded:
     * an unresolved reference is archived by import() instead of becoming 0.
     * Required zero-date fields receive a valid sentinel date.
     *
     * @return array<string, mixed>
     */
    protected function applyColumnDefaults(string $entity, string $table, array $columns, array $referenceFields = [], array $zeroDates = []): array
    {
        $required = $this->required[$entity] ?? [];
        $meta = $this->columnMeta($table);

        foreach ($columns as $field => $value) {
            if ($value !== null || ! isset($meta[$field])) {
                continue;
            }

            // Reference resolution failures are handled explicitly by import()
            // (archive or nullable value). Never turn them into a bogus 0 here.
            if (in_array($field, $referenceFields, true)) {
                continue;
            }

            $column = $meta[$field];
            if ($column['default'] !== null) {
                // Let the database apply its own default.
                unset($columns[$field]);

                continue;
            }

            // A required zero-date still needs a valid sentinel, otherwise it
            // fails the required-field check before the database sees it.
            if ($column['nullable']
                || (in_array($field, $required, true) && ! in_array($field, $zeroDates, true))) {
                continue;
            }

            $columns[$field] = $this->emptyValue((string) $column['type']);
        }

        return $columns;
    }

    /** @return array<string, array<string, mixed>> */
    protected function columnMeta(string $table): array
    {
        if (isset($this->metaCache[$table])) {
            return $this->metaCache[$table];
        }

        $meta = [];
        $quoted = '`'.str_replace('`', '``', $table).'`';
        foreach (DB::select('SHOW COLUMNS FROM '.$quoted) as $column) {
            $meta[(string) $column->Field] = [
                'nullable' => strtoupper((string) $column->Null) === 'YES',
                'default' => $column->Default,
                'type' => (string) $column->Type,
            ];
        }

        return $this->metaCache[$table] = $meta;
    }

    protected function emptyValue(string $type): mixed
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'int'), str_contains($type, 'decimal'),
            str_contains($type, 'float'), str_contains($type, 'double'),
            str_contains($type, 'bit') => 0,
            str_starts_with($type, 'date') && ! str_contains($type, 'time') => '1970-01-01',
            str_contains($type, 'date'), str_contains($type, 'time') => '1970-01-01 00:00:00',
            str_starts_with($type, 'enum'), str_starts_with($type, 'set') => $this->firstEnumValue($type),
            default => '',
        };
    }

    protected function firstEnumValue(string $type): string
    {
        if (preg_match("/'(?:[^'\\\\]|\\\\.)*'/", $type, $match)) {
            return trim($match[0], "'");
        }

        return '';
    }

    protected function protectUniqueness(string $entity, array &$columns, string $sourceId, ImportContext $ctx): array
    {
        if (! in_array($entity, ['invoices', 'orders', 'tickets', 'currencies'], true)) {
            return $columns;
        }

        $field = match ($entity) {
            'invoices' => 'invoice_num',
            'orders' => 'order_num',
            'tickets' => 'tid',
            'currencies' => 'code',
        };

        $value = (string) ($columns[$field] ?? '');
        if ($value === '') {
            return $columns;
        }

        $table = (string) ($this->definition($entity)['target'] ?? '');
        $clash = DB::table($table)->where($field, $value)->exists();
        if (! $clash) {
            return $columns;
        }

        // The existing row may be the one this source row was imported to on
        // an earlier run. In that case the value is already correct and must
        // not be suffixed again.
        $mappedId = $ctx->targetId($entity, $sourceId);
        if ($mappedId !== null && DB::table($table)->where('id', $mappedId)->where($field, $value)->exists()) {
            return $columns;
        }

        // Preserve the historical number as metadata and use an explicit,
        // traceable suffix rather than silently renumbering.
        $suffixed = $value.'-whmcs-'.$sourceId;
        $ctx->warn(sprintf('Historical %s %s already exists in PNLCS; stored as %s. Review the original number manually.', $entity, $value, $suffixed));
        $columns[$field] = $suffixed;

        return $columns;
    }

    protected function findExisting(string $entity, string $table, array $columns, ImportContext $ctx): ?object
    {
        $keys = $this->keys[$entity] ?? [];
        $query = DB::table($table);
        $hasCriteria = false;

        foreach ($keys as $key) {
            if (! array_key_exists($key, $columns) || $columns[$key] === null || $columns[$key] === '') {
                // An unresolved reference must not turn into a partial match.
                if (in_array($key, ['client_id', 'invoice_id', 'ticket_id', 'field_id', 'group_id', 'config_id', 'service_id', 'server_id', 'server_group_id', 'product_id'], true)) {
                    return null;
                }

                continue;
            }
            $query->where($key, $columns[$key]);
            $hasCriteria = true;
        }

        return $hasCriteria ? $query->orderBy('id')->first() : null;
    }

    protected function findById(string $table, int $id): ?object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    /** @return list<string> */
    protected function columns(string $table): array
    {
        return $this->columnsCache[$table] ??= Schema::getColumnListing($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    protected function definition(string $entity): array
    {
        return (new SourceCatalog)->get($entity) ?? [];
    }

    protected function hash(array $columns): string
    {
        $copy = $columns;
        unset($copy['updated_at']);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    protected function safeError(Throwable $e): string
    {
        $message = $e->getMessage();

        return preg_replace('/(password|secret|token|key)\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?: 'Import failed';
    }

    /** @return array{status: string, target_id: int|null, error: string|null} */
    protected function result(string $status, ?int $targetId, ?string $error): array
    {
        return ['status' => $status, 'target_id' => $targetId, 'error' => $error];
    }
}
