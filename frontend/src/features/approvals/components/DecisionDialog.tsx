import { useId, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'

/** The API rejects shorter rejection comments with 422. */
export const MIN_REJECT_COMMENT = 5

interface DecisionDialogProps {
  mode: 'approve' | 'reject'
  open: boolean
  onOpenChange: (open: boolean) => void
  /** What is being decided, e.g. "Update lead #42" or "3 requests". */
  subject: string
  pending?: boolean
  /** Resolves when the decision succeeded (the dialog then closes). */
  onSubmit: (comment: string) => void | Promise<unknown>
}

function DecisionForm({
  mode,
  onOpenChange,
  subject,
  pending = false,
  onSubmit,
}: Omit<DecisionDialogProps, 'open'>) {
  const id = useId()
  const [comment, setComment] = useState('')
  const [error, setError] = useState<string | null>(null)
  const reject = mode === 'reject'

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    const trimmed = comment.trim()
    if (reject && trimmed.length < MIN_REJECT_COMMENT) {
      setError(`Explain the rejection (at least ${MIN_REJECT_COMMENT} characters).`)
      return
    }
    try {
      await onSubmit(trimmed)
      onOpenChange(false)
    } catch {
      // The mutation reports its own error; keep the dialog open.
    }
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <DialogHeader>
        <DialogTitle>{reject ? 'Reject request' : 'Approve request'}</DialogTitle>
        <DialogDescription>
          {reject
            ? `${subject}: the requester sees your comment, so say what needs to change.`
            : `${subject}: the change is applied as soon as you approve.`}
        </DialogDescription>
      </DialogHeader>
      <div className="space-y-2">
        <Label htmlFor={id}>{reject ? 'Comment (required)' : 'Comment (optional)'}</Label>
        <Textarea
          id={id}
          value={comment}
          maxLength={2000}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
          onChange={(event) => {
            setComment(event.target.value)
            if (error) setError(null)
          }}
        />
        {error ? (
          <p id={`${id}-error`} role="alert" className="text-destructive text-sm">
            {error}
          </p>
        ) : null}
      </div>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
          Cancel
        </Button>
        <Button type="submit" variant={reject ? 'destructive' : 'default'} disabled={pending}>
          {pending ? <Spinner /> : null}
          {reject ? 'Reject' : 'Approve'}
        </Button>
      </DialogFooter>
    </form>
  )
}

/** Comment box for approving (optional comment) or rejecting (comment required). */
export function DecisionDialog({ open, onOpenChange, ...rest }: DecisionDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {/* Mounted only while open, so the comment resets every time. */}
        <DecisionForm onOpenChange={onOpenChange} {...rest} />
      </DialogContent>
    </Dialog>
  )
}
