<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Clinical\AccessLog;
use App\Models\Clinical\Addendum;
use App\Models\Clinical\Encounter;
use App\Models\Clinical\VitalSign;
use App\Models\Invoices;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Consultas: triaje → consulta → firmada. Una consulta firmada ya no se
 * modifica; solo se le agregan notas aclaratorias con fecha y autor.
 */
class EncounterController extends Controller
{
    use Concerns;

    /** Abre la consulta de una factura (o una consulta sin factura). */
    public function store(Request $request)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        $v = $request->validate([
            'patient_id' => 'required|integer|exists:users,id',
            'invoice_id' => 'nullable|integer|exists:in_invoices,id',
        ]);

        $invoice = isset($v['invoice_id']) ? Invoices::find($v['invoice_id']) : null;
        if ($invoice && (int) $invoice->patient_id !== (int) $v['patient_id']) {
            return $this->fail('La factura no es de este paciente.');
        }
        if ($invoice && ($existing = Encounter::where('invoice_id', $invoice->id)->first())) {
            return response()->json($existing);
        }

        $user = auth()->user();
        $isDoctor = $this->hasPerm('clinical.write') && (int) $user->user_type_id === 3;
        $doctorId = $invoice?->doctor_id ?? ($isDoctor ? $user->id : null);
        $starts = $this->hasPerm('clinical.write') && (! $doctorId || (int) $doctorId === (int) $user->id);

        $e = Encounter::create([
            'patient_id' => $v['patient_id'],
            'invoice_id' => $invoice?->id,
            'doctor_id' => $doctorId,
            'status' => $starts ? 'in_progress' : 'triage',
            'started_at' => $starts ? now() : null,
            'created_by' => $user->id,
        ]);
        AccessLog::record($e->patient_id, 'open', "Consulta #{$e->id}");

        return response()->json($e, 201);
    }

    public function show(int $id)
    {
        $this->authorizeAny('clinical.view', 'clinical.triage');
        $e = Encounter::with([
            'patient', 'doctor:id,name,last_name,position_id,exequatur', 'doctor.position:id,description',
            'signer:id,name,last_name', 'vitals.taker:id,name,last_name', 'prescription', 'orders',
            'addenda.author:id,name,last_name', 'attachments.uploader:id,name,last_name',
            'invoice:id,ncf_number,catalog_services_id', 'invoice.catalogServices:id,description',
        ])->findOrFail($id);
        AccessLog::record($e->patient_id, 'view', "Consulta #{$e->id}");

        $data = $e->toArray();
        $data['patient'] = $this->patientCard($e->patient);
        $data['doctor_name'] = $this->personName($e->doctor);
        $data['specialty'] = $e->doctor?->position?->description;
        $data['service'] = $e->invoice?->catalogServices?->description;
        $data['can_edit'] = ! $e->isSigned() && $this->hasPerm('clinical.write')
            && (! $e->doctor_id || (int) $e->doctor_id === (int) auth()->id() || auth()->user()->hasRole('SuperAdmin'));
        $data['can_vitals'] = ! $e->isSigned() && ($this->hasPerm('clinical.triage') || $this->hasPerm('clinical.write'));
        $data['can_addendum'] = $e->isSigned() && $this->hasPerm('clinical.write');

        return response()->json($data);
    }

    /** El médico empieza a atender una consulta que estaba en triaje. */
    public function start(int $id)
    {
        $this->authorizeAny('clinical.write');
        $e = Encounter::findOrFail($id);
        if ($e->isSigned()) {
            return $this->fail('La consulta ya está firmada.');
        }
        if ($r = $this->assertDoctorOf($e)) {
            return $r;
        }
        $e->update([
            'status' => 'in_progress',
            'doctor_id' => $e->doctor_id ?? auth()->id(),
            'started_at' => $e->started_at ?? now(),
        ]);

        return $this->show($id);
    }

    /** Guarda el borrador de la consulta (campos, receta y órdenes). */
    public function update(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write');
        $e = Encounter::findOrFail($id);
        if ($e->isSigned()) {
            return $this->fail('La consulta está firmada y ya no se puede modificar. Agrega una nota aclaratoria.');
        }
        if ($r = $this->assertDoctorOf($e)) {
            return $r;
        }

        $rules = array_fill_keys(Encounter::CLINICAL_FIELDS, 'nullable|string|max:10000');
        $v = $request->validate($rules + [
            'prescription' => 'nullable|array|max:30',
            'prescription.*.medication' => 'required|string|max:150',
            'prescription.*.presentation' => 'nullable|string|max:100',
            'prescription.*.dose' => 'nullable|string|max:100',
            'prescription.*.frequency' => 'nullable|string|max:100',
            'prescription.*.duration' => 'nullable|string|max:100',
            'prescription.*.route' => 'nullable|string|max:50',
            'prescription.*.quantity' => 'nullable|string|max:50',
            'prescription.*.instructions' => 'nullable|string|max:255',
            'orders' => 'nullable|array|max:30',
            'orders.*.type' => ['required', Rule::in(['laboratorio', 'imagen', 'referimiento', 'otro'])],
            'orders.*.description' => 'required|string|max:255',
            'orders.*.notes' => 'nullable|string|max:255',
        ], [
            'prescription.*.medication.required' => 'Cada línea de la receta necesita el medicamento.',
            'orders.*.description.required' => 'Cada orden necesita la descripción.',
        ]);

        DB::transaction(function () use ($e, $v) {
            $e->update(array_intersect_key($v, array_flip(Encounter::CLINICAL_FIELDS)) + [
                'status' => 'in_progress',
                'doctor_id' => $e->doctor_id ?? auth()->id(),
                'started_at' => $e->started_at ?? now(),
            ]);
            if (array_key_exists('prescription', $v)) {
                $e->prescription()->delete();
                foreach (array_values($v['prescription'] ?? []) as $i => $item) {
                    $e->prescription()->create($item + ['position' => $i]);
                }
            }
            if (array_key_exists('orders', $v)) {
                $e->orders()->delete();
                foreach (array_values($v['orders'] ?? []) as $i => $o) {
                    $e->orders()->create($o + ['position' => $i]);
                }
            }
        });

        return $this->show($id);
    }

    public function storeVitals(Request $request, int $id)
    {
        $this->authorizeAny('clinical.triage', 'clinical.write');
        $e = Encounter::findOrFail($id);
        if ($e->isSigned()) {
            return $this->fail('La consulta ya está firmada.');
        }
        $v = $request->validate([
            'weight_kg' => 'nullable|numeric|min:0.3|max:400',
            'height_cm' => 'nullable|numeric|min:20|max:250',
            'temperature_c' => 'nullable|numeric|min:30|max:45',
            'systolic' => 'nullable|integer|min:40|max:300',
            'diastolic' => 'nullable|integer|min:20|max:200',
            'heart_rate' => 'nullable|integer|min:20|max:250',
            'respiratory_rate' => 'nullable|integer|min:5|max:80',
            'spo2' => 'nullable|integer|min:40|max:100',
            'glucose' => 'nullable|integer|min:10|max:900',
            'notes' => 'nullable|string|max:255',
        ], [
            'min' => ['numeric' => 'El valor de :attribute es demasiado bajo; revísalo.'],
            'max' => ['numeric' => 'El valor de :attribute es demasiado alto; revísalo.', 'string' => 'La nota es demasiado larga.'],
            'integer' => 'La :attribute debe ser un número entero.',
            'numeric' => 'El :attribute debe ser un número.',
        ], [
            'weight_kg' => 'peso', 'height_cm' => 'talla', 'temperature_c' => 'temperatura', 'systolic' => 'presión sistólica',
            'diastolic' => 'presión diastólica', 'heart_rate' => 'frecuencia cardiaca', 'respiratory_rate' => 'frecuencia respiratoria',
            'spo2' => 'saturación', 'glucose' => 'glucosa',
        ]);
        $values = array_filter($v, fn ($x) => $x !== null && $x !== '');
        if (! array_diff_key($values, ['notes' => 1])) {
            return $this->fail('Anota al menos un signo vital.');
        }
        $bmi = (! empty($v['weight_kg']) && ! empty($v['height_cm']))
            ? round($v['weight_kg'] / (($v['height_cm'] / 100) ** 2), 2) : null;

        VitalSign::create($v + [
            'patient_id' => $e->patient_id,
            'encounter_id' => $e->id,
            'bmi' => $bmi,
            'taken_by' => auth()->id(),
            'taken_at' => now(),
        ]);
        AccessLog::record($e->patient_id, 'edit', "Signos vitales consulta #{$e->id}");

        return $this->show($id);
    }

    public function sign(int $id)
    {
        $this->authorizeAny('clinical.write');
        $e = Encounter::findOrFail($id);
        if ($e->isSigned()) {
            return $this->fail('La consulta ya está firmada.');
        }
        if ($r = $this->assertDoctorOf($e)) {
            return $r;
        }
        if (! trim((string) $e->reason) || ! trim((string) $e->diagnosis)) {
            return $this->fail('Para firmar, la consulta necesita al menos el motivo y el diagnóstico.');
        }
        $e->update([
            'status' => 'signed',
            'doctor_id' => $e->doctor_id ?? auth()->id(),
            'signed_at' => now(),
            'signed_by' => auth()->id(),
        ]);
        AccessLog::record($e->patient_id, 'sign', "Consulta #{$e->id}");

        return $this->show($id);
    }

    public function storeAddendum(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write');
        $e = Encounter::findOrFail($id);
        if (! $e->isSigned()) {
            return $this->fail('Mientras no esté firmada, corrige la consulta directamente.');
        }
        $v = $request->validate(['text' => 'required|string|max:5000']);
        Addendum::create(['encounter_id' => $e->id, 'text' => $v['text'], 'created_by' => auth()->id()]);
        AccessLog::record($e->patient_id, 'addendum', "Consulta #{$e->id}");

        return $this->show($id);
    }

    /** Borra una consulta sin firmar abierta por error. */
    public function destroy(int $id)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        $e = Encounter::findOrFail($id);
        if ($e->isSigned()) {
            return $this->fail('Una consulta firmada no se puede borrar.');
        }
        if ((int) $e->created_by !== (int) auth()->id() && ! auth()->user()->hasRole('SuperAdmin')) {
            return $this->fail('Solo quien abrió la consulta puede borrarla.', 403);
        }
        AccessLog::record($e->patient_id, 'delete', "Consulta #{$e->id} sin firmar");
        $e->delete();

        return response()->json(['isSuccess' => true]);
    }

    /** Registra una impresión (receta o consulta) en la bitácora. */
    public function logPrint(Request $request, int $id)
    {
        $this->authorizeAny('clinical.view');
        $e = Encounter::findOrFail($id);
        $what = $request->validate(['what' => ['required', Rule::in(['receta', 'consulta', 'ordenes'])]])['what'];
        AccessLog::record($e->patient_id, 'print', ucfirst($what) . " consulta #{$e->id}");

        return response()->json(['isSuccess' => true]);
    }
}
