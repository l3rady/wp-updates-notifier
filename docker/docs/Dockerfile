# syntax=docker/dockerfile:1
#
# The documentation site: `make docs`, `make docs-serve`, `make docs-deploy`. MkDocs, Material for MkDocs and mike come
# from requirements.txt (hashes, generated from requirements.in); Mermaid comes from package-lock.json. Base images are
# pinned by tag and digest. The repository is bind-mounted at /app when it runs (see the docs service in
# docker-compose.yml), as .dockerignore leaves out the Markdown files.

# Mermaid, installed from the lockfile. Its single-file build is copied into the final image.
FROM node:26-slim@sha256:36793bcbb160a6a16cede1e62b93f84e26b78a972a8a4c0b879c1c12f22761ec AS mermaid
WORKDIR /opt/mermaid
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-fund --no-audit

FROM python:3.15-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928
# mike drives git (it commits to the gh-pages branch).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /tmp/requirements.txt && rm /tmp/requirements.txt
COPY --from=mermaid /opt/mermaid/node_modules/mermaid/dist/mermaid.min.js /opt/mermaid/mermaid.min.js
WORKDIR /app
