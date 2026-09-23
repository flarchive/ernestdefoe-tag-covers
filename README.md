# Tag Covers

Gives every Flarum tag a cover image. Upload one in the tag's own edit
modal, and it becomes available anywhere a tag is rendered as a card.

Flarum 2, requires `flarum/tags`.

## Using it

Admin → Tags → click a tag → **Cover image** → choose a file. Landscape
works best, around 1200×480. PNG, JPEG, WebP or GIF up to 8MB; everything
is re-encoded to WebP at a maximum width of 1200, so a 2MB upload from an
image generator lands as roughly 100KB.

## For themes

Each tag gains a `coverUrl` attribute on the API, and the forum frontend
publishes a stylesheet exposing it as two custom properties:

```css
--tag-cover   /* url("…") */
--cov         /* the same, for consumers already using this name */
```

They are set on:

- `[data-tag-cover="<slug>"]` — put that attribute on anything you render
- `.BespokeForum` whose link points at the tag (ernestdefoe/bespoke cards)
- a `.TagTile` for the tag

So a card only has to read the property:

```css
.MyCard { background-image: var(--tag-cover); background-size: cover; }
```

Nothing here knows what a card looks like, which is why the extension does
not depend on any particular theme.

## Notes

Files live on the `flarum-assets` disk beside the logo and favicon, named
`tag-cover-<tagId>-<random>.webp`.

Removing a cover — or replacing one — sweeps **every** file for that tag by
filename prefix rather than trusting the path in the database. An upload
that writes its file and then fails before saving the row would otherwise
leave an orphan nothing referenced.

## Installation

```bash
composer require ernestdefoe/tag-covers
php flarum cache:clear
```

## Licence

MIT.
