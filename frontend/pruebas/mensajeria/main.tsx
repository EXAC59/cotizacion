import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { MessagingPreview } from './MessagingPreview'
import { LiveEntry } from './LiveEntry'
import './preview-base.css'
import './messaging.css'
import './workflow.css'

createRoot(document.getElementById('root')!).render(<StrictMode>{import.meta.env.VITE_MENSAJERIA_LIVE === 'true' ? <LiveEntry /> : <MessagingPreview />}</StrictMode>)
