import {
  Navigate,
  Outlet,
  Route,
  RouterProvider,
  createBrowserRouter,
  createRoutesFromElements,
} from 'react-router-dom'
import { AuthLayout } from '@/components/layout/AuthLayout'
import { AppLayout } from '@/components/layout/AppLayout'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { ModuleRoute } from '@/components/ModuleRoute'
import { AdminRoute } from '@/components/admin/AdminRoute'
import { AuthProvider } from '@/context/AuthProvider'
import { BrandingProvider } from '@/context/BrandingProvider'
import { DataProvider } from '@/context/DataProvider'
import { RbacProvider } from '@/context/RbacProvider'
import { ToastProvider } from '@/context/ToastProvider'
import { AdminHomePage } from '@/pages/admin/AdminHomePage'
import { RolesPage } from '@/pages/admin/RolesPage'
import { UserFormPage } from '@/pages/admin/UserFormPage'
import { UsersPage } from '@/pages/admin/UsersPage'
import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'
import { LandingPage } from '@/pages/auth/LandingPage'
import { LoginPage } from '@/pages/auth/LoginPage'
import { ClientDetailPage } from '@/pages/clients/ClientDetailPage'
import { ClientFormPage } from '@/pages/clients/ClientFormPage'
import { ClientsPage } from '@/pages/clients/ClientsPage'
import { DashboardPage } from '@/pages/dashboard/DashboardPage'
import { QuoteFormPage } from '@/pages/quotes/QuoteFormPage'
import { QuotesPage } from '@/pages/quotes/QuotesPage'
import { ReportsPage } from '@/pages/reports/ReportsPage'
import { RequestDetailPage } from '@/pages/requests/RequestDetailPage'
import { RequestNewPage } from '@/pages/requests/RequestNewPage'
import { RequestsPage } from '@/pages/requests/RequestsPage'
import { SettingsPage } from '@/pages/settings/SettingsPage'
import { WholesalersPage } from '@/pages/wholesalers/WholesalersPage'
import { getRouterBasename } from '@/lib/app-paths'

function RootProviders() {
  return (
    <AuthProvider>
      <BrandingProvider>
        <RbacProvider>
          <DataProvider>
            <ToastProvider>
              <Outlet />
            </ToastProvider>
          </DataProvider>
        </RbacProvider>
      </BrandingProvider>
    </AuthProvider>
  )
}

const router = createBrowserRouter(
  createRoutesFromElements(
    <Route element={<RootProviders />}>
      <Route element={<AuthLayout />}>
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/login/admin" element={<AdminLoginPage />} />
      </Route>

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route
            path="dashboard"
            element={
              <ModuleRoute module="dashboard">
                <DashboardPage />
              </ModuleRoute>
            }
          />
          <Route
            path="solicitudes"
            element={
              <ModuleRoute module="solicitudes">
                <RequestsPage />
              </ModuleRoute>
            }
          />
          <Route
            path="solicitudes/nueva"
            element={
              <ModuleRoute module="solicitudes">
                <RequestNewPage />
              </ModuleRoute>
            }
          />
          <Route
            path="solicitudes/:id"
            element={
              <ModuleRoute module="solicitudes">
                <RequestDetailPage />
              </ModuleRoute>
            }
          />
          <Route
            path="cotizaciones"
            element={
              <ModuleRoute module="cotizaciones">
                <QuotesPage />
              </ModuleRoute>
            }
          />
          <Route
            path="cotizaciones/nueva"
            element={
              <ModuleRoute module="cotizaciones">
                <QuoteFormPage />
              </ModuleRoute>
            }
          />
          <Route
            path="cotizaciones/:id"
            element={
              <ModuleRoute module="cotizaciones">
                <QuoteFormPage />
              </ModuleRoute>
            }
          />
          <Route
            path="clientes"
            element={
              <ModuleRoute module="clientes">
                <ClientsPage />
              </ModuleRoute>
            }
          />
          <Route
            path="clientes/nuevo"
            element={
              <ModuleRoute module="clientes">
                <ClientFormPage />
              </ModuleRoute>
            }
          />
          <Route
            path="clientes/:id/editar"
            element={
              <ModuleRoute module="clientes">
                <ClientFormPage />
              </ModuleRoute>
            }
          />
          <Route
            path="clientes/:id"
            element={
              <ModuleRoute module="clientes">
                <ClientDetailPage />
              </ModuleRoute>
            }
          />
          <Route
            path="mayoristas"
            element={
              <ModuleRoute module="mayoristas">
                <WholesalersPage />
              </ModuleRoute>
            }
          />
          <Route
            path="reportes"
            element={
              <ModuleRoute module="reportes">
                <ReportsPage />
              </ModuleRoute>
            }
          />
          <Route
            path="configuracion"
            element={
              <ModuleRoute module="configuracion">
                <SettingsPage />
              </ModuleRoute>
            }
          />
          <Route path="admin" element={<AdminRoute />}>
            <Route index element={<AdminHomePage />} />
            <Route path="usuarios" element={<UsersPage />} />
            <Route path="usuarios/nuevo" element={<UserFormPage />} />
            <Route path="usuarios/:id" element={<UserFormPage />} />
            <Route path="roles" element={<RolesPage />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Route>,
  ),
  { basename: getRouterBasename() },
)

export default function App() {
  return <RouterProvider router={router} />
}
