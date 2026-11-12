package com.boothpos.customer.core.network.interceptor

import com.boothpos.customer.core.network.TokenProvider
import com.boothpos.customer.core.network.UnauthorizedEvents
import okhttp3.HttpUrl
import okhttp3.Interceptor
import okhttp3.Response

/**
 * Menempelkan token pelanggan HANYA pada panggilan `…/customer/…` ke host API yang dikonfigurasi.
 *
 * Kenapa sesempit itu:
 * - storefront bersifat publik, jadi token tidak perlu ikut (dan tidak boleh bocor ke log proxy);
 * - host lain (mis. URL gambar) tidak boleh pernah menerima token;
 * - login/register tidak membawa token, supaya 401 `invalid_credentials` dari login tidak disangka
 *   "sesi berakhir".
 *
 * Bila server menjawab 401 untuk permintaan yang membawa token, kejadiannya diteruskan ke
 * [UnauthorizedEvents]; penghapusan sesi adalah keputusan lapisan sesi, bukan interseptor ini.
 */
class AuthInterceptor(
    private val apiBaseUrl: HttpUrl,
    private val tokenProvider: TokenProvider,
    private val unauthorizedEvents: UnauthorizedEvents,
) : Interceptor {

    private val customerPrefix = apiBaseUrl.encodedPath + "customer/"
    private val publicPaths = setOf(customerPrefix + "auth/login", customerPrefix + "auth/register")

    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        // Header yang sudah dipasang pemanggil (logout dengan token yang baru saja dilepas) dibiarkan.
        if (request.header(AUTHORIZATION) != null || !requiresToken(request.url)) {
            return chain.proceed(request)
        }
        val token = tokenProvider.currentToken() ?: return chain.proceed(request)
        val response = chain.proceed(
            request.newBuilder().header(AUTHORIZATION, "Bearer $token").build(),
        )
        if (response.code == 401) {
            unauthorizedEvents.notifyRejected(token)
        }
        return response
    }

    internal fun requiresToken(url: HttpUrl): Boolean =
        url.scheme == apiBaseUrl.scheme &&
            url.host == apiBaseUrl.host &&
            url.port == apiBaseUrl.port &&
            url.encodedPath.startsWith(customerPrefix) &&
            url.encodedPath !in publicPaths

    companion object {
        const val AUTHORIZATION = "Authorization"
    }
}
