<?php

namespace App\Services\WhmcsImport;

/**
 * Diagnostic samples are intentionally not a substitute for original content.
 * All free text is replaced wholesale: regex PII removal is not reliable.
 */
final class SampleSanitizer
{
    public function __construct(private string $key) {}

    public function row(array $row, array $types): array
    {
        $out = [];
        foreach ($row as $field => $value) {
            $type = strtolower($types[$field] ?? '');
            if ($value === null || $value === '') {
                $out[$field] = $value;
            } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|date|datetime|timestamp)\b/', $type)) {
                $out[$field] = $value;
            } elseif (in_array($field, ['permissions', 'attachment', 'attachments'], true)) {
                $out[$field] = $this->structured((string) $value, $field === 'permissions');
            } elseif (in_array($field, ['email', 'to', 'cc', 'bcc'], true)) {
                $out[$field] = 'sample-'.$this->token(strtolower(trim((string) $value))).'@example.invalid';
            } elseif (in_array($field, ['domain', 'hostname'], true)) {
                $out[$field] = 'sample-'.$this->token((string) $value).'.example.invalid';
            } elseif (in_array($field, ['status', 'domainstatus', 'billingcycle', 'type', 'editor', 'urgency', 'paymentmethod', 'gateway', 'code', 'language', 'stage'], true)
                && preg_match('/^[a-zA-Z0-9 _.-]{1,64}$/D', (string) $value)) {
                $out[$field] = $value;
            } elseif (in_array($field, ['invoicenum', 'ordernum', 'transid', 'tid'], true)) {
                $out[$field] = 'ref-'.$this->token((string) $value);
            } else {
                $out[$field] = 'redacted-'.$this->token((string) $value);
            }
        }

        return $out;
    }

    private function token(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->key), 0, 20);
    }

    private function structured(string $value, bool $permissions): string
    {
        // Never unserialize source data. PHP serialization requires a reviewed decoder.
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $clean = $this->tree($decoded, $permissions);

                return json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        } catch (\JsonException) {
            // Retain delimiter layout for common scalar list formats without
            // claiming a verified attachment naming convention.
        }
        $knownPermissions = ['profile', 'contacts', 'products', 'manageproducts', 'domains', 'managedomains',
            'invoices', 'tickets', 'affiliates', 'emails', 'orders'];
        $parts = preg_split('/([,|;])/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_map(function ($part) use ($permissions, $knownPermissions) {
            if (in_array($part, [',', '|', ';'], true)) {
                return $part;
            }
            if ($permissions && in_array($part, $knownPermissions, true)) {
                return $part;
            }

            return 'redacted-'.$this->token($part);
        }, $parts));
    }

    private function tree(array $values, bool $permissions): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $safeKey = is_int($key) ? $key : 'key-'.$this->token($key);
            $out[$safeKey] = is_array($value) ? $this->tree($value, $permissions)
                : (is_bool($value) || $value === null ? $value : 'redacted-'.$this->token((string) $value));
        }

        return $out;
    }
}
