import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'
import './index.css'

function render() {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
}

// The static browser demo (GitHub Pages, `npm run build:demo`) answers the API in the browser.
// The condition is replaced at build time, so other builds contain none of the demo code or data.
if (import.meta.env.VITE_STATIC_DEMO === 'true') {
  import('./demo/start')
    .then(({ startStaticDemo }) => startStaticDemo())
    .catch((error: unknown) => console.error('The browser demo could not start.', error))
    .finally(render)
} else {
  render()
}
