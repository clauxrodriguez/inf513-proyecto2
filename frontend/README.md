# Frontend — SPA (React 19 + Vite + Tailwind CSS 4)

Capa de **presentación** del Sistema de Legalizaciones. Consume la API REST del [backend](../backend) por HTTP con JSON.

La instalación y la ejecución del proyecto completo están en el [README principal](../README.md).

## Comandos

```bash
npm install
npm run dev       # desarrollo en http://localhost:5173
npm run build     # producción en dist/ (base /inf513/grupo20sa/proyecto2/)
npm run lint      # oxlint
```

Por defecto la API se busca en `http://127.0.0.1:8000/api`. Para usar otra dirección, copia `.env.example` a `.env.local` y ajusta `VITE_API_URL`.

## Estructura

```
src/
├── api/            Único punto que usa Axios (un módulo por caso de uso) y archivos.js para abrir PDF con sesión
├── context/        AuthContext: sesión (token en sessionStorage) y menú dinámico por rol
├── components/     Componentes por módulo: tramites/, delegaciones/, despacho/, certificados/, sla/, publico/, ui/
└── pages/          Una página por ruta; pages/publico/ contiene las páginas sin sesión
```

| Ruta | Página | Roles |
|---|---|---|
| `/tramites`, `/mis-tramites` | Bandeja y detalle de trámites | Recepción, autoridades / Estudiante |
| `/tramites/nuevo`, `/solicitar` | Registro de trámites | Recepción / Estudiante |
| `/delegaciones` | Suplencias y certificados de firma | Todos (registran Rectorado) |
| `/despacho` | Bandeja de despacho y firma digital | Vicerrector/a, Suplente |
| `/rectorado` | Visado previo a la Apostilla | Rectorado |
| `/certificados` | Emisión y anulación de certificados con QR | Recepción (emite), autoridades |
| `/monitoreo-sla` | Monitor SLA de 72 h hábiles | Recepción, autoridades, Administrador |
| `/usuarios`, `/bitacora` | Administración y auditoría | Administrador |
| `/seguimiento`, `/validar-certificado` | Portal del estudiante y validador del QR | **Públicas** |

El menú lateral se construye con la tabla `menu_opcion` según el rol. La campana de la barra superior muestra las notificaciones de cada usuario.
