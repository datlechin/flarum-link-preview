import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import LinkPreview from './components/LinkPreview';

app.initializers.add('datlechin/flarum-link-preview', () => {
  extend(CommentPost.prototype, 'refreshContent', function () {
    const convertMediaUrls = app.forum.attribute<boolean>('datlechin-link-preview.convertMediaURLs') ?? false;
    const useGoogleFavicons = app.forum.attribute<boolean>('datlechin-link-preview.useGoogleFavicons') ?? false;
    const openLinksInNewTab = app.forum.attribute<boolean>('datlechin-link-preview.openLinksInNewTab') ?? false;
    const linkSelectorExcludes = ['.PostMention', '.UserMention', '.LinkPreview-link', '.LinkPreview-captured'].map((cls) => `:not(${cls})`).join('');

    this.element.querySelectorAll<HTMLAnchorElement>(`.Post-body a[rel]${linkSelectorExcludes}`).forEach((link) => {
      if (link.classList.contains('LinkPreview-captured')) {
        return;
      }

      if (link.href.replace(/\/$/, '') !== (link.textContent?.replace(/\/$/, '') ?? '')) {
        return;
      }

      if (convertMediaUrls) {
        const normalizedUrl = link.href.replace(/^https?:\/\/(.+?)\/?$/i, '$1');
        if (normalizedUrl.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) {
          return;
        }
      }

      const wrapper = document.createElement('div');
      link.parentNode!.insertBefore(wrapper, link);
      link.style.display = 'none';
      link.classList.add('LinkPreview-captured');

      m.mount(wrapper, {
        view: () =>
          m(LinkPreview, {
            link,
            useGoogleFavicons,
            openLinksInNewTab,
          }),
      });
    });
  });
});
