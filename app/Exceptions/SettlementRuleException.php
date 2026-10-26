<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 076-settlement-tab-session-branding — pelanggaran aturan bisnis settlement
 * seller yang punya KODE mesin-terbaca (`code`) selain pesan, supaya UI
 * memetakan kode ke tindakan yang tepat tanpa mengurai kalimat galat.
 * Konvensi proyek: konflik aturan bisnis = 409 (BomRuleException adalah
 * kembarannya untuk BOM). render() dipakai Laravel sehingga controller tetap
 * tipis — tidak ada try/catch per endpoint.
 */
class SettlementRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  bidang tambahan respons (mis. existing_id, figure)
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function make(string $reason, array $context = [], array $messageParams = [], int $status = 409): self
    {
        return new self(__("settlements.{$reason}", $messageParams), $reason, $context, $status);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason] + $this->context, $this->status);
    }
}
