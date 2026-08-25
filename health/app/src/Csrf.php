<?php
declare(strict_types=1);

namespace Health;

final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function check(): bool
    {
        return self::checkToken((string)($_POST['_csrf'] ?? ''));
    }

    /**
     * Wie check(), aber mit explizit übergebenem Token statt $_POST –
     * für Endpunkte, die JSON statt eines Formulars empfangen (z.B.
     * die Push-Anmeldung, die der Browser per fetch() sendet).
     */
    public static function checkToken(string $sent): bool
    {
        return $sent !== ''
            && !empty($_SESSION[self::KEY])
            && hash_equals($_SESSION[self::KEY], $sent);
    }

    /** Bricht die Anfrage bei ungültigem Token ab. */
    public static function requireValid(): void
    {
        if (!self::check()) {
            http_response_code(419);
            exit('Sitzung abgelaufen oder ungültige Anfrage. Bitte Seite neu laden.');
        }
    }

    /** Nach Login/Logout neu würfeln. */
    public static function rotate(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
