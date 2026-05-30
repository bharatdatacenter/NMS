<?php

declare(strict_types=1);

namespace NMS\Core\Models\Notifications;

/**
 * SmtpMailer
 *
 * Minimal, dependency-free SMTP client (fsockopen + AUTH LOGIN). Supports
 * implicit TLS (ssl://, port 465) and STARTTLS (port 587). Kept intentionally
 * small and in-house, matching the codebase convention of hand-rolling network
 * I/O (curl in ZabbixClient / ImsTicketClient) rather than pulling in a library.
 *
 * Throws \RuntimeException with an HTTP-ish code so RetryHandler can classify
 * transient failures (4xx SMTP replies → non-retryable, 5xx / connection →
 * retryable).
 */
final class SmtpMailer
{
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,   // 'tls' | 'ssl' | 'none'
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * Send one message. Returns the SMTP server's final DATA-accept reply line
     * (some servers embed a queue id there) for logging.
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $textBody,
        string $htmlBody = ''
    ): string {
        $this->connect();

        try {
            $this->ehlo();

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                $this->enableCrypto();
                $this->ehlo(); // re-EHLO after TLS upgrade
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $this->sanitizeAddress($fromEmail) . '>', 250);
            $this->command('RCPT TO:<' . $this->sanitizeAddress($toEmail) . '>', 250);
            $this->command('DATA', 354);

            $payload = $this->buildMime($fromEmail, $fromName, $toEmail, $subject, $textBody, $htmlBody);
            // End-of-data terminator.
            $reply = $this->command($payload . "\r\n.", 250);

            $this->command('QUIT', 221);

            return $reply;
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($transport . $this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException(
                "SMTP connection failed to {$this->host}:{$this->port} — {$errstr} ({$errno})",
                500 // connection failure → retryable
            );
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->expect(220); // server greeting
    }

    private function ehlo(): void
    {
        $host = gethostname() ?: 'localhost';
        $this->command('EHLO ' . $host, 250);
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    private function enableCrypto(): void
    {
        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        );

        if ($ok !== true) {
            throw new \RuntimeException('SMTP STARTTLS negotiation failed', 500);
        }
    }

    /**
     * Write a command and assert the reply code.
     * Returns the full reply line.
     */
    private function command(string $command, int $expectedCode): string
    {
        $this->write($command);
        return $this->expect($expectedCode);
    }

    private function write(string $line): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP socket is not open', 500);
        }
        fwrite($this->socket, $line . "\r\n");
    }

    /**
     * Read a (possibly multi-line) SMTP reply and assert its code.
     */
    private function expect(int $expectedCode): string
    {
        $reply = '';
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly', 500);
            }
            $reply .= $line;
            // Continuation lines look like "250-...", final line "250 ...".
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($reply, 0, 3);
        if ($code !== $expectedCode) {
            // 4xx/5xx SMTP codes map to HTTP-ish codes for RetryHandler:
            // 4xx (transient per RFC) → retryable, 5xx (permanent) → not.
            $httpCode = $code >= 400 && $code < 500 ? 503 : 400;
            throw new \RuntimeException(
                "SMTP expected {$expectedCode}, got: " . trim($reply),
                $httpCode
            );
        }

        return trim($reply);
    }

    private function buildMime(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $textBody,
        string $htmlBody
    ): string {
        $boundary = 'nms_' . bin2hex(random_bytes(12));
        $from = $this->encodeHeaderName($fromName) . ' <' . $this->sanitizeAddress($fromEmail) . '>';

        $headers = [
            'From: ' . $from,
            'To: <' . $this->sanitizeAddress($toEmail) . '>',
            'Subject: ' . $this->encodeHeaderValue($subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@nms>',
            'MIME-Version: 1.0',
        ];

        if ($htmlBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body =
                "--{$boundary}\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                $this->encodeBody($textBody) . "\r\n" .
                "--{$boundary}\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                $this->encodeBody($htmlBody) . "\r\n" .
                "--{$boundary}--";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = $this->encodeBody($textBody);
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($body);
    }

    private function encodeBody(string $body): string
    {
        return chunk_split(base64_encode($body), 76, "\r\n");
    }

    /**
     * RFC 5321 dot-stuffing: a line starting with '.' must be doubled so it is
     * not mistaken for the end-of-data terminator.
     */
    private function dotStuff(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }

    private function encodeHeaderValue(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function encodeHeaderName(string $name): string
    {
        $encoded = $this->encodeHeaderValue($name);
        // Quote plain names containing specials.
        if ($encoded === $name && preg_match('/[",;<>@]/', $name) === 1) {
            return '"' . str_replace('"', '', $name) . '"';
        }
        return $encoded;
    }

    /**
     * Strip CR/LF to prevent SMTP header/command injection via addresses.
     */
    private function sanitizeAddress(string $address): string
    {
        return str_replace(["\r", "\n", "\0"], '', trim($address));
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
