<?php

namespace Database\Seeders;

use App\Models\PaymentMethods;
use Illuminate\Database\Seeder;

class PaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Efectivo',
                'code' => 'cash',
                'is_active' => true,
            ],
            [
                'name' => 'Terjeta cretido',
                'code' => 'credit_card',
                'is_active' => true,
            ],
            [
                'name' => 'Terjeta debito',
                'code' => 'debit_card',
                'is_active' => true,
            ],
            [
                'name' => 'Transferencia',
                'code' => 'transfer',
                'is_active' => true,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethods::updateOrCreate(
                ['code' => $method['code']],
                $method
            );
        }
    }
}
