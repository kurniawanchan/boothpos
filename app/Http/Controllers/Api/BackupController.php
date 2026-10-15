<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Antarmuka cadangan & pemulihan database (Pengaturan). Seluruh endpoint hanya
 * untuk owner/admin: cadangan memuat SELURUH data (termasuk hash kata sandi dan
 * data pelanggan), dan pemulihan menimpa semuanya.
 *
 * Pemulihan meminta kata konfirmasi "RESTORE" di sisi server — bukan hanya
 * dialog di frontend — supaya request nyasar/otomatis tidak bisa menimpa data.
 */
class BackupController extends Controller
{
    public const CONFIRM_WORD = 'RESTORE';

    public function __construct(private BackupService $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => $this->backups->list()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            return response()->json($this->backups->create(), 201);
        } catch (BackupException $e) {
            return $this->failure($e);
        }
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $path = $this->backups->sqlPath($id) ?? abort(404, __('backups.not_found'));

        return response()->streamDownload(function () use ($path) {
            readfile($path);
        }, "boothpos-backup-{$id}.sql", ['Content-Type' => 'application/sql']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorizeAdmin($request);
        abort_unless($this->backups->delete($id, $request->user()->id), 404, __('backups.not_found'));

        return response()->noContent();
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $path = $this->backups->sqlPath($id) ?? abort(404, __('backups.not_found'));
        $this->validateConfirmation($request);

        return $this->runRestore($request, $path, $id);
    }

    public function restoreUpload(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate([
            'file' => ['required', 'file', 'extensions:sql', 'max:51200'],
            'confirm' => ['required', Rule::in([self::CONFIRM_WORD])],
        ], [
            // Berkas yang ditolak PHP sendiri (melewati upload_max_filesize,
            // unggahan terputus) tiba sebagai UploadedFile tidak valid; pesan
            // bawaan Laravel ("failed to upload") tak memberi tahu apa-apa.
            'file.uploaded' => __('backups.upload_failed'),
        ]);

        $path = $request->file('file')->getRealPath();
        if (! $this->backups->looksLikeAppDump($path)) {
            throw ValidationException::withMessages(['file' => __('backups.invalid_dump')]);
        }

        return $this->runRestore($request, $path, 'upload');
    }

    private function runRestore(Request $request, string $sqlPath, string $source): JsonResponse
    {
        try {
            return response()->json($this->backups->restoreFromFile($sqlPath, $source, $request->user()->id));
        } catch (BackupException $e) {
            return $this->failure($e);
        }
    }

    private function validateConfirmation(Request $request): void
    {
        $request->validate(['confirm' => ['required', Rule::in([self::CONFIRM_WORD])]]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('backups.not_authorized'));
    }

    private function failure(BackupException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 500);
    }
}
