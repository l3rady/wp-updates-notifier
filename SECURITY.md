# Security Policy

## Supported versions

Security fixes are made to the latest release only.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately
on GitHub: open the repository's **Security** tab and choose **Report a
vulnerability**. If you cannot use that, email
[scott@cariss.dev](mailto:scott@cariss.dev). Either way, describe the problem
and, if you can, how to reproduce it. You will get a reply as soon as
possible, and a fix will be released before the details are made public.

WP Updates Notifier checks the site for core, plugin and theme updates and sends
what it finds through notification channels. Email is the one built-in channel,
and other plugins can add their own. Channel settings, including secrets such as
webhook URLs, are stored in the database or set in `wp-config.php` constants or
environment variables. The settings screen is for administrators (the
`manage_options` capability, unless a site changes it with the
`sc_wpun_capability` filter).

Problems we would like to hear about include:

- ways for users without that capability to change settings, manage channels,
  run the update check or send messages;
- unescaped output on the settings screen, in notices or in notifications;
- leaking secrets, such as webhook URLs or values from `wp-config.php` and the
  environment, into pages, logs or debug output;
- ways to make the plugin send requests or email to addresses an administrator
  did not configure;
- flaws in the public API that make add-on channels unsafe when used as
  documented.

The [threat model](docs/security/threat-model.md) describes what the plugin
trusts, each entry point, and the control and test that cover it.

## OpenSSF Scorecard

[OpenSSF Scorecard](https://scorecard.dev/viewer/?uri=github.com/l3rady/wp-updates-notifier)
scores the repository's supply-chain setup every week and on every push to
`main` (`.github/workflows/scorecard.yml`). Its results are in the repository's
code scanning alerts. These checks score below 10 on purpose:

- **Code-Review** and part of **Branch-Protection**: one maintainer writes and
  merges every change, so `main` can't require an approving review, a code
  owner's review or approval of the last push. Pull requests, signed commits,
  linear history and the required CI checks still apply to every change, and
  `.github/CODEOWNERS` marks workflow and release-script changes. The rules
  don't bind administrators, so the maintainer can fast-forward a reviewed
  branch onto `main` and keep its signed commits unchanged.
- **Contributors**: a one-maintainer project.
- **Fuzzing**: no fuzzing tool fits a WordPress plugin. Untrusted input is
  covered by the Psalm taint analysis (`make taint`) and the PHPUnit tests.
- **CII-Best-Practices**: no OpenSSF Best Practices badge yet.
- **Packaging** and **Signed-Releases**: not scored until the release workflow
  publishes a signed release.
