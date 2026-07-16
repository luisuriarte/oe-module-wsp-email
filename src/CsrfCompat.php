<?php

/**
 * CsrfCompat — Backward-compatible CSRF helper for OE 8.0.0 and 8.2.0.
 *
 * The CsrfUtils API changed between these versions: the SessionInterface
 * parameter became required. This helper bypasses that incompatibility by
 * reading the private key directly from the $_SESSION superglobal (which
 * works in both versions) and computing tokens with the same algorithm.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2026 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

class CsrfCompat
{
    public static function collectCsrfToken(string $subject = 'default'): string
    {
        $privateKey = $_SESSION['csrf_private_key'] ?? null;
        if (empty($privateKey)) {
            return '';
        }
        return substr(hash_hmac('sha256', $subject, (string) $privateKey), 0, 40);
    }

    public static function verifyCsrfToken(string $token, string $subject = 'default'): bool
    {
        $expected = self::collectCsrfToken($subject);
        if (empty($expected) || empty($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }
}
