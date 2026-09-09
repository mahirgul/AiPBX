package com.mhrgl.aipbx.data

import android.util.Log
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import com.mhrgl.aipbx.model.*
import java.io.File
import java.util.concurrent.TimeUnit

class ApiClient(private val prefsProvider: (() -> AppPreferences?)? = null) {

    private val gson = Gson()
    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()
    private val refreshLock = Any()

    // Sertifika doğrulaması PLATFORMA bırakılır — burada hiçbir baypas yok.
    private val rawClient: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)
            .build()
    }

    private val client: OkHttpClient by lazy {
        rawClient.newBuilder()
            .addInterceptor(object : Interceptor {
                override fun intercept(chain: Interceptor.Chain): Response {
                    val originalRequest = chain.request()
                    val response = chain.proceed(originalRequest)

                    if (response.code == 401 &&
                        !originalRequest.url.encodedPath.endsWith("login.php") &&
                        !originalRequest.url.encodedPath.endsWith("refresh.php") &&
                        !originalRequest.url.encodedPath.endsWith("ping.php")
                    ) {
                        val prefs = prefsProvider?.invoke()
                        val oldToken = prefs?.token
                        val baseUrl = prefs?.serverUrl
                        if (!oldToken.isNullOrEmpty() && !baseUrl.isNullOrEmpty()) {
                            response.close()
                            var retriedRequest: Request? = null
                            synchronized(refreshLock) {
                                val currentToken = prefs.token
                                if (!currentToken.isNullOrEmpty() && currentToken != oldToken) {
                                    retriedRequest = originalRequest.newBuilder()
                                        .header("Authorization", "Bearer $currentToken")
                                        .build()
                                } else {
                                    val cleanUrl = baseUrl.trim().trimEnd('/')
                                    val refreshRequest = Request.Builder()
                                        .url("$cleanUrl/api/mobile/refresh.php")
                                        .header("Authorization", "Bearer $oldToken")
                                        .get()
                                        .build()

                                    try {
                                        rawClient.newCall(refreshRequest).execute().use { refreshResp ->
                                            if (refreshResp.isSuccessful) {
                                                val body = refreshResp.body?.string() ?: ""
                                                val loginResult = gson.fromJson(body, LoginResponse::class.java)
                                                if (loginResult != null && loginResult.success && !loginResult.token.isNullOrEmpty()) {
                                                    prefs.saveLogin(loginResult)
                                                    Log.i("ApiClient", "Token successfully refreshed reactively after 401")
                                                    retriedRequest = originalRequest.newBuilder()
                                                        .header("Authorization", "Bearer ${loginResult.token}")
                                                        .build()
                                                }
                                            } else {
                                                Log.w("ApiClient", "Reactive refresh failed with HTTP ${refreshResp.code}")
                                            }
                                            Unit
                                        }
                                    } catch (e: Exception) {
                                        Log.e("ApiClient", "Exception during reactive token refresh", e)
                                    }
                                }
                            }
                            if (retriedRequest != null) {
                                return chain.proceed(retriedRequest!!)
                            }
                        }
                    }
                    return response
                }
            })
            .build()
    }

    suspend fun ping(baseUrl: String): Result<ServerInfo> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            val endpoint = "$cleanUrl/api/mobile/ping.php"
            val request = Request.Builder()
                .url(endpoint)
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                if (!response.isSuccessful) {
                    return@withContext Result.failure(Exception("HTTP ${response.code}: Sunucu yanıt vermedi"))
                }
                val info = gson.fromJson(body, ServerInfo::class.java)
                if (info.success) {
                    Result.success(info)
                } else {
                    Result.failure(Exception("Geçersiz sunucu yanıtı"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun login(baseUrl: String, userOrExt: String, pass: String): Result<LoginResponse> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                val endpoint = "$cleanUrl/api/mobile/login.php"

                val jsonBody = JSONObject().apply {
                    put("username", userOrExt.trim())
                    put("password", pass.trim())
                    put("device_name", "Android-${android.os.Build.MODEL}")
                }.toString()

                val request = Request.Builder()
                    .url(endpoint)
                    .post(jsonBody.toRequestBody(jsonMediaType))
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val result = gson.fromJson(body, LoginResponse::class.java)
                    if (response.isSuccessful && result.success) {
                        Result.success(result)
                    } else {
                        val errMsg = result?.error ?: "Giriş başarısız (HTTP ${response.code})"
                        Result.failure(Exception(errMsg))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    suspend fun getCallHistory(
        baseUrl: String,
        token: String,
        filter: String = "all",
        query: String? = null,
        limit: Int = 50
    ): Result<CallHistoryResponse> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            var url = "$cleanUrl/api/mobile/call_history.php?filter=$filter&limit=$limit"
            if (!query.isNullOrBlank()) {
                url += "&q=${java.net.URLEncoder.encode(query, "UTF-8")}"
            }
            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer $token")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                val result = gson.fromJson(body, CallHistoryResponse::class.java)
                if (response.isSuccessful && result != null && result.success) {
                    Result.success(result)
                } else {
                    Result.failure(Exception("Arama geçmişi alınamadı (HTTP ${response.code})"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun getContacts(
        baseUrl: String,
        token: String
    ): Result<ContactsResponse> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            val endpoint = "$cleanUrl/api/mobile/contacts.php"
            val request = Request.Builder()
                .url(endpoint)
                .addHeader("Authorization", "Bearer $token")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                val result = gson.fromJson(body, ContactsResponse::class.java)
                if (response.isSuccessful && result != null && result.success) {
                    Result.success(result)
                } else {
                    Result.failure(Exception("Rehber listesi alınamadı (HTTP ${response.code})"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun getFeatures(
        baseUrl: String,
        token: String
    ): Result<FeaturesResponse> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            val endpoint = "$cleanUrl/api/mobile/features.php"
            val request = Request.Builder()
                .url(endpoint)
                .addHeader("Authorization", "Bearer $token")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                val result = gson.fromJson(body, FeaturesResponse::class.java)
                if (response.isSuccessful && result != null && result.success) {
                    Result.success(result)
                } else {
                    Result.failure(Exception("Özellikler alınamadı (HTTP ${response.code})"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun updateFeatures(
        baseUrl: String,
        token: String,
        dndEnabled: Boolean,
        callForwardNumber: String,
        cfBusyNumber: String = "",
        cfNoAnswerNumber: String = "",
        cfNoAnswerTimeout: Int = 20,
        phoneMode: String = "both"
    ): Result<FeaturesResponse> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            val endpoint = "$cleanUrl/api/mobile/features.php"

            val jsonBody = JSONObject().apply {
                put("dnd_enabled", dndEnabled)
                put("call_forward_number", callForwardNumber.trim())
                put("cf_busy_number", cfBusyNumber.trim())
                put("cf_noanswer_number", cfNoAnswerNumber.trim())
                put("cf_noanswer_timeout", cfNoAnswerTimeout)
                put("allowed_phone_mode", phoneMode)
            }.toString()

            val request = Request.Builder()
                .url(endpoint)
                .addHeader("Authorization", "Bearer $token")
                .post(jsonBody.toRequestBody(jsonMediaType))
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                val result = gson.fromJson(body, FeaturesResponse::class.java)
                if (response.isSuccessful && result != null && result.success) {
                    Result.success(result)
                } else {
                    Result.failure(Exception(result?.message ?: "Ayarlar güncellenemedi (HTTP ${response.code})"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun registerFcmToken(
        baseUrl: String,
        token: String,
        fcmToken: String,
        deviceId: String? = null,
        deviceName: String? = null
    ): Result<FcmTokenResponse> = withContext(Dispatchers.IO) {
        try {
            val cleanUrl = baseUrl.trim().trimEnd('/')
            val endpoint = "$cleanUrl/api/mobile/fcm_token.php"

            val jsonBody = JSONObject().apply {
                put("fcm_token", fcmToken.trim())
                put("device_id", deviceId ?: android.os.Build.ID)
                put("device_name", deviceName ?: "Android-${android.os.Build.MODEL}")
                put("platform", "android")
                put("app_version", com.mhrgl.aipbx.BuildConfig.VERSION_NAME)
            }.toString()

            val request = Request.Builder()
                .url(endpoint)
                .addHeader("Authorization", "Bearer $token")
                .post(jsonBody.toRequestBody(jsonMediaType))
                .build()

            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: ""
                val result = gson.fromJson(body, FcmTokenResponse::class.java)
                if (response.isSuccessful && result != null && result.success) {
                    Result.success(result)
                } else {
                    Result.failure(Exception("FCM token kaydedilemedi (HTTP ${response.code})"))
                }
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun refreshToken(baseUrl: String, token: String): Result<LoginResponse> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                val endpoint = "$cleanUrl/api/mobile/refresh.php"

                val request = Request.Builder()
                    .url(endpoint)
                    .addHeader("Authorization", "Bearer $token")
                    .get()
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val result = gson.fromJson(body, LoginResponse::class.java)
                    if (response.isSuccessful && result != null && result.success) {
                        Result.success(result)
                    } else {
                        val errMsg = result?.error ?: "Token yenileme başarısız (HTTP ${response.code})"
                        Result.failure(Exception(errMsg))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    // --- CHAT API METHODS ---

    suspend fun getChatConversations(baseUrl: String, token: String): Result<List<ChatConversation>> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                val endpoint = "$cleanUrl/chat/api/conversations"

                val request = Request.Builder()
                    .url(endpoint)
                    .addHeader("Authorization", "Bearer $token")
                    .get()
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val res = gson.fromJson(body, ChatConversationsResponse::class.java)
                    if (response.isSuccessful && res != null && res.success) {
                        Result.success(res.conversations ?: emptyList())
                    } else {
                        Result.failure(Exception("Sohbetler alınamadı (HTTP ${response.code})"))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    suspend fun getChatMessages(baseUrl: String, token: String, convId: Int, limit: Int = 50, beforeId: Long = 0): Result<List<ChatMessage>> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                var endpoint = "$cleanUrl/chat/api/messages?conversation_id=$convId&limit=$limit"
                if (beforeId > 0) {
                    endpoint += "&before_id=$beforeId"
                }

                val request = Request.Builder()
                    .url(endpoint)
                    .addHeader("Authorization", "Bearer $token")
                    .get()
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val res = gson.fromJson(body, ChatMessagesResponse::class.java)
                    if (response.isSuccessful && res != null && res.success) {
                        Result.success(res.messages ?: emptyList())
                    } else {
                        Result.failure(Exception("Mesajlar alınamadı (HTTP ${response.code})"))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    suspend fun createDirectChat(baseUrl: String, token: String, targetExt: String): Result<ChatConversation> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                val endpoint = "$cleanUrl/chat/api/conversations/direct"
                val jsonBody = JSONObject().put("target_extension", targetExt).toString()

                val request = Request.Builder()
                    .url(endpoint)
                    .addHeader("Authorization", "Bearer $token")
                    .post(jsonBody.toRequestBody(jsonMediaType))
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val res = gson.fromJson(body, DirectChatResponse::class.java)
                    if (response.isSuccessful && res != null && res.success && res.conversation != null) {
                        Result.success(res.conversation)
                    } else {
                        Result.failure(Exception("Sohbet oluşturulamadı (HTTP ${response.code})"))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }

    suspend fun uploadChatFile(baseUrl: String, token: String, file: File, mimeType: String): Result<ChatUploadResponse> =
        withContext(Dispatchers.IO) {
            try {
                val cleanUrl = baseUrl.trim().trimEnd('/')
                val endpoint = "$cleanUrl/chat/api/upload"

                val mediaType = mimeType.toMediaTypeOrNull()
                val requestBody = MultipartBody.Builder()
                    .setType(MultipartBody.FORM)
                    .addFormDataPart("file", file.name, file.asRequestBody(mediaType))
                    .build()

                val request = Request.Builder()
                    .url(endpoint)
                    .addHeader("Authorization", "Bearer $token")
                    .post(requestBody)
                    .build()

                client.newCall(request).execute().use { response ->
                    val body = response.body?.string() ?: ""
                    val res = gson.fromJson(body, ChatUploadResponse::class.java)
                    if (response.isSuccessful && res != null && res.success) {
                        Result.success(res)
                    } else {
                        Result.failure(Exception(res?.error ?: "Dosya yüklenemedi (HTTP ${response.code})"))
                    }
                }
            } catch (e: Exception) {
                Result.failure(e)
            }
        }
}
