import app from 'flarum/admin/app';
import type { SaveSubmitEvent } from 'flarum/admin/components/AdminPage';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import FieldSet from 'flarum/common/components/FieldSet';
import Form from 'flarum/common/components/Form';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import { NUMBER_BOUNDS, SETTING, settingKey, trans, transText } from '../config';

/**
 * Anything `buildSettingComponent` accepts beside the three things this page
 * derives from a setting's name.
 */
type FieldOptions = { type: string; [attr: string]: unknown };

/**
 * A page rather than nine `Extend.Admin().setting()` calls, for one reason:
 * the grouping. Core renders registered settings as one flat column, and read
 * flat these nine give no sign that two of them decide which links the other
 * seven ever get to describe. Nothing else here needs a page, so everything
 * else is core's, called rather than reimplemented.
 */
export default class LinkPreviewSettingsPage extends ExtensionPage {
  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <Form>
            {this.settingSections().toArray()}
            {/* Both buttons live in the `content()` this page replaces, so they
                are built here from the same methods core builds them with. */}
            <div className="Form-group Form-controls">
              {this.submitButton()}
              {this.resetButton(
                // Core's default reads `settingLabels`, which `field()` fills,
                // so the modal lists settings by name rather than by key.
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
    // Submitting does not always take focus off a field first, so the numbers
    // are checked once more on the way out.
    Object.keys(NUMBER_BOUNDS).forEach((name) => this.clampNumber(name));

    return super.saveSettings(e);
  }

  /**
   * Nothing enforces `min` on a number input, and an emptied one saves as an
   * empty row. The server clamps both back into range on every read, so what
   * would otherwise happen is that the field says one thing and the forum does
   * another. Correcting the field keeps the two in agreement.
   */
  protected clampNumber(name: string): void {
    const { min, fallback } = NUMBER_BOUNDS[name];
    const setting = this.setting(settingKey(name));
    // A setting nobody has saved yet arrives from the payload as the number
    // the extender declared, not as a string.
    const raw = String(setting() ?? '').trim();
    const clamped = raw === '' || !Number.isFinite(Number(raw)) ? fallback : Math.max(min, Math.trunc(Number(raw)));

    // Only on a real change, so tabbing through an untouched field does not
    // light up the submit button.
    if (String(clamped) !== raw) {
      setting(String(clamped));
    }
  }

  /**
   * Builds one field from its name.
   *
   * `buildSettingComponent` records nothing about what it built, so the reset
   * modal would list these settings by their storage keys. Registering the
   * label alongside the stream gives that list the names the fields carry.
   */
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

  /**
   * Exposed as an `ItemList` so another extension can slot a section of its own
   * between these, which is the only way into a page that draws its own fields.
   *
   * Every section carries `FieldSet--form`. Without it core spaces the items in
   * a fieldset 5px apart while spacing the label and help text inside one field
   * 10px apart, so each help text sits closer to the next field than to the one
   * it describes. `MailPage` is core's own use of the modifier.
   */
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

    // Core's `content()` renders whatever another extension registered against
    // this one. Replacing that method would otherwise make this the one
    // extension page on the panel that silently drops those.
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
