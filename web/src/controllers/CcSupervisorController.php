<?php

/**
 * /cc-supervisor: the same screen as /cc-board; the global view permission is
 * read from the 'queue_monitor' module key (see CcBoardController::renderBoard()).
 */
class CcSupervisorController extends CcBoardController
{
    public static function index(): void
    {
        static::requireRole(['admin', 'cc_manager', 'cc_agent', 'read_only_admin']);
        static::renderBoard('queue_monitor');
    }
}
