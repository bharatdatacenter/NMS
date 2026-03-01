<?php

declare(strict_types=1);

namespace NMS\Core\Database;

/**
 * Runs database/setup.php index creation.
 */
class Migration
{
    private MongoDB $db;

    public function __construct(?MongoDB $db = null)
    {
        $this->db = $db ?? MongoDB::getInstance();
    }

    public function run(): void
    {
        $setupFile = dirname(__DIR__, 2) . '/database/setup.php';
        if (!file_exists($setupFile)) {
            throw new \RuntimeException("database/setup.php not found");
        }
        $db = $this->db->getDatabase();
        require $setupFile;
    }
}
