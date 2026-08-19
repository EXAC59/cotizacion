from pathlib import Path

ROOT = Path(r"c:\laragon\www\cotizacion\frontend\src")

app = ROOT / "App.tsx"
text = app.read_text(encoding="utf-8")
if "LandingPage" not in text:
    text = text.replace(
        "import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'\nimport { LoginPage }",
        "import { AdminLoginPage } from '@/pages/auth/AdminLoginPage'\nimport { LandingPage } from '@/pages/auth/LandingPage'\nimport { LoginPage }",
    )
    text = text.replace(
        "      <Route element={<AuthLayout />}>\n        <Route path=\"/login\" element={<LoginPage />} />",
        "      <Route element={<AuthLayout />}>\n        <Route path=\"/\" element={<LandingPage />} />\n        <Route path=\"/login\" element={<LoginPage />} />",
    )
    text = text.replace(
        '<Route index element={<DashboardPage />} />',
        '<Route path="dashboard" element={<DashboardPage />} />',
    )
    app.write_text(text, encoding="utf-8")
    print("App.tsx updated")

sidebar = ROOT / "components/layout/Sidebar.tsx"
st = sidebar.read_text(encoding="utf-8").replace("to: '/', label: 'Dashboard'", "to: '/dashboard', label: 'Dashboard'")
sidebar.write_text(st, encoding="utf-8")
print("Sidebar updated")

cf = ROOT / "pages/clients/ClientFormPage.tsx"
ct = cf.read_text(encoding="utf-8")
ct = ct.replace("paymentTerms: '30 días'", "paymentTerms: ''")
old = """          <div>
            <Label>Condiciones de pago</Label>
            <Input
              value={form.paymentTerms}
              onChange={(e) => update('paymentTerms', e.target.value)}
            />
          </div>"""
new = """          <div className=\"sm:col-span-2\">
            <Label>Condiciones de pago</Label>
            <Textarea
              rows={3}
              value={form.paymentTerms}
              onChange={(e) => update('paymentTerms', e.target.value)}
              placeholder=\"Ej. 30 días, transferencia bancaria…\"
            />
          </div>"""
if old in ct:
    cf.write_text(ct.replace(old, new), encoding="utf-8")
    print("ClientFormPage updated")
else:
    print("ClientFormPage block missing")
