interface MithrilQueryInstance {
  rootEl: HTMLElement;
  trigger(selector: string, eventName: string, event?: Partial<MouseEvent>, silent?: boolean): void;
}
