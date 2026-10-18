<?php

declare(strict_types=1);

/**
 * OpenAPI 3.1 Specification builder for `/api/v1/openapi.json`.
 */
final class ApiV1OpenApi
{
    public static function serve(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'AiPBX REST API & Plugin Platform',
                'version'     => defined('AIPBX_VERSION') ? AIPBX_VERSION : '1.0.0',
                'description' => 'Full-coverage stateless REST API for managing AiPBX extensions, trunks, routing, queues, calls, CDRs, webhooks, and plugins.',
            ],
            'servers' => [
                ['url' => '/api/v1', 'description' => 'AiPBX REST API v1'],
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'apbx_<token>',
                    ],
                ],
            ],
            'security' => [['BearerAuth' => []]],
            'paths'    => [
                '/system/info'             => ['get' => ['summary' => 'Get PBX version, time, and pending sync status', 'tags' => ['System']]],
                '/system/health'           => ['get' => ['summary' => 'Get system health check results', 'tags' => ['System']]],
                '/system/pending-sync'     => ['get' => ['summary' => 'List pending Asterisk configuration changes', 'tags' => ['System']]],
                '/system/apply-sync'       => ['post' => ['summary' => 'Apply all pending Asterisk changes with automatic rollback', 'tags' => ['System']]],
                '/system/audit-log'        => ['get' => ['summary' => 'List audit log records', 'tags' => ['System']]],
                '/users'                   => ['get' => ['summary' => 'List portal users', 'tags' => ['Users']], 'post' => ['summary' => 'Create user', 'tags' => ['Users']]],
                '/users/{id}'              => ['get' => ['summary' => 'Get user', 'tags' => ['Users']], 'patch' => ['summary' => 'Update user', 'tags' => ['Users']], 'delete' => ['summary' => 'Delete user', 'tags' => ['Users']]],
                '/roles'                   => ['get' => ['summary' => 'List roles and RBAC permissions', 'tags' => ['Users']]],
                '/extensions'              => ['get' => ['summary' => 'List SIP/Fax extensions', 'tags' => ['PBX']], 'post' => ['summary' => 'Create extension', 'tags' => ['PBX']]],
                '/extensions/{id}'         => ['get' => ['summary' => 'Get extension', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update extension', 'tags' => ['PBX']], 'delete' => ['summary' => 'Remove extension', 'tags' => ['PBX']]],
                '/trunks'                  => ['get' => ['summary' => 'List SIP trunks', 'tags' => ['PBX']], 'post' => ['summary' => 'Create SIP trunk', 'tags' => ['PBX']]],
                '/trunks/{id}'             => ['get' => ['summary' => 'Get SIP trunk', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update SIP trunk', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete SIP trunk', 'tags' => ['PBX']]],
                '/did-routes'              => ['get' => ['summary' => 'List inbound DID routes', 'tags' => ['PBX']], 'post' => ['summary' => 'Create inbound DID route', 'tags' => ['PBX']]],
                '/did-routes/{id}'         => ['get' => ['summary' => 'Get inbound DID route', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update inbound DID route', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete inbound DID route', 'tags' => ['PBX']]],
                '/outbound-routes'         => ['get' => ['summary' => 'List outbound routes', 'tags' => ['PBX']], 'post' => ['summary' => 'Create outbound route', 'tags' => ['PBX']]],
                '/outbound-routes/{id}'    => ['get' => ['summary' => 'Get outbound route', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update outbound route', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete outbound route', 'tags' => ['PBX']]],
                '/ivrs'                    => ['get' => ['summary' => 'List IVR menus & digit entries', 'tags' => ['PBX']], 'post' => ['summary' => 'Create IVR menu', 'tags' => ['PBX']]],
                '/ivrs/{id}'               => ['get' => ['summary' => 'Get IVR menu', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update IVR menu', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete IVR menu', 'tags' => ['PBX']]],
                '/time-conditions'         => ['get' => ['summary' => 'List time conditions', 'tags' => ['PBX']], 'post' => ['summary' => 'Create time condition', 'tags' => ['PBX']]],
                '/time-groups'             => ['get' => ['summary' => 'List time groups', 'tags' => ['PBX']], 'post' => ['summary' => 'Create time group', 'tags' => ['PBX']]],
                '/ring-groups'             => ['get' => ['summary' => 'List ring groups', 'tags' => ['PBX']], 'post' => ['summary' => 'Create ring group', 'tags' => ['PBX']]],
                '/conferences'             => ['get' => ['summary' => 'List conference rooms', 'tags' => ['PBX']], 'post' => ['summary' => 'Create conference room', 'tags' => ['PBX']]],
                '/queues'                  => ['get' => ['summary' => 'List call center queues', 'tags' => ['Queues']], 'post' => ['summary' => 'Create queue', 'tags' => ['Queues']]],
                '/queues/live'             => ['get' => ['summary' => 'Live queue and agent telemetry', 'tags' => ['Queues']]],
                '/calls/active'            => ['get' => ['summary' => 'List active Asterisk channels', 'tags' => ['Calls']]],
                '/calls/originate'         => ['post' => ['summary' => 'Click-to-call originate', 'tags' => ['Calls']]],
                '/calls/hangup'            => ['post' => ['summary' => 'Hangup an active channel', 'tags' => ['Calls']]],
                '/cdr'                     => ['get' => ['summary' => 'Query Call Detail Records (CDR)', 'tags' => ['Calls']]],
                '/voicemails'              => ['get' => ['summary' => 'List voicemails for an extension', 'tags' => ['Calls']]],
                '/web-widgets'             => ['get' => ['summary' => 'List website call widgets', 'tags' => ['Plugins']], 'post' => ['summary' => 'Create or update website call widget', 'tags' => ['Plugins']]],
                '/webhooks'                => ['get' => ['summary' => 'List outbound webhooks', 'tags' => ['Webhooks']], 'post' => ['summary' => 'Create outbound webhook', 'tags' => ['Webhooks']]],
                '/plugins'                 => ['get' => ['summary' => 'List official catalog and custom plugins', 'tags' => ['Plugins']]],
                '/plugins/{slug}/enable'   => ['post' => ['summary' => 'Install and enable a plugin', 'tags' => ['Plugins']]],
                '/plugins/{slug}/disable'  => ['post' => ['summary' => 'Disable a plugin', 'tags' => ['Plugins']]],
                '/plugins/{slug}/settings' => ['get' => ['summary' => 'Read plugin settings', 'tags' => ['Plugins']], 'patch' => ['summary' => 'Update plugin settings', 'tags' => ['Plugins']]],
                '/plugins/{slug}/kv/{collection}/{key}' => ['get' => ['summary' => 'Get plugin KV item', 'tags' => ['Plugins']], 'put' => ['summary' => 'Store plugin KV item', 'tags' => ['Plugins']], 'delete' => ['summary' => 'Delete plugin KV item', 'tags' => ['Plugins']]],
            ],
        ];
        echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
