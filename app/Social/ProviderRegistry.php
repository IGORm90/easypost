<?php

namespace App\Social;

use App\Social\Contracts\SocialProviderInterface;
use App\Social\Exceptions\UnknownProviderException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a SocialProviderInterface by its stable identifier (a short
 * lowercase slug naming the network). The list of identifier => class
 * bindings lives entirely in config('social.providers') — this class
 * contains no provider-specific knowledge, matching the project's Global
 * Constraint that no provider-specific logic lives outside
 * app/Social/Providers.
 *
 * Providers are resolved through the container (not `new`'d directly)
 * so each provider class can declare its own constructor dependencies
 * (HTTP client, config, ...) the normal Laravel way. Resolved instances
 * are cached per identifier for the life of the registry.
 */
final class ProviderRegistry
{
    /** @var array<string, SocialProviderInterface> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
    ) {}

    public function get(string $identifier): SocialProviderInterface
    {
        if (! $this->has($identifier)) {
            throw UnknownProviderException::forIdentifier($identifier);
        }

        if (isset($this->resolved[$identifier])) {
            return $this->resolved[$identifier];
        }

        /** @var class-string<SocialProviderInterface> $class */
        $class = $this->providerClasses()[$identifier];

        return $this->resolved[$identifier] = $this->container->make($class);
    }

    /**
     * @return array<string, SocialProviderInterface>
     */
    public function all(): array
    {
        $result = [];
        foreach (array_keys($this->providerClasses()) as $identifier) {
            $result[$identifier] = $this->get($identifier);
        }

        return $result;
    }

    public function has(string $identifier): bool
    {
        return array_key_exists($identifier, $this->providerClasses());
    }

    /**
     * @return array<string, class-string<SocialProviderInterface>>
     */
    private function providerClasses(): array
    {
        /** @var array<string, class-string<SocialProviderInterface>> */
        return (array) $this->config->get('social.providers', []);
    }
}
