import { MAX_UPLOAD_BYTES, type ColumnMapping, type ImportField } from './types'

/** Returns an error message when the file cannot be imported, otherwise null. */
export function checkFile(file: File): string | null {
  if (!/\.(csv|txt)$/i.test(file.name)) return 'Choose a .csv or .txt file.'
  if (file.size > MAX_UPLOAD_BYTES) return 'The file is larger than 2 MB.'
  if (file.size === 0) return 'The file is empty.'
  return null
}

/** Problems with a mapping, in plain words (the server checks the same rules). */
export function mappingProblems(fields: ImportField[], mapping: ColumnMapping): string[] {
  const problems: string[] = []
  const chosen = Object.values(mapping).filter((field): field is string => Boolean(field))

  const missing = fields.filter((field) => field.required && !chosen.includes(field.key))
  if (missing.length > 0) {
    problems.push(`Map a column to: ${missing.map((field) => field.label).join(', ')}.`)
  }
  for (const field of fields) {
    if (chosen.filter((key) => key === field.key).length > 1) {
      problems.push(`"${field.label}" is mapped to more than one column.`)
    }
  }
  return problems
}
