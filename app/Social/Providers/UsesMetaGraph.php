<?php

namespace App\Social\Providers;

use App\Social\Exceptions\ProviderPermanentException;
use Closure;
use Illuminate\Http\Client\Response;

/**
 * The one piece of the three Meta-backed providers (Facebook Page,
 * Instagram, Threads — Block 5) that is genuinely shared: how a Graph
 * API URL is built, and the one place a Graph call is sent
 * (`call()`, via ProviderCall with the MetaGraphErrors adapter). The
 * token-exchange step stays out of shared code (UsesOAuth2Pkce's
 * docblock explains why: different hosts, different grant param names,
 * different client auth).
 *
 * Threads lives on a different host (`graph.threads.net`, not
 * `graph.facebook.com`) but answers with the same envelope shape, which
 * is why `graphHost()` is overridable rather than a fixed constant.
 */
trait UsesMetaGraph
{
    /**
     * Threads versions its API independently of the main Graph API
     * (`v1.0`, not `v21.0`), so this is overridable the same way
     * `graphHost()` is. Not a class constant (PHPStan flags a trait
     * constant as unused in any composing class that overrides the one
     * method reading it, e.g. ThreadsProvider) — a literal default
     * here carries the same "one place to bump" property.
     */
    protected function graphVersion(): string
    {
        return 'v21.0';
    }

    /**
     * The display host a subclass' Graph calls target. Facebook Page and
     * Instagram share graph.facebook.com; ThreadsProvider overrides this
     * to graph.threads.net.
     */
    protected function graphHost(): string
    {
        return 'graph.facebook.com';
    }

    protected function graphUrl(string $path): string
    {
        return 'https://'.$this->graphHost().'/'.$this->graphVersion().'/'.ltrim($path, '/');
    }

    /**
     * The `config()` key prefix a provider's credentials live under, e.g.
     * `services.instagram`.
     */
    abstract protected function configPrefix(): string;

    /**
     * Every Graph call goes through here: the request closure is built by
     * the provider with `http()`/`uploadHttp()`, and ProviderCall owns the
     * rest (transport errors, redirect refusal, Graph's error envelope via
     * MetaGraphErrors, decoding).
     *
     * @param  Closure(): Response  $call
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context): array
    {
        return (new ProviderCall($this->name(), new MetaGraphErrors($this->name())))->json($call, $context);
    }

    private function requireConfig(string $key): string
    {
        $value = config("{$this->configPrefix()}.{$key}");

        if (! is_string($value) || $value === '') {
            throw new ProviderPermanentException("{$this->name()} is not configured: missing {$this->configPrefix()}.{$key}.");
        }

        return $value;
    }
}
