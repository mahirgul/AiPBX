<?php
/**
 * The web phone's caller card: the address book entry of the number that rings.
 * GET ?number= → {contact: {id, display_name, company, numbers} | null, can_add}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/PhonebookService.php';
require_once __DIR__ . '/../src/services/ConnectionService.php';

header('Content-Type: application/json; charset=utf-8');
session_write_close();

if (!hasModulePermission('phonebook', 'view')) {
    echo json_encode(['success' => true, 'contact' => null, 'can_access' => false, 'can_add' => false]);
    return;
}
$canAccess = hasModulePermission('phonebook', 'access');
$num = (string) ($_GET['number'] ?? '');
$c = PhonebookService::findByNumber($num);
$out = $c ? [
    'id' => (int) $c['id'],
    'customer_id' => (string) ($c['external_id'] ?? ''),
    'external_id' => (string) ($c['external_id'] ?? ''),
    'display_name' => (string) $c['display_name'],
    'company' => (string) $c['company'],
    'numbers' => $c['numbers'],
] : null;
if ($out === null) {
    $ext = ConnectionService::lookupCaller($num);
    if ($ext !== null) {
        $out = [
            'id' => 0,
            'customer_id' => $ext['customer_id'],
            'external_id' => $ext['external_id'],
            'display_name' => $ext['display_name'],
            'company' => $ext['company'],
            'numbers' => [],
            'external_url' => $ext['external_url'],
            'source' => $ext['source'],
        ];
    }
}
echo json_encode(['success' => true, 'contact' => $out, 'can_access' => $canAccess, 'can_add' => $canAccess && hasModulePermission('phonebook', 'edit')], JSON_UNESCAPED_UNICODE);
