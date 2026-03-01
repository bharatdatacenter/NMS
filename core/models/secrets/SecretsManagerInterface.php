<?php

declare(strict_types=1);

namespace NMS\Core\Models\Secrets;

interface SecretsManagerInterface
{
    /**
     * Retrieve a secret value at the given path.
     *
     * @param  string $path  Vault-style path (e.g. "nms/devices/fw01/password")
     * @return string        The secret value
     * @throws \RuntimeException on retrieval failure
     */
    public function get(string $path): string;

    /**
     * Store a secret value at the given path.
     *
     * @param  string $path  Vault-style path
     * @param  string $value The secret to store
     * @throws \RuntimeException on storage failure
     */
    public function put(string $path, string $value): void;

    /**
     * Delete a secret at the given path.
     *
     * @param  string $path  Vault-style path
     * @throws \RuntimeException on failure
     */
    public function delete(string $path): void;

    /**
     * Check if a secret exists at the given path.
     */
    public function exists(string $path): bool;
}
