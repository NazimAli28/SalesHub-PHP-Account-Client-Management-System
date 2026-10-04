import { useState } from 'react'
import {
  closestCorners,
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type Announcements,
  type DragEndEvent,
  type DragStartEvent,
  type UniqueIdentifier,
} from '@dnd-kit/core'
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Lead, LeadStage } from '@/api/types'
import { useAuth } from '@/features/auth/AuthProvider'
import { leadStages } from '@/lib/enums'
import {
  BOARD_STAGES,
  findBoardLead,
  leadName,
  useMoveLeadStage,
  type BoardFilters,
} from './board-api'
import { LeadCardBody } from './LeadCard'
import { LeadBoardColumn } from './LeadBoardColumn'
import { LostReasonDialog } from './LostReasonDialog'
import { WonOrderDialog } from './WonOrderDialog'

const stageLabel = (stage: LeadStage) =>
  leadStages.options.find((option) => option.value === stage)?.label ?? stage

interface LeadBoardProps {
  filters: BoardFilters
  onOpen?: (lead: Lead) => void
}

/**
 * Kanban board. Drag a card's grip handle (pointer, or Space/Enter then arrow keys), or use
 * the card's "Move to…" menu. Users with neither leads.update nor leads.request-change get a
 * read-only board.
 */
export function LeadBoard({ filters, onOpen }: LeadBoardProps) {
  const { canAny } = useAuth()
  const canMove = canAny(['leads.update', 'leads.request-change'])
  const queryClient = useQueryClient()
  const move = useMoveLeadStage()
  const [activeId, setActiveId] = useState<number | null>(null)
  const [lostFor, setLostFor] = useState<Lead | null>(null)
  const [wonFor, setWonFor] = useState<Lead | null>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const requestMove = (lead: Lead, stage: LeadStage) => {
    if (stage === lead.stage.value) return
    if (lead.pending_change) {
      toast.info('This lead already has a change waiting for approval.')
      return
    }
    if (stage === 'lost') setLostFor(lead)
    else if (stage === 'won') setWonFor(lead)
    else move.mutate({ lead, stage })
  }

  const stageOf = (id: UniqueIdentifier | undefined): LeadStage | undefined => {
    if (id === undefined) return undefined
    if (typeof id === 'string' && id.startsWith('column:')) return id.slice(7) as LeadStage
    return findBoardLead(queryClient, Number(id))?.stage.value
  }

  const announcements: Announcements = {
    onDragStart: ({ active }) => {
      const lead = findBoardLead(queryClient, Number(active.id))
      return lead ? `Picked up ${leadName(lead)} from ${lead.stage.label}.` : undefined
    },
    onDragOver: ({ active, over }) => {
      const lead = findBoardLead(queryClient, Number(active.id))
      const stage = stageOf(over?.id)
      return lead && stage ? `${leadName(lead)} is over ${stageLabel(stage)}.` : undefined
    },
    onDragEnd: ({ active, over }) => {
      const lead = findBoardLead(queryClient, Number(active.id))
      const stage = stageOf(over?.id)
      if (!lead) return undefined
      return stage && stage !== lead.stage.value
        ? `Dropped ${leadName(lead)} in ${stageLabel(stage)}.`
        : `${leadName(lead)} was not moved.`
    },
    onDragCancel: ({ active }) => {
      const lead = findBoardLead(queryClient, Number(active.id))
      return lead ? `Move cancelled. ${leadName(lead)} stays in ${lead.stage.label}.` : undefined
    },
  }

  const handleDragStart = (event: DragStartEvent) => setActiveId(Number(event.active.id))
  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    setActiveId(null)
    const lead = findBoardLead(queryClient, Number(active.id))
    const stage = stageOf(over?.id)
    if (lead && stage) requestMove(lead, stage)
  }

  const activeLead = activeId === null ? undefined : findBoardLead(queryClient, activeId)

  return (
    <>
      {!canMove ? (
        <p className="text-muted-foreground mb-3 text-sm">
          You have view-only access to the pipeline, so cards cannot be moved.
        </p>
      ) : null}

      <DndContext
        sensors={sensors}
        collisionDetection={closestCorners}
        accessibility={{
          announcements,
          screenReaderInstructions: {
            draggable:
              'To pick up a lead, press space or enter. Use the arrow keys to move it to another stage, then press space or enter to drop it, or escape to cancel.',
          },
        }}
        onDragStart={handleDragStart}
        onDragEnd={handleDragEnd}
        onDragCancel={() => setActiveId(null)}
      >
        <div
          role="group"
          aria-label="Leads pipeline"
          className="flex items-start gap-3 overflow-x-auto pb-3"
        >
          {BOARD_STAGES.map((stage) => (
            <LeadBoardColumn
              key={stage}
              stage={stage}
              label={stageLabel(stage)}
              filters={filters}
              onOpen={onOpen}
              onMove={canMove ? requestMove : undefined}
            />
          ))}
        </div>
        <DragOverlay>{activeLead ? <LeadCardBody lead={activeLead} dragging /> : null}</DragOverlay>
      </DndContext>

      <LostReasonDialog
        lead={lostFor}
        onCancel={() => setLostFor(null)}
        onConfirm={(lead, lost_reason, lost_note) => {
          setLostFor(null)
          move.mutate({ lead, stage: 'lost', lost_reason, lost_note })
        }}
      />
      <WonOrderDialog
        lead={wonFor}
        onCancel={() => setWonFor(null)}
        onConfirm={(lead, order_id) => {
          setWonFor(null)
          move.mutate({ lead, stage: 'won', order_id })
        }}
      />
    </>
  )
}
