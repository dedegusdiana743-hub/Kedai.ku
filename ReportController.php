<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;

final class ReportController extends Controller
{
    public function index(Request $request): void
    {
        $seller = $this->seller();
        $daily = $this->app->db()->all(
            "SELECT DATE(p.paid_at) AS day, COUNT(DISTINCT p.order_id) AS order_count,
                     COALESCE(SUM(p.amount - (SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.order_id = p.order_id AND r.status = 'SUCCEEDED')),0) AS paid_revenue
             FROM payments p JOIN orders o ON o.id = p.order_id
             WHERE o.seller_id = ? AND p.status IN ('PAID','PARTIALLY_REFUNDED','REFUNDED') AND p.paid_at IS NOT NULL AND DATE(p.paid_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY DATE(p.paid_at) ORDER BY day ASC",
            [(int) $seller['id']]
        );
        $topMenus = $this->app->db()->all(
            'SELECT oi.name_snapshot, SUM(oi.quantity) AS quantity, SUM(oi.line_total) AS revenue
             FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN payments p ON p.order_id = o.id
             WHERE oi.seller_id = ? AND p.status IN (\'PAID\',\'PARTIALLY_REFUNDED\',\'REFUNDED\') AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY oi.name_snapshot ORDER BY quantity DESC LIMIT 10',
            [(int) $seller['id']]
        );
        $this->app->render('seller/report', [
            'title' => 'Laporan penjualan',
            'seller' => $seller,
            'daily' => $daily,
            'topMenus' => $topMenus,
        ]);
    }
}
