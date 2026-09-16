<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/LocaleFilesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFilesTest
 *
 * @brief Translations and email templates. OJS 3.5 has no locale fallback: a
 *  key missing from a locale is shown as ##key##, and a placeholder renamed in
 *  a translation reaches the co-author as literal text.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

class LocaleFilesTest extends PluginTestCase
{
    /** Locale codes shipped by the plugin, using the OJS 3.5 (Weblate) codes. */
    public const LOCALES = [
        'ar', 'az', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'eu', 'fa', 'fi', 'fr', 'fr_CA',
        'gl', 'hu', 'hy', 'id', 'it', 'ja', 'ka', 'mk', 'ms', 'nb_NO', 'nl', 'pl', 'pt', 'pt_BR',
        'ro', 'ru', 'sl', 'sr_Latn', 'sv', 'tr', 'uk', 'vi', 'zh_Hans',
    ];

    public const FILES = ['locale.po', 'emails.po'];

    protected function localeDir(): string
    {
        return dirname(__DIR__) . '/locale';
    }

    /** @return array<string, PoFile> */
    protected function files(string $file): array
    {
        $files = [];
        foreach (self::LOCALES as $locale) {
            $path = $this->localeDir() . "/{$locale}/{$file}";
            if (is_file($path)) {
                $files[$locale] = new PoFile($path);
            }
        }
        return $files;
    }

    public function testShipsExactlyTheSupportedLocaleCodes(): void
    {
        $dirs = array_map('basename', glob($this->localeDir() . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);
        $expected = self::LOCALES;
        sort($expected);

        $this->assertSame($expected, $dirs);
    }

    public function testEveryLocaleHasBothFilesWithTheKeysOfTheEnglishMaster(): void
    {
        foreach (self::FILES as $file) {
            $files = $this->files($file);
            $this->assertCount(count(self::LOCALES), $files, "Some locale is missing {$file}.");
            $master = array_keys($files['en']->entries);
            foreach ($files as $locale => $po) {
                $this->assertSame($master, array_keys($po->entries), "Keys of {$locale}/{$file} differ from en.");
            }
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (self::FILES as $file) {
            foreach ($this->files($file) as $locale => $po) {
                foreach ($po->entries as $key => $value) {
                    $this->assertNotEmpty(trim($value), "Empty translation for {$key} in {$locale}/{$file}.");
                }
            }
        }
    }

    public function testHeaderDeclaresTheDirectoryLocale(): void
    {
        foreach (self::FILES as $file) {
            foreach ($this->files($file) as $locale => $po) {
                $this->assertStringContainsString("Language: {$locale}\n", $po->header, "Wrong Language header in {$locale}/{$file}.");
            }
        }
    }

    public function testPlaceholdersAreKeptInEveryTranslation(): void
    {
        $placeholders = function (string $value): array {
            preg_match_all('/\{\$[a-zA-Z]+\}/', $value, $matches);
            $found = array_unique($matches[0]);
            sort($found);
            return $found;
        };

        foreach (self::FILES as $file) {
            $files = $this->files($file);
            foreach ($files['en']->entries as $key => $english) {
                $expected = $placeholders($english);
                foreach ($files as $locale => $po) {
                    $this->assertSame($expected, $placeholders($po->entries[$key] ?? ''), "Placeholders of {$key} differ in {$locale}/{$file}.");
                }
            }
        }
    }

    public function testHtmlStructureIsKeptInEveryTranslation(): void
    {
        $tags = fn (string $html): array => preg_match_all('#</?[a-z]+#i', $html, $m) ? $m[0] : [];

        foreach (self::FILES as $file) {
            $files = $this->files($file);
            foreach ($files['en']->entries as $key => $english) {
                $expected = $tags($english);
                foreach ($files as $locale => $po) {
                    $this->assertSame($expected, $tags($po->entries[$key] ?? ''), "HTML of {$key} differs in {$locale}/{$file}.");
                }
            }
        }
    }
}
