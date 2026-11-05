# Packaging

This page is about the zip that users install, the files generated for WordPress.org (`readme.txt`, the Live Preview blueprint, the assets) and the plugin artwork. The release steps that use them are on [Releases](releases.md).

## Building the plugin zip

```sh
make dist                        # build/wp-updates-notifier-<version>.zip, runtime files only
make dist-check                  # check its contents
make dist-repro                  # build it twice from fresh clones and compare the SHA-256
make test-dist                   # install it into a fresh WordPress, activate, deactivate, uninstall
make plugin-check                # WordPress.org's Plugin Check on the zip (fails on errors)
make sbom                        # a CycloneDX SBOM of the zip contents
make test-release                # rehearse the scripts the release workflow runs
```

`.distignore` lists what stays out of the zip; the WordPress.org deploy uses the same list. `bin/build-dist.sh` follows `.distignore` and `.gitattributes`.

- `make dist` builds `build/wp-updates-notifier-<version>.zip` with the runtime files only. It also writes the `.sha256` file and wipes `build/`.
- `make dist-check` fails if the contents are not on the allowlist, a required file is missing or the version is wrong. It also fails if the zip holds any of the WordPress.org asset files (see below). The plugin has no runtime dependencies, and it fails if one appears in `composer.json`'s `require` or the zip.
- `make test-dist` installs the zip into a fresh WordPress, then activates, deactivates and uninstalls it.
- `make plugin-check` runs WordPress.org's Plugin Check on the zip (it fails on errors).
- `make test-release` rehearses the scripts the release workflow runs: the copyright year and the signed commit request.

### Reproducible zip and SBOM

The zip is reproducible. `make dist-repro` builds it twice from fresh clones of `HEAD` (so only committed changes count), with a different umask, file times and time zone, and fails if the SHA-256 differs. `make sbom` writes a CycloneDX SBOM of the zip contents to `build/wp-updates-notifier-<version>.cdx.json`.

## The readme

`readme.txt` is not in the repository. `bin/build-dist.sh` generates it into the zip from:

- the plugin header (name, requirements, licence, and the version as the stable tag);
- `CHANGELOG.md` (the latest 3 releases);
- the questions of `docs/users/troubleshooting.md` listed in `.wordpress-org/readme/faq.txt`;
- `.wordpress-org/readme/template.txt` (the rest).

Edit those, never a `readme.txt`.

```sh
make readme                      # preview the generated readme.txt in build/readme.txt (it is not committed)
make check-headers               # plugin header and the generated readme.txt agree and follow WordPress.org's rules
make test-readme                 # test the readme generator and those checks
```

## The Live Preview blueprint

```sh
make blueprint                   # preview the generated WordPress Playground blueprint in build/blueprint.json (it is not committed)
make check-blueprint             # validate it against the Playground blueprint schema (vendored in tests/bin/blueprint)
make test-blueprint              # test the blueprint generator and that schema check
make blueprint-smoke             # run the blueprint with the zip in WordPress Playground CLI and check the demo in a browser (needs the network; not in CI)
```

The Live Preview button of WordPress.org needs `assets/blueprints/blueprint.json` in SVN. Like `readme.txt` it is not committed: `bin/blueprint.php` generates it from `.wordpress-org/blueprint/`. That folder holds the template `blueprint.json`, the demo PHP it includes with `"@file:<name>"` values, and the made-up plugins. The release deploy ships the result to SVN `assets/blueprints/`.

The demo is:

- a mail catcher (`mail-catcher.php`: nothing is sent, Tools → Demo emails shows the messages);
- fake offline updates (`fake-updates.php`, shared with `make screenshots`);
- `seed.php`, which sets up the two channels and runs the update check once.

`make check-blueprint` validates the output against the schema of the Playground version it is tested with (`tests/bin/blueprint/README.md` says which; the schema check uses Ajv in a pinned node image). It also checks that the landing page is the settings screen and no `@file:` placeholder is left. `make test-blueprint` tests the generator (deterministic, missing and unused files) and that the schema check rejects broken blueprints.

`make blueprint-smoke` is the real test: it runs the blueprint in Playground CLI (own compose project `wpun-playground`, never touches `make up`), installing the plugin from the zip of `make dist` because WordPress.org does not have the release yet, and opens the demo in Playwright. See [Tests and checks](testing.md#live-preview-blueprint).

## WordPress.org assets

```sh
make wporg-assets                # assemble build/wporg-assets/, what the release deploys to SVN assets/ (icons, banners, screenshots, blueprints/blueprint.json)
make wporg-assets-check          # check it: allowed names, formats, exact sizes, screenshots against the readme captions, the SVG icon, the blueprint schema
make test-wporg-assets           # test that check against a clean set and broken ones
```

`make wporg-assets` assembles what goes to SVN `assets/` in `build/wporg-assets/`: the top-level files of `.wordpress-org/` (icons, banners, screenshots; never `src/`, `readme/` or `blueprint/`) and `blueprints/blueprint.json`. `make dist` wipes `build/`, so run `make wporg-assets` after it.

`make wporg-assets-check` fails on:

- a file name WordPress.org does not use, or a format that differs from the extension;
- a wrong size (icons 128x128 and 256x256, banners 772x250 and 1544x500);
- an icon or banner over 1 MB or a screenshot over 10 MB (our limits: WordPress.org publishes none);
- screenshots that are not numbered 1..N, or whose number differs from the captions of the generated readme;
- an `icon.svg` with a script, event attribute, `foreignObject` or external reference;
- a blueprint that fails the schema check.

`make test-wporg-assets` tests the checker against a clean set and each kind of mistake. The check runs in the `plugin-check` CI job and before the deploy.

The deploy (`10up/action-wordpress-plugin-deploy`) uses `build/wporg-assets/` as its `ASSETS_DIR`. It syncs it with `rsync --delete` and sets `svn:mime-type` for the png, jpg, gif and svg files itself, so no extra step is needed. The screenshots are made by `make screenshots` (see [Tests and checks](testing.md#screenshots)).

## Plugin artwork

The icon and the banner are SVG files in `.wordpress-org/src/`: `icon` and `banner` are the light versions for WordPress.org, the `-dark` ones are for the dark scheme of the documentation site, and `icon-small` is the simplified icon for 32px and below (the documentation site's logo and tab icon).

Edit the SVG, then run `make assets`: it renders every PNG and copy in `.wordpress-org/` and `docs/assets/brand/` in Docker and optimises them losslessly, with the same bytes every run. Never edit an export by hand. Commit the sources and the exports together.

The banner's text is set in Liberation Sans (metric-compatible with Arial), which the assets image installs, so it renders the same on every machine. Deploying the artwork to WordPress.org is a separate step.

## CI

`ci.yml` has a job for each check: `dist` (PHP 7.4 and 8.5, which also runs `make test-dist`), `dist-repro`, `plugin-check` (with `check-headers`, `test-readme`, `test-blueprint`, `check-blueprint`, `test-wporg-assets` and `wporg-assets-check`), `smoke` and `release-scripts`. The release workflow builds the zip, its `.sha256` and the SBOM (see [Releases](releases.md)). `make assets`, `make screenshots` and `make blueprint-smoke` are not run by CI.

`make smoke` (PHP, the `mysqli` extension and a lint of every PHP file) and `make matrix` (the same on every supported PHP version) are on the [contributor guide](README.md).
