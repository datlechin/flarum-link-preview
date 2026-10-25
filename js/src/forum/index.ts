import app from 'flarum/forum/app';

import hideLinkPreviewsSetting from './hideLinkPreviewsSetting';

export { default as extend } from './extend';

app.initializers.add('datlechin-link-preview', () => {
  hideLinkPreviewsSetting();
});
