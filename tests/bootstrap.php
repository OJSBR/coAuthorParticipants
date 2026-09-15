<?php

/**
 * @file plugins/generic/coAuthorParticipants/tests/bootstrap.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Bootstrap for the test suite.
 *
 * Under PKP's PHPUnit configuration the application is already loaded. When the
 * suite runs standalone (`php tests/run.php`) from a plugin installed in
 * plugins/generic/coAuthorParticipants, the OJS installation around it is
 * bootstrapped so the plugin classes are compiled against the real PKP classes
 * they extend. The tests read the database of that installation but never
 * write to it.
 */

if (!class_exists('\PKP\plugins\GenericPlugin')) {
    $ojsRoot = dirname(__DIR__, 4);
    if (!is_file($ojsRoot . '/lib/pkp/includes/bootstrap.php')) {
        fwrite(STDERR, "The plugin must be installed in plugins/generic/coAuthorParticipants of an OJS 3.5 installation to run the suite.\n");
        exit(2);
    }
    chdir($ojsRoot);
    define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
    require_once $ojsRoot . '/lib/pkp/includes/bootstrap.php';
}

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/PoFile.php';
