# AI-assisted security review for 2.0.0

A bounded, read-only source review of the 2.0.0 release candidate, done with the
[WordPress Security Skills](https://github.com/wpultimatesecurity/WordPress-Security-Skills) pack (#230). It is advisory:
it is not a certification, it gives no release sign-off and it never gates CI. The scripted tools in the security epic
and the manual audit sign-off (#228) remain the release gates, and #228 reads this report as one of its inputs.

**Result: no confirmed findings and nothing that needs validation.** 33 candidates were rejected, each with the control
that stops it. Five hardening recommendations were triaged by hand and all five are tracked in #325.

To compare a later run with this one, use the same skills pack commit and the [threat model](threat-model.md), and run
the `quick` profile on the diff since `1002e36` before each later release.

## Triage

Done by hand by the maintainer on 2026-10-26.

| Item | Verdict | Decision |
| --- | --- | --- |
| Confirmed findings | none | Nothing to file, so no new regression is needed. |
| Needs validation | none | Nothing to run on a local site. |
| [H1](#hardening-recommendations) Escape the General tab's Settings API titles | hardening | Fix in #325. |
| [H2](#hardening-recommendations) Read files only for installed plugins and themes | hardening | Fix in #325. |
| [H3](#hardening-recommendations) Treat external values as secret for custom field types | hardening | Fix in #325. |
| [H4](#hardening-recommendations) Redact `Channel_Instance` when serialized | hardening | Fix in #325. |
| [H5](#hardening-recommendations) Apply the http(s)-only link rule to plain text | hardening | Fix in #325. |

The maintainer's session checked the report's claims about the earlier fixes (#231, #232, #233, #235) against the code
at `1002e36`, and they hold. Each of those fixes already has a deterministic regression in `tests/wp/`: `d708b81`
(#231), `7562bab` (#232) and `88e5b27` (#233) added them.

## Earlier run

The first full run, on 2026-10-18, reviewed `main` at `1f65557` with the same agent, skills pack and `standard` profile.
It found no confirmed or needs-validation items and rejected 24 candidates. Its hardening was raised and fixed as #231
(Markdown link schemes and control characters), #232 (resolved secrets in debug output, edit forms and custom field
types), #233 (settings page capability, capability docs, autoloader, `SECURITY.md`) and #234 (local Docker ports on
loopback only). It also found a correctness bug, #235, that was not a security issue. Its full report was not kept, so
this run derived every candidate afresh. This run is the one done on the release candidate, as #230 asked, after #57,
#86, #87, #88 and #286.

## Review context

| Field | Value |
| --- | --- |
| Target | WP Updates Notifier (`l3rady/wp-updates-notifier`), the 2.0.0 release candidate |
| Revision | `1002e3619f1520c3dd3b9a8d6c6ac92e326691a3` on `main`, with a clean working tree. The plugin header and `SC_WPUN_VERSION` still say `1.6.0` (`wp-updates-notifier.php:17`, `:59`) because release-please bumps them at release, so the revision is identified by the commit, not the version. |
| Reviewer | The read-only `wordpress-security-reviewer` agent from the maintainer's dotfiles, on Claude Opus 5.5 (`claude-opus-5-5`), launched by the maintainer's Claude Code session |
| Review date | 2026-10-26 |
| Profile | `standard`, full review mode |
| In scope | `includes/` (43 files), `modules/` (2 files), `uninstall.php` and `wp-updates-notifier.php`: 13,784 lines |
| Excluded | `vendor/`, `tests/`, `docker/`, `bin/`, `build/`, `docs/`, `examples/` (including the example HTTP add-on), the shipped `languages/*.l10n.php` translation files, and multisite network-level behaviour. Multisite observations are noted below but were not audited. |
| Platform assumptions | WordPress 6.7 or newer and PHP 7.4 or newer (plugin header). Single sites only, per [ADR 0008](../decisions/0008-single-sites-only-in-2-0-0.md). |
| Trust model | The [threat model](threat-model.md) at this commit. Trusted: WordPress core, administrators with the settings capability (`manage_options`, or whatever the `sc_wpun_capability` filter returns), and code in the same PHP process (add-ons, the theme, `wp-config.php`, filter callbacks). Untrusted: every other role, visitors, request parameters, third-party plugin and theme headers, api.wordpress.org data and responses from services. |
| Skills pack | Commit `a0ba3ab3d524f033e2c985a771f22577b6f4bda8`. The maintainer's session checked that the archive at that commit has the pinned sha256 `d9df4b04ab0328454bbea2f41e546b7c66a27ab106226047045c6f085afc60ab`. |
| Skills loaded | `security-auditing-code-review` (`SKILL.md`, `full-audit-workflow.md`, `report-template.md`, `audit-checklist.md`, `attack-classes.md`, `resource-exhaustion.md`, `severity-anchors.md`), plus the checklists of `nonces-csrf-protection`, `capability-permission-checks`, `output-escaping`, `settings-options-security`, `cron-background-job-security`, `secrets-credentials-management` and `filesystem-security`. Skills for features the plugin doesn't use (WooCommerce, blocks, REST, AJAX, shortcodes, uploads, WP-CLI, LLM integration, headers and CSP) were not loaded. |
| Methods | Manual source review: every in-scope file was read, and each candidate was re-read from its entry point before a verdict. The pack's heuristic sink inventory (`scan-security-sinks.sh`) was run. Targeted searches covered entry points, sinks, dynamic execution, magic methods, outbound HTTP, SQL, the filesystem, redirects, and "obvious things" such as TODOs, keys and credentials. |
| Active testing | None. No PHP, test suite, Docker, WP-CLI or HTTP request was run against any site. |
| Limitations | Static proof only. WordPress core source was not available to the agent, so core behaviour is cited from its documented API, and a plugin-side control is cited as the governing one wherever it matters. |

## Confirmed findings

None.

## Needs validation

None.

## Rejected candidates

Each candidate is disproved by the control cited. Line numbers refer to commit `1002e36`. Candidates with the same root
cause are merged.

| # | Candidate claim | Governing control (file:line) |
| --- | --- | --- |
| R1 | A visitor who is not logged in triggers a plugin action through `admin-post.php`. | Only `admin_post_*` hooks are registered (`includes/class-plugin.php:506`, `:542-547`). No `admin_post_nopriv_*`, `wp_ajax_*` or REST route exists in scope, and core fires only `admin_post_nopriv_*` for logged-out requests. |
| R2 | A logged-in user without the settings capability (a subscriber or editor) sends a test, or toggles, duplicates or deletes a channel. | `Channels_Tab::authorise()` requires `current_user_can( Settings_Page::capability() )`, then a security token for `<action>_<id>` (`includes/admin/class-channels-tab.php:588-599`). A refusal returns 403 before any change (`:830-833`). |
| R3 | A user without the capability adds or edits a channel through `sc_wpun_save_channel`. | The capability, then a security token bound to the mode and the type or id (`includes/admin/class-channel-form.php:894-906`). A refusal returns 403 (`:1105-1109`). |
| R4 | A user without the capability runs "Check now" and makes the site send mail. | The capability and a security token are checked before anything runs (`includes/admin/class-check-now.php:116-122`, `:129-131`). A refusal returns 403 (`:210-213`). Only `$_POST` is read, so a GET can't pass. |
| R5 | A cross-site GET on the Slack notice's "Dismiss" link changes state. | The capability and a security token (`includes/class-slack-notice.php:199-205`). The only effect is a meta flag on the current user (`:207`). |
| R6 | A lower role opens the settings page by URL. | The menu capability (`includes/admin/class-settings-page.php:204`), plus a fresh check with a 403 at render (`:376-380`). |
| R7 | A lower role saves the General settings through `options.php`. | Core's `options.php` checks `option_page_capability_sc_wpun_settings`, which is filtered to the same capability (`includes/class-plugin.php:541`), and the settings group's security token printed by `settings_fields()` (`includes/admin/class-settings-page.php:456`). |
| R8 | An administrator's security token for one channel or form is replayed against another channel, mode or type. | Token actions name the target: `<action>_<id>` (`includes/admin/class-channels-tab.php:462`, `:594`) and `sc_wpun_save_channel_<mode>_<type\|id>` (`includes/admin/class-channel-form.php:372`, `:904`). |
| R9 | A capability filter returns junk (not a string, empty, or a meta capability) and opens the screens. | `Settings_Page::capability()` falls back to `manage_options` for anything but a non-empty string (`includes/admin/class-settings-page.php:160-162`). A meta capability checked without an object fails closed in core's `map_meta_cap`. |
| R10 | Reflected XSS through the `tab`, `action`, `id` or `type` query parameters. | `sanitize_key( wp_unslash() )` (`includes/admin/class-settings-page.php:355`; `includes/admin/class-channels-tab.php:231-233`), allowlists (`class-settings-page.php:357`, `class-channels-tab.php:236`), and `esc_attr` or `esc_url` on every output (for example `class-channels-tab.php:359`, `:405`; `class-channel-form.php:371`). |
| R11 | Stored XSS through a channel label, including the admin `<title>` built by the `admin_title` filter. | Labels use the text field type, `sanitize_text_field` (`includes/class-field-types.php:310-316`, applied at `includes/admin/class-channel-form.php:943`), and are escaped at every HTML output (`class-channels-tab.php:323`, `:361`, `:480-484`; `class-notices.php:79`). The `<title>` path (`class-settings-page.php:229-259`) receives text without tags. |
| R12 | Stored XSS through a channel's last delivery result or a failure message from a remote service. | `esc_html` at `includes/admin/class-channels-tab.php:379` (the list) and `includes/admin/class-notices.php:79` (notices, including the Check now summaries built at `class-check-now.php:168-203`). |
| R13 | Stored XSS through field values, error messages or the form values kept after a failed save. | Every built-in renderer escapes for its context (`includes/admin/class-field-renderer.php:66-93`, `:140`, `:219`, `:240-241`, `:268-280`, `:306`, `:372-396`). The error summary uses `esc_html` (`includes/admin/class-channel-form.php:461-463`). Kept values go back through the same renderers (`:345-358`). |
| R14 | XSS in HTML email or admin output through third-party plugin and theme headers or api.wordpress.org data (names, versions, URLs). | `esc_html` and `esc_url` on every value in `Notification_Renderer::render_html()` (`includes/class-notification-renderer.php:313-339`). The update nag and Site Health print no item data. The plugins screen row escapes the third-party API header (`includes/admin/class-plugin-requirements.php:83-91`). |
| R15 | A `javascript:`, `data:` or `file:` link reaches a Markdown notification (#231). | `markdown_url()` allows only explicit `http(s)` schemes that also pass `esc_url_raw`, and encodes spaces, parentheses and angle brackets (`includes/class-notification-renderer.php:390-399`). Text is Markdown-escaped (`:408-410`). |
| R16 | Control characters or new lines in item values inject extra lines or headers into messages (#231). | `Notification_Item::single_line()` collapses `\x00-\x1F\x7F` in the name, versions, notes and link labels (`includes/class-notification-item.php:112-114`, `:119`, `:126`, `:145-147`), and control characters are stripped from link URLs (`:119`). The `key` is used only as a state array key and is never rendered. |
| R17 | Mail header injection through the From or Reply-To settings, a translated From name, or a payload filter. | The settings are validated with `sanitize_email` and `is_email` (`modules/email/class-email-channel.php:163-172`) and cleaned again before use (`:257-264`). After the filters, any header line containing CR or LF is dropped (`:337-342`). |
| R18 | Recipient injection through the payload filters. | Recipients that contain CR or LF, or aren't strings, are dropped, falling back to the validated list (`modules/email/class-email-channel.php:328-333`, `:348`). Stored recipients pass `email_list` and channel validation (`includes/class-field-types.php:391-414`; `class-email-channel.php:145-160`). |
| R19 | A stored secret is printed on the channel form. | A secret's renderer is told only whether a value is saved (`includes/admin/class-field-renderer.php:122-125`), and the password input always has `value=""` (`:268`). Text, URL, email-list and textarea inputs route secrets there (`:236-238`, `:302-304`). |
| R20 | A secret from `wp-config.php` or the environment is shown on the edit form through a text field not marked secret (#232). | `Channel_Form::control()` blanks external values of text-like fields unless the schema sets `show_external` (`includes/admin/class-channel-form.php:569-572`). |
| R21 | Saving, toggling or duplicating a channel writes an external (constant or environment) secret into the database. | `Channel_Store::save()` restores the previous stored value, or removes the key, for every externally supplied field (`includes/class-channel-store.php:221-229`). Toggle and duplicate start from the stored instance, not the effective one (`includes/admin/class-channels-tab.php:672`, `:709`). |
| R22 | Secrets persist in user meta when the form keeps values after a failed save. | `without_secrets()` removes secret fields and `__clear` flags before `remember()` (`includes/admin/class-channel-form.php:993`, `:1066-1075`). The kept values are scoped to the user, the site and five minutes (`:780-791`). |
| R23 | Secrets leak through `var_dump`, `print_r`, exception messages or debug logs (#232). | `Channel_Instance::__debugInfo()` redacts settings values (`includes/class-channel-instance.php:104-113`). `Config_Value` exposes only the key and source in debug output and serialization (`includes/class-config-value.php:98-112`). The Dispatcher keeps and logs only the exception class (`includes/class-dispatcher.php:209-237`, `:346-353`), and so does a failing config source (`includes/class-config.php:74-79`). |
| R24 | Configuration problems shown in the admin notice or Site Health disclose the supplied value. | Reports carry only the key, source label and a fixed message (`includes/class-config-report.php:84`, `:117`). A JSON error reports only `json_last_error_msg()` (`includes/class-channel-store.php:563-567`). |
| R25 | An administrator picks a channel id that makes the plugin read an arbitrary constant or environment variable (a database password, say) and use or show it. | Keys always start `SC_WPUN_SETTING_` or `SC_WPUN_CHANNEL_<ID>__<FIELD>` (`includes/class-config-context.php:167-173`). Ids are limited to `[a-z0-9_]` (`includes/class-channel-instance.php:84-86`). Environment variables are read only after an explicit opt-in (`includes/class-config.php:306-320`, `:346`). |
| R26 | An open redirect after a handler. | Every redirect is `wp_safe_redirect()` to a fixed admin URL built from `admin_url()` and `sanitize_key`-ed values (`includes/admin/class-channels-tab.php:835`; `includes/admin/class-channel-form.php:998`, `:1028`, `:1111`; `includes/admin/class-check-now.php:215`; `includes/class-slack-notice.php:222`). |
| R27 | Duplicating or adding a channel overwrites an existing, code-defined or unavailable channel. | New ids skip every stored and code-defined id (`includes/admin/class-channels-tab.php:715-721`; `includes/admin/class-channel-form.php:811-813`, `:840-857`). |
| R28 | A code-defined channel, or one whose module is unavailable, is changed through the handlers or the form. | `changeable()` refuses ids that are unlisted or code-defined (`includes/admin/class-channels-tab.php:772-785`), and the form makes the same checks (`includes/admin/class-channel-form.php:917-932`). The store refuses to save or delete code-defined ids (`includes/class-channel-store.php:209-214`, `:278-282`). |
| R29 | Local file inclusion through a crafted class name reaching the autoloader (#233). | Only the `WPUN\` prefix is accepted, and each namespace part must match `^[A-Za-z0-9_]+$` (`includes/class-autoloader.php:59-70`). Paths are built under `includes/` only (`:74-86`). |
| R30 | Path traversal through the locale in the translation loader. | `lang_dir()` returns only the plugin's fixed `languages/` directory, never a path containing the locale (`includes/class-translations.php:70-77`). `load_pack_first()` acts only on core's own `.mo` path for this text domain under that directory (`:90-102`). |
| R31 | Path traversal through `update_plugins` or `update_themes` transient keys (api.wordpress.org data is untrusted) reaching `get_plugin_data()` and `wp_get_theme()` (`includes/class-update-checker.php:656`, `:716`). | Rejected on impact. These calls only read named header fields from a file, and the result goes only into notifications for recipients an administrator chose, so whoever controls the response gets nothing back. No file is written, included or returned to the requester. See H2. |
| R32 | ReDoS or markup injection through a third-party plugin's `Requires WPUN API` header. | The parser uses linear patterns with no nested quantifiers (`includes/class-version-constraint.php:135`, `:166-221`). Output uses `esc_html` and `esc_attr` (`includes/admin/class-plugin-requirements.php:83-91`). |
| R33 | Visitors exhaust resources or flood api.wordpress.org through WP-Cron or `init`. | `init` only checks the schedule (`includes/class-scheduler.php:102-113`). Frequencies under one hour are never offered (`includes/class-frequencies.php:54`). Visitors have no on-demand path: `sc_wpun_run_check` can be reached only from trusted code, and the deactivation hook clears the event (`includes/class-scheduler.php:121-123`). |

## Hardening recommendations

None of these is a demonstrated vulnerability. Each is defence in depth, and all five are tracked in #325.

**H1. Escape the translated Settings API titles on the General tab.** `includes/admin/class-settings-page.php:491` passes
a translated section title, and `:503` a field label, to `add_settings_section()` and `add_settings_field()`. Core's
`do_settings_sections()` and `do_settings_fields()` print titles as given, and translations, language packs included,
are a supply-chain input. The channel form already escapes its row titles (`class-channel-form.php:621`, `:662`, `:751`).

```php
add_settings_section( 'sc_wpun_settings_main', esc_html__( 'Settings', 'wp-updates-notifier' ), null, 'wp-updates-notifier' );
// ...
$title = esc_html( isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name )
	. ( Field_Renderer::is_required( $schema ) ? Field_Renderer::required_marker() : '' );
```

**H2. Read files only for installed plugins and themes.** `includes/class-update-checker.php:656` builds
`WP_PLUGIN_DIR . '/' . $key` from transient keys, which come from api.wordpress.org data or an add-on filter, and `:716`
passes theme keys to `wp_get_theme()`. Before the loops, keep only installed plugins with
`array_intersect_key( $plugins_need_update, get_plugins() )`, and skip themes where `! wp_get_theme( $key )->exists()`.
Foreign or traversal keys then never reach a file read, and stray filter entries can't produce empty items.

**H3. Treat external values as secret by default for custom field types.** `includes/admin/class-channel-form.php:569`
blanks external values only for the built-in text-like types. A custom type registered through `sc_wpun_field_types`
that receives a constant or environment value, without `secret => true` or `show_external`, is passed the resolved value
by its add-on renderer. The add-on is trusted and the behaviour is documented (#232), but the safe default depends on
the add-on author. Blank external values for every type outside the built-in set unless `show_external` is true.

**H4. Redact `Channel_Instance` when it is serialized.** `Config_Value` redacts in `__serialize()`
(`includes/class-config-value.php:110-112`), but `Channel_Instance` redacts only in `__debugInfo()`
(`includes/class-channel-instance.php:104-113`). Instances reach public hooks (`sc_wpun_channel_deleted`,
`sc_wpun_notification_sent`, `sc_wpun_notification_failed`), where a logging plugin that serializes hook arguments would
capture resolved secrets. A `__serialize()` that returns the same redacted array closes that path. `var_export()`, array
casts and Reflection still bypass it.

**H5. Apply the http(s)-only link rule to plain text.** `includes/class-notification-renderer.php:217-219` writes link
URLs into plain-text messages with only control characters removed. Markdown already allows only `http(s)`, and HTML
uses `esc_url`. Skip links that `markdown_url()` rejects, so a third-party update server's `javascript:` or `data:` URL
never reaches a channel as text that a client might turn into a link.

## Status of earlier hardening fixes

| Issue | What it fixed | Status at `1002e36` | Evidence |
| --- | --- | --- | --- |
| #231 | Markdown link schemes, and control characters in notification items | Holds | The `markdown_url()` scheme allowlist and encoding (`includes/class-notification-renderer.php:390-399`). `single_line()` on the name, versions, notes and link labels, and control characters stripped from link URLs (`includes/class-notification-item.php:112-126`, `:145-147`). Introduced in `d708b81`. |
| #232 | Resolved channel secrets in debug output, edit forms and custom field types | Holds | `Channel_Instance::__debugInfo()` (`includes/class-channel-instance.php:104-113`). External text-like values blanked on the form (`includes/admin/class-channel-form.php:569-572`). `secret => true` honoured on any type (`includes/class-field-types.php:190-194`), and the renderer told only whether a value is saved (`includes/admin/class-field-renderer.php:122-125`). Dispatcher logs carry only the class (`includes/class-dispatcher.php:346-353`). Introduced in `7562bab` and refined in `4cca643`. The remaining trade-off is H3. |
| #233 | Settings page capability check, capability docs, autoloader hardening, `SECURITY.md` | Holds | The render-time check with a 403 (`includes/admin/class-settings-page.php:376-380`). The capability fallback (`:160-162`), used by every handler and by `options.php` (`includes/class-plugin.php:541`). The autoloader's identifier allowlist (`includes/class-autoloader.php:59-70`). `SECURITY.md:26-28` and the threat model's Scope section document `manage_options` and `sc_wpun_capability`. Introduced in `88e5b27`. |
| #235 | The auto-update email used a disabled email instance | Holds (superseded) | Fixed in `63771ca`, then the feature was removed in `21ed806`: the plugin no longer redirects core's auto-update emails. `SC_WP_Updates_Notifier::filter_auto_core_update_email()` is a deprecated pass-through (`wp-updates-notifier.php:281-285`), and nothing in scope hooks `auto_core_update_email`. The remaining senders use enabled email instances only (`includes/class-notifier.php:51-55`, `includes/class-slack-notice.php:92-95`). The Slack notice falls back to `admin_email` when no email channel is enabled (`:143-146`), on purpose, so the site owner learns that Slack is no longer supported. |

## Multisite observations (not audited)

2.0.0 supports single sites only ([ADR 0008](../decisions/0008-single-sites-only-in-2-0-0.md)). These points are kept
for the 3.0.0 network work (#85) and were not reviewed in depth.

- **Network constants apply to every site.** `Constant_Source` and `Env_Source` ignore the site id in the context
  (`includes/class-constant-source.php:33-35`, `includes/class-env-source.php:33-41`). A site administrator who creates
  a channel whose id matches network-wide `SC_WPUN_CHANNEL_<ID>__*` constants picks up those values. Secrets are used
  but not shown, and `show_external` fields (`to`, `from`, `reply_to`, `subject_prefix`) are shown read-only to that
  site's administrators.
- **Test sends reach recipients defined for the network.** "Send test" is offered on code-defined channels
  (`includes/admin/class-channels-tab.php:407`). On a network, a site administrator can send fixed-content test mail to
  recipients defined at network level, and can point their own channels at any address through the network's mailer.
- **Site administrators without `unfiltered_html` reach the forms.** Every stored value is sanitised and escaped on
  output, and no raw-HTML write was found.
- **Per-site scoping is already in place.** Notices and the values kept after a failed save are scoped per site
  (`includes/admin/class-notices.php:54`, `includes/admin/class-channel-form.php:783`).
- **Uninstall on large networks.** `uninstall.php:60-69` goes through every site with `number => 0`, which may be slow
  on large networks.

## Coverage

Profile `standard`, a full review of the agreed scope. No budget limit was set, and every unit was reviewed in this run.

| Unit (entry point × trust boundary × attack class) | State |
| --- | --- |
| `admin-post:sc_wpun_send_test` × visitor or lower role → channels × access control, CSRF | covered |
| `admin-post:sc_wpun_channel_toggle` × visitor or lower role → channels × access control, CSRF | covered |
| `admin-post:sc_wpun_channel_duplicate` × visitor or lower role → channels × access control, CSRF, business logic (id collision, secret copy) | covered |
| `admin-post:sc_wpun_channel_delete` × visitor or lower role → channels × access control, CSRF | covered |
| `admin-post:sc_wpun_check_now` × visitor or lower role → mail and outbound × access control, CSRF, resource use | covered |
| `admin-post:sc_wpun_save_channel` × visitor or lower role → channels × access control, CSRF | covered |
| `admin-post:sc_wpun_save_channel` × administrator input → store × validation, secret persistence, second-order output | covered |
| `admin-post:sc_wpun_dismiss_slack_notice` (GET) × lower role → user meta × access control, CSRF | covered |
| `options.php` `sc_wpun_settings` × lower role → settings × access control, CSRF, validation | covered |
| Settings screen (General, Channels, About, add-on tabs) × lower role → page × access control | covered |
| Settings screen × request parameters → markup × reflected XSS | covered |
| Settings screen and notices × stored data → markup × stored XSS (labels, results, config problems, rejected modules) | covered |
| Channel edit form × stored and external secrets → markup × disclosure | covered |
| Update data (headers, transients) → HTML, plain-text and Markdown renderers × injection | covered |
| Update data → `get_plugin_data()` and `wp_get_theme()` × path traversal | candidate, rejected (R31) |
| Email channel × settings and payload filters → `wp_mail` × header and recipient injection | covered |
| Config sources (constants, environment, filters, `SC_WPUN_CHANNELS` JSON) × secret leak, arbitrary key read, malformed values | covered |
| Site Health tests × disclosure, XSS | covered |
| Plugins screen row (`Plugin_Requirements`) × third-party header → markup | covered |
| Removal of the update nag × access control | covered |
| Cron `sc_wpun_update_check` and the Slack notice mail × visitor trigger, resource use, `current_user_can` in cron | covered |
| Actions for trusted code (`sc_wpun_run_check`, `sc_wpun_enable_cron`, `sc_wpun_disable_cron`) × reachable from a request | covered (no request path) |
| Legacy facade `SC_WP_Updates_Notifier` × reachable from a request | covered (no request hooks) |
| Autoloader × file inclusion | covered |
| Translation loader × path traversal | covered |
| `uninstall.php` and direct file access × running outside WordPress | covered (`uninstall.php:33-35`; every in-scope PHP file has an `ABSPATH` guard) |
| Public add-on API (`Field_Types`, `Channel`, `Dispatcher` isolation, API version gate) × safe when used as documented | covered |
| Outbound HTTP and SSRF | covered: no sink in scope (no `wp_remote_*`, cURL, `file_get_contents` or sockets) |
| SQL injection | covered: no `$wpdb` or SQL in scope |
| Deserialization, code execution, file writes and deletes | covered: no `unserialize`, `eval`, `extract`, file writes or deletes in scope. `call_user_func` targets only schema and filter callbacks from trusted code. |
| "Obvious things" (hard-coded credentials, TODOs on auth, debug toggles, committed artefacts) | covered: none found |
| JavaScript | covered: none ships (`assets/` holds only `css/admin.css`) |
| Multisite network-level behaviour | out of scope (observations above) |
| `languages/*.l10n.php` (PHP that core includes at runtime) | out of scope |
| `vendor/`, `tests/`, `docker/`, `bin/`, `build/`, `docs/`, `examples/` | out of scope |

In total: 32 units covered, 1 candidate resolved as rejected, none blocked or deferred, and 3 out of scope. All 30
in-scope files that changed between `1f65557` and `1002e36` were read in full, including the new `class-about-tab.php`
and `class-translations.php`.

After the hunt, a coverage pass over the hook registrations looked for entry points or roles without a unit:
`extra_plugin_headers`, `plugin_action_links_*`, `plugin_row_meta`, `after_plugin_row`, `switch_blog`,
`load_textdomain`, `lang_dir_for_domain`, the `sc_wpun_channel_deleted` and `sc_wpun_notification_*` listeners, and
`site_status_tests`. It also went through the cron, secrets and filesystem checklists and the attack classes (access
control, business logic, feature abuse, second-order, resource exhaustion, wildcard, obvious things). It found no
material gap.

## Residual limitations

- **Static review only.** Core behaviour, such as the capability handling in `options.php`, Settings API title output
  and the escaping of the admin title, is cited from its documented contract. Where it mattered, a plugin-side control
  is cited as the governing one.
- **Not reviewed in this run:** multisite network behaviour, the shipped translation PHP files, the example add-on, the
  build and release pipeline, and dependencies. The threat model covers them separately.
- **Add-on and same-process code is trusted** by the threat model. Custom renderers, field types, config sources and
  filter callbacks were reviewed only for what the plugin guarantees when its API is used as documented.
- An empty confirmed set means no vulnerability was confirmed in the recorded scope. It does not mean the plugin is
  secure.

## References

- [Threat model](threat-model.md), `SECURITY.md` and [ADR 0008](../decisions/0008-single-sites-only-in-2-0-0.md).
- [Plugin security](https://developer.wordpress.org/plugins/security/) in the Plugin Handbook.
- [Escaping data](https://developer.wordpress.org/apis/security/escaping/) and
  [data validation](https://developer.wordpress.org/apis/security/data-validation/).
- [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/).
