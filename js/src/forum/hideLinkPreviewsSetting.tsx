import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Switch from 'flarum/common/components/Switch';

// Type only. The class itself lives in a lazily loaded chunk, so importing it
// for use would pull a second copy into this bundle.
import type SettingsPage from 'flarum/forum/components/SettingsPage';

/**
 * The page while the preference is being saved. Kept on the page instance, the
 * way core keeps `discloseOnlineLoading`, so two settings pages cannot share
 * one spinner.
 */
type PageWithLoading = SettingsPage & { hideLinkPreviewsLoading?: boolean };

/**
 * Let a reader turn link previews off for themselves.
 *
 * The preference has always been read when posts are rendered, but nothing
 * could ever set it. It sits with the privacy settings because that is what it
 * decides: a card fetches its picture and its favicon from the site being
 * linked, straight from the reader's browser, which tells that site the reader
 * was here.
 */
export default function hideLinkPreviewsSetting(): void {
  extend<PageWithLoading, 'privacyItems'>('flarum/forum/components/SettingsPage', 'privacyItems', function (items) {
    const user = app.session.user;

    if (!user) return;

    items.add(
      'hideLinkPreviews',
      // The help text goes inside the switch, where core puts its own: the
      // `.SettingsPage .Checkbox .helpText` rule is what gives it its own line.
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
    // A failed save leaves the switch showing what is actually stored, which is
    // the truth of it. The alert core raises is the report of what went wrong.
    .catch(() => undefined)
    .then(() => {
      page.hideLinkPreviewsLoading = false;
      m.redraw();
    });
}
