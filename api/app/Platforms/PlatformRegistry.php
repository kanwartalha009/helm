<?php

declare(strict_types=1);

namespace App\Platforms;

use App\Platforms\Contracts\PlatformAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Singleton resolver for platform adapters. Adapters self-register via
 * config/platforms.php and are constructed by the service container.
 * See spec §6.3.
 */
class PlatformRegistry
{
    /** @param array<string, class-string<PlatformAdapter>> $map */
    public function __construct(
        private readonly Container $container,
        private readonly array $map,
    ) {}

    public function for(string $key): PlatformAdapter
    {
        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException("Unknown platform: {$key}");
        }

        return $this->container->make($this->map[$key]);
    }

    /** @return array<string, PlatformAdapter> */
    public function all(): array
    {
        $resolved = [];
        foreach ($this->map as $key => $class) {
            $resolved[$key] = $this->container->make($class);
        }
        return $resolved;
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->map);
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }
}
