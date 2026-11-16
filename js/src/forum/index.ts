import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TagsPage from 'ext:flarum/tags/forum/components/TagsPage';

/**
 * Publishes each tag's images as CSS custom properties.
 *
 * A stylesheet rather than walking the DOM: whatever renders a tag as a
 * card (Bespoke's forum cards, a tag tile, anything future) only has to
 * read `--tag-cover` or `--tag-logo`, and nothing here needs to know that
 * element exists.
 *
 * 🚨 A stylesheet is also the only way the LOGO can reach a subforum chip.
 * The component that draws a chip renders a Font Awesome class or nothing —
 * it has no img — so a custom property the theme paints as a background is
 * what lets a chip carry a crest without every renderer being taught about
 * logos.
 */
type Imagery = { cover?: string | null; logo?: string | null };

/**
 * The map AddTagImagery publishes with the page: slug => its images.
 *
 * 🚨 The payload, never `app.store.all('tags')`. The store holds whatever the
 * page happened to load, which on a Flarum forum is the primary tags and
 * nothing else — so a board with a hundred child tags published rules for a
 * handful of them and every other crest was simply missing. The server sends
 * every visible tag's images, so the sheet is complete at boot.
 */
const publishedMap = (): Record<string, Imagery> => (app.data as any)?.tagCoverImagery || {};

const cssUrl = (u: string) => `url("${String(u).replace(/"/g, '\\"')}")`;

/**
 * The crest inside core's own tag pill, wherever one is drawn — a
 * discussion's header, a list row, a search result.
 *
 * 🚨 The whole RULE is emitted per tag, not just the variable: `tagLabel` is a
 * helper function rather than a component, so there is no element to render a
 * crest into and no way for CSS to ask "does this tag have a logo?". Emitting
 * it only for tags that HAVE one means a pill with no logo is never given the
 * inset, so it cannot end up with a gap where a crest is not. Sized in `em` so
 * it follows the pill's own font size.
 */
const pillRule = (esc: string, logo: string) =>
  `a.TagLabel[href$="/t/${esc}"] .TagLabel-name::before{` +
  `content:"";display:inline-block;width:1em;height:1em;` +
  `margin-inline-end:.35em;vertical-align:-.15em;` +
  `background-image:${cssUrl(logo)};background-size:contain;` +
  `background-position:center;background-repeat:no-repeat}`;

/**
 * Every rule one tag's images need. The selectors:
 *
 * - `[data-tag-cover]` is a paint hook: the stylesheet draws the cover on it.
 * - 🚨 `[data-tag-vars]` carries the same variables and paints NOTHING, for an
 *   element that wants the crest through `var(--lg)` without also wearing a
 *   full-bleed photograph (a discussion list wanted its forum's badge and got
 *   the cover art).
 * - 🚨 Cards match a DIRECT-child anchor, not any descendant. A forum card
 *   holds a link for every subforum chip, so a descendant `:has()` matched the
 *   card once per child and the last one won: a conference card wore one of
 *   its teams' crests.
 * - A Bespoke chip and a Page Builder tag card ARE the link, so they match on
 *   their own href.
 */
function rulesFor(slug: string, { cover, logo }: Imagery): string[] {
  if (!slug || (!cover && !logo)) return [];

  const esc = String(slug).replace(/"/g, '\\"');

  // Both kinds in one block per selector, so a tag with a logo and no cover
  // still gets its logo.
  const decl = [cover ? `--tag-cover:${cssUrl(cover)};--cov:${cssUrl(cover)}` : '', logo ? `--tag-logo:${cssUrl(logo)};--lg:${cssUrl(logo)}` : '']
    .filter(Boolean)
    .join(';');

  const selectors = [
    `[data-tag-cover="${esc}"]`,
    `[data-tag-vars="${esc}"]`,
    `.BespokeForum:has(> a[href$="/t/${esc}"])`,
    `.TagTile:has(> a[href$="/t/${esc}"]),a.TagTile[href$="/t/${esc}"]`,
    `a.BespokeForum-sub[href$="/t/${esc}"]`,
    `a.PB-tagCard[href$="/t/${esc}"]`,
  ];

  return [...selectors.map((sel) => `${sel}{${decl}}`), logo ? pillRule(esc, logo) : ''].filter(Boolean);
}

app.initializers.add('ernestdefoe-tag-covers', () => {
  const published = publishedMap();
  const css = Object.keys(published)
    .flatMap((slug) => rulesFor(slug, published[slug]))
    .join('\n');

  if (!css) return;

  const el = document.createElement('style');
  el.id = 'tag-covers-css';
  el.textContent = css;
  document.head.appendChild(el);
});

/**
 * A tag's cover and logo, from the published map first and the model second —
 * the same order, for the same reason, as the stylesheet above.
 */
function imageryFor(tag: any): { slug?: string | null; cover?: string | null; logo?: string | null } {
  const published = publishedMap();
  const slug: string | null = tag.slug();

  const fromMap = (slug && published[slug]) || {};

  return {
    slug,
    cover: fromMap.cover || tag.attribute('coverUrl') || null,
    logo: fromMap.logo || tag.attribute('logoUrl') || null,
  };
}

/*
 * 🚨 The stock tags page DRAWS the images itself.
 *
 * Until 1.2.0 this extension only published custom properties and left the
 * drawing to a theme. Every theme that drew them was one of ours, configured
 * on our own forums, so on anybody else's forum an uploaded cover went
 * nowhere: the tile carried `--tag-cover` and nothing ever read it. Someone
 * installing this to put pictures on their tags page saw no pictures.
 *
 * Real elements, not pseudo-elements on the tile — Bespoke already spends the
 * tile's ::before and ::after on its shine and its border, and a second claim
 * on either would wipe one of them out. And they are rendered only for a tag
 * that HAS an image, so a tile without one is the stock tile, untouched.
 */
app.initializers.add('ernestdefoe-tag-covers-tiles', () => {
  extend(TagsPage.prototype, 'tagTileView', function (vnode: any, tag: any) {
    if (!vnode || !Array.isArray(vnode.children)) return;

    const { slug, cover, logo } = imageryFor(tag);
    if (!cover && !logo) return;

    if (cover) {
      vnode.attrs.className = `${vnode.attrs.className || ''} TagTile--hasCover`;
      vnode.children.unshift(
        m('.TagTile-cover', {
          'aria-hidden': 'true',
          style: { backgroundImage: `url("${String(cover).replace(/"/g, '\\"')}")` },
        })
      );
    }

    if (logo) {
      // The info link's first child is the heading: [icon | false, name].
      // A logo stands in for the icon — a crest beside a Font Awesome glyph
      // is two marks for one thing.
      const info = vnode.children.find((c: any) => c && c.attrs && /\bTagTile-info\b/.test(c.attrs.className || ''));
      const heading =
        info && Array.isArray(info.children)
          ? info.children.find((c: any) => c && c.attrs && /\bTagTile-heading\b/.test(c.attrs.className || ''))
          : null;

      if (heading && Array.isArray(heading.children)) {
        const img = m('img.TagTile-logo', { src: logo, alt: '', loading: 'lazy', 'data-tag-slug': slug });
        const iconAt = heading.children.findIndex((c: any) => c && c.attrs && /\bicon\b/.test(c.attrs.className || ''));

        if (iconAt >= 0) heading.children[iconAt] = img;
        else heading.children.unshift(img);
      }
    }
  });
});

export { default as extend } from './extend';
