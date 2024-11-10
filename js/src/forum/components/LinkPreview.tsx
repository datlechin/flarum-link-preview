import Component from 'flarum/common/Component';
import icon from 'flarum/common/helpers/icon';
import classList from 'flarum/common/utils/classList';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import batchManager from '../utils/batch-manager';
import type Mithril from 'mithril';

interface LinkPreviewAttrs {
  link: HTMLAnchorElement;
  openLinksInNewTab: boolean;
  useGoogleFavicons: boolean;
}

interface LinkPreviewData {
  title?: string;
  description?: string;
  site_name?: string;
  image?: string;
  error?: string;
}

export default class LinkPreview extends Component<LinkPreviewAttrs> {
  loading!: boolean;
  link!: HTMLAnchorElement;
  linkAttributes!: { [key: string]: string };
  linkClasses!: string;
  data!: LinkPreviewData | null;
  useGoogleFavicons!: boolean;

  oninit(vnode: Mithril.Vnode<LinkPreviewAttrs, this>) {
    super.oninit(vnode);

    const attrs = vnode.attrs;

    this.loading = true;
    this.link = attrs.link;
    this.link.classList.add('LinkPreview-captured');
    this.linkAttributes = Object.assign({}, ...Array.from(this.link.attributes, ({ name, value }) => ({ [name]: value })));
    this.linkClasses = this.linkAttributes.class || '';
    this.linkAttributes.target = attrs.openLinksInNewTab ? '_blank' : '_self';
    delete this.linkAttributes.class;
    this.data = null;
    this.useGoogleFavicons = attrs.useGoogleFavicons;

    this.fetchData();
  }

  view() {
    const classes = {
      loading: this.loading,
    };

    return (
      <div className={classList('LinkPreview', classes)}>
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
          <div className="LinkPreview-description">{this.loading ? '' : (this.data?.description ?? '')}</div>
          <div className="LinkPreview-domain">
            {this.useGoogleFavicons ? <img src={this.getFavicon()} data-link-preview /> : icon('fas fa-external-link-alt')}
            {this.getLink(this.data?.site_name)}
          </div>
        </div>
      </div>
    );
  }

  oncreate(vnode: Mithril.VnodeDOM<LinkPreviewAttrs, this>) {
    if (this.link.parentNode) {
      this.link.parentNode.insertBefore(vnode.dom, this.link);
    }
  }

  getLink(text?: string) {
    return (
      <a {...this.linkAttributes} className={classList('LinkPreview-link', this.linkClasses)}>
        {this.loading ? this.getDomain() : (text ?? this.getDomain())}
      </a>
    );
  }

  getHref() {
    return this.link.href;
  }

  getDomain() {
    return this.getHref().split('/')[2];
  }

  getImage() {
    return this.data?.image || this.getFavicon();
  }

  getFavicon() {
    return this.useGoogleFavicons ? `https://www.google.com/s2/favicons?sz=64&domain_url=${this.getDomain()}` : null;
  }

  fetchData() {
    batchManager.add(this.getHref(), (data: LinkPreviewData) => {
      this.setData(data);
      this.loading = false;
      m.redraw();
    });
  }

  setData(data: LinkPreviewData) {
    this.data = data;
    m.redraw();
  }
}
