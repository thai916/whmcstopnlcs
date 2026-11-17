<?php

namespace App\Services\WhmcsImport;

/**
 * Compatibility presentation adapter for isolated callers/tests.
 * HTTP access uses WhmcsHistoryController's server-side full-admin authorization,
 * profileRecords scope, and escaped admin.whmcs-history Blade templates instead.
 * Never render decrypted historical HTML/Markdown/BBCode as trusted markup.
 * Internal notes are intentionally available only with explicit administrator access.
 */
final class HistoryArchive
{
    public function __construct(private HistoryStore $store) {}

    public function clientPage(string $source, string $clientId, bool $authorizedAdmin, int $after = 0): string
    {
        if (! $authorizedAdmin) {
            throw new \RuntimeException('Administrator history permission required.');
        }
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section><h2>WHMCS historical read-only data</h2>';
        foreach ($this->store->clientHistory($source, $clientId, $after) as $record) {
            $html .= '<article data-history-id="'.$escape($record->id).'"><h3>'
                .$escape($record->source_table).' #'.$escape($record->source_id).'</h3><pre>'
                .$escape(json_encode(json_decode($this->store->decode($record), true, flags: JSON_THROW_ON_ERROR),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                .'</pre></article>';
        }

        return $html.'</section>';
    }
}
