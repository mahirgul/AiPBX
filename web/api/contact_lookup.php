<?php
/**
 * The web phone's caller card: the address book entry of the number that rings.
 * GET ?number= → {contact: {id, display_name, company, numbers} | null, can_add}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../src/services/PhonebookService.php';

header('Content-Type: application/json; charset=utf-8');
session_write_close();

if (!hasModulePermission('phonebook', 'view')) {
    echo json_encode(['success' => true, 'contact' => null, 'can_access' => false, 'can_add' => false]);
    return;
}
$canAccess = hasModulePermission('phonebook', 'access');
$c = PhonebookService::findByNumber((string) ($_GET['number'] ?? ''));
echo json_encode(['success' => true, 'contact' => $c ? [
    'id' => (int) $c['id'],
    'display_name' => (string) $c['display_name'],
    'company' => (string) $c['company'],
    'numbers' => $c['numbers'],
] : null, 'can_access' => $canAccess, 'can_add' => $canAccess && hasModulePermission('phonebook', 'edit')], JSON_UNESCAPED_UNICODE);
