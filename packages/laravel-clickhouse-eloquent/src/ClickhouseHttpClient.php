<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use DateTimeInterface;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use LaravelClickhouseEloquent\Expressions\InsertExpression;

class ClickhouseHttpClient
{
    private string $baseUrl;
    private string $database;
    private string $username;
    private string $password;
    private int $connectTimeout;
    private int $queryTimeout;
    private int $retries;
    private array $settings;

    public function __construct(array $config)
    {
        $scheme = !empty($config['https']) ? 'https' : 'http';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? ($scheme === 'https' ? 8443 : 8123);

        $this->baseUrl = "{$scheme}://{$host}:{$port}/";
        $this->database = $config['database'] ?? 'default';
        $this->username = $config['username'] ?? 'default';
        $this->password = $config['password'] ?? '';
        $this->connectTimeout = (int) ($config['timeout_connect'] ?? 5);
        $this->queryTimeout = (int) ($config['timeout_query'] ?? 30);
        $this->retries = (int) ($config['retries'] ?? 0);
        $this->settings = $config['settings'] ?? [];
    }

    /**
     * Execute a SELECT query and return rows as associative arrays.
     */
    public function select(string $sql, array $bindings = []): array
    {
        $sql = $this->bindParams($sql, $bindings);
        $response = $this->request($sql . ' FORMAT JSON');
        $json = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ClickhouseException('Failed to decode ClickHouse JSON response: ' . json_last_error_msg());
        }

        return $json['data'] ?? [];
    }

    /**
     * Execute a write query (INSERT, CREATE, ALTER, DROP, etc.).
     */
    public function write(string $sql, array $bindings = []): bool
    {
        $sql = $this->bindParams($sql, $bindings);
        $this->request($sql);

        return true;
    }

    /**
     * Bulk insert rows with explicit column list.
     *
     * @param string $table Table name (may include database prefix like "db.table")
     * @param array[] $rows Array of row arrays (positional values)
     * @param string[] $columns Column names
     */
    public function insert(string $table, array $rows, array $columns = []): bool
    {
        if (empty($rows)) {
            throw new ClickhouseException('Cannot insert empty values');
        }

        $table = $this->quoteTableName($table);
        $sql = 'INSERT INTO ' . $table;

        if (!empty($columns)) {
            $sql .= ' (`' . implode('`,`', $columns) . '`)';
        }

        $sql .= ' VALUES ';

        $rowStrings = [];
        foreach ($rows as $row) {
            $rowStrings[] = '(' . implode(',', array_map([self::class, 'formatInsertValue'], $row)) . ')';
        }
        $sql .= implode(',', $rowStrings);

        return $this->write($sql);
    }

    /**
     * Bulk insert rows from associative arrays.
     *
     * @param string $table Table name
     * @param array[] $rows Array of associative arrays [column => value]
     */
    public function insertAssocBulk(string $table, array $rows): bool
    {
        if (empty($rows)) {
            throw new ClickhouseException('Cannot insert empty values');
        }

        $rows = array_values($rows);

        if (isset($rows[0]) && is_array($rows[0])) {
            $columns = array_keys($rows[0]);
            $values = [];
            foreach ($rows as $row) {
                $values[] = array_values($row);
            }
        } else {
            $columns = array_keys($rows);
            $values = [array_values($rows)];
        }

        return $this->insert($table, $values, $columns);
    }

    /**
     * Replace `?` or `:N` placeholders with escaped binding values.
     *
     * Supports both standard `?` (positional) and legacy `:0`, `:1` (indexed) placeholders.
     * The `:N` format is supported for backward compatibility with raw queries.
     */
    public function bindParams(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        // Detect which placeholder style is used
        $hasQuestionMark = false;
        $hasColonIndex = false;

        // Quick scan outside quotes
        $scanLen = strlen($sql);
        for ($s = 0; $s < $scanLen; $s++) {
            $ch = $sql[$s];
            if ($ch === "'" || $ch === '"') {
                $q = $ch;
                $s++;
                while ($s < $scanLen) {
                    if ($sql[$s] === '\\') {
                        $s++;
                    } elseif ($sql[$s] === $q) {
                        break;
                    }
                    $s++;
                }
                continue;
            }
            if ($ch === '?') {
                $hasQuestionMark = true;
                break;
            }
            if ($ch === ':' && $s + 1 < $scanLen && ctype_digit($sql[$s + 1])) {
                $hasColonIndex = true;
                break;
            }
        }

        // Use :N substitution if detected, otherwise use ? substitution
        if ($hasColonIndex && !$hasQuestionMark) {
            return $this->bindColonParams($sql, $bindings);
        }

        return $this->bindQuestionParams($sql, $bindings);
    }

    /**
     * Replace `?` placeholders positionally.
     */
    private function bindQuestionParams(string $sql, array $bindings): string
    {
        $index = 0;
        $result = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            // Skip quoted strings
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $result .= $char;
                $i++;
                while ($i < $len) {
                    $result .= $sql[$i];
                    if ($sql[$i] === '\\') {
                        $i++;
                        if ($i < $len) {
                            $result .= $sql[$i];
                        }
                    } elseif ($sql[$i] === $quote) {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === '?') {
                if (!array_key_exists($index, $bindings)) {
                    throw new ClickhouseException("Missing binding for placeholder at index {$index}");
                }
                $result .= self::formatValue($bindings[$index]);
                $index++;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Replace `:N` placeholders (legacy indexed format).
     */
    private function bindColonParams(string $sql, array $bindings): string
    {
        $result = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            // Skip quoted strings
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $result .= $char;
                $i++;
                while ($i < $len) {
                    $result .= $sql[$i];
                    if ($sql[$i] === '\\') {
                        $i++;
                        if ($i < $len) {
                            $result .= $sql[$i];
                        }
                    } elseif ($sql[$i] === $quote) {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === ':' && $i + 1 < $len && ctype_digit($sql[$i + 1])) {
                // Read the full number after the colon
                $numStr = '';
                $j = $i + 1;
                while ($j < $len && ctype_digit($sql[$j])) {
                    $numStr .= $sql[$j];
                    $j++;
                }
                $idx = (int) $numStr;
                if (!array_key_exists($idx, $bindings)) {
                    throw new ClickhouseException("Missing binding for placeholder :{$idx}");
                }
                $result .= self::formatValue($bindings[$idx]);
                $i = $j - 1; // skip past the number digits
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Format a value for use in a SQL query (SELECT bindings).
     */
    public static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return "'" . $value->format('Y-m-d H:i:s') . "'";
        }

        if (is_array($value)) {
            return implode(',', array_map([self::class, 'formatValue'], $value));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        throw new ClickhouseException('Unsupported binding value type: ' . get_debug_type($value));
    }

    /**
     * Format a value for INSERT VALUES clause.
     */
    public static function formatInsertValue(mixed $value): string
    {
        if ($value instanceof InsertExpression) {
            return $value->getValue();
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return "'" . $value->format('Y-m-d H:i:s') . "'";
        }

        if (is_array($value)) {
            $formatted = array_map(function ($v) {
                return is_string($v)
                    ? "'" . self::escapeInsertString($v) . "'"
                    : self::formatInsertValue($v);
            }, $value);
            return '[' . implode(',', $formatted) . ']';
        }

        if (is_string($value)) {
            return "'" . self::escapeInsertString($value) . "'";
        }

        throw new ClickhouseException('Unsupported insert value type: ' . get_debug_type($value));
    }

    /**
     * Escape a string for INSERT VALUES (backslash-escape single quotes and backslashes).
     */
    private static function escapeInsertString(string $value): string
    {
        return preg_replace("/([\\\\\'])/", '\\\\$1', $value);
    }

    /**
     * Quote a table name, adding backticks if needed.
     */
    private function quoteTableName(string $table): string
    {
        if (str_contains($table, '`') || str_contains($table, '.')) {
            return $table;
        }

        return '`' . $table . '`';
    }

    /**
     * Build the URL with query parameters for the request.
     */
    private function buildUrl(): string
    {
        $params = [
            'database' => $this->database,
            'max_execution_time' => $this->queryTimeout,
        ];

        foreach ($this->settings as $key => $value) {
            $params[$key] = $value;
        }

        return $this->baseUrl . '?' . http_build_query($params);
    }

    /**
     * Execute an HTTP request to ClickHouse.
     *
     * @throws ClickhouseException on HTTP or ClickHouse errors
     */
    private function request(string $body): string
    {
        $url = $this->buildUrl();
        $attempts = 1 + max(0, $this->retries);
        $lastError = '';

        while ($attempts-- > 0) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->queryTimeout + $this->connectTimeout,
                CURLOPT_HTTPHEADER => [
                    'X-ClickHouse-User: ' . $this->username,
                    'X-ClickHouse-Key: ' . $this->password,
                    'Cache-Control: no-cache',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $lastError = "cURL error: {$curlError}";
                continue;
            }

            if ($httpCode === 200) {
                return (string) $response;
            }

            $lastError = $this->parseClickhouseError((string) $response, $httpCode);

            // Don't retry client errors (4xx)
            if ($httpCode >= 400 && $httpCode < 500) {
                break;
            }
        }

        throw new ClickhouseException($lastError);
    }

    /**
     * Parse a ClickHouse error response into a readable message.
     */
    private function parseClickhouseError(string $response, int $httpCode): string
    {
        // ClickHouse errors follow the pattern: Code: NNN. DB::Exception: message
        if (preg_match('/Code:\s*(\d+).*?DB::Exception:\s*(.+)/s', $response, $matches)) {
            return "ClickHouse error {$matches[1]}: " . trim($matches[2]);
        }

        if (!empty($response)) {
            return "ClickHouse HTTP {$httpCode}: " . trim($response);
        }

        return "ClickHouse HTTP {$httpCode}";
    }
}
