import Component from 'flarum/common/Component'

export default class LinkPreview extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true
    this.link = vnode.attrs.link
    this.data = null

    this.fetchData()
  }
  view() {
    return (
      <div className="LinkPreview">
        <div className="LinkPreview-image">
          {
            this.loading
              ? <i className="fa fa-spinner fa-spin" />
              : <img src={(this.data?.image ?? 'https://www.google.com/s2/favicons?sz=64&domain_url=' + this.getDomain())} />
          }
        </div>
        <div className="LinkPreview-main">
          <div className="LinkPreview-title">
            <a href={this.link} target="_blank">
              {this.loading ? this.getDomain() : (this.data?.title ?? this.data.error)}
            </a>
          </div>
          <div className="LinkPreview-description">
            {this.loading ? '' : (this.data?.description ?? '')}
          </div>
          <div className="LinkPreview-domain">
            <img src={'https://www.google.com/s2/favicons?sz=64&domain_url=' + this.getDomain()} />
            <a href={this.getHref()} target="_blank">
              {this.loading ? this.getDomain() : (this.data?.site_name ?? this.getDomain())}
            </a>
          </div>
        </div>
      </div>
    )
  }

  oncreate(vnode) {
    this.link.parentNode.insertBefore(vnode.dom, this.link)
  }

  getHref() {
    return this.link.href
  }

  getDomain() {
    return this.getHref().split('/')[2]
  }

  fetchData() {
    app
      .request({
        url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(this.getHref()) ,
        method: 'GET' ,
      })
      .then((data) => {
        this.setData(data)
        this.loading = false
      });
  }

  setData(data) {
    this.data = data
    m.redraw()
  }
}
