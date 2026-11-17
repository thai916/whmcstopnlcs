# WHMCS historical archive (staging development)

This extends the existing **Utilities → Import WHMCS** importer. It is not a
replacement importer, and archived records are not operational invoices,
payments, credits, logins, services or tickets.

## Safety boundary

- Use a consistent restored WHMCS backup and a SELECT-only source account.
- Archive, profile-link, ownership-index and attachment writes require an
  isolated database ending in `_whmcs_test` or `_whmcs_staging` (or SQLite
  `:memory:` in the standalone tests). A name is not proof of isolation.
- No production-write override exists. Native financial projection stays disabled.
- Historical writes use the query builder, not operational models, payment
  services, events, provisioning modules, email, or queues.
- **Full administrators only:** archived internal notes and correspondence can
  contain sensitive data. Ordinary `view_clients` or `manage_settings` access
  does not grant archive access. Customer-facing archive access is not enabled.
- Keep the destination application encryption key with database backups.
- Attachment bytes are in private storage, not encrypted by this component.
  Protect and encrypt filesystem backups separately.

## Install and use on an isolated staging copy

Review these three application migrations before applying them on staging:

1. `2026_11_17_000001_create_whmcs_history_tables.php`
2. `2026_11_17_000002_create_whmcs_history_profile_links.php`
3. `2026_11_17_000003_create_whmcs_history_attachments.php`

The first reuses the original historical schema definition under
`app/Services/WhmcsImport/database/`; do not remove that file.
No migration was executed against an application database during development.
If a previous staging operator manually installed the original three tables,
review its migration history before using Laravel's discovery; do not blindly
run the first migration over those existing tables.

1. Save a restored-backup connection in the existing importer.
2. Follow **WHMCS historical archive** from the import index.
3. Supply a stable source identifier and immutable backup identifier. Never
   reuse a source identifier for a different installation. Source identity and
   operator confirmations are not an automated backup-consistency check.
4. Select categories, confirm the source conditions, and run **Dry run** first.
5. On an isolated destination, select **Archive to isolated staging database**.
   Inspect counts, exact totals, conflicts, missing links and ownership results.
6. From the PNLCS customer profile, follow **WHMCS historical archive**.
   Expand **Link an archived WHMCS customer**, independently verify both
   identities, and explicitly link the source/client IDs. Email equality alone
   never creates an association. Links are immutable in this interface.
7. Browse the filtered, keyset-paginated archive. Individual records retain
   source identifiers and raw dates; imported HTML is displayed as text.

Runs persist their reconciliation reports. To resume an interrupted run, supply
its run ID, original source/backup identifiers and identical ordered category
selection. Saved records are rechecked rather than overwritten. A killed worker's
five-minute lease must expire before resumption. Source changes cause conflicts.
The browser import is synchronous; very large staging rehearsals may exceed web
request limits. A background run coordinator is not implemented.

The historical catalog currently supports the supplied schema's `tbl` prefix.
Category dependencies are not automatically expanded. Missing parents are
reported; choose the relevant parent categories. Unowned/ambiguous records stay
encrypted but are withheld from customer profiles. A global unassigned-history
browser and mapping correction workflow are not implemented.

## Financial evidence, not a native ledger

Invoice archive pages show the original invoice number, stored totals/credit,
exact item sums, and payment/fee/outflow totals grouped by explicitly stored
transaction currency. Related item/payment records are linked.
Linked-record previews are capped at 50; evidence totals scan all accessible
matching rows.

These sums do not establish an outstanding balance or verify that all source
items/payments were selected and archived. Invoice currency is not inferred from
the customer's current currency. `refundid` and `tblcredit.relid` are preserved
without asserting allocation semantics. Cross-customer invoice-item and
transaction ownership conflicts are withheld from profile presentation.

Before any future native financial projection, verify:

- WHMCS invoice totals, credit application, fees, refunds, taxes and currencies.
- PNLCS's current gross-total credit semantics and payment balance calculation.
- Timestamp precision: native transactions currently have date-only storage.
- Historical invoice buyer snapshots and invoice numbering.
- Durable billing/retention quarantine across schedulers, payments,
  notifications, provisioning, exports, and deletion workflows.

Do not import history by calling `PaymentService`, invoice payment/refund
endpoints, or invoice Eloquent creation hooks.

## Explicit attachment manifests

No attachment convention is guessed. For independently verified restored
storage, prepare a private JSON array of entries:

```json
[
  {
    "record_id": 123,
    "field": "filename",
    "relative_path": "verified-backup-name.pdf",
    "reference_hash": "<64 lowercase hexadecimal SHA-256 characters>",
    "sha256": "<64 lowercase hexadecimal SHA-256 characters>",
    "download_name": "contract.pdf"
  }
]
```

- `record_id` is the **archive record ID**, not a WHMCS or native customer ID.
- `field` must be a reviewed attachment field: client file `filename`,
  ticket/reply `attachment`, or ticket-note/email `attachments`.
- `reference_hash` is SHA-256 of the exact raw archived field string. It
  verifies the original reference without decoding it.
- `sha256` is the independently supplied checksum of the exact source file.
- Multiple verified files can map to one raw multi-file reference; supply one
  explicit entry per relative path. The importer never parses or unserializes
  the reference to invent that mapping.
- Relative paths cannot traverse directories or use symlink ancestors.

On staging, validate first:

```bash
php artisan whmcs-history:attachments /secure/manifest.json \
  --source-root=/secure/restored-attachments --verified-storage
```

Only after review, repeat with `--commit`. That writes manifest rows and files
under `storage/app/private/whmcs-history`, using opaque storage names, file locks,
bounded streams, checksums and private permissions. A rerun verifies existing
bytes; it never overwrites a conflicting destination. A file published before a
manifest completion update can be recovered on replay.

Completed files appear on the authorized parent archive record. Downloads check
the mapped customer and parent record, recheck checksums, force attachment
disposition and `application/octet-stream`, and disable caching/type sniffing.
Attachments remain potentially hostile: download-only is not malware scanning.

Hard kills can leave private `.part-*` files. No automatic cleanup or retention
job is installed. Inspect/reconcile these against manifests on staging before
manually removing confirmed orphans. Do not discard copied history to resolve
checksum conflicts.

## Tests and remaining validation

The existing runner now includes profile integration and attachment tests from
`tests/WhmcsHistory/`:

```bash
php app/Services/WhmcsImport/tests/run.php
php app/Services/WhmcsImport/tests/run.php /secure/whmcs-schema.sql
```

On the Windows development host enable `pdo_sqlite`, `mbstring`, and `openssl`
with `-d extension=...` as needed.

The runner does not load `.env`, `bootstrap/app.php`, application providers or
the normal MySQL test bootstrap. It uses SQLite memory, a minimal framework
container, actual archive controllers/routes/Blade views with a test-only
layout, synthetic files in a unique temporary directory, and no real source DB.
The normal admin middleware stack and customer profile layout need separate
application/browser testing on isolated staging.

Outstanding production validation includes MySQL/MariaDB migrations and
concurrency, real restored-backup throughput, full web/CSRF/two-factor integration,
storage permissions on the deployment host, representative financial semantics,
attachment-layout evidence, key/DB/file backup restoration, and cutover approval.
Unverified encoded fields remain excluded; no PHP unserialization or speculative
timezone conversion has been added. Broad opaque-payload retention still needs
field-specific security review. Do not describe this as complete native migration.
