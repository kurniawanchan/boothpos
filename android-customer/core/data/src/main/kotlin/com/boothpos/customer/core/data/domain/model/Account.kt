package com.boothpos.customer.core.data.domain.model

import java.time.Instant

/** Akun pelanggan yang sedang masuk (kontrak §5). E-mail tidak dapat diubah di v1. */
data class Account(
    val id: Long,
    val name: String,
    val email: String,
    val phone: String?,
    val emailVerified: Boolean,
    val createdAt: Instant?,
) {
    // Nama, e-mail, dan telepon tidak boleh muncul bila objek ini tercetak di log atau laporan crash.
    override fun toString(): String = "Account(id=$id)"
}
