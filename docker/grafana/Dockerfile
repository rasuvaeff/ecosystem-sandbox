FROM grafana/grafana:12.1.1

# Installed at build time, not on start: an image layer is cached, while a
# start-time install depends on grafana.com answering within Grafana's own
# deadline, and a slow download left the container exited (and `make ui-full`
# failed) with nothing wrong in the sandbox.
RUN grafana cli plugins install grafana-clickhouse-datasource 4.21.3
