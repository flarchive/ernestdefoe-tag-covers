import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import EditTagModal from 'ext:flarum/tags/admin/components/EditTagModal';
import Button from 'flarum/common/components/Button';

const t = (key) => app.translator.trans(`ernestdefoe-tag-covers.admin.${key}`);

app.initializers.add('ernestdefoe-tag-covers', () => {
  extend(EditTagModal.prototype, 'fields', function (items) {
    const tag = this.attrs.model;

    // A tag being created has no id yet, so there is nothing to attach a
    // file to. The field appears once the tag has been saved.
    if (!tag || !tag.exists) return;

    items.add('cover', imageField(this, tag, 'cover'), 4);
    items.add('logo', imageField(this, tag, 'logo'), 3);
  });
});

/**
 * One field, both images.
 *
 * `kind` is 'cover' or 'logo'. Everything except the endpoint, the attribute
 * and the wording is identical, and a second hand-written copy of an upload
 * field is a second place for the busy/error handling to drift.
 */
function imageField(modal, tag, kind) {
  const attr = kind === 'logo' ? 'logoUrl' : 'coverUrl';
  const route = kind === 'logo' ? 'tag-logos' : 'tag-covers';

  // Per-kind state, so uploading a logo does not put the cover field into a
  // spinner and vice versa.
  modal.tagImages ??= {};
  const s = (modal.tagImages[kind] ??= {
    url: tag.attribute(attr) || null,
    busy: false,
    error: null,
  });

  const send = (method, body) => {
    s.busy = true;
    s.error = null;
    m.redraw();

    return app
      .request({
        method,
        url: `${app.forum.attribute('apiUrl')}/${route}/${tag.id()}`,
        body,
        serialize: (raw) => raw, // FormData must not be JSON-encoded
      })
      .then((res) => {
        s.url = (res && res[attr]) || null;
        // Keep the store in step so other views pick the change up without
        // a reload.
        try { tag.pushAttributes({ [attr]: s.url }); } catch (e) {}
      })
      .catch(() => { s.error = t('failed'); })
      .then(() => { s.busy = false; m.redraw(); });
  };

  const onpick = (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const data = new FormData();
    data.append(kind, file);
    send('POST', data);
    e.target.value = '';
  };

  return m('.Form-group.TagCovers-field', [
    m('label', t(kind + '_label')),
    m('.helpText', t(kind + '_help')),

    s.url
      ? m('.TagCovers-preview', { className: kind === 'logo' ? 'TagCovers-preview--logo' : '' },
          m('img', { src: s.url, alt: '' }))
      : null,

    m('.TagCovers-actions', [
      m('label.Button.TagCovers-pick', { disabled: s.busy }, [
        s.busy ? t('uploading') : (s.url ? t('replace') : t('upload')),
        m('input', {
          type: 'file',
          accept: 'image/png,image/jpeg,image/webp,image/gif',
          disabled: s.busy,
          onchange: onpick,
        }),
      ]),
      s.url
        ? m(Button, {
            className: 'Button Button--danger',
            disabled: s.busy,
            onclick: () => send('DELETE', null),
          }, t('remove'))
        : null,
    ]),

    s.error ? m('.TagCovers-error', s.error) : null,
  ]);
}
