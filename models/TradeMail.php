<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The words of the two login emails. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
final class TradeMail
{
    /** Where the emailed link points: /trade/verify/<token>, with the site's own habit for a trailing slash. */
    public static function linkUrl(string $fqdn, string $token, bool $trailingSlash): string
    {
        return \rtrim($fqdn, '/') . '/trade/verify/' . $token . ($trailingSlash ? '/' : '');
    }

    /**
     * @param array<string, mixed> $customer
     * @return array{subject: string, html: string}
     */
    public static function link(array $customer, string $url, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your ' . $siteName . ' trade login link',
            heading: 'Hello ' . self::greetingName($customer),
            paragraphs: [
                'Use the button below to log in to your trade account. It works once and expires in ' . TradeLoginRules::LINK_MINUTES . ' minutes.',
                '<p><a href="' . self::e($url) . '" style="display:inline-block;background:#222;color:#fff;padding:12px 22px;text-decoration:none;border-radius:4px">Log in</a></p>',
                'If the button does not work, copy this address into your browser:',
                '<p style="word-break:break-all"><a href="' . self::e($url) . '">' . self::e($url) . '</a></p>',
                'If you did not ask for this, you can ignore this email. Nobody can log in without it.',
            ],
        );
    }

    /**
     * @param array<string, mixed> $customer
     * @return array{subject: string, html: string}
     */
    public static function code(array $customer, string $code, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your ' . $siteName . ' verification code',
            heading: 'Hello ' . self::greetingName($customer),
            paragraphs: [
                'Your verification code is:',
                '<p style="font-size:30px;letter-spacing:6px;font-weight:bold;margin:8px 0">' . self::e($code) . '</p>',
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
     * @param string[] $paragraphs Plain text, or markup when it starts with '<'
     * @return array{subject: string, html: string}
     */
    private static function compose(string $siteName, string $subject, string $heading, array $paragraphs): array
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $isMarkup = \str_starts_with($paragraph, '<');
            $body .= $isMarkup ? $paragraph : '<p>' . self::e($paragraph) . '</p>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;max-width:560px">'
            . '<h2 style="margin:0 0 12px">' . self::e($heading) . '</h2>' . $body
            . '<p style="color:#777;margin-top:24px">' . self::e($siteName) . '</p></div>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function e(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES);
    }
}
