# Building a screen: the Phase 4 checklist

Every module screen follows the pattern of the **Leads** reference implementation. Copy it, rename it, and work through this list. Paths are relative to `frontend/`. Read [architecture.md](architecture.md) first for the "why".

Reference files:

| Piece | Leads file |
|-------|-----------|
| Data layer (keys, queries, mutations, option loaders) | `src/features/leads/api.ts` |
| Form schema, defaults, payload mapping | `src/features/leads/schemas.ts` |
| Table columns | `src/features/leads/components/lead-columns.tsx` |
| Row menu (edit, delete, 202 handling) | `src/features/leads/components/LeadRowActions.tsx` |
| Create / edit form | `src/features/leads/components/LeadFormSheet.tsx` |
| List page | `src/features/leads/pages/LeadsPage.tsx` |
| Tests | `src/features/leads/pages/LeadsPage.test.tsx` |

## 0. Before you start

- [ ] Find your endpoint in `docs/api/openapi.json` (or `docs/api/module-guide.md`), and its allowed `filter[...]`, `sort` and `include` values in `backend/app/Http/Queries/<Model>IndexQuery.php`. Unknown filters or sorts return **400**.
- [ ] Run `npm run api:types` if the spec changed. Add a narrowed type to `src/api/types.ts` when the generator types enums or money loosely (see `Lead` there).
- [ ] Note the permissions for view (scope tiers), create, update, delete and `request-change` in `src/lib/permissions.ts`. Add a `VIEW_ANY.<module>` group if one is missing.
- [ ] New enum? Add it to `src/lib/enums.ts` with a tone per value, and `StatusBadge` will support it automatically.

## 1. Route and navigation

- [ ] `src/app/router.tsx`: replace the module's `comingSoon` with your page import. Keep the same permission:
  ```tsx
  page(paths.clients, () => import('@/features/clients/pages/ClientsPage'), { crumb: 'Clients' }, VIEW_ANY.clients),
  ```
- [ ] Detail page: add a sibling route such as `page('/clients/:clientId', () => import(...ClientDetailPage), { crumb: 'Client' }, VIEW_ANY.clients)`, and add the path helper to `src/app/paths.ts` if you link to it.
- [ ] The sidebar entry already exists in `src/app/navigation.ts`. Keep its `permission` identical to the route's.
- [ ] Pages are **default exports** (lazy loading needs that); everything else uses named exports.

## 2. Data layer: `features/<module>/api.ts`

- [ ] `export const clientKeys = createQueryKeys('clients')`
- [ ] `export const CLIENT_LIST_CONFIG = { filterKeys: [...], defaultSort: '...' } as const satisfies ListParamsConfig`. Filter keys are the API filter names (`stage` → `?stage=` → `filter[stage]=`). `defaultSort` must equal the endpoint's default so the header shows it as active.
- [ ] List hook: `useQuery({ queryKey: keys.list(params), queryFn: ({ signal }) => api.get<Paginated<T>>(path, { query: toApiQuery(params, { include }), signal }), placeholderData: keepPreviousData })`.
- [ ] Detail hook: `api.get<Envelope<T>>(`/clients/${id}`)` with `select: (r) => r.data`, key `keys.detail(id)`.
- [ ] Mutations via `useApiMutation`:
  - create: `api.send<T>('POST', path, payload)`
  - update: `api.send<T>('PATCH', `${path}/${id}`, changedFields)`, which **may return 202**
  - delete: `api.send<void>('DELETE', `${path}/${id}`)`, which **may return 202**
  - always `invalidate: [keys.all]`, a `successMessage`, and accept `{ form, onSuccess }` so 422s land on fields.
- [ ] Option loaders for pickers: `async (search, signal) => ComboboxOption[]` (see `fetchClientOptions`), and a `useXOptions` query for filter facets (see `useOwnerOptions`; gate it with `enabled` when it needs a permission).

## 3. List page with `<DataTable>`

- [ ] `const table = useDataTableParams(CLIENT_LIST_CONFIG)`, then `const clients = useClients(table.params)`.
- [ ] Columns in `components/<module>-columns.tsx` using `createColumnHelper<T>()`:
  - [ ] **Sortable** column: `id` = API sort field, `enableSorting: true`, header `<DataTableColumnHeader column={column} title="..." />`. Columns are not sortable unless you opt in.
  - [ ] `meta: { label }` names the column in the "Columns" menu; `meta: { className: 'text-right' }` aligns it. Use `enableHiding: false` for the identifying column and actions.
  - [ ] Render values with `StatusBadge`, `MoneyText`, `RelativeTime` (`display="date"` for calendar dates).
  - [ ] Actions column: `column.display({ id: 'actions', cell: ({ row }) => <XRowActions ... /> })`.
  - [ ] Export a `getXColumns({ onEdit, selectable })` factory and call it inside `useMemo` in the page (columns must be stable).
- [ ] `<DataTable label="Clients" columns={columns} query={clients} state={table} searchPlaceholder="..." filters={...} emptyState={...} />`
  - [ ] Filters: `<DataTableFacetedFilter state={table} filterKey="status" title="Status" options={clientStatuses.options} />`.
  - [ ] Hide filters the user can't use (e.g. owner needs `users.view`).
  - [ ] Selection: pass `enableRowSelection` + `bulkActions={(rows, clear) => ...}`, and `selectable: true` to the columns factory.
  - [ ] Provide a friendly `emptyState`. The "no matches + Clear filters" state is built in.
- [ ] `PageHeader` with the title, a one-line description, and a primary action wrapped in `<Can permission="clients.create">`.

## 4. Create / edit form

- [ ] `schemas.ts`: a zod schema mirroring the Form Request rules (the API stays authoritative), a `xFormDefaults(record?)`, and a `toXPayload(values)` mapping. Form values use API field names wherever possible, so 422 errors map without a `fieldMap`.
- [ ] Field components (`@/components/form`), all typed against your form values:

  | Field | Form value |
  |-------|-----------|
  | `TextField`, `PasswordField`, `TextareaField` | `string` |
  | `SelectField` (enums; `clearable` for `null`) | option value string |
  | `AsyncComboboxField` (related records) | record id `number \| null` |
  | `DateField` | `YYYY-MM-DD \| null` |
  | `MoneyField` | integer cents `number \| null` |
  | `CheckboxField` | `boolean` |

  For a one-off control, use `FormField` with a custom `render`.
- [ ] Container: `FormSheet` for longer forms (Leads uses it), `FormDialog` for up to about six fields. The parent owns `open`. Reset the form in an effect when it opens: `useEffect(() => { if (open) form.reset(defaults(record)) }, [open, record, form])`.
- [ ] Edits send only changed fields: `pickChanged(payload, form.formState.dirtyFields)`.
- [ ] Mark required fields with `required` (visual only; zod enforces it).
- [ ] Form-level errors (422 for fields the form lacks, other failures) appear through `FormRootError`, which `FormSheet` and `FormDialog` already include.

## 5. Permissions and approvals (202)

- [ ] Gate every action by permission: `<Can>` for buttons, `can` / `canAny` inside menus.
- [ ] Users with `x.request-change` but not `x.update` / `x.delete` still get the actions, and the API queues them (202). Make that visible:
  - [ ] Change wording: "Send for approval", "Request deletion" (see `LeadRowActions`, `LeadFormSheet`).
  - [ ] Show an optional "Reason for the change" field (`reason`, max 500) only in that case.
  - [ ] `useApiMutation` already toasts "Sent for approval" on 202. Do not show "Saved".
- [ ] Records with `pending_change !== null`: show the clock indicator (see the stage cell in `lead-columns.tsx`) and disable edit/delete, because a second request fails with 422.
- [ ] Destructive actions use `ConfirmDialog` (`destructive`, `pending={mutation.isPending}`, `onConfirm={() => mutation.mutateAsync(id)}`). Open it from menus with state, outside the dropdown.

## 6. Detail page (pattern)

- [ ] `const { clientId } = useParams()`, then `useClient(Number(clientId))`.
- [ ] Loading: `CardSkeleton` / `PageSkeleton`. Error: `ErrorState` with `onRetry={refetch}`. A 403 from the API (record outside the user's scope) shows "You don't have access to this record" via `ErrorState`.
- [ ] `PageHeader` title = the record's name, with actions = Edit (opens the same form sheet with `record`) and Delete.
- [ ] Use a `<dl>` of details (see `ProfilePage`) plus `Tabs` for related lists (each related list can be a `DataTable` with its own `useDataTableParams`, but give its filter keys distinct names if two tables share a page).

## 7. Tests (`*.test.tsx` next to the page)

- [ ] Use `renderWithProviders(<Page />, { route: '/clients?status=active', user: makeUser(overrides, permissions) })`.
- [ ] Assert the API request built from the URL (capture `request.url` in a `server.use(http.get('*/api/clients', ...))` handler).
- [ ] Permission gating: a user without `x.create` doesn't see the button (use `SALES_EXECUTIVE_PERMISSIONS` or a custom list).
- [ ] 202 path: respond with `{ status: 202 }` and expect "Sent for approval".
- [ ] 422 path for forms: respond with `{ errors: { field: ['msg'] } }`, then expect the message next to the field and `aria-invalid="true"`.
- [ ] Use fictional data and `@example.com` emails only.

## 8. Done checklist

- [ ] `npm run lint && npm run format:check && npm run typecheck && npm test && npm run build` all pass.
- [ ] The page works at 375 px wide (the sidebar becomes a sheet; check toolbar wrapping).
- [ ] Keyboard only: tab through filters, headers, row menus and the form. Focus is visible and Escape closes overlays.
- [ ] Light and dark themes both look right.
- [ ] Reload keeps the filters, sort and page. Back/forward walk through page changes.

## 9. Phase 5 patterns beyond list/detail screens

Reference files for the pieces the checklist above does not cover. The permission list in `src/lib/permissions.ts` mirrors the API's 71 permissions, including `leads.import`, `clients.import` and `reports.export`.

| Pattern | Reference | Rules |
|---------|-----------|-------|
| Dashboard with charts | `features/dashboard/pages/DashboardPage.tsx`, `components/*Chart.tsx`, `components/ui/chart.tsx` | Keep the range and filters in the URL. Wrap each chart in `ChartCard`, give it a `role="img"` label plus a `sr-only` text summary, and handle loading, error and empty states for every panel. Colour with `var(--chart-N)` tokens. |
| Kanban board with drag and drop | `features/leads/board/*` | One infinite query per column. Make moves optimistic and roll back on error and on 202 (show the pending badge instead). Collect extra data in a dialog before the move (`LostReasonDialog`, `WonOrderDialog`). Always offer a keyboard path: the dnd-kit keyboard sensor with announcements, and a "Move to…" menu on the card. Read-only for users without update or request-change. |
| Multi-step wizard | `features/imports/pages/ImportPage.tsx`, `steps.ts`, `components/*Step.tsx` | Keep step state in the page, one component per step, poll a long-running job with `refetchInterval` that stops when the job finishes. |
| CSV download button | `features/imports/components/ExportCsvButton.tsx`, `download.ts` | Wrap in `<Can permission="reports.export">`, pass the list's current filters and sort, ignore paging. |
| Security forms (OTP, password confirm) | `features/auth/components/OtpCodeField.tsx`, `features/profile/components/*` | Ask for the password in `ConfirmPasswordDialog` for sensitive actions, show recovery codes only in `RecoveryCodesPanel`, never store secrets in query cache longer than the dialog needs. jsdom needs `document.elementFromPoint`, which `src/test/setup.ts` stubs. |
| Record search | `app/layouts/CommandMenu.tsx` | Debounce the term, disable cmdk's own filtering for server results, and keep screens listed below the records. |
