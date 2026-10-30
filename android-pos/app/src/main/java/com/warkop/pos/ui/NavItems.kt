package com.warkop.pos.ui

import com.warkop.pos.state.Dest

/**
 * 084 — daftar menu navigasi dan URUTANNYA di satu tempat (tanpa ikon, jadi teruji tanpa Compose): Kasir, Meja, Pesanan,
 * Shift, Koneksi. Rail (layar lebar) dan bilah bawah (layar sempit) memakai daftar yang sama, sehingga urutan dan istilah
 * tidak bisa berbeda antar tata letak. "Koneksi" dulu "Perangkat" dan berdiri terpisah di dasar rail.
 */
data class NavSpec(val dest: Dest, val label: String)

val NAV_SPECS: List<NavSpec> = listOf(
    NavSpec(Dest.EDITOR, "Kasir"),
    NavSpec(Dest.TABLES, "Meja"),
    NavSpec(Dest.ORDERS, "Pesanan"),
    NavSpec(Dest.SHIFT, "Shift"),
    NavSpec(Dest.SYNC, "Koneksi"),
)
