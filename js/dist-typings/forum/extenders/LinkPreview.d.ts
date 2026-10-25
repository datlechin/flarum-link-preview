import type IExtender from 'flarum/common/extenders/IExtender';
import type { IExtensionModule } from 'flarum/common/extenders/IExtender';
import type ForumApplication from 'flarum/forum/ForumApplication';
import type { ContentBodies, WatchedComponent } from '../utils/watchContent';
export default class LinkPreview implements IExtender<ForumApplication> {
    private contents;
    content(component: WatchedComponent, bodies: string | ContentBodies): this;
    extend(app: ForumApplication, extension: IExtensionModule): void;
}
