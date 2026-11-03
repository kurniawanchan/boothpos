package com.warkop.pos.model

/** 090 / US8 — satu pesanan mandiri baru. Sengaja tanpa data pelanggan (FR-039). */
data class SelfServiceAlert(val id: Long, val tableName: String, val reference: String, val totalText: String) {
    /** "Rp 55.000" dari nominal server ("55000.00"). */
    val totalLabel: String get() = rp(totalText.toDoubleOrNull()?.toInt() ?: 0)
}

/** 090 / US8 — hasil satu polling: pesanan baru sejak kursor, jumlah yang menunggu, dan kursor berikutnya. */
data class SelfServiceAlerts(val items: List<SelfServiceAlert>, val waiting: Int, val lastId: Long)

/** 090 — pesanan mandiri di tab Mandiri. Status: awaiting_verification | verified | dispatched | rejected. */
data class SelfServiceOrderView(
    val id: Long, val reference: String, val status: String, val tableName: String, val takeaway: Boolean,
    val itemCount: Int, val totalText: String, val customerName: String?, val createdAt: String?,
    val paymentReference: String?, val rejectReason: String?, val posOrderNumber: String?,
    val lines: List<SelfServiceLineView>, val detailLoaded: Boolean,
) {
    val totalLabel: String get() = rp(totalText.toDoubleOrNull()?.toInt() ?: 0)
    val awaiting: Boolean get() = status == "awaiting_verification"
    val verified: Boolean get() = status == "verified"
    val statusLabel: String get() = when (status) {
        "awaiting_verification" -> "Menunggu verifikasi"
        "verified" -> "Terverifikasi"
        "dispatched" -> "Sudah ke dapur"
        "rejected" -> "Ditolak"
        else -> status
    }
}

data class SelfServiceLineView(val name: String, val qty: Int, val totalText: String, val note: String?, val modifiers: List<String>) {
    val totalLabel: String get() = rp(totalText.toDoubleOrNull()?.toInt() ?: 0)
}

data class SelfServiceQueue(val items: List<SelfServiceOrderView>, val pending: Int)
