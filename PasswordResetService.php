<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use InvalidArgumentException;
use PesanMenu\Core\App;
use PesanMenu\Core\Database;

final class PasswordResetService
{
    private readonly Mailer $mailer;

    public function __construct(private readonly App $app, private readonly Database $db)
    {
        $this->mailer = new Mailer(
            (string) $app->config('mail.driver', 'log'),
            (string) $app->config('mail.from', 'no-reply@pesanmenu.local'),
            $app->root() . '/storage',
            [
                'host' => (string) $app->config('mail.smtp_host', ''),
                'port' => (int) $app->config('mail.smtp_port', 587),
                'username' => (string) $app->config('mail.smtp_username', ''),
                'password' => (string) $app->config('mail.smtp_password', ''),
                'encryption' => (string) $app->config('mail.smtp_encryption', 'tls'),
                'timeout' => (int) $app->config('mail.smtp_timeout', 15),
            ]
        );
    }

    public function request(string $email, string $ipAddress): bool
    {
        $email = strtolower(trim($email));
        $user = $this->db->one('SELECT id, name, email FROM users WHERE email = ? AND status = \'ACTIVE\' LIMIT 1', [$email]);
        if (!$user) {
            return true;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function (Database $db) use ($user, $token): void {
            $db->execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
            $db->execute(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
                [(int) $user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]
            );
        });

        $url = $this->app->url('/penjual/reset-password?token=' . rawurlencode($token));
        try {
            $this->mailer->send(
                (string) $user['email'],
                'Reset password PesanMenu',
                "Halo {$user['name']},\n\nGunakan tautan berikut untuk mengatur ulang password:\n{$url}\n\nTautan berlaku 60 menit. Abaikan jika Anda tidak meminta reset."
            );
        } catch (\Throwable $exception) {
            $this->app->log($exception);
        }
        $this->db->execute(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)',
            [(int) $user['id'], 'auth.password_reset_requested', 'user', (int) $user['id'], $ipAddress]
        );
        return true;
    }

    public function reset(string $token, string $password, string $confirmation): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        self::validatePassword($password);
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('Konfirmasi password tidak sama.');
        }

        return $this->db->transaction(function (Database $db) use ($token, $password): bool {
            $reset = $db->one('SELECT * FROM password_resets WHERE token_hash = ? LIMIT 1 FOR UPDATE', [hash('sha256', $token)]);
            if (!$reset || $reset['used_at'] !== null || strtotime((string) $reset['expires_at']) < time()) {
                return false;
            }
            $db->execute(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $reset['user_id']]
            );
            $db->execute(
                'UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]
            );
            $db->execute(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)',
                [(int) $reset['user_id'], 'auth.password_reset_completed', 'user', (int) $reset['user_id']]
            );
            return true;
        });
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): bool
    {
        self::validatePassword($newPassword);
        if (!hash_equals($newPassword, $confirmation)) {
            throw new InvalidArgumentException('Konfirmasi password tidak sama.');
        }
        return $this->db->transaction(function (Database $db) use ($userId, $currentPassword, $newPassword): bool {
            $user = $db->one('SELECT password_hash FROM users WHERE id = ? AND status = \'ACTIVE\' FOR UPDATE', [$userId]);
            if (!$user || !password_verify($currentPassword, (string) $user['password_hash'])) {
                throw new InvalidArgumentException('Password saat ini salah.');
            }
            $db->execute(
                'UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
                [password_hash($newPassword, PASSWORD_DEFAULT), $userId]
            );
            $db->execute('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)', [$userId, 'auth.password_changed', 'user', $userId]);
            return true;
        });
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new InvalidArgumentException('Password minimal 10 karakter dan harus berisi huruf serta angka.');
        }
    }
}