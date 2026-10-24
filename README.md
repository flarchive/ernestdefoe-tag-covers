# Tag Covers

Give every Flarum tag a cover image and a logo. Upload them in the tag's own
edit dialog, and they show up anywhere a tag is drawn as a card.

![The tags page with a cover image across the top of each tag tile, and a conference logo on a tile without a cover](screenshots/tags.png)

- **A cover image per tag.** Landscape works best, around 1200×480. It runs as a band across the top of the tag's tile on `/tags`.
- **A logo per tag.** A crest or mark, square works best and a transparent PNG sits on any colour. It replaces the icon on the tag's tile and appears inside the tag's label wherever one is shown: a discussion's header, a list row, a search result.
- **Picked where you edit the tag.** Admin → Tags → click a tag → **Cover image** or **Logo**. A brand-new tag can have its images chosen in the same dialog that creates it; they upload as soon as the tag is saved.
- **Small files.** PNG, JPEG, WebP or GIF up to 8MB, re-encoded to WebP (covers to at most 1200px wide, logos to 256px), so a 2MB upload from an image generator lands as roughly 100KB.
- **Stock tiles stay stock.** A tag with neither image keeps Flarum's own tile, untouched.

## Settings

There are none. Everything is set per tag, in the tag's edit dialog under
Admin → Tags.

## For themes

Each tag gains `coverUrl` and `logoUrl` attributes on the API, and the forum
publishes every tag's images as custom properties:

```css
--tag-cover   /* url("…") */
--tag-logo
--cov         /* short forms of the same two */
--lg
```

They are set on:

- `[data-tag-cover="<slug>"]`, which also **paints** the cover as a background
- `[data-tag-vars="<slug>"]`, which only declares the properties and paints nothing, for an element that wants the logo or cover as a background of its own choosing
- a `.TagTile` for the tag
- a `.BespokeForum` card or `.BespokeForum-sub` chip for the tag ([Bespoke](https://github.com/ernestdefoe/bespoke))
- a Page Builder tag card (`a.PB-tagCard`) for the tag

`[data-tag-logo]` paints `--tag-logo` with `background-size: contain`, so a
crest is never cropped.

So a card only has to read the property:

```css
.MyCard { background-image: var(--tag-cover); background-size: cover; }
```

Nothing here knows what a card looks like, which is why the extension does
not depend on any particular theme.

## Good to know

- **Every tag, not just the ones loaded.** The images for all tags are sent with the page in one small query, so a team tag's crest appears even on a page that never loaded that tag.
- **Files live on the `flarum-assets` disk** beside the logo and favicon, named `tag-cover-<tagId>-<random>.webp` and `tag-logo-<tagId>-<random>.webp`.
- **No orphaned files.** Removing or replacing an image sweeps every file of that kind for the tag by filename, rather than trusting the path in the database, so an upload that failed halfway cannot leave a file nothing points at.
- **Requires** Flarum 2, `flarum/tags` and PHP 8.3.

## Installation

```bash
composer require ernestdefoe/tag-covers
php flarum cache:clear
```

Then enable **Tag Covers** in the admin panel.

## Updating

```bash
composer update ernestdefoe/tag-covers
php flarum cache:clear
```

## Licence

MIT.
