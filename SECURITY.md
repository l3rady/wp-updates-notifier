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

WP Updates Notifier stores webhook URLs and email addresses, adds a settings
screen for administrators and sends what it finds to email and Slack. Problems
we would like to hear about include ways for users without the right
capability to change its settings or trigger sends, unescaped output, leaking
webhook URLs or other secrets, and ways to make it send requests to addresses
an administrator did not configure.
