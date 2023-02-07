import Component from 'flarum/common/Component';
import icon from 'flarum/common/helpers/icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

export default class LinkPreview extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true;
    this.link = vnode.attrs.link;
    this.linkAttributes = Object.assign({}, ...Array.from(this.link.attributes, ({ name, value }) => ({ [name]: value })));
    this.data = null;
    this.useGoogleFavicons = vnode.attrs.useGoogleFavicons;

    this.fetchData();
  }

  view() {
    const classes = {
      loading: this.loading,
    };

    return (
      <div className={'LinkPreview ' + classList(classes)}>
        {this.loading || this.getImage() ? (
          <div className="LinkPreview-image">
            {this.loading ? (
              <LoadingIndicator display="unset" containerClassName={classList('LinkPreview-loading', this.loading && 'active')} size="small" />
            ) : (
              <img src={this.getImage()} data-link-preview />
            )}
          </div>
        ) : null}
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">{this.getLink(this.data?.title ?? this.data?.error)}</div>
          <div className="LinkPreview-description">{this.loading ? '' : this.data?.description ?? ''}</div>
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} data-link-preview /> : icon('fas fa-external-link-alt')}
            {this.getLink(this.data?.site_name)}
          </div>
        </div>
      </div>
    );
  }

  oncreate(vnode) {
    this.link.parentNode.insertBefore(vnode.dom, this.link);
  }

  getLink(text) {
    return <Link {...this.linkAttributes}>{this.loading ? this.getDomain() : text ?? this.getDomain()}</Link>;
  }

  getHref() {
    return this.link.href;
  }

  getDomain() {
    return this.getHref().split('/')[2];
  }

  getImage() {
    return this.data?.image ?? this.getFavicon();
  }

  getFavicon() {
    return this.useGoogleFavicons ? 'https://www.google.com/s2/favicons?sz=64&domain_url=' + this.getDomain() : null;
  }

  fetchData() {
    app
      .request({
        url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(this.getHref()),
        method: 'GET',
      })
      .then((data) => {
        this.setData(data);
        this.loading = false;
      });
  }

  setData(data) {
    this.data = data;
    m.redraw();
  }
}
