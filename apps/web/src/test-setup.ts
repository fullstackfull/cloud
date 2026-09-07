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
/*
 * Written against a widened alias of the prototype. The DOM lib declares these
 * methods as always present, so TypeScript narrows the object to `never`
 * inside any check for their absence — while jsdom is precisely the
 * environment where they are absent, which is why this file exists. The alias
 * says "this is the runtime object, not the declared type" once, rather than
 * casting at each assignment.
 */
const dialogPrototype = HTMLDialogElement.prototype as Partial<HTMLDialogElement>

if (dialogPrototype.showModal === undefined) {
  dialogPrototype.showModal = function showModal(this: HTMLDialogElement) {
    this.open = true
  }

  dialogPrototype.show = function show(this: HTMLDialogElement) {
    this.open = true
  }

  dialogPrototype.close = function close(this: HTMLDialogElement, returnValue?: string) {
    this.open = false

    if (returnValue !== undefined) this.returnValue = returnValue

    this.dispatchEvent(new Event('close'))
  }
}
