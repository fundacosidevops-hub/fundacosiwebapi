<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Clinical\AccessLog;
use App\Models\Clinical\Allergy;
use App\Models\Clinical\Attachment;
use App\Models\Clinical\Background;
use App\Models\Clinical\Encounter;
use App\Models\Clinical\Medication;
use App\Models\Clinical\Vaccine;
use App\Models\Clinical\VitalSign;
use App\Models\Invoices;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Expediente del paciente y lista de atención del día. */
class PatientRecordController extends Controller
{
    use Concerns;

    /** Buscar pacientes por documento, número de registro o nombre. */
    public function search(Request $request)
    {
        $this->authorizeAny('clinical.view');
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $users = User::with('insurance:id,description')
            ->where('user_type_id', 2)
            ->where(function ($w) use ($q) {
                $w->where('document_number', 'like', "%{$q}%");
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q);
                }
                foreach (preg_split('/\s+/', $q) as $word) {
                    $w->orWhere(fn ($n) => $n->where('name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
                }
            })
            ->orderBy('name')->limit(25)->get();

        $allergies = Allergy::whereIn('patient_id', $users->pluck('id'))->groupBy('patient_id')->selectRaw('patient_id, count(*) c')->pluck('c', 'patient_id');
        $last = Encounter::whereIn('patient_id', $users->pluck('id'))->groupBy('patient_id')->selectRaw('patient_id, max(created_at) d')->pluck('d', 'patient_id');

        return response()->json($users->map(fn ($u) => $this->patientCard($u) + [
            'allergies' => (int) ($allergies[$u->id] ?? 0),
            'last_encounter' => $last[$u->id] ?? null,
        ]));
    }

    /** Expediente completo. */
    public function show(int $id)
    {
        $this->authorizeAny('clinical.view');
        $patient = User::findOrFail($id);
        AccessLog::record($patient->id, 'view');

        $encounters = Encounter::with(['doctor:id,name,last_name,position_id', 'doctor.position:id,description'])
            ->withCount('prescription')
            ->where('patient_id', $id)->orderByDesc('created_at')->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'date' => $e->created_at,
                'status' => $e->status,
                'doctor' => $this->personName($e->doctor),
                'specialty' => $e->doctor?->position?->description,
                'reason' => $e->reason,
                'diagnosis' => $e->diagnosis,
                'prescription_count' => $e->prescription_count,
                'signed_at' => $e->signed_at,
            ]);

        return response()->json([
            'patient' => $this->patientCard($patient),
            'background' => Background::where('patient_id', $id)->first(),
            'allergies' => Allergy::where('patient_id', $id)->orderByRaw("CASE severity WHEN 'grave' THEN 0 WHEN 'moderada' THEN 1 ELSE 2 END")->get(),
            'medications' => Medication::where('patient_id', $id)->orderByDesc('active')->orderBy('name')->get(),
            'vaccines' => Vaccine::where('patient_id', $id)->orderByDesc('applied_on')->get(),
            'last_vitals' => VitalSign::with('taker:id,name,last_name')->where('patient_id', $id)->latest('taken_at')->first(),
            'vitals_history' => VitalSign::where('patient_id', $id)->latest('taken_at')->limit(20)->get(),
            'encounters' => $encounters,
            'attachments_count' => Attachment::where('patient_id', $id)->count(),
            'can' => [
                'write' => $this->hasPerm('clinical.write'),
                'triage' => $this->hasPerm('clinical.triage'),
                'attach' => $this->hasPerm('clinical.attach'),
            ],
        ]);
    }

    public function saveBackground(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        User::findOrFail($id);
        $v = $request->validate([
            'blood_type' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'personal' => 'nullable|string|max:5000',
            'family' => 'nullable|string|max:5000',
            'surgical' => 'nullable|string|max:5000',
            'gyneco' => 'nullable|string|max:5000',
            'habits' => 'nullable|string|max:5000',
            'other' => 'nullable|string|max:5000',
        ], ['blood_type.in' => 'El grupo sanguíneo no es válido.']);
        $bg = Background::updateOrCreate(['patient_id' => $id], $v + ['updated_by' => auth()->id()]);
        AccessLog::record($id, 'edit', 'Antecedentes');

        return response()->json($bg);
    }

    // --- Alergias -------------------------------------------------------

    public function storeAllergy(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        User::findOrFail($id);
        $v = $request->validate([
            'substance' => 'required|string|max:150',
            'reaction' => 'nullable|string|max:255',
            'severity' => ['required', Rule::in(['leve', 'moderada', 'grave'])],
        ]);
        $a = Allergy::create($v + ['patient_id' => $id, 'created_by' => auth()->id()]);
        AccessLog::record($id, 'edit', "Alergia: {$a->substance}");

        return response()->json($a, 201);
    }

    public function destroyAllergy(int $id, int $allergyId)
    {
        $this->authorizeAny('clinical.write');
        $a = Allergy::where('patient_id', $id)->findOrFail($allergyId);
        AccessLog::record($id, 'edit', "Quitó alergia: {$a->substance}");
        $a->delete();

        return response()->json(['isSuccess' => true]);
    }

    // --- Medicamentos de uso continuo -----------------------------------

    public function storeMedication(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        User::findOrFail($id);
        $v = $request->validate([
            'name' => 'required|string|max:150',
            'dose' => 'nullable|string|max:100',
            'frequency' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
        ]);
        $m = Medication::create($v + ['patient_id' => $id, 'active' => true, 'created_by' => auth()->id()]);
        AccessLog::record($id, 'edit', "Medicamento: {$m->name}");

        return response()->json($m, 201);
    }

    public function updateMedication(Request $request, int $id, int $medId)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        $m = Medication::where('patient_id', $id)->findOrFail($medId);
        $m->update($request->validate(['active' => 'required|boolean']));

        return response()->json($m);
    }

    // --- Vacunas --------------------------------------------------------

    public function storeVaccine(Request $request, int $id)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        User::findOrFail($id);
        $v = $request->validate([
            'name' => 'required|string|max:150',
            'applied_on' => 'nullable|date|before_or_equal:today',
            'dose' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:255',
        ]);
        $vac = Vaccine::create($v + ['patient_id' => $id, 'created_by' => auth()->id()]);

        return response()->json($vac, 201);
    }

    public function destroyVaccine(int $id, int $vaccineId)
    {
        $this->authorizeAny('clinical.write', 'clinical.triage');
        Vaccine::where('patient_id', $id)->findOrFail($vaccineId)->delete();

        return response()->json(['isSuccess' => true]);
    }

    /** Quién ha visto o modificado el expediente. */
    public function accessLog(int $id)
    {
        $this->authorizeAny('clinical.write');

        return response()->json(
            AccessLog::with('user:id,name,last_name')->where('patient_id', $id)->latest('created_at')->limit(100)->get()
        );
    }

    /** Médicos (para filtrar la lista del día). */
    public function doctors()
    {
        $this->authorizeAny('clinical.view', 'clinical.triage');

        return response()->json(User::where('user_type_id', 3)->orderBy('name')->get(['id', 'name', 'last_name']));
    }

    /**
     * Pacientes del día: las facturas de la fecha (cada una es una atención)
     * con el estado de su consulta. Un médico ve los suyos por defecto.
     */
    public function worklist(Request $request)
    {
        $this->authorizeAny('clinical.view', 'clinical.triage');
        $date = $request->input('date', now()->toDateString());
        $doctorId = $request->input('doctor_id');
        if ($doctorId === null && (int) auth()->user()->user_type_id === 3) {
            $doctorId = auth()->id();
        }

        $q = Invoices::with(['patient:id,name,last_name,document_number,birth_date,gender', 'doctor:id,name,last_name', 'catalogServices:id,description'])
            ->whereBetween('created_at', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->orderBy('created_at');
        if ($doctorId && $doctorId !== 'all') {
            $q->where('doctor_id', (int) $doctorId);
        }
        $invoices = $q->get();

        $encounters = Encounter::withCount('vitals')->whereIn('invoice_id', $invoices->pluck('id'))->get()->keyBy('invoice_id');
        $allergies = Allergy::whereIn('patient_id', $invoices->pluck('patient_id'))
            ->get(['patient_id', 'substance', 'severity'])->groupBy('patient_id');

        return response()->json([
            'date' => $date,
            'doctor_id' => $doctorId,
            'rows' => $invoices->map(function ($f) use ($encounters, $allergies) {
                $e = $encounters->get($f->id);
                $age = $f->patient?->birth_date ? Carbon::parse($f->patient->birth_date)->age : null;

                return [
                    'invoice_id' => $f->id,
                    'time' => $f->created_at,
                    'paid' => (int) $f->status_id === 3,
                    'patient_id' => $f->patient_id,
                    'patient' => $this->personName($f->patient),
                    'age' => $age,
                    'gender' => $f->patient?->gender,
                    'document' => $f->patient?->document_number,
                    'doctor_id' => $f->doctor_id,
                    'doctor' => $this->personName($f->doctor),
                    'service' => $f->catalogServices?->description,
                    'allergies' => ($allergies->get($f->patient_id) ?? collect())->map(fn ($a) => $a->substance . ($a->severity === 'grave' ? ' (grave)' : ''))->values(),
                    'encounter_id' => $e?->id,
                    'encounter_status' => $e?->status,
                    'has_vitals' => ($e?->vitals_count ?? 0) > 0,
                ];
            }),
        ]);
    }
}
