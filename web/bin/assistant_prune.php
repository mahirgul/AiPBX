#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/services/ai/AssistantChatService.php';
AssistantChatService::pruneOldChats();
