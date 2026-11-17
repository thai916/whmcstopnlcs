# WHMCS to PNLCS

**Version 0.1 · Maintained by Monlam Makhampa**

Improved WHMCS import utility for PNLCS: secure historical archives,
customer-profile integration, exact financial evidence, and verified attachment
migration.

> **Staging development release — not production-ready.** Native financial
> writes remain disabled. This repository extends PNLCS's existing importer;
> it is not a standalone application or a replacement importer.

## Features

- Existing client, hosting-service, and domain importer included.
- Historical field allowlists for 39 WHMCS categories.
- Encrypted historical archive with source-ID mapping, dry runs, idempotence,
  checkpoints, conflict detection, and reconciliation reports.
- Full-administrator archive console and customer-profile access.
- Explicit source/customer associations, shared-user ownership, safe pagination,
  and cross-customer access checks.
- Exact decimal financial evidence preserving original identifiers and raw dates,
  without activating invoice collection, payment, refund, or provisioning actions.
- Operator-verified attachment manifests, checksummed private transfers,
  resumability, and authorized download-only access.
- Synthetic migration, controller/view, migration-schema, and attachment tests.

## Compatibility

- PHP 8.4 and an existing PNLCS application with its Composer dependencies.
- Developed against PNLCS `1.28.1-dev`, upstream commit
  `29e9e331690bbd1cdbf0b68a295b5808518889c3`.
- Historical catalog validated against a WHMCS 8.8 schema with 173 tables and
  the `tbl` prefix.
- Archive writes require an isolated destination database named with the suffix
  `_whmcs_test` or `_whmcs_staging` (or SQLite memory for synthetic tests).

## Package layout

```text
overlay/             Extension files, using paths relative to the PNLCS root
integration.patch    Focused changes to existing PNLCS provider/routes/views
extension.json       Release metadata
VERSION              Extension version (not the PNLCS application version)
NOTICE               Upstream attribution
```

No `.env`, database exports, customer data, source credentials, attachment bytes,
Composer dependencies, upstream Git history, or unrelated local changes are
included.

## Installation: isolated staging only

1. Back up and verify an isolated PNLCS staging copy. Review the code and
   migrations before use. Do not install directly on a live billing system.
2. From that PNLCS root, verify that the integration patch matches:

   ```bash
   git apply --check /path/to/whmcstopnlcs/integration.patch
   ```

3. Review differences before copying the contents of `overlay/` into the PNLCS
   root. Some files extend existing importer classes: preserve local changes and
   merge manually if necessary. Never copy the extension `VERSION` over PNLCS's.
4. Apply `integration.patch` from the PNLCS root. If the target application
   differs from the documented upstream base, review and merge the patch rather
   than forcing it.
5. Review and apply only the archive migrations on the isolated staging
   database. Do not blindly run all pending application migrations.
   If archive tables were manually installed previously, reconcile migration
   history before applying them.
6. As a full administrator, open **Utilities → Import WHMCS → WHMCS historical
   archive**. Use a consistent restored WHMCS backup and a SELECT-only account.
   Start with a dry run.

Read the [operating guide](overlay/docs/guides/whmcs-historical-archive.md) before
importing or linking any customer history. The existing legacy native importer
does not inherit all historical-archive isolation safeguards.

## Tests

After integration, run from the PNLCS staging root:

```bash
php app/Services/WhmcsImport/tests/run.php
php app/Services/WhmcsImport/tests/run.php /secure/whmcs-schema.sql
```

Requires PDO SQLite, mbstring and OpenSSL. The tests use SQLite memory and a
minimal framework container, not the normal application/MySQL bootstrap.
Synthetic files and view caches are confined to unique temporary directories.

Development validation: **112 assertions** without a source schema;
**171 assertions** with the original schema. The source schema itself is not
distributed. MySQL/MariaDB concurrency, full application middleware/browser
integration, and real restored-backup behavior still require staging validation.

## Deliberate limitations

- No native historical invoice/payment/credit/refund projection.
- No speculative credit/refund allocation, historical currency inference,
  opaque PHP payload decoding, or timezone conversion.
- No automatic attachment filename-convention discovery or malware scanning.
- No production-write override, automatic cutover, rollback coordinator, global
  unassigned-history browser, or background import coordinator.
- Development formatting cleanup remains outstanding in some new PHP files.

## Author and license

Extension maintainer: **Monlam Makhampa**.

Based on the existing [PNLCS](https://github.com/Panelica/pnlcs) WHMCS importer
by Panelica. Distributed under the MIT license; see [LICENSE](LICENSE) and
[NOTICE](NOTICE). This project is not presented as an official Panelica release.
