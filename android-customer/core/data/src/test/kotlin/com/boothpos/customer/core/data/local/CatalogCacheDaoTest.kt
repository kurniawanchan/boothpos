package com.boothpos.customer.core.data.local

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class CatalogCacheDaoTest {

    private lateinit var database: AppDatabase
    private lateinit var dao: CatalogCacheDao

    @Before
    fun setUp() {
        database = Room.inMemoryDatabaseBuilder(ApplicationProvider.getApplicationContext<Context>(), AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        dao = database.catalogCacheDao()
    }

    @After
    fun tearDown() = database.close()

    private fun product(index: Int, cachedAt: Long, position: Int = index, name: String = "Produk $index") =
        CachedProductEntity(
            slug = "produk-$index",
            name = name,
            imageUrl = null,
            categoryId = 1,
            categoryName = "Aksesori",
            sellerName = "Penjual Uji",
            priceMin = "10000.00",
            priceMax = "20000.00",
            inStock = true,
            listPosition = position,
            cachedAt = cachedAt,
        )

    private fun variant(id: Long, slug: String) =
        CachedVariantEntity(id, slug, "Varian $id", null, "10000.00", inStock = true, maxQty = 5, position = 0)

    @Test
    fun `cache dipangkas ke batas tetap dan yang tertua dibuang`() = runTest {
        val overflow = 5
        dao.cacheListedProducts((1..CatalogCacheLimits.MAX_PRODUCTS).map { product(it, cachedAt = 1_000) })
        dao.cacheListedProducts(
            (1..overflow).map { product(CatalogCacheLimits.MAX_PRODUCTS + it, cachedAt = 2_000) },
        )

        assertEquals(CatalogCacheLimits.MAX_PRODUCTS, dao.countProducts())
        assertNotNull("Yang baru di-cache bertahan", dao.findProductWithDetail("produk-${CatalogCacheLimits.MAX_PRODUCTS + 1}"))
        // Di antara yang seumur, posisi daftar terbesar yang lebih dulu dibuang.
        assertNull(dao.findProductWithDetail("produk-${CatalogCacheLimits.MAX_PRODUCTS}"))
        assertNotNull(dao.findProductWithDetail("produk-1"))
    }

    @Test
    fun `memangkas produk ikut menghapus detail dan variannya`() = runTest {
        dao.cacheProductDetail(product(1, cachedAt = 1_000), "Deskripsi", listOf(variant(11, "produk-1")), cap = 10)
        assertEquals(1, dao.findProductWithDetail("produk-1")?.variants?.size)

        dao.cacheListedProducts(listOf(product(2, cachedAt = 2_000)), cap = 1)

        assertNull(dao.findProductWithDetail("produk-1"))
        assertEquals(1, dao.countProducts())
    }

    @Test
    fun `menyimpan detail mempertahankan posisi daftar dan mengganti varian lama`() = runTest {
        dao.cacheListedProducts(listOf(product(1, cachedAt = 1_000, position = 3)))
        dao.cacheProductDetail(
            product(1, cachedAt = 2_000, position = CatalogCacheLimits.UNLISTED_POSITION, name = "Nama Baru"),
            "Deskripsi",
            listOf(variant(11, "produk-1"), variant(12, "produk-1")),
        )
        dao.cacheProductDetail(
            product(1, cachedAt = 3_000, position = CatalogCacheLimits.UNLISTED_POSITION, name = "Nama Baru"),
            null,
            listOf(variant(12, "produk-1")),
        )

        val cached = dao.findProductWithDetail("produk-1")!!
        assertEquals(3, cached.product.listPosition)
        assertEquals("Nama Baru", cached.product.name)
        assertNull(cached.detail?.description)
        assertEquals(listOf(12L), cached.variants.map { it.id })
        assertEquals(listOf("produk-1"), dao.observeListedProducts(50).first().map { it.slug })
    }

    @Test
    fun `produk yang hanya dibuka lewat detail tidak muncul di daftar offline`() = runTest {
        dao.cacheProductDetail(
            product(9, cachedAt = 1_000, position = CatalogCacheLimits.UNLISTED_POSITION),
            null,
            emptyList(),
        )

        assertEquals(emptyList<CachedProductEntity>(), dao.observeListedProducts(50).first())
        assertNotNull(dao.findProductWithDetail("produk-9"))
    }

    @Test
    fun `halaman pertama yang baru menggantikan daftar offline lama tanpa membuang cache detailnya`() = runTest {
        dao.cacheListedProducts(listOf(product(1, cachedAt = 1_000), product(2, cachedAt = 1_000)))
        dao.cacheProductDetail(product(1, cachedAt = 1_500), "Deskripsi", listOf(variant(11, "produk-1")))

        dao.replaceListedProducts(listOf(product(3, cachedAt = 2_000, position = 0), product(2, cachedAt = 2_000, position = 1)))

        assertEquals(listOf("produk-3", "produk-2"), dao.observeListedProducts(50).first().map { it.slug })
        // Produk 1 keluar dari daftar offline tetapi detailnya masih dapat dibuka dari cache.
        assertEquals(1, dao.findProductWithDetail("produk-1")?.variants?.size)
    }

    @Test
    fun `menghapus satu produk ikut menghapus detail dan variannya`() = runTest {
        dao.cacheProductDetail(product(1, cachedAt = 1_000), "Deskripsi", listOf(variant(11, "produk-1")))
        dao.cacheListedProducts(listOf(product(2, cachedAt = 1_000)))

        dao.deleteProduct("produk-1")

        assertNull(dao.findProductWithDetail("produk-1"))
        assertEquals(1, dao.countProducts())
    }

    @Test
    fun `kategori diganti seluruhnya dan dibatasi`() = runTest {
        dao.replaceCategories(listOf(CachedCategoryEntity(1, "Lama", null, 0)))
        dao.replaceCategories(
            (1..CatalogCacheLimits.MAX_CATEGORIES + 5).map { CachedCategoryEntity(it.toLong() + 100, "Kategori $it", null, it) },
        )

        val categories = dao.observeCategories().first()
        assertEquals(CatalogCacheLimits.MAX_CATEGORIES, categories.size)
        assertEquals("Kategori 1", categories.first().name)
    }
}
