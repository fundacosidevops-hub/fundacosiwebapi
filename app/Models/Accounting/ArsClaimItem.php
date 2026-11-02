<?php

namespace App\Models\Accounting;

use App\Models\Invoices;
use Illuminate\Database\Eloquent\Model;

class ArsClaimItem extends Model
{
    protected $table = 'acc_ars_claim_items';

    protected $fillable = [
        'claim_id', 'invoice_id', 'authorization_number', 'amount_claimed',
        'amount_paid', 'amount_glosa', 'glosa_reason',
    ];

    protected $casts = [
        'amount_claimed' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_glosa' => 'decimal:2',
    ];

    public function claim()
    {
        return $this->belongsTo(ArsClaim::class, 'claim_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoices::class, 'invoice_id');
    }

    /** Lo que falta por pagar o glosar. */
    public function pending(): float
    {
        return round((float) $this->amount_claimed - (float) $this->amount_paid - (float) $this->amount_glosa, 2);
    }
}
