<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddressRequest;
use App\Http\Resources\Customer\CustomerAddressResource;
use App\Services\CustomerCommerce\CustomerAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Customer commerce (CONTRACT §7) — alamat tersimpan akun yang sedang masuk.
 *
 * Parameter rute `{addressId}` SENGAJA berupa angka polos, bukan
 * route-model binding: binding akan mengambil alamat dengan id itu tanpa
 * melihat pemiliknya. Alamat selalu dicari CustomerAddressService lewat
 * `$account->addresses()`.
 */
class AddressController extends Controller
{
    public function __construct(private CustomerAddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CustomerAddressResource::collection($this->addresses->listFor($request->user('customer'))),
        ]);
    }

    public function show(Request $request, string $addressId): JsonResponse
    {
        $address = $this->addresses->find($request->user('customer'), (int) $addressId);

        return response()->json(['data' => new CustomerAddressResource($address)]);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user('customer'), $request->validated());

        return response()->json(['data' => new CustomerAddressResource($address)], 201);
    }

    public function update(AddressRequest $request, string $addressId): JsonResponse
    {
        $address = $this->addresses->update($request->user('customer'), (int) $addressId, $request->validated());

        return response()->json(['data' => new CustomerAddressResource($address)]);
    }

    public function destroy(Request $request, string $addressId): Response
    {
        $this->addresses->delete($request->user('customer'), (int) $addressId);

        return response()->noContent();
    }
}
