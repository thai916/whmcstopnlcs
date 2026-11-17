<?php

namespace App\Services\WhmcsImport;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Explicit operator-verified mappings only. No WHMCS attachment convention,
 * delimiter, serialized payload, source disk or timezone is guessed.
 */
final class HistoryAttachments
{
    private const FIELDS = [
        'tblclientsfiles' => ['filename'], 'tbltickets' => ['attachment'],
        'tblticketreplies' => ['attachment'], 'tblticketnotes' => ['attachments'],
        'tblemails' => ['attachments'],
    ];

    public function __construct(
        private HistoryStore $store,
        private Connection $db,
        private Encrypter $encryption,
        private string $privateRoot,
    ) {
    }

    /** Dry-run returns a plan and never creates directories, files or DB rows. */
    public function transfer(array $mapping, string $sourceRoot, bool $verifiedStorage, bool $dryRun = true): array
    {
        $this->store->assertIsolated();
        if (! $verifiedStorage) {
            throw new RuntimeException('Source storage mapping must be explicitly verified.');
        }
        foreach (['record_id', 'field', 'relative_path', 'reference_hash', 'sha256', 'download_name'] as $key) {
            if (! isset($mapping[$key]) || (! is_string($mapping[$key]) && ! ($key === 'record_id' && is_int($mapping[$key])))) {
                throw new RuntimeException('Incomplete attachment mapping.');
            }
        }
        foreach (['reference_hash', 'sha256'] as $key) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $mapping[$key])) {
                throw new RuntimeException('Expected SHA-256 evidence is required.');
            }
        }
        if (! preg_match('/^[1-9][0-9]*$/D', (string) $mapping['record_id'])
            || strlen($mapping['download_name']) > 200
            || $mapping['download_name'] === ''
            || preg_match('~[\\\\/:\x00-\x1f\x7f]~', $mapping['download_name'])
            || in_array($mapping['download_name'], ['.', '..'], true)) {
            throw new RuntimeException('Invalid attachment identity or download name.');
        }
        $record = $this->db->table('whmcs_history_records')->where('id', $mapping['record_id'])->first();
        if (! $record || ! in_array($mapping['field'], self::FIELDS[$record->source_table] ?? [], true)) {
            throw new RuntimeException('Unreviewed attachment field.');
        }
        $json = $this->store->decode($record);
        if (! hash_equals($record->checksum, hash('sha256', $json))) {
            throw new RuntimeException('Archive checksum mismatch.');
        }
        $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! isset($row[$mapping['field']]) || ! is_string($row[$mapping['field']])
            || $row[$mapping['field']] === ''
            || ! hash_equals($mapping['reference_hash'], hash('sha256', $row[$mapping['field']]))) {
            throw new RuntimeException('Attachment reference changed or is not a raw string.');
        }
        $source = $this->sourcePath($sourceRoot, $mapping['relative_path']);
        $bytes = filesize($source);
        if ($bytes === false || ! hash_equals($mapping['sha256'], hash_file('sha256', $source))) {
            throw new RuntimeException('Source attachment checksum differs from verified manifest.');
        }
        $plan = ['record_id' => (int) $record->id, 'bytes' => $bytes, 'sha256' => $mapping['sha256'], 'state' => 'would_copy'];
        if ($dryRun) {
            return $plan;
        }
        if (! $this->db->getSchemaBuilder()->hasTable('whmcs_history_attachments')) {
            throw new RuntimeException('Attachment manifest schema is not installed.');
        }
        $this->preparePrivateRoot();
        $identity = ['record_id' => $record->id, 'field' => $mapping['field'], 'path_hash' => hash('sha256', $mapping['relative_path'])];
        $this->db->table('whmcs_history_attachments')->insertOrIgnore($identity + [
            'source_path' => $this->encryption->encryptString($mapping['relative_path']),
            'reference_hash' => $mapping['reference_hash'], 'sha256' => $mapping['sha256'],
            'bytes' => $bytes, 'download_name' => $mapping['download_name'], 'state' => 'planned',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $attachment = $this->db->table('whmcs_history_attachments')->where($identity)->first();
        if (! $attachment || $attachment->sha256 !== $mapping['sha256'] || (int) $attachment->bytes !== $bytes
            || $attachment->reference_hash !== $mapping['reference_hash'] || $attachment->download_name !== $mapping['download_name']
            || $this->encryption->decryptString($attachment->source_path) !== $mapping['relative_path']) {
            throw new RuntimeException('Attachment mapping conflicts with saved manifest.');
        }
        $destination = $this->destination($attachment);
        $lockPath = $destination.'.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Attachment lock cannot be a symlink.');
        }
        $lock = fopen($lockPath, 'c+b');
        if (! $lock) {
            throw new RuntimeException('Cannot open attachment lock.');
        }
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire attachment lock.');
            }
            if (file_exists($destination) || is_link($destination)) {
                $this->verifyCopied($attachment, $destination);
            } else {
                $temporary = $destination.'.part-'.bin2hex(random_bytes(12));
                $output = fopen($temporary, 'x+b');
                $input = fopen($source, 'rb');
                if (! $input || ! $output) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('Cannot open attachment transfer streams.');
                }
                try {
                    if (! chmod($temporary, 0600)) {
                        throw new RuntimeException('Cannot restrict private attachment permissions.');
                    }
                    // Bounded by the operator's verified file size; a growing
                    // source cannot fill destination storage indefinitely.
                    $copied = stream_copy_to_stream($input, $output, $bytes + 1);
                    if (! fflush($output) || ! fsync($output)) {
                        throw new RuntimeException('Cannot flush attachment bytes safely.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($copied !== $bytes) {
                    throw new RuntimeException('Attachment changed during transfer.');
                }
                $this->verifyCopied($attachment, $temporary);
                if (! rename($temporary, $destination)) {
                    throw new RuntimeException('Cannot publish verified attachment.');
                }
                $temporary = null;
            }
            $this->db->table('whmcs_history_attachments')->where('id', $attachment->id)
                ->update(['state' => 'copied', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        } finally {
            if ($temporary !== null && is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return array_replace($plan, ['attachment_id' => (int) $attachment->id, 'state' => 'copied', 'disposition' => 'copied']);
    }

    public function forRecord(int $record): array
    {
        if (! $this->db->getSchemaBuilder()->hasTable('whmcs_history_attachments')) {
            return [];
        }

        return $this->db->table('whmcs_history_attachments')->where('record_id', $record)
            ->where('state', 'copied')->orderBy('id')->get(['id', 'download_name', 'bytes', 'sha256'])->all();
    }

    /** Controller must authorize the parent through profileRecords first. */
    public function download(int $record, int $id): array
    {
        $attachment = $this->db->table('whmcs_history_attachments')->where('record_id', $record)
            ->where('id', $id)->where('state', 'copied')->first();
        if (! $attachment) {
            throw new RuntimeException('Attachment is not available.');
        }
        $this->assertPrivateRoot();
        $path = $this->destination($attachment);
        $this->verifyCopied($attachment, $path);

        return ['path' => $path, 'name' => $attachment->download_name];
    }

    private function sourcePath(string $root, string $relative): string
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        if ($root === '' || (! str_starts_with($normalizedRoot, '/') && ! preg_match('~^[A-Za-z]:/~', $normalizedRoot))) {
            throw new RuntimeException('Verified source root must be an explicit absolute directory.');
        }
        $parts = explode('/', $relative);
        if ($relative === '' || preg_match('~[\\\\:\x00-\x1f\x7f]~', $relative)
            || array_intersect($parts, ['', '.', '..']) !== []) {
            throw new RuntimeException('Source path must be a safe relative path.');
        }
        $this->noSymlinks($root);
        $root = realpath($root);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Verified source root is not a directory.');
        }
        $path = $root.'/'.implode('/', $parts);
        $this->noSymlinks($path);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Verified source attachment is missing.');
        }

        return $path;
    }

    private function preparePrivateRoot(): void
    {
        $this->assertPrivateRoot();
        if (! is_dir($this->privateRoot) && ! mkdir($this->privateRoot, 0700, true) && ! is_dir($this->privateRoot)) {
            throw new RuntimeException('Cannot create private archive directory.');
        }
        if (! chmod($this->privateRoot, 0700)) {
            throw new RuntimeException('Cannot restrict archive directory permissions.');
        }
        $this->assertPrivateRoot();
    }

    private function assertPrivateRoot(): void
    {
        $root = str_replace('\\', '/', $this->privateRoot);
        if ((! str_starts_with($root, '/') && ! preg_match('~^[A-Za-z]:/~', $root))
            || preg_match('~/(public|public_html|www|htdocs)(/|$)~i', $root)
            || in_array('..', explode('/', $root), true)) {
            throw new RuntimeException('Archive files must be outside public storage.');
        }
        $this->noSymlinks($this->privateRoot);
    }

    private function noSymlinks(string $path): void
    {
        do {
            if (is_link($path)) {
                throw new RuntimeException('Symlink attachment paths are not supported.');
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        } while ($path !== '' && $path !== '.');
    }

    private function destination(object $attachment): string
    {
        return rtrim($this->privateRoot, '/\\').'/'.hash('sha256', $attachment->id.':'.$attachment->sha256).'.bin';
    }

    private function verifyCopied(object $attachment, string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || filesize($path) !== (int) $attachment->bytes
            || ! hash_equals($attachment->sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('Transferred attachment checksum mismatch.');
        }
    }
}
