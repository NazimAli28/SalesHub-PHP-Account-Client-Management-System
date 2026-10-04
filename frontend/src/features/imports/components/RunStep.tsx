import { useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Spinner } from '@/components/ui/spinner'
import { formatNumber } from '@/lib/format'
import { useImport, useStartImport } from '../api'
import type { ColumnMapping, ImportRecord, UploadedImport } from '../types'

interface RunStepProps {
  upload: UploadedImport
  mapping: ColumnMapping
  onBack: () => void
  /** Called once the import has completed or failed. */
  onFinished: (record: ImportRecord) => void
}

/** Starts the import, then follows its progress until it is done. */
export function RunStep({ upload, mapping, onBack, onFinished }: RunStepProps) {
  const start = useStartImport()
  const progress = useImport(start.isSuccess ? upload.id : null)
  const record = progress.data ?? start.data
  const status = record?.status.value

  useEffect(() => {
    if (record && (status === 'completed' || status === 'failed')) onFinished(record)
    // Only the status decides; the record object changes on every poll.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status])

  const total = record?.total_rows ?? upload.total_rows
  const processed = record?.processed_rows ?? 0
  const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0
  const running = start.isSuccess

  return (
    <Card>
      <CardHeader>
        <CardTitle>{running ? 'Importing…' : 'Ready to import'}</CardTitle>
        <CardDescription>
          {running
            ? 'This runs in the background. You can keep this page open to follow it.'
            : `${formatNumber(upload.total_rows)} rows from ${upload.original_filename} will be imported as ${upload.type.label.toLowerCase()}. Rows with problems are skipped and listed afterwards.`}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {running ? (
          <div className="space-y-2">
            <Progress value={percent} aria-label="Import progress" />
            <p className="text-muted-foreground text-sm" role="status">
              {formatNumber(processed)} of {formatNumber(total)} rows processed
              {record ? ` (${record.status.label})` : ''}
            </p>
          </div>
        ) : null}

        <div className="flex justify-between gap-3">
          <Button type="button" variant="outline" onClick={onBack} disabled={running}>
            Back
          </Button>
          <Button
            type="button"
            disabled={start.isPending || running}
            onClick={() => start.mutate({ id: upload.id, mapping })}
          >
            {start.isPending || running ? <Spinner /> : null}
            Start import
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
