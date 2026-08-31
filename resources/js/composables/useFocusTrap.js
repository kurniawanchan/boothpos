import { watch, onBeforeUnmount } from 'vue';

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Minimal focus trap for modals/drawers (accessibility.md — focus
 * management): focuses the first focusable element on open, cycles Tab
 * within the container, and restores focus to the trigger on close.
 */
export function useFocusTrap(containerRef, isActiveRef) {
  let previouslyFocused = null;

  function getFocusable() {
    if (!containerRef.value) return [];
    return Array.from(containerRef.value.querySelectorAll(FOCUSABLE));
  }

  function handleKeydown(e) {
    if (e.key !== 'Tab') return;
    const focusable = getFocusable();
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  watch(
    isActiveRef,
    (active) => {
      if (active) {
        previouslyFocused = document.activeElement;
        requestAnimationFrame(() => {
          const focusable = getFocusable();
          (focusable[0] || containerRef.value)?.focus();
        });
        document.addEventListener('keydown', handleKeydown);
      } else {
        document.removeEventListener('keydown', handleKeydown);
        previouslyFocused?.focus?.();
      }
    },
    { immediate: true }
  );

  onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));
}
