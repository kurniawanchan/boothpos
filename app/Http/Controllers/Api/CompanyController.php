<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateCompanyRequest;
use App\Http\Requests\DeactivateCompanyRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(private CompanyOnboardingService $onboardingService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $companies = Company::query()
            ->with(['businessType', 'license', 'owner'])
            ->withCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('business_type_id'), fn ($q) => $q->where('business_type_id', $request->integer('business_type_id')))
            ->when($request->filled('license_id'), fn ($q) => $q->where('license_id', $request->integer('license_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => CompanyResource::collection($companies->items()),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'last_page' => $companies->lastPage(),
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->onboardingService->onboard($request->validated(), $request->user());

        return response()->json(new CompanyResource($company->load(['businessType', 'license', 'owner'])->loadCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')])), 201);
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->load(['businessType', 'license', 'owner'])->loadCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')]);

        return response()->json(new CompanyResource($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company = $this->onboardingService->update($company, $request->validated());

        return response()->json(new CompanyResource($company->load(['businessType', 'license', 'owner'])->loadCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')])));
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        try {
            $this->onboardingService->delete($company);
        } catch (ValidationException $e) {
            // Konflik aturan bisnis (masih punya invoice) dipetakan ke 409,
            // konsisten dengan konvensi InvoiceController::destroy() (R10).
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(null, 204);
    }

    /**
     * 019-billing-system (third expansion) — tidak lagi butuh kode dari
     * client, hanya butuh Invoice 'paid' milik company ini sudah ada
     * (research.md R14). Konflik aturan bisnis (sudah aktif / belum ada
     * invoice lunas) dipetakan ke 409, konsisten dengan konvensi
     * delete-guard Invoice/License/Company lain di kodebase ini.
     */
    public function activate(ActivateCompanyRequest $request, Company $company): JsonResponse
    {
        try {
            $company = $this->onboardingService->activate($company);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new CompanyResource($company->load(['businessType', 'license', 'owner'])->loadCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')])));
    }

    /**
     * 019-billing-system (third expansion) — kebalikan activate(): mengunci
     * kembali login owner tanpa menghapus company/invoice-nya sama sekali.
     */
    public function deactivate(DeactivateCompanyRequest $request, Company $company): JsonResponse
    {
        try {
            $company = $this->onboardingService->deactivate($company);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new CompanyResource($company->load(['businessType', 'license', 'owner'])->loadCount(['invoices as paid_invoices_count' => fn ($q) => $q->where('status', 'paid')])));
    }
}
