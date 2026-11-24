# Changelog

## 0.1 — 2026-11-24 (flat-layout replacement)

- Replace the overlay-based package with an independent, flat-layout
  WHMCS → PNLCS admin migration utility at the PNLCS root paths.
- Keep the original **Import WHMCS** utility unchanged.
- Add read-only source analysis, TLS fail-closed modes, admin approval,
  dry runs, batching, pause/resume, reconciliation, and protected reports.
- Preserve native clients, contacts, groups, products, pricing, services,
  domains, orders, invoices, items, transactions, tickets, replies, notes,
  and servers; archive unsupported or sensitive history with redaction.
- Persist original WHMCS identity for invoices, services, and transactions so
  reused or blank references cannot collapse distinct source records; blank
  WHMCS invoice numbers display the source row ID.
- Keep product prices in their original WHMCS currency and derive a converted
  shop-currency copy using the mapped PNLCS currency rate; convert service
  prices to the shop currency while keeping original amounts for audit.
- Verify with a 79-check disposable synthetic-source regression suite and a
  reviewed real-source import.
- Add a first-page reminder to allowlist the PNLCS server IP on the WHMCS
  database, describe the utility as a vibe-coded improved/extended Import WHMCS
  tool, and expand the README installation instructions.

This release replaces the earlier overlay-based `v0.1` package.

## 0.1 — 2026-11-17

- Package the existing PNLCS importer and historical extension without
  publishing the full application or unrelated workspace changes.
- Add encrypted historical archives, safe-column reconciliation, checkpoints,
  idempotent replay, and source-change conflict handling.
- Integrate full-admin-only archive access with PNLCS customer profiles.
- Add explicit native customer associations and many-to-many ownership indexing.
- Present exact read-only invoice/payment evidence without native financial writes.
- Add verified attachment manifests, private transfers, and scoped downloads.
- Include isolated synthetic, controller/view, migration, and file-transfer tests.

This is a staging development release. See README and the operating guide for
limitations and outstanding production-validation requirements.
