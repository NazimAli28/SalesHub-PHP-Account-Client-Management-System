import { forwardRef, useState, type HTMLAttributes } from 'react'
import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { ClockIcon, GripVerticalIcon, MoreHorizontalIcon } from 'lucide-react'
import type { Lead, LeadStage } from '@/api/types'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { leadName } from './board-api'
import { leadStages } from '@/lib/enums'
import { initials, toIsoDate } from '@/lib/format'
import { cn } from '@/lib/utils'

interface LeadCardBodyProps extends HTMLAttributes<HTMLDivElement> {
  lead: Lead
  /** Opens the lead's edit sheet; omit for read-only users. */
  onOpen?: (lead: Lead) => void
  /** The "Move to…" menu is shown only when set. */
  onMove?: (lead: Lead, stage: LeadStage) => void
  /** Drag handle props from dnd-kit; the handle is shown only when set. */
  handleProps?: HTMLAttributes<HTMLButtonElement>
  dragging?: boolean
}

export const LeadCardBody = forwardRef<HTMLDivElement, LeadCardBodyProps>(function LeadCardBody(
  { lead, onOpen, onMove, handleProps, dragging, className, ...props },
  ref,
) {
  const name = leadName(lead)
  const pending = lead.pending_change !== null
  const [today] = useState(() => toIsoDate(new Date()))
  const overdue = lead.next_follow_up_on !== null && lead.next_follow_up_on < today

  return (
    <div
      ref={ref}
      data-testid={`lead-card-${lead.id}`}
      className={cn(
        'bg-card text-card-foreground flex gap-1 rounded-lg border p-2.5 shadow-xs',
        dragging && 'ring-ring/60 shadow-md ring-2',
        className,
      )}
      {...props}
    >
      {handleProps ? (
        <button
          type="button"
          aria-label={`Drag ${name}`}
          className="text-muted-foreground hover:text-foreground focus-visible:ring-ring/60 -ml-1 h-fit cursor-grab touch-none rounded p-0.5 outline-none focus-visible:ring-2 active:cursor-grabbing"
          {...handleProps}
        >
          <GripVerticalIcon className="size-4" aria-hidden="true" />
        </button>
      ) : null}

      <div className="min-w-0 flex-1 space-y-2">
        <div className="flex items-start justify-between gap-1">
          {onOpen ? (
            <button
              type="button"
              onClick={() => onOpen(lead)}
              className="focus-visible:ring-ring/60 min-w-0 rounded text-left text-sm font-medium outline-none hover:underline focus-visible:ring-2"
            >
              <span className="block truncate">{name}</span>
            </button>
          ) : (
            <span className="truncate text-sm font-medium">{name}</span>
          )}
          {onMove ? <MoveMenu lead={lead} name={name} onMove={onMove} disabled={pending} /> : null}
        </div>

        {lead.client ? (
          <p className="text-muted-foreground truncate text-xs">@{lead.client.discord_username}</p>
        ) : null}

        <div className="flex flex-wrap items-center gap-1.5">
          {lead.estimated_value ? (
            <span className="text-sm font-medium tabular-nums">
              {lead.estimated_value.formatted}
            </span>
          ) : null}
          {pending ? (
            <Badge variant="outline" className="gap-1 text-amber-700 dark:text-amber-400">
              <ClockIcon className="size-3" aria-hidden="true" />
              Pending approval
            </Badge>
          ) : null}
        </div>

        <div className="flex items-center justify-between gap-2 text-xs">
          <span
            className={cn(
              'flex min-w-0 items-center gap-1',
              overdue ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground',
            )}
          >
            <span>Follow-up:</span>
            <RelativeTime value={lead.next_follow_up_on} display="date" placeholder="none" />
            {overdue ? <span className="sr-only">(overdue)</span> : null}
          </span>
          {lead.owner ? (
            <Avatar className="size-6" title={lead.owner.name}>
              <AvatarFallback className="text-[10px]">{initials(lead.owner.name)}</AvatarFallback>
            </Avatar>
          ) : null}
        </div>
      </div>
    </div>
  )
})

function MoveMenu({
  lead,
  name,
  onMove,
  disabled,
}: {
  lead: Lead
  name: string
  onMove: (lead: Lead, stage: LeadStage) => void
  disabled: boolean
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-xs"
          aria-label={`Move ${name} to…`}
          disabled={disabled}
          className="-mt-0.5 -mr-1 shrink-0"
        >
          <MoreHorizontalIcon aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>Move to…</DropdownMenuLabel>
        {leadStages.options
          .filter((option) => option.value !== lead.stage.value)
          .map((option) => (
            <DropdownMenuItem key={option.value} onSelect={() => onMove(lead, option.value)}>
              {option.label}
            </DropdownMenuItem>
          ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

interface SortableLeadCardProps {
  lead: Lead
  onOpen?: (lead: Lead) => void
  onMove?: (lead: Lead, stage: LeadStage) => void
}

/** A card that can be dragged (pointer or keyboard) by its grip handle. */
export function SortableLeadCard({ lead, onOpen, onMove }: SortableLeadCardProps) {
  const draggable = onMove !== undefined && lead.pending_change === null
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: lead.id,
    disabled: !draggable,
  })

  return (
    <LeadCardBody
      ref={setNodeRef}
      lead={lead}
      onOpen={onOpen}
      onMove={onMove}
      handleProps={draggable ? { ...attributes, ...listeners } : undefined}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(isDragging && 'opacity-40')}
    />
  )
}
