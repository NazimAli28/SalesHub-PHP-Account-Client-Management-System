export const IMPORT_STEPS = [
  { id: 'upload', label: 'Upload' },
  { id: 'map', label: 'Map columns' },
  { id: 'preview', label: 'Preview' },
  { id: 'import', label: 'Import' },
  { id: 'result', label: 'Result' },
] as const

export type ImportStepId = (typeof IMPORT_STEPS)[number]['id']
