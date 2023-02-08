import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import LinkPreview from './components/LinkPreview';

app.initializers.add('datlechin/flarum-link-preview', () => {
  extend(CommentPost.prototype, 'refreshContent', function () {
    const getMultiDimensionalSetting = (key) => {
      const setting = app.forum.attribute(key);
      return setting ? setting.split(/[,\n]/).map((item) => item.trim()) : [];
    };

    const inList = (needle, haystack) => {
      if (0 === haystack.length) {
        return false;
      }
      if (haystack.includes(needle)) {
        return true;
      }
      for (const item of haystack) {
        const quoted = item
          .replace(/[-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, '\\$&')
          .replace('\\*', '.*')
          .replace('\\?', '.');
        if (needle.match(new RegExp(quoted, 'i'))) {
          return true;
        }
      }
      return false;
    };

    const blacklistArray = getMultiDimensionalSetting('datlechin-link-preview.blacklist');
    const whitelistArray = getMultiDimensionalSetting('datlechin-link-preview.whitelist');
    const useGoogleFavicons = app.forum.attribute('datlechin-link-preview.useGoogleFavicons') ?? false;

    this.element.querySelectorAll('.Post-body a[rel]').forEach((link) => {
      if (link.classList.contains('PostMention') || link.classList.contains('UserMention') || link.classList.contains('LinkPreview-link')) {
        return;
      }

      const normalizedUrl = link.href.replace(/^https?:\/\/(.+?)\/?$/i, '$1');

      if (
        (whitelistArray.length && !inList(normalizedUrl, whitelistArray)) ||
        (blacklistArray.length && inList(normalizedUrl, blacklistArray)) ||
        link.href.replace(/\/$/, '') !== link.textContent.replace(/\/$/, '')
      ) {
        return;
      }

      if (app.forum.attribute('datlechin-link-preview.convertMediaURLs') && normalizedUrl.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) {
        return;
      }

      m.mount(link, {
        view: function () {
          return m(LinkPreview, { link, useGoogleFavicons: useGoogleFavicons });
        },
      });
    });
  });
});
