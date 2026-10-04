import { useCallback, useMemo, useState } from 'react'
import { PlusIcon, ShareIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Can } from '@/features/auth/Can'
import {
  SOCIAL_ACCOUNT_LIST_CONFIG,
  usePlatformAccountFilterOptions,
  useSocialAccounts,
} from '../api'
import { getSocialAccountColumns } from '../components/social-account-columns'
import { SocialAccountFormSheet } from '../components/SocialAccountFormSheet'
import { SOCIAL_PLATFORM_OPTIONS } from '../platforms'
import type { SocialAccount } from '../types'

const IN_USE_OPTIONS = [
  { value: '1', label: 'In use' },
  { value: '0', label: 'Available' },
]

export default function SocialAccountsPage() {
  const table = useDataTableParams(SOCIAL_ACCOUNT_LIST_CONFIG)
  const accounts = useSocialAccounts(table.params)
  const platformAccounts = usePlatformAccountFilterOptions()

  const [sheet, setSheet] = useState<{ open: boolean; account: SocialAccount | null }>({
    open: false,
    account: null,
  })
  const openCreate = () => setSheet({ open: true, account: null })
  const openEdit = useCallback((account: SocialAccount) => setSheet({ open: true, account }), [])

  const columns = useMemo(() => getSocialAccountColumns({ onEdit: openEdit }), [openEdit])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Social accounts"
        description="Instagram, X, Behance and other profiles registered under platform accounts."
        actions={
          <Can permission="social-accounts.create">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New social account
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Social accounts"
        columns={columns}
        query={accounts}
        state={table}
        searchPlaceholder="Search username, login email or platform account…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="platform"
              title="Platform"
              options={SOCIAL_PLATFORM_OPTIONS}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="in_use"
              title="Usage"
              multiple={false}
              options={IN_USE_OPTIONS}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="platform_account"
              title="Platform account"
              options={platformAccounts.data ?? []}
              isLoading={platformAccounts.isPending}
            />
          </>
        }
        emptyState={
          <EmptyState
            icon={ShareIcon}
            title="No social accounts yet"
            description="Social accounts registered under the platform accounts you can see show up here."
            action={
              <Can permission="social-accounts.create">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New social account
                </Button>
              </Can>
            }
          />
        }
      />

      <SocialAccountFormSheet
        open={sheet.open}
        account={sheet.account}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}
