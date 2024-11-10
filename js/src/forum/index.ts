import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import LinkPreview from './components/LinkPreview';

app.initializers.add('datlechin/flarum-link-preview', () => {
  extend(CommentPost.prototype, 'refreshContent', function () {
    const getMultiDimensionalSetting = (key: string): string[] => {
      const setting = app.forum.attribute<string>(key);
      return setting ? setting.split(/[,\n]/).map((item) => item.trim()) : [];
    };

    const inList = (needle: string, haystack: string[]): boolean => {
      if (haystack.length === 0) {
        return false;
      }
      if (haystack.includes(needle)) {
        return true;
      }
      for (const item of haystack) {
        const quoted = item
          .replace(/[-\[\]\/{}()*+?.\\^$|]/g, '\\$&')
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
    const convertMediaUrls = app.forum.attribute<boolean>('datlechin-link-preview.convertMediaURLs') ?? false;
    const useGoogleFavicons = app.forum.attribute<boolean>('datlechin-link-preview.useGoogleFavicons') ?? false;
    const openLinksInNewTab = app.forum.attribute<boolean>('datlechin-link-preview.openLinksInNewTab') ?? false;
    const linkSelectorExcludes = ['.PostMention', '.UserMention', '.LinkPreview-link', '.LinkPreview-captured'].map((cls) => `:not(${cls})`).join('');

    this.element.querySelectorAll<HTMLAnchorElement>(`.Post-body a[rel]${linkSelectorExcludes}`).forEach((link) => {
      const normalizedUrl = link.href.replace(/^https?:\/\/(.+?)\/?$/i, '$1');

      if (
        (whitelistArray.length && !inList(normalizedUrl, whitelistArray)) ||
        (blacklistArray.length && inList(normalizedUrl, blacklistArray)) ||
        link.href.replace(/\/$/, '') !== (link.textContent?.replace(/\/$/, '') ?? '')
      ) {
        return;
      }

      if (convertMediaUrls && normalizedUrl.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) {
        return;
      }

      m.mount(link, {
        view: function () {
          return m(LinkPreview, {
            link,
            useGoogleFavicons: useGoogleFavicons,
            openLinksInNewTab: openLinksInNewTab,
          });
        },
      });
    });
  });
});
