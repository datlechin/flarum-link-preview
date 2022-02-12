import app from 'flarum/admin/app';

app.initializers.add('datlechin/flarum-link-preview', () => {
  app.extensionData
    .for('datlechin-link-preview')
    .registerSetting({
      setting: 'datlechin-link-preview.convert_media_urls',
      label: app.translator.trans('datlechin-link-preview.admin.settings.convert_media_urls_label'),
      help: app.translator.trans('datlechin-link-preview.admin.settings.convert_media_urls_help'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: 'datlechin-link-preview.blacklist',
      label: app.translator.trans('datlechin-link-preview.admin.settings.blacklist_label'),
      help: app.translator.trans('datlechin-link-preview.admin.settings.blacklist_help'),
      placeholder: app.translator.trans('datlechin-link-preview.admin.settings.blacklist_placeholder'),
      type: 'textarea',
    });
});
