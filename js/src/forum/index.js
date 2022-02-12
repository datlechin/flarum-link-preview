import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

app.initializers.add('datlechin/flarum-link-preview', () => {
  extend(CommentPost.prototype, 'oncreate', function () {
    const blacklist = app.forum.attribute('datlechin-link-preview.blacklist');
    const blacklistArray = blacklist
      ? blacklist.split(',').map(function (item) {
          return item.trim();
        })
      : [];

    const links = this.element.querySelectorAll('.Post-body a[rel]');

    links.forEach((link) => {
      const href = link.getAttribute('href');
      const domain = href.split('/')[2].replace('www.', '');

      if (!link.classList.contains('PostMention') || !link.classList.contains('UserMention')) {
        if (href === link.textContent && !blacklistArray.includes(domain) && !blacklistArray.includes(href)) {
          if (!app.forum.attribute('datlechin-link-preview.convertMediaURLs')) {
            if (href.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) return;
          }
          const wrapper = document.createElement('div');
          wrapper.classList.add('LinkPreview');
          link.parentNode.insertBefore(wrapper, link);
          wrapper.appendChild(link);

          const imageWrapper = document.createElement('div');
          imageWrapper.classList.add('LinkPreview-image');
          wrapper.appendChild(imageWrapper);

          const img = document.createElement('img');
          imageWrapper.appendChild(img);

          const mainWrapper = document.createElement('div');
          mainWrapper.classList.add('LinkPreview-main');
          wrapper.appendChild(mainWrapper);

          const titleWrapper = document.createElement('div');
          titleWrapper.classList.add('LinkPreview-title');
          mainWrapper.appendChild(titleWrapper);

          const titleLink = document.createElement('a');
          titleLink.target = '_blank';
          titleWrapper.appendChild(titleLink);

          const description = document.createElement('div');
          description.classList.add('LinkPreview-description');
          mainWrapper.appendChild(description);

          const domainWrapper = document.createElement('div');
          domainWrapper.classList.add('LinkPreview-domain');
          mainWrapper.appendChild(domainWrapper);

          const domainLink = document.createElement('a');
          domainLink.href = href;
          domainLink.target = '_blank';

          const siteUrl = href.split('/')[0] + '//' + domain;

          const favicon = document.createElement('img');
          favicon.setAttribute('src', 'https://www.google.com/s2/favicons?sz=64&domain_url=' + siteUrl);
          domainWrapper.appendChild(favicon);

          domainLink.textContent = domain;
          domainWrapper.appendChild(domainLink);
          domainLink.href = siteUrl;

          const loadingIcon = document.createElement('i');
          loadingIcon.classList.add('fa', 'fa-spinner', 'fa-spin');
          imageWrapper.appendChild(loadingIcon);

          link.remove();

          app
            .request({
              url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(href),
              method: 'GET',
            })
            .then((data) => {
              loadingIcon.remove();

              if (data.image) {
                img.setAttribute('src', data.image);
              } else {
                img.remove();
                const errorIcon = document.createElement('i');
                errorIcon.classList.add('fas', 'fa-image');
                imageWrapper.appendChild(errorIcon);
              }

              titleLink.href = data.url ? data.url : href;
              titleLink.textContent = data.title ? data.title : domain;
              description.textContent = data.description ? data.description : '';
              domainLink.textContent = data.site_name ? data.site_name : domain;

              if (data.error) {
                titleLink.textContent = app.translator.trans('datlechin-link-preview.forum.site_cannot_be_reached');
                titleLink.removeAttribute('href');
                description.textContent = '';
                domainLink.removeAttribute('href');
              }
            });
        }
      }
    });
  });
});
