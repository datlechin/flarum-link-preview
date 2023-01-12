import Component from 'flarum/common/Component';

export default class LinkPreview extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true;
    this.link = vnode.attrs.link;
    this.linkAttributes = (() => {
      const attributes = {};
      for (const attribute of Object.values(this.link.attributes)) {
        attributes[attribute.name] = attribute.value;
      }
      return attributes;
    })();
    this.data = null;
    this.useGoogleFavicons = vnode.attrs.useGoogleFavicons;

    this.fetchData();
  }
  view() {
    return (
      <div className="LinkPreview">
        {this.loading || this.getImage() ? (
          <div className="LinkPreview-image">
            {this.loading ? <i className="fa fa-spinner fa-spin" /> : <img src={this.getImage()} data-link-preview />}
          </div>
        ) : null}
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">{this.getLink(this.loading ? this.getDomain() : this.data?.title ?? this.data.error)}</div>
          <div className="LinkPreview-description">{this.loading ? '' : this.data?.description ?? ''}</div>
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} data-link-preview /> : <i className="fa fa-external-link-alt"></i>}
            {this.getLink(this.loading ? this.getDomain() : this.data?.site_name ?? this.getDomain())}
          </div>
        </div>
      </div>
    );
  }

  oncreate(vnode) {
    this.link.parentNode.insertBefore(vnode.dom, this.link);
  }

  getLink(text) {
    return m('a', this.linkAttributes, text);
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
