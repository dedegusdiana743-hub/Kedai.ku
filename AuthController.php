<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;

final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        if ($this->app->auth()->check()) {
            redirect('/penjual');
        }
        $this->app->render('seller/login', ['title' => 'Login penjual']);
    }

    public function login(Request $request): void
    {
        $this->csrf($request);
        $email = strtolower(trim((string) $request->post('email')));
        $password = (string) $request->post('password');
        $attemptKey = hash('sha256', $email . '|' . $request->ip());
        $blocked = $this->app->db()->one(
            'SELECT blocked_until FROM login_attempts WHERE attempt_key = ? LIMIT 1',
            [$attemptKey]
        );
        if ($blocked && $blocked['blocked_until'] && strtotime((string) $blocked['blocked_until']) > time()) {
            remember_old(['email' => $email]);
            Flash::set('error', 'Terlalu banyak percobaan login. Coba lagi nanti.');
            redirect('/penjual/login');
        }

        if (!$this->app->auth()->attempt($email, $password)) {
            $this->app->db()->transaction(function ($db) use ($attemptKey, $email, $request): void {
                $row = $db->one('SELECT * FROM login_attempts WHERE attempt_key = ? FOR UPDATE', [$attemptKey]);
                $count = $row ? ((int) $row['attempt_count'] + 1) : 1;
                if ($count >= 5) {
                    if ($row) {
                        $db->execute(
                            "UPDATE login_attempts SET attempt_count = 0, blocked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE), ip_address = ? WHERE attempt_key = ?",
                            [$request->ip(), $attemptKey]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO login_attempts (attempt_key, email, ip_address, attempt_count, blocked_until) VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 15 MINUTE))",
                            [$attemptKey, $email, $request->ip()]
                        );
                    }
                } elseif ($row) {
                    $db->execute('UPDATE login_attempts SET attempt_count = ?, ip_address = ? WHERE attempt_key = ?', [$count, $request->ip(), $attemptKey]);
                } else {
                    $db->execute('INSERT INTO login_attempts (attempt_key, email, ip_address, attempt_count) VALUES (?, ?, ?, 1)', [$attemptKey, $email, $request->ip()]);
                }
            });
            remember_old(['email' => $email]);
            Flash::set('error', 'Email atau password salah.');
            redirect('/penjual/login');
        }

        $this->app->db()->execute('DELETE FROM login_attempts WHERE attempt_key = ?', [$attemptKey]);
        if (!$this->app->auth()->seller()) {
            $this->app->auth()->logout();
            Flash::set('error', 'Akun ini belum terhubung ke toko.');
            redirect('/penjual/login');
        }
        redirect('/penjual');
    }

    public function logout(Request $request): void
    {
        $this->csrf($request);
        $this->app->auth()->logout();
        redirect('/penjual/login');
    }
}
