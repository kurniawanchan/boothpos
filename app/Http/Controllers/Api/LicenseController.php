<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateLicenseRequest;
use App\Services\LicenseActivationService;
use Illuminate\Http\JsonResponse;

class LicenseController extends Controller
{
    public function __construct(private LicenseActivationService $licenseActivationService) {}

    public function status(): JsonResponse
    {
        return response()->json(['activated' => $this->licenseActivationService->isActivated()]);
    }

    public function activate(ActivateLicenseRequest $request): JsonResponse
    {
        try {
            $this->licenseActivationService->activate($request->validated()['license_key']);
        } catch (\InvalidArgumentException) {
            // Satu alasan generik saja (FR-003) — tidak membedakan
            // "bentuk salah" dari "tanda tangan salah" dari "field
            // hilang" di respons API, supaya tidak jadi oracle untuk
            // menebak kunci valid lewat percobaan.
            return response()->json([
                'message' => __('license.license_key_invalid'),
                'errors' => ['license_key' => [__('license.license_key_invalid')]],
            ], 422);
        }

        return response()->json(['activated' => true]);
    }
}
