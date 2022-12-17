import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import LinkPreview from './components/LinkPreview';

app.initializers.add('datlechin/flarum-link-preview' , () => {
  extend(CommentPost.prototype , 'oncreate' , function () {
    const blacklist = app.forum.attribute('datlechin-link-preview.blacklist');
    const blacklistArray = blacklist
      ? blacklist.split(',').map(function (item) {
        return item.trim();
      })
      : [];

    const links = this.element.querySelectorAll('.Post-body a[rel]');

    console.log(blacklistArray);
    links.forEach((link) => {
      const href = link.href
      const domain = href.split('/')[2].split('.').slice(-2).join('.');
      if (link.classList.contains('PostMention') || link.classList.contains('UserMention')) return
      if (blacklistArray.includes(domain)) return
      if (app.forum.attribute('datlechin-link-preview.convertMediaURLs') && href.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) return;

      m.mount(link , {
        view: function () {
          return m(LinkPreview , { link });
        }
      });
    });
  });
});
