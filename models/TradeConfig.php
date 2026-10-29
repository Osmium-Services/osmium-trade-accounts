<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Trade Accounts settings, read from and saved to this service's own file
 * (app/config/services/osmium-trade-accounts.json.php).
 */
class TradeConfig
{
    public const PATH = 'app/config/services/osmium-trade-accounts.json.php';
    private const HEADER = "<?php exit(); ?>\n";

    private static ?array $config = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'source' => '', // where customers come from: empty means added by hand in admin; otherwise a source id
            'codeRequired' => true, // the emailed 6-digit code after the login link
        ];
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $cached = self::$config !== null;
        if ($cached) return self::$config;

        self::$config = self::read(path: self::PATH);

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /** @return array<string, mixed> The saved settings laid over the defaults; defaults if the file is missing or broken */
    public static function read(string $path): array
    {
        $fileExists = \file_exists($path);
        if (!$fileExists) return self::defaults();

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJson = $jsonStart === false;
        if ($noJson) return self::defaults();

        $decoded = \json_decode(json: \substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['trade'] ?? []) : [];

        return \array_merge(self::defaults(), \is_array($saved) ? $saved : []);
    }

    /** @param array<string, mixed> $values Merged over what is saved; unknown keys are dropped */
    public static function save(array $values, string $path = self::PATH): void
    {
        $merged = \array_merge(self::read(path: $path), $values);
        $known = \array_intersect_key($merged, self::defaults());

        $directory = \dirname($path);
        $missing = !\is_dir($directory);
        if ($missing) \mkdir(directory: $directory, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['trade' => $known],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        \file_put_contents($path, self::HEADER . $json . "\n");

        self::clearCache();
    }

    /** A source id: lower case letters, digits and dashes, so a saved value cannot carry anything else. */
    public static function isValidSource(string $source): bool
    {
        return \preg_match(pattern: '/^[a-z0-9-]{1,40}$/', subject: $source) === 1;
    }
}
