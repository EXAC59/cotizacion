import { Link } from 'react-router-dom'
import { Card, CardBody } from '@/components/ui/Card'

export function AdminLoginPage() {
  return (
    <Card className="w-full max-w-md">
      <CardBody className="text-center text-sm text-slate-600">
        <p>Inicia sesión con un usuario administrador desde el login principal.</p>
        <Link to="/login" className="mt-4 inline-block font-medium text-indigo-600 hover:underline">
          Ir al login
        </Link>
      </CardBody>
    </Card>
  )
}
