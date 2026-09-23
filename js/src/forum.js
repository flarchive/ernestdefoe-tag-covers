import app from 'flarum/forum/app';

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
app.initializers.add('ernestdefoe-tag-covers', () => {
  const write = () => {
    /*
     * 🚨 The payload FIRST, the store second.
     *
     * `app.store.all('tags')` holds whatever the page happened to load, which
     * on a Flarum forum is the primary tags and nothing else — so a board with
     * a hundred child tags published rules for a handful of them and every
     * other crest was simply missing, on every page, with nothing to say why.
     * The server publishes the whole map; the store is the fallback for a
     * payload that predates it.
     */
    const published = (app.data && app.data.tagCoverImagery) || null;

    let entries = [];

    if (published) {
      entries = Object.keys(published).map((slug) => ({
        slug,
        cover: published[slug].cover,
        logo: published[slug].logo,
      }));
    } else {
      let tags = [];
      try { tags = app.store.all('tags') || []; } catch (e) { return; }

      entries = tags.map((tag) => {
        try {
          return { slug: tag.slug(), cover: tag.attribute('coverUrl'), logo: tag.attribute('logoUrl') };
        } catch (e) {
          return null;
        }
      }).filter(Boolean);
    }

    const rules = [];

    entries.forEach(({ slug, cover, logo }) => {
      if (!slug || (!cover && !logo)) return;

      const esc = String(slug).replace(/"/g, '\\"');
      const url = (u) => `url("${String(u).replace(/"/g, '\\"')}")`;

      // Both kinds are declared in one block per selector, so a tag with a
      // logo and no cover still gets its logo — an earlier version bailed on
      // a missing cover and took the logo with it.
      const decl = [
        cover ? `--tag-cover:${url(cover)};--cov:${url(cover)}` : '',
        logo ? `--tag-logo:${url(logo)};--lg:${url(logo)}` : '',
      ].filter(Boolean).join(';');

      rules.push(
        `[data-tag-cover="${esc}"]{${decl}}`,

        /*
         * 🚨 The same variables, on an attribute that paints NOTHING.
         *
         * `data-tag-cover` is a paint hook — the stylesheet draws the cover on
         * anything carrying it — so an element that only wants to reach the
         * crest through `var(--lg)` cannot use it without also wearing a
         * full-bleed photograph. That caught out a discussion list that wanted
         * its forum's badge in the background and got the cover art instead.
         *
         * This is the seam for that: say which tag an element represents, get
         * the imagery as custom properties, and decide for yourself what to do
         * with them.
         */
        `[data-tag-vars="${esc}"]{${decl}}`,
        // 🚨 DIRECT-child anchor, not any descendant. A forum card contains a
        // link for every subforum chip inside it, so `:has(a[href$=...])`
        // matched the card for each of its children too and the last child in
        // the sheet won — a conference card wore one of its teams' crests.
        // The card's own title link is its direct child; the chips are not.
        `.BespokeForum:has(> a[href$="/t/${esc}"]){${decl}}`,
        `.TagTile:has(> a[href$="/t/${esc}"]),a.TagTile[href$="/t/${esc}"]{${decl}}`,
        // A chip links to its own tag, so it IS the anchor rather than an
        // ancestor of one.
        `a.BespokeForum-sub[href$="/t/${esc}"]{${decl}}`,
        // Page Builder's tag grid, which is the same shape: the card IS the
        // link. Without this a front page built from that block is the one
        // place on the site where the forums have no crests.
        `a.PB-tagCard[href$="/t/${esc}"]{${decl}}`,

        /*
         * The crest inside core's own tag pill, wherever one is drawn — a
         * discussion's header, a list row, a search result.
         *
         * 🚨 The whole RULE is emitted per tag, not just the variable, and
         * that is the point: `tagLabel` is a helper function rather than a
         * component, so there is no element to render a crest into and no way
         * for CSS to ask "does this tag have a logo?". Emitting the marker
         * only for tags that HAVE one puts the condition where it can actually
         * be expressed — a pill with no logo is never given the inset, so it
         * cannot end up with a gap where a crest is not.
         *
         * Sized in `em` so it follows the pill's own font size; these labels
         * are drawn at half a dozen sizes across a board.
         */
        logo
          ? `a.TagLabel[href$="/t/${esc}"] .TagLabel-name::before{` +
              `content:"";display:inline-block;width:1em;height:1em;` +
              `margin-inline-end:.35em;vertical-align:-.15em;` +
              `background-image:${url(logo)};background-size:contain;` +
              `background-position:center;background-repeat:no-repeat}`
          : ''
      );
    });

    let el = document.getElementById('tag-covers-css');
    if (!el) {
      el = document.createElement('style');
      el.id = 'tag-covers-css';
      document.head.appendChild(el);
    }

    const css = rules.filter(Boolean).join('\n');
    if (el.textContent !== css) el.textContent = css;
  };

  write();

  // Tags load lazily on most pages, so refresh the sheet after the store
  // settles rather than only at boot.
  const refresh = () => setTimeout(write, 0);
  document.addEventListener('DOMContentLoaded', refresh);
  setTimeout(write, 1200);
  setTimeout(write, 3000);
});
