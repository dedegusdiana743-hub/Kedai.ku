<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Request;

final class SettingsController extends Controller
{
    public function edit(Request $request): void
    {
        $seller = $this->seller();
        $this->app->render('seller/settings', ['title' => 'Pengaturan toko', 'seller' => $seller]);
    }

    public function update(Request $request): void
    {
        $this->csrf($request);
        $seller = $this->seller();
        if (!$this->app->auth()->hasRole('OWNER', 'MANAGER')) {
            http_response_code(403);
            $this->app->render('errors/403', ['title' => 'Akses ditolak']);
            return;
        }
        $name = trim((string) $request->post('name'));
        $slug = $this->slug((string) $request->post('slug'));
        $address = trim((string) $request->post('address'));
        $whatsapp = trim((string) $request->post('whatsapp'));
        $shippingRaw = trim((string) $request->post('shipping_fee'));
        $taxRaw = trim((string) $request->post('tax_rate'));
        if ($name === '' || mb_strlen($name) > 120) {
            $old = $request->allPost();
            $old['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError('Nama toko tidak valid.');
            redirect('/penjual/pengaturan');
        }
        if (strlen($slug) > 140 || mb_strlen($address) > 500 || strlen($whatsapp) > 30) {
            $old = $request->allPost();
            $old['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError('Data toko terlalu panjang.');
            redirect('/penjual/pengaturan');
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $old = $request->allPost();
            $old['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError('Slug harus berupa huruf, angka, dan tanda hubung.');
            redirect('/penjual/pengaturan');
        }
        if (!preg_match('/^\d{1,7}$/', $shippingRaw) || !preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $taxRaw) || (float) $taxRaw > 100) {
            $old = $request->allPost();
            $old['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError('Ongkir atau tarif pajak tidak valid.');
            redirect('/penjual/pengaturan');
        }
        try {
            $this->app->db()->transaction(function ($db) use ($seller, $name, $slug, $address, $whatsapp, $shippingRaw, $taxRaw): void {
                $db->execute(
                    'UPDATE sellers SET name = ?, slug = ?, address = ?, whatsapp = ?, shipping_fee = ?, tax_rate = ?, is_active = ? WHERE id = ?',
                    [$name, $slug, $address ?: null, $whatsapp ?: null, (int) $shippingRaw, (float) $taxRaw, isset($_POST['is_active']) ? 1 : 0, (int) $seller['id']]
                );
                $db->execute('INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)', [(int) $seller['id'], $this->app->auth()->id(), 'seller.updated', 'seller', (int) $seller['id']]);
            });
            $this->flashSuccess('Pengaturan toko diperbarui.');
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flashError('Slug toko sudah digunakan.');
            } else {
                throw $exception;
            }
        }
        redirect('/penjual/pengaturan');
    }
}
