import { HistoryIcon, StickyNoteIcon } from 'lucide-react'
import { Link } from 'react-router'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { cn } from '@/lib/utils'
import { useClientTimeline } from '../notes-api'

/** Newest-first activity for the client, its leads, orders and payments, merged with notes. */
export function ClientTimeline({ clientId, enabled }: { clientId: number; enabled: boolean }) {
  const timeline = useClientTimeline(clientId, { enabled })
  const items = timeline.data?.pages.flatMap((page) => page.data) ?? []

  if (timeline.isPending) return <CardSkeleton />
  if (timeline.isError) {
    return <ErrorState error={timeline.error} onRetry={() => void timeline.refetch()} />
  }
  if (items.length === 0) {
    return (
      <EmptyState
        icon={HistoryIcon}
        title="No activity yet"
        description="Changes to this client, its leads, orders and payments show up here."
      />
    )
  }

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <ol className="space-y-4" aria-label="Client activity">
          {items.map((item) => {
            const Icon = item.kind === 'note' ? StickyNoteIcon : HistoryIcon
            return (
              <li key={item.id} className="flex items-start gap-3">
                <span
                  className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full',
                    item.kind === 'note' ? 'bg-primary/10 text-primary' : 'bg-muted',
                  )}
                >
                  <Icon className="size-3.5" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1 space-y-0.5">
                  <p className="text-sm break-words">
                    {item.link ? (
                      <Link to={item.link} className="hover:underline focus-visible:underline">
                        {item.summary}
                      </Link>
                    ) : (
                      item.summary
                    )}
                  </p>
                  <p className="text-muted-foreground text-xs">
                    {item.actor ? `${item.actor.name} · ` : null}
                    <RelativeTime value={item.at} />
                  </p>
                </div>
              </li>
            )
          })}
        </ol>
      </Card>
      {timeline.hasNextPage ? (
        <div className="flex justify-center">
          <Button
            variant="outline"
            disabled={timeline.isFetchingNextPage}
            onClick={() => void timeline.fetchNextPage()}
          >
            {timeline.isFetchingNextPage ? <Spinner /> : null}
            Load more
          </Button>
        </div>
      ) : null}
    </div>
  )
}
