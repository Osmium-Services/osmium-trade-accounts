<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Where customer logos live and which types are allowed. Pure: no files are touched here.
 */
final class TradeLogo
{
    public const DIR = '/assets/images/trade/';
    /** @var list<string> Extensions accepted, checked by core's ImageUpload */
    public const TYPES = ['jpg', 'png', 'gif', 'webp', 'svg'];

    /** @return string The site-relative address of a stored logo, or '' when there is none */
    public static function url(?string $filename): string
    {
        $none = $filename === null || $filename === '';
        if ($none) return '';

        return self::DIR . \basename($filename);
    }
}
