import app from 'flarum/admin/app';
import type { SaveSubmitEvent } from 'flarum/admin/components/AdminPage';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import FieldSet from 'flarum/common/components/FieldSet';
import Form from 'flarum/common/components/Form';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import { NUMBER_BOUNDS, SETTING, settingKey, trans, transText } from '../config';

type FieldOptions = { type: string; [attr: string]: unknown };

export default class LinkPreviewSettingsPage extends ExtensionPage {
  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <Form>
            {this.settingSections().toArray()}
            <div className="Form-group Form-controls">
              {this.submitButton()}
              {this.resetButton(
                undefined,
                app.translator.trans(
                  'core.admin.extension.reset_settings.title_extension',
                  { extensionTitle: this.extension.extra['flarum-extension'].title },
                  true
                ),
                this.extension.id
              )}
            </div>
          </Form>
        </div>
      </div>
    );
  }

  saveSettings(e: SaveSubmitEvent) {
    Object.keys(NUMBER_BOUNDS).forEach((name) => this.clampNumber(name));

    return super.saveSettings(e);
  }

  protected clampNumber(name: string): void {
    const { min, fallback } = NUMBER_BOUNDS[name];
    const setting = this.setting(settingKey(name));
    const raw = String(setting() ?? '').trim();
    const clamped = raw === '' || !Number.isFinite(Number(raw)) ? fallback : Math.max(min, Math.trunc(Number(raw)));

    if (String(clamped) !== raw) {
      setting(String(clamped));
    }
  }

  protected field(name: string, options: FieldOptions): Mithril.Children {
    const key = settingKey(name);
    const label = trans(`settings.${name}_label`);
    const bounds = NUMBER_BOUNDS[name];

    this.setting(key, '', label);

    return this.buildSettingComponent({
      setting: key,
      label,
      help: trans(`settings.${name}_help`),
      ...(bounds ? { min: bounds.min, onblur: () => this.clampNumber(name) } : {}),
      ...options,
    });
  }

  settingSections(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    items.add(
      'appearance',
      <FieldSet className="FieldSet--form" label={transText('sections.appearance_label')} description={transText('sections.appearance_description')}>
        {this.field(SETTING.openLinksInNewTab, { type: 'bool' })}
        {this.field(SETTING.googleFaviconFallback, { type: 'bool' })}
      </FieldSet>,
      100
    );

    items.add(
      'behaviour',
      <FieldSet className="FieldSet--form" label={transText('sections.behaviour_label')} description={transText('sections.behaviour_description')}>
        {this.field(SETTING.previewInternalLinks, { type: 'bool' })}
        {this.field(SETTING.skipMediaLinks, { type: 'bool' })}
        {this.field(SETTING.maxPreviewsPerPost, { type: 'number' })}
      </FieldSet>,
      90
    );

    items.add(
      'performance',
      <FieldSet
        className="FieldSet--form"
        label={transText('sections.performance_label')}
        description={transText('sections.performance_description')}
      >
        {this.field(SETTING.enableBatchRequests, { type: 'bool' })}
        {this.field(SETTING.cacheTime, { type: 'number' })}
      </FieldSet>,
      80
    );

    items.add(
      'filtering',
      <FieldSet className="FieldSet--form" label={transText('sections.filtering_label')} description={transText('sections.filtering_description')}>
        {this.field(SETTING.allowlist, { type: 'textarea', placeholder: transText('settings.allowlist_placeholder') })}
        {this.field(SETTING.blocklist, { type: 'textarea', placeholder: transText('settings.blocklist_placeholder') })}
      </FieldSet>,
      70
    );

    const registered = app.registry.getSettings(this.extension.id) ?? [];

    if (registered.length) {
      items.add(
        'registered',
        registered.map((entry) => {
          if (typeof entry !== 'function') {
            this.setting(entry.setting, '', entry.label);
          }

          return this.buildSettingComponent(entry);
        }),
        0
      );
    }

    return items;
  }
}
