<?php

use App\Services\WhmcsImport\HistoryAttachments;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;

$attachmentMigration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_000003_create_whmcs_history_attachments.php';
$attachmentMigration::create($connection->getSchemaBuilder());
$attachmentTemp = (getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'/Temp/opencode' : sys_get_temp_dir()).'/whmcs-attachments-'.bin2hex(random_bytes(6));
mkdir($attachmentTemp.'/restored', 0700, true);
file_put_contents($attachmentTemp.'/restored/verified.bin', 'Synthetic private contract; not a real attachment.');
$fileRecord = $store->find('synthetic', 'tblclientsfiles', '211');
$fileMapping = [
    'record_id' => (int) $fileRecord->id, 'field' => 'filename', 'relative_path' => 'verified.bin',
    'reference_hash' => hash('sha256', 'private-contract.pdf'),
    'sha256' => hash_file('sha256', $attachmentTemp.'/restored/verified.bin'), 'download_name' => 'contract.txt',
];
try {
    $attachmentService = new HistoryAttachments($store, $connection, new Encrypter(random_bytes(32), 'AES-256-CBC'), $attachmentTemp.'/private/archive');
    $planned = $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', true);
    check($planned['state'] === 'would_copy' && ! is_dir($attachmentTemp.'/private'), 'attachment dry-run creates no destination directory');
    check($connection->table('whmcs_history_attachments')->count() === 0, 'attachment dry-run writes no manifest records');
    rejects(fn () => $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', false, false), 'unverified storage mapping cannot transfer files');
    rejects(fn () => $attachmentService->transfer($fileMapping, '', true), 'empty source root cannot fall back to the current directory');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['relative_path' => '../verified.bin']), $attachmentTemp.'/restored', true), 'attachment source traversal is rejected');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['relative_path' => 'C:/private.txt']), $attachmentTemp.'/restored', true), 'absolute source attachment paths are rejected');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['reference_hash' => str_repeat('0', 64)]), $attachmentTemp.'/restored', true), 'mapping must match the raw archived reference checksum');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['sha256' => str_repeat('0', 64)]), $attachmentTemp.'/restored', true), 'source attachment must match independently supplied checksum');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['download_name' => '../bad.txt']), $attachmentTemp.'/restored', true), 'download filename cannot contain traversal');
    rejects(fn () => $attachmentService->transfer(array_replace($fileMapping, ['field' => 'notes']), $attachmentTemp.'/restored', true), 'unreviewed attachment field is rejected');
    $copied = $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', true, false);
    check($copied['state'] === 'copied' && $connection->table('whmcs_history_attachments')->count() === 1, 'verified attachment transfer persists one manifest');
    $again = $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', true, false);
    check($again['attachment_id'] === $copied['attachment_id'] && $connection->table('whmcs_history_attachments')->count() === 1, 'attachment rerun is idempotent');
    $download = $attachmentService->download($fileRecord->id, $copied['attachment_id']);
    check(hash_file('sha256', $download['path']) === $fileMapping['sha256'], 'copied attachment bytes match source checksum exactly');
    check($attachmentService->forRecord($fileRecord->id)[0]->download_name === 'contract.txt', 'only completed attachment manifests appear in archive');
    rejects(fn () => $attachmentService->download($invoice->id, $copied['attachment_id']), 'attachment ID cannot be read under a different parent record');
    $storedPath = $connection->table('whmcs_history_attachments')->value('source_path');
    check(! str_contains($storedPath, 'verified.bin'), 'attachment source path is encrypted at rest');
    $connection->table('whmcs_history_attachments')->where('id', $copied['attachment_id'])->update(['state' => 'planned']);
    $recovered = $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', true, false);
    check($recovered['state'] === 'copied', 'attachment transfer resumes after file publication but before manifest completion');
    file_put_contents($download['path'], 'tampered');
    rejects(fn () => $attachmentService->download($fileRecord->id, $copied['attachment_id']), 'tampered copied attachment cannot be downloaded');
    rejects(fn () => $attachmentService->transfer($fileMapping, $attachmentTemp.'/restored', true, false), 'attachment rerun refuses to overwrite tampered destination bytes');
    $publicService = new HistoryAttachments($store, $connection, new Encrypter(random_bytes(32), 'AES-256-CBC'), $attachmentTemp.'/public/archive');
    rejects(fn () => $publicService->transfer($fileMapping, $attachmentTemp.'/restored', true, false), 'attachment destination cannot be public storage');
} finally {
    // Only this test's uniquely named temporary tree.
    (new Filesystem)->deleteDirectory($attachmentTemp);
}
