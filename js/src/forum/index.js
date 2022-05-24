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
      const domain = href.split('/')[2];

      if (!link.classList.contains('PostMention') || !link.classList.contains('UserMention')) {
        if (href === link.textContent && !blacklistArray.includes(domain) && !blacklistArray.includes(href)) {
          if (!app.forum.attribute('datlechin-link-preview.convertMediaURLs')) {
            if (href.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) return;
          }
          const siteUrl = href.split('/')[0] + '//' + domain;

          const linkPreviewWrapper = document.createElement('div');
          const linkPreviewImage = document.createElement('div');
          const linkPreviewImg = document.createElement('img');
          const linkPreviewMain = document.createElement('div');
          const linkPreviewTitle = document.createElement('div');
          const linkPreviewTitleURL = document.createElement('a');
          const linkPreviewDescription = document.createElement('div');
          const linkPreviewDomain = document.createElement('div');
          const linkPreviewDomainURL = document.createElement('a');
          const linkPreviewDomainFavicon = document.createElement('img');
          const linkPreviewLoadingIcon = document.createElement('i');

          linkPreviewWrapper.classList.add('LinkPreview');
          linkPreviewImage.classList.add('LinkPreview-image');
          linkPreviewMain.classList.add('LinkPreview-main');
          linkPreviewTitle.classList.add('LinkPreview-title');
          linkPreviewTitleURL.target = '_blank';
          linkPreviewDescription.classList.add('LinkPreview-description');
          linkPreviewDomain.classList.add('LinkPreview-domain');
          linkPreviewDomainURL.href = href;
          linkPreviewDomainURL.target = '_blank';
          linkPreviewDomainURL.textContent = domain;
          linkPreviewDomainURL.href = siteUrl;
          linkPreviewDomainFavicon.setAttribute('src', 'https://www.google.com/s2/favicons?sz=64&domain_url=' + href);
          linkPreviewLoadingIcon.classList.add('fa', 'fa-spinner', 'fa-spin');

          link.parentNode.insertBefore(linkPreviewWrapper, link);
          linkPreviewWrapper.appendChild(link);
          linkPreviewWrapper.appendChild(linkPreviewImage);
          linkPreviewImage.appendChild(linkPreviewImg);
          linkPreviewWrapper.appendChild(linkPreviewMain);
          linkPreviewMain.appendChild(linkPreviewTitle);
          linkPreviewTitle.appendChild(linkPreviewTitleURL);
          linkPreviewMain.appendChild(linkPreviewDescription);
          linkPreviewMain.appendChild(linkPreviewDomain);
          linkPreviewDomain.appendChild(linkPreviewDomainFavicon);
          linkPreviewDomain.appendChild(linkPreviewDomainURL);
          linkPreviewImage.appendChild(linkPreviewLoadingIcon);

          link.remove();

          app
            .request({
              url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(href),
              method: 'GET',
            })
            .then((data) => {
              linkPreviewLoadingIcon.remove();

              linkPreviewImg.setAttribute('src', data.image ?? 'https://www.google.com/s2/favicons?sz=64&domain_url=' + siteUrl);
              linkPreviewTitleURL.href = data.url ?? href;
              linkPreviewTitleURL.textContent = data.title ?? domain;
              linkPreviewDescription.textContent = data.description ?? '';
              linkPreviewDomainURL.textContent = data.site_name ?? domain;

              data.error ? (linkPreviewTitleURL.textContent = data.error) : null;
            });
        }
      }
    });
  });
});
