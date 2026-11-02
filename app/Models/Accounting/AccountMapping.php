<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class AccountMapping extends Model
{
    protected $table = 'acc_account_mappings';

    protected $fillable = ['key', 'label', 'account_id'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
