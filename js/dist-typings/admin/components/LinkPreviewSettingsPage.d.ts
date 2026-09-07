import type { SaveSubmitEvent } from 'flarum/admin/components/AdminPage';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
type FieldOptions = {
    type: string;
    [attr: string]: unknown;
};
export default class LinkPreviewSettingsPage extends ExtensionPage {
    content(): JSX.Element;
    saveSettings(e: SaveSubmitEvent): Promise<void>;
    protected clampNumber(name: string): void;
    protected field(name: string, options: FieldOptions): Mithril.Children;
    settingSections(): ItemList<Mithril.Children>;
}
export {};
