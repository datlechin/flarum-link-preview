import app from 'flarum/admin/app';

const PREFIX = 'datlechin-link-preview';

app.initializers.add('datlechin/flarum-link-preview', () => {
  app.extensionData
    .for(PREFIX)
    .registerSetting({
      setting: `${PREFIX}.enable_batch_requests`,
      label: app.translator.trans(`${PREFIX}.admin.settings.enable_batch_requests_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.enable_batch_requests_help`),
      type: 'checkbox',
    })
    .registerSetting({
      setting: `${PREFIX}.convert_media_urls`,
      label: app.translator.trans(`${PREFIX}.admin.settings.convert_media_urls_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.convert_media_urls_help`),
      type: 'checkbox',
    })
    .registerSetting({
      setting: `${PREFIX}.use_google_favicons`,
      label: app.translator.trans(`${PREFIX}.admin.settings.use_google_favicons_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.use_google_favicons_help`),
      type: 'checkbox',
    })
    .registerSetting({
      setting: `${PREFIX}.blacklist`,
      label: app.translator.trans(`${PREFIX}.admin.settings.blacklist_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.blacklist_help`),
      placeholder: app.translator.trans(`${PREFIX}.admin.settings.blacklist_placeholder`),
      type: 'textarea',
    })
    .registerSetting({
      setting: `${PREFIX}.whitelist`,
      label: app.translator.trans(`${PREFIX}.admin.settings.whitelist_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.whitelist_help`),
      placeholder: app.translator.trans(`${PREFIX}.admin.settings.whitelist_placeholder`),
      type: 'textarea',
    })
    .registerSetting({
      setting: `${PREFIX}.cache_time`,
      label: app.translator.trans(`${PREFIX}.admin.settings.cache_time_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.cache_time_help`),
      type: 'number',
      min: 0,
    })
    .registerSetting({
      setting: `${PREFIX}.open_links_in_new_tab`,
      label: app.translator.trans(`${PREFIX}.admin.settings.open_links_in_new_tab_label`),
      help: app.translator.trans(`${PREFIX}.admin.settings.open_links_in_new_tab_help`),
      type: 'checkbox',
    });
});
