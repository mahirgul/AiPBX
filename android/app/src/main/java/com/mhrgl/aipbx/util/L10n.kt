package com.mhrgl.aipbx.util

import android.app.LocaleManager
import android.content.Context
import android.content.res.Configuration
import android.os.Build
import android.os.LocaleList
import androidx.annotation.StringRes
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import com.mhrgl.aipbx.R
import com.mhrgl.aipbx.data.AppPreferences
import java.util.Locale

/**
 * App language manager supporting all 23 bundled languages across Android 8–16+.
 *
 * Ensures synchronous synchronization between [AppPreferences], Android 13+
 * [LocaleManager], [AppCompatDelegate], and Activity base contexts ([wrap]).
 */
object L10n {
    const val EN = "en"
    const val TR = "tr"

    /** Language names in their own language, so they are found whatever is active. */
    private val NAMES = linkedMapOf(
        EN to "English",
        TR to "Türkçe",
        "az" to "Azərbaycan dili",
        "bg" to "Български",
        "cs" to "Čeština",
        "da" to "Dansk",
        "de" to "Deutsch",
        "el" to "Ελληνικά",
        "es" to "Español",
        "fi" to "Suomi",
        "fr" to "Français",
        "hr" to "Hrvatski",
        "hu" to "Magyar",
        "it" to "Italiano",
        "nb" to "Norsk bokmål",
        "nl" to "Nederlands",
        "pl" to "Polski",
        "pt" to "Português",
        "ro" to "Română",
        "ru" to "Русский",
        "sr" to "Srpski",
        "sv" to "Svenska",
        "uk" to "Українська",
    )
    private val SUPPORTED = NAMES.keys.toList()

    private lateinit var app: Context
    @Volatile private var cachedLang: String? = null
    @Volatile private var cachedContext: Context? = null

    fun init(context: Context) {
        app = context.applicationContext
        val prefs = AppPreferences.getInstance(app)
        // If the user changed per-app language in Android 13+ system settings while
        // the app was closed, adopt that choice on startup.
        if (Build.VERSION.SDK_INT >= 33) {
            val sys = app.getSystemService(LocaleManager::class.java)?.applicationLocales
            if (sys != null && !sys.isEmpty) {
                val sysLang = normalize(sys[0]?.language)
                if (sysLang != prefs.appLanguage) {
                    prefs.appLanguage = sysLang
                }
            }
        }
        apply(normalize(prefs.appLanguage))
    }

    /** Returns the active language code (always one of [SUPPORTED]). */
    fun current(): String {
        if (!::app.isInitialized) return EN
        val prefs = AppPreferences.getInstance(app)
        return normalize(prefs.appLanguage)
    }

    fun displayName(lang: String = current()): String = NAMES[lang] ?: lang

    /** Switches the language and recreates the calling Activity if needed. */
    fun set(lang: String, activity: AppCompatActivity? = null) {
        val l = normalize(lang)
        if (::app.isInitialized) {
            AppPreferences.getInstance(app).appLanguage = l
        } else if (activity != null) {
            app = activity.applicationContext
            AppPreferences.getInstance(app).appLanguage = l
        }
        cachedLang = null
        cachedContext = null
        apply(l)
        if (activity != null && !activity.isFinishing && !activity.isDestroyed) {
            activity.recreate()
        }
    }

    /** Wraps an Activity base context with the selected locale on all Android versions. */
    fun wrap(base: Context): Context {
        val appCtx = base.applicationContext ?: base
        if (!::app.isInitialized) {
            app = appCtx
        }
        val lang = try {
            normalize(AppPreferences.getInstance(appCtx).appLanguage)
        } catch (_: Exception) {
            EN
        }
        val locale = Locale.forLanguageTag(lang)
        Locale.setDefault(locale)
        val cfg = Configuration(base.resources.configuration)
        cfg.setLocale(locale)
        cfg.setLayoutDirection(locale)
        return base.createConfigurationContext(cfg)
    }

    /** Localized string for code that has no Activity context (services, notifications, API errors). */
    fun str(@StringRes id: Int, vararg args: Any?): String = context().getString(id, *args)

    fun context(): Context {
        val lang = current()
        cachedContext?.takeIf { cachedLang == lang }?.let { return it }
        val locale = Locale.forLanguageTag(lang)
        val cfg = Configuration(app.resources.configuration)
        cfg.setLocale(locale)
        cfg.setLayoutDirection(locale)
        return app.createConfigurationContext(cfg).also {
            cachedContext = it
            cachedLang = lang
        }
    }

    /** Language chooser used on the login, server and settings screens. */
    fun showPicker(activity: AppCompatActivity) {
        val cur = current()
        val idx = SUPPORTED.indexOf(cur).coerceAtLeast(0)
        AlertDialog.Builder(activity)
            .setTitle(activity.getString(R.string.language_title))
            .setSingleChoiceItems(SUPPORTED.map { displayName(it) }.toTypedArray(), idx) { dialog, which ->
                dialog.dismiss()
                val picked = SUPPORTED[which]
                set(picked, activity)
            }
            .setNegativeButton(activity.getString(R.string.btn_cancel), null)
            .show()
    }

    private fun apply(lang: String) {
        val locale = Locale.forLanguageTag(lang)
        Locale.setDefault(locale)
        if (Build.VERSION.SDK_INT >= 33 && ::app.isInitialized) {
            try {
                val lm = app.getSystemService(LocaleManager::class.java)
                val target = LocaleList.forLanguageTags(lang)
                if (lm != null && lm.applicationLocales != target) {
                    lm.applicationLocales = target
                }
            } catch (_: Exception) {
            }
        }
        val compatTarget = LocaleListCompat.forLanguageTags(lang)
        if (AppCompatDelegate.getApplicationLocales() != compatTarget) {
            AppCompatDelegate.setApplicationLocales(compatTarget)
        }
    }

    /** "tr-TR" -> "tr"; Norwegian "no"/"nn" -> "nb"; anything unsupported -> English. */
    private fun normalize(lang: String?): String {
        val base = lang?.lowercase()?.substringBefore('-')?.substringBefore('_') ?: return EN
        val code = if (base == "no" || base == "nn") "nb" else base
        return if (code in SUPPORTED) code else EN
    }
}
