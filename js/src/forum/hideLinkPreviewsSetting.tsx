import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Switch from 'flarum/common/components/Switch';

import type SettingsPage from 'flarum/forum/components/SettingsPage';

type PageWithLoading = SettingsPage & { hideLinkPreviewsLoading?: boolean };

export default function hideLinkPreviewsSetting(): void {
  extend<PageWithLoading, 'privacyItems'>('flarum/forum/components/SettingsPage', 'privacyItems', function (items) {
    const user = app.session.user;

    if (!user) return;

    items.add(
      'hideLinkPreviews',
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
    .catch(() => undefined)
    .then(() => {
      page.hideLinkPreviewsLoading = false;
      m.redraw();
    });
}
