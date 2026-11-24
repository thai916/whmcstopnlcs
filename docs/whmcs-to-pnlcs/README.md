# WHMCS → PNLCS migration utility

An independent, auditable migration tool for importing WHMCS 8.x data into PNLCS. It is deliberately separate from the existing **Import WHMCS** utility: different namespace, tables, routes, menu links, views, and language file. The original importer is unchanged and remains available at `admin/whmcs-import`.

## Hard safety boundaries

- **Read-only source.** The remote connector exposes only SELECT/read/diagnostic methods. Use a dedicated database user with `SELECT`; cPanel-style `GRANT SELECT ON \`db\`.*` is recognised and validated by actually reading tables.
- **TLS never silently downgrades.** Modes are `verified`, `unverified`, and explicit `none`. Verified/unverified connections fail closed if TLS is not negotiated. `none` requires administrator confirmation when the connection is saved.
- **No PNLCS automation.** Writes use the query builder, not Eloquent model events. No emails, provisioning, suspension, termination, registrar, gateway, payment, or refund calls are made.
- **Credentials are protected.** Source passwords are encrypted at rest, excluded from old input, logs, views, maps, and exports.
- **Unsupported/sensitive history is archived.** Vaulted cards, EPP codes, tokens, password hashes, and tables with no native PNLCS equivalent go to a read-only archive with secrets redacted.
- **Imported products and servers are inert.** Products are visible in the admin catalogue but retired/non-orderable; servers are disabled with all API secrets omitted. Both require human review before live use.
- **No real migration without approval.** Dry run is the default. A real run starts only when an administrator deliberately selects it after reviewing a successful dry run and its reconciliation. There is no automatic promotion from dry run to real run.

## Architecture

| Layer | Location |
| --- | --- |
| HTTP/UI | `app/Http/Controllers/Admin/WhmcsToPnlcsController.php`, `resources/views/admin/whmcs-to-pnlcs/`, `lang/en/whmcs_to_pnlcs.php` |
| Routes | `routes/admin.php`, group `admin.whmcs-to-pnlcs.*` (18 routes) under `admin.auth`, `admin.2fa`, `admin.permission:manage_settings` |
| Services | `app/Services/WhmcsToPnlcs/` — connector, catalog, analyzer, safety guard, runner, importer, reconciliation, reports |
| Models | `app/Models/WhmcsToPnlcs*.php` |
| Schema | `database/migrations/2026_11_23_000001_create_whmcs_to_pnlcs_tables.php`, `...000002_add_whmcs_service_id_to_services.php`, `...000003_widen_whmcs_migration_target_columns.php`, `...000004_add_whmcs_transaction_id_to_transactions.php`, `...2026_11_24_000001_add_whmcs_invoice_and_price_identity.php` |

Utility tables: `whmcs_to_pnlcs_connections`, `whmcs_to_pnlcs_profiles`, `whmcs_to_pnlcs_runs`, `whmcs_to_pnlcs_records`, `whmcs_to_pnlcs_mappings`, `whmcs_to_pnlcs_archive`, `whmcs_to_pnlcs_reports`, `whmcs_to_pnlcs_logs`.

`SourceCatalog` is the single source of truth for source tables, target tables, field maps, references (`source_refs`), dependency order, native/archive classification, and scope groups. Every reference must declare its source column; `docs/whmcs-to-pnlcs/verify.php` asserts this. `SourceAnalyzer` also reports `schema_issues` when a declared source column does not exist in the source table; `MigrationRunner` surfaces them as run warnings so a bad catalog cannot silently import NULLs.

`EntityImporter` writes with the query builder, preserves original IDs through persistent mappings (`source_invoice_id` on invoices, `whmcs_service_id` on services, `whmcs_transaction_id` on transactions), maps WHMCS references to imported PNLCS IDs, respects PNLCS column defaults when source values are NULL, converts zero dates to valid sentinels, and omits source secrets. An unresolved NOT NULL reference is archived with the reason instead of being coerced to `0`.

WHMCS often leaves `invoicenum` blank and displays the row ID as the invoice number. The importer now keys invoices by source row ID and falls back to that ID for display, so blank numbers cannot collapse distinct invoices onto one target row. Product prices are kept in their original WHMCS currency row and a converted copy is derived for the PNLCS shop currency (the only row the admin product form reads); conversion uses the **mapped PNLCS currency row's rate**, because WHMCS currency IDs are not PNLCS currency IDs. The original currency and derived-row provenance stay in dedicated columns. Service prices are converted from the client's WHMCS currency into the shop currency while the original values are retained for audit. Existing shop-currency prices are never overwritten.

## Wizard

1. **Connect** — host, database, prefix, credentials, TLS mode, CA/SNI, timeout. Test or save.
2. **Source analysis** — version, readable tables, row counts, grants, TLS evidence, detected timezone with warning/override.
3. **Destination & scope** — existing PNLCS counts, scope groups, batch size, duplicate strategy, archive-extras, dry run.
4. **Mapping** — review the catalog, including native vs archive classification and review flags.
5. **Dry run** — processes batches without writing business rows, mappings, or record logs.
6. **Migrate** — only after approval. Click **Start / continue** once; the page then auto-continues bounded batches until completion, with Pause/Retry/Cancel stopping the loop.
7. **Verify** — reconciliation compares counts and financial totals, with invoice/transaction totals preserved.
8. **Report** — protected CSV/JSON reports: summary, records, mappings, financial, warnings.

Runs are resumable: the checkpoint stores entity index and offset. Retries delete failed record rows and rewind the checkpoint; already-imported rows are idempotent skips via the mapping table.

The browser auto-run loop keeps each HTTP request bounded to one batch, but stops on terminal status, HTTP/JSON errors, a repeated checkpoint, or a calculated safety limit. New runner warnings are shown in the live progress note instead of being silently appended only to the warnings card.

If a batch throws (for example, the source MySQL user is rejected), the run is marked **failed** with a finish time, the last source error is shown in the live note and in the Warnings card, and no further batches are requested. Fix the source connection, reload the Migrate page, and click **Start / continue** to resume from the stored checkpoint: the interruption warning is cleared as soon as a later batch succeeds. The source is never written to; only `SELECT` diagnostics are issued.

## Installation

This is a flat-layout drop-in for an existing PNLCS application. It does not replace or modify the original **Import WHMCS** utility.

1. Back up the PNLCS application files and database.
2. Clone or copy this repository to a temporary folder, then copy its files into the PNLCS root, preserving paths (`app/`, `database/`, `docs/`, `lang/`, `resources/`, `routes/`).
3. Review `routes/admin.php` and `resources/views/admin/layouts/app.blade.php` against any local changes before overwriting them.
4. On the WHMCS server, create a dedicated MySQL user with `SELECT` only and add this PNLCS server's public IP to that user's allowed hosts (cPanel → **MySQL Access Hosts**). Without that allowlist, connection tests fail with `Access denied for user ... @your-IP`.
5. Run the migrations and rebuild caches:

   ```powershell
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

6. Sign in as a full administrator and open **Utilities → WHMCS to PNLCS**. Save a connection, test it, then follow the wizard. Dry run is the default; a real import only starts after explicit approval.

Use a PHP CLI binary with `pdo_mysql` enabled. The repository's `VERSION` file (`0.1`) is the migration utility version, not the PNLCS version.

## Verification

Verification uses a disposable synthetic WHMCS source and a separate migrated target database. It never needs a live WHMCS connection.

Use the PHP binary with `pdo_mysql`; the default CLI PHP on this machine lacks it:

```powershell
$php = 'D:\Laragon\bin\php\php-8.4.28-Win32-vs17-x64\php.exe'

# DB_DATABASE overrides require config cache to be cleared.
& $php artisan config:clear

$env:DB_DATABASE = 'pnlcs_wtptest_target'
& $php artisan migrate --force
& $php docs/whmcs-to-pnlcs/synthetic_source.php
& $php docs/whmcs-to-pnlcs/verify.php
Remove-Item Env:\DB_DATABASE

& $php artisan config:cache
& $php artisan route:cache
```

Expected result: **79 passed, 0 failed**.

The scripts refuse unsafe targets:

- `verify.php` reads the application database from `.env` (or `WTP_APP_DB`) and refuses to write there unless `WTP_ALLOW_APP_DB=1`.
- `synthetic_source.php` refuses to drop the application database unless `WTP_ALLOW_APP_DB=1`.
- `WTP_TEST_SOURCE_DB` can rename the disposable source database.

The suite covers connection diagnostics, cPanel grant recognition, TLS fail-closed behaviour, source timezone detection, catalog schema validation, dry-run purity, real import, ID mappings, reference resolution, idempotency, archive, pause/resume, failed-run visibility, reconciliation, reports, and absence of email/provisioning/activity-log side effects. It also covers real-source edge cases found during the first live run: blank client names, over-long phone numbers, reused transaction references, zero dates, blank ticket-reply bodies, WHMCS text flags, and distinct services sharing a client/domain/product name. Later additions cover blank invoice numbers with row-ID fallback, non-default product currency prices with a derived shop-currency copy, currency conversion of service prices, and protection of an existing shop-currency price.

## Real-source repair notes

After a dry run completes with `SCHEMA_ISSUES=[]`, the first real import can still archive rows whose source references are genuinely orphaned. A live run on the supplied WHMCS source produced these final counts: 108 clients, 29 products, 316 services, 1 domain, 13 tickets, 19 ticket replies, 6 ticket notes, and 525 transactions. Ten source transactions reference client IDs that do not exist in WHMCS (`10, 11, 14, 21, 23, 24, 27, 115, 116, 117`); they are kept in the read-only archive with the reason and are intentionally not imported with a fabricated client. Service and transaction mappings are one-to-one with their target rows so no distinct source record can collapse onto another.

A later review found two live-data gaps caused by the first importer version: 694 source invoice mappings collapsed onto 105 target invoices because blank `invoicenum` values were used as the key, and product prices were attached to WHMCS currency 2 (`USD-whmcs-2`) while the PNLCS default currency is INR, so the admin product form showed no price. The fixes above are verified only against the disposable synthetic source/target suite. Repairing live data requires restoring read-only WHMCS source access for the runner's IP and taking a fresh full target backup before any destructive invoice rebuild; the old mappings alone cannot reconstruct the collapsed invoice rows or their dependent order/invoice-item/transaction/credit references.

## Administrative rule

This utility is a migration tool, not a go-live switch. A real import must not be started until an authorised administrator has:

1. reviewed the source/destination analysis and warnings;
2. completed a successful dry run;
3. checked reconciliation and reports;
4. explicitly approved the real run.
