<?php
/**
 * Voice models on this server, through the local AI service (AI → Local
 * models): every running text-to-speech model is one voice here (EMA
 * Lightning for Turkish, Piper voices for German and others …). No key and no
 * cost; the text never leaves the PBX.
 */
require_once __DIR__ . '/TtsProviders.php';
require_once dirname(__DIR__) . '/LocalAiService.php';

final class LocalTts extends TtsProvider
{
    public static function id(): string { return 'local'; }
    public static function title(): string { return 'Local models (this server)'; }
    public static function local(): bool { return true; }
    // Shorter pieces: a CPU needs a fraction of the audio length per piece.
    public static function maxChars(): int { return 1000; }
    public static function fields(): array { return []; }

    public function configured(): bool
    {
        return LocalAiService::readyTtsModels() !== [];
    }

    public function voices(string $language = ''): array
    {
        $out = [];
        foreach (LocalAiService::readyTtsModels() as $m) {
            foreach ((array) ($m['languages'] ?? []) as $lang) {
                $out[] = ['id' => (string) $m['id'], 'name' => (string) ($m['title'] ?? $m['id']),
                          'language' => LocalAiService::languageTag((string) $lang), 'gender' => (string) ($m['gender'] ?? '')];
            }
        }
        return $out;
    }

    public function synthesize(string $text, string $voice, string $language, float $speed): string
    {
        if (!LocalAiService::validId($voice)) {
            throw new TtsException('Local models: unknown voice ' . $voice);
        }
        try {
            return LocalAiService::wavToMp3(LocalAiService::tts($voice, $text, $speed, 24000));
        } catch (LocalAiException $e) {
            throw new TtsException($voice . ': ' . $e->getMessage());
        }
    }
}
