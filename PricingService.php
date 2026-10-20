<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use InvalidArgumentException;

final class PricingService
{
    private const MAX_TOTAL = 9_000_000_000_000_000;

    /**
     * @param list<array{price:int|float|string, quantity:int|float|string}> $items
     * @return array{subtotal:int,shipping_fee:int,tax_rate:float,tax_amount:int,total:int}
     */
    public function calculate(array $items, array $seller, string $deliveryType): array
    {
        if (!in_array($deliveryType, ['PICKUP', 'DELIVERY'], true)) {
            throw new InvalidArgumentException('Metode pengiriman tidak valid.');
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $price = $this->amount($item['price'] ?? $item['unit_price'] ?? null, 'Harga');
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 99) {
                throw new InvalidArgumentException('Jumlah item tidak valid.');
            }
            $lineTotal = $price * $quantity;
            if ($subtotal > self::MAX_TOTAL - $lineTotal) {
                throw new InvalidArgumentException('Total pesanan melebihi batas yang didukung.');
            }
            $subtotal += $lineTotal;
        }

        $shippingFee = $deliveryType === 'DELIVERY'
            ? $this->amount($seller['shipping_fee'] ?? 0, 'Ongkos antar')
            : 0;
        $taxRate = (float) ($seller['tax_rate'] ?? 10);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('Tarif pajak tidak valid.');
        }
        $taxAmount = (int) round($subtotal * $taxRate / 100, 0, PHP_ROUND_HALF_UP);
        $total = $subtotal + $shippingFee + $taxAmount;
        if ($total > self::MAX_TOTAL) {
            throw new InvalidArgumentException('Total pesanan melebihi batas yang didukung.');
        }

        return [
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ];
    }

    private function amount(mixed $value, string $label): int
    {
        $normalized = ltrim(trim((string) $value), '+');
        if (!preg_match('/^\d{1,15}$/', $normalized)) {
            throw new InvalidArgumentException($label . ' tidak valid.');
        }

        return (int) $normalized;
    }
}