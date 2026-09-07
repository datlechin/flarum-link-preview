// mithril-query ships types narrower than the API it exposes. `rootEl` is real
// and undeclared, `trigger`'s event argument is optional in practice, and a
// click init carries `button`, which a bare `Event` does not accept. Declared
// once here rather than cast at every call.
interface MithrilQueryInstance {
  rootEl: HTMLElement;
  trigger(selector: string, eventName: string, event?: Partial<MouseEvent>, silent?: boolean): void;
}
