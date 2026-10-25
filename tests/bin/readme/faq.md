# A guide

Text before the first question is not used.

## Links and lists

See [the other page](other.md), [a part of it](other.md#part), [the index](index.md), [a sibling folder](../developers/hooks.md), [here](#links-and-lists) and [outside](https://example.com/a?b=c#d). A code span keeps `[text](x.md)` as it is.

1. **First.** One.
2. **Second.** Two.

- A bullet
* Another bullet

## Code and subheadings

Intro line one
and line two of the same paragraph.

### Request it

```php
define( 'DISABLE_WP_CRON', true );
```

### Fire it

```
*/30 * * * * wp eval "do_action( 'x' );"
## not a heading
```

## Admonitions and tables

!!! note
    The default title is the type.

    A second paragraph.

!!! warning "Careful now"
    A titled one with [a link](other.md).

| How | Core | Plugins |
| --- | --- | --- |
| The `schedule` | 1 minute | 2 hours |
| **Now** | 1 minute | 12 hours |

## Not listed

<div>HTML would fail, but nobody asked for this question.</div>
