package com.mhrgl.aipbx.util

import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.util.Log
import android.widget.Toast
import androidx.core.content.FileProvider
import com.mhrgl.aipbx.BuildConfig
import com.mhrgl.aipbx.data.AppPreferences
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.BufferedReader
import java.io.File
import java.io.FileOutputStream
import java.io.InputStreamReader
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

object AppLogManager {

    private const val TAG = "AppLogManager"

    /**
     * Cihaz ve sistem bilgilerini toplayıp güncel Logcat çıktısıyla birleştirir.
     */
    suspend fun collectLogs(context: Context): String = withContext(Dispatchers.IO) {
        val sb = StringBuilder()
        val prefs = AppPreferences.getInstance(context)
        val dateFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss.SSS", Locale.getDefault())
        val now = dateFormat.format(Date())

        sb.append("====================================================\n")
        sb.append("            AI PBX SİSTEM VE TANI RAPORU            \n")
        sb.append("====================================================\n")
        sb.append("Tarih & Saat     : $now\n")
        sb.append("Uygulama Sürümü  : v${BuildConfig.VERSION_NAME} (Build ${BuildConfig.VERSION_CODE})\n")
        sb.append("Paket Adı        : ${context.packageName}\n")
        sb.append("Cihaz            : ${Build.MANUFACTURER} ${Build.MODEL} (${Build.PRODUCT})\n")
        sb.append("Android Sürümü   : ${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})\n")
        sb.append("CPU ABI          : ${Build.SUPPORTED_ABIS.joinToString(", ")}\n")
        sb.append("----------------------------------------------------\n")
        sb.append("Santral Sunucusu : ${prefs.serverUrl}\n")
        sb.append("Domain           : ${prefs.domain}\n")
        sb.append("Dahili (Ext)     : ${prefs.extension}\n")
        sb.append("Kullanıcı Adı    : ${prefs.username}\n")
        sb.append("Ad Soyad         : ${prefs.fullName}\n")
        sb.append("WebSocket URL    : ${prefs.wsUrl}\n")
        sb.append("TURN Sunucusu    : ${prefs.turnUrl ?: "Yok"}\n")
        sb.append("FCM Token        : ${if (!prefs.fcmToken.isNullOrEmpty()) "Mevcut (${prefs.fcmToken?.take(15)}...)" else "Yok"}\n")
        sb.append("Aktif Ağ Türü    : ${getActiveNetworkType(context)}\n")
        sb.append("====================================================\n\n")

        sb.append("--- LOGCAT ÇIKTISI (Son Olaylar) ---\n")

        try {
            // Logcat'ten son 1500 satırı çek
            val process = Runtime.getRuntime().exec(arrayOf("logcat", "-d", "-v", "time", "-t", "1500"))
            val reader = BufferedReader(InputStreamReader(process.inputStream))
            var line: String?
            while (reader.readLine().also { line = it } != null) {
                sb.append(line).append("\n")
            }
            reader.close()
            process.waitFor()
        } catch (e: Exception) {
            sb.append("Logcat okuma hatası: ${e.message}\n")
            Log.e(TAG, "Logcat reading failed", e)
        }

        sb.toString()
    }

    /**
     * Aktif ağ türünü belirler (Wi-Fi, Mobil, vs.)
     */
    private fun getActiveNetworkType(context: Context): String {
        return try {
            val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return "Bilinmiyor"
            val network = cm.activeNetwork ?: return "Ağ Yok"
            val caps = cm.getNetworkCapabilities(network) ?: return "Bilinmiyor"
            when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "Wi-Fi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "Hücresel (Mobil Veri)"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "Ethernet"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN) -> "VPN"
                else -> "Diğer"
            }
        } catch (e: Exception) {
            "Hata: ${e.message}"
        }
    }

    /**
     * Logları bir metin dosyasına kaydeder (Cache/Logs klasörüne).
     */
    suspend fun saveLogsToFile(context: Context): File = withContext(Dispatchers.IO) {
        val logContent = collectLogs(context)
        val logsDir = File(context.cacheDir, "logs").apply { mkdirs() }
        val timeStamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
        val file = File(logsDir, "aipbx_logs_$timeStamp.txt")
        FileOutputStream(file).use { out ->
            out.write(logContent.toByteArray(Charsets.UTF_8))
        }
        file
    }

    /**
     * Log dosyasını doğrudan cihazın "İndirilenler" (Downloads) klasörüne kaydeder.
     * Kullanıcıya indirme linki gibi yerel dosya erişimi sağlar.
     */
    suspend fun exportLogsToDownloads(context: Context): Uri? = withContext(Dispatchers.IO) {
        val logContent = collectLogs(context)
        val timeStamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
        val fileName = "aipbx_logs_$timeStamp.txt"

        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val resolver = context.contentResolver
                val contentValues = ContentValues().apply {
                    put(MediaStore.MediaColumns.DISPLAY_NAME, fileName)
                    put(MediaStore.MediaColumns.MIME_TYPE, "text/plain")
                    put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/AiPBX")
                }
                val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, contentValues)
                if (uri != null) {
                    resolver.openOutputStream(uri)?.use { os ->
                        os.write(logContent.toByteArray(Charsets.UTF_8))
                    }
                    return@withContext uri
                }
            } else {
                @Suppress("DEPRECATION")
                val downloadsDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
                val targetDir = File(downloadsDir, "AiPBX").apply { mkdirs() }
                val targetFile = File(targetDir, fileName)
                FileOutputStream(targetFile).use { fos ->
                    fos.write(logContent.toByteArray(Charsets.UTF_8))
                }
                return@withContext Uri.fromFile(targetFile)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error saving to Downloads", e)
        }
        null
    }

    /**
     * Log dosyasını FileProvider üzerinden dışa aktarma / paylaşma (Share Sheet) açar.
     */
    fun shareLogs(context: Context, logFile: File) {
        try {
            val uri = FileProvider.getUriForFile(
                context,
                "${context.packageName}.fileprovider",
                logFile
            )

            val shareIntent = Intent(Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(Intent.EXTRA_SUBJECT, "AI PBX Sistem Logları (${logFile.name})")
                putExtra(Intent.EXTRA_STREAM, uri)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }

            val chooser = Intent.createChooser(shareIntent, "Logları İndir veya Paylaş").apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            context.startActivity(chooser)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to share logs", e)
            Toast.makeText(context, "Log dosyası paylaşılamadı: ${e.message}", Toast.LENGTH_LONG).show()
        }
    }
}
