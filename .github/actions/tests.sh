#!/bin/bash

# This file is sourced by pkp-github-actions under "set -e", so the report below
# would never run after a failing spec: the option is turned off on purpose and
# the exit status of the spec is given back at the end.
set +e

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/coAuthorParticipants/cypress/tests/functional/*.cy.js"]}'
status=$?

# The plugin's PHP suite, against the database this job has just built: it runs
# the very path the wizard takes (Repo::submission()->submit()) and says whether
# the co-author ends up as a participant.
echo "=== coAuthorParticipants: PHP suite ==="
php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage plugins/generic/coAuthorParticipants/tests
phpunit=$?
echo "=== coAuthorParticipants: end of PHP suite (status ${phpunit}) ==="

# The plugin's own report over the journal the spec has just used: for every
# contributor it says whether it was linked and, when it was not, why.
echo "=== coAuthorParticipants: dry run ==="
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=publicknowledge --dry-run
echo "=== coAuthorParticipants: end of dry run (spec status ${status}) ==="

if [ "$status" -eq 0 ]; then status=$phpunit; fi
exit $status
