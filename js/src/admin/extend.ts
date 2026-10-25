import Extend from 'flarum/common/extenders';

import { SETTING, settingKey, transText } from './config';
import LinkPreviewSettingsPage from './components/LinkPreviewSettingsPage';

export default [
  new Extend.Admin()
    .page(LinkPreviewSettingsPage)

    .generalIndexItems('settings', () =>
      Object.values(SETTING).map((name) => ({
        id: settingKey(name),
        label: transText(`settings.${name}_label`),
        help: transText(`settings.${name}_help`),
      }))
    ),
];
