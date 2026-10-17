# Sourced by every scenario that runs the outbox worker by hand: the `ui`
# profile's background worker would claim the scenario's messages first, and
# the scenario would fail on state it never produced.
if [ -n "$(${COMPOSE:-docker compose} --profile ui ps -q --status running worker 2>/dev/null)" ]; then
    echo 'The web UI worker is running and would take this scenario'"'"'s outbox messages. Stop it first: make ui-down' >&2
    exit 1
fi
