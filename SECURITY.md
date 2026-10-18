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
