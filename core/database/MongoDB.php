<?php

declare(strict_types=1);

namespace NMS\Core\Database;

use MongoDB\Client;
use MongoDB\Database;
use RuntimeException;

/**
 * MongoDB singleton connection wrapper.
 */
class MongoDB
{
    private static ?MongoDB $instance = null;
    private Client $client;
    private Database $database;

    private function __construct(array $config)
    {
        $this->client = new Client($config['uri'], [], $config['options'] ?? []);
        $this->database = $this->client->selectDatabase($config['database']);
    }

    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                $config = require __DIR__ . '/../config/database.php';
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Reset singleton — used in tests only.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function selectCollection(string $name): \MongoDB\Collection
    {
        return $this->database->selectCollection($name);
    }

    /**
     * Ping the server to verify connectivity.
     */
    public function ping(): bool
    {
        try {
            $result = $this->database->command(['ping' => 1]);
            return (bool)($result->toArray()[0]['ok'] ?? false);
        } catch (\Exception) {
            return false;
        }
    }
}
