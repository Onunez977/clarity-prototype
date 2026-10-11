<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use Illuminate\Database\Connection as BaseConnection;

class Connection extends BaseConnection
{
    public const DEFAULT_NAME = "clickhouse";

    protected ClickhouseHttpClient $client;

    public function getClient(): ClickhouseHttpClient
    {
        return $this->client;
    }

    /**
     * @param array $config
     * @return static
     */
    public static function createWithClient(array $config): static
    {
        $conn = new static(null, $config["database"], "", $config);
        $conn->client = new ClickhouseHttpClient($config);

        return $conn;
    }

    /** @inheritDoc */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** @inheritDoc */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /** @inheritDoc */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /** @inheritDoc */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->client->select($query, $bindings);
    }

    /** @inheritDoc */
    public function statement($query, $bindings = []): bool
    {
        return $this->client->write($query, $bindings);
    }

    /** @inheritDoc */
    public function affectingStatement($query, $bindings = []): int
    {
        return (int) $this->statement($query, $bindings);
    }

    /**
     * Escape a string value without relying on PDO.
     *
     * @param  string  $value
     * @return string
     */
    protected function escapeString($value)
    {
        $value = str_replace("'", "''", $value);

        return "'{$value}'";
    }
}
