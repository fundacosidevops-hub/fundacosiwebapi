<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceStatusSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('in_invoice_statuses')->upsert(
            [
                ['name' => 'pending', 'description' => 'Factura pendiente'],
                ['name' => 'partial', 'description' => 'Pago parcial'],
                ['name' => 'paid', 'description' => 'Factura pagada'],
                ['name' => 'canceled', 'description' => 'Factura cancelada'],
            ],
            ['name'], // campo único
            ['description'] // campo a actualizar
        );
    }
}
