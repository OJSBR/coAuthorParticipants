<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/PluginTestCase.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTestCase
 *
 * @brief The base of this plugin's suite: PKP's own test case, plus the router a
 *        command line request has no way to build.
 */

namespace APP\plugins\generic\coAuthorParticipants\tests;

use APP\core\Application;
use APP\core\PageRouter;
use PKP\tests\PKPTestCase;

abstract class PluginTestCase extends PKPTestCase
{
    /**
     * A command line request has no router, and PKP forms, mailables and
     * account code ask it for the context. The page router answers "no
     * context", the site level, which is all these tests need.
     */
    protected function ensureRouter(): void
    {
        $request = Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new PageRouter();
            $router->setApplication(Application::get());
            $request->setRouter($router);
        }
    }
}
