---
title: WP Updates Notifier
description: A free, open source, extensible, developer-friendly, accessible and security-conscious update notifier for WordPress.
hide:
  - navigation
  - toc
---

![WP Updates Notifier: know when your WordPress site has updates waiting. Free, open source, extensible, accessible, secure.](assets/brand/banner.png#only-light){ .wpun-banner width="1544" height="500" }
![WP Updates Notifier: know when your WordPress site has updates waiting. Free, open source, extensible, accessible, secure.](assets/brand/banner-dark.png#only-dark){ .wpun-banner width="1544" height="500" }

# WP Updates Notifier { .wpun-visually-hidden }

<p class="wpun-lead">A free, open source, extensible, developer-friendly, accessible and security-conscious update notifier for WordPress.</p>

WP Updates Notifier checks your site on a schedule and emails you when WordPress core, a plugin or a theme has an update waiting, so you know without logging in. It is made for sites you rarely visit and for sites you look after for clients.

[Install from WordPress.org](https://wordpress.org/plugins/wp-updates-notifier/){ .md-button .md-button--primary }
[Read the user guide](users/README.md){ .md-button }
[Read the developer guide](developers/README.md){ .md-button }
[View the code on GitHub](https://github.com/l3rady/wp-updates-notifier){ .md-button }

## Why WP Updates Notifier

<div class="grid cards" markdown>

-   :material-hand-heart:{ .lg .middle } __Free and open source__

    ---

    GPL-2.0-or-later and developed in the open on GitHub. No tracking, no analytics and no account to sign up for.

-   :material-puzzle:{ .lg .middle } __Extensible__

    ---

    A public, versioned API lets add-ons bring their own notification channels, item types, routing rules and configuration sources.

    [:octicons-arrow-right-24: Writing a module](developers/writing-a-module.md)

-   :material-code-braces:{ .lg .middle } __Developer friendly__

    ---

    Every hook is documented, settings can come from constants and environment variables, and the Docker setup runs every test with one `make` command.

    [:octicons-arrow-right-24: Hook reference](developers/hooks.md)

-   :material-human:{ .lg .middle } __Accessible__

    ---

    The settings screen is checked against WCAG 2.2 AA with axe and keyboard tests on every change. This site is checked the same way, in both colour schemes.

-   :material-shield-check:{ .lg .middle } __Security conscious__

    ---

    A published threat model, security tests for every request handler, static and taint analysis in CI, and signed releases with build provenance you can verify.

    [:octicons-arrow-right-24: Threat model](security/threat-model.md)

-   :material-email-fast:{ .lg .middle } __Quietly useful__

    ---

    Each new version is reported once, not every day. Choose the schedule, the recipients and whether to watch only the plugins and themes you have active.

</div>

## What it does

- Checks for core, plugin and theme updates hourly, twice daily, daily or weekly, and tells you about each new version once.
- Sends email to one or more addresses, with a test send to check it arrives.
- Can watch only active plugins and themes.
- Lets you set channels and global settings in code: `wp-config.php` constants, environment variables or your own configuration source, so a fleet of sites can share one setup.
- Routes each kind of update to the channels that should hear about it, and add-ons can add new kinds.

Requires WordPress 6.7 or newer and PHP 7.4 or newer.

## Install

- **WordPress.org (recommended):** search for "WP Updates Notifier" under **Plugins → Add New**, or download it from [wordpress.org/plugins/wp-updates-notifier](https://wordpress.org/plugins/wp-updates-notifier/).
- **Composer:** through [WordPress Packagist](https://wpackagist.org/): `composer require wpackagist-plugin/wp-updates-notifier`.
- **Zip:** each [GitHub release](https://github.com/l3rady/wp-updates-notifier/releases) has the plugin zip attached.

Then open **Settings → Updates Notifier** to choose where notifications go.

## Where to go next

- [User guide](users/README.md): set up channels, choose what to be told about, and fix common problems.
- [Developer guide](developers/README.md): how the plugin fits together and how to extend it.
- [Architecture](developers/architecture.md): one update check from start to finish.
- [Hook reference](developers/hooks.md): every action and filter.
- [Decision records](decisions/README.md): why the plugin is built the way it is.
- [Threat model](security/threat-model.md): what the plugin trusts and how each threat is handled.
- [Changelog](https://github.com/l3rady/wp-updates-notifier/blob/main/CHANGELOG.md): what changed in each release.

## Help and feedback

- **Questions:** the [WordPress.org support forum](https://wordpress.org/support/plugin/wp-updates-notifier/).
- **Bugs and ideas:** [GitHub issues](https://github.com/l3rady/wp-updates-notifier/issues).
- **Security problems:** please report them privately, as the [security policy](https://github.com/l3rady/wp-updates-notifier/blob/main/SECURITY.md) explains.
- **Like it?** [Leave a review on WordPress.org](https://wordpress.org/support/plugin/wp-updates-notifier/reviews/#new-post). It helps other people find the plugin.

## About the developer

<div class="wpun-author" markdown>

![Scott Cariss](assets/images/scott-cariss.jpg){ .wpun-author__avatar width="128" height="128" }

<div markdown>

### Scott Cariss

I've been a software engineer for over 20 years and I'm now a Lead Platform Engineer, building the platforms other engineers ship on. I have a passion for all things techy. At home that means a homelab I'm always rebuilding, a smart home that's never quite finished, and plenty of PC gaming.

I first released WP Updates Notifier on WordPress.org in 2011, and it now runs on around 30,000 sites. Version 2.0.0 is a ground-up modernisation: the same simple promise, with the testing, accessibility and security work a plugin on that many sites deserves.

[:fontawesome-brands-github: Scott Cariss on GitHub](https://github.com/l3rady){ .md-button }
[:fontawesome-brands-linkedin: Scott Cariss on LinkedIn](https://www.linkedin.com/in/scott-cariss){ .md-button }

</div>

</div>

## Support the project

WP Updates Notifier is free. If it saves you time and you'd like to say thanks, a donation helps keep it maintained.

- **PayPal:** [paypal.me/scottcariss](https://paypal.me/scottcariss)
- **Bitcoin:** <code class="wpun-address">bc1qd7cxxq4pyak47h6gdxpgggrpesep6hxrzmjs8j</code>
- **Litecoin:** <code class="wpun-address">ltc1q4r0qrfltarylj27znh89a4jt88errvg0uqt8g9</code>
- **Ethereum:** <code class="wpun-address">0x5d9A2e20330d6a673531644d9Aa943F408fcB8E4</code>

A review on WordPress.org or a star on GitHub helps too.
