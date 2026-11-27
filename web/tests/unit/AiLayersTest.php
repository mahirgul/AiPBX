<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ai/CloudAiService.php';

/**
 * The layers of the cloud AI account code (see AGENTS.md, "Architectural Coupling"). A class may use the ones below it:
 *
 *   AiProviderManifest, CloudAiException, AiLanguages
 *     < AiEndpointGuard, AiUsageMeter, AiProviderCatalog
 *       < AiAccountStore
 *         < AiProviderClient
 *           < CloudAiService (the facade the rest of the portal calls)
 *   AiAccountTester sits above AiGateway, which asks CloudAiService for its connection.
 *
 * Nothing below may name a class above it, and AiProviderManifest.php must read the same file as the AI service.
 */
final class AiLayersTest extends TestCase
{
    private const LAYERS = [
        'AiProviderManifest' => 0, 'CloudAiException' => 0, 'AiLanguages' => 0,
        'AiEndpointGuard' => 1, 'AiUsageMeter' => 1, 'AiProviderCatalog' => 1,
        'AiAccountStore' => 2,
        'AiProviderClient' => 3,
        'CloudAiService' => 4,
    ];
    /** classes above the account code that must not be reached from it */
    private const ABOVE = ['AiGateway', 'AiJobService', 'AiAppService', 'AiBindingService', 'AssistantModel', 'BuiltinAiAgentService',
        'AiAccountTester', 'VoicemailAiService', 'CdrAiService'];

    /** @return list<string> class names a file refers to (comments and strings are not looked at) */
    private static function referencedClasses(string $file): array
    {
        $out = [];
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
        ));
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && (($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON || (($tokens[$i - 1][0] ?? null) === T_NEW))) {
                $out[$t[1]] = true;
            }
        }
        return array_keys($out);
    }

    public function testNoClassUsesOneAboveIt(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/services/ai/';
        foreach (self::LAYERS as $class => $layer) {
            $used = self::referencedClasses($dir . $class . '.php');
            $this->assertSame([], array_values(array_intersect($used, self::ABOVE)), "{$class} uses a class above the account code");
            foreach ($used as $other) {
                if (isset(self::LAYERS[$other]) && $other !== $class) {
                    $this->assertLessThan($layer, self::LAYERS[$other], "{$class} (layer {$layer}) uses {$other} (layer " . self::LAYERS[$other] . ')');
                }
            }
        }
    }

    public function testThePortalAndTheAiServiceReadTheSameProviderList(): void
    {
        $this->assertSame(realpath(dirname(__DIR__, 3) . '/ai/aipbx_ai/providers.json'), realpath(AiProviderManifest::path()));
        $this->assertSame('meta/llama-3.1-8b-instruct', AiProviderManifest::llmModel('nvidia_local'));
        $this->assertTrue(AiProviderManifest::privateOk('ollama'));
        $this->assertFalse(AiProviderManifest::privateOk('qwen'));
        $this->assertTrue(AiProviderManifest::ownAddress('qwen'));
        $this->assertSame('', AiProviderManifest::llmBase('google_ai'));
    }

    public function testTheFacadeKeepsTheAccountApi(): void
    {
        foreach (['accounts', 'config', 'configured', 'chat', 'stt', 'discoverModels', 'gatewayConfig', 'overLimit', 'count', 'language'] as $name) {
            $this->assertTrue(method_exists(CloudAiService::class, $name), $name);
        }
        $this->assertFalse(method_exists(CloudAiService::class, 'test'), 'the connection test is AiAccountTester, above the gateway');
    }

    public function testGatewayAndAssistantLayersStayLowerTriangular(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/services/ai/';
        $forbidden = [
            'AiGateway.php' => ['AiKnowledgeService', 'AiAppService', 'BuiltinAiAgentService', 'AiAppResolver', 'AssistantModel', 'AssistantChatService'],
            'AssistantSettingsService.php' => ['AssistantModel', 'AssistantChatService', 'AiGateway'],
            'AssistantModel.php' => ['AssistantChatService'],
            'AiKnowledgeService.php' => ['AiAppService', 'BuiltinAiAgentService', 'AiAppResolver'],
        ];
        foreach ($forbidden as $file => $above) {
            $used = self::referencedClasses($dir . $file);
            $this->assertSame([], array_values(array_intersect($used, $above)), "{$file} reaches above itself");
        }
    }
}
