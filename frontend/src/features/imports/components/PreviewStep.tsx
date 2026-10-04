import { AlertTriangleIcon, CheckCircle2Icon } from 'lucide-react'
import { ErrorState } from '@/components/layout/ErrorState'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { usePreviewImport } from '../api'
import type { ColumnMapping, UploadedImport } from '../types'

interface PreviewStepProps {
  upload: UploadedImport
  mapping: ColumnMapping
  onBack: () => void
  onContinue: () => void
}

/** Checks the first rows with the real create rules and shows what would go wrong. */
export function PreviewStep({ upload, mapping, onBack, onContinue }: PreviewStepProps) {
  const preview = usePreviewImport(upload.id, mapping)
  const labels = Object.fromEntries(upload.fields.map((field) => [field.key, field.label]))
  const mapped = upload.headers.filter((header) => mapping[header])

  return (
    <Card>
      <CardHeader>
        <CardTitle>Check the first rows</CardTitle>
        <CardDescription>
          Nothing is saved yet. Rows with problems are skipped during the import; the rest are
          created.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {preview.isPending ? (
          <div className="space-y-2" aria-busy="true">
            <Skeleton className="h-6 w-64" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : preview.isError ? (
          <ErrorState
            title="Could not check the rows"
            error={preview.error}
            onRetry={() => void preview.refetch()}
          />
        ) : (
          <>
            <p
              className="flex items-center gap-2 text-sm"
              role="status"
              data-testid="preview-summary"
            >
              {preview.data.summary.invalid > 0 ? (
                <AlertTriangleIcon className="text-destructive size-4" aria-hidden="true" />
              ) : (
                <CheckCircle2Icon className="size-4 text-green-600" aria-hidden="true" />
              )}
              <span>
                Checked {preview.data.summary.checked} of {preview.data.summary.total_rows} rows:{' '}
                <strong>{preview.data.summary.valid} ready</strong>,{' '}
                <strong>{preview.data.summary.invalid} with problems</strong>.
              </span>
            </p>

            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-16">Row</TableHead>
                    {mapped.map((header) => (
                      <TableHead key={header}>{labels[mapping[header]!] ?? header}</TableHead>
                    ))}
                    <TableHead>Result</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {preview.data.rows.map((row) => (
                    <TableRow
                      key={row.row}
                      data-invalid={!row.valid || undefined}
                      className={cn(!row.valid && 'bg-destructive/5')}
                    >
                      <TableCell className="text-muted-foreground">{row.row}</TableCell>
                      {mapped.map((header) => {
                        const field = mapping[header]!
                        const failed = (row.errors[field]?.length ?? 0) > 0
                        return (
                          <TableCell
                            key={header}
                            className={cn(
                              'max-w-56 truncate',
                              failed && 'text-destructive font-medium',
                            )}
                          >
                            {row.values[header] || '—'}
                          </TableCell>
                        )
                      })}
                      <TableCell className="min-w-56 text-sm">
                        {row.valid ? (
                          <span className="text-muted-foreground">Ready</span>
                        ) : (
                          <ul className="text-destructive space-y-0.5">
                            {Object.values(row.errors)
                              .flat()
                              .map((message) => (
                                <li key={message}>{message}</li>
                              ))}
                          </ul>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </>
        )}

        <div className="flex justify-between gap-3">
          <Button type="button" variant="outline" onClick={onBack}>
            Back
          </Button>
          <Button
            type="button"
            onClick={onContinue}
            disabled={!preview.isSuccess || preview.data.summary.valid === 0}
          >
            Continue
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
