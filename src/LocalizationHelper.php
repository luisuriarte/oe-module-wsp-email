<?php
/**
 * LocalizationHelper — Provides locale-aware formatting and translatable fallback strings
 * for notification channels in OpenEMR.
 *
 * @package   OpenEMR\Modules\WspEmail
 */

namespace OpenEMR\Modules\WspEmail;

class LocalizationHelper
{
    public static function translate(string $text): string
    {
        if (function_exists('xlt')) {
            return \xlt($text);
        }

        return $text;
    }

    public static function currentLanguageTag(): string
    {
        $lang = $GLOBALS['language_default'] ?? $GLOBALS['language_choice'] ?? 'en';
        $lang = strtolower((string)$lang);

        if (strpos($lang, '_') !== false) {
            $lang = str_replace('_', '-', $lang);
        }

        return preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $lang) ? $lang : 'en';
    }

    public static function formatAppointmentDate(string $date, string $time = '00:00:00'): string
    {
        $timestamp = strtotime(trim($date . ' ' . $time));
        if (!$timestamp) {
            return trim($date . ' ' . $time);
        }

        $localeCandidates = self::buildLocaleCandidates();

        if (class_exists('\IntlDateFormatter')) {
            foreach ($localeCandidates as $locale) {
                $formatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::FULL,
                    \IntlDateFormatter::NONE,
                    date_default_timezone_get() ?: 'UTC',
                    \IntlDateFormatter::GREGORIAN,
                    'EEEE dd MMMM yyyy'
                );

                if ($formatter) {
                    $formatted = $formatter->format($timestamp);
                    if ($formatted !== false) {
                        return $formatted;
                    }
                }
            }
        }

        return date('Y-m-d', $timestamp);
    }

    private static function buildLocaleCandidates(): array
    {
        $langTag = self::currentLanguageTag();
        $primary = explode('-', $langTag)[0];

        return array_values(array_unique([
            $langTag,
            str_replace('-', '_', $langTag),
            $primary,
            'en_US',
            'en'
        ]));
    }
}
