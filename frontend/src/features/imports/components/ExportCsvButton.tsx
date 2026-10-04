import { useState } from 'react'
import { DownloadIcon } from 'lucide-react'
import { toast } from 'sonner'
import { errorMessage } from '@/api/errors'
import { toApiQuery, type ListParams } from '@/api/list-params'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { Can } from '@/features/auth/Can'
import { downloadFile } from '../download'
import type { ImportTypeKey } from '../types'

interface ExportCsvButtonProps {
  type: ImportTypeKey
  /** The list's current filters, sort and search (from `useDataTableParams`). */
  params: ListParams
}

/**
 * Downloads the rows the user can see, filtered and sorted like the list on screen (all pages,
 * up to 10,000 rows). Shown only to users with `reports.export`.
 */
export function ExportCsvButton({ type, params }: ExportCsvButtonProps) {
  const [busy, setBusy] = useState(false)

  const download = async () => {
    // The export ignores paging: it always starts at the first row.
    const { 'page[number]': _page, 'page[size]': _size, ...query } = toApiQuery(params)
    setBusy(true)
    try {
      await downloadFile(`/exports/${type}`, query, `${type}.csv`)
    } catch (error) {
      toast.error(errorMessage(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Can permission="reports.export">
      <Button type="button" variant="outline" onClick={download} disabled={busy}>
        {busy ? <Spinner /> : <DownloadIcon aria-hidden="true" />}
        Export CSV
      </Button>
    </Can>
  )
}
