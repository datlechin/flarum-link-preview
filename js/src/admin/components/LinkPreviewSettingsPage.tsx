import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import FieldSet from 'flarum/common/components/FieldSet';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

const PREFIX = 'datlechin-link-preview';

export default class LinkPreviewSettingsPage extends ExtensionPage {
  content() {
    const sections = this.settingSections();

    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <div className="Form">
            {sections.toArray()}
            <div className="Form-group">{this.submitButton()}</div>
          </div>
        </div>
      </div>
    );
  }

  settingSections(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    items.add(
      'general',
      <FieldSet
        label={app.translator.trans(`${PREFIX}.admin.sections.general_label`) as string}
        description={app.translator.trans(`${PREFIX}.admin.sections.general_description`) as string}
      >
        {this.buildSettingComponent({
          setting: `${PREFIX}.open_links_in_new_tab`,
          label: app.translator.trans(`${PREFIX}.admin.settings.open_links_in_new_tab_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.open_links_in_new_tab_help`),
          type: 'bool',
        })}
        {this.buildSettingComponent({
          setting: `${PREFIX}.convert_media_urls`,
          label: app.translator.trans(`${PREFIX}.admin.settings.convert_media_urls_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.convert_media_urls_help`),
          type: 'bool',
        })}
        {this.buildSettingComponent({
          setting: `${PREFIX}.use_google_favicons`,
          label: app.translator.trans(`${PREFIX}.admin.settings.use_google_favicons_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.use_google_favicons_help`),
          type: 'bool',
        })}
      </FieldSet>,
      100
    );

    items.add(
      'performance',
      <FieldSet
        label={app.translator.trans(`${PREFIX}.admin.sections.performance_label`) as string}
        description={app.translator.trans(`${PREFIX}.admin.sections.performance_description`) as string}
      >
        {this.buildSettingComponent({
          setting: `${PREFIX}.enable_batch_requests`,
          label: app.translator.trans(`${PREFIX}.admin.settings.enable_batch_requests_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.enable_batch_requests_help`),
          type: 'bool',
        })}
        {this.buildSettingComponent({
          setting: `${PREFIX}.cache_time`,
          label: app.translator.trans(`${PREFIX}.admin.settings.cache_time_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.cache_time_help`),
          type: 'number',
          min: 0,
        })}
      </FieldSet>,
      90
    );

    items.add(
      'urlFiltering',
      <FieldSet
        label={app.translator.trans(`${PREFIX}.admin.sections.url_filtering_label`) as string}
        description={app.translator.trans(`${PREFIX}.admin.sections.url_filtering_description`) as string}
      >
        {this.buildSettingComponent({
          setting: `${PREFIX}.blacklist`,
          label: app.translator.trans(`${PREFIX}.admin.settings.blacklist_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.blacklist_help`),
          placeholder: app.translator.trans(`${PREFIX}.admin.settings.blacklist_placeholder`),
          type: 'textarea',
        })}
        {this.buildSettingComponent({
          setting: `${PREFIX}.whitelist`,
          label: app.translator.trans(`${PREFIX}.admin.settings.whitelist_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.whitelist_help`),
          placeholder: app.translator.trans(`${PREFIX}.admin.settings.whitelist_placeholder`),
          type: 'textarea',
        })}
      </FieldSet>,
      80
    );

    items.add(
      'externalApi',
      <FieldSet
        label={app.translator.trans(`${PREFIX}.admin.sections.external_api_label`) as string}
        description={app.translator.trans(`${PREFIX}.admin.sections.external_api_description`) as string}
      >
        {this.buildSettingComponent({
          setting: `${PREFIX}.external_api_fallback`,
          label: app.translator.trans(`${PREFIX}.admin.settings.external_api_fallback_label`),
          help: app.translator.trans(`${PREFIX}.admin.settings.external_api_fallback_help`),
          type: 'bool',
        })}
        {this.setting(`${PREFIX}.external_api_fallback`)() === '1' &&
          this.buildSettingComponent({
            setting: `${PREFIX}.external_api_url`,
            label: app.translator.trans(`${PREFIX}.admin.settings.external_api_url_label`),
            help: app.translator.trans(`${PREFIX}.admin.settings.external_api_url_help`),
            placeholder: 'https://open.iframe.ly/api/oembed?origin=flarum',
            type: 'url',
          })}
      </FieldSet>,
      70
    );

    return items;
  }
}
