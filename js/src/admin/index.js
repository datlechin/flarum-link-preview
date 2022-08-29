import app from 'flarum/admin/app';

const EXT_PREFIX = 'datlechin-link-preview';

app.initializers.add('datlechin/flarum-link-preview', () => {
  app.extensionData
    .for(EXT_PREFIX + '')
    .registerSetting({
      setting: EXT_PREFIX + '.convert_media_urls',
      label: app.translator.trans(EXT_PREFIX + '.admin.settings.convert_media_urls_label'),
      help: app.translator.trans(EXT_PREFIX + '.admin.settings.convert_media_urls_help'),
      type: 'checkbox',
    })
    .registerSetting({
      setting: EXT_PREFIX + '.blacklist',
      label: app.translator.trans(EXT_PREFIX + '.admin.settings.blacklist_label'),
      help: app.translator.trans(EXT_PREFIX + '.admin.settings.blacklist_help'),
      placeholder: app.translator.trans(EXT_PREFIX + '.admin.settings.blacklist_placeholder'),
      type: 'textarea',
    });
});
