<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $table = 'acc_journal_entries';

    protected $fillable = [
        'year', 'sequence', 'number', 'date', 'description', 'source_type', 'source_id',
        'status', 'reversal_of_id', 'total', 'created_by',
    ];

    protected $casts = ['date' => 'date:Y-m-d', 'total' => 'decimal:2'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function reversedBy()
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
    }
}
