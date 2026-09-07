import '@testing-library/jest-dom/vitest'

/**
 * jsdom does not implement `<dialog>`'s modal methods.
 *
 * The portal uses the native element deliberately — the browser owns the focus
 * trap, the inert background, Escape, and the accessibility tree, and those are
 * the four things a hand-rolled overlay gets subtly wrong. jsdom parses the
 * element but leaves showModal() and close() undefined, so a component test
 * that opens one throws.
 *
 * This fills in only what the tests need: the `open` attribute, which is what
 * `role="dialog"` visibility is derived from, and the cancel event that Escape
 * dispatches. It is deliberately not a full polyfill — the real behaviour is
 * asserted in the browser suite against Chromium, and a rich fake here would
 * only prove that the fake works.
 */
// Tested with `in` rather than against undefined: the DOM lib declares
// showModal as always present, so comparing it is a condition the type system
// considers impossible — while jsdom is precisely the environment where it is
// absent, which is why this file exists.
if (! ('showModal' in HTMLDialogElement.prototype)) {
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.open = true
  }

  HTMLDialogElement.prototype.show = function show(this: HTMLDialogElement) {
    this.open = true
  }

  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement, returnValue?: string) {
    this.open = false

    if (returnValue !== undefined) this.returnValue = returnValue

    this.dispatchEvent(new Event('close'))
  }
}
