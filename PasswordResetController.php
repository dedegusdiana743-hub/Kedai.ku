<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;
use PesanMenu\Services\PasswordResetService;

final class PasswordResetController extends Controller
{
    public function changeForm(Request $request): void
    {
        $this->seller();
        $this->app->render('seller/password-change', ['title' => 'Ganti password']);
    }

    public function change(Request $request): void
    {
        $this->csrf($request);
        $this->seller();
        $user = $this->app->auth()->user();
        try {
            (new PasswordResetService($this->app, $this->app->db()))->changePassword(
                (int) $user['id'],
                (string) $request->post('current_password'),
                (string) $request->post('password'),
                (string) $request->post('password_confirmation')
            );
            $fresh = $this->app->db()->one('SELECT token_version FROM users WHERE id = ?', [(int) $user['id']]);
            $_SESSION['token_version'] = (int) ($fresh['token_version'] ?? 1);
            Flash::set('success', 'Password berhasil diubah. Session lain telah dikeluarkan.');
            redirect('/penjual');
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage());
            redirect('/penjual/password');
        }
    }

    public function requestForm(Request $request): void
    {
        $this->app->render('seller/password-request', ['title' => 'Lupa password']);
    }

    public function sendLink(Request $request): void
    {
        $this->csrf($request);
        $email = strtolower(trim((string) $request->post('email')));
        $key = hash('sha256', 'reset|' . $email . '|' . $request->ip());
        $blocked = $this->app->db()->one('SELECT blocked_until FROM login_attempts WHERE attempt_key = ? LIMIT 1', [$key]);
        if (!$blocked || !$blocked['blocked_until'] || strtotime((string) $blocked['blocked_until']) <= time()) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                (new PasswordResetService($this->app, $this->app->db()))->request($email, $request->ip());
                $this->recordAttempt($key, $email, $request->ip());
            }
        }
        Flash::set('success', 'Jika email terdaftar, tautan reset akan dikirim.');
        redirect('/penjual/lupa-password');
    }

    public function resetForm(Request $request): void
    {
        $token = (string) $request->query('token', '');
        $this->app->render('seller/password-reset', ['title' => 'Atur ulang password', 'token' => $token]);
    }

    public function reset(Request $request): void
    {
        $this->csrf($request);
        $token = (string) $request->post('token');
        $password = (string) $request->post('password');
        $confirmation = (string) $request->post('password_confirmation');
        try {
            $updated = (new PasswordResetService($this->app, $this->app->db()))->reset($token, $password, $confirmation);
            if (!$updated) {
                Flash::set('error', 'Tautan reset tidak valid atau sudah kedaluwarsa.');
                redirect('/penjual/lupa-password');
            }
            $this->app->auth()->logout();
            Flash::set('success', 'Password diperbarui. Silakan login kembali.');
            redirect('/penjual/login');
        } catch (InvalidArgumentException $exception) {
            remember_old(['password' => '']);
            Flash::set('error', $exception->getMessage());
            redirect('/penjual/reset-password?token=' . rawurlencode($token));
        }
    }

    private function recordAttempt(string $key, string $email, string $ip): void
    {
        $this->app->db()->transaction(function ($db) use ($key, $email, $ip): void {
            $row = $db->one('SELECT * FROM login_attempts WHERE attempt_key = ? FOR UPDATE', [$key]);
            $count = $row ? ((int) $row['attempt_count'] + 1) : 1;
            if ($count >= 3) {
                if ($row) {
                    $db->execute("UPDATE login_attempts SET attempt_count = 0, blocked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE attempt_key = ?", [$key]);
                } else {
                    $db->execute("INSERT INTO login_attempts (attempt_key, email, ip_address, attempt_count, blocked_until) VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 15 MINUTE))", [$key, $email, $ip]);
                }
            } elseif ($row) {
                $db->execute('UPDATE login_attempts SET attempt_count = ? WHERE attempt_key = ?', [$count, $key]);
            } else {
                $db->execute('INSERT INTO login_attempts (attempt_key, email, ip_address, attempt_count) VALUES (?, ?, ?, 1)', [$key, $email, $ip]);
            }
        });
    }
}