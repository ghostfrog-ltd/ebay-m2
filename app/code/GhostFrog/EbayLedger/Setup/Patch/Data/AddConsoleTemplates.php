<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Starter parts templates for Nintendo Switch consoles. Core has sold comparables for the console on its own
 * (nintendo-switch-tablet-only) but not for docks, Joy-Cons or accessories, so those have no estimate until you
 * enter one under GhostFrog → Ledger → Parts Templates.
 */
class AddConsoleTemplates implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $parts = static fn(string $consoleKey) => json_encode([
            ['name' => 'Console', 'group' => 'Console and dock', 'size_band' => 'medium', 'comp_model_key' => $consoleKey, 'estimate' => null],
            ['name' => 'Dock', 'group' => 'Console and dock', 'size_band' => 'medium', 'comp_model_key' => '', 'estimate' => null],
            ['name' => 'Joy-Con pair', 'group' => '', 'size_band' => 'small', 'comp_model_key' => '', 'estimate' => null],
            ['name' => 'Joy-Con grip', 'group' => '', 'size_band' => 'small', 'comp_model_key' => '', 'estimate' => null],
            ['name' => 'Power adapter', 'group' => '', 'size_band' => 'small', 'comp_model_key' => '', 'estimate' => null],
            ['name' => 'HDMI cable', 'group' => '', 'size_band' => 'small', 'comp_model_key' => '', 'estimate' => null],
            ['name' => 'Box', 'group' => '', 'size_band' => 'medium', 'comp_model_key' => '', 'estimate' => null],
        ]);
        $db = $this->setup->getConnection();
        $table = $this->setup->getTable('ghostfrog_ebayledger_parts_template');
        $db->insertMultiple($table, [
            ['name' => 'Nintendo Switch (original)', 'model_keys' => 'nintendo-switch-base', 'whole_size_band' => 'large',
                'parts' => $parts('nintendo-switch-tablet-only')],
            ['name' => 'Nintendo Switch OLED', 'model_keys' => 'nintendo-switch-oled', 'whole_size_band' => 'large',
                'parts' => $parts('')],
        ]);
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
