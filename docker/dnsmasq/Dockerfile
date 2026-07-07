# docker/dnsmasq/Dockerfile — split-horizon DNS for the office LAN.
# All behavior comes from command-line flags in docker-compose.prod.yml
# (the answer IP lives in .env, not baked into the image).
FROM alpine:3.20
RUN apk add --no-cache dnsmasq
ENTRYPOINT ["dnsmasq"]
