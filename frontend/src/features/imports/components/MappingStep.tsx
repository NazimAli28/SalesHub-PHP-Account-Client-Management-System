import { useId, useState } from 'react'
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
import type { ColumnMapping, UploadedImport } from '../types'
import { mappingProblems } from '../validation'

interface MappingStepProps {
  upload: UploadedImport
  initialMapping: ColumnMapping
  onBack: () => void
  onContinue: (mapping: ColumnMapping) => void
}

export function MappingStep({ upload, initialMapping, onBack, onContinue }: MappingStepProps) {
  const [mapping, setMapping] = useState<ColumnMapping>(initialMapping)
  const [problems, setProblems] = useState<string[]>([])
  const baseId = useId()
  const sample = upload.sample_rows[0] ?? {}

  const submit = () => {
    const found = mappingProblems(upload.fields, mapping)
    setProblems(found)
    if (found.length === 0) onContinue(mapping)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Match your columns</CardTitle>
        <CardDescription>
          Choose which field each column of <strong>{upload.original_filename}</strong> fills.
          Fields marked * are required. Columns set to Skip are ignored.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>CSV column</TableHead>
              <TableHead>Example</TableHead>
              <TableHead>Import as</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {upload.headers.map((header, index) => {
              const id = `${baseId}-${index}`
              return (
                <TableRow key={header}>
                  <TableCell className="font-medium">
                    <label htmlFor={id}>{header}</label>
                  </TableCell>
                  <TableCell className="text-muted-foreground max-w-48 truncate">
                    {sample[header] || '—'}
                  </TableCell>
                  <TableCell>
                    <select
                      id={id}
                      value={mapping[header] ?? ''}
                      onChange={(event) =>
                        setMapping((current) => ({
                          ...current,
                          [header]: event.target.value || null,
                        }))
                      }
                      className="border-input bg-background h-9 w-full min-w-48 rounded-md border px-2 text-sm"
                    >
                      <option value="">Skip this column</option>
                      {upload.fields.map((field) => (
                        <option key={field.key} value={field.key}>
                          {field.label}
                          {field.required ? ' *' : ''}
                        </option>
                      ))}
                    </select>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>

        {problems.length > 0 ? (
          <div role="alert" className="text-destructive space-y-1 text-sm">
            {problems.map((problem) => (
              <p key={problem}>{problem}</p>
            ))}
          </div>
        ) : null}

        <div className="flex justify-between gap-3">
          <Button type="button" variant="outline" onClick={onBack}>
            Back
          </Button>
          <Button type="button" onClick={submit}>
            Preview rows
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
