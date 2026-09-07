import app from 'flarum/forum/app';

import addLinkPreviews from './addLinkPreviews';
import hideLinkPreviewsSetting from './hideLinkPreviewsSetting';

export { default as extend } from './extend';

app.initializers.add('datlechin-link-preview', () => {
  addLinkPreviews();
  hideLinkPreviewsSetting();
});
