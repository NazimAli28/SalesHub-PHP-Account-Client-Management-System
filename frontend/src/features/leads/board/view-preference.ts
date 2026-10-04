import { useCallback } from 'react'
import { useSearchParams } from 'react-router'

export type LeadsView = 'table' | 'board'

const STORAGE_KEY = 'saleshub.leads.view'

function readStored(): LeadsView | null {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY)
    return value === 'board' || value === 'table' ? value : null
  } catch {
    return null
  }
}

function store(view: LeadsView): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, view)
  } catch {
    // Private mode or blocked storage: the URL still carries the choice.
  }
}

/** Table/board choice: `?view=` wins, then the last choice remembered in localStorage. */
export function useLeadsView(): [LeadsView, (view: LeadsView) => void] {
  const [searchParams, setSearchParams] = useSearchParams()
  const fromUrl = searchParams.get('view')
  const view: LeadsView =
    fromUrl === 'board' || fromUrl === 'table' ? fromUrl : (readStored() ?? 'table')

  const setView = useCallback(
    (next: LeadsView) => {
      store(next)
      setSearchParams(
        (current) => {
          const params = new URLSearchParams(current)
          params.set('view', next)
          // Page numbers belong to the table.
          params.delete('page')
          return params
        },
        { preventScrollReset: true },
      )
    },
    [setSearchParams],
  )

  return [view, setView]
}
