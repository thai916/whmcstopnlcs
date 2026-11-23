<?php

namespace App\Services\WhmcsToPnlcs;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Strictly read-only connection to a source WHMCS database.
 *
 * Only SELECT / SHOW / information_schema reads are issued, every value is
 * bound through a prepared statement, and identifiers are quoted explicitly.
 * The session is additionally switched to READ ONLY where MySQL allows it.
 * TLS mode is enforced: a failed negotiation is an error, never a downgrade.
 */
class WhmcsConnector
{
    protected PDO $pdo;

    protected string $database;

    protected string $prefix;

    protected string $tlsMode;

    /** @var array<string, mixed> */
    protected array $diagnostics = [];

    public function __construct(array $config)
    {
        $this->database = (string) ($config['database'] ?? '');
        $this->prefix = (string) ($config['prefix'] ?? 'tbl');
        $this->tlsMode = (string) ($config['tls_mode'] ?? 'verified');

        if (! in_array($this->tlsMode, ['verified', 'unverified', 'none'], true)) {
            throw new RuntimeException('Unknown connection security mode.');
        }

        // Mode 3 needs an explicit, per-connection acknowledgement.
        if ($this->tlsMode === 'none' && empty($config['allow_insecure'])) {
            throw new RuntimeException('A standard, unencrypted MySQL connection requires explicit administrator confirmation.');
        }

        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 3306);
        $timeout = max(1, min((int) ($config['timeout'] ?? 10), 120));

        if ($host === '' || $this->database === '') {
            throw new RuntimeException('A database host and name are required.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $this->database);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => $timeout,
        ];

        $caPath = trim((string) ($config['ca_path'] ?? ''));

        if ($this->tlsMode === 'verified') {
            // Setting a verification option makes mysqlnd negotiate TLS. When
            // no custom CA is supplied the platform trust store is used; a
            // certificate problem is always a hard failure, never a downgrade.
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } elseif ($caPath === '') {
                throw new RuntimeException('This PHP build cannot verify MySQL server certificates. Supply a CA certificate path or use an explicitly-confirmed weaker mode.');
            }
            if ($caPath !== '') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            }
            // MYSQL_ATTR_SSL_SERVER_NAME is not available on every build
            // (notably Windows mysqlnd); skip it rather than causing a fatal.
            if (! empty($config['tls_hostname']) && defined('PDO::MYSQL_ATTR_SSL_SERVER_NAME')) {
                $options[PDO::MYSQL_ATTR_SSL_SERVER_NAME] = (string) $config['tls_hostname'];
            }
        } elseif ($this->tlsMode === 'unverified') {
            // TLS is negotiated but peer identity is not checked. This is the
            // shared-hosting "encrypt but don't verify" case; the UI shows an
            // explicit warning before this mode can be selected.
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            if ($caPath !== '') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            }
        }

        try {
            $this->pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
        } catch (PDOException $e) {
            throw new RuntimeException($this->describeConnectionError($e), 0, $e);
        }

        try {
            $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (Throwable) {
            // Best effort: the class API itself never issues a write.
        }

        $this->enforceTls();
    }

    /** Confirm the negotiated security mode and populate diagnostics. */
    protected function enforceTls(): void
    {
        $cipher = $this->status('Ssl_cipher');
        $verifyMode = $this->status('Ssl_version');
        $active = $cipher !== null && $cipher !== '';

        $this->diagnostics['tls_active'] = $active;
        $this->diagnostics['ssl_version'] = $verifyMode;
        $this->diagnostics['ssl_cipher'] = $cipher;
        $this->diagnostics['identity_verified'] = $this->tlsMode === 'verified';

        if ($this->tlsMode !== 'none' && ! $active) {
            throw new RuntimeException('TLS negotiation failed: the connection was not encrypted. Nothing was downgraded; check the host, port and TLS settings.');
        }
    }

    /** Translate driver errors into actionable, credential-free messages. */
    protected function describeConnectionError(PDOException $e): string
    {
        $message = $e->getMessage();
        $code = (int) $e->getCode();

        // SQLSTATE 1045 = access denied, 1049 = unknown database, 2002/2005 = host,
        // 2006 = server gone, 2013 = lost connection. Do not echo the DSN or
        // credentials that PDO may embed in the exception message.
        $needle = match (true) {
            str_contains($message, 'Access denied') || $code === 1045 => 'Remote MySQL access denied: check the username, password and the host this user is allowed from.',
            str_contains($message, 'Unknown database') || $code === 1049 => 'Database not found on this server.',
            str_contains($message, 'Connection refused') || str_contains($message, 'No such file') || $code === 2002 || $code === 2005 => 'Connection failed: check the hostname and port, and whether remote MySQL access is enabled and the port is open.',
            str_contains($message, 'timed out') || $code === 2002 => 'Connection timed out: the host may be unreachable or the port blocked.',
            str_contains($message, 'server certificate') || str_contains($message, 'certificate verify') || str_contains($message, 'SSL') || str_contains($message, 'TLS') => 'TLS negotiation or certificate verification failed. Use a valid CA certificate in verified mode, or select the explicitly-warned unencrypted/without-verification mode.',
            str_contains($message, 'Authentication plugin') || str_contains($message, 'caching_sha2') => 'Unsupported MySQL authentication method on the source server.',
            default => 'Database connection failed: '.$message,
        };

        return $needle;
    }

    public function test(): bool
    {
        $this->pdo->query('SELECT 1');

        return true;
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    protected function status(string $name): ?string
    {
        try {
            $row = $this->pdo->query("SHOW SESSION STATUS LIKE '".$name."'")->fetch();
        } catch (Throwable) {
            return null;
        }

        return $row ? (string) ($row['Value'] ?? '') : null;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<array{name: string, type: string}> */
    public function columns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return array_map(
            fn (array $row) => ['name' => (string) $row['COLUMN_NAME'], 'type' => (string) $row['COLUMN_TYPE']],
            $stmt->fetchAll()
        );
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s LIMIT ?', $this->quoteIdentifier($table)));
        $stmt->bindValue(1, max(1, min($limit, 1000)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(string $table): int
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s', $this->quoteIdentifier($table)));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Decimal-safe aggregate for reconciliation, with identifier quoting. */
    public function sum(string $table, string $column): float
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT COALESCE(SUM(%s),0) FROM %s',
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table)
        ));
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    }

    /** Readability probe that never changes source data. */
    public function readable(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(sprintf('SELECT 1 FROM %s LIMIT 1', $this->quoteIdentifier($table)));
            $stmt->execute();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Read a bounded page ordered by the primary key. Offset-based paging with
     * a stable key keeps each batch small and makes resume exact.
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $table, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 1000));
        $order = $this->orderColumn($table);
        $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($table));
        if ($order !== null) {
            $sql .= sprintf(' ORDER BY %s', $this->quoteIdentifier($order));
        }
        $sql .= ' LIMIT ? OFFSET ?';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Fetch all rows of a small reference table. */
    public function all(string $table): array
    {
        return $this->pdo->query(sprintf('SELECT * FROM %s', $this->quoteIdentifier($table)))->fetchAll();
    }

    /**
     * id => value lookups for a set of ids, bounded by the caller's chunk.
     *
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function columnValues(string $table, array $ids, string $column): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id, %s AS col_value FROM %s WHERE id IN (%s)',
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table),
            $placeholders
        ));
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['id']] = $row['col_value'];
        }

        return $result;
    }

    /** Emails of source clients, keyed by client id. */
    public function clientEmails(array $ids): array
    {
        return $this->columnValues($this->prefix.'clients', $ids, 'email');
    }

    /** Enrich rows for relation resolution in one query per chunk. */
    public function enrich(array &$rows, array $lookups): void
    {
        if ($rows === []) {
            return;
        }

        $maps = [];
        foreach ($lookups as $alias => $lookup) {
            [$table, $column, $rowKey] = $lookup;
            $ids = array_map(fn (array $row) => $row[$rowKey] ?? null, $rows);
            $maps[$alias] = $this->columnValues($table, $ids, $column);
        }

        foreach ($rows as &$row) {
            foreach ($lookups as $alias => $lookup) {
                $rowKey = $lookup[2];
                $row['migrated_'.$alias] = $maps[$alias][(string) ($row[$rowKey] ?? '')] ?? null;
            }
        }
        unset($row);
    }

    protected function orderColumn(string $table): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = ?
                 ORDER BY ORDINAL_POSITION LIMIT 1'
            );
            $stmt->execute([$table, 'PRI']);

            return $stmt->fetchColumn() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Backtick-quote an identifier, doubling embedded backticks. */
    public function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** @return array<string, string> */
    public function serverInfo(): array
    {
        return [
            'mysql_version' => (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'database' => $this->database,
            'security_mode' => $this->tlsMode,
            'tls_active' => ! empty($this->diagnostics['tls_active']),
            'identity_verified' => $this->tlsMode === 'verified',
        ];
    }

    /** Connection fingerprint: never match IDs across unrelated sources. */
    public function fingerprint(array $tables): string
    {
        sort($tables);
        $seed = implode('|', array_merge([
            $this->database,
            $this->serverInfo()['mysql_version'],
        ], array_slice($tables, 0, 40)));

        return hash('sha256', $seed);
    }

    /**
     * Grant inspection. cPanel-created database users are granted at database
     * scope (`GRANT SELECT ON `db`.*`) — that is valid and must be recognised.
     *
     * @return array{database_wide_select: bool, all_privileges: bool, table_select: list<string>, column_select: array<string, list<string>>, inconclusive: bool, raw: list<string>}
     */
    public function grants(): array
    {
        $result = [
            'database_wide_select' => false,
            'all_privileges' => false,
            'table_select' => [],
            'column_select' => [],
            'inconclusive' => false,
            'raw' => [],
        ];

        try {
            $rows = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            // Some hosts forbid SHOW GRANTS for the current user. That is
            // inconclusive, not "missing permissions" — readability is tested
            // separately below.
            $result['inconclusive'] = true;

            return $result;
        }

        foreach ($rows as $grant) {
            $grant = (string) $grant;
            $result['raw'][] = $this->redactGrant($grant);

            if (! preg_match('/^GRANT\s+(?<privs>.+?)\s+ON\s+(?<object>.+?)\s+TO\s/i', $grant, $m)) {
                continue;
            }

            $privs = strtoupper($m['privs']);
            $hasSelect = str_contains($privs, 'SELECT') || str_contains($privs, 'ALL PRIVILEGES');
            $isAll = str_contains($privs, 'ALL PRIVILEGES');
            [$schema, $object] = $this->splitGrantObject($m['object']);

            if ($schema !== null && ! $this->identifierMatches($schema, $this->database)) {
                continue;
            }

            if ($object === '*') {
                if ($isAll) {
                    $result['all_privileges'] = true;
                }
                if ($hasSelect) {
                    $result['database_wide_select'] = true;
                }

                continue;
            }

            if ($object === null) {
                continue;
            }

            if (preg_match('/\(([^)]*)\)/', $privs, $cols)) {
                if ($hasSelect) {
                    $result['column_select'][$object] = array_values(array_filter(array_map('trim', explode(',', $cols[1]))));
                }
            } elseif ($hasSelect) {
                $result['table_select'][] = $object;
            }
        }

        return $result;
    }

    /** Split `db`.`table`, "db"。"table", db.table, or bare `db`.* safely. */
    protected function splitGrantObject(string $object): array
    {
        $object = trim($object);
        if (preg_match('/^(?:`((?:[^`]|``)*)`|([A-Za-z0-9_$*]+))\.(?:`((?:[^`]|``)*)`|([A-Za-z0-9_$*]+))$/u', $object, $m)) {
            $schema = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            $objectPart = isset($m[3]) && $m[3] !== '' ? $m[3] : ($m[4] ?? '*');

            return [str_replace('``', '`', $schema), str_replace('``', '`', $objectPart)];
        }

        if (preg_match('/^(?:`((?:[^`]|``)*)`|([A-Za-z0-9_$]+))$/u', $object, $m)) {
            return [null, str_replace('``', '`', $m[1] !== '' ? $m[1] : $m[2])];
        }

        return [null, null];
    }

    /** MySQL database names may contain `_` wildcards inside quoted grants. */
    protected function identifierMatches(string $pattern, string $name): bool
    {
        if ($pattern === $name) {
            return true;
        }

        // MySQL treats * and _ as wildcards in unquoted grant identifiers.
        $regex = '/^'.str_replace(['\\*', '\\_'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';

        return preg_match($regex, $name) === 1;
    }

    protected function redactGrant(string $grant): string
    {
        return preg_replace("/IDENTIFIED BY (?:PASSWORD )?'[^']*'/i", "IDENTIFIED BY '***'", $grant) ?: $grant;
    }

    /** Read-only timezone diagnostics. */
    public function timezone(): array
    {
        $evidence = [];
        try {
            $row = $this->pdo->query('SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz, NOW() AS db_now, UTC_TIMESTAMP() AS utc_now')->fetch();
            $evidence = $row ?: [];
        } catch (Throwable) {
            $evidence = [];
        }

        $offset = null;
        if (! empty($evidence['db_now']) && ! empty($evidence['utc_now'])) {
            $offset = (int) round((strtotime((string) $evidence['db_now']) - strtotime((string) $evidence['utc_now'])) / 60);
        }

        $configured = [];
        foreach ($this->tables() as $table) {
            if (str_ends_with($table, 'configuration')) {
                try {
                    $stmt = $this->pdo->prepare(sprintf(
                        'SELECT setting, value FROM %s WHERE setting IN (?,?,?,?,?)',
                        $this->quoteIdentifier($table)
                    ));
                    $stmt->execute(['Version', 'TimeZone', 'SystemTimeZone', 'timezone', 'DefaultTimezone']);
                    foreach ($stmt->fetchAll() as $row) {
                        $configured[(string) $row['setting']] = $row['value'];
                    }
                } catch (Throwable) {
                    // Optional table.
                }
            }
        }

        $sourceZone = $configured['TimeZone'] ?? $configured['SystemTimeZone'] ?? $configured['timezone'] ?? $configured['DefaultTimezone'] ?? null;
        $iana = null;
        $verified = false;
        if (is_string($sourceZone) && preg_match('#^[A-Za-z_]+/[A-Za-z_]+$#', trim($sourceZone))) {
            $iana = trim($sourceZone);
            $verified = true;
        }

        return [
            'session_timezone' => $evidence['session_tz'] ?? null,
            'global_timezone' => $evidence['global_tz'] ?? null,
            'database_now' => $evidence['db_now'] ?? null,
            'utc_now' => $evidence['utc_now'] ?? null,
            'offset_minutes' => $offset,
            'source_setting' => $sourceZone,
            'source_iana' => $iana,
            'destination_timezone' => config('app.timezone'),
            'verified' => $verified,
            'warning' => $verified
                ? null
                : 'Exact IANA source timezone could not be determined from the database. Offsets alone do not identify a timezone or its daylight-saving rules.',
        ];
    }

    /** WHMCS version, when the source exposes it. */
    public function version(array $tables): ?string
    {
        foreach ($tables as $table) {
            if (! str_ends_with($table, 'configuration')) {
                continue;
            }
            try {
                $stmt = $this->pdo->prepare(sprintf(
                    'SELECT value FROM %s WHERE setting IN (?,?,?) LIMIT 1',
                    $this->quoteIdentifier($table)
                ));
                $stmt->execute(['Version', 'version', 'WHMCSVersion']);
                $value = $stmt->fetchColumn();
                if ($value !== false && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            } catch (Throwable) {
                // Optional.
            }
        }

        return null;
    }
}
