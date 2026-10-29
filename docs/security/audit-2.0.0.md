# Security audit for 2.0.0

The security sign-off for 2.0.0 (#228, the last step of the security epic #229). It records a result for every item of
three checklists, run against `main` at `d2f4377` on 2026-10-26:

- [OWASP ASVS 5.0.0](https://github.com/OWASP/ASVS/tree/v5.0.0) Level 1: all 70 requirements.
- The [OWASP Top 10:2025](https://github.com/OWASP/Top10/tree/3a31f35346c3f4e90f350395124382fa53af2afe/2025), mapped
  onto the ASVS results.
- The security chapter of the WordPress developer handbooks, and the WordPress.org plugin guidelines that bear on
  security and trust.

It also re-checks the areas #228 names, and every suppression of a security sniff or rule.

**Result: no High or Medium finding is open for 2.0.0, and nothing blocks the release once #328 is merged.**

- **On a single site, the only setup 2.0.0 supports, there are no findings above Info.**
- **Multisite: three Medium findings, fixed in #328.**
  - They share one cause: the default capability let a site administrator who is not a super admin use the settings.
  - On multisite that person could:
    - mail out the network's list of pending updates, which core shows only to super admins;
    - pick up channel settings that a network owner set in `wp-config.php` for another site;
    - send mail through the network's mailer from any From address.
  - The threat model counted that site administrator as trusted, so these sit on the old trust boundary rather than
    below it. #328 moves the boundary to match core: on multisite only super admins manage the plugin by default.
- **Low and Info hardening:** 16 items, none reachable from below the trust boundary. They are filed as #329 (plugin
  code) and #330 (CI and release), for 3.0.0.
- **Fixed in the audit branch:** one documentation gap (V15.1.1). `SECURITY.md` now gives fix times for vulnerable
  development tools.
- **Reporting channel:** the reporting policy in `SECURITY.md` now promises a reply within 7 days and a fix or a plan
  within 30 days. No third-party disclosure programme was joined (decision on #228): GitHub private vulnerability
  reporting stays the one channel.

## Method

The audit is checklist-driven, so it can be repeated for a later release.

- **Scope:** `includes/`, `modules/`, `uninstall.php`, `wp-updates-notifier.php`, `bin/`, `.github/`, the release build,
  and the GitHub repository settings that the release depends on.
- **Who did it:** the maintainer's session split the checklists into three slices: encoding and validation;
  authorisation, data and configuration; secure coding and supply chain. Each slice was a read-only source review with
  the [WordPress Security Skills](https://github.com/wpultimatesecurity/WordPress-Security-Skills) pack, checked
  against the WordPress 7.1.3 source. The maintainer's session then verified every Fail and every open question itself,
  and decided the N/A rows.
- **Severity rubric:**

  | Severity | What it means |
  | --- | --- |
  | Critical | Unauthenticated code execution, SQL injection or authentication bypass. |
  | High | Reachable by a visitor or a low-privilege user: stored XSS, CSRF on a state change, privilege escalation, or a secret disclosed to someone who isn't an administrator. |
  | Medium | Needs an unusual setup, or a lower-trust user with limited impact. |
  | Low or Info | Hardening, or input the [threat model](threat-model.md) trusts. That input is administrators, add-on code, translations, api.wordpress.org data, and `wp-config.php` or the environment. |

- **Inputs:**
  - the [threat model](threat-model.md);
  - the triaged [AI review](ai-review-2.0.0.md) (#230), whose five hardening items were fixed in #325 before this
    audit;
  - the security test group;
  - the scripted tools below.

### Tool results at `d2f4377`

| Tool | Command | Result |
| --- | --- | --- |
| actionlint, zizmor 1.31.1 | `make check-workflows` | Clean. In CI, `security.yml` passed on `main` with zizmor's online audits, and no zizmor code-scanning alert is open. |
| gitleaks | `make secrets` | No leaks in the history, the working tree or the release zip. |
| composer audit, OSV-Scanner | `make audit` | No issues. |
| Semgrep | `make sast` | 7 rules on 48 files, 0 findings; the rule tests pass; every suppression carries a reason. |
| Psalm taint analysis | `make taint` | No errors. |
| OWASP ZAP 2.17.0 | `make dast` | No Medium or High alerts. The 30 notes come from 6 rules (10010, 10021, 10036, 10037, 10054, 10096) about cookies and headers set by WordPress core and the test server, not the plugin. |
| PHPUnit security group | `make test-wp ARGS="--group security"` | 157 tests, 33,338 assertions, 1 skipped (the multisite-only control). |
| OpenSSF Scorecard | `scorecard.yml` | Open alerts only for the checks `SECURITY.md` explains (Branch-Protection, Code-Review, Fuzzing, CII-Best-Practices). |

### Repository settings

Checked with `gh api` on 2026-10-26.

- **`release` environment:** deploys only from protected branches and needs the maintainer's approval. It holds
  `RELEASE_SIGNING_KEY`.
- **`wordpress-org` environment:** deploys only `v*` tags and needs the maintainer's approval. It holds the SVN
  username and password.
- **Repository-level secrets:** `RELEASE_APP_PRIVATE_KEY` is the only one. Moving it into an environment is CI4 in
  #330.
- **`release tags` ruleset:** active on `refs/tags/v*`. It blocks deletion, updates and non-fast-forward moves, and has
  no bypass actors.
- **`main` branch protection:** requires signed commits, linear history and the required checks.

## ASVS 5.0 Level 1

**Result values:**

- **Pass:** the control is in place.
- **Fail:** the control is missing, with a link to the finding.
- **N/A:** the requirement does not apply, with the reason.

**How to read the rows:**

- Paths are relative to the repository root.
- Line numbers are at `d2f4377`.
- "Review only" means no automated proof exists beyond the source review.

### V1 Encoding and Sanitization

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V1.2.1 | Pass | Each output context has its own encoder: HTML text and attributes (`esc_html`, `esc_attr`), URLs (`esc_url`), Markdown (`Notification_Renderer::escape_markdown`), and mail headers (left to PHPMailer). | `security/test-stored-xss.php`; `test-notification.php`; `make lint` (WPCS EscapeOutput); `make taint` |
| V1.2.2 | Pass | HTML links use `esc_url`. Plain text and Markdown keep only http(s) (`Notification_Renderer::web_url`, #325). Ids in query arguments are limited to `[a-z0-9_]`. | `test-notification.php::test_markdown_drops_unsafe_link_targets`; `security/test-hardening-plain-text-links.php`; `security/test-settings-input.php::test_url_fields_reject_script_schemes` |
| V1.2.3 | N/A | No JavaScript or JSON is output. `wp_json_encode` is used only to build an in-request key. | Review only |
| V1.2.4 | N/A | No `$wpdb` or SQL. Storage uses the Options and Meta APIs. | Review only; Psalm taint |
| V1.2.5 | N/A | No operating system calls. Mail goes through `wp_mail()`. | Review only; Semgrep |
| V1.3.1 | N/A | No HTML input is accepted. Text is cleaned with `sanitize_text_field` and `sanitize_textarea_field`. | `security/test-settings-input.php::test_every_field_type_refuses_or_neutralises_a_payload` |
| V1.3.2 | Pass | No `eval` or dynamic code. `call_user_func` only targets callbacks registered in code (`Field_Types`, `Field_Renderer`). | Review only; Semgrep |
| V1.5.1 | N/A | No XML is parsed. | Review only |

### V2 Validation and Business Logic

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V2.1.1 | Pass | The rules for each field type are documented in [Writing a module](../developers/writing-a-module.md) and in the [threat model](threat-model.md) (§2). | Review only |
| V2.2.1 | Pass on a single site; multisite fixed in #328 | Enums, booleans, tabs, actions and ids are checked against allowlists. Numbers are bounded, URLs must be http(s), and addresses must pass `is_email`. On multisite, a site administrator could set any From address (finding M3). | `security/test-settings-input.php`; `test-field-types.php`; `test-settings-validation.php` |
| V2.2.2 | Pass | All validation runs on the server. No JavaScript ships. | `security/test-settings-input.php::test_values_of_the_wrong_shape_are_not_stored_raw` |
| V2.3.1 | Pass | Steps can't be skipped to any effect. Every step re-checks the capability, a security token bound to the mode and type or id, and whether the channel may be changed (`Channel_Form::handle_save`, `Channels_Tab::changeable`). | `security/test-capability-matrix.php::test_a_refused_request_changes_nothing`; `test-channel-form.php` |

### V3 Web Frontend Security

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V3.2.1 | N/A | The plugin serves no API, download or user file. Every PHP file exits when it is loaded outside WordPress. | `make sast-ignores` (the file guard check); `make dast` |
| V3.2.2 | N/A | No client-side code ships (`assets/css/admin.css` only). | Review only |
| V3.3.1 | N/A | The plugin sets no cookies. | Review only |
| V3.4.1 | N/A | The plugin sends no headers. HSTS is the host's job. | Review only |
| V3.4.2 | N/A | No CORS headers are sent. | Review only |
| V3.5.1 | Pass | Every state change needs the capability and a security token for that action and target, and is refused with a 403 otherwise. | `security/test-capability-matrix.php` |
| V3.5.2 | N/A | Nothing relies on a CORS preflight. | n/a |
| V3.5.3 | Pass | Every sensitive action is a POST. The exception is the Slack notice dismiss: a GET that needs a security token and the capability, and only sets the user's own flag. Making it a POST is HN7 in #329. | `security/test-capability-matrix.php`; `test-slack-notice.php` |

### V4 API and Web Service

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V4.1.1 | Pass | Screens render inside core's admin, which sends Content-Type with a charset. Refusals use `wp_die`. Mail sets `text/plain` or `text/html`. | Review only |
| V4.4.1 | N/A | No WebSockets. | Review only |

### V5 File Handling

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V5.2.1 | N/A | No file uploads. | Review only |
| V5.2.2 | N/A | No files are accepted. | Review only |
| V5.3.1 | N/A | The plugin writes no files. | Review only |
| V5.3.2 | Pass | Every path is built from trusted parts: the autoloader allowlist, translation files with fixed names, and reads of installed plugins and themes only (#325). | `test-autoloader.php::test_names_with_path_characters_are_refused`; `security/test-hardening-installed-items.php`; `test-translations.php` |

### V6 Authentication

Authentication belongs to WordPress core. The plugin has no login, passwords, accounts, password reset or rate
limiting of its own.

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V6.1.1 | N/A | Core's. | n/a |
| V6.2.1 to V6.2.5, V6.2.8 | N/A | The plugin has no user passwords. | n/a |
| V6.2.6 | Pass (by analogy) | The plugin's only credential-like inputs are channel secrets. They use `type="password"` and are never filled in (`Field_Renderer::secret`). | `test-field-types.php::test_render_secret_never_outputs_value` |
| V6.2.7 | N/A | Not a user password. The secret input sets `autocomplete="off"`; HN4 in #329 changes that to `new-password`. | n/a |
| V6.3.1, V6.3.2 | N/A | Core's. | n/a |
| V6.4.1, V6.4.2 | N/A | Core's. | n/a |

### V7 Session Management

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V7.2.1 to V7.2.4, V7.4.1, V7.4.2 | N/A | Sessions belong to WordPress core. The plugin creates, reads and ends no sessions and sets no cookies. | Review only |

### V8 Authorization

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V8.1.1 | Pass | The rules are documented in the [threat model](threat-model.md) (Scope, §1), `SECURITY.md` and the `sc_wpun_capability` filter's docblock. #328 corrects the multisite rule. | Review only |
| V8.2.1 | Pass | Every function has an explicit capability check (`Settings_Page::capability()`), made before anything changes. An invalid filter value falls back to the default. | `security/test-capability-matrix.php`; `test-settings-screen.php::test_the_page_refuses_users_without_the_capability` |
| V8.2.2 | Pass on a single site; multisite fixed in #328 | Ids are validated and looked up, security tokens are bound to the id, and user meta is scoped per user and site. On multisite, a site administrator reached network data (findings M1 and M2). | `security/test-capability-matrix.php` (a token for another channel); #328 adds the multisite rows |
| V8.3.1 | Pass | All checks run in PHP on the server, before any change. | Review only |

### V9 Self-contained Tokens and V10 OAuth and OIDC

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V9.1.1 to V9.1.3, V9.2.1 | N/A | The plugin issues and accepts no self-contained tokens. Core's security tokens are not self-contained tokens. | Review only |
| V10.4.1 to V10.4.5 | N/A | The plugin is not an OAuth authorisation server or client. | Review only |

### V11 Cryptography

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V11.3.1 | N/A | No encryption. Secrets are stored in plain text, as documented (threat model §8). Sites can supply them from `wp-config.php` or the environment instead. | Review only |
| V11.3.2 | N/A | No ciphers. | Review only |
| V11.4.1 | Pass | The only hash is an md5 key, used to warn once per field per request (`Enum_Field`, `Boolean_Field`). It is not a security use. | Review only |

### V12 Secure Communication

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V12.1.1 | N/A | The plugin configures no TLS. Core and the host negotiate it. | Review only |
| V12.2.1 | Pass | The plugin opens no HTTP connection itself, and email goes through `wp_mail`. Add-on channels are told to use `wp_safe_remote_*` ([Writing a module](../developers/writing-a-module.md)). | `security/test-outbound-requests.php`; the Semgrep rule `wpun-outbound-http-safe-only` |
| V12.2.2 | Pass | No `sslverify`, `https_ssl_verify` or `http_request_args` override, so core's certificate check stays on. | Review only; Semgrep |

### V13 Configuration

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V13.4.1 | Pass | The zip is built from tracked files minus `.distignore`, then checked against an allowlist (`bin/build-dist.sh`, `bin/check-dist.sh`). It contains no VCS folders, tests, `bin/` or `vendor/`. | `make dist-check`; `make test-dist` |

### V14 Data Protection

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V14.2.1 | Pass | Secrets travel only in POST bodies, and the password input is always empty. URLs carry only the tab, action, id and a security token. | `security/test-secrets.php::test_a_secret_never_shows` |
| V14.3.1 | N/A | Nothing is stored on the client: no JavaScript, no cookies, no Web Storage. | Review only |

### V15 Secure Coding and Architecture

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| V15.1.1 | Pass (fixed in this audit) | `SECURITY.md` gives fix times for a vulnerable development dependency, Action or tool image: 7 days for High or Critical, 30 days otherwise. Before this audit only the update cadence was documented. | Review only |
| V15.2.1 | Pass | No third-party code ships. `bin/check-dist.sh` fails on `vendor/` or `node_modules/` in the zip, and on any `require` besides `php`. Development components were clean on 2026-10-26. | `make dist-check`; `make audit`; dependency review |
| V15.3.1 | Pass | Secret fields reach a renderer only as `'1'` or `''`. Site Health and the field errors name the key and the source, never the value. `Channel_Instance` redacts its settings in debug output and in `serialize()` (#325). | `security/test-secrets.php`; `security/test-hardening-instance-serialize.php`; `security/test-hardening-custom-field-secrets.php` |

### V16 Security Logging and Error Handling

ASVS 5.0 has no Level 1 requirement in this chapter. #228 asks for a note on it all the same:

- **Debug log:** when `WP_DEBUG` is on, a module that throws is logged with its instance id, type and exception class.
  The message and stack trace are never logged, because a message can hold a webhook URL.
- **Delivery results:** they keep a fixed message, plus the exception class.
- **`_doing_it_wrong` notices:** they name types and fields, never values.
- **Site Health:** it lists rejected configuration keys with their source, never the value.
- **`last_result`:** it is shown only to administrators, escaped.

The proof is `security/test-secrets.php::test_a_secret_never_shows`. It covers delivery results, stored state, notices,
every tab, Site Health, `print_r` and `var_dump`, configuration problems, `_doing_it_wrong` messages and the PHP error
log.

Two gaps are filed in #329:

- **HN6:** a failed send stores the mailer's error text as it is.
- **HN8:** a module that throws outside its `send()` stops the whole check, which is narrower than threat model §6
  promises.

## WordPress security guidance

### Developer handbooks

| ID | Result | Evidence | Proof |
| --- | --- | --- | --- |
| [Capabilities](https://developer.wordpress.org/plugins/security/checking-user-capabilities/) | Pass | One specific capability through `Settings_Page::capability()`, with a fail-safe fallback, checked in every handler and again when the page renders. | `security/test-capability-matrix.php` |
| [Security tokens](https://developer.wordpress.org/apis/security/nonces/) | Pass | One security token per action, bound to the id, mode or type. Every check fails closed. `options.php` uses core's check. | `test-channels-tab.php::test_nonce_and_capability_are_enforced_for_every_action`; `security/test-capability-matrix.php` (wrong action, other channel, other user) |
| [Data validation](https://developer.wordpress.org/apis/security/data-validation/) | Pass | Allowlists, `is_email`, the http(s) rule, integer bounds and the id pattern. Unknown ids and types are refused. | `test-field-types.php`; `test-settings-validation.php` |
| [Sanitizing](https://developer.wordpress.org/apis/security/sanitizing/) | Pass | Request data is unslashed, then sanitised: `sanitize_key` for ids, actions and tabs, and the field types for everything else. | `security/test-settings-input.php` |
| [Escaping](https://developer.wordpress.org/apis/security/escaping/) | Pass | Output is escaped late everywhere. #325 fixed the Settings API titles. Settings error messages are still passed to core unescaped (HN2 in #329); that is trusted input. | `security/test-stored-xss.php`; `security/test-hardening-settings-titles.php`; `make lint` |
| [Common vulnerabilities: SQL injection](https://developer.wordpress.org/apis/security/common-vulnerabilities/) | Pass | There is no SQL. Uninstall uses `delete_metadata`, which core prepares. | Review only |
| Common vulnerabilities: XSS | Pass | Update data, labels, last results, configuration problems, rejected modules and the plugin row are all escaped. | `security/test-stored-xss.php` |
| Common vulnerabilities: CSRF | Pass | State changes happen only in POST handlers that check the capability and a security token. The GET dismiss checks both too. | As security tokens; `test-slack-notice.php::test_dismissing_needs_a_nonce_and_manage_options` |
| Safe redirects | Pass | All four `wp_safe_redirect` calls are followed by `exit`, and go to fixed `admin_url()` targets with a sanitised id. The plugin has no `wp_redirect`. | WPCS SafeRedirect; `security/test-capability-matrix.php` |
| [HTTP API](https://developer.wordpress.org/plugins/http-api/) | Pass | As V12.2.1. The `Retry-After` parser is strict and capped. | `test-retry-after.php`; `security/test-outbound-requests.php` |

### WordPress.org plugin guidelines

From the [detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

| ID | Result | Evidence |
| --- | --- | --- |
| 1, 2: GPL | Pass | GPL-2.0-or-later in the header, `LICENSE`, `composer.json` and the readme (ADR 0001). No third-party code, fonts or images are bundled. |
| 4: Human-readable code | Pass | No obfuscation. The `.l10n.php` files are WP-CLI output, rebuilt and compared byte for byte by `make check-translations`. The source and the build tools are public. |
| 5: No trialware | Pass | No locked features, licence key or upsell. The About tab has donate and review links only. |
| 7: No tracking | Pass | No telemetry. Every outbound message is listed under "Outbound messages" below. |
| 8: No remote code | Pass | No remote code, remote assets or iframes. The stylesheet is local. The sandboxed iframe in the Playground demo plugin is not in the zip. |
| 11: No admin hijacking | Pass | No global admin notices. The plugin's notices show only on its own page: the Slack notice can be dismissed, and the configuration notices last only while their condition does. `hide_updates` hides core's nag only from users who can't update. |
| 13: Default libraries | Pass | No bundled libraries. The plugin uses `wp_mail`, the Settings API and the HTTP API types. |

#### Outbound messages

1. **The notification email**, to the recipients an administrator configured. It is sent:
   - by the cron event, at the chosen frequency;
   - by "Check now" and "Send test", each behind the capability and a security token;
   - by `sc_wpun_run_check`, from trusted code.
2. **A one-time email about the Slack channel** being removed. It goes to the email channel's recipients, or to the
   admin email if there are none.
3. **The deprecated `send_email_message()`**, only when trusted code calls it.
4. **Core's own update checks to api.wordpress.org**, which the plugin fires before each check. Core rate-limits them
   itself.

## Areas re-checked

| Area | Result | Notes |
| --- | --- | --- |
| #72 Escaping on the settings screen | Pass | Every tab, form, confirmation, error summary and notice, and the About tab. The titles were fixed in #325. |
| #164 Keeping and clearing secrets | Pass | Renderers see only `'1'` or `''`. An empty input keeps the stored value and `__clear` removes it. Round-trip values drop secrets. Outside values are never written to the database. A secret made only of spaces clears the stored value (HN3 in #329). |
| #107 Configuration sources | Pass on a single site; multisite fixed in #328 | Values are never echoed. Keys always start with `SC_WPUN_`, and ids allow no double underscore, so no other constant can be read. Environment variables are off unless the site turns them on. The order of sources is documented and add-ons are trusted. On multisite, constants apply to every site (finding M2). |
| #165 and #166 Handlers | Pass | See "Request handlers" below. |
| Email header injection | Pass | No header injection through the From name, From address, Reply-To, subject or prefix, recipients, or extra headers. The plugin, core and PHPMailer each strip or refuse CR and LF; the traces are under "Email header injection" below. |
| `uninstall.php` | Pass | It removes every option, the 1.x transients, every user meta key and the cron event, on every site of a network (cross-checked against every writer). Batching large networks is HN10 in #329. |
| REST, AJAX and shortcodes | Pass | None are registered. There are only `admin_post_*` hooks, which core runs for logged-in users only. |
| Suppressions | Pass | 16 found, all still justified. Two have no written reason, in files the checker doesn't scan (CI1 in #330). See "Suppressions" below. |

### Request handlers

In every handler, the capability is checked first and the security token second, before anything changes. A refusal
is a 403.

| Action | Handler | Target check |
| --- | --- | --- |
| `sc_wpun_send_test` | `Channels_Tab::handle_test_send` | The id is sanitised and must be listed; the module must support a test. |
| `sc_wpun_channel_toggle` | `Channels_Tab` | Listed and not defined in code. An outside `enabled` is refused. Works on the stored copy. |
| `sc_wpun_channel_duplicate` | `Channels_Tab` | As toggle. The new id avoids every taken id. |
| `sc_wpun_channel_delete` | `Channels_Tab` | As toggle. The store refuses ids defined in code. |
| `sc_wpun_check_now` | `Check_Now::handle` | No target. |
| `sc_wpun_save_channel` | `Channel_Form::handle_save` | Mode allowlist; registered module (new) or listed, stored, not code-defined (edit). The security token is bound to the mode and type or id. |
| `sc_wpun_dismiss_slack_notice` (GET) | `Slack_Notice` | No target; only sets the user's own flag. |
| `options.php` (`sc_wpun_settings`) | Core, through `option_page_capability_` | Only declared settings are kept. |

Proof:

- `security/test-capability-matrix.php`. It discovers every endpoint, so a new one can't go untested.
- `test-channels-tab.php`, `test-channel-form.php`, `test-check-now.php` and `test-slack-notice.php`.

### Email header injection

| Value | Control |
| --- | --- |
| From address | `sanitize_email` and `is_email` on save, `sanitize_email` again at send, then core's validator in `setFrom`. |
| From name | A translation, which is trusted. PHPMailer's `setFrom` strips CR and LF and encodes the header. |
| Reply-To | `sanitize_email`, then core's `addReplyTo` validation. |
| Subject and prefix | The prefix uses `sanitize_text_field`. On every path PHPMailer's `secureHeader` strips CR and LF from the subject, including subjects from filters and channels defined in code. |
| Recipients | `sanitize_email` for each address. Payload addresses that contain CR or LF are dropped. PHPMailer validates each one. |
| Extra headers | Lines with CR or LF are dropped, and core's `addCustomHeader` refuses them too. |

Proof: `security/test-settings-input.php::test_a_line_break_in_the_email_settings_adds_no_header` and
`test-email-channel.php::test_the_payload_filter_may_only_return_safe_values`.

### Suppressions

| Where | Sniff or rule | Justified |
| --- | --- | --- |
| `includes/class-env-source.php:39` | ValidatedSanitizedInput | Yes. `$_ENV` is trusted configuration, and it is cast and validated like form input. |
| `includes/admin/class-channels-tab.php:230-234` | NonceVerification.Recommended | Yes. It only picks the view, from `sanitize_key` values. |
| `includes/admin/class-channels-tab.php:441` | EscapeOutput | Yes. Every item is escaped where it is built. |
| `includes/admin/class-settings-page.php:355` | NonceVerification.Recommended | Yes. The tab only picks the display and is checked against the visible tabs. |
| `includes/admin/class-settings-page.php:652` | EscapeOutput | Yes. `Field_Renderer` escapes every value, and the type, values and bounds are internal. |
| `includes/admin/class-channel-form.php:160` | EscapeOutput | Yes. Every title is escaped by its only caller, and the controls come from `Field_Renderer`. |
| `includes/admin/class-channel-form.php:728` | EscapeOutput | Yes, on trust. It is a module's own `render_settings()` markup, and add-ons are trusted (threat model §2). |
| `includes/admin/class-channel-form.php:867` | NonceVerification.Missing | Yes. It reads `mode`, `type` and `id` with `sanitize_key`, only to build the security token's action. |
| `includes/admin/class-channel-form.php:879-884, 941, 952, 991, 1014` | NonceVerification.Missing, InputNotSanitized | Yes. These run after the security token is checked, and the values go through the field types or the module's validation. |
| `bin/l10n-to-po.php:141` | EscapeOutput | Yes. A command-line tool that isn't shipped. |
| `bin/coverage-check.php:13` | EscapeOutput (`phpcs:disable`) | Yes, but it has no written reason (CI1 in #330). A command-line tool that isn't shipped. |
| `.wordpress-org/blueprint/mail-catcher.php:102` | NonceVerification.Recommended | Yes, but it has no written reason (CI1 in #330). A read-only `absint` in the Playground demo, behind `manage_options`. |
| `nosemgrep`, `@psalm-suppress Tainted*` | None found | n/a |
| Psalm baseline | 1 entry: `TaintedSSRF` in `examples/` | Yes. A URL the administrator sets, sent with `wp_safe_remote_post`. |
| gitleaks allowlists | 5 rules | Yes. One has no path, against the file's own rule (CI5 in #330). |

## Supply chain and release

| Item | Result | Evidence |
| --- | --- | --- |
| Workflows | Pass | Every action is pinned to a full commit SHA. Permissions default to none or `contents: read`, and each job raises only what it needs. Every checkout sets `persist-credentials: false`. No `pull_request_target` or `workflow_run` triggers. Untrusted values reach `run:` only through `env:`. The release and deploy jobs run in protected environments, and the deploy verifies the attestation against the tag's commit. |
| Release zip | Pass | 60 files, all the plugin's own: the main file, `uninstall.php`, `readme.txt`, `LICENSE`, the stylesheet, `includes/`, `modules/email/` and the `languages/*.l10n.php` files. The build is reproducible (fixed time, modes and order). Provenance and the SBOM are attested and checked before publishing. The tag is signed and checked against `.github/release-signers`. |
| Dependencies | Pass | No runtime dependencies (enforced by `bin/check-dist.sh`). Development dependencies are locked, images are pinned by digest, and the pip requirements carry hashes. Dependabot covers every ecosystem. |
| Hardening | Info | Five items are filed in #330: remove the signing key straight after signing (CI2), pin the apt packages behind the reproducible-build claim (CI3), move the release-please app key into an environment (CI4), tidy a gitleaks allowlist (CI5), and scan the zip in the release job (CI6). |

## OWASP Top 10:2025

| Category | Result | Where it is covered |
| --- | --- | --- |
| A01 Broken Access Control | Pass on a single site; multisite fixed in #328 | V8, V3.5, request handlers; findings M1 to M3 |
| A02 Security Misconfiguration | Pass | V13.4.1, V12.2.2, repository settings |
| A03 Software Supply Chain Failures | Pass | V15.2.1, supply chain and release, #330 |
| A04 Cryptographic Failures | Pass | V11, V12 |
| A05 Injection | Pass | V1, email header injection, Psalm taint, Semgrep, ZAP |
| A06 Insecure Design | Pass | The [threat model](threat-model.md), V2, V15.3.1 |
| A07 Authentication Failures | N/A | Core's (V6, V7) |
| A08 Software or Data Integrity Failures | Pass | The release zip's provenance, signed tags, the WordPress.org deploy gate, translations rebuilt and compared |
| A09 Security Logging and Alerting Failures | Pass | V16 note |
| A10 Mishandling of Exceptional Conditions | Pass, with HN8 in #329 | `Dispatcher` contains exceptions in `send()`, and every security token and capability check fails closed |

## Findings

### Medium (multisite, fixed in #328)

On multisite, the default capability `manage_options` let a site administrator who is not a super admin use the
settings. That gave them three things core does not:

- **M1:** a list of the network's pending core, plugin and theme updates, with installed versions, mailed to any
  address. Core shows that list only to super admins.
- **M2:** channel settings set in `wp-config.php` for another site. Constants apply on every site, so a channel
  created with the same id picks them up.
- **M3:** mail sent through the network's mailer from any From address.

2.0.0 doesn't support multisite (ADR 0008), and the threat model trusted site administrators. #328 changes the
default capability on multisite to `manage_network_options`. Networks that want site administrators to manage the
plugin can grant it back with the `sc_wpun_capability` filter.

### Low and Info

| Item | Severity | Issue |
| --- | --- | --- |
| HN1 `markdown_url()` returns the raw input instead of the cleaned URL | Low | #329 |
| HN2 Settings error messages reach core unescaped | Low | #329 |
| HN3 A secret made only of spaces clears the stored value | Low | #329 |
| HN4 to HN7, HN9, HN10 Autocomplete hint, slug pattern, mailer error text, a POST dismiss, an https-only option, uninstall batching | Info | #329 |
| HN8 Exceptions outside `send()` stop the check (threat model §6) | Info | #329 |
| CI1 to CI6 Suppression checker scope, signing key lifetime, apt pins, release app key, gitleaks allowlist, zip scan in the release job | Info | #330 |
| V15.1.1 No fix times for vulnerable development tools | Info | Fixed in this audit (`SECURITY.md`) |

## Limits

- **This is a source review**, backed by the scripted tools and the tests. No manual penetration test was done, apart
  from the ZAP scan.
- **Core and the host are trusted.** Weaknesses in core's own URL checks, such as DNS rebinding, are out of scope.
- **Third-party Actions are taken as pinned.** Their internals were not reviewed.
- **The rows are tied to `d2f4377`.** A later release repeats this audit on its diff, using the same checklists, the
  threat model and the rubric above.

## References

- #228 (this audit), #229 (security epic), #230 and the [AI review](ai-review-2.0.0.md), #325 (its hardening), #328,
  #329 and #330 (this audit's findings).
- [OWASP ASVS 5.0.0](https://github.com/OWASP/ASVS/tree/v5.0.0). The Level 1 list is the rows of
  `5.0/docs_en/OWASP_Application_Security_Verification_Standard_5.0.0_en.csv` where `L` is `1`.
- [OWASP Top 10:2025](https://github.com/OWASP/Top10/tree/3a31f35346c3f4e90f350395124382fa53af2afe/2025).
- [WordPress Security in the Common APIs Handbook](https://developer.wordpress.org/apis/security/), and
  [the detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
