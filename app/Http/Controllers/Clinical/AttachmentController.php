<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Clinical\AccessLog;
use App\Models\Clinical\Attachment;
use App\Models\Clinical\Encounter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Resultados y documentos del paciente (PDF o imágenes). Se guardan fuera de public. */
class AttachmentController extends Controller
{
    use Concerns;

    public function index(int $id)
    {
        $this->authorizeAny('clinical.view');

        return response()->json(
            Attachment::with('uploader:id,name,last_name')->where('patient_id', $id)->latest()->get()
        );
    }

    public function store(Request $request, int $id)
    {
        $this->authorizeAny('clinical.attach');
        User::findOrFail($id);
        $v = $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
            'title' => 'required|string|max:150',
            'category' => ['required', Rule::in(['laboratorio', 'imagen', 'documento', 'otro'])],
            'encounter_id' => 'nullable|integer',
        ], [
            'file.max' => 'El archivo no puede pasar de 10 MB.',
            'file.mimes' => 'Solo se aceptan PDF o imágenes (JPG, PNG, WEBP).',
        ]);
        if (! empty($v['encounter_id']) && ! Encounter::where('id', $v['encounter_id'])->where('patient_id', $id)->exists()) {
            return $this->fail('La consulta no es de este paciente.');
        }

        $file = $request->file('file');
        $path = $file->storeAs("clinical/{$id}", Str::uuid() . '.' . strtolower($file->getClientOriginalExtension()), 'local');
        $a = Attachment::create([
            'patient_id' => $id,
            'encounter_id' => $v['encounter_id'] ?? null,
            'category' => $v['category'],
            'title' => $v['title'],
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
        AccessLog::record($id, 'upload', $a->title);

        return response()->json($a->load('uploader:id,name,last_name'), 201);
    }

    public function download(int $id, int $attachmentId)
    {
        $this->authorizeAny('clinical.view', 'clinical.attach');
        $a = Attachment::where('patient_id', $id)->findOrFail($attachmentId);
        abort_unless(Storage::disk('local')->exists($a->path), 404, 'El archivo ya no está en el servidor.');
        AccessLog::record($id, 'download', $a->title);

        return Storage::disk('local')->response($a->path, $a->original_name, [
            'Content-Type' => $a->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Solo quien lo subió, el mismo día (para corregir un archivo equivocado). */
    public function destroy(int $id, int $attachmentId)
    {
        $this->authorizeAny('clinical.attach');
        $a = Attachment::where('patient_id', $id)->findOrFail($attachmentId);
        if ((int) $a->uploaded_by !== (int) auth()->id() || $a->created_at->lt(now()->subDay())) {
            return $this->fail('Solo quien subió el archivo puede quitarlo, y dentro de las primeras 24 horas.', 403);
        }
        AccessLog::record($id, 'delete', "Adjunto: {$a->title}");
        $a->delete();

        return response()->json(['isSuccess' => true]);
    }
}
