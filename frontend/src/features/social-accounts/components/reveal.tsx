import { useEffect, useState } from 'react'
import { CopyIcon, EyeIcon, EyeOffIcon, LockKeyholeOpenIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { REVEAL_HIDE_AFTER_MS } from './reveal-utils'

export interface RevealEntry {
  key: string
  label: string
  value: string | null
}

async function copyToClipboard(label: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success(`${label} copied`)
  } catch {
    toast.error('Could not copy. Select the value and copy it manually.')
  }
}

function RevealedValue({ entry, scope }: { entry: RevealEntry; scope: string }) {
  const [visible, setVisible] = useState(false)
  const id = `revealed-${scope}-${entry.key}`
  const value = entry.value ?? ''

  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{entry.label}</Label>
      <div className="flex items-center gap-1.5">
        <Input
          id={id}
          readOnly
          type={visible ? 'text' : 'password'}
          value={value}
          placeholder={entry.value === null ? 'Not set' : undefined}
          autoComplete="off"
          spellCheck={false}
          className="font-mono"
        />
        <Button
          type="button"
          variant="outline"
          size="icon"
          aria-label={`${visible ? 'Hide' : 'Show'} ${entry.label}`}
          aria-pressed={visible}
          disabled={entry.value === null}
          onClick={() => setVisible((current) => !current)}
        >
          {visible ? <EyeOffIcon aria-hidden="true" /> : <EyeIcon aria-hidden="true" />}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="icon"
          aria-label={`Copy ${entry.label}`}
          disabled={entry.value === null}
          onClick={() => void copyToClipboard(entry.label, value)}
        >
          <CopyIcon aria-hidden="true" />
        </Button>
      </div>
    </div>
  )
}

/**
 * Revealed values, masked by default, with copy buttons. They hide themselves after
 * `hideAfterMs`; the parent drops the values in `onHide`.
 */
export function RevealedValues({
  entries,
  onHide,
  scope,
  hideAfterMs = REVEAL_HIDE_AFTER_MS,
}: {
  entries: RevealEntry[]
  onHide: () => void
  /** Unique per panel on a page (ids of the inputs). */
  scope: string
  hideAfterMs?: number
}) {
  useEffect(() => {
    const timer = setTimeout(onHide, hideAfterMs)
    return () => clearTimeout(timer)
  }, [onHide, hideAfterMs])

  return (
    <div className="space-y-4" data-testid="revealed-values">
      {entries.map((entry) => (
        <RevealedValue key={entry.key} entry={entry} scope={scope} />
      ))}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
          <LockKeyholeOpenIcon className="size-3.5" aria-hidden="true" />
          Hides automatically in {Math.round(hideAfterMs / 1000)} seconds.
        </p>
        <Button type="button" variant="outline" size="sm" onClick={onHide}>
          Hide now
        </Button>
      </div>
    </div>
  )
}
