import { DownloadIcon } from 'lucide-react'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useImports } from '../api'
import { downloadImportErrors } from '../download'
import type { ImportStatusKey } from '../types'

const STATUS_VARIANT: Record<ImportStatusKey, 'default' | 'secondary' | 'destructive' | 'outline'> =
  {
    uploaded: 'outline',
    queued: 'secondary',
    processing: 'secondary',
    completed: 'default',
    failed: 'destructive',
  }

export function RecentImports() {
  const imports = useImports()
  const rows = imports.data?.data ?? []

  if (rows.length === 0) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle>Recent imports</CardTitle>
        <CardDescription>Your latest uploads.</CardDescription>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>File</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Created</TableHead>
              <TableHead className="text-right">Skipped</TableHead>
              <TableHead>When</TableHead>
              <TableHead className="w-10">
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell className="max-w-56 truncate font-medium">
                  {row.original_filename}
                </TableCell>
                <TableCell>{row.type.label}</TableCell>
                <TableCell>
                  <Badge variant={STATUS_VARIANT[row.status.value]}>{row.status.label}</Badge>
                </TableCell>
                <TableCell className="text-right">{row.created_rows}</TableCell>
                <TableCell className="text-right">{row.failed_rows}</TableCell>
                <TableCell>
                  <RelativeTime value={row.created_at} />
                </TableCell>
                <TableCell>
                  {row.failed_rows > 0 ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon-sm"
                      aria-label={`Download failed rows of ${row.original_filename}`}
                      onClick={() => downloadImportErrors(row)}
                    >
                      <DownloadIcon aria-hidden="true" />
                    </Button>
                  ) : null}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
