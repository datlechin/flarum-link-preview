import Extend from 'flarum/common/extenders';

import { SETTING, settingKey, transText } from './config';
import LinkPreviewSettingsPage from './components/LinkPreviewSettingsPage';

export default [
  new Extend.Admin()
    // A page rather than a list of `.setting()` calls: `.page()` replaces the
    // renderer that would draw that list, so anything registered through
    // `.setting()` here would never reach the screen.
    .page(LinkPreviewSettingsPage)

    // Settings registered through `.setting()` turn up in the admin panel's
    // search box on their own. Ones a page draws itself do not, so they are
    // listed here, the way core's own custom pages list theirs.
    .generalIndexItems('settings', () =>
      Object.values(SETTING).map((name) => ({
        id: settingKey(name),
        label: transText(`settings.${name}_label`),
        help: transText(`settings.${name}_help`),
      }))
    ),
];
