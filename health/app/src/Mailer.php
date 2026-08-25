<?php
declare(strict_types=1);

namespace Health;

/**
 * Dünner Wrapper um PHP mail(). Kein SMTP, keine Warteschlange – für
 * die überschaubare Zahl an Termin-Erinnerungen genügt das. Auf World4You
 * läuft mail() über den hauseigenen Mailrelay, ohne dass eigene
 * SMTP-Zugangsdaten hinterlegt werden müssten.
 */
final class Mailer
{
    public function __construct(private App $app) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $from = $this->app->config['app']['mail_from'] ?? null;
        if (!$from) {
            error_log('[health] mail_from ist nicht konfiguriert – E-Mail nicht gesendet.');
            return false;
        }

        $headers = [
            'From: ' . $this->app->config['app']['name'] . ' <' . $from . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Health-Dashboard',
        ];

        // mb_encode_mimeheader statt rohem UTF-8 im Subject – nicht jeder
        // Mailrelay ist bei Kopfzeilen zuverlässig 8-bit-sauber.
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    }
}
