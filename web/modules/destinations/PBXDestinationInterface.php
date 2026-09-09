<?php
namespace PBX\Destinations;

/**
 * Standard Interface for Plug-and-Play PBX Destination Modules
 */
interface PBXDestinationInterface {
    /**
     * Unique module key (e.g. 'time_condition', 'ivr', 'queue', 'extension', 'fax', 'announcement', 'hangup')
     */
    public function getKey(): string;

    /**
     * Human-readable module name for Admin UI dropdowns
     */
    public function getName(): string;

    /**
     * Get array of selectable items for this module: [['id' => '1', 'name' => 'Mesai Kontrolü']]
     */
    public function getOptions(): array;

    /**
     * Resolve target during Asterisk AGI execution
     */
    public function resolve($agi, string $destId): array;
}
