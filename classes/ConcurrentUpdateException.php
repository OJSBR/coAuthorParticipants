<?php

/**
 * @file plugins/generic/coAuthorParticipants/classes/ConcurrentUpdateException.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ConcurrentUpdateException
 *
 * @brief Another process wrote the same account, group assignment, participant
 *  or control row first.
 *
 * It is thrown out of the contributor's transaction on purpose. Under InnoDB's
 * REPEATABLE READ isolation a read inside the same transaction keeps seeing the
 * snapshot taken before the other process committed, so looking the row up
 * again there never finds it. Rolling back and running the whole contributor
 * again starts a new snapshot that does.
 */

namespace APP\plugins\generic\coAuthorParticipants\classes;

use RuntimeException;

class ConcurrentUpdateException extends RuntimeException
{
}
