<?php

/**
 * Enforces the project's hardest rule: no provider-specific logic
 * outside app/Social/Providers. If a network's name shows up anywhere
 * else in app/ (a controller branching on 'telegram', a service
 * special-casing 'slack', ...) this test goes red.
 *
 * Two layers, deliberately:
 *  - a grep-based name check, because it catches *any* mention of a
 *    provider name regardless of what kind of code it's in (string
 *    comparison, match arm, comment referencing real logic, etc.) —
 *    the kind of violation an arch() preset can't see since it only
 *    understands class/namespace structure, not string literals;
 *  - Pest's arch() presets, which catch a different class of leak:
 *    app/Social/Providers/* classes being *depended on* from outside
 *    app/Social (e.g. a controller type-hinting TelegramProvider
 *    directly instead of going through SocialProviderInterface /
 *    ProviderRegistry). Neither check subsumes the other.
 */
it('has no provider-specific branching outside app/Social/Providers', function () {
    $hits = [];
    foreach (['telegram', 'mastodon', 'bluesky', 'discord', 'slack'] as $p) {
        $out = shell_exec("grep -rniE \"['\\\"]{$p}['\\\"]\" ".base_path('app').' --include=*.php '
            ."| grep -v 'app/Social/Providers' | grep -v 'config/social.php' || true");
        if (trim((string) $out) !== '') {
            $hits[] = $out;
        }
    }
    expect($hits)->toBe([]);
});

arch('nothing outside app/Social depends on a concrete provider implementation')
    ->expect('App\Social\Providers')
    ->not->toBeUsed()
    ->ignoring('App\Social\Providers');
