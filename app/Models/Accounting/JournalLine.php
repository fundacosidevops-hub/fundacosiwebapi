<?php

namespace App\Models\Accounting;

use App\Models\Insurances;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $table = 'acc_journal_lines';

    protected $fillable = [
        'entry_id', 'account_id', 'debit', 'credit', 'description',
        'insurance_id', 'patient_id', 'catalog_services_id', 'payment_method_id',
    ];

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2'];

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function insurance()
    {
        return $this->belongsTo(Insurances::class, 'insurance_id');
    }
}
