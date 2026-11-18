# Guía de pruebas — recorrido completo

Esta guía recorre los 7 casos de uso con los usuarios de prueba, siguiendo los mismos trámites desde la solicitud hasta el certificado con QR y su validación pública. También incluye los casos de error más importantes. Parte de una **instalación nueva** (`php artisan migrate --seed`).

**Antes de empezar**
1. Levanta el firmador, el backend y el frontend (ver el [README](../README.md#ejecución)) y abre `http://localhost:5173`.
2. Ten a mano **3 o 4 archivos PDF** cualesquiera: harán de documentos escaneados y de resolución.
3. La sesión se guarda **por pestaña**: abre una pestaña por rol y cambia entre ellas sin cerrar sesión.
4. Los códigos de trámite son correlativos (`LEG-2026-000001`, `DES-2026-000002`, …). **Anota el código que te dé el sistema** en cada paso.

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@uagrm.edu.bo` | `Admin123*` |
| Recepción | `recepcion@uagrm.edu.bo` | `Recepcion123*` |
| Vicerrectora (Juana Borja) | `vicerrector@uagrm.edu.bo` | `Vice123*` |
| Rectorado (Reinerio Vargas) | `rectorado@uagrm.edu.bo` | `Rectorado123*` |
| Decano suplente (Juan Perez) | `suplente@uagrm.edu.bo` | `Suplente123*` |
| Estudiante (Carlos Rojas) | `estudiante@uagrm.edu.bo` | `Estudiante123*` — Registro **219012345**, C.I. **7654321** |

---

## 1. CU1 · Usuarios — Administrador
1. **Gestión de Usuarios** → crea un usuario con rol **ESTUDIANTE** y algún cargo → ❌ *"un ESTUDIANTE no puede tener cargo"*.
2. Créalo sin cargo → ✅. Edítalo, **desactívalo** e intenta entrar con él en otra pestaña → ❌ *"cuenta inactiva"*. Luego reactívalo.
3. Intenta desactivar tu propia cuenta → ❌.
4. **Perfil** (cualquier rol) → cambia el teléfono.

## 2. CU2 · Certificados de firma — Rectorado
1. **Delegación de Autoridad** → **Emitir Certificado Digital de Firma**:
   - **Juana Borja Saavedra** · 1 año · PIN **123456**
   - **Juan Perez** · 1 año · PIN **654321**
2. Ambos aparecen como **Vigente**, emitidos por la *AC Raiz de Prueba UAGRM*.
3. *(Todavía no registres la delegación: primero verás que el suplente no puede firmar.)*

## 3. CU3 · Solicitudes — Estudiante
1. **Guía Procesal y Requisitos** → revisa el flujo y la cadena de firmas precedentes de cada documento.
2. **Solicitar Trámite** → **Legalización** · destino **EXTERIOR** · país **España** · prioridad **URGENTE**:
   - *Certificado de horas reloj* · 2 páginas · adjunta un PDF.
   - *Malla curricular* · 1 página · adjunta otro PDF.

   → anota el código (**trámite EXTERIOR**). Queda **INGRESADO**.
3. **Solicitar Trámite** → **Descuento de diplomado**: unidad *Escuela de Posgrado FICCT*, diplomado *Ciencia de Datos*, gestión del año en curso, avance *AA-001*, régimen **SEMESTRAL**, año de la última nota = año actual, periodo **2** (o **1**, si estás en el primer semestre) → anota el código (**DESCUENTO**). Queda catalogado **CON FINANCIAMIENTO**.

## 4. CU3 + CU4 · Revisión — Recepción ↔ Estudiante
**Recepción → Revisión y Expedientes → trámite EXTERIOR:**
1. Arriba aparece *"Solicitud en línea pendiente de recepción"*. En cada documento usa **Revisar documento** para ver el archivo y luego **Validar firmas y valorado** (N° *V-001* / *V-002*, importe **15**). El trámite pasa a **EN REVISIÓN**.
2. **Registrar observación**: *Foja ilegible* sobre la malla → el trámite pasa a **OBSERVADO** y el SLA muestra *"Detenido por observación"*.

**Estudiante → Mis Trámites → trámite EXTERIOR:**

3. Sube el archivo de **subsanación** → el trámite vuelve a **EN REVISIÓN** con el expediente en versión 2.

**Recepción:**

4. Pulsa **Confirmar revisión y derivar a despacho** sin marcar los requisitos → el botón está deshabilitado y abajo se lista lo que falta.
5. Marca los 4 requisitos → **Confirmar revisión y derivar a despacho** ✅.
6. Abre el **DESCUENTO** → **Recibir e iniciar revisión** → marca sus 4 requisitos → envíalo a despacho.
7. **Nuevo Trámite** (en ventanilla) → estudiante Carlos Rojas · Legalización **NACIONAL** · *Malla curricular* · 1 página · con PDF, valorado *V-003* por **15** y firmas verificadas. Queda **EN REVISIÓN** → marca sus requisitos → envíalo a despacho (**trámite NACIONAL**).
8. **Nuevo Trámite** otra vez (otro documento) y **déjalo en revisión** (**trámite SLA**, para el paso 10).

## 5. CU5 · Despacho y firma
**Vicerrectora → Bandeja de Despacho y Firma:**
1. Arriba aparece **TITULAR** con su certificado. El orden es URGENTE primero.
2. **Revisar** el trámite EXTERIOR → visor con el legajo (carátula y "Foja X de N") y, al lado, el detalle con el hash SHA-256.
3. Marca **EXTERIOR** y **DESCUENTO** → **Firmar Lote Consolidado** → modalidad **Remota**, ubicación *"La Paz – Comisión CEUB"*:
   - PIN **000000** → ❌ *"PIN incorrecto"*.
   - PIN **123456** → ✅. EXTERIOR pasa a **DERIVADO A RECTORADO** y DESCUENTO a **FIRMADO**.
4. *(Opcional, SF-3: con **Devolver a revisión** un expediente vuelve a OBSERVADO.)*

**Suplente → Bandeja de Despacho:**

5. Aparece **SIN HABILITACIÓN – no tiene delegación ACTIVA**.

**Rectorado → Delegación de Autoridad → Registrar suplencia:**

6. Titular *Juana Borja* · suplente *Juan Perez* · Resolución **RECTORAL N° R-045/2026** · motivo *Viaje* · desde hoy hasta dentro de 5 días · PDF de la resolución.

**Suplente:** recarga la bandeja.

7. Ahora es **INTERINO** *"…según Resolución Rectoral N° R-045/2026…"* → firma el **trámite NACIONAL** (individual, presencial, PIN **654321**).

## 6. CU5 SF-2 · Visado — Rectorado
**Bandeja Apostilla / Exterior → Revisar el trámite EXTERIOR:**
1. La verificación muestra 1 firma ✅ íntegra y ✅ con cadena de confianza.
2. **Confirmar sello institucional** → el PDF tiene **2 sellos visibles** y la verificación indica 2 firmas válidas.

## 7. CU6 · Certificados — Recepción
**Emisión de Certificados QR → Listos para emisión** muestra NACIONAL (firma interina), EXTERIOR (visado) y DESCUENTO.
1. **Emitir con QR** en los tres → se generan **CL-…** (legalización) y **CD-…** (constancia), y los trámites pasan a **CONCLUIDO**.
2. **Ver / Imprimir** → revisa la leyenda: titular remota o interino con su resolución.
3. En la constancia → **Entrega** → formulario *F-0001* ✅. Intenta registrarla de nuevo → ❌ *"ya se entregó"*.
4. **Abrir validador público** en cualquiera de ellos.

## 8. CU6 · Portal público (sin sesión)
Usa una pestaña nueva, o los botones de la pantalla de login.
1. **Seguimiento** con Registro **219012345** y C.I. **7654321** → ves tus trámites, el avance del SLA y las fases. Los concluidos tienen **Descargar PDF/A**.
2. Con un C.I. incorrecto → ❌ *"no se encontraron trámites"*.
3. **Validar certificado**:
   - Desde el enlace del QR → **VÁLIDO**, con la autoridad firmante.
   - Cambia una letra del parámetro `hash=` en la URL → **HASH ALTERADO**.
   - Escribe un código inventado → **NO ENCONTRADO**.
   - **Verificar archivo PDF** → sube el certificado descargado: firma íntegra ✅. Edítalo con cualquier programa y súbelo de nuevo → ❌.

## 9. CU6 SF-3 · Anulación y reemisión
1. **Vicerrectora → Certificados Emitidos** → **Anular** el certificado del EXTERIOR con el motivo *"Error material en el país de destino"*.
2. En el validador ese código ahora dice **ANULADO**, con el motivo, y la descarga se bloquea.
3. **Recepción** → el trámite vuelve a "Listos" como **Reemisión** → emítelo de nuevo y sale con un número nuevo.
4. **Estudiante** → la 🔔 muestra *"El certificado … fue ANULADO"*.

## 10. CU7 · Monitoreo SLA
1. **Recepción → Alertas SLA**: ves las tarjetas, la tabla y el **trámite SLA** en verde.
2. Para ver alertas sin esperar 3 días hábiles, **retrocede el reloj** de ese trámite. Ejecuta en tu cliente SQL (solo cambia sus fechas):
   ```sql
   UPDATE tramite_tiempo_fase SET fecha_inicio = (
       SELECT LOCALTIMESTAMP - make_interval(mins => m)
         FROM generate_series(1, 60*24*30) AS m
        WHERE fn_horas_habiles(LOCALTIMESTAMP - make_interval(mins => m), LOCALTIMESTAMP) >= 60
        ORDER BY m LIMIT 1)
    WHERE fecha_fin IS NULL
      AND tramite_id = (SELECT id FROM tramite WHERE codigo = 'LEG-2026-00000X');  -- código del trámite SLA
   ```
3. **Actualizar** en el monitor → semáforo **AMARILLO** y alerta **PREVENTIVA**. La 🔔 de Recepción muestra la alerta.
4. Repite el SQL con `>= 80` → **ROJO** y aparece también la alerta **VENCIDA**.
5. Haz clic en el trámite → **desglose por fase** (horas en INGRESADO, EN REVISIÓN…).
6. **Marcar atendida** → desaparece. Con *"Mostrar atendidas"* ves quién la atendió y cuándo.
7. **Administrador → Monitoreo SLA → Parámetros**: en *Legalización* pon plazo **120** y alerta **50 %** → el semáforo cambia al instante. Luego **"Usar la general"** para volver a los valores originales.

## 11. Cierre — auditoría
1. **Rectorado → Delegación** → **Revocar** la suplencia. El suplente vuelve a ver *"sin habilitación"*.
2. **Administrador → Bitácora y Auditoría** → la integridad sale ✅ y quedan registrados los accesos, las firmas, los visados, los certificados y las alertas.
3. *(Opcional)* En la bandeja de despacho, descarga el **certificado raíz de la AC**. Instálalo como confiable en Adobe Reader y abre un PDF firmado: las firmas aparecerán como **válidas**.

---

Para empezar de nuevo: `php artisan migrate:fresh --seed` (borra todos los datos).
