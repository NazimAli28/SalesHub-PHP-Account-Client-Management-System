import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Activity } from '../api'
import {
  changeRows,
  displayValue,
  extraDetails,
  humanizeEvent,
  humanizeKey,
  isRedacted,
} from '../format'

function Value({ value, present = true }: { value: unknown; present?: boolean }) {
  if (!present) return <span className="text-muted-foreground">—</span>
  return (
    <span
      className={cn(
        'break-words',
        isRedacted(value) && 'text-muted-foreground italic',
        (value === null || value === undefined || value === '') && 'text-muted-foreground',
      )}
    >
      {displayValue(value)}
    </span>
  )
}

/** Side sheet with who/what/when and the changed attributes as an old -> new table. */
export function ActivityDetailSheet({
  activity,
  onClose,
}: {
  activity: Activity | null
  onClose: () => void
}) {
  const rows = activity ? changeRows(activity) : []
  const details = activity ? extraDetails(activity.properties) : []

  return (
    <Sheet open={activity !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
        <SheetHeader className="border-b">
          <SheetTitle>{activity ? humanizeEvent(activity.event) : 'Activity'}</SheetTitle>
          <SheetDescription>
            {activity
              ? `${formatDateTime(activity.created_at)} · ${activity.causer?.name ?? 'System'}`
              : null}
          </SheetDescription>
        </SheetHeader>
        {activity ? (
          <div className="space-y-6 p-4">
            <dl className="grid grid-cols-[7rem_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">Description</dt>
              <dd>{activity.description}</dd>
              <dt className="text-muted-foreground">Log</dt>
              <dd>{activity.log_name ? humanizeKey(activity.log_name) : '—'}</dd>
              <dt className="text-muted-foreground">Subject</dt>
              <dd>
                {activity.subject
                  ? `${humanizeKey(activity.subject.type ?? 'record')} #${activity.subject.id}${
                      activity.subject.label ? ` · ${activity.subject.label}` : ''
                    }`
                  : '—'}
              </dd>
            </dl>

            <section aria-labelledby="audit-changes">
              <h3 id="audit-changes" className="mb-2 text-sm font-medium">
                Changes
              </h3>
              {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">No attribute changes were recorded.</p>
              ) : (
                <div className="rounded-lg border">
                  <Table aria-label="Changed attributes">
                    <TableHeader>
                      <TableRow>
                        <TableHead>Attribute</TableHead>
                        <TableHead>Old</TableHead>
                        <TableHead>New</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {rows.map((row) => (
                        <TableRow key={row.attribute}>
                          <TableCell className="font-medium">
                            {humanizeKey(row.attribute)}
                          </TableCell>
                          <TableCell className="whitespace-normal">
                            <Value value={row.old} present={row.hasOld} />
                          </TableCell>
                          <TableCell className="whitespace-normal">
                            <Value value={row.next} present={row.hasNext} />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </section>

            {details.length > 0 ? (
              <section aria-labelledby="audit-details">
                <h3 id="audit-details" className="mb-2 text-sm font-medium">
                  Other details
                </h3>
                <dl className="grid grid-cols-[7rem_1fr] gap-x-4 gap-y-2 text-sm">
                  {details.map(([key, value]) => (
                    <div key={key} className="contents">
                      <dt className="text-muted-foreground">{humanizeKey(key)}</dt>
                      <dd>
                        <Value value={value} />
                      </dd>
                    </div>
                  ))}
                </dl>
              </section>
            ) : null}
          </div>
        ) : null}
      </SheetContent>
    </Sheet>
  )
}
