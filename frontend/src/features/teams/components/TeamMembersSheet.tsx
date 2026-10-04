import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { ErrorState } from '@/components/layout/ErrorState'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { initials } from '@/lib/format'
import { useTeam, type Team } from '../api'

interface TeamMembersSheetProps {
  /** The team to show; `null` closes the sheet. */
  team: Team | null
  onClose: () => void
}

/** Lists the people on a team (the team detail endpoint always includes `members`). */
export function TeamMembersSheet({ team, onClose }: TeamMembersSheetProps) {
  const detail = useTeam(team?.id ?? null)
  const members = detail.data?.members ?? []

  return (
    <Sheet open={team !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader className="border-b">
          <SheetTitle>{team ? `${team.name} members` : 'Members'}</SheetTitle>
          <SheetDescription>
            {team ? `Floor ${team.floor} · ${team.shift?.label ?? 'No shift'}` : null}
          </SheetDescription>
        </SheetHeader>
        <div className="flex-1 overflow-y-auto p-4">
          {detail.isPending ? (
            <div className="space-y-3" aria-busy="true">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : detail.isError ? (
            <ErrorState error={detail.error} onRetry={() => void detail.refetch()} />
          ) : members.length === 0 ? (
            <p className="text-muted-foreground text-sm">This team has no members yet.</p>
          ) : (
            <ul className="divide-y" aria-label="Team members">
              {members.map((member) => (
                <li key={member.id} className="flex items-center gap-3 py-2.5">
                  <Avatar size="sm">
                    <AvatarFallback>{initials(member.name)}</AvatarFallback>
                  </Avatar>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{member.name}</p>
                    {member.username ? (
                      <p className="text-muted-foreground truncate text-xs">@{member.username}</p>
                    ) : null}
                  </div>
                  {member.id === detail.data?.team_lead_id ? (
                    <span className="text-muted-foreground text-xs">Team lead</span>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </div>
      </SheetContent>
    </Sheet>
  )
}
