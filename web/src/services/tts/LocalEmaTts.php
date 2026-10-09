<?php
/**
 * EMA Lightning (Turkish) on this server, through the local AI service
 * (AI → Local models). No key and no cost; the text never leaves the PBX.
 * Usable once the model is downloaded and loaded there.
 */
require_once __DIR__ . '/TtsProviders.php';
require_once dirname(__DIR__) . '/LocalAiService.php';

final class LocalEmaTts extends TtsProvider
{
    public const MODEL = 'ema-lightning';
    public const VOICE = 'ema-tr';

    public static function id(): string { return 'local_ema'; }
    public static function title(): string { return 'EMA Lightning (local, Turkish)'; }
    public static function local(): bool { return true; }
    // Shorter pieces: a CPU needs about a quarter of the audio length per piece.
    public static function maxChars(): int { return 1000; }
    public static function fields(): array { return []; }

    public function configured(): bool
    {
        return LocalAiService::ready(self::MODEL);
    }

    public function voices(string $language = ''): array
    {
        // One speaker; the model speaks Turkish only.
        return [['id' => self::VOICE, 'name' => 'EMA Lightning', 'language' => 'tr-TR', 'gender' => '']];
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        if ($voice !== self::VOICE) {
            throw new TtsException('EMA Lightning: unknown voice ' . $voice);
        }
        try {
            return LocalAiService::wavToMp3(LocalAiService::tts(self::MODEL, $text, $speed, 24000));
        } catch (LocalAiException $e) {
            throw new TtsException('EMA Lightning: ' . $e->getMessage());
        }
    }
}
