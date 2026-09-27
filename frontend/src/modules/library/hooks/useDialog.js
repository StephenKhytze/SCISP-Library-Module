import { useEffect, useRef } from 'react';

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

/**
 * Tracks which dialogs are open, innermost last, so Escape only ever closes the
 * dialog on top. Without this a confirm dialog opened over the loan-details
 * modal would close both at once.
 */
const openDialogs = [];

/**
 * Shared dialog behaviour: Escape to close, focus moved in on open, focus
 * trapped while open, focus restored to whatever opened it on close.
 *
 * Returns a ref to put on the dialog panel (not the backdrop).
 */
export default function useDialog(isOpen, onClose) {
  const ref = useRef(null);

  // Held in a ref so a caller passing an inline arrow function does not make
  // the effect re-run and steal focus back on every render.
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!isOpen) return undefined;

    const token = {};
    openDialogs.push(token);

    const previouslyFocused = document.activeElement;
    const node = ref.current;

    const focusables = node ? node.querySelectorAll(FOCUSABLE) : [];
    if (focusables.length > 0) {
      focusables[0].focus();
    } else if (node) {
      node.setAttribute('tabindex', '-1');
      node.focus();
    }

    const handleKeyDown = (event) => {
      // Only the topmost dialog responds.
      if (openDialogs[openDialogs.length - 1] !== token) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        onCloseRef.current?.();
        return;
      }

      if (event.key !== 'Tab' || !node) return;

      const items = Array.from(node.querySelectorAll(FOCUSABLE));
      if (items.length === 0) return;

      const first = items[0];
      const last = items[items.length - 1];

      if (!node.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);

      const index = openDialogs.indexOf(token);
      if (index > -1) openDialogs.splice(index, 1);

      if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
        previouslyFocused.focus();
      }
    };
  }, [isOpen]);

  return ref;
}
