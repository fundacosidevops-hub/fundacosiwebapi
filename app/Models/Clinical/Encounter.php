<?php

namespace App\Models\Clinical;

use App\Models\Invoices;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Encounter extends Model
{
    protected $table = 'cl_encounters';

    protected $fillable = [
        'patient_id', 'doctor_id', 'invoice_id', 'status', 'reason', 'current_illness', 'physical_exam',
        'diagnosis', 'plan', 'indications', 'notes', 'started_at', 'signed_at', 'signed_by', 'created_by',
    ];

    protected $casts = ['started_at' => 'datetime', 'signed_at' => 'datetime'];

    /** Campos clínicos que escribe el médico. */
    public const CLINICAL_FIELDS = ['reason', 'current_illness', 'physical_exam', 'diagnosis', 'plan', 'indications', 'notes'];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoices::class, 'invoice_id');
    }

    public function vitals()
    {
        return $this->hasMany(VitalSign::class, 'encounter_id')->orderBy('taken_at');
    }

    public function prescription()
    {
        return $this->hasMany(PrescriptionItem::class, 'encounter_id')->orderBy('position');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'encounter_id')->orderBy('position');
    }

    public function addenda()
    {
        return $this->hasMany(Addendum::class, 'encounter_id')->orderBy('created_at');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'encounter_id');
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }
}
