import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

app.initializers.add('datlechin/flarum-link-preview', () => {
  extend(CommentPost.prototype, 'oncreate', function () {
    const links = this.element.querySelectorAll('.Post-body a[rel]');

    for (let i = 0; i < links.length; i++) {
      const link = links[i];
      const href = link.getAttribute('href');

      if (!link.classList.contains('PostMention') || !link.classList.contains('UserMention')) {
        if (href === link.textContent) {
          const wrapper = document.createElement('div');
          wrapper.classList.add('LinkPreview');
          link.parentNode.insertBefore(wrapper, link);
          wrapper.appendChild(link);

          link.remove();

          const imageWrapper = document.createElement('div');
          imageWrapper.classList.add('LinkPreview-image');
          wrapper.appendChild(imageWrapper);

          const img = document.createElement('img');
          imageWrapper.appendChild(img);

          const mainWrapper = document.createElement('div');
          mainWrapper.classList.add('LinkPreview-main');
          wrapper.appendChild(mainWrapper);

          const title = document.createElement('div');
          title.classList.add('LinkPreview-title');
          mainWrapper.appendChild(title);

          const titleLink = document.createElement('a');
          titleLink.target = '_blank';
          title.appendChild(titleLink);

          const description = document.createElement('div');
          description.classList.add('LinkPreview-description');
          mainWrapper.appendChild(description);

          const domain = document.createElement('div');
          domain.classList.add('LinkPreview-domain');
          mainWrapper.appendChild(domain);

          const domainLink = document.createElement('a');
          domainLink.href = href;
          domainLink.target = '_blank';

          const domainName = href.split('/')[2].replace('www.', '');
          const domainUrl = href.split('/')[0] + '//' + domainName;

          const favicon = document.createElement('img');
          favicon.src = 'https://www.google.com/s2/favicons?sz=64&domain_url=' + domainUrl;
          domain.appendChild(favicon);

          domainLink.textContent = domainName;
          domain.appendChild(domainLink);
          domainLink.href = domainUrl;

          const loadingIcon = document.createElement('i');
          loadingIcon.classList.add('fa', 'fa-spinner', 'fa-spin');
          imageWrapper.appendChild(loadingIcon);

          app
            .request({
              url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + domainUrl,
              method: 'GET',
            })
            .then((data) => {
              loadingIcon.remove();
              img.src = data.image ? data.image : 'https://www.google.com/s2/favicons?sz=64&domain_url=' + domainName;
              titleLink.href = data.url ? data.url : href;
              titleLink.textContent = data.title ? data.title : domainName;
              description.textContent = data.description ? data.description : '';
              domainLink.textContent = data.site_name ? data.site_name : domainName;
            });
        }
      }
    }
  });
});
