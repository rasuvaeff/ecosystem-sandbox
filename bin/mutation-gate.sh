#!/usr/bin/env bash
# The mutation gate, run inside the app container: Infection over the domain
# and the use cases (minMsi is the floor), then rasuvaeff/mutation-history as
# a ratchet against the previous run — no mutant killed before may escape now,
# whatever the aggregate says. The infection exit code is kept, not followed:
# a run that fails the floor is exactly the one the ledger must record.
set -uo pipefail

storage=build/mutation-history
scope=sandbox
code=0

vendor/bin/infection --threads=max --no-progress \
    --initial-tests-php-options="-d pcov.enabled=1 -d pcov.directory=src" || code=$?

if [ ! -f build/infection-log.json ] || [ ! -f build/infection-summary.log ]; then
    echo 'MUTATION FAIL: Infection wrote no log' >&2
    exit "$((code == 0 ? 1 : code))"
fi

# Infection prints the MSI rounded; the ledger gets it from the counts.
msi=$(awk -F': ' '/^Total:/{t=$2} /^Killed by Test Framework:/{k+=$2} /^Killed by Static Analysis:/{k+=$2} /^Ignored:/{i=$2} END{if (t-i > 0) printf "%.2f", 100*k/(t-i); else print 0}' build/infection-summary.log)
base=$(vendor/bin/mutation-history trend --scope="$scope" --storage="$storage" | tail -n 1 | cut -f1)
run="$(date -u +%Y%m%dT%H%M%SZ)"

vendor/bin/mutation-history digest --scope="$scope" --run="$run" --log=build/infection-log.json --msi="$msi" --storage="$storage" || code=1
if [ -n "$base" ]; then
    vendor/bin/mutation-history diff --scope="$scope" --base="$base" --head="$run" --storage="$storage" || code=1
else
    echo "first run recorded: the next one is ratcheted against it"
fi

# The trend the tests page and the CI summary draw: one run<TAB>msi line per run.
mkdir -p build/reports
vendor/bin/mutation-history trend --scope="$scope" --storage="$storage" > build/reports/mutation-trend.tsv || true

if [ "$code" -eq 0 ]; then
    echo "MUTATION PASS: MSI ${msi}%, no mutant escaped that the previous run killed"
else
    echo "MUTATION FAIL: see the diff above (NEW lines) or build/infection.log" >&2
fi
exit "$code"
