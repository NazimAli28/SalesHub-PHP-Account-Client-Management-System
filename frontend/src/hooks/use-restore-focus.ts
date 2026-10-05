import { useRef } from 'react'

/**
 * Radix returns focus to a dialog's own trigger when it closes. Most dialogs here are opened from
 * state (a button sets `open`) and have no trigger, so focus would fall back to <body> and
 * keyboard users lose their place. This remembers what had focus when the dialog opened and puts
 * it back on close. Spread the result onto `Dialog.Content` / `Sheet.Content`.
 */
export function useRestoreFocus(handlers: {
  onOpenAutoFocus?: (event: Event) => void
  onCloseAutoFocus?: (event: Event) => void
}) {
  const opener = useRef<HTMLElement | null>(null)

  return {
    onOpenAutoFocus: (event: Event) => {
      const active = document.activeElement
      opener.current = active instanceof HTMLElement && active !== document.body ? active : null
      handlers.onOpenAutoFocus?.(event)
    },
    onCloseAutoFocus: (event: Event) => {
      handlers.onCloseAutoFocus?.(event)
      const target = opener.current
      opener.current = null
      if (!event.defaultPrevented && target?.isConnected) {
        // Skip Radix's default (it only knows about a trigger) and restore the opener instead.
        event.preventDefault()
        target.focus()
      }
    },
  }
}
