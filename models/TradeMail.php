<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The words of the two login emails. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
final class TradeMail
{
    /** Where the emailed link points: /trade/verify?t=<token>, with the site's own habit for a trailing slash. */
    public static function linkUrl(string $fqdn, string $token, bool $trailingSlash): string
    {
        return \rtrim($fqdn, '/') . '/trade/verify' . ($trailingSlash ? '/' : '') . '?t=' . $token;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array{logoUrl?: string, logoDarkCss?: string, logoDarkImg?: string} $brand the site's email logo, as BrandLogo::emailVars() gives it
     * @return array{subject: string, html: string}
     */
    public static function link(array $customer, string $url, string $siteName, array $brand = []): array
    {
        return self::compose(
            siteName: $siteName,
            brand: $brand,
            subject: 'Your ' . $siteName . ' trade login link',
            heading: 'Hello ' . self::greetingName($customer),
            paragraphs: [
                'Use the button below to log in to your trade account. It works once and expires in ' . TradeLoginRules::LINK_MINUTES . ' minutes.',
                '<p><a href="' . self::e($url) . '" style="display:inline-block;background-color:#166534;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;font-size:16px">Log in</a></p>',
                'If the button does not work, copy this address into your browser:',
                '<p style="word-break:break-all"><a href="' . self::e($url) . '">' . self::e($url) . '</a></p>',
                'If you did not ask for this, you can ignore this email. Nobody can log in without it.',
            ],
        );
    }

    /**
     * @param array<string, mixed> $customer
     * @param array{logoUrl?: string, logoDarkCss?: string, logoDarkImg?: string} $brand
     * @return array{subject: string, html: string}
     */
    public static function code(array $customer, string $code, string $siteName, array $brand = []): array
    {
        return self::compose(
            siteName: $siteName,
            brand: $brand,
            subject: 'Your ' . $siteName . ' verification code',
            heading: 'Hello ' . self::greetingName($customer),
            paragraphs: [
                'Your verification code is:',
                '<div style="background-color:#f8f8f8;border-radius:8px;padding:20px;text-align:center;margin:0 0 30px 0"><span style="font-size:36px;font-weight:bold;letter-spacing:8px;color:#166534;font-family:monospace">' . self::e($code) . '</span></div>',
                'It expires in ' . TradeLoginRules::CODE_MINUTES . ' minutes. If you did not just try to log in, ignore this email and do not share the code.',
            ],
        );
    }

    /** @param array<string, mixed> $customer */
    private static function greetingName(array $customer): string
    {
        $contact = \trim((string) ($customer['contact_name'] ?? ''));
        $hasContact = $contact !== '';
        if ($hasContact) return $contact;

        return \trim((string) ($customer['company_name'] ?? '')) ?: 'there';
    }

    /**
     * The site's email styling, as the shop and core emails have it: logo band, green heading, grey footer.
     *
     * @param string[] $paragraphs Plain text, or markup when it starts with '<'
     * @param array{logoUrl?: string, logoDarkCss?: string, logoDarkImg?: string} $brand
     * @return array{subject: string, html: string}
     */
    private static function compose(string $siteName, array $brand, string $subject, string $heading, array $paragraphs): array
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $isMarkup = \str_starts_with($paragraph, '<');
            $body .= $isMarkup ? $paragraph : '<p style="color:#666;font-size:16px;line-height:1.5;margin:0 0 20px 0">' . self::e($paragraph) . '</p>';
        }

        $site = self::e($siteName);
        $logoUrl = (string) ($brand['logoUrl'] ?? '');
        $logo = $logoUrl === ''
            ? '<span style="font-size:22px;font-weight:bold;color:#166534">' . $site . '</span>'
            : '<img class="logo-light" src="' . self::e($logoUrl) . '" alt="' . $site . '" style="max-width:220px;height:auto">' . ($brand['logoDarkImg'] ?? '');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">' . ($brand['logoDarkCss'] ?? '') . '</head>'
            . '<body style="margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f4f4f4">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background-color:#ffffff">'
            . '<tr><td class="logo-band" style="padding:30px;text-align:center;background-color:#ffffff;border-bottom:4px solid #166534">' . $logo . '</td></tr>'
            . '<tr><td style="padding:40px 30px"><h2 style="color:#166534;margin:0 0 20px 0;font-size:22px">' . self::e($heading) . '</h2>' . $body . '</td></tr>'
            . '<tr><td style="padding:20px 30px;background-color:#f8f8f8;text-align:center"><p style="color:#999;font-size:12px;margin:0">&copy; ' . $site . ' - This is an automated message, please do not reply.</p></td></tr>'
            . '</table></body></html>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function e(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES);
    }
}
