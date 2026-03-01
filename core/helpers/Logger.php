<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;

/**
 * PSR-3 compatible structured logger.
 * Wraps Monolog with JSON structured output for log aggregation.
 */
class Logger
{
    private static ?MonologLogger $instance = null;
    private static array $contexts = [];

    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            $logger = new MonologLogger('nms');
            $logDir = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $formatter = new JsonFormatter();

            // Rotating file handler — keeps 30 days of logs
            $fileHandler = new RotatingFileHandler(
                $logDir . '/nms.log',
                30,
                Level::Debug
            );
            $fileHandler->setFormatter($formatter);
            $logger->pushHandler($fileHandler);

            // Stderr for critical errors
            $stderrHandler = new StreamHandler('php://stderr', Level::Error);
            $stderrHandler->setFormatter($formatter);
            $logger->pushHandler($stderrHandler);

            self::$instance = $logger;
        }
        return self::$instance;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, array_merge(self::$contexts, $context));
    }

    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, array_merge(self::$contexts, $context));
    }

    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, array_merge(self::$contexts, $context));
    }

    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, array_merge(self::$contexts, $context));
    }

    public static function critical(string $message, array $context = []): void
    {
        self::getInstance()->critical($message, array_merge(self::$contexts, $context));
    }

    /**
     * Add persistent context to all subsequent log entries (e.g., request ID, user ID).
     */
    public static function addContext(array $context): void
    {
        self::$contexts = array_merge(self::$contexts, $context);
    }

    public static function clearContext(): void
    {
        self::$contexts = [];
    }

    /**
     * Reset singleton — for testing.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$contexts = [];
    }
}
