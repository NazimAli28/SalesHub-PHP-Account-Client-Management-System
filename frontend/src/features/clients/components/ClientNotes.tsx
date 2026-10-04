import { useState, type FormEvent } from 'react'
import { PencilIcon, PinIcon, PinOffIcon, StickyNoteIcon, Trash2Icon } from 'lucide-react'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import {
  NOTE_MAX_LENGTH,
  useAddClientNote,
  useClientNotes,
  useDeleteClientNote,
  useUpdateClientNote,
  type ClientNote,
} from '../notes-api'

/** Composer plus the list of notes on a client. Pinned notes come first. */
export function ClientNotes({ clientId }: { clientId: number }) {
  const notes = useClientNotes(clientId)
  const items = notes.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <div className="space-y-4">
      <NoteComposer clientId={clientId} />
      {notes.isPending ? (
        <CardSkeleton />
      ) : notes.isError ? (
        <ErrorState error={notes.error} onRetry={() => void notes.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={StickyNoteIcon}
          title="No notes yet"
          description="Write down what you learn about this client so the team can pick it up."
        />
      ) : (
        <ul className="space-y-3" aria-label="Client notes">
          {items.map((note) => (
            <NoteItem key={note.id} clientId={clientId} note={note} />
          ))}
        </ul>
      )}
      {notes.hasNextPage ? (
        <div className="flex justify-center">
          <Button
            variant="outline"
            disabled={notes.isFetchingNextPage}
            onClick={() => void notes.fetchNextPage()}
          >
            {notes.isFetchingNextPage ? <Spinner /> : null}
            Load more
          </Button>
        </div>
      ) : null}
    </div>
  )
}

function NoteComposer({ clientId }: { clientId: number }) {
  const [body, setBody] = useState('')
  const add = useAddClientNote(clientId)
  const trimmed = body.trim()

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!trimmed) return
    add.mutate({ body: trimmed }, { onSuccess: () => setBody('') })
  }

  return (
    <Card className="p-4">
      <form onSubmit={submit} className="space-y-3">
        <label htmlFor="client-note-body" className="sr-only">
          New note
        </label>
        <Textarea
          id="client-note-body"
          value={body}
          onChange={(event) => setBody(event.target.value)}
          maxLength={NOTE_MAX_LENGTH}
          rows={3}
          placeholder="Write a note about this client…"
        />
        <div className="flex items-center justify-between gap-3">
          <span className="text-muted-foreground text-xs">
            {body.length} / {NOTE_MAX_LENGTH}
          </span>
          <Button type="submit" disabled={!trimmed || add.isPending}>
            {add.isPending ? <Spinner /> : null}
            Add note
          </Button>
        </div>
      </form>
    </Card>
  )
}

function NoteItem({ clientId, note }: { clientId: number; note: ClientNote }) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(note.body)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const update = useUpdateClientNote(clientId)
  const remove = useDeleteClientNote(clientId)
  const author = note.author?.name ?? 'Unknown'

  const save = (event: FormEvent) => {
    event.preventDefault()
    const body = draft.trim()
    if (!body || body === note.body) return setEditing(false)
    update.mutate({ id: note.id, body }, { onSuccess: () => setEditing(false) })
  }

  return (
    <li>
      <Card className={cn('p-4', note.is_pinned && 'border-primary/40 bg-primary/5')}>
        <div className="flex items-start justify-between gap-3">
          <p className="text-muted-foreground text-xs">
            {note.is_pinned ? (
              <span className="text-primary mr-1.5 inline-flex items-center gap-1 font-medium">
                <PinIcon className="size-3" aria-hidden="true" />
                Pinned
              </span>
            ) : null}
            <span className="text-foreground font-medium">{author}</span> ·{' '}
            <RelativeTime value={note.created_at} />
          </p>
          <div className="-mt-1 -mr-1 flex shrink-0 gap-0.5">
            <Button
              variant="ghost"
              size="icon-sm"
              disabled={update.isPending}
              aria-label={note.is_pinned ? 'Unpin note' : 'Pin note'}
              onClick={() => update.mutate({ id: note.id, is_pinned: !note.is_pinned })}
            >
              {note.is_pinned ? <PinOffIcon aria-hidden="true" /> : <PinIcon aria-hidden="true" />}
            </Button>
            {note.can_edit ? (
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Edit note"
                onClick={() => {
                  setDraft(note.body)
                  setEditing(true)
                }}
              >
                <PencilIcon aria-hidden="true" />
              </Button>
            ) : null}
            {note.can_delete ? (
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Delete note"
                onClick={() => setConfirmDelete(true)}
              >
                <Trash2Icon aria-hidden="true" />
              </Button>
            ) : null}
          </div>
        </div>

        {editing ? (
          <form onSubmit={save} className="mt-2 space-y-2">
            <label htmlFor={`edit-note-${note.id}`} className="sr-only">
              Note text
            </label>
            <Textarea
              id={`edit-note-${note.id}`}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              maxLength={NOTE_MAX_LENGTH}
              rows={3}
            />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" size="sm" onClick={() => setEditing(false)}>
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={!draft.trim() || update.isPending}>
                {update.isPending ? <Spinner /> : null}
                Save
              </Button>
            </div>
          </form>
        ) : (
          <p className="mt-2 text-sm break-words whitespace-pre-wrap">{note.body}</p>
        )}
      </Card>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this note?"
        description="The note is removed for everyone who can see this client."
        confirmLabel="Delete note"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(note.id)}
      />
    </li>
  )
}
