import Extend from 'flarum/common/extenders';

import { SETTING, settingKey, transText } from './config';
import LinkPreviewSettingsPage from './components/LinkPreviewSettingsPage';

export default [
  new Extend.Admin()
    // `.page()` replaces the renderer that draws registered settings, so
    // anything added through `.setting()` here would never reach the screen.
    .page(LinkPreviewSettingsPage)

    // A page that draws its own fields has to list them for the admin search.
    .generalIndexItems('settings', () =>
      Object.values(SETTING).map((name) => ({
        id: settingKey(name),
        label: transText(`settings.${name}_label`),
        help: transText(`settings.${name}_help`),
      }))
    ),
];
