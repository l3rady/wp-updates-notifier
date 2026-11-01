# 0026. Field groups and conditional fields, without JavaScript

Status: Accepted. Date: 2026-11-01.

## Context

The channel form drew a module's fields as one flat list. The email channel already has five fields, the generic webhook will have more (address, headers, signing, timeout, format), and a third-party module with twenty settings got a form that was hard to read. Its only way out was to draw the form itself with `render_settings()` and lose the generic form's validation, accessibility and error handling. Some fields also only matter for one value of another field, such as an app token that is used only when the mode is "App" (#347).

## Decision

- **Groups are declared by the module.** `Channel::get_field_groups()` returns `id => array( 'label', 'description', 'collapsed' )`, and a field joins one with its `group` key. The `sc_wpun_channel_field_groups` filter changes the groups, and `sc_wpun_channel_fields` can already move a field into one. Fields without a group come first, then each group in the declared order. A group with no fields is not drawn.
- **One level, drawn as a fieldset.** Each group is a `<fieldset>` with a `<legend>` and its own form table. A collapsed group sits in `<details>`/`<summary>`, with the legend kept for screen readers only, and is open when one of its fields has an error after a failed save, so the links of the error summary land on a visible field.
- **Conditions are a map of values.** `'applies_when' => array( 'mode' => 'app' )` names other fields and the value, or list of values, that make the field apply: every entry must match, and any value of a list matches. The value compared is the submitted one, else the stored one, else the default, cleaned by the type of that field, so `'1'` and `true` are the same and an enum compares its key.
- **The field is always shown.** A note, "Used only when Mode is App.", is tied to the control with `aria-describedby`, like the "Set by" note. It uses the label of the other field and the label of the option.
- **Only `required` depends on the condition.** A field that does not apply is not required, but a value that is there is still cleaned and validated, and it is kept, so switching back restores it. Secrets are never shown, as before.
- **Mistakes are reported, not fatal.** An unknown group draws the field without a group, and an unknown field in `applies_when` is ignored so the field always applies. Both raise `_doing_it_wrong` once.
- **The plugin uses it.** The email channel has "Recipients" (the addresses to send to) and "Message" (from and Reply-To addresses, format and subject prefix) groups.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle (as in ADRs 0022 to 0025).

## Consequences

- Good: a module with many settings gets a readable form without drawing it, and keeps validation, error links and the accessible markup.
- Good: everything works without JavaScript, as ADR 0011 requires.
- Good: the config report, Site Health and `wp updates-notifier channels` are unchanged, as they list fields, not groups.
- Bad: every field is shown, including those that don't apply. The note says when a field is used, but a long form stays long.
- Bad: a module that overrides `validate_settings()` without calling the parent, such as the email channel, has to honour `applies_when` itself.

## Considered options

- **A small script that hides the fields that don't apply.** Rejected for 3.0.0: the form must work without it, so it adds code and testing for a visual gain only. To be revisited if forms get long.
- **Nested groups.** Rejected: one level is enough and keeps the heading order valid.
- **Conditions as callables.** Rejected: they can't be described in the note or listed in the config report. A value map covers enums, booleans and text.

## References

- [Issue #347](https://github.com/l3rady/wp-updates-notifier/issues/347): field groups and conditional fields in the channel form schema.
- [ADR 0011](0011-accessibility-wcag-2-2-aa-without-javascript.md): accessibility, WCAG 2.2 AA without JavaScript.
