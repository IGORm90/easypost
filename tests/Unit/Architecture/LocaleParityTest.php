<?php

/**
 * Final review, Critical 6, made executable: `lang/en` and `lang/ru` must
 * describe exactly the same set of keys.
 *
 * There was no gate on this anywhere. `lang/ru/mail.php` was missing the
 * whole `post_failed` block that `PostFailedNotification` renders, and
 * nothing noticed — `fallback_locale` quietly served the English string,
 * so a Russian user got a wholly English "your post failed" email and the
 * only symptom was the email itself. The two i18n JSONs the SPA uses were
 * at exact parity purely by luck.
 *
 * Recursive on purpose: a file present in both trees but missing a nested
 * key is precisely the failure that shipped, and a top-level file-name
 * comparison would have called it green.
 */

/**
 * @param  array<array-key, mixed>  $messages
 * @return list<string>
 */
function flattenTranslationKeys(array $messages, string $prefix = ''): array
{
    $keys = [];

    foreach ($messages as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, flattenTranslationKeys($value, $path));

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

/**
 * @return array<string, list<string>>
 */
function localeKeySet(string $locale): array
{
    $root = base_path('lang/'.$locale);
    $sets = [];

    foreach (glob($root.'/*.php') ?: [] as $file) {
        /** @var array<array-key, mixed> $messages */
        $messages = require $file;
        $sets[basename($file, '.php')] = flattenTranslationKeys($messages);
    }

    ksort($sets);

    return $sets;
}

it('keeps lang/en and lang/ru at exact key parity, file by file and key by key', function () {
    $en = localeKeySet('en');
    $ru = localeKeySet('ru');

    expect($en)->not->toBeEmpty('no lang/en files were found — the guard cannot have run');
    expect(array_keys($ru))->toEqual(array_keys($en));

    foreach ($en as $file => $keys) {
        expect($ru[$file])->toEqual($keys, "lang/ru/{$file}.php does not carry the same keys as lang/en/{$file}.php");
    }
});

it('keeps the SPA\'s en.json and ru.json at exact key parity', function () {
    /** @var array<array-key, mixed> $en */
    $en = json_decode((string) file_get_contents(base_path('resources/js/i18n/en.json')), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<array-key, mixed> $ru */
    $ru = json_decode((string) file_get_contents(base_path('resources/js/i18n/ru.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(flattenTranslationKeys($ru))->toEqual(flattenTranslationKeys($en));
});
