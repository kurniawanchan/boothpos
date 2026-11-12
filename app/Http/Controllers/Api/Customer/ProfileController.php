<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Resources\Customer\CustomerAccountResource;
use App\Services\CustomerCommerce\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §5) — profil akun yang sedang masuk. Subjeknya
 * selalu pemilik token (`$request->user('customer')`); tidak ada id akun di
 * URL, jadi tidak ada cara meminta profil orang lain.
 */
class ProfileController extends Controller
{
    public function __construct(private CustomerAccountService $accounts) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new CustomerAccountResource($request->user('customer'))]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $account = $this->accounts->updateProfile($request->user('customer'), $request->validated());

        return response()->json(['data' => new CustomerAccountResource($account)]);
    }
}
