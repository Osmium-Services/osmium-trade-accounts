<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Keeps an uploaded spreadsheet on the server between the wizard's steps, under a random token, and deletes it when
 * the import is done or after half an hour. The folder is inside app/, which the web server never serves.
 */
final class TradeImportFile
{
    public const DIRECTORY = 'app/cache/trade-import';
    private const KEEP_SECONDS = 1800;

    /** @return string the token to give back later */
    public static function store(string $uploadedPath, string $extension, string $directory = self::DIRECTORY): string
    {
        $missing = !\is_dir($directory);
        if ($missing) \mkdir(directory: $directory, permissions: 0700, recursive: true);

        self::purgeExpired($directory);

        $token = \bin2hex(\random_bytes(16));
        \copy($uploadedPath, "{$directory}/{$token}.{$extension}");

        return $token;
    }

    /** @return array{path: string, extension: string}|null null when the token is not one we issued or the file is gone */
    public static function find(string $token, string $directory = self::DIRECTORY): ?array
    {
        $malformed = !\preg_match('/^[a-f0-9]{32}$/', $token);
        if ($malformed) return null;

        foreach (['csv', 'xlsx'] as $extension) {
            $path = "{$directory}/{$token}.{$extension}";
            if (\is_file($path)) return ['path' => $path, 'extension' => $extension];
        }

        return null;
    }

    public static function delete(string $token, string $directory = self::DIRECTORY): void
    {
        $found = self::find(token: $token, directory: $directory);
        if ($found !== null) \unlink($found['path']);
    }

    /** Deletes uploads nobody finished with. Called whenever the import page or an upload is used, so personal data does not linger. */
    public static function purgeExpired(string $directory = self::DIRECTORY): void
    {
        foreach (\glob("{$directory}/*.{csv,xlsx}", GLOB_BRACE) ?: [] as $path) {
            $old = \filemtime($path) < \time() - self::KEEP_SECONDS;
            if ($old) \unlink($path);
        }
    }
}
