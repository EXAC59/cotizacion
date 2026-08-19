# Feature: Revisión de Solicitudes (Quote Requests Review)
## Resolución de Bug en Migración y Tests

---

### Contexto
- **Fecha**: 2026-08-12
- **Proyecto**: Cotización (Laravel + Vue/TS)
- **Feature**: Sistema de revisión de solicitudes de cotización
- **Problema**: Test `el_primer_revisor_gana` fallaba tras migración de hoy

---

### Arquitectura de la Feature

#### Base de datos
```sql
-- Migración: 2026_08_11_100000_add_reviewed_columns_to_quote_requests
ALTER TABLE quote_requests ADD COLUMN reviewed_by BIGINT UNSIGNED NULL;
ALTER TABLE quote_requests ADD COLUMN reviewed_at TIMESTAMP NULL;
ALTER TABLE quote_requests ADD FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
```

#### Backend (Laravel)
| Componente | Responsabilidad |
|---|---|
| `SolicitudLecturaService::marcarRevisada()` | Marca revisada (idempotente: primer revisor gana) |
| `SolicitudController::show()` | Dispara revisión al abrir detalle |
| `DashboardAnalyticsService::pendingReviewRequests()` | Lista solicitudes sin revisar |

#### Frontend (Vue/TypeScript)
| Archivo | Uso |
|---|---|
| `solicitudes-api.ts` | Tipos `reviewed_by`, `reviewed_by_name`, `reviewed_at` |
| `DashboardPage.tsx` | Alertas "Solicitudes pendientes de revisión" |

---

### El Bug

#### Síntoma
```
Tests\Feature\SolicitudRevisionTest::el_primer_revisor_gana
Failed asserting that '1' is identical to 1.
```

#### Causa Raíz
La migración original (2026_08_11_100000) declaraba:
```php
// INCORRECTO - antes del fix
$table->uuid('reviewed_by')->nullable()->after('status');
$table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
```

**Problemas:**
1. `users.id` es **BIGINT** (`$table->id()`)
2. `quote_requests.created_by` usa correctamente `foreignId()` (BIGINT)
3. `reviewed_by` como **UUID** (VARCHAR) → mismatch de tipos
4. FK de UUID a BIGINT no se crea en Postgres/MySQL
5. Al leer desde BD: `'1'` (string) vs `1` (int) → `assertSame` falla

---

### La Solución

#### Migración Corregida
```php
// CORRECTO - después del fix
Schema::table('quote_requests', function (Blueprint $table) {
    if (! Schema::hasColumn('quote_requests', 'reviewed_by')) {
        $table->foreignId('reviewed_by')        // BIGINT UNSIGNED
              ->nullable()
              ->after('status')
              ->constrained('users')             // FK automática a users.id
              ->nullOnDelete();
    }
    // reviewed_at sin cambios
});
```

**Cambios clave:**
- `uuid()` → `foreignId()` (coherente con `created_by`)
- `foreign()` manual → `constrained('users')` (convención Laravel)
- Tipo: BIGINT UNSIGNED → coincide con `users.id`

---

### Verificación

#### Tests de la Feature (6 tests) ✅
| Test | Antes | Después |
|---|---|---|
| `se_crea_sin_revisor` | ✅ | ✅ |
| `marca_como_revisada_al_abrir_el_detalle` | ✅ | ✅ |
| **`el_primer_revisor_gana`** | ❌ **FAIL** | ✅ **PASS** |
| `el_dashboard_lista_solo_las_sin_revisar` | ✅ | ✅ |
| `el_dashboard_lista_solo_las_en_elaboracion` | ✅ | ✅ |
| `no_permite_editar_lineas_una_vez_enviada` | ✅ | ✅ |
| `permite_editar_lineas_en_elaboracion` | ✅ | ✅ |

#### Suite Completa (250 tests)
- **247 pasan** (incluyendo los 6 de revisión)
- **3 fallos preexistentes** (no relacionados):
  - `CvaConnectorTest::test_maps_precios_stock...` (case-sensitivity warehouse name)
  - `ApiAuthorizationTest::ventas_can_dispatch_comparator...` (permiso 403 vs 202)
  - `ComparatorSettingsTest::it_manages_user_comparator_preferences` (permiso 403 vs 200)

---

### Impacto

| Métrica | Valor |
|---|---|
| **Líneas cambiadas** | 1 migración (3 líneas efectivas) |
| **Tests nuevos que pasan** | 1 (el que fallaba) |
| **Regresiones** | 0 |
| **Tiempo de fix** | < 10 min |

---

### Lecciones / Buenas Prácticas

1. **Consistencia de tipos FK**: Siempre usar `foreignId()` cuando la tabla referenciada usa `$table->id()` (BIGINT)
2. **Convención Laravel**: `constrained('users')` > `foreign()` manual + `references()`
3. **Verificar migraciones en BD real**: SQLite en memoria (tests) permite tipos que Postgres/MySQL rechazan
4. **Test idempotencia**: El test `el_primer_revisor_gana` valida regla de negocio crítica

---

### Próximos Pasos (Opcional)

- [ ] Revisar/fixear los 3 tests preexistentes del comparador
- [ ] Documentar convención FK en `CLAUDE.md` o guía de contribución
- [ ] Añadir test de migración en CI con Postgres real