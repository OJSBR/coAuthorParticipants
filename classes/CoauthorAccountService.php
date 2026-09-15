<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/CoauthorAccountService.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorAccountService
 *
 * @brief Finds the account of a contributor by email, or creates one.
 *
 * An existing account is never modified: no name, password, username, ORCID,
 * affiliation or status change, and a disabled account is never reactivated.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use APP\author\Author;
use APP\facades\Repo;
use Illuminate\Database\QueryException;
use PKP\context\Context;
use PKP\core\Core;
use PKP\facades\Locale;
use PKP\security\Validation;
use PKP\user\User;

class CoauthorAccountService
{
    /**
     * Trim and lowercase an email address; null when it is not a valid address.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Whether a database error is a unique key violation.
     */
    public static function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (MySQL/MariaDB duplicate entry 1062) or 23505 (PostgreSQL).
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23505' || ($sqlState === '23000' && in_array($driverCode, [0, 1062], true));
    }

    /**
     * Whether a database error was caused by another process: a unique key
     * violation, a deadlock (1213 / 40001) or a lock wait timeout (1205).
     */
    public static function isConcurrencyError(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return static::isUniqueViolation($e) || in_array($sqlState, ['40001', '40P01'], true) || in_array($driverCode, [1205, 1213], true);
    }

    public function findByEmail(string $normalizedEmail): ?User
    {
        return Repo::user()->getByEmail($normalizedEmail, true);
    }

    /**
     * Create the account of a contributor.
     *
     * @throws ConcurrentUpdateException when another process takes the email or
     *  the username first; the caller runs the contributor again.
     */
    public function createFromAuthor(Author $author, string $normalizedEmail, Context $context, string $submissionLocale): User
    {
        $user = $this->buildUser($author, $normalizedEmail, $context, $submissionLocale);

        try {
            $userId = Repo::user()->add($user);
        } catch (QueryException $e) {
            if (static::isConcurrencyError($e)) {
                throw new ConcurrentUpdateException('Another process created an account with the same email or username.', 0, $e);
            }
            throw $e;
        }

        return Repo::user()->get($userId, true);
    }

    /**
     * Build, without saving, the account of a contributor.
     */
    public function buildUser(Author $author, string $normalizedEmail, Context $context, string $submissionLocale): User
    {
        $siteLocale = static::getSitePrimaryLocale();
        $givenNames = static::withLocale((array) $author->getData('givenName'), $siteLocale, $author->getLocalizedGivenName());
        $familyNames = array_filter((array) $author->getData('familyName'), fn ($value) => trim((string) $value) !== '');

        $user = Repo::user()->newDataObject();
        $user->setData('givenName', $givenNames);
        if ($familyNames) {
            $user->setData('familyName', static::withLocale($familyNames, $siteLocale, $author->getLocalizedFamilyName()));
        }

        $preferred = array_filter((array) $author->getData('preferredPublicName'), fn ($value) => trim((string) $value) !== '');
        if ($preferred) {
            $user->setData('preferredPublicName', $preferred);
        }

        $username = static::suggestUsername($givenNames[$siteLocale] ?? '', $familyNames[$siteLocale] ?? reset($familyNames) ?: null, $normalizedEmail);

        $user->setUsername($username);
        $user->setEmail($normalizedEmail);
        // A strong random password nobody knows; the person sets their own
        // through the password link. Only the hash is stored.
        $user->setPassword(Validation::encryptCredentials($username, bin2hex(random_bytes(32))));
        $user->setMustChangePassword(true);
        $user->setDateRegistered(Core::getCurrentDate());
        $user->setInlineHelp(1);
        $user->setDisabled(false);

        $country = strtoupper(trim((string) $author->getData('country')));
        if ($country !== '' && Locale::getCountries()->getByAlpha2($country)) {
            $user->setCountry($country);
        }

        $user->setLocales([static::pickUserLocale($submissionLocale, $context)]);

        return $user;
    }

    /**
     * The username suggested by OJS, which already avoids existing usernames,
     * falling back to the email when the name has no Latin letters.
     */
    public static function suggestUsername(string $givenName, ?string $familyName, string $normalizedEmail): string
    {
        $suggestion = Validation::suggestUsername($givenName, $familyName);
        if (preg_match('/^[0-9]*$/', $suggestion)) {
            $localPart = (string) strstr($normalizedEmail, '@', true);
            $suggestion = Validation::suggestUsername($localPart);
        }
        if (preg_match('/^[0-9]*$/', $suggestion)) {
            $suggestion = Validation::suggestUsername('coauthor');
        }

        return $suggestion;
    }

    /**
     * Make sure a localized name has a value in the given locale.
     */
    public static function withLocale(array $values, string $locale, ?string $fallback): array
    {
        $values = array_filter($values, fn ($value) => trim((string) $value) !== '');
        if (!isset($values[$locale]) && ($fallback ?? '') !== '') {
            $values[$locale] = $fallback;
        }

        return $values;
    }

    /**
     * The submission language when the journal uses it, otherwise the journal's
     * primary language.
     */
    public static function pickUserLocale(string $submissionLocale, Context $context): string
    {
        $supported = (array) $context->getSupportedLocales();

        return in_array($submissionLocale, $supported, true) ? $submissionLocale : $context->getPrimaryLocale();
    }

    protected static function getSitePrimaryLocale(): string
    {
        return \APP\core\Application::get()->getRequest()->getSite()->getPrimaryLocale();
    }
}
