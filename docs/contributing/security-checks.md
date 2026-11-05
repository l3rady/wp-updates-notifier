# Security checks

The things the PHP tests cannot see are checked by scanners: the workflows, committed secrets, vulnerable dependencies, unsafe code patterns and the running site. They need only Docker. Run `make security` before opening a pull request.

The rules for suppressions, baselines, pinned tools and adding a new tool are in [CONTRIBUTING.md](https://github.com/l3rady/wp-updates-notifier/blob/main/CONTRIBUTING.md). This page is how to run the checks and what each one covers.

```sh
make security                    # every check below: run it before a pull request
make check-workflows             # actionlint (with shellcheck) and zizmor over .github/workflows/
make secrets                     # gitleaks over the git history, the working tree and the built zip
make audit                       # composer audit and OSV-Scanner over every lockfile
make sast                        # Semgrep with the rules in .semgrep/, and the suppression checks
make taint                       # Psalm taint analysis
make audit-images                # Trivy over docker/ and the built image (reports only, not in make security)
make test-wp ARGS="--group security"   # just the security tests
```

Reports go to `security-reports/` (gitignored).

## What each check covers

`make security` runs the first five checks below, and each is also a job in `.github/workflows/security.yml`:

| Target | Tool | What it checks | CI job |
| --- | --- | --- | --- |
| `make check-workflows` | actionlint (with shellcheck), zizmor | The workflows in `.github/workflows/` | `workflows` |
| `make secrets` | gitleaks | The git history, the working tree and the built zip | `secrets` |
| `make audit` | composer audit, OSV-Scanner | Known vulnerabilities in every lockfile | `dependencies` |
| `make sast` | Semgrep, with the rules in `.semgrep/` | The plugin code, with tests for the rules, plus a check that every suppression gives a reason | `sast` |
| `make taint` | Psalm | Untrusted data reaching output, mail, URLs or SQL without escaping (taint analysis from untrusted sources to unsafe output) | `taint` |

CI also runs these, outside `make security`:

- **Dependency review** on pull requests: new dependencies must have no known vulnerabilities and a licence on the allowlist.
- **Trivy** over the images (`make audit-images`): on `main` and weekly, report only.
- **OpenSSF Scorecard** (`scorecard.yml`) on `main`.

The workflow runs weekly too, because new advisories appear without a change here.

## zizmor and online audits

`make check-workflows` runs actionlint and zizmor. `make zizmor` runs zizmor alone (`ZIZMOR_FORMAT=sarif ZIZMOR_OUT=file` writes a SARIF report). zizmor runs its online audits only when `GITHUB_PUBLIC_TOKEN` holds a GitHub token that can read public repositories. Without one it skips them.

## Security tests

The security tests (`tests/wp/security/`, `@group security`) run with the rest of the WordPress suite. To run only them, use `make test-wp ARGS="--group security"`. What they cover is on [Tests and checks](testing.md#security-tests).

## Dynamic security scan

```sh
make dast                        # OWASP ZAP against a throwaway copy of the site (about 11 minutes)
```

`make dast` builds an isolated site as its own compose project (`wpun-dast`, from `tests/dast/compose.yml`) with the plugin active, an email channel, the field showcase and outdated plugins, scans it with OWASP ZAP and removes it again, volumes included, even when the scan fails. It publishes no ports and shares no container, volume or network with `make up`, so it never touches your local site.

The scan is defined by `tests/dast/zap-plan.yaml`. It logs in as an administrator, spiders and actively scans only the plugin's settings screens and `admin-post.php` handlers, and then checks that a visitor who is not logged in is turned away from the same URLs. Any Medium or High alert fails the run. The HTML and SARIF reports are written to `security-reports/` (the HTML one is `security-reports/zap-report.html`).

It is not run in CI, because the active scan alone takes about 10 minutes. Run it before each release (see [Releases](releases.md)).

**Limits.** Security tokens turn away most of the active scan's requests to the POST handlers. That is the plugin working, and why the capability and security token matrix tests remain the real enforcement. The scan looks at the plugin from the outside (reflected input, leaking error pages, headers, redirects); it does not replace them.
