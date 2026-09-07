import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Switch from 'flarum/common/components/Switch';

// Type only: the class lives in a lazily loaded chunk, so a value import would pull in a second copy.
import type SettingsPage from 'flarum/forum/components/SettingsPage';

/** Kept on the page instance, not in module scope, so two settings pages cannot share one spinner. */
type PageWithLoading = SettingsPage & { hideLinkPreviewsLoading?: boolean };

/**
 * Sits with the privacy settings because that is what it decides: a card fetches its
 * picture and favicon from the linked site, straight from the reader's browser,
 * which tells that site the reader was here.
 */
export default function hideLinkPreviewsSetting(): void {
  extend<PageWithLoading, 'privacyItems'>('flarum/forum/components/SettingsPage', 'privacyItems', function (items) {
    const user = app.session.user;

    if (!user) return;

    items.add(
      'hideLinkPreviews',
      // Help text belongs inside the switch: only `.SettingsPage .Checkbox .helpText` gives it its own line.
      <Switch
        state={Boolean(user.preferences()?.hideLinkPreviews)}
        loading={Boolean(this.hideLinkPreviewsLoading)}
        onchange={(hide: boolean) => save(this, hide)}
      >
        {app.translator.trans('datlechin-link-preview.forum.settings.hide_link_previews_label')}
        <span className="helpText">{app.translator.trans('datlechin-link-preview.forum.settings.hide_link_previews_help')}</span>
      </Switch>,
      90
    );
  });
}

function save(page: PageWithLoading, hide: boolean): void {
  const user = app.session.user;

  if (!user) return;

  page.hideLinkPreviewsLoading = true;

  user
    .savePreferences({ hideLinkPreviews: hide })
    // Swallowed so the switch keeps showing what is actually stored; core already alerts.
    .catch(() => undefined)
    .then(() => {
      page.hideLinkPreviewsLoading = false;
      m.redraw();
    });
}
