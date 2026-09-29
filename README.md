# Footprints

**English** | [日本語](README.ja.md)

A MediaWiki extension that puts a footprint icon and a number next to each
page title. The number is how many **different people** have read the page.
Clicking it shows **who** they were and when they last came by.

## Read this first: this extension records who read what

Footprints stores which user read which page and when, and shows that to
everyone who can read the wiki. There is no opt-out for individual readers;
that is what keeps the count an honest number of unique readers.

It is built for **small, closed wikis** (a club, a team, a class) where
reading already requires an account and everyone knows that reading leaves
a footprint. **Tell your users before you install it.**

The optional notification (below) goes one step further: "someone read your
page" is delivered to the page's author, rather than just being there for
anyone who looks. Turn it off with `$wgFootprintsNotifyEnabled = false` if
that is more than your wiki wants.

## Features

- **Indicator** next to the page title: icon + unique reader count. It is a
  plain link to `Special:Footprints/<page>`, so it works without JavaScript.
- **Dialog** on click, in one of two styles (`$wgFootprintsDialogStyle`):
  - `simple` (default): an OOUI dialog with a table of readers, last read
    time and view count.
  - `rich`: a styled card with "read since the last edit" progress, a 14-day
    view chart, sort tabs (recent / frequent / not read since the last edit),
    and badges (you, page creator, last editor, regular). It follows the
    skin's light/dark theme.
- **Special:Footprints**: without a page, the pages read by the most people;
  with one, everyone who read it.
- **Echo notification** (if Echo is installed): the page creator is told when
  their page reaches 1, 3, 5, 10, 20, 50 and 100 readers.
- **API**: `action=query&list=footprints&fppage=<title>`.

## Installation

1. Put this directory at `extensions/Footprints`.
2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'Footprints' );
   ```
3. Run `php maintenance/run.php update` to create the `footprint` and
   `footprint_log` tables.

Requires MediaWiki 1.45 or later. Tested on 1.46 with MariaDB.
Only MySQL/MariaDB schema files are included.

## How views are counted

- Recorded from `BeforePageDisplay`, in a `POSTSEND` deferred update, so the
  reader never waits on the write.
- Only when the page content itself is shown (`OutputPage::isArticle()`).
  History, diffs, edit previews, special pages and the API leave no footprint.
- Only in `$wgFootprintsNamespaces` (default: main namespace).
- Anonymous users, temporary accounts and `$wgFootprintsExcludeGroups`
  (default: `bot`) are never recorded.
- A view less than `$wgFootprintsCooldown` seconds (default 300) after the
  same person's previous view of the page does not add to the view count, so
  reloads and a page kept open and refreshed count once. The reader count is not
  affected by this at all: `footprint` has one row per (page, user).
- `footprint_log` keeps one row per counted view, for the rich dialog's chart.
- Deleting a page deletes its footprints.
- Nothing is recorded from before the extension was installed.

## Notifications

A notification is sent only when all of these hold:

1. A reader opens the page **for the first time** (never on a reload or a
   return visit).
2. The number of readers **other than the page creator** lands exactly on one
   of `$wgFootprintsNotifyMilestones`.
3. The reader is not the creator.

The creator is left out of the count because saving a page redirects to it,
so the creator is almost always reader number one. Counting them, "someone
read your page for the first time" would never fire.

Milestones rather than every reader: on a small wiki a new page collects its
whole audience in a week or two, and a notification per reader becomes noise.

Web notifications are on by default, e-mail is off; users can change both in
their preferences.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `$wgFootprintsNamespaces` | `[ NS_MAIN ]` | Namespaces where footprints are recorded and shown |
| `$wgFootprintsExcludeGroups` | `[ 'bot' ]` | Groups whose members leave no footprints |
| `$wgFootprintsCooldown` | `300` | Seconds within which a repeat view is not counted again (`0` counts every view) |
| `$wgFootprintsListLimit` | `200` | Maximum rows on Special:Footprints and in the API |
| `$wgFootprintsDialogStyle` | `'simple'` | `'simple'` or `'rich'` |
| `$wgFootprintsDialogFontFamily` | `null` | Rich dialog only: CSS `font-family` for its text (`null`: the skin's font) |
| `$wgFootprintsDialogNumberFontFamily` | `null` | Rich dialog only: CSS `font-family` for counts and dates (`null`: same as the text) |
| `$wgFootprintsDialogFontStylesheets` | `[]` | Rich dialog only: stylesheet URLs to load when it first opens |
| `$wgFootprintsRegularViews` | `15` | Views needed for the "regular" badge in the rich dialog |
| `$wgFootprintsNotifyEnabled` | `true` | Send milestone notifications (needs Echo) |
| `$wgFootprintsNotifyMilestones` | `[ 1, 3, 5, 10, 20, 50, 100 ]` | Reader counts (excluding the creator) that trigger a notification |

Web fonts for the rich dialog, for example from Google Fonts:

```php
$wgFootprintsDialogStyle = 'rich';
$wgFootprintsDialogFontFamily = "'Zen Kaku Gothic New', sans-serif";
$wgFootprintsDialogNumberFontFamily = "'M PLUS 1 Code', monospace";
$wgFootprintsDialogFontStylesheets = [
	'https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@500;700&display=swap',
	'https://fonts.googleapis.com/css2?family=M+PLUS+1+Code:wght@400;500&display=swap',
];
```

Nothing is loaded from outside the wiki unless you list it here; loading from
Google Fonts sends each reader who opens the dialog to Google. Use one URL per
family if your site is behind Cloudflare with "Rewrite to Cloudflare Fonts" on:
that rewrite keeps only the first family of a multi-family URL.

Special:Footprints and the API are open to anyone with the `read` right. To
limit them to some group, add a check in `SpecialFootprints::execute()` and
`ApiQueryFootprints::execute()`.

## Extending

- Hook `FootprintsViewCounted( Title $title, UserIdentity $reader, bool $isNewReader )`:
  runs after a view has been counted (post-send).
- Hook `FootprintsIndicator( OutputPage $out, array &$before )`: HTML added to
  `$before` is placed inside the indicator, left of the footprint link.
- JS hook `ext.footprints.dialog.footer` (rich dialog): receives the footer
  element; prepend a link with class `ext-footprints-footer-link`.

## License

GPL-2.0-or-later. See `COPYING`.
