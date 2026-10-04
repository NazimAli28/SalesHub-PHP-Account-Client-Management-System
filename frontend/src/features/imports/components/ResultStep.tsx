import { Link } from 'react-router'
import { CheckCircle2Icon, DownloadIcon, XCircleIcon } from 'lucide-react'
import { paths } from '@/app/paths'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { formatNumber } from '@/lib/format'
import { downloadImportErrors } from '../download'
import type { ImportRecord } from '../types'

interface ResultStepProps {
  record: ImportRecord
  onAnother: () => void
}

export function ResultStep({ record, onAnother }: ResultStepProps) {
  const failedImport = record.status.value === 'failed'
  const target = record.type.value === 'leads' ? paths.leads : paths.clients
  const shownErrors = (record.errors ?? []).slice(0, 10)

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {failedImport ? (
            <XCircleIcon className="text-destructive size-5" aria-hidden="true" />
          ) : (
            <CheckCircle2Icon className="size-5 text-green-600" aria-hidden="true" />
          )}
          {failedImport ? 'The import stopped early' : 'Import finished'}
        </CardTitle>
        <CardDescription>{record.original_filename}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <dl className="grid grid-cols-3 gap-4 text-center">
          <div>
            <dt className="text-muted-foreground text-sm">Created</dt>
            <dd className="text-2xl font-semibold" data-testid="created-count">
              {formatNumber(record.created_rows)}
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground text-sm">Skipped</dt>
            <dd className="text-2xl font-semibold" data-testid="failed-count">
              {formatNumber(record.failed_rows)}
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground text-sm">Rows in file</dt>
            <dd className="text-2xl font-semibold">{formatNumber(record.total_rows)}</dd>
          </div>
        </dl>

        {shownErrors.length > 0 ? (
          <div className="space-y-2">
            <p className="text-sm font-medium">Why rows were skipped</p>
            <ul className="text-muted-foreground space-y-1 text-sm">
              {shownErrors.map((error, index) => (
                <li key={`${error.row}-${index}`}>
                  Row {error.row}
                  {error.column ? ` (${error.column})` : ''}: {error.message}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        <div className="flex flex-wrap justify-between gap-3">
          <Button type="button" variant="outline" onClick={onAnother}>
            Import another file
          </Button>
          <div className="flex flex-wrap gap-2">
            {record.failed_rows > 0 ? (
              <Button type="button" variant="outline" onClick={() => downloadImportErrors(record)}>
                <DownloadIcon aria-hidden="true" />
                Download failed rows
              </Button>
            ) : null}
            <Button asChild>
              <Link to={target}>View {record.type.label.toLowerCase()}</Link>
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
