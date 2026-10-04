import { useRef, useState, type DragEvent } from 'react'
import { DownloadIcon, FileSpreadsheetIcon, UploadCloudIcon } from 'lucide-react'
import { toast } from 'sonner'
import { errorMessage } from '@/api/errors'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Spinner } from '@/components/ui/spinner'
import { cn } from '@/lib/utils'
import { useUploadImport } from '../api'
import { downloadFile } from '../download'
import { checkFile } from '../validation'
import { MAX_UPLOAD_ROWS, type ImportTypeKey, type UploadedImport } from '../types'

const TYPE_OPTIONS: { value: ImportTypeKey; label: string; description: string }[] = [
  {
    value: 'leads',
    label: 'Leads',
    description: 'Creates leads, and the clients that do not exist yet.',
  },
  {
    value: 'clients',
    label: 'Clients',
    description: 'Creates clients. Discord usernames must be unique.',
  },
]

interface UploadStepProps {
  allowedTypes: readonly ImportTypeKey[]
  onUploaded: (data: UploadedImport) => void
}

export function UploadStep({ allowedTypes, onUploaded }: UploadStepProps) {
  const options = TYPE_OPTIONS.filter((option) => allowedTypes.includes(option.value))
  const [type, setType] = useState<ImportTypeKey>(options[0]?.value ?? 'leads')
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const upload = useUploadImport({ onSuccess: onUploaded })

  const choose = (candidate: File | undefined) => {
    if (!candidate) return
    const problem = checkFile(candidate)
    setFileError(problem)
    setFile(problem ? null : candidate)
    upload.reset()
  }

  const onDrop = (event: DragEvent) => {
    event.preventDefault()
    setDragging(false)
    choose(event.dataTransfer.files[0])
  }

  const downloadTemplate = async () => {
    try {
      await downloadFile(`/imports/templates/${type}`, undefined, `${type}-import-template.csv`)
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  const message = fileError ?? upload.error?.message

  return (
    <Card>
      <CardHeader>
        <CardTitle>Choose what to import</CardTitle>
        <CardDescription>
          CSV files up to 2 MB and {MAX_UPLOAD_ROWS.toLocaleString('en-US')} rows. Comma or
          semicolon separated, UTF-8.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <fieldset className="space-y-2">
          <legend className="mb-2 text-sm font-medium">Import type</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            {options.map((option) => (
              <label
                key={option.value}
                className={cn(
                  'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm',
                  type === option.value && 'border-primary bg-primary/5',
                )}
              >
                <input
                  type="radio"
                  name="import-type"
                  value={option.value}
                  checked={type === option.value}
                  onChange={() => setType(option.value)}
                  className="mt-1"
                />
                <span>
                  <span className="block font-medium">{option.label}</span>
                  <span className="text-muted-foreground">{option.description}</span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>

        <div
          onDragOver={(event) => {
            event.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={cn(
            'flex flex-col items-center gap-3 rounded-lg border-2 border-dashed px-6 py-10 text-center',
            dragging && 'border-primary bg-primary/5',
          )}
        >
          {file ? (
            <FileSpreadsheetIcon className="text-primary size-8" aria-hidden="true" />
          ) : (
            <UploadCloudIcon className="text-muted-foreground size-8" aria-hidden="true" />
          )}
          {file ? (
            <p className="text-sm">
              <span className="font-medium">{file.name}</span>{' '}
              <span className="text-muted-foreground">({Math.ceil(file.size / 1024)} KB)</span>
            </p>
          ) : (
            <p className="text-muted-foreground text-sm">Drag a CSV file here, or</p>
          )}
          <input
            ref={inputRef}
            type="file"
            accept=".csv,.txt,text/csv,text/plain"
            className="sr-only"
            aria-label="CSV file"
            onChange={(event) => choose(event.target.files?.[0])}
          />
          <Button type="button" variant="outline" onClick={() => inputRef.current?.click()}>
            {file ? 'Choose another file' : 'Choose file'}
          </Button>
        </div>

        {message ? (
          <p role="alert" className="text-destructive text-sm">
            {message}
          </p>
        ) : null}

        <div className="flex flex-wrap items-center justify-between gap-3">
          <Button type="button" variant="ghost" onClick={downloadTemplate}>
            <DownloadIcon aria-hidden="true" />
            Download {type} template
          </Button>
          <Button
            type="button"
            disabled={!file || upload.isPending}
            onClick={() => file && upload.mutate({ type, file })}
          >
            {upload.isPending ? <Spinner /> : null}
            Upload and continue
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
