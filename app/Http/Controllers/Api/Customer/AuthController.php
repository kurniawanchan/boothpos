<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\LoginRequest;
use App\Http\Requests\Customer\RegisterRequest;
use App\Http\Resources\Customer\CustomerAccountResource;
use App\Models\CustomerAccount;
use App\Services\CustomerCommerce\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Customer commerce (CONTRACT §5) — daftar, masuk, keluar untuk aplikasi
 * pelanggan. TERPISAH dari App\Http\Controllers\Api\AuthController milik
 * staf: tidak ada kode bersama dan tidak ada percabangan "kalau pelanggan"
 * di controller staf. Aturan bisnisnya ada di CustomerAccountService.
 */
class AuthController extends Controller
{
    public function __construct(private CustomerAccountService $accounts) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $account = $this->accounts->register($request->validated());

        return $this->tokenResponse($account, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $account = $this->accounts->authenticate($credentials['email'], $credentials['password']);

        return $this->tokenResponse($account, 200);
    }

    public function logout(Request $request): Response
    {
        $this->accounts->revokeCurrentToken($request->user('customer'));

        return response()->noContent();
    }

    private function tokenResponse(CustomerAccount $account, int $status): JsonResponse
    {
        $token = $this->accounts->issueToken($account);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            'account' => new CustomerAccountResource($account),
        ], $status);
    }
}
