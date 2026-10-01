<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 027-preorder-duplicate-split — asal sebuah pre-order hasil "Duplikat" atau
 * "Pisah". Ketiganya NULL untuk pre-order biasa.
 *
 * - `source_preorder_id` memakai nullOnDelete, BUKAN restrict: pre-order sumber
 *   berstatus "ordered" tanpa pembayaran masih boleh dihapus (PreorderService::
 *   delete()), dan salinan tidak boleh ikut menghalanginya.
 * - `source_preorder_number` adalah snapshot nomor sumber, supaya tulisan
 *   "Duplikat dari PO-…" tetap bermakna setelah sumbernya dihapus (data
 *   historis disimpan sebagai snapshot, bukan diturunkan ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->foreignId('source_preorder_id')->nullable()->after('cancel_reason')
                ->constrained('preorders')->nullOnDelete();
            $table->enum('source_type', ['duplicate', 'split'])->nullable()->after('source_preorder_id');
            $table->string('source_preorder_number', 30)->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_preorder_id');
            $table->dropColumn(['source_type', 'source_preorder_number']);
        });
    }
};
