<?php

namespace App\Models\Clinical;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cl_access_logs';

    protected $fillable = ['patient_id', 'user_id', 'action', 'detail', 'ip'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $patientId, string $action, ?string $detail = null): void
    {
        static::create([
            'patient_id' => $patientId,
            'user_id' => auth()->id(),
            'action' => $action,
            'detail' => $detail ? mb_substr($detail, 0, 255) : null,
            'ip' => request()->ip(),
        ]);
    }
}
