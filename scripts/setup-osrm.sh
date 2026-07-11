#!/usr/bin/env bash
# setup-osrm.sh — download the Algeria OSM extract and build the OSRM routing
# graph the `osrm` compose service serves. One-time per machine (re-run to
# refresh the map, e.g. twice a year). Safe to re-run: overwrites in place.
#
#   ./scripts/setup-osrm.sh            # build into docker/osrm/data
#
# Needs ~2 GB free disk and ~2 GB RAM for the build. The compose service waits
# politely until the graph exists, so run this before or after `up -d`.
set -euo pipefail

cd "$(dirname "$0")/.."
DATA_DIR="docker/osrm/data"
PBF="algeria-latest.osm.pbf"
IMAGE="ghcr.io/project-osrm/osrm-backend:latest"

mkdir -p "$DATA_DIR"

echo "==> Downloading Algeria extract from Geofabrik (~130 MB)…"
curl -fL --retry 3 -o "$DATA_DIR/$PBF.tmp" \
  "https://download.geofabrik.de/africa/algeria-latest.osm.pbf"
mv "$DATA_DIR/$PBF.tmp" "$DATA_DIR/$PBF"

run() {
  docker run --rm -v "$(pwd)/$DATA_DIR:/data" "$IMAGE" "$@"
}

echo "==> osrm-extract (car profile)…"
run osrm-extract -p /opt/car.lua "/data/$PBF"

echo "==> osrm-partition…"
run osrm-partition "/data/${PBF%.osm.pbf}.osrm"

echo "==> osrm-customize…"
run osrm-customize "/data/${PBF%.osm.pbf}.osrm"

echo "==> Graph built. (Re)start the engine:  docker compose up -d osrm"
echo "    Smoke test:  curl 'http://localhost:5000/route/v1/driving/3.05,36.75;3.06,36.76' (from inside the network)"
