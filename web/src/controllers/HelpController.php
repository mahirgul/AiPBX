<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/DocsService.php';

/**
 * Admin → Help: the documentation of docs/ read from this server's disk, so it
 * is there without internet access (DocsService). Admins only: the system map
 * names files, services and commands of the server.
 */
class HelpController extends BaseController
{
    public static function index(): void
    {
        static::requireRole('admin');

        $docs = DocsService::list();
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $name = (string) ($_GET['doc'] ?? '');
        if ($name === '' && $query === '') {
            $name = 'system-map';
        }
        $source = $name !== '' ? DocsService::source($name) : null;

        static::renderPage('help/index', [
            'docs' => $docs,
            'current' => $source !== null ? $name : '',
            'html' => $source !== null ? DocsService::render($source) : '',
            'query' => $query,
            'results' => $query !== '' ? DocsService::search($query) : [],
            'notFound' => $name !== '' && $source === null,
        ], ['title' => t('help.title')]);
    }
}
