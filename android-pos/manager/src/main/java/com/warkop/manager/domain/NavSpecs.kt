package com.warkop.manager.domain

/** Tujuan navigasi utama aplikasi manajemen. */
enum class Dest { DASHBOARD, KITCHEN, DINING, CATEGORIES, PRODUCTS, STOCK, VENDORS, MATERIALS, PURCHASE_ORDERS }

enum class NavGroup(val label: String) { HOME("Beranda"), RESTAURANT("Restoran"), INVENTORY("Inventori"), PURCHASE("Pembelian") }

/** Satu baris menu: tujuan, label, grup, dan kunci menu server yang WAJIB dipegang peran (sama dengan `meta.menuKey` web). */
data class NavSpec(val dest: Dest, val label: String, val group: NavGroup, val menuKey: String)

/**
 * 094 / T037, FR-011/FR-012 — SATU daftar menu aplikasi. Menu tampil hanya bila peran memegang kunci menu yang sama dengan
 * web; server tetap menolak aksi tanpa izin. Lantai & Tab sengaja TIDAK ada (keputusan pemilik 2026-11-25).
 */
object NavSpecs {
    val ALL: List<NavSpec> = listOf(
        NavSpec(Dest.DASHBOARD, "Dashboard", NavGroup.HOME, "dashboard"),
        NavSpec(Dest.KITCHEN, "Dapur & Bar", NavGroup.RESTAURANT, "kitchen"),
        NavSpec(Dest.DINING, "Pengaturan Restoran", NavGroup.RESTAURANT, "dining_setup"),
        NavSpec(Dest.CATEGORIES, "Kategori", NavGroup.INVENTORY, "categories"),
        NavSpec(Dest.PRODUCTS, "Produk", NavGroup.INVENTORY, "products"),
        NavSpec(Dest.STOCK, "Stok", NavGroup.INVENTORY, "stock"),
        NavSpec(Dest.VENDORS, "Vendor", NavGroup.PURCHASE, "vendors"),
        NavSpec(Dest.MATERIALS, "Bahan", NavGroup.PURCHASE, "materials"),
        NavSpec(Dest.PURCHASE_ORDERS, "Purchase Order", NavGroup.PURCHASE, "purchase_orders"),
    )

    /** Menu yang boleh tampil untuk himpunan kunci menu ini, urutan tetap. Kosong = layar "Tidak ada menu". */
    fun visible(menuKeys: Set<String>): List<NavSpec> = ALL.filter { it.menuKey in menuKeys }

    /** Tujuan awal setelah login: Dashboard bila boleh, selain itu menu pertama yang boleh. */
    fun start(menuKeys: Set<String>): Dest? = visible(menuKeys).firstOrNull()?.dest
}
