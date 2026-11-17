<?php

// Included by the standalone importer runner. SQLite :memory: only.
use App\Services\WhmcsImport\HistoryFinance;

check($store->profileSchemaInstalled(), 'profile integration migration installs access index and links');
$store->linkClient('synthetic', '41', 1001, 9);
$store->linkClient('synthetic', '41', 1001, 9);
check($connection->table('whmcs_history_client_links')->count() === 1, 'explicit native customer association is idempotent');
rejects(fn () => $store->linkClient('synthetic', '41', 1002, 9), 'source customer cannot silently move to another native customer');
rejects(fn () => $store->linkClient('synthetic', '404', 1002, 9), 'unknown archived customer cannot be adopted');
check($store->profileRecords(1002)->count() === 0, 'unmapped native customer cannot read another customer history');
check($store->profileRecords(1001)->where('source_table', 'tblticketnotes')->count() === 1, 'ticket internal note inherits authoritative ticket owner');
check($store->profileRecords(1001)->where('source_table', 'tblticketreplies')->count() === 2, 'staff reply with userid zero belongs to ticket customer');
check($store->profileRecords(1001)->where('source_table', 'tblusers')->count() === 1, 'linked login user appears in mapped profile');
check($store->profileRecords(1001)->where('source_key', 'other-source')->count() === 0, 'profile scope does not leak unrelated sources');
check($connection->table('whmcs_history_runs')->where('id', $report['run_id'])->value('report') !== null, 'reconciliation report persists with run');

$finance = new HistoryFinance($store);
$invoice = $store->find('synthetic', 'tblinvoices', '81');
$evidence = $finance->invoice(1001, $invoice);
check($evidence['item_amount_sum'] === '100.00' && $evidence['item_count'] === 1, 'invoice evidence reconciles exact linked line-item sum');
check($evidence['transactions_by_explicit_currency']['1']['amountin'] === '100.00', 'multiple payment evidence remains exact and grouped by explicit currency');
check($evidence['transactions_by_explicit_currency']['1']['fees'] === '0.90', 'fees preserved without subtracting them from payments');
check($evidence['invoice_currency'] === 'unknown_not_in_invoice_row', 'current client currency is not invented as historical invoice currency');
rejects(fn () => $finance->invoice(1002, $invoice), 'financial evidence enforces native profile scope');
$refund = $finance->invoice(1001, $store->find('synthetic', 'tblinvoices', '83'));
check($refund['transactions_by_explicit_currency']['1']['net_observed_flow'] === '0.00', 'refund outflow and payment produce exact observed net zero');
$partial = $finance->invoice(1001, $store->find('synthetic', 'tblinvoices', '82'));
check($partial['original_credit'] === '20.00' && ! isset($partial['amount_due']), 'credit evidence is preserved without asserting an unverified balance');

$source->data = $fixture;
$reverse = $importer->run($source, 'reverse-order', 'snapshot', array_reverse($categories), false);
$store->linkClient('reverse-order', '41', 1003, 9);
check($store->profileRecords(1003)->where('source_table', 'tblticketnotes')->count() === 1, 'profile owner resolution is independent of category order');
check($store->profileRecords(1003)->where('source_table', 'tblusers')->count() === 1, 'reverse-order imports still associate users through account links');

$source->data = $fixture;
$source->data['tblclients'][] = array_replace($fixture['tblclients'][0], ['id' => 42, 'email' => 'other@example.invalid']);
$source->data['tblusers_clients'][] = ['id' => 72, 'auth_user_id' => 61, 'client_id' => 42, 'owner' => 0];
$source->data['tblinvoiceitems'][0]['userid'] = 42;
$source->data['tblaccounts'][0]['userid'] = 42;
$conflict = $importer->run($source, 'ownership-conflict', 'snapshot', $categories, false);
$store->linkClient('ownership-conflict', '41', 1004, 9);
$store->linkClient('ownership-conflict', '42', 1005, 9);
check($conflict['profile_ownership']['withheld'] === 2, 'cross-customer financial owners are withheld and reported');
check($conflict['state'] === 'partial', 'withheld ownership conflicts prevent a successful archive disposition');
foreach ([1004, 1005] as $nativeClient) {
    check($store->profileRecords($nativeClient)->where('source_table', 'tblusers')->count() === 1, 'shared WHMCS login appears under each verified linked account');
    check(! $store->profileRecords($nativeClient)->where('source_table', 'tblinvoiceitems')->where('source_id', '91')->exists(), 'conflicted invoice line is not disclosed under either account');
    check(! $store->profileRecords($nativeClient)->where('source_table', 'tblaccounts')->where('source_id', '101')->exists(), 'conflicted transaction is not disclosed under either account');
}
$store->rebuildOwnership('ownership-conflict');
check($store->profileRecords(1004)->where('source_table', 'tblusers')->count() === 1, 'rebuilding ownership does not duplicate shared-user access');
$source->data = $fixture;

require __DIR__.'/attachments.php';
require __DIR__.'/profile-http.php';
require __DIR__.'/migrations.php';
