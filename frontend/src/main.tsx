import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ensureCsrfCookie } from '@/lib/api-fetch'
import './index.css'
import App from './App.tsx'

void ensureCsrfCookie()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
