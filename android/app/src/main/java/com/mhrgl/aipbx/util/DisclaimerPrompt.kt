package com.mhrgl.aipbx.util

import android.app.Activity
import android.content.Context
import androidx.appcompat.app.AlertDialog

/**
 * First-launch Open Source License, Emergency Calling (112/911/999) & Data Privacy Disclaimer
 * for the AiPBX Android mobile application.
 */
object DisclaimerPrompt {
    private const val PREFS_NAME = "aipbx_legal_prefs"
    private const val KEY_ACCEPTED = "disclaimer_accepted_v1"

    fun showIfNeeded(activity: Activity) {
        if (activity.isFinishing || activity.isDestroyed) return
        val sp = activity.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        if (sp.getBoolean(KEY_ACCEPTED, false)) return

        val isTr = L10n.current().startsWith("tr", ignoreCase = true)

        val title = if (isTr) {
            "Açık Kaynak Lisansı ve Sorumluluk Beyanı"
        } else {
            "Open Source License & Telecom Disclaimer"
        }

        val message = if (isTr) {
            "AiPBX, MIT Lisansı altında hiçbir garanti olmaksızın \"OLDUĞU GİBİ\" (AS IS) sunulan açık kaynaklı bir kurumsal iletişim uygulamasıdır:\n\n" +
                "• Acil Çağrılar (112 / 911 / 999): Bu uygulama internet/VoIP üzerinden çalışır; elektrik, internet veya santral kesintilerinde acil çağrı yapamayabilir. Acil durumlar için her zaman telefonunuzun normal GSM şebekesini kullanın.\n\n" +
                "• Veri Gizliliği (KVKK / GDPR): Sesli görüşmeler, mesajlar ve ses kayıtları bağlandığınız santral sunucusu üzerinden kurumunuzun gizlilik politikasına göre işlenir.\n\n" +
                "• Sorumluluk Reddi: Yazılım geliştiricileri ve katkıda bulunanlar, uygulamanın kullanımından doğabilecek doğrudan veya dolaylı zararlardan sorumlu tutulamaz."
        } else {
            "AiPBX is open-source software provided \"AS IS\" under the MIT License without warranty of any kind:\n\n" +
                "• Emergency Calls (112 / 911 / 999): This VoIP softphone depends on network and PBX availability and is not a replacement for cellular emergency services. Always use your device's native cellular dialer for emergency calls.\n\n" +
                "• Data Privacy (GDPR / KVKK): Calls, recordings, and messages are processed by the PBX server you connect to in accordance with your organization's privacy policies.\n\n" +
                "• Limitation of Liability: The authors and contributors shall not be liable for any direct, indirect, or consequential damages arising from the use of this application."
        }

        val acceptText = if (isTr) "Kabul Et ve Devam Et" else "Accept & Continue"

        AlertDialog.Builder(activity)
            .setTitle(title)
            .setMessage(message)
            .setCancelable(false)
            .setPositiveButton(acceptText) { dialog, _ ->
                sp.edit().putBoolean(KEY_ACCEPTED, true).apply()
                dialog.dismiss()
            }
            .show()
    }
}
