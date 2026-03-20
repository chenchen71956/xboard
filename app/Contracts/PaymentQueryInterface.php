<?php

namespace App\Contracts;

interface PaymentQueryInterface
{
    /**
     * Query payment status by trade no.
     *
     * Return example:
     * [
     *   'paid' => true,
     *   'callback_no' => '...',
     *   'raw' => mixed
     * ]
     */
    public function query(string $tradeNo): array|bool;
}
