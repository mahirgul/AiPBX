<?php

use PHPUnit\Framework\TestCase;

/**
 * jsArg(): a value inside an inline handler stays one JavaScript string, the
 * way the browser reads it (HTML entities decoded first, then the script).
 */
final class JsArgTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function values(): array
    {
        return [
            'quote' => ["');alert(1);//"],
            'double quote' => ['"><img src=x onerror=alert(1)>'],
            'apostrophe in a translation' => ["Supprimer l'appel ?"],
            'entity' => ['&#039;);alert(1);//'],
            'script end' => ['</script><script>alert(1)</script>'],
            'line break and backslash' => ["a\\\nb"],
            'unicode' => ['Çağrı silinsin mi?'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testValueStaysOneString(string $value): void
    {
        $attr = jsArg($value);
        $this->assertDoesNotMatchRegularExpression('/[\'"<>]/', $attr, 'no raw quote or tag in the attribute');
        $js = html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8');   // what the browser hands to the script
        $this->assertSame($value, json_decode($js), 'the script gets exactly the value');
        $this->assertMatchesRegularExpression('/^"[^"]*"$/', $js, 'one string literal, nothing after it');
    }
}
