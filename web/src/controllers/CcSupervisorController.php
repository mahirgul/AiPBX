<?php

/**
 * /cc-supervisor: /cc-board ile aynı ekran, global görme izni 'queue_monitor'
 * modül anahtarından okunur (bkz. CcBoardController::renderBoard()).
 */
class CcSupervisorController extends CcBoardController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent', 'read_only_admin']);
        static::renderBoard('queue_monitor');
    }
}
