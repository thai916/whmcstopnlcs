# WHMCS → PNLCS migration utility

**Version 0.1 · Maintained by Monlam Makhampa**

An independent, auditable WHMCS 8.x → PNLCS admin migration utility. It is a
flat-layout drop-in for an existing PNLCS application: copy the files at the
paths shown below into the PNLCS root, run the four migrations, and clear caches.
The original **Import WHMCS** utility is unchanged and remains available.

> This is a migration tool, not a go-live switch. A real import must only be
> started after an administrator has reviewed the analysis, completed a
> successful dry run, checked reconciliation, and explicitly approved it.

## Hard safety boundaries

- **Read-only source.** The connector exposes only SELECT/read/diagnostic
  methods. A dedicated `SELECT`-only database user is required; cPanel-style
  `GRANT SELECT ON \`db\`.*` is recognised and validated by actually reading.
- **TLS never silently downgrades.** Modes are `verified`, `unverified`, and
  explicit `none`; the first two fail closed without TLS, `none` needs admin
  confirmation.
- **No PNLCS automation.** Writes use the query builder, not Eloquent events:
  no emails, provisioning, suspension, termination, registrar, gateway,
  payment, or refund calls.
- **Credentials are protected.** Source passwords are encrypted at rest and
  excluded from input, logs, views, maps, and exports.
- **Unsupported/sensitive history is archived** in a read-only archive with
  tokens, card data, EPP codes, and password hashes redacted.
- **Imported products and servers are inert.** Products are visible but retired
  and non-orderable; servers are disabled with secrets omitted.
- **Stable identity.** Original WHMCS IDs are persisted through mappings plus
  `invoices.source_invoice_id`, `services.whmcs_service_id`, and
  `transactions.whmcs_transaction_id`, so no distinct source record collapses
  onto another. Blank WHMCS invoice numbers display the source row ID.
- **Price provenance.** Product prices keep their original WHMCS currency row
  and gain a converted shop-currency copy; service prices are converted to the
  shop currency while original values are retained for audit.
- **Failed runs are visible.** Batch exceptions mark the run `failed`, record a
  finish time, and surface the last error in the live progress note.

## Package layout

This repository uses a flat layout — paths are relative to the PNLCS root:

```text
app/Http/Controllers/Admin/WhmcsToPnlcsController.php
app/Models/WhmcsToPnlcs*.php
app/Services/WhmcsToPnlcs/*.php
database/migrations/2026_11_23_00000{1,2,3,4}_*.php
database/migrations/2026_11_24_000001_*.php
docs/whmcs-to-pnlcs/{README.md,synthetic_source.php,verify.php}
lang/en/whmcs_to_pnlcs.php
resources/views/admin/whmcs-to-pnlcs/*.blade.php
resources/views/admin/layouts/app.blade.php
routes/admin.php
VERSION            Extension version (0.1), not the PNLCS version
LICENSE, NOTICE    Upstream attribution and license
```

No `.env`, database exports, customer data, source credentials, Composer
dependencies, or upstream Git history are included.

## Installation

1. Back up the PNLCS application and database first.
2. Copy the files above into the PNLCS root, preserving paths. Review
   `routes/admin.php` and `resources/views/admin/layouts/app.blade.php` against
   local changes before overwriting them.
3. Run `php artisan migrate --force`, then
   `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
4. Open **Utilities → WHMCS to PNLCS** as a full administrator.

## Wizard

1. **Connect** — host, database, prefix, credentials, TLS mode, CA/SNI, timeout.
2. **Source analysis** — version, readable tables, counts, grants, TLS evidence,
   detected timezone with warning/override, and catalog `schema_issues`.
3. **Destination & scope** — existing counts, scope groups, batch size, dry run.
4. **Mapping** — review native vs archive classification and review flags.
5. **Dry run** — processes batches without writing business rows, mappings, or
   record logs.
6. **Migrate** — after approval. The page auto-continues bounded batches until
   completion; Pause/Retry/Cancel stop the loop.
7. **Verify** — reconciliation of counts and financial totals.
8. **Report** — protected CSV/JSON reports: summary, records, mappings,
   financial, warnings.

## Verification

Verification uses a disposable synthetic WHMCS source and a separate target
database. It never needs a live WHMCS connection. Use a PHP binary with
`pdo_mysql`:

```powershell
$php = 'D:\Laragon\bin\php\php-8.4.28-Win32-vs17-x64\php.exe'
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

See [docs/whmcs-to-pnlcs/README.md](docs/whmcs-to-pnlcs/README.md) for the full
operating notes, including real-source repair observations.

## Author and license

Extension maintainer: **Monlam Makhampa**.

Based on the existing [PNLCS](https://github.com/Panelica/pnlcs) WHMCS importer
by Panelica. Distributed under the MIT license; see [LICENSE](LICENSE) and
[NOTICE](NOTICE). This project is not presented as an official Panelica release.
