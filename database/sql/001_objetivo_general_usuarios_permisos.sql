-- =============================================================================
-- Cotización B2B — Esquema inicial PostgreSQL
-- Cubre: Objetivo general (flujo cotización) + §2.1 Usuarios/Roles + §2.2 Permisos
-- Ejecutar en la BD `cotizacion` (pgAdmin, DBeaver o psql)
-- =============================================================================

    CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- -----------------------------------------------------------------------------
-- Limpieza (solo tablas de dominio; no toca cache/jobs de Laravel si ya existen)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS quote_line_offers CASCADE;
DROP TABLE IF EXISTS quote_lines CASCADE;
DROP TABLE IF EXISTS quotes CASCADE;
DROP TABLE IF EXISTS quote_request_pricing_lines CASCADE;
DROP TABLE IF EXISTS quote_request_lines CASCADE;
DROP TABLE IF EXISTS quote_requests CASCADE;
DROP TABLE IF EXISTS inventory_items CASCADE;
DROP TABLE IF EXISTS wholesalers CASCADE;
DROP TABLE IF EXISTS clients CASCADE;
DROP TABLE IF EXISTS app_settings CASCADE;
DROP TABLE IF EXISTS role_permissions CASCADE;
DROP TABLE IF EXISTS permissions CASCADE;
DROP TABLE IF EXISTS modules CASCADE;
DROP TABLE IF EXISTS roles CASCADE;

-- Evita role_id huérfanos si users ya existía de una corrida anterior
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_foreign;
UPDATE users SET role_id = NULL WHERE role_id IS NOT NULL;

-- -----------------------------------------------------------------------------
-- §2.1 Roles del sistema
-- -----------------------------------------------------------------------------
CREATE TABLE roles (
    id              SMALLSERIAL PRIMARY KEY,
    slug            VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE roles IS '§2.1 — Administrador, Gerente de Compras, Ventas, Gerencia';

-- -----------------------------------------------------------------------------
-- §2.2 Permisos (módulos + acciones)
-- -----------------------------------------------------------------------------
CREATE TABLE modules (
    id              SMALLSERIAL PRIMARY KEY,
    slug            VARCHAR(40) NOT NULL UNIQUE,
    name            VARCHAR(80) NOT NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE permissions (
    id              SERIAL PRIMARY KEY,
    module_id       SMALLINT NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
    action          VARCHAR(20) NOT NULL,
    code            VARCHAR(60) NOT NULL UNIQUE,
    description     VARCHAR(255),
    UNIQUE (module_id, action)
);

CREATE TABLE role_permissions (
    role_id         SMALLINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id   INT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

COMMENT ON TABLE role_permissions IS '§2.2 — Matriz rol × permiso';

-- -----------------------------------------------------------------------------
-- Objetivo general — Parámetros globales
-- -----------------------------------------------------------------------------
CREATE TABLE app_settings (
    id                      SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    default_margin_percent  NUMERIC(5,2) NOT NULL DEFAULT 30.00,
    tax_percent             NUMERIC(5,2) NOT NULL DEFAULT 16.00,
    quote_validity_days     SMALLINT NOT NULL DEFAULT 15,
    min_stock_alert         INT NOT NULL DEFAULT 5,
    currency_code           CHAR(3) NOT NULL DEFAULT 'MXN',
    company_name            VARCHAR(255),
    company_rfc             VARCHAR(20),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- Objetivo general — Clientes (§10)
-- -----------------------------------------------------------------------------
CREATE TABLE clients (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company         VARCHAR(255) NOT NULL,
    rfc             VARCHAR(20) NOT NULL DEFAULT '',
    address         TEXT NOT NULL DEFAULT '',
    contact_name    VARCHAR(255) NOT NULL DEFAULT '',
    email           VARCHAR(255) NOT NULL DEFAULT '',
    whatsapp        VARCHAR(30) NOT NULL DEFAULT '',
    payment_terms   VARCHAR(120) NOT NULL DEFAULT '',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX clients_company_idx ON clients(company);
CREATE INDEX clients_rfc_idx ON clients(rfc);

-- -----------------------------------------------------------------------------
-- Objetivo general — Mayoristas (§6)
-- -----------------------------------------------------------------------------
CREATE TABLE wholesalers (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code            VARCHAR(30) NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    integration     VARCHAR(20) NOT NULL CHECK (integration IN ('api', 'xml', 'csv', 'ftp', 'scraping')),
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    config_json     JSONB NOT NULL DEFAULT '{}',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- Objetivo general — Inventario / alertas (Dashboard §3)
-- -----------------------------------------------------------------------------
CREATE TABLE inventory_items (
    id              BIGSERIAL PRIMARY KEY,
    part_number     VARCHAR(80) NOT NULL,
    product_name    VARCHAR(255) NOT NULL,
    stock           INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
    warehouse       VARCHAR(80) NOT NULL DEFAULT '',
    wholesaler_id   UUID REFERENCES wholesalers(id) ON DELETE SET NULL,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (part_number, warehouse)
);

CREATE INDEX inventory_items_stock_idx ON inventory_items(stock);

-- -----------------------------------------------------------------------------
-- Objetivo general — Solicitudes + OCR (§4, §5)
-- -----------------------------------------------------------------------------
CREATE TABLE quote_requests (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id           UUID REFERENCES clients(id) ON DELETE SET NULL,
    created_by          BIGINT REFERENCES users(id) ON DELETE SET NULL,
    source              VARCHAR(10) NOT NULL CHECK (source IN ('pdf', 'excel', 'word', 'text')),
    status              VARCHAR(20) NOT NULL DEFAULT 'pendiente'
                        CHECK (status IN ('pendiente', 'procesando', 'procesada', 'precios_listos', 'error')),
    file_name           VARCHAR(255),
    file_path           VARCHAR(500),
    raw_text            TEXT,
    n8n_workflow_id     VARCHAR(100),
    error_message       TEXT,
    pricing_uploaded_at TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX quote_requests_status_idx ON quote_requests(status);
CREATE INDEX quote_requests_client_idx ON quote_requests(client_id);
CREATE INDEX quote_requests_created_at_idx ON quote_requests(created_at DESC);

CREATE TABLE quote_request_lines (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    request_id      UUID NOT NULL REFERENCES quote_requests(id) ON DELETE CASCADE,
    line_order      SMALLINT NOT NULL DEFAULT 0,
    quantity        NUMERIC(12,4) NOT NULL CHECK (quantity > 0),
    product         VARCHAR(255) NOT NULL,
    part_number     VARCHAR(80) NOT NULL DEFAULT '',
    brand           VARCHAR(80) NOT NULL DEFAULT '',
    description     TEXT NOT NULL DEFAULT '',
    unit            VARCHAR(20) NOT NULL DEFAULT 'pza',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX quote_request_lines_request_idx ON quote_request_lines(request_id);

-- Precios que sube compras para ventas
CREATE TABLE quote_request_pricing_lines (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    request_id          UUID NOT NULL REFERENCES quote_requests(id) ON DELETE CASCADE,
    request_line_id     UUID REFERENCES quote_request_lines(id) ON DELETE SET NULL,
    line_order          SMALLINT NOT NULL DEFAULT 0,
    quantity            NUMERIC(12,4) NOT NULL CHECK (quantity > 0),
    product             VARCHAR(255) NOT NULL,
    part_number         VARCHAR(80) NOT NULL DEFAULT '',
    brand               VARCHAR(80) NOT NULL DEFAULT '',
    description         TEXT NOT NULL DEFAULT '',
    unit                VARCHAR(20) NOT NULL DEFAULT 'pza',
    cost                NUMERIC(15,4) NOT NULL DEFAULT 0,
    stock               INT NOT NULL DEFAULT 0,
    wholesaler_id       UUID REFERENCES wholesalers(id) ON DELETE SET NULL,
    wholesaler_name     VARCHAR(120) NOT NULL DEFAULT '',
    warehouse           VARCHAR(80) NOT NULL DEFAULT '',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX quote_request_pricing_request_idx ON quote_request_pricing_lines(request_id);

-- -----------------------------------------------------------------------------
-- Objetivo general — Cotizaciones (§8, §9, §13)
-- -----------------------------------------------------------------------------
CREATE TABLE quotes (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    folio                   VARCHAR(30) NOT NULL UNIQUE,
    client_id               UUID NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    request_id              UUID REFERENCES quote_requests(id) ON DELETE SET NULL,
    created_by              BIGINT REFERENCES users(id) ON DELETE SET NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'en_elaboracion'
                            CHECK (status IN ('en_elaboracion', 'pendiente_envio', 'enviada', 'aceptada', 'facturada')),
    validity_days           SMALLINT NOT NULL DEFAULT 15,
    global_margin_percent   NUMERIC(5,2) NOT NULL DEFAULT 30.00,
    tax_percent             NUMERIC(5,2) NOT NULL DEFAULT 16.00,
    notes                   TEXT NOT NULL DEFAULT '',
    subtotal                NUMERIC(15,4) NOT NULL DEFAULT 0,
    tax_amount              NUMERIC(15,4) NOT NULL DEFAULT 0,
    total                   NUMERIC(15,4) NOT NULL DEFAULT 0,
    sent_at                 TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX quotes_client_idx ON quotes(client_id);
CREATE INDEX quotes_status_idx ON quotes(status);
CREATE INDEX quotes_folio_idx ON quotes(folio);

CREATE TABLE quote_lines (
    id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    quote_id                UUID NOT NULL REFERENCES quotes(id) ON DELETE CASCADE,
    line_order              SMALLINT NOT NULL DEFAULT 0,
    quantity                NUMERIC(12,4) NOT NULL CHECK (quantity > 0),
    product                 VARCHAR(255) NOT NULL,
    part_number             VARCHAR(80) NOT NULL DEFAULT '',
    cost                    NUMERIC(15,4) NOT NULL DEFAULT 0,
    margin_percent          NUMERIC(5,2) NOT NULL DEFAULT 30.00,
    sale_price              NUMERIC(15,4) NOT NULL DEFAULT 0,
    amount                  NUMERIC(15,4) NOT NULL DEFAULT 0,
    warehouse               VARCHAR(80) NOT NULL DEFAULT '',
    selected_wholesaler_id  UUID REFERENCES wholesalers(id) ON DELETE SET NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX quote_lines_quote_idx ON quote_lines(quote_id);

-- Comparador mayoristas por partida (§7)
CREATE TABLE quote_line_offers (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    quote_line_id   UUID NOT NULL REFERENCES quote_lines(id) ON DELETE CASCADE,
    wholesaler_id   UUID NOT NULL REFERENCES wholesalers(id) ON DELETE CASCADE,
    cost            NUMERIC(15,4) NOT NULL,
    stock           INT NOT NULL DEFAULT 0,
    warehouse       VARCHAR(80) NOT NULL DEFAULT '',
    lead_days       SMALLINT NOT NULL DEFAULT 0,
    is_selected     BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (quote_line_id, wholesaler_id)
);

-- -----------------------------------------------------------------------------
-- Datos iniciales — Roles §2.1
-- -----------------------------------------------------------------------------
INSERT INTO roles (slug, name, description) VALUES
    ('administrador',     'Administrador',           'Acceso total y administración de usuarios'),
    ('gerente_compras',   'Gerente de Compras',      'Solicitudes, mayoristas, precios a ventas'),
    ('ventas',            'Personal de Ventas',      'Cotizaciones, clientes, envío'),
    ('gerencia',          'Gerencia',                'Dashboard y reportes'),
    ('solo_lectura',      'Solo lectura',            'Consulta de inventarios y reportes');

-- FK users → roles (después de poblar roles; users viene de php artisan migrate)
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_foreign;
ALTER TABLE users
    ADD CONSTRAINT users_role_id_foreign
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- Datos iniciales — Módulos y permisos §2.2
-- -----------------------------------------------------------------------------
INSERT INTO modules (slug, name, sort_order) VALUES
    ('dashboard',      'Dashboard',       1),
    ('solicitudes',    'Solicitudes',     2),
    ('cotizaciones',   'Cotizaciones',    3),
    ('clientes',       'Clientes',        4),
    ('mayoristas',     'Mayoristas',      5),
    ('reportes',       'Reportes',        6),
    ('configuracion',  'Configuración',   7),
    ('admin',          'Administración',  8);

INSERT INTO permissions (module_id, action, code) VALUES
    ((SELECT id FROM modules WHERE slug = 'dashboard'),     'view',   'dashboard.view'),
    ((SELECT id FROM modules WHERE slug = 'solicitudes'),   'view',   'solicitudes.view'),
    ((SELECT id FROM modules WHERE slug = 'solicitudes'),   'create', 'solicitudes.create'),
    ((SELECT id FROM modules WHERE slug = 'solicitudes'),   'edit',   'solicitudes.edit'),
    ((SELECT id FROM modules WHERE slug = 'solicitudes'),   'delete', 'solicitudes.delete'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'view',   'cotizaciones.view'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'create', 'cotizaciones.create'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'edit',   'cotizaciones.edit'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'delete', 'cotizaciones.delete'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'send',   'cotizaciones.send'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'approve', 'cotizaciones.approve'),
    ((SELECT id FROM modules WHERE slug = 'cotizaciones'),  'edit_margin', 'cotizaciones.edit_margin'),
    ((SELECT id FROM modules WHERE slug = 'clientes'),      'view',   'clientes.view'),
    ((SELECT id FROM modules WHERE slug = 'clientes'),      'create', 'clientes.create'),
    ((SELECT id FROM modules WHERE slug = 'clientes'),      'edit',   'clientes.edit'),
    ((SELECT id FROM modules WHERE slug = 'clientes'),      'delete', 'clientes.delete'),
    ((SELECT id FROM modules WHERE slug = 'clientes'),      'import', 'clientes.import'),
    ((SELECT id FROM modules WHERE slug = 'mayoristas'),    'view',   'mayoristas.view'),
    ((SELECT id FROM modules WHERE slug = 'mayoristas'),    'edit',   'mayoristas.edit'),
    ((SELECT id FROM modules WHERE slug = 'reportes'),      'view',   'reportes.view'),
    ((SELECT id FROM modules WHERE slug = 'configuracion'), 'view', 'configuracion.view'),
    ((SELECT id FROM modules WHERE slug = 'configuracion'), 'edit', 'configuracion.edit'),
    ((SELECT id FROM modules WHERE slug = 'admin'),         'view',   'admin.view'),
    ((SELECT id FROM modules WHERE slug = 'admin'),         'manage', 'admin.manage');

-- Administrador: todos los permisos
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'administrador';

-- Gerente de compras
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.slug = 'gerente_compras' AND p.code IN (
    'dashboard.view', 'solicitudes.view', 'solicitudes.create', 'solicitudes.edit',
    'cotizaciones.view', 'cotizaciones.create', 'cotizaciones.edit', 'cotizaciones.send',
    'cotizaciones.approve', 'cotizaciones.edit_margin',
    'clientes.view', 'clientes.create', 'clientes.edit', 'clientes.import',
    'mayoristas.view', 'reportes.view', 'configuracion.view'
);

-- Ventas
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.slug = 'ventas' AND p.code IN (
    'dashboard.view', 'solicitudes.view', 'solicitudes.create', 'solicitudes.edit',
    'cotizaciones.view', 'cotizaciones.create', 'cotizaciones.edit', 'cotizaciones.send',
    'clientes.view', 'clientes.create', 'clientes.edit',
    'mayoristas.view', 'configuracion.view'
);

-- Gerencia
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.slug = 'gerencia' AND p.code IN (
    'dashboard.view', 'solicitudes.view', 'cotizaciones.view', 'cotizaciones.approve',
    'clientes.view', 'mayoristas.view', 'reportes.view', 'configuracion.view'
);

-- Solo lectura
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.slug = 'solo_lectura' AND p.code IN (
    'mayoristas.view', 'reportes.view'
);

-- -----------------------------------------------------------------------------
-- Parámetros y catálogos demo
-- -----------------------------------------------------------------------------
INSERT INTO app_settings (id) VALUES (1);

INSERT INTO wholesalers (code, name, integration, active) VALUES
    ('CT',            'CT Internacional',   'api',       TRUE),
    ('EXEL',          'Exel del Norte',     'csv',       TRUE),
    ('INGRAM',        'Ingram Micro',       'api',       TRUE),
    ('ASC',           'ASC Direct',         'api',       FALSE),
    ('AZERTY',        'Azerty',             'scraping',  FALSE),
    ('EVERTEK',       'Evertek',            'api',       FALSE),
    ('CALCOM',        'Calcom',             'api',       FALSE),
    ('CVA',           'Grupo CVA',          'csv',       TRUE),
    ('ROWAN',         'Rowan Tech',         'api',       FALSE),
    ('SYSCOM',        'Syscom',             'api',       TRUE),
    ('EPCOM',         'Epcom',              'api',       FALSE),
    ('TVC',           'TVC',                'api',       FALSE),
    ('TECHDATA',      'Tech Data',          'api',       FALSE),
    ('AMAZON',        'Amazon',             'api',       FALSE),
    ('EBAY',          'eBay',               'api',       FALSE),
    ('INTCOMEX',      'Intcomex',           'xml',       FALSE),
    ('TECNOSINERGIA', 'Tecnosinergia',      'api',       FALSE),
    ('INTTELEC',      'Inttelec',           'api',       FALSE),
    ('TEAM',          'Team',               'scraping',  TRUE),
    ('PCH',           'PCH',                'ftp',       FALSE),
    ('MALABS',        'Malabs',             'api',       FALSE),
    ('DC',            'DC',                 'api',       FALSE),
    ('DAISYYEK',      'Daisy Yek',          'api',       FALSE),
    ('INALARM',       'Inalarm',            'api',       FALSE)
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    integration = EXCLUDED.integration,
    updated_at = NOW();

INSERT INTO inventory_items (part_number, product_name, stock, warehouse) VALUES
    ('C9200L-24T-4G-E', 'Switch Cisco C9200L', 2, 'CDMX'),
    ('KVR16N11S8/16',    'Memoria Kingston 16GB DDR4', 18, 'MTY'),
    ('U6-PLUS',          'Access Point Ubiquiti U6+', 0, 'GDL'),
    ('WD19',             'Docking Dell WD19', 4, 'CDMX'),
    ('LAT5540',          'Laptop Dell Latitude 5540', 3, 'MTY'),
    ('CAT6-305',         'Cable UTP Cat6 305m', 12, 'CDMX');

INSERT INTO clients (id, company, rfc, address, contact_name, email, whatsapp, payment_terms) VALUES
    ('a1000001-0001-4000-8000-000000000001', 'ACME Tecnología S.A. de C.V.', 'ACM010101ABC',
     'Av. Reforma 100, CDMX', 'Juan Pérez', 'compras@acme.mx', '+52 55 1234 5678', '30 días'),
    ('a1000001-0001-4000-8000-000000000002', 'Redes del Norte', 'RDN020202XYZ',
     'Monterrey, NL', 'Laura Martínez', 'laura@redesnorte.com', '+52 81 9876 5432', 'Contado'),
    ('a1000001-0001-4000-8000-000000000003', 'Grupo Industrial Vega', 'GIV030303VEG',
     'Guadalajara, JAL', 'Roberto Sánchez', 'roberto@vegaindustrial.mx', '+52 33 5555 1212', '45 días')
ON CONFLICT (id) DO UPDATE SET
    company = EXCLUDED.company,
    rfc = EXCLUDED.rfc,
    address = EXCLUDED.address,
    contact_name = EXCLUDED.contact_name,
    email = EXCLUDED.email,
    whatsapp = EXCLUDED.whatsapp,
    payment_terms = EXCLUDED.payment_terms,
    updated_at = NOW();

-- Usuarios demo (contraseñas alineadas con frontend/src/data/demo-users.ts)
INSERT INTO users (uuid, role_id, name, email, password, active) VALUES
    (gen_random_uuid(), (SELECT id FROM roles WHERE slug = 'administrador'),
     'Administrador del Sistema', 'admin@cotizacion.test',
     '$2y$10$H9hNaIViGUBCVRzt8qB15.3bsDqNJCImnIHAJV4q8D2eesXRZiBC6', TRUE),
    (gen_random_uuid(), (SELECT id FROM roles WHERE slug = 'gerente_compras'),
     'Luis Ramírez', 'compras@cotizacion.test',
     '$2y$10$JwuoPOv08FMJ4xpbhrx2zuROwAVfdwotONaG/MIoZzksM.bGGgvsm', TRUE),
    (gen_random_uuid(), (SELECT id FROM roles WHERE slug = 'ventas'),
     'María González', 'maria@empresa.com',
     '$2y$10$5Oa9qK30lFW.hDtj7B8uo.URCddHIsE.FupKUbX/XqVVVLbIGII9i', TRUE),
    (gen_random_uuid(), (SELECT id FROM roles WHERE slug = 'gerencia'),
     'Ana Dirección', 'gerencia@cotizacion.test',
     '$2y$10$IhhouOQVJZgWvZbAsQbHS.6XMDCuWpS36603lZ947BbKhV1kzPgJu', TRUE),
    (gen_random_uuid(), (SELECT id FROM roles WHERE slug = 'solo_lectura'),
     'Usuario Solo Lectura', 'lectura@cotizacion.test',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE)
ON CONFLICT (email) DO UPDATE SET
    role_id = EXCLUDED.role_id,
    active = EXCLUDED.active,
    name = EXCLUDED.name;

-- =============================================================================
-- Fin — Verificación rápida:
-- SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY 1;
-- =============================================================================
