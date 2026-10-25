# Blueprint checks

Files for `make check-blueprint` and `make test-blueprint`.

- `blueprint-schema.json` is the WordPress Playground blueprint JSON schema, vendored so the check needs no network. It is the file `https://playground.wordpress.net/blueprint-schema.json` served on 2026-10-25, byte for byte the `blueprint-schema.json` of the npm package `@wp-playground/blueprints@3.1.60` (the version `make blueprint-smoke` runs). To update it, copy that file from the new package version (the smoke test pins it in `tests/blueprint-smoke/compose.yml`), and change the date and version here.
- `check.js` validates a generated blueprint against it with Ajv, and checks the demo's own rules (landing page, no `@file:` left).
- `package.json` and `package-lock.json` pin Ajv. `docker-compose.yml` (service `blueprint-check`) runs `npm ci` and `check.js` in a node image pinned by digest.
