import { formatSort, parseSort, readListParams, toApiQuery, writeListParams } from './list-params'

const config = { filterKeys: ['stage', 'owner'], defaultSort: '-contacted_on' }

describe('list params', () => {
  it('reads defaults from an empty URL', () => {
    expect(readListParams(new URLSearchParams(), config)).toEqual({
      page: 1,
      pageSize: 25,
      sort: '-contacted_on',
      search: '',
      filters: {},
    })
  })

  it('reads page, size, sort, search and comma-separated filters', () => {
    const params = readListParams(
      new URLSearchParams(
        'page=3&size=50&sort=next_follow_up_on&q=pixel&stage=new,engaged&unknown=x',
      ),
      config,
    )
    expect(params).toEqual({
      page: 3,
      pageSize: 50,
      sort: 'next_follow_up_on',
      search: 'pixel',
      filters: { stage: ['new', 'engaged'] },
    })
  })

  it('ignores invalid page numbers and page sizes', () => {
    const params = readListParams(new URLSearchParams('page=-2&size=33'), config)
    expect(params.page).toBe(1)
    expect(params.pageSize).toBe(25)
  })

  it('resets to page 1 when anything but the page changes, and drops defaults from the URL', () => {
    const current = new URLSearchParams('page=4&stage=new')
    expect(writeListParams(current, { page: 5 }, config).toString()).toBe('page=5&stage=new')
    expect(writeListParams(current, { sort: '-contacted_on' }, config).toString()).toBe('stage=new')
    expect(
      writeListParams(current, { filters: { stage: ['won', 'lost'] } }, config).toString(),
    ).toBe('stage=won%2Clost')
    expect(writeListParams(current, { page: 1 }, config).toString()).toBe('stage=new')
  })

  it('builds the API query in spatie query-builder format', () => {
    const params = readListParams(
      new URLSearchParams('page=2&size=10&q=%20pixel%20&stage=new,quoted&owner=4'),
      config,
    )
    expect(toApiQuery(params, { include: ['client', 'owner'] })).toEqual({
      'page[number]': 2,
      'page[size]': 10,
      sort: '-contacted_on',
      'filter[search]': 'pixel',
      'filter[stage]': ['new', 'quoted'],
      'filter[owner]': ['4'],
      include: 'client,owner',
    })
  })

  it('parses and formats sort strings', () => {
    expect(parseSort('-contacted_on,id')).toEqual({ id: 'contacted_on', desc: true })
    expect(parseSort('name')).toEqual({ id: 'name', desc: false })
    expect(parseSort('')).toBeNull()
    expect(formatSort({ id: 'name', desc: true })).toBe('-name')
    expect(formatSort(null)).toBe('')
  })
})
