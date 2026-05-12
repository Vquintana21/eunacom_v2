# Reporte del Sistema de Estilos — Eunacom v2

> Generado: 2026-05-12  
> Propósito: Auditoría previa al plan de migración a colores institucionales.  
> Estado: solo lectura — ningún archivo fue modificado.

---

## 1. Frameworks CSS utilizados

| Framework | Versión | Dónde se carga | Alcance |
|---|---|---|---|
| Bootstrap CSS | `5.3.0` (jsDelivr CDN) | `examen.php:7` | Solo `examen.php` |
| Bootstrap JS Bundle | `5.3.0` (jsDelivr CDN) | `examen.php:599` | Solo `examen.php` |
| Bootstrap Icons | `1.13.1` (jsDelivr CDN) | `materiales.php:191` | Solo `materiales.php` |
| Font Awesome | `6.4.0` (cdnjs) | `examen.php:8` | Solo `examen.php` |

**Conclusión:** No hay un framework CSS aplicado globalmente. Bootstrap solo aparece en `examen.php`; los íconos de Bootstrap solo en `materiales.php`. El resto del sitio es CSS propio. No existe `package.json`, ni build tool, ni Bootstrap autohospedado.

---

## 2. Archivos CSS propios

Solo existe **un único archivo CSS externo**:

- `css/style.css` — 727 líneas, ~13.5 KB  
  Encabezado: *"SISTEMA EUNACOM - ESTILOS GLOBALES"*

**Problema crítico:** solo se enlaza en una vista:

```
recuperar-password.php:226 → <link rel="stylesheet" href="css/style.css">
```

Ninguna otra vista lo importa. El resto del proyecto usa **17 bloques `<style>` inline**.

### Tamaño de estilos inline por archivo

| Archivo | Líneas dentro de `<style>` |
|---|---|
| `simulacro_examen.php` | 716 |
| `materiales.php` | 545 |
| `entrenamiento.php` | 444 |
| `index.php` | 438 |
| `examen.php` | 320 |
| `registro.php` | 217 |
| `login.php` | 196 |
| `simulacro_revision.php` | propio |
| `simulacro_resultados.php` | propio |
| `simulacro_intermedio.php` | propio |
| `simulacro_inicio.php` | propio |
| `generar_simulacro.php` | propio |
| `descargas_masivas.php` | propio |
| `perfil.php` | propio |
| `pdf-viewer.php` | propio |
| `download.php` | propio (2 bloques) |

Adicionalmente, hay **~79 atributos `style="…"` inline** repartidos en el HTML, con mayor concentración en:

| Archivo | Ocurrencias `style="` |
|---|---|
| `simulacro_resultados.php` | 19 |
| `materiales.php` | 10 |
| `entrenamiento.php` | 10 |
| `recuperar-password.php` | 8 |
| `simulacro_inicio.php` | 6 |
| `simulacro_examen.php` | 6 |
| `generar_simulacro.php` | 6 |

---

## 3. Colores en uso

Hay **99 valores hexadecimales únicos** entre `style.css` y todos los `<style>` inline. No existen CSS custom properties (`--variable`). Todos los colores son literales repetidos.

### 3.1 Paleta de marca actual (gradient principal)

```
linear-gradient(135deg, #667eea 0%, #764ba2 100%)
```

| Color | Rol actual | Usos |
|---|---|---|
| `#667eea` | Primario / violeta-azul | 41 |
| `#764ba2` | Primario / púrpura | 28 |

Aparece en: `body`, `register-header`, `welcome-banner`, `.module-btn`, `.btn-primary`, `button[type="submit"]`, breadcrumb links, stat numbers, input focus.

### 3.2 Grises / neutros (paleta Flat UI)

| Color | Rol | Usos |
|---|---|---|
| `#2c3e50` | Títulos, texto fuerte | 77 |
| `#7f8c8d` | Texto secundario | 77 |
| `#95a5a6` | Texto suave | 16 |
| `#ecf0f1` | Fondo gris muy claro | 6 |
| `#e9ecef` | Bordes, separadores | 42 |
| `#f8f9fa` | Fondos suaves | 27 |
| `#6c757d` | Textos helper/small | 6 |

### 3.3 Acentos y estados

| Color | Rol | Usos |
|---|---|---|
| `#3498db` | Azul info / acción | 48 |
| `#2980b9` | Azul hover | 14 |
| `#e74c3c` | Rojo error / logout | 18 |
| `#c0392b` | Rojo hover | 9 |
| `#27ae60` | Verde éxito | 15 |
| `#229954` | Verde hover | 7 |
| `#28a745` | Verde (variante Bootstrap) | 12 |
| `#dc3545` | Rojo (variante Bootstrap) | 11 |
| `#f39c12` | Naranja advertencia | 10 |
| `#e67e22` | Naranja (variante) | 3 |
| `#ffc107` | Amarillo warning | 11 |

### 3.4 Colores de alertas / estados semánticos

| Uso | Fondo | Texto | Borde |
|---|---|---|---|
| Success | `#d4edda` | `#155724` | `#c3e6cb` |
| Error | `#f8d7da` | `#721c24` | `#f5c6cb` |
| Warning | `#fff3cd` | `#856404` | `#ffeeba` |
| Info | `#d1ecf1` | `#0c5460` | `#bee5eb` |

### 3.5 Colores específicos de módulos `simulacro_*`

| Color | Contexto |
|---|---|
| `#3ab4f2`, `#1c92d2`, `#1b7db8` | Tonos azules en vistas de examen |
| `#f2fcfe`, `#e3f2fd` | Fondos info claros |
| `#0ea5e9`, `#0369a1`, `#0c4a6e` | Azul profundo / código recuperación |
| `#fef3c7`, `#fbbf24`, `#92400e` | Warning en recuperar-password |

### 3.6 Colores aislados (uso único o puntual)

- `#0d6efd` — "Azul Bootstrap" con comentario explícito en `index.php:519`
- `#2196f3` — Azul Material Design (5 usos en simulacros)
- `#2ecc71` — Verde esmeralda Flat UI (4 usos)
- `#fee`, `#efe`, `#cfc`, `#fcc`, `#c33` — Abreviaturas de 3 dígitos en alertas antiguas

---

## 4. Estructura de vistas globales

**No hay layout compartido.** No se encontró ningún `include` o `require` de header, footer, nav o layout parcial. Cada `.php` repite su propio `<!DOCTYPE>`, `<head>`, header visual y navegación.

### 4.1 Header

Patrón visual replicado por copia/pega como `<div class="header">…</div>`:

| Archivo | Línea(s) |
|---|---|
| `index.php` | 614 |
| `entrenamiento.php` | 689 |
| `perfil.php` | 405 |
| `materiales.php` | 740, 805, 970, 983 |
| `descargas_masivas.php` | — |
| `pdf-viewer.php` | — |
| `simulacro_examen.php` | — |
| `simulacro_resultados.php` | — |
| `simulacro_revision.php` | — |
| `examen.php` | — |

**Clases del header** definidas en `css/style.css:44–85`: `.header`, `.header-left`, `.header-right`, `.user-info`, `.user-name`, `.user-email`. Como `style.css` no se enlaza en estas vistas, cada una redefine estas reglas en su `<style>` interno.

Contenido típico del header:
- Izquierda: logo/título de la sección + subtítulo
- Derecha: nombre del usuario, email, botón logout

### 4.2 Navbar / Navegación

No existe un componente `<nav>` o `navbar` consistente. La navegación principal se implementa como:
- Grid de módulos en `index.php` (`.modules-grid` / `.module-card`)
- Botones de retorno (`btn-home-green`, `btn-home-blue`, `btn-logout`)
- Links `link-secundario` / `link-primary`

### 4.3 Footer

No hay clase `.footer` ni `<footer>` común. El sitio no tiene pie de página compartido.

### 4.4 Sidebar

Únicamente en `simulacro_examen.php` (líneas 269–741):

- `.sidebar` — panel lateral de preguntas
- `.sidebar-toggle` — botón de hamburguesa (solo móvil)
- `.sidebar-overlay` — capa oscura al abrir
- `.sidebar-close` — botón ✕ dentro del sidebar
- JS de toggle en líneas 1594–1620

No existe sidebar en ninguna otra vista.

### 4.5 Breadcrumb

Definido en `css/style.css:528` (`.breadcrumb`, `.breadcrumb-separator`, `.breadcrumb-current`). Uso esporádico y manual en algunas vistas.

---

## 5. Clases Bootstrap funcionales detectadas

> Solo identificación. No se tocó ningún archivo.

### 5.1 Botones (`btn-*`)

**Bootstrap genuino** (solo funciona en `examen.php` donde Bootstrap está cargado):

| Clase | Usos |
|---|---|
| `btn-primary` | 8 |
| `btn-secondary` | 6 |
| `btn-success` | 4 |
| `btn-danger` | 1 |

**Custom del proyecto** (redefinidas en CSS propio, chocan con Bootstrap si se cargara):

| Clase | Usos |
|---|---|
| `btn-filtro` | 8 |
| `btn-home-green` | 5 |
| `btn-logout` | 4 |
| `btn-download` | 2 |
| `btn-cancel` | 2 |
| `btn-small` | 2 |
| `btn-volver`, `btn-top`, `btn-toggle`, `btn-primary-custom` | 1 c/u |
| `btn-previous`, `btn-next`, `btn-perfil` | 1 c/u |
| `btn-modal-guardar`, `btn-modal-cancelar` | 1 c/u |
| `btn-mark`, `btn-home`, `btn-guardar` | 1 c/u |
| `btn-finish`, `btn-continuar`, `btn-confirm` | 1 c/u |
| `btn-cambiar-pass`, `btn-bottom` | 1 c/u |

### 5.2 Alertas (`alert-*`)

| Clase | Tipo | Usos |
|---|---|---|
| `alert-success` | Bootstrap-compatible | 3 |
| `alert-danger` | Bootstrap-compatible | 3 |
| `alert-error` | Custom (no Bootstrap) | 3 |
| `alert-warning` | Mixto | — |
| `alert-info` | Mixto | — |

Variantes custom adicionales: `mensaje-exito`, `mensaje-error`, `config-alert`, `expiracion-warning`.

### 5.3 Badges (`badge`/`badge-*`)

| Clase | Usos |
|---|---|
| `badge` (genérico) | 9 |
| `badge-success` | 1 |
| `badge-rut` | 1 |
| `badge-profesional` | 1 |
| `badge-pasaporte` | 1 |
| `badge-estudiante` | 1 |

### 5.4 Otras clases con nomenclatura Bootstrap

| Clase | Usos | Nota |
|---|---|---|
| `container` | 17 | Custom, no Bootstrap |
| `card` | 51 | Custom |
| `card-title` | 5 | Custom |
| `row` | 2 | Bootstrap solo en `examen.php` |
| `d-flex` | 3 | Bootstrap solo en `examen.php` |
| `justify-content-between` | 2 | Bootstrap |
| `align-items-start` | 2 | Bootstrap |
| `m-5`, `mt-2` | 3, 2 | Bootstrap |
| `text-success`, `text-danger`, `text-muted`, `text-center` | 1 c/u | Bootstrap |

---

## 6. Resumen: nivel de centralización

**Nivel general: muy bajo / disperso.**

### Estado actual

| Aspecto | Estado |
|---|---|
| Archivo CSS central | Existe (`css/style.css`) pero ignorado por 20+ vistas |
| Layout compartido (include/require) | ❌ No existe |
| Variables CSS (`:root`) | ❌ No existen |
| Paleta unificada | ❌ 99 hex únicos, sin single source of truth |
| Framework CSS global | ❌ Bootstrap solo en 1 vista |
| Estilos inline `<style>` | ⚠️ 17 bloques, hasta 716 líneas por archivo |
| Atributos `style=""` inline | ⚠️ ~79 ocurrencias |
| Colisión de nombres con Bootstrap | ⚠️ `btn-primary`, `btn-secondary`, `card`, `alert-*` redefinidos |

### Colores duplicados / casi-idénticos a unificar

| Color A | Color B | Rol compartido |
|---|---|---|
| `#27ae60` | `#28a745` | Verde éxito |
| `#e74c3c` | `#dc3545` | Rojo error |
| `#3498db` | `#2196f3` / `#0d6efd` | Azul acción |
| `#f39c12` | `#e67e22` | Naranja advertencia |

---

## 7. Inventario para el plan de colores institucionales

Este bloque reúne todo lo que necesita reemplazarse al migrar a colores institucionales:

### Elementos que usan el gradient de marca actual

```css
/* Reemplazar en estos selectores (están en style.css y en inline de cada .php): */
body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.register-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.welcome-banner { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.module-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.btn-primary, button[type="submit"] { background: linear-gradient(...); }
input:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
.stat-number { color: #667eea; }
.link-secundario, .link-primary { color: #667eea; }
.breadcrumb a { color: #667eea; }
.codigo-box { border-color: #0ea5e9; }
.codigo-display { color: #0369a1; }
```

### Archivos donde ocurre `#667eea` o `#764ba2`

Buscar y reemplazar en:
- `css/style.css` (fuente base)
- `index.php` (438 líneas de `<style>`)
- `login.php` (196 líneas)
- `registro.php` (217 líneas)
- `entrenamiento.php` (444 líneas)
- `materiales.php` (545 líneas)
- `simulacro_inicio.php`
- `simulacro_examen.php`
- `simulacro_resultados.php`
- `simulacro_revision.php`
- `simulacro_intermedio.php`
- `recuperar-password.php`
- `perfil.php`
- `pdf-viewer.php`
- `descargas_masivas.php`
- `generar_simulacro.php`
- `download.php`
- `examen.php`

### Pasos recomendados para el plan de migración

1. **Definir paleta institucional** → mapear colores nuevos a los roles actuales (primario, hover primario, texto fuerte, texto suave, éxito, error, warning, info).
2. **Centralizar primero**: enlazar `css/style.css` en todas las vistas y extraer los `<style>` inline a ese archivo o a módulos por vista bajo `css/`.
3. **Introducir variables CSS** en `css/style.css`:
   ```css
   :root {
     --color-primary: /* nuevo color institucional */;
     --color-primary-dark: /* hover */;
     --color-text-strong: #2c3e50;
     --color-text-soft: #7f8c8d;
     --color-success: #27ae60;
     --color-error: #e74c3c;
     --color-warning: #f39c12;
   }
   ```
4. **Reemplazar literales** por variables en todo el CSS.
5. **Revisar colisiones Bootstrap** en `examen.php` antes de aplicar el nuevo theme.
6. **Unificar pares duplicados** (`#27ae60`/`#28a745`, etc.).
