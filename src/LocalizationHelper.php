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
    private const OPENEMR_LANGUAGE_LOCALE_MAP = [
        'Spanish' => 'es_ES',
        'Spanish (Mexico)' => 'es_MX',
        'Spanish (Colombia)' => 'es_CO',
        'Spanish (Latin American)' => 'es_419',
        'English' => 'en_US',
        'French' => 'fr_FR',
        'German' => 'de_DE',
        'Portuguese' => 'pt_PT',
        'Portuguese (Brazil)' => 'pt_BR',
        'Italian' => 'it_IT',
        'Dutch' => 'nl_NL',
        'Arabic' => 'ar_SA',
        'Chinese Simplified' => 'zh_CN',
        'Japanese' => 'ja_JP',
        'Russian' => 'ru_RU',
    ];

    public static function translate(string $text): string
    {
        if (function_exists('xlt')) {
            return \xlt($text);
        }

        return $text;
    }

    public static function currentLanguageTag(): string
    {
        $lang = self::detectLanguage();
        $lang = strtolower((string)$lang);

        if (strpos($lang, '_') !== false) {
            $lang = str_replace('_', '-', $lang);
        }

        return preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/', $lang) ? $lang : 'en';
    }

    public static function formatAppointmentDate(string $date, string $time = '00:00:00'): string
    {
        $date = trim($date);
        if ($date === '') {
            return trim($date . ' ' . $time);
        }

        $locale = self::currentLocale();

        if (class_exists('\IntlDateFormatter')) {
            $dateObject = \DateTime::createFromFormat('Y-m-d', $date)
                ?: \DateTime::createFromFormat('Y-m-d H:i:s', trim($date . ' ' . $time));

            if ($dateObject instanceof \DateTime) {
                $formatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::FULL,
                    \IntlDateFormatter::NONE,
                    date_default_timezone_get() ?: 'UTC',
                    \IntlDateFormatter::GREGORIAN
                );

                if ($formatter) {
                    $formatted = $formatter->format($dateObject);
                    if ($formatted !== false && $formatted !== '') {
                        return (string)$formatted;
                    }
                }
            }
        }

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

    public static function appointmentAttachmentCaption(string $facilityName = ''): string
    {
        $caption = self::translate('Press the attachment to save your appointment.');

        return trim($facilityName) !== '' ? trim($facilityName) . ': ' . $caption : $caption;
    }

    private static function detectLanguage(): string
    {
        $candidates = [
            $GLOBALS['language_default'] ?? null,
            $GLOBALS['language_choice'] ?? null,
            $GLOBALS['current_language'] ?? null,
            $_SESSION['language_locale'] ?? null,
            $GLOBALS['language_default_locale'] ?? null,
            $_SESSION['language_choice'] ?? null,
            $_SESSION['language_direction'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'en';
    }

    private static function currentLocale(): string
    {
        $sessionLanguage = $_SESSION['language_choice'] ?? $GLOBALS['language_default'] ?? '';
        if (is_string($sessionLanguage) && isset(self::OPENEMR_LANGUAGE_LOCALE_MAP[$sessionLanguage])) {
            return self::OPENEMR_LANGUAGE_LOCALE_MAP[$sessionLanguage];
        }

        $locale = $_SESSION['language_locale'] ?? $GLOBALS['language_default_locale'] ?? '';
        if (is_string($locale) && trim($locale) !== '') {
            return trim($locale);
        }

        $lang = self::currentLanguageTag();
        if (strpos($lang, '-') !== false) {
            [$language, $region] = explode('-', $lang, 2);
            return strtolower($language) . '_' . strtoupper($region);
        }

        return $lang;
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
