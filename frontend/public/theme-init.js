// Applies the saved theme before the first paint so dark-mode users never see a white flash.
// Loaded synchronously from index.html (an external file, so the CSP needs no inline script).
// Keep the storage key and logic in sync with src/app/theme/theme.ts.
;(function () {
  try {
    var stored = localStorage.getItem('saleshub-theme')
    var theme = stored === 'light' || stored === 'dark' ? stored : 'system'
    var dark =
      theme === 'dark' ||
      (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
    document.documentElement.classList.toggle('dark', dark)
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
  } catch {}
})()
