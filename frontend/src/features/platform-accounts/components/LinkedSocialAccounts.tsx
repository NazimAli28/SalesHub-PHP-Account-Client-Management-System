import { useState } from 'react'
import { KeyRoundIcon, PencilIcon, PlusIcon, ShareIcon } from 'lucide-react'
import { Link } from 'react-router'
import { paths } from '@/app/paths'
import { EmptyState } from '@/components/layout/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useAuth } from '@/features/auth/AuthProvider'
import { RevealSocialPasswordDialog } from '@/features/social-accounts/components/RevealSocialPasswordDialog'
import { SocialAccountFormSheet } from '@/features/social-accounts/components/SocialAccountFormSheet'
import type { SocialAccount } from '@/features/social-accounts/types'
import type { PlatformAccount } from '../types'

/**
 * Social accounts registered under one platform account, each with its own reveal
 * (`social-accounts.reveal-credentials`), plus add/edit when the user may.
 */
export function LinkedSocialAccounts({ account }: { account: PlatformAccount }) {
  const { can, canAny } = useAuth()
  const socials = account.social_accounts ?? []
  const [sheet, setSheet] = useState<{ open: boolean; social: SocialAccount | null }>({
    open: false,
    social: null,
  })
  const [revealing, setRevealing] = useState<SocialAccount | null>(null)

  const canCreate = can('social-accounts.create')
  const canEdit = canAny(['social-accounts.update', 'social-accounts.request-change'])
  const canReveal = can('social-accounts.reveal-credentials')

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <ShareIcon className="size-4" aria-hidden="true" />
          Social accounts
        </CardTitle>
        <CardDescription>Profiles registered under this platform account.</CardDescription>
        <CardAction className="flex gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link to={`${paths.socialAccounts}?platform_account=${account.id}`}>View in list</Link>
          </Button>
          {canCreate ? (
            <Button size="sm" onClick={() => setSheet({ open: true, social: null })}>
              <PlusIcon aria-hidden="true" />
              Add
            </Button>
          ) : null}
        </CardAction>
      </CardHeader>
      <CardContent>
        {socials.length === 0 ? (
          <EmptyState
            icon={ShareIcon}
            title="No social accounts"
            description="Nothing is registered under this platform account yet."
          />
        ) : (
          <Table aria-label="Linked social accounts">
            <TableHeader>
              <TableRow>
                <TableHead>Username</TableHead>
                <TableHead>Platform</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {socials.map((social) => (
                <TableRow key={social.id}>
                  <TableCell className="font-medium">@{social.username}</TableCell>
                  <TableCell>
                    <Badge variant="secondary">{social.platform.label}</Badge>
                  </TableCell>
                  <TableCell>
                    {social.is_in_use ? (
                      <Badge>In use</Badge>
                    ) : (
                      <span className="text-muted-foreground text-sm">Available</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      {canReveal ? (
                        <Button
                          variant="outline"
                          size="sm"
                          aria-label={`Reveal password for @${social.username}`}
                          onClick={() => setRevealing(social)}
                        >
                          <KeyRoundIcon aria-hidden="true" />
                          Reveal
                        </Button>
                      ) : null}
                      {canEdit ? (
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Edit @${social.username}`}
                          disabled={Boolean(social.pending_change)}
                          onClick={() => setSheet({ open: true, social })}
                        >
                          <PencilIcon aria-hidden="true" />
                        </Button>
                      ) : null}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>

      <SocialAccountFormSheet
        open={sheet.open}
        account={sheet.social}
        defaultPlatformAccount={{ id: account.id, label: account.email }}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
      {canReveal ? (
        <RevealSocialPasswordDialog
          account={revealing}
          open={revealing !== null}
          onOpenChange={(open) => {
            if (!open) setRevealing(null)
          }}
        />
      ) : null}
    </Card>
  )
}
