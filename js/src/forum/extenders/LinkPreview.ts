import type IExtender from 'flarum/common/extenders/IExtender';
import type { IExtensionModule } from 'flarum/common/extenders/IExtender';
import type ForumApplication from 'flarum/forum/ForumApplication';

import watchContent, { bodiesMatching } from '../utils/watchContent';
import type { ContentBodies, WatchedComponent } from '../utils/watchContent';

export default class LinkPreview implements IExtender<ForumApplication> {
  private contents: Array<[WatchedComponent, ContentBodies]> = [];

  content(component: WatchedComponent, bodies: string | ContentBodies): this {
    this.contents.push([component, typeof bodies === 'string' ? bodiesMatching(bodies) : bodies]);

    return this;
  }

  extend(app: ForumApplication, extension: IExtensionModule): void {
    this.contents.forEach(([component, bodies]) => watchContent(component, bodies));
  }
}
