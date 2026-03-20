<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payments extends Model
{
    use HasFactory;

    protected $table = 'in_payments';

    protected $fillable = [
        'invoice_id',
        'payment_method_id',
        'amount',
        'reference',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function invoices()
    {
        return $this->belongsTo(Invoices::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethods::class);
    }
}
