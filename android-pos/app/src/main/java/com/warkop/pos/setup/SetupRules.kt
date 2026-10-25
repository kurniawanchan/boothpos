package com.warkop.pos.setup

import com.warkop.pos.data.OutletChoice
import com.warkop.pos.data.TerminalChoice

/**
 * 073 — aturan layar penyiapan yang bisa diuji tanpa Compose: kapan kolom Terminal tampil, nama mana yang dikirim, dan kapan
 * tombol Lanjut aktif. Layar hanya merangkai hasilnya.
 */
object SetupRules {
    /** Kolom Terminal baru tampil SESUDAH outlet dipilih (FR-002); sebelumnya tidak ada dan tidak bisa lanjut. */
    fun showTerminalField(outlet: OutletChoice?): Boolean = outlet != null

    /** Instalasi satu outlet: outlet itu langsung terpilih supaya alurnya tetap sama (kolom Terminal tetap tampil). */
    fun preselect(outlets: List<OutletChoice>): OutletChoice? = outlets.singleOrNull()

    /**
     * Nama yang dikirim ke server: yang DIKETIK menang atas yang dipilih dari daftar (FR-004: boleh mengetik nama baru walau
     * outlet sudah punya terminal). Dipangkas; kosong bila keduanya kosong. Nama yang sama dengan terminal yang ada tidak
     * perlu ditangani di sini — server mengembalikan terminal itu (FR-006).
     */
    fun effectiveName(typed: String, picked: TerminalChoice?): String = typed.trim().ifBlank { picked?.name.orEmpty() }

    fun canSubmit(outlet: OutletChoice?, typed: String, picked: TerminalChoice?): Boolean =
        outlet != null && effectiveName(typed, picked).isNotBlank()
}
