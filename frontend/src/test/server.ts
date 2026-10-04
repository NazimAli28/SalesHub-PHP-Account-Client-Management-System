import { setupServer } from 'msw/node'
import { handlers } from './handlers'

/** MSW server for tests. Override per test with `server.use(http.get(...))`. */
export const server = setupServer(...handlers)
