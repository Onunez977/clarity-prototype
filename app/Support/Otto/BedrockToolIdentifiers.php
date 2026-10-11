<?php

namespace App\Support\Otto;

final class BedrockToolIdentifiers
{
    public const ID_MAX_LENGTH = 64;

    public const NAME_MAX_LENGTH = 64;

    public static function sanitizeId(string $id): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $id) ?? '';
        $cleaned = trim($cleaned, '_');

        if ($cleaned === '') {
            $cleaned = 'tool';
        }

        return self::fit($cleaned, self::ID_MAX_LENGTH, $id);
    }

    public static function sanitizeName(string $name): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name) ?? '';
        $cleaned = trim($cleaned, '_');

        if ($cleaned === '') {
            $cleaned = 'tool';
        }

        return self::fit($cleaned, self::NAME_MAX_LENGTH, $name);
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public static function sanitizeProviderContentBlocks(array $blocks): array
    {
        return array_map(function (mixed $block): mixed {
            if (! is_array($block)) {
                return $block;
            }

            if (isset($block['toolUse']) && is_array($block['toolUse'])) {
                if (isset($block['toolUse']['toolUseId'])) {
                    $block['toolUse']['toolUseId'] = self::sanitizeId((string) $block['toolUse']['toolUseId']);
                }
                if (isset($block['toolUse']['name'])) {
                    $block['toolUse']['name'] = self::sanitizeName((string) $block['toolUse']['name']);
                }
            }

            if (isset($block['toolResult']) && is_array($block['toolResult'])) {
                if (isset($block['toolResult']['toolUseId'])) {
                    $block['toolResult']['toolUseId'] = self::sanitizeId((string) $block['toolResult']['toolUseId']);
                }
            }

            return $block;
        }, $blocks);
    }

    private static function fit(string $cleaned, int $max, string $original): string
    {
        if (strlen($cleaned) <= $max) {
            return $cleaned;
        }

        $hash = substr(hash('sha256', $original), 0, 8);

        return substr($cleaned, 0, $max - 9).'_'.$hash;
    }
}
