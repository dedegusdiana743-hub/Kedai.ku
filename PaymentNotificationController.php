<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;
use PesanMenu\Exceptions\PaymentException;

final class PaymentNotificationController extends Controller
{
    public function midtrans(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->app->paymentsEnabled()) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'message' => 'Payment provider belum dikonfigurasi.'], JSON_THROW_ON_ERROR);
            return;
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'message' => 'Payload terlalu besar.'], JSON_THROW_ON_ERROR);
            return;
        }
        $payload = $request->json();
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Payload kosong.'], JSON_THROW_ON_ERROR);
            return;
        }

        try {
            $result = $this->app->paymentService()->applyNotification($payload);
            echo json_encode(['ok' => true, 'duplicate' => $result['duplicate']], JSON_THROW_ON_ERROR);
        } catch (PaymentException $exception) {
            $this->app->log($exception);
            http_response_code($exception->httpStatus >= 400 && $exception->httpStatus < 500 ? $exception->httpStatus : 422);
            echo json_encode(['ok' => false, 'message' => 'Notification ditolak.'], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->app->log($exception);
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Notification gagal diproses.'], JSON_THROW_ON_ERROR);
        }
    }
}