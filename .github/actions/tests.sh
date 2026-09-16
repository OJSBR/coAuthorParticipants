#!/bin/bash

set -uo pipefail

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/coAuthorParticipants/cypress/tests/functional/*.cy.js"]}'
status=$?

# The plugin's own report over the journal the spec has just used: it says, for
# every contributor, whether it was linked and why not. Without it a failure of
# the functional test cannot be diagnosed from the CI log at all.
echo "=== coAuthorParticipants: dry run ==="
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=publicknowledge --dry-run || true

exit $status
