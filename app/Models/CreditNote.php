<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota de crédito (NCF B04) que anula una factura completa. */
class CreditNote extends Model
{
    protected $table = 'in_credit_notes';

    protected $fillable = [
        'invoice_id', 'ncf_number', 'reason', 'amount', 'patient_amount', 'coverage',
        'refund_amount', 'refund_method_id', 'cash_session_id', 'refunded_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'patient_amount' => 'float',
        'coverage' => 'float',
        'refund_amount' => 'float',
        'refunded_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoices::class, 'invoice_id');
    }

    public function refundMethod()
    {
        return $this->belongsTo(PaymentMethods::class, 'refund_method_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
