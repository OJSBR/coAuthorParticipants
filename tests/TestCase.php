<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/TestCase.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class TestCase
 *
 * @brief Base class for the suite, usable with or without PHPUnit.
 *
 * Under CI (and under PKP's `ApplicationPlugins` PHPUnit suite, which collects
 * plugins/../tests/*Test.php) this extends the real PHPUnit TestCase. On a plain
 * server, where the OJS tarball ships no development dependencies at all, it
 * falls back to a minimal implementation of the handful of assertions used here,
 * so that `php tests/run.php` still exercises the same tests.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

if (!defined('COAUTHORPARTICIPANTS_STANDALONE_TESTS') && class_exists('\PHPUnit\Framework\TestCase')) {
    abstract class TestCaseBase extends \PHPUnit\Framework\TestCase
    {
    }
} else {
    abstract class TestCaseBase
    {
        public int $assertionCount = 0;

        protected function assertTrue($condition, string $message = ''): void
        {
            $this->assertionCount++;
            if ($condition !== true) {
                throw new AssertionFailed($message ?: 'Failed asserting that the condition is true.');
            }
        }

        protected function assertFalse($condition, string $message = ''): void
        {
            $this->assertTrue($condition === false, $message ?: 'Failed asserting that the condition is false.');
        }

        protected function assertSame($expected, $actual, string $message = ''): void
        {
            $this->assertTrue(
                $expected === $actual,
                $message ?: sprintf('Failed asserting that %s is identical to %s.', var_export($actual, true), var_export($expected, true))
            );
        }

        protected function assertCount(int $expected, $haystack, string $message = ''): void
        {
            $actual = is_countable($haystack) ? count($haystack) : -1;
            $this->assertTrue($expected === $actual, $message ?: sprintf('Failed asserting that the count %d matches the expected %d.', $actual, $expected));
        }

        protected function assertEmpty($value, string $message = ''): void
        {
            $this->assertTrue(empty($value), $message ?: 'Failed asserting that the value is empty.');
        }

        protected function assertNotEmpty($value, string $message = ''): void
        {
            $this->assertTrue(!empty($value), $message ?: 'Failed asserting that the value is not empty.');
        }

        protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->assertTrue(str_contains($haystack, $needle), $message ?: sprintf('Failed asserting that "%s" contains "%s".', $haystack, $needle));
        }

        protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->assertTrue(!str_contains($haystack, $needle), $message ?: sprintf('Failed asserting that "%s" does not contain "%s".', $haystack, $needle));
        }
    }

    class AssertionFailed extends \Exception
    {
    }
}

abstract class TestCase extends TestCaseBase
{
    /**
     * A command line request has no router, and PKP forms, mailables and
     * account code ask it for the context. The page router answers "no
     * context", the site level, which is all these tests need.
     */
    protected function ensureRouter(): void
    {
        $request = \APP\core\Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new \APP\core\PageRouter();
            $router->setApplication(\APP\core\Application::get());
            $request->setRouter($router);
        }
    }
}
