# syntax=docker/dockerfile:1
#
# The plugin artwork: `make assets`. Renders the SVG sources in .wordpress-org/src to PNG with librsvg and optimises
# them losslessly with oxipng. fonts-liberation2 is the banner's font (Liberation Sans, metric-compatible with Arial).
# The base image is pinned by tag and digest, oxipng (not packaged by Debian) by version and SHA-256 of the release
# archive. The repository is bind-mounted at /app when it runs (see the assets service in docker-compose.yml).
ARG OXIPNG_VERSION=10.2.1

FROM debian:trixie-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f AS oxipng
ARG OXIPNG_VERSION
ARG TARGETARCH
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*
RUN set -eu; \
    case "$TARGETARCH" in \
      amd64) arch=x86_64; sha=1813750ef592c5350ca79c88f98b2c0876d05c826dc7156245256d4255c1ad17 ;; \
      arm64) arch=aarch64; sha=ff21aa2a005b324c8dfbdf13cd2f469fb6728b27dacded82635824ecd12e3b35 ;; \
      *) echo "Unsupported architecture: $TARGETARCH" >&2; exit 1 ;; \
    esac; \
    name="oxipng-${OXIPNG_VERSION}-${arch}-unknown-linux-musl"; \
    curl -fsSLO "https://github.com/oxipng/oxipng/releases/download/v${OXIPNG_VERSION}/${name}.tar.gz"; \
    echo "${sha}  ${name}.tar.gz" | sha256sum -c -; \
    tar -xzf "${name}.tar.gz"; \
    install -m 0755 "${name}/oxipng" /usr/local/bin/oxipng

FROM debian:trixie-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f
RUN apt-get update \
    && apt-get install -y --no-install-recommends fontconfig fonts-liberation2 librsvg2-bin \
    && rm -rf /var/lib/apt/lists/*
COPY --from=oxipng /usr/local/bin/oxipng /usr/local/bin/oxipng
WORKDIR /app
