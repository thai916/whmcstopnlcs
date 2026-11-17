<?php

namespace App\Services\WhmcsImport;

/** Read-only contract shared by the connector adapter and synthetic fixtures. */
interface HistorySource
{
    public function tables(): array;

    public function columns(string $table): array;

    public function count(string $table): int;

    /** @return list<array<string, mixed>> */
    public function historyPage(string $table, array $columns, string $after, int $size): array;
}
