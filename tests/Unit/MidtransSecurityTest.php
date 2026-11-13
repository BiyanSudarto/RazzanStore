<?php

namespace Tests\Unit;

use App\Services\MidtransAmount;
use App\Services\MidtransSignatureVerifier;
use Tests\TestCase;

class MidtransSecurityTest extends TestCase
{
    public function test_midtrans_signature_uses_exact_order_status_amount_and_server_key(): void
    {
        config(['midtrans.server_key' => 'secret-server-key']);

        $payload = [
            'order_id' => 'ORDER-123',
            'status_code' => '200',
            'gross_amount' => '15000.00',
            'signature_key' => hash('sha512', 'ORDER-12320015000.00secret-server-key'),
        ];

        self::assertTrue(app(MidtransSignatureVerifier::class)->verify($payload));

        $payload['gross_amount'] = '15000';
        self::assertFalse(app(MidtransSignatureVerifier::class)->verify($payload));
    }

    public function test_midtrans_idr_amount_parser_rejects_fractional_rupiah_and_invalid_strings(): void
    {
        $amounts = new MidtransAmount;

        self::assertSame(15000, $amounts->toIntegerIdr('15000.00'));
        self::assertSame(15000, $amounts->toIntegerIdr('15000'));
        self::assertNull($amounts->toIntegerIdr('15000.50'));
        self::assertNull($amounts->toIntegerIdr('15,000.00'));
        self::assertNull($amounts->toIntegerIdr(15000.00));
        self::assertNull($amounts->toIntegerIdr('-100'));
    }
}
