<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Where customer logos live and what is allowed. Pure: no files are touched here.
 */
final class TradeLogo
{
    public const DIR = '/assets/images/trade/';
    public const MAX_DIMENSION = 400;

    /** @var array<string, string> mime type => file extension */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];

    public static function isAllowedType(string $mimeType): bool
    {
        return isset(self::TYPES[$mimeType]);
    }

    /** @return string The site-relative address of a stored logo, or '' when there is none */
    public static function url(?string $filename): string
    {
        $none = $filename === null || $filename === '';
        if ($none) return '';

        return self::DIR . \basename($filename);
    }

    /** The scale that fits a side of $longest within MAX_DIMENSION, never above 1 (a small logo is not enlarged). */
    public static function scale(int $longest): float
    {
        $fits = $longest <= self::MAX_DIMENSION;
        if ($fits) return 1.0;

        return self::MAX_DIMENSION / $longest;
    }
}
