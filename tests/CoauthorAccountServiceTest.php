<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/CoauthorAccountServiceTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CoauthorAccountServiceTest
 *
 * @brief The account built for a contributor. Nothing is saved: usernames are
 *  checked against the installation's users, read only.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\author\Author;
use APP\journal\Journal;
use APP\plugins\generic\coAuthorParticipants\classes\CoauthorAccountService;
use PKP\security\Validation;

class CoauthorAccountServiceTest extends TestCase
{
    public function testUsernameComesFromTheName(): void
    {
        $username = CoauthorAccountService::suggestUsername('Maria', 'Zzyzxwvut', 'maria@example.org');

        $this->assertStringContainsString('mzzyzxwvut', $username);
    }

    public function testNamesWithoutLatinLettersFallBackToTheEmail(): void
    {
        $username = CoauthorAccountService::suggestUsername('王', '小明', 'wang.xiaoming.zzyzx@example.org');

        $this->assertStringContainsString('wangxiaomingzzyzx', $username);
    }

    public function testASuggestedUsernameIsFreeInThisInstallation(): void
    {
        $username = CoauthorAccountService::suggestUsername('Admin', null, 'x@example.org');

        $this->assertSame(null, \APP\facades\Repo::user()->getByUsername($username, true));
    }

    public function testTheSitePrimaryLocaleIsFilledFromTheLocalizedName(): void
    {
        $names = CoauthorAccountService::withLocale(['en' => 'Mary', 'es' => ' '], 'pt_BR', 'Mary');

        $this->assertSame(['en' => 'Mary', 'pt_BR' => 'Mary'], $names);
    }

    public function testTheAccountLanguageFollowsTheSubmissionWhenTheJournalUsesIt(): void
    {
        $context = new Journal();
        $context->setData('supportedLocales', ['pt_BR', 'en']);
        $context->setData('primaryLocale', 'pt_BR');

        $this->assertSame('en', CoauthorAccountService::pickUserLocale('en', $context));
        $this->assertSame('pt_BR', CoauthorAccountService::pickUserLocale('fr', $context));
    }

    public function testTheBuiltAccountCannotBeUsedWithoutChoosingAPassword(): void
    {
        $author = new Author();
        $author->setData('givenName', ['pt_BR' => 'Ana', 'en' => 'Ana']);
        $author->setData('familyName', ['pt_BR' => 'Souza Zzyzx']);
        $author->setData('country', 'br');

        $context = new Journal();
        $context->setData('supportedLocales', ['pt_BR', 'en']);
        $context->setData('primaryLocale', 'pt_BR');

        $this->ensureRouter();
        $user = (new CoauthorAccountService())->buildUser($author, 'ana.souza.zzyzx@example.org', $context, 'pt_BR');

        $this->assertSame('ana.souza.zzyzx@example.org', $user->getEmail());
        $this->assertTrue((bool) $user->getMustChangePassword());
        $this->assertFalse((bool) $user->getDisabled());
        $this->assertSame('BR', $user->getCountry());
        $this->assertSame(['pt_BR'], $user->getLocales());
        $this->assertNotEmpty($user->getPassword());
        // The stored value is a hash, never a password somebody could know.
        $this->assertFalse(Validation::verifyPassword($user->getUsername(), '', $user->getPassword(), $rehash));
        $this->assertSame(null, $user->getData('orcid'));
    }
}
