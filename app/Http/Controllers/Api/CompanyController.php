<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateCompanyRequest;
use App\Http\Requests\StoreCompanyRequest;
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
            ->with(['businessType', 'package', 'owner'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('business_type_id'), fn ($q) => $q->where('business_type_id', $request->integer('business_type_id')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
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

        return response()->json(new CompanyResource($company->load(['businessType', 'package', 'owner'])), 201);
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->load(['businessType', 'package', 'owner']);

        return response()->json(new CompanyResource($company));
    }

    public function resendActivation(Request $request, Company $company): JsonResponse
    {
        $this->authorize('activate', $company);

        try {
            $company = $this->onboardingService->resendActivationCode($company);
        } catch (ValidationException $e) {
            // Konflik aturan bisnis (sudah aktif) dipetakan ke 409, bukan
            // 422 — konsisten dengan konvensi OrderController.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new CompanyResource($company->load(['businessType', 'package', 'owner'])));
    }

    public function activate(ActivateCompanyRequest $request, Company $company): JsonResponse
    {
        try {
            $company = $this->onboardingService->activate($company, $request->validated()['code']);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return response()->json(new CompanyResource($company->load(['businessType', 'package', 'owner'])));
    }
}
