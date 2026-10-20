<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $seller = $this->seller();
        $sellerId = (int) $seller['id'];
        $todayOrders = (int) $this->app->db()->value('SELECT COUNT(*) FROM orders WHERE seller_id = ? AND DATE(created_at) = CURDATE()', [$sellerId]);
        $pendingOrders = (int) $this->app->db()->value("SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status IN ('PENDING_CONFIRMATION','ACCEPTED','PREPARING')", [$sellerId]);
        $dailyRevenue = (int) $this->app->db()->value("SELECT COALESCE(SUM(p.amount - (SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.order_id = p.order_id AND r.status = 'SUCCEEDED')),0) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.seller_id = ? AND p.status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED') AND DATE(p.paid_at) = CURDATE()", [$sellerId]);
        $totalRevenue = (int) $this->app->db()->value("SELECT COALESCE(SUM(p.amount - (SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.order_id = p.order_id AND r.status = 'SUCCEEDED')),0) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.seller_id = ? AND p.status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED')", [$sellerId]);
        $menuCount = (int) $this->app->db()->value('SELECT COUNT(*) FROM menu_items WHERE seller_id = ? AND deleted_at IS NULL', [$sellerId]);
        $recentOrders = $this->app->db()->all(
            'SELECT o.*, p.status AS payment_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.seller_id = ? ORDER BY o.created_at DESC LIMIT 8',
            [$sellerId]
        );
        $this->app->render('seller/dashboard', [
            'title' => 'Dashboard',
            'seller' => $seller,
            'todayOrders' => $todayOrders,
            'pendingOrders' => $pendingOrders,
            'dailyRevenue' => $dailyRevenue,
            'totalRevenue' => $totalRevenue,
            'menuCount' => $menuCount,
            'recentOrders' => $recentOrders,
        ]);
    }
}
