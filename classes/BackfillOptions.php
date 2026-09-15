<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/BackfillOptions.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BackfillOptions
 *
 * @brief Parsing and validation of the backfill command line options, kept
 *  apart from the tool so they can be tested without running it.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use DateTime;

class BackfillOptions
{
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 500;

    /**
     * Parse --name and --name=value arguments.
     *
     * @return array<string, string|bool>|null null on an unknown argument
     */
    public static function parse(array $argv): ?array
    {
        $known = ['journal', 'dry-run', 'execute', 'submission-id', 'after-id', 'batch-size', 'send-email', 'retry-failed-emails', 'from-date', 'to-date', 'output', 'help'];
        $options = [];
        foreach ($argv as $argument) {
            if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $matches) || !in_array($matches[1], $known, true)) {
                return null;
            }
            $options[$matches[1]] = array_key_exists(2, $matches) ? $matches[2] : true;
        }

        return $options;
    }

    /**
     * Validate the options.
     *
     * @return string[] error messages
     */
    public static function validate(array $options): array
    {
        $errors = [];
        $isFlag = fn (string $name) => ($options[$name] ?? null) === true;
        $isPositiveInt = fn (string $name) => isset($options[$name]) && is_string($options[$name]) && ctype_digit($options[$name]) && (int) $options[$name] > 0;

        if (!isset($options['journal']) || !is_string($options['journal']) || trim($options['journal']) === '') {
            $errors[] = '--journal=<path or id> is required.';
        }
        if ($isFlag('dry-run') === $isFlag('execute')) {
            $errors[] = 'Use exactly one of --dry-run or --execute.';
        }
        foreach (['dry-run', 'execute', 'retry-failed-emails'] as $flag) {
            if (isset($options[$flag]) && $options[$flag] !== true) {
                $errors[] = "--{$flag} takes no value.";
            }
        }
        foreach (['submission-id', 'after-id'] as $name) {
            if (isset($options[$name]) && !$isPositiveInt($name)) {
                $errors[] = "--{$name} must be a positive integer.";
            }
        }
        if (isset($options['batch-size']) && (!$isPositiveInt('batch-size') || (int) $options['batch-size'] > self::MAX_BATCH_SIZE)) {
            $errors[] = '--batch-size must be between 1 and ' . self::MAX_BATCH_SIZE . '.';
        }
        if (isset($options['send-email']) && !in_array($options['send-email'], [SyncOptions::EMAIL_ALL, SyncOptions::EMAIL_NEW, SyncOptions::EMAIL_NONE], true)) {
            $errors[] = '--send-email must be all, new or none.';
        }
        foreach (['from-date', 'to-date'] as $name) {
            if (isset($options[$name]) && (!is_string($options[$name]) || !static::isValidDate($options[$name]))) {
                $errors[] = "--{$name} must be a valid date in the YYYY-MM-DD format.";
            }
        }
        if (isset($options['from-date'], $options['to-date']) && empty(array_filter($errors, fn ($e) => str_contains($e, 'date'))) && $options['from-date'] > $options['to-date']) {
            $errors[] = '--from-date must not be after --to-date.';
        }
        if (isset($options['output']) && (!is_string($options['output']) || trim($options['output']) === '')) {
            $errors[] = '--output needs a file path.';
        }

        return $errors;
    }

    public static function isValidDate(string $value): bool
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
