import { useState } from 'react'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { useAuth } from '@/features/auth/AuthProvider'
import { ImportStepper } from '../components/ImportStepper'
import { MappingStep } from '../components/MappingStep'
import { PreviewStep } from '../components/PreviewStep'
import { RecentImports } from '../components/RecentImports'
import { ResultStep } from '../components/ResultStep'
import { RunStep } from '../components/RunStep'
import { UploadStep } from '../components/UploadStep'
import type { ImportStepId } from '../steps'
import type { ColumnMapping, ImportRecord, ImportTypeKey, UploadedImport } from '../types'

/** CSV import wizard: upload, map columns, preview, import, result. */
export default function ImportPage() {
  const { can } = useAuth()
  const allowedTypes: ImportTypeKey[] = [
    ...(can('leads.import') ? (['leads'] as const) : []),
    ...(can('clients.import') ? (['clients'] as const) : []),
  ]

  const [step, setStep] = useState<ImportStepId>('upload')
  const [upload, setUpload] = useState<UploadedImport | null>(null)
  const [mapping, setMapping] = useState<ColumnMapping>({})
  const [result, setResult] = useState<ImportRecord | null>(null)

  const restart = () => {
    setUpload(null)
    setMapping({})
    setResult(null)
    setStep('upload')
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Import"
        description="Bring leads or clients in from a CSV file. You can check everything before it is saved."
      />

      {allowedTypes.length === 0 ? (
        <EmptyState
          title="You cannot import data"
          description="Ask an administrator if you need to import leads or clients."
        />
      ) : (
        <>
          <ImportStepper current={step} />

          {step === 'upload' ? (
            <UploadStep
              allowedTypes={allowedTypes}
              onUploaded={(data) => {
                setUpload(data)
                setMapping(data.suggested_mapping)
                setStep('map')
              }}
            />
          ) : null}

          {step === 'map' && upload ? (
            <MappingStep
              upload={upload}
              initialMapping={mapping}
              onBack={restart}
              onContinue={(chosen) => {
                setMapping(chosen)
                setStep('preview')
              }}
            />
          ) : null}

          {step === 'preview' && upload ? (
            <PreviewStep
              upload={upload}
              mapping={mapping}
              onBack={() => setStep('map')}
              onContinue={() => setStep('import')}
            />
          ) : null}

          {step === 'import' && upload ? (
            <RunStep
              upload={upload}
              mapping={mapping}
              onBack={() => setStep('preview')}
              onFinished={(record) => {
                setResult(record)
                setStep('result')
              }}
            />
          ) : null}

          {step === 'result' && result ? <ResultStep record={result} onAnother={restart} /> : null}

          <RecentImports />
        </>
      )}
    </div>
  )
}
