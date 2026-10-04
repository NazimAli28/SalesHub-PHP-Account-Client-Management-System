import type { EnumValue, IsoDateTime } from '@/api/types'

export type ImportTypeKey = 'leads' | 'clients'
export type ImportStatusKey = 'uploaded' | 'queued' | 'processing' | 'completed' | 'failed'

/** One importable column of an import type (what a CSV column can be mapped to). */
export interface ImportField {
  key: string
  label: string
  required: boolean
  example: string
  hint: string
}

export interface ImportRowError {
  row: number
  /** The CSV header of the column, when the error belongs to one. */
  column: string | null
  field: string | null
  message: string
}

/** CSV header -> field key, or `null` to skip the column. */
export type ColumnMapping = Record<string, string | null>

export interface ImportRecord {
  id: number
  type: EnumValue<ImportTypeKey>
  status: EnumValue<ImportStatusKey>
  original_filename: string
  headers: string[]
  mapping: ColumnMapping | null
  fields: ImportField[]
  total_rows: number
  processed_rows: number
  created_rows: number
  failed_rows: number
  /** Up to 200 row errors; absent from the list endpoint. */
  errors?: ImportRowError[]
  started_at: IsoDateTime | null
  finished_at: IsoDateTime | null
  created_at: IsoDateTime
}

/** The upload response: the import plus what the mapping step needs. */
export interface UploadedImport extends ImportRecord {
  sample_rows: Record<string, string>[]
  suggested_mapping: ColumnMapping
}

export interface PreviewRow {
  row: number
  values: Record<string, string>
  valid: boolean
  /** Field key -> messages. */
  errors: Record<string, string[]>
}

export interface PreviewResult {
  rows: PreviewRow[]
  summary: { total_rows: number; checked: number; valid: number; invalid: number }
}

export const MAX_UPLOAD_BYTES = 2 * 1024 * 1024
export const MAX_UPLOAD_ROWS = 2000
