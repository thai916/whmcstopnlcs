<?php

namespace App\Console\Commands;

use App\Services\WhmcsImport\HistoryAttachments;
use Illuminate\Console\Command;

class WhmcsHistoryAttachments extends Command
{
    protected $signature = 'whmcs-history:attachments
        {manifest : Private JSON array of explicit attachment mappings}
        {--source-root= : Verified attachment directory in a restored backup}
        {--verified-storage : Confirm the manifest paths and reference mappings were independently verified}
        {--commit : Copy verified attachments; otherwise dry-run only}';

    protected $description = 'Validate or transfer explicitly mapped WHMCS attachments on isolated staging only';

    public function handle(HistoryAttachments $attachments): int
    {
        try {
            if (! $this->option('verified-storage') || ! is_string($this->option('source-root')) || $this->option('source-root') === '') {
                throw new \RuntimeException('Verified storage is required.');
            }
            $path = $this->argument('manifest');
            if (! is_file($path) || filesize($path) > 4 * 1024 * 1024) {
                throw new \RuntimeException('Manifest must be a bounded private file.');
            }
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! array_is_list($manifest) || count($manifest) > 1000) {
                throw new \RuntimeException('Expected a bounded list of explicit mappings.');
            }
            $failed = 0;
            foreach ($manifest as $index => $mapping) {
                try {
                    $result = $attachments->transfer($mapping, $this->option('source-root'), true, ! $this->option('commit'));
                    // No source paths, original names or archived payloads in logs.
                    $this->line('Entry '.$index.': '.($result['disposition'] ?? $result['state']));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error('Entry '.$index.': rejected; review verified mapping, reference and checksums.');
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Attachment operation rejected. Verify the private manifest, restored storage root and isolated destination. No automatic filename decoding is available.');

            return self::FAILURE;
        }
    }
}
