<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Logging\LoggingClickhouseHttpClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('clickhouse')]
class ClickhouseLogRedactionTest extends TestCase
{
    public function test_truncate_sql_leaves_a_short_statement_unchanged(): void
    {
        $sql = 'SELECT 1';

        $truncated = LoggingClickhouseHttpClient::truncateSql($sql);

        $this->assertSame($sql, $truncated['sql']);
        $this->assertFalse($truncated['sql_truncated']);
        $this->assertSame(strlen($sql), $truncated['sql_original_length']);
    }

    public function test_truncate_sql_keeps_the_head_and_records_the_omitted_count(): void
    {
        $sql = str_repeat('a', LoggingClickhouseHttpClient::SQL_LIMIT).'TAIL';

        $truncated = LoggingClickhouseHttpClient::truncateSql($sql);

        $this->assertTrue($truncated['sql_truncated']);
        $this->assertSame(strlen($sql), $truncated['sql_original_length']);
        $this->assertSame(
            str_repeat('a', LoggingClickhouseHttpClient::SQL_LIMIT).'…[truncated 4 chars]',
            $truncated['sql'],
        );
    }

    public function test_truncated_head_still_names_a_geohash_query(): void
    {
        $head = 'SELECT geohash FROM zod.public_address '
            .'INNER JOIN clarity.hotlist FINAL '
            .'WHERE org_id IN (1, 469) AND length(plate) >= 3 ';
        $sql = $head.'pointInPolygon(('.str_repeat('1.0,2.0,', 800).'))';

        $this->assertGreaterThan(LoggingClickhouseHttpClient::SQL_LIMIT, strlen($sql));

        $truncated = LoggingClickhouseHttpClient::truncateSql($sql);

        $this->assertTrue($truncated['sql_truncated']);
        $this->assertStringContainsString('public_address', $truncated['sql']);
        $this->assertStringContainsString('hotlist FINAL', $truncated['sql']);
        $this->assertStringContainsString('org_id IN (', $truncated['sql']);
        $this->assertStringContainsString('length(plate) >= 3', $truncated['sql']);
        $this->assertSame(strlen($sql), $truncated['sql_original_length']);
    }

    public function test_redact_sql_masks_credentials_and_keeps_the_plate_and_org(): void
    {
        $sql = 'SELECT plate FROM t WHERE password=hunter2 AND api_key: sk_live '
            ."AND note = 'Bearer abc.def' AND secret: s3cr3t AND plate = 'ABC123' AND org_id = 469";

        $redacted = LoggingClickhouseHttpClient::redactSql($sql);

        $this->assertStringNotContainsString('hunter2', $redacted);
        $this->assertStringNotContainsString('sk_live', $redacted);
        $this->assertStringNotContainsString('abc.def', $redacted);
        $this->assertStringNotContainsString('s3cr3t', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
        $this->assertStringContainsString('ABC123', $redacted);
        $this->assertStringContainsString('org_id = 469', $redacted);
    }

    public function test_redact_bindings_accepts_every_format_value_type(): void
    {
        $stringable = new class
        {
            public function __toString(): string
            {
                return 'password=from-object';
            }
        };

        $redacted = LoggingClickhouseHttpClient::redactBindings([
            null,
            true,
            false,
            7,
            1.5,
            new \DateTimeImmutable('2026-10-05 12:00:00'),
            ['password=nested', 3],
            $stringable,
            'Bearer live-token',
        ]);

        $this->assertFalse($redacted['bindings_truncated']);
        $this->assertSame(9, $redacted['binding_count']);
        $this->assertSame([
            null,
            true,
            false,
            7,
            1.5,
            '2026-10-05 12:00:00',
            ['[redacted]', 3],
            '[redacted]',
            '[redacted]',
        ], $redacted['bindings_redacted']);
    }

    public function test_redact_bindings_caps_items_and_string_length(): void
    {
        $bindings = array_fill(0, 33, 'ok');
        $bindings[0] = str_repeat('p', 250);

        $redacted = LoggingClickhouseHttpClient::redactBindings($bindings);

        $this->assertSame(33, $redacted['binding_count']);
        $this->assertCount(32, $redacted['bindings_redacted']);
        $this->assertTrue($redacted['bindings_truncated']);
        $this->assertSame(200, strlen((string) $redacted['bindings_redacted'][0]));
    }
}
