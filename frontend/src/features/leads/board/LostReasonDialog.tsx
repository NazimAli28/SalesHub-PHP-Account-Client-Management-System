import { useState } from 'react'
import type { Lead, LeadLostReason } from '@/api/types'
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
import { Textarea } from '@/components/ui/textarea'
import { leadLostReasons } from '@/lib/enums'

interface LostReasonDialogProps {
  lead: Lead | null
  onCancel: () => void
  onConfirm: (lead: Lead, reason: LeadLostReason, note: string) => void
}

export function LostReasonDialog({ lead, onCancel, onConfirm }: LostReasonDialogProps) {
  return (
    <Dialog open={lead !== null} onOpenChange={(open) => (open ? undefined : onCancel())}>
      <DialogContent>
        {/* Remounted per lead so the fields start empty. */}
        {lead ? (
          <LostReasonForm key={lead.id} lead={lead} onCancel={onCancel} onConfirm={onConfirm} />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function LostReasonForm({
  lead,
  onCancel,
  onConfirm,
}: {
  lead: Lead
  onCancel: () => void
  onConfirm: LostReasonDialogProps['onConfirm']
}) {
  const [reason, setReason] = useState<LeadLostReason | ''>('')
  const [note, setNote] = useState('')
  const [touched, setTouched] = useState(false)
  const name = lead.client?.name ?? lead.client?.discord_username ?? `Lead #${lead.id}`

  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault()
        setTouched(true)
        if (reason) onConfirm(lead, reason, note)
      }}
    >
      <DialogHeader>
        <DialogTitle>Mark as lost</DialogTitle>
        <DialogDescription>Why did we lose the lead for {name}?</DialogDescription>
      </DialogHeader>

      <div className="space-y-1.5">
        <Label htmlFor="lost-reason">Lost reason</Label>
        <select
          id="lost-reason"
          value={reason}
          onChange={(event) => setReason(event.target.value as LeadLostReason | '')}
          aria-invalid={touched && !reason}
          aria-describedby={touched && !reason ? 'lost-reason-error' : undefined}
          className="border-input bg-background focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
        >
          <option value="">Choose a reason…</option>
          {leadLostReasons.options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        {touched && !reason ? (
          <p id="lost-reason-error" role="alert" className="text-destructive text-sm">
            A lost reason is required.
          </p>
        ) : null}
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="lost-note">Note (optional)</Label>
        <Textarea
          id="lost-note"
          value={note}
          maxLength={2000}
          onChange={(event) => setNote(event.target.value)}
        />
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" variant="destructive">
          Mark as lost
        </Button>
      </DialogFooter>
    </form>
  )
}
