package com.mhrgl.aipbx.util

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.util.Log

object SamsungPowerManagerHelper {

    private const val TAG = "SamsungPowerHelper"

    val isSamsungDevice: Boolean
        get() = Build.MANUFACTURER.equals("samsung", ignoreCase = true)

    /**
     * Safely attempts to open Samsung Device Care battery / background limit settings.
     * Tries Samsung OEM components first, then standard Android battery optimization settings.
     */
    fun openBatterySettings(context: Context): Boolean {
        val intents = listOf(
            // 1. Samsung Device Care Deep Sleeping / Background limits
            Intent().setComponent(ComponentName("com.samsung.android.lool", "com.samsung.android.sm.ui.battery.BatteryActivity")),
            Intent().setComponent(ComponentName("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity")),
            Intent().setComponent(ComponentName("com.samsung.android.sm", "com.samsung.android.sm.ui.battery.BatteryActivity")),
            Intent("com.samsung.android.sm.ACTION_BATTERY"),
            // 2. Android standard battery optimization settings
            Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS),
            // 3. Application details settings
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.parse("package:${context.packageName}")
            }
        )

        for (intent in intents) {
            try {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                context.startActivity(intent)
                return true
            } catch (e: Exception) {
                Log.d(TAG, "Battery intent failed: ${intent.component?.className ?: intent.action}")
            }
        }
        return false
    }

    const val SAMSUNG_GUIDE_TITLE = "Samsung Arka Plan Kısıtlama Uyarısı"

    const val SAMSUNG_GUIDE_MESSAGE = "Samsung cihazlarda ekran kapalıyken arka planda çağrı kaçırmamak için:\n\n" +
            "1. 'Ayarlar' -> 'Pil ve cihaz bakımı' -> 'Pil' bölümüne gidin.\n" +
            "2. 'Arka planda kullanım sınırları' seçeneğini açın.\n" +
            "3. 'Uyuyan uygulamalar' veya 'Derin uyku' listesinde AiPBX varsa kaldırın.\n" +
            "4. 'Asla otomatik uykuya alınmayacak uygulamalar' listesine AiPBX'i ekleyin."
}
