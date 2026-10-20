<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use RuntimeException;

final class Mailer
{
    public function __construct(
        private readonly string $driver,
        private readonly string $from,
        private readonly string $storagePath,
        private readonly array $smtp = []
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $this->from . $to . $subject)) {
            throw new RuntimeException('Alamat atau header email tidak valid.');
        }

        return match ($this->driver) {
            'log' => $this->writeLog($to, $subject, $body),
            'php' => $this->sendPhpMail($to, $subject, $body),
            'smtp' => $this->sendSmtp($to, $subject, $body),
            default => throw new RuntimeException('Mailer belum dikonfigurasi.'),
        };
    }

    private function writeLog(string $to, string $subject, string $body): bool
    {
        $directory = $this->storagePath . '/logs';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Direktori log email tidak dapat dibuat.');
        }
        $entry = sprintf("[%s] To: %s\nSubject: %s\n%s\n---\n", date('c'), $to, $subject, $body);
        return file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    private function sendPhpMail(string $to, string $subject, string $body): bool
    {
        if (!function_exists('mail')) {
            throw new RuntimeException('Fungsi mail PHP tidak tersedia.');
        }
        $headers = 'From: ' . $this->from . "\r\nContent-Type: text/plain; charset=UTF-8";
        return mail($to, $subject, $body, $headers);
    }

    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $host = trim((string) ($this->smtp['host'] ?? ''));
        $port = (int) ($this->smtp['port'] ?? 587);
        $username = (string) ($this->smtp['username'] ?? '');
        $password = (string) ($this->smtp['password'] ?? '');
        $encryption = strtolower((string) ($this->smtp['encryption'] ?? 'tls'));
        $timeout = max(5, (int) ($this->smtp['timeout'] ?? 15));
        if ($host === '' || $port < 1) {
            throw new RuntimeException('Konfigurasi SMTP tidak lengkap.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($remote, $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP tidak dapat dihubungi: ' . $error);
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, 220);
            $hostname = gethostname() ?: 'pesanmenu.local';
            $this->command($socket, 'EHLO ' . $hostname, 250);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS SMTP gagal diaktifkan.');
                }
                $this->command($socket, 'EHLO ' . $hostname, 250);
            }
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($username), 334);
                $this->command($socket, base64_encode($password), 235);
            }
            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->command($socket, 'DATA', 354);
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");
            $message = "From: {$this->from}\r\nTo: {$to}\r\nSubject: {$encodedSubject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encodedBody}";
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            $this->command($socket, $message . ".\r\n", 250);
            @fwrite($socket, "QUIT\r\n");
            return true;
        } finally {
            @fclose($socket);
        }
    }

    private function command($socket, string $command, int $expectedCode): void
    {
        $payload = $command . "\r\n";
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP gagal menerima perintah.');
            }
            $offset += $written;
        }
        $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): void
    {
        $response = '';
        do {
            $line = fgets($socket, 8192);
            if ($line === false) {
                throw new RuntimeException('SMTP menutup koneksi.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException('SMTP merespons ' . $code . '.');
        }
    }
}