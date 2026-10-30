package com.warkop.pos.model

/** Data contoh dari design/screens/pos-app-data.js. Dipakai sampai katalog diambil dari GET /products?pos=1. */
object PosCatalog {
    val categories = listOf(
        MenuCategory("kopi", "Kopi", "K", Tone.INK), MenuCategory("nonkopi", "Non-kopi", "N", Tone.TINT),
        MenuCategory("makan", "Makanan", "M", Tone.ACCENT), MenuCategory("snack", "Camilan", "C", Tone.NEUTRAL),
        MenuCategory("extra", "Tambahan", "T", Tone.OUTLINE),
    )

    val products = listOf(
        Product("k1", "coffee", "kopi", "Kopi Tubruk", 8000), Product("k2", "coffee", "kopi", "Kopi Susu", 12000),
        Product("k3", "cup-soda", "kopi", "Es Kopi Susu Aren", 18000, listOf("gula", "es")),
        Product("k4", "coffee", "kopi", "Vietnam Drip", 15000), Product("k5", "coffee", "kopi", "Kopi Jahe", 13000),
        Product("k6", "coffee", "kopi", "Cappuccino", 18000, listOf("suhu")), Product("k7", "coffee", "kopi", "Americano", 15000, listOf("suhu")),
        Product("k8", "ice-cream", "kopi", "Affogato", 22000, is86 = true),
        Product("n1", "milk", "nonkopi", "Teh Tarik", 12000, listOf("suhu")), Product("n2", "glass-water", "nonkopi", "Es Teh Manis", 6000),
        Product("n3", "coffee", "nonkopi", "Teh Poci", 10000), Product("n4", "milk", "nonkopi", "Susu Cokelat", 14000, listOf("suhu")),
        Product("n5", "flame", "nonkopi", "Wedang Jahe", 10000), Product("n6", "citrus", "nonkopi", "Es Jeruk", 10000),
        Product("n7", "cup-soda", "nonkopi", "Lemon Tea", 12000, listOf("suhu")),
        Product("m1", "soup", "makan", "Indomie Goreng", 12000, listOf("pedas", "topping")),
        Product("m2", "soup", "makan", "Indomie Rebus", 12000, listOf("pedas", "topping")),
        Product("m3", "utensils", "makan", "Nasi Goreng Kampung", 22000, listOf("pedas")),
        Product("m4", "egg", "makan", "Nasi Telur Ceplok", 15000), Product("m5", "drumstick", "makan", "Nasi Ayam Geprek", 25000, listOf("pedas")),
        Product("m6", "soup", "makan", "Mie Tek-tek", 20000, listOf("pedas")), Product("m7", "sandwich", "makan", "Roti Bakar", 15000, listOf("rasa")),
        Product("s1", "banana", "snack", "Pisang Goreng", 12000, is86 = true), Product("s2", "popcorn", "snack", "Kentang Goreng", 15000),
        Product("s3", "layers", "snack", "Tahu Crispy", 10000), Product("s4", "cookie", "snack", "Cireng Rujak", 12000),
        Product("s5", "layers", "snack", "Tempe Mendoan", 10000), Product("s6", "cookie", "snack", "Singkong Keju", 12000),
        Product("e1", "egg", "extra", "Telur Ceplok", 4000), Product("e2", "wheat", "extra", "Nasi Putih", 5000),
        Product("e3", "cookie", "extra", "Kerupuk", 2000), Product("e4", "glass-water", "extra", "Air Mineral", 5000),
    )

    val groups = mapOf(
        "gula" to ModGroup("gula", "Level gula", 1, 1, opts("Normal", "Kurang manis", "Tanpa gula")),
        "es" to ModGroup("es", "Es", 1, 1, opts("Es normal", "Sedikit es", "Tanpa es")),
        "suhu" to ModGroup("suhu", "Suhu", 1, 1, listOf(ModOption("Panas", 0), ModOption("Dingin", 2000))),
        "pedas" to ModGroup("pedas", "Level pedas", 1, 1, opts("Tidak pedas", "Sedang", "Pedas", "Pedas banget")),
        "topping" to ModGroup("topping", "Topping", 0, 2, listOf(ModOption("Telur", 4000), ModOption("Kornet", 5000), ModOption("Keju", 4000), ModOption("Sosis", 5000))),
        "rasa" to ModGroup("rasa", "Rasa", 1, 2, opts("Cokelat", "Keju", "Srikaya", "Kacang")),
    )

    val users = listOf(
        StaffUser("u1", "Rina", "Kasir", "CSH"), StaffUser("u5", "Dewi", "Kasir", "CSH"), StaffUser("u2", "Andi", "Waiter", "WTR"),
        StaffUser("u3", "Sari", "Supervisor", "SPV"), StaffUser("u4", "Budi", "Outlet Manager", "OMG"),
    )

    val areas = linkedMapOf(
        "Indoor" to listOf("1", "2", "3", "4", "5", "6", "7", "8"),
        "Teras" to listOf("T1", "T2", "T3", "T4", "T5", "T6"),
    )

    val denominations = listOf(100_000, 50_000, 20_000, 10_000, 5_000, 2_000, 1_000, 500)
    val voidReasons = listOf("Salah input", "Pelanggan batal", "Item tidak tersedia", "Kualitas / keluhan")
    val cancelReasons = listOf("Pelanggan batal", "Pesanan ganda", "Salah meja / tipe pesanan")

    /** Alasan layar → kode server untuk void/batal tab (wrong_item | customer_request | kitchen_error | other). */
    private val serverReasons = mapOf(
        "Salah input" to "wrong_item", "Pelanggan batal" to "customer_request", "Item tidak tersedia" to "other",
        "Kualitas / keluhan" to "kitchen_error", "Pesanan ganda" to "other", "Salah meja / tipe pesanan" to "wrong_item",
    )
    fun serverReasonCode(label: String): String = serverReasons[label] ?: "other"
    /** Alasan kas: kode yang dikirim ke server (`CashMovement::REASONS`) dan label layar. */
    val cashInReasons = listOf("float_topup" to "Tambah modal", "change_exchange" to "Kembalian dari supplier", "other" to "Lainnya")
    val cashOutReasons = listOf("petty_cash" to "Belanja bahan", "bank_deposit" to "Setor ke brankas", "supplier" to "Bayar ojek / kurir", "other" to "Lainnya")

    fun product(id: String) = products.first { it.id == id }
    private fun opts(vararg n: String) = n.map { ModOption(it, 0) }
}

