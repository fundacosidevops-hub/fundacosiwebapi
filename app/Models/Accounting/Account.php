<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'acc_accounts';

    protected $fillable = ['code', 'name', 'type', 'nature', 'parent_id', 'is_postable', 'is_active', 'description'];

    protected $casts = ['is_postable' => 'boolean', 'is_active' => 'boolean'];

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
