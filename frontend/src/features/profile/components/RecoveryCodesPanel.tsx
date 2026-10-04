import { CopyIcon, DownloadIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'

const FILE_NAME = 'saleshub-recovery-codes.txt'

function codesText(codes: string[]): string {
  return [
    'SalesHub recovery codes',
    'Each code signs you in once if you lose your authenticator app. Keep them somewhere safe.',
    '',
    ...codes,
    '',
  ].join('\n')
}

/** The recovery codes, with copy and download. Shown right after they are created or on request. */
export function RecoveryCodesPanel({ codes }: { codes: string[] }) {
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(codes.join('\n'))
      toast.success('Recovery codes copied.')
    } catch {
      toast.error('Could not copy. Select the codes and copy them by hand.')
    }
  }

  const download = () => {
    const url = URL.createObjectURL(new Blob([codesText(codes)], { type: 'text/plain' }))
    const link = document.createElement('a')
    link.href = url
    link.download = FILE_NAME
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <section aria-labelledby="recovery-codes-title" className="space-y-3">
      <div className="space-y-1">
        <h3 id="recovery-codes-title" className="text-sm font-medium">
          Recovery codes
        </h3>
        <p className="text-muted-foreground text-sm">
          Save these somewhere safe. Each one signs you in once if you lose access to your
          authenticator app.
        </p>
      </div>
      <ol
        aria-label="Recovery codes"
        className="bg-muted/50 grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-lg border p-4 font-mono text-sm"
      >
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ol>
      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" size="sm" onClick={() => void copy()}>
          <CopyIcon aria-hidden="true" />
          Copy
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={download}>
          <DownloadIcon aria-hidden="true" />
          Download .txt
        </Button>
      </div>
    </section>
  )
}
