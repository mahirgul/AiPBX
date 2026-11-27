<?php

declare(strict_types=1);

/**
 * The languages AI features work in: code => locale and name. The name is what language models are told to speak or
 * write ("Turkish"), the locale goes to speech recognition and speech synthesis ("tr-TR"). It is a fixed table, not a
 * setting: an agent chooses its language with {language, xx} in its instructions, the other features find it themselves.
 */
final class AiLanguages
{
    public const TABLE = [
        'af' => ['locale' => 'af-ZA', 'name' => 'Afrikaans'],
        'sq' => ['locale' => 'sq-AL', 'name' => 'Albanian'],
        'am' => ['locale' => 'am-ET', 'name' => 'Amharic'],
        'ar' => ['locale' => 'ar-SA', 'name' => 'Arabic'],
        'hy' => ['locale' => 'hy-AM', 'name' => 'Armenian'],
        'az' => ['locale' => 'az-AZ', 'name' => 'Azerbaijani'],
        'eu' => ['locale' => 'eu-ES', 'name' => 'Basque'],
        'be' => ['locale' => 'be-BY', 'name' => 'Belarusian'],
        'bn' => ['locale' => 'bn-BD', 'name' => 'Bengali'],
        'bs' => ['locale' => 'bs-BA', 'name' => 'Bosnian'],
        'bg' => ['locale' => 'bg-BG', 'name' => 'Bulgarian'],
        'my' => ['locale' => 'my-MM', 'name' => 'Burmese'],
        'ca' => ['locale' => 'ca-ES', 'name' => 'Catalan'],
        'zh' => ['locale' => 'zh-CN', 'name' => 'Chinese'],
        'hr' => ['locale' => 'hr-HR', 'name' => 'Croatian'],
        'cs' => ['locale' => 'cs-CZ', 'name' => 'Czech'],
        'da' => ['locale' => 'da-DK', 'name' => 'Danish'],
        'nl' => ['locale' => 'nl-NL', 'name' => 'Dutch'],
        'en' => ['locale' => 'en-US', 'name' => 'English'],
        'et' => ['locale' => 'et-EE', 'name' => 'Estonian'],
        'fil' => ['locale' => 'fil-PH', 'name' => 'Filipino'],
        'fi' => ['locale' => 'fi-FI', 'name' => 'Finnish'],
        'fr' => ['locale' => 'fr-FR', 'name' => 'French'],
        'gl' => ['locale' => 'gl-ES', 'name' => 'Galician'],
        'ka' => ['locale' => 'ka-GE', 'name' => 'Georgian'],
        'de' => ['locale' => 'de-DE', 'name' => 'German'],
        'el' => ['locale' => 'el-GR', 'name' => 'Greek'],
        'gu' => ['locale' => 'gu-IN', 'name' => 'Gujarati'],
        'ha' => ['locale' => 'ha-NG', 'name' => 'Hausa'],
        'he' => ['locale' => 'he-IL', 'name' => 'Hebrew'],
        'hi' => ['locale' => 'hi-IN', 'name' => 'Hindi'],
        'hu' => ['locale' => 'hu-HU', 'name' => 'Hungarian'],
        'is' => ['locale' => 'is-IS', 'name' => 'Icelandic'],
        'id' => ['locale' => 'id-ID', 'name' => 'Indonesian'],
        'ga' => ['locale' => 'ga-IE', 'name' => 'Irish'],
        'it' => ['locale' => 'it-IT', 'name' => 'Italian'],
        'ja' => ['locale' => 'ja-JP', 'name' => 'Japanese'],
        'jv' => ['locale' => 'jv-ID', 'name' => 'Javanese'],
        'kn' => ['locale' => 'kn-IN', 'name' => 'Kannada'],
        'kk' => ['locale' => 'kk-KZ', 'name' => 'Kazakh'],
        'km' => ['locale' => 'km-KH', 'name' => 'Khmer'],
        'ko' => ['locale' => 'ko-KR', 'name' => 'Korean'],
        'ky' => ['locale' => 'ky-KG', 'name' => 'Kyrgyz'],
        'lo' => ['locale' => 'lo-LA', 'name' => 'Lao'],
        'lv' => ['locale' => 'lv-LV', 'name' => 'Latvian'],
        'lt' => ['locale' => 'lt-LT', 'name' => 'Lithuanian'],
        'lb' => ['locale' => 'lb-LU', 'name' => 'Luxembourgish'],
        'mk' => ['locale' => 'mk-MK', 'name' => 'Macedonian'],
        'ms' => ['locale' => 'ms-MY', 'name' => 'Malay'],
        'ml' => ['locale' => 'ml-IN', 'name' => 'Malayalam'],
        'mt' => ['locale' => 'mt-MT', 'name' => 'Maltese'],
        'mr' => ['locale' => 'mr-IN', 'name' => 'Marathi'],
        'mn' => ['locale' => 'mn-MN', 'name' => 'Mongolian'],
        'ne' => ['locale' => 'ne-NP', 'name' => 'Nepali'],
        'nb' => ['locale' => 'nb-NO', 'name' => 'Norwegian'],
        'ps' => ['locale' => 'ps-AF', 'name' => 'Pashto'],
        'fa' => ['locale' => 'fa-IR', 'name' => 'Persian'],
        'pl' => ['locale' => 'pl-PL', 'name' => 'Polish'],
        'pt' => ['locale' => 'pt-BR', 'name' => 'Portuguese'],
        'pa' => ['locale' => 'pa-IN', 'name' => 'Punjabi'],
        'ro' => ['locale' => 'ro-RO', 'name' => 'Romanian'],
        'ru' => ['locale' => 'ru-RU', 'name' => 'Russian'],
        'sr' => ['locale' => 'sr-RS', 'name' => 'Serbian'],
        'si' => ['locale' => 'si-LK', 'name' => 'Sinhala'],
        'sk' => ['locale' => 'sk-SK', 'name' => 'Slovak'],
        'sl' => ['locale' => 'sl-SI', 'name' => 'Slovenian'],
        'so' => ['locale' => 'so-SO', 'name' => 'Somali'],
        'es' => ['locale' => 'es-ES', 'name' => 'Spanish'],
        'su' => ['locale' => 'su-ID', 'name' => 'Sundanese'],
        'sw' => ['locale' => 'sw-KE', 'name' => 'Swahili'],
        'sv' => ['locale' => 'sv-SE', 'name' => 'Swedish'],
        'tg' => ['locale' => 'tg-TJ', 'name' => 'Tajik'],
        'ta' => ['locale' => 'ta-IN', 'name' => 'Tamil'],
        'te' => ['locale' => 'te-IN', 'name' => 'Telugu'],
        'th' => ['locale' => 'th-TH', 'name' => 'Thai'],
        'tr' => ['locale' => 'tr-TR', 'name' => 'Turkish'],
        'tk' => ['locale' => 'tk-TM', 'name' => 'Turkmen'],
        'uk' => ['locale' => 'uk-UA', 'name' => 'Ukrainian'],
        'ur' => ['locale' => 'ur-PK', 'name' => 'Urdu'],
        'uz' => ['locale' => 'uz-UZ', 'name' => 'Uzbek'],
        'vi' => ['locale' => 'vi-VN', 'name' => 'Vietnamese'],
        'cy' => ['locale' => 'cy-GB', 'name' => 'Welsh'],
        'yo' => ['locale' => 'yo-NG', 'name' => 'Yoruba'],
        'zu' => ['locale' => 'zu-ZA', 'name' => 'Zulu'],
    ];

    /** Codes some places write differently. */
    public const ALIASES = ['no' => 'nb', 'nn' => 'nb', 'tl' => 'fil', 'iw' => 'he', 'in' => 'id'];

    /**
     * The languages AI jobs run in (AiLanguages::TABLE): code => locale and name. The name is what language models are
     * told to speak or write, the locale goes to speech recognition and synthesis.
     * @return array<string, array{locale: string, name: string}>
     */
    public static function languages(): array
    {
        return AiLanguages::TABLE;
    }

    /** The language of a code ("tr", "tr_TR", "pt-BR", an alias) when the table has it; else the PBX's own language, else English. */
    public static function resolve(string $lang): string
    {
        $lang = strtolower(str_replace('_', '-', trim($lang)));
        foreach ([substr($lang, 0, 3), substr($lang, 0, 2)] as $code) {
            $code = AiLanguages::ALIASES[$code] ?? $code;
            if (isset(AiLanguages::TABLE[$code])) {
                return $code;
            }
        }
        $own = class_exists('MailTemplateService') ? (string) MailTemplateService::systemLanguage() : '';
        return isset(AiLanguages::TABLE[$own]) ? $own : 'en';
    }

    public static function nameOf(string $lang): string
    {
        return AiLanguages::TABLE[self::resolve($lang)]['name'];
    }

    public static function localeOf(string $lang): string
    {
        return AiLanguages::TABLE[self::resolve($lang)]['locale'];
    }
}
