#!/bin/bash

# This file is sourced by pkp-github-actions under "set -e": the option is turned
# off on purpose so that both suites always run, and the worst status is given
# back at the end.
set +e

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/coAuthorParticipants/cypress/tests/functional/*.cy.js"]}'
status=$?

# The PHP suite runs the path the wizard takes (Repo::submission()->submit()) and
# checks the co-author ends up as a participant — on both databases of the matrix,
# which is how the PostgreSQL insert bug was caught.
php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage plugins/generic/coAuthorParticipants/tests
phpunit=$?

if [ "$status" -eq 0 ]; then status=$phpunit; fi
exit $status
