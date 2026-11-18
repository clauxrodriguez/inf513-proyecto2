-- =====================================================================
--  SISTEMA DE LEGALIZACIONES CON FIRMA DIGITAL
--  Vicerrectorado - Universidad Autónoma Gabriel René Moreno (UAGRM)
--  Base de datos PostgreSQL (>= 13) - Proyecto académico INF513
--  Sin extensiones: usa sha256() y gen_random_uuid() nativos de PostgreSQL
--
--    CU1 Gestión de Usuarios (Vicerrector, Recepción, Suplentes, Estudiantes)
--    CU2 Gestión de Delegación de Autoridad e Indicadores de Gestión
--    CU3 Gestión de Trámites, Seleccionar Documentos, Validar Requisitos y Compilar Expediente
--    CU4 Gestión de Enrutar Flujo, Visualizar Guía Procesal, Gestionar Observaciones y Bitácora
--    CU5 Gestión de Despachar, Previsualizar Legajos y Ejecutar Firma Digital Consolidada o Remota
--    CU6 Gestión de Certificado Oficial con QR y Habilitar Portal de Seguimiento Estudiantil
--    CU7 Gestión de Monitoreo y Tiempos de Atención y Alertas SLA (72 Horas Hábiles)
--    CU8 Reportes y Estadísticas
--
--  Trámites atendidos:
--    A. LEGALIZACION         - documentos académicos con destino NACIONAL o EXTERIOR (apostilla)
--    B. DESCUENTO_DIPLOMADO  - descuento del 50% en diplomados (Res. Vicerrectoral 01-28/2013)
--
--  Instalación (elegir UNA forma, sobre una base de datos VACÍA):
--    a) Recomendada, desde backend/:   php artisan migrate --seed
--       (la migración 0001_01_01_000000_crear_esquema_legalizaciones ejecuta este archivo
--        y el seeder crea los usuarios de prueba con sus contraseñas)
--    b) Manual con psql:
--         CREATE DATABASE db_legalizaciones ENCODING 'UTF8';
--         ALTER DATABASE db_legalizaciones SET timezone TO 'America/La_Paz';
--         \c db_legalizaciones
--         \i legalizaciones.sql
--       y luego, desde backend/:  php artisan db:seed
--
--  Para que la bitácora registre al usuario, la aplicación ejecuta en cada petición:
--       SELECT set_config('app.usuario_id', '<id>', false);
-- =====================================================================
SET search_path TO public;

-- =====================================================================
-- TIPOS ENUM (reemplazan a las tablas que solo guardaban estados o tipos)
-- =====================================================================
-- RF-01: la Autoridad Interina no es un rol fijo: la otorga una delegación ACTIVA (CU2)
CREATE TYPE rol_usuario         AS ENUM ('ADMINISTRADOR', 'VICERRECTOR', 'RECTORADO', 'RECEPCION',
                                         'SUPLENTE', 'ESTUDIANTE');
-- Puesto institucional real (distinto del rol de acceso). Anexo Delegaciones: el suplente debe ser DECANO
CREATE TYPE cargo_institucional AS ENUM ('RECTOR', 'VICERRECTOR', 'SECRETARIO_GENERAL', 'DECANO', 'VICEDECANO',
                                         'DIRECTOR_CARRERA', 'JEFE_ADMISIONES', 'JEFE_DAGA',
                                         'ENCARGADO_LEGALIZACIONES', 'FUNCIONARIO_VENTANILLA', 'SECRETARIO_RECTORADO');
-- Art. 46 Estatuto Orgánico: viaje, licencia, baja médica o el Vicerrector asume provisionalmente el Rectorado
CREATE TYPE motivo_delegacion   AS ENUM ('VIAJE', 'COMISION_OFICIAL', 'AUSENCIA', 'VACACION', 'LICENCIA',
                                         'BAJA_MEDICA', 'ASUME_RECTORADO', 'OTRO');
CREATE TYPE estado_delegacion   AS ENUM ('ACTIVA', 'FINALIZADA', 'REVOCADA');
-- Anexo Delegaciones: la suplencia solo se formaliza por Resolución Rectoral o del ICU
CREATE TYPE tipo_resolucion     AS ENUM ('RECTORAL', 'ICU');
CREATE TYPE tipo_tramite        AS ENUM ('LEGALIZACION', 'DESCUENTO_DIPLOMADO');
-- El orden de declaración define el avance del flujo (se usa en comparaciones >=)
CREATE TYPE estado_tramite      AS ENUM ('INGRESADO', 'EN_REVISION', 'OBSERVADO', 'EN_BANDEJA_DESPACHO',
                                         'FIRMADO_DIGITALMENTE', 'DERIVADO_RECTORADO', 'CONCLUIDO', 'RECHAZADO');
CREATE TYPE destino_tramite     AS ENUM ('NACIONAL', 'EXTERIOR');
CREATE TYPE prioridad_tramite   AS ENUM ('NORMAL', 'ALTA', 'URGENTE');
CREATE TYPE estado_documento    AS ENUM ('PENDIENTE', 'VALIDO', 'OBSERVADO', 'RECHAZADO');
-- RN-01: unidad con la que Caja calcula el valorado (referencia para verificar el importe; el área no cobra)
CREATE TYPE unidad_cobro        AS ENUM ('FOJA', 'MATERIA', 'DOCUMENTO');
-- RN-02: régimen de la carrera según el Avance Académico
CREATE TYPE regimen_carrera     AS ENUM ('SEMESTRAL', 'ANUAL');
-- RN-02 / RN-03: situación del beneficio del 50%
CREATE TYPE estado_financiamiento AS ENUM ('CON_FINANCIAMIENTO', 'SIN_FINANCIAMIENTO', 'PERDIDO_POR_REPROBACION');
CREATE TYPE tipo_observacion    AS ENUM ('FOJA_ILEGIBLE', 'SELLO_FALTANTE', 'FIRMA_FALTANTE', 'VALORADO_FALTANTE',
                                         'VALORADO_INSUFICIENTE',
                                         'FOLIACION_INCORRECTA', 'DOCUMENTO_EXTERNO',
                                         'FUERA_DE_PLAZO', 'OTRO');
CREATE TYPE estado_observacion  AS ENUM ('PENDIENTE', 'SUBSANADA', 'ANULADA');
-- Anexo Delegaciones: el firmante deja constancia de su calidad (titular o interino)
CREATE TYPE calidad_firmante    AS ENUM ('TITULAR', 'INTERINO');
CREATE TYPE tipo_certificado    AS ENUM ('LEGALIZACION', 'CONSTANCIA_DESCUENTO');
CREATE TYPE estado_certificado  AS ENUM ('VIGENTE', 'ANULADO');
CREATE TYPE tipo_alerta         AS ENUM ('PREVENTIVA', 'VENCIDA');
CREATE TYPE tipo_notificacion   AS ENUM ('PENDIENTE_REVISION', 'PENDIENTE_FIRMA', 'PENDIENTE_RECTORADO',
                                         'CAMBIO_ESTADO', 'ALERTA_SLA');

-- =====================================================================
-- CU1 - GESTIÓN DE USUARIOS
-- =====================================================================
-- Anexo Delegaciones: el orden de prelación (antigüedad) define qué Decano suple al Vicerrector
CREATE TABLE facultad (
    id               SERIAL PRIMARY KEY,
    codigo           VARCHAR(15)  NOT NULL UNIQUE,
    nombre           VARCHAR(150) NOT NULL UNIQUE,
    orden_prelacion  INT          NOT NULL UNIQUE CHECK (orden_prelacion > 0),   -- 1 = la más antigua
    anio_creacion    INT          CHECK (anio_creacion BETWEEN 1800 AND 2100)
);

CREATE TABLE carrera (
    id           SERIAL PRIMARY KEY,
    codigo       VARCHAR(15)  NOT NULL UNIQUE,
    nombre       VARCHAR(150) NOT NULL,
    facultad_id  INT          NOT NULL REFERENCES facultad(id)
);

CREATE TABLE usuario (
    id             SERIAL PRIMARY KEY,
    nombres        VARCHAR(100) NOT NULL,
    apellidos      VARCHAR(100) NOT NULL,
    ci             VARCHAR(20)  NOT NULL UNIQUE,
    registro       VARCHAR(20)  UNIQUE,             -- registro universitario (estudiantes)
    email          VARCHAR(150) NOT NULL UNIQUE,
    telefono       VARCHAR(30),
    direccion      VARCHAR(200),                    -- formulario de descuento, Sección I
    password       VARCHAR(255) NOT NULL,          -- texto plano (sistema de práctica)
    rol            rol_usuario  NOT NULL,
    carrera_id     INT REFERENCES carrera(id),      -- para Decano / Vicedecano / Director: identifica su facultad
    cargo          cargo_institucional,             -- NULL = sin cargo (estudiantes, docentes de base)
    activo         BOOLEAN      NOT NULL DEFAULT TRUE,
    CHECK (rol <> 'ESTUDIANTE' OR cargo IS NULL),
    CHECK ((cargo IS NOT DISTINCT FROM 'VICERRECTOR') = (rol = 'VICERRECTOR')),
    CHECK (rol <> 'SUPLENTE' OR cargo IS NOT DISTINCT FROM 'DECANO'),   -- Art. 46: solo un Decano puede suplir al Vicerrector/a
    CHECK (cargo NOT IN ('DECANO', 'VICEDECANO', 'DIRECTOR_CARRERA') OR carrera_id IS NOT NULL)
);

-- =====================================================================
-- CU2 - DELEGACIÓN DE AUTORIDAD (los indicadores se calculan con consultas)
-- El Vicerrector no elige a su suplente: lo designa el Rectorado (Resolución Rectoral)
-- o el ICU, y la registra el ADMINISTRADOR del sistema.
-- =====================================================================
CREATE TABLE delegacion_autoridad (
    id                SERIAL PRIMARY KEY,
    titular_id        INT               NOT NULL REFERENCES usuario(id),
    suplente_id       INT               NOT NULL REFERENCES usuario(id),
    tipo_resolucion   tipo_resolucion   NOT NULL DEFAULT 'RECTORAL',
    nro_resolucion    VARCHAR(50)       NOT NULL,
    fecha_resolucion  DATE              NOT NULL,
    motivo            motivo_delegacion NOT NULL,
    detalle_motivo    VARCHAR(255),
    -- Art. 46 sucesión en cadena: obligatoria si se salta a Decanos de mayor prelación (impedidos, ausentes o renunciantes)
    justificacion_prelacion VARCHAR(255),
    fecha_inicio      DATE              NOT NULL,
    fecha_fin         DATE              NOT NULL,
    estado            estado_delegacion NOT NULL DEFAULT 'ACTIVA',
    registrado_por    INT REFERENCES usuario(id),
    CHECK (fecha_fin >= fecha_inicio),
    CHECK (fecha_resolucion <= fecha_inicio),
    CHECK (titular_id <> suplente_id),
    CHECK (registrado_por IS DISTINCT FROM titular_id)
);

-- =====================================================================
-- CU3 - TRÁMITES, DOCUMENTOS, REQUISITOS Y EXPEDIENTE
-- =====================================================================
CREATE TABLE unidad_origen (        -- unidades de la UAGRM que emiten, firman o sellan
    id      SERIAL PRIMARY KEY,
    codigo  VARCHAR(30)  NOT NULL UNIQUE,
    nombre  VARCHAR(150) NOT NULL
);

-- RF-04: catálogo oficial de documentos legalizables.
-- RF-07: el arancel es solo referencial para verificar el valorado que trae el interesado (el área no cobra)
CREATE TABLE catalogo_documento (
    id                 SERIAL PRIMARY KEY,
    codigo             VARCHAR(30)   NOT NULL UNIQUE,
    nombre             VARCHAR(200)  NOT NULL,
    unidad_emisora_id  INT           NOT NULL REFERENCES unidad_origen(id),
    unidad_cobro         unidad_cobro  NOT NULL DEFAULT 'DOCUMENTO',
    arancel_referencial  NUMERIC(8,2)  NOT NULL DEFAULT 0 CHECK (arancel_referencial >= 0),
    activo             BOOLEAN       NOT NULL DEFAULT TRUE
);

-- RF-06 / RN-04: cadena de firmas y sellos precedentes que exige cada documento
CREATE TABLE catalogo_firma_precedente (
    id                     SERIAL PRIMARY KEY,
    catalogo_documento_id  INT          NOT NULL REFERENCES catalogo_documento(id) ON DELETE CASCADE,
    orden                  INT          NOT NULL,
    unidad_id              INT          NOT NULL REFERENCES unidad_origen(id),
    cargo_firmante         VARCHAR(150) NOT NULL,
    requiere_sello         BOOLEAN      NOT NULL DEFAULT TRUE,
    UNIQUE (catalogo_documento_id, orden)
);

CREATE SEQUENCE IF NOT EXISTS seq_tramite;

CREATE TABLE tramite (
    id                   SERIAL PRIMARY KEY,
    codigo               VARCHAR(25)       UNIQUE,                 -- LEG-2026-000001 / DES-2026-000002 (trigger)
    tipo                 tipo_tramite      NOT NULL,
    solicitante_id       INT               NOT NULL REFERENCES usuario(id),
    carrera_id           INT               NOT NULL REFERENCES carrera(id),
    destino              destino_tramite   NOT NULL DEFAULT 'NACIONAL',
    pais_destino         VARCHAR(80),                              -- RF-03: obligatorio si es EXTERIOR
    institucion_destino  VARCHAR(200),
    finalidad            VARCHAR(255),
    prioridad            prioridad_tramite NOT NULL DEFAULT 'NORMAL',
    estado               estado_tramite    NOT NULL DEFAULT 'INGRESADO',
    total_fojas          INT               NOT NULL DEFAULT 0,
    registrado_por       INT REFERENCES usuario(id),
    fecha_ingreso        TIMESTAMP         NOT NULL DEFAULT LOCALTIMESTAMP,
    fecha_conclusion     TIMESTAMP,
    CHECK (destino = 'NACIONAL' OR pais_destino IS NOT NULL),
    CHECK (tipo = 'LEGALIZACION' OR destino = 'NACIONAL')
);

CREATE TABLE tramite_documento (
    id                     SERIAL PRIMARY KEY,
    tramite_id             INT              NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    catalogo_documento_id  INT              NOT NULL REFERENCES catalogo_documento(id),
    cantidad_paginas       INT              NOT NULL CHECK (cantidad_paginas > 0),
    emitido_por_uagrm      BOOLEAN          NOT NULL DEFAULT TRUE,
    firmas_verificadas     BOOLEAN          NOT NULL DEFAULT FALSE,          -- RN-04
    cantidad_materias      INT              CHECK (cantidad_materias > 0),   -- documentos con arancel por MATERIA
    -- RF-07 / RN-01: Recepción registra el valorado de caja que adjunta cada documento (no es un cobro)
    nro_valorado           VARCHAR(40)      UNIQUE,
    importe_valorado       NUMERIC(10,2)    CHECK (importe_valorado > 0),
    arancel_esperado       NUMERIC(10,2)    NOT NULL DEFAULT 0,              -- trigger: arancel referencial × unidad
    archivo_ruta           VARCHAR(255),
    folio_inicio           INT,
    folio_fin              INT,
    estado                 estado_documento NOT NULL DEFAULT 'PENDIENTE',
    CHECK ((nro_valorado IS NULL) = (importe_valorado IS NULL))
);

-- RF-02 / RN-02 / RN-03: datos propios de la solicitud de descuento del 50% (formulario Secciones II y III)
CREATE TABLE solicitud_descuento (
    tramite_id               INT                   PRIMARY KEY REFERENCES tramite(id) ON DELETE CASCADE,
    unidad_postgrado         VARCHAR(150)          NOT NULL,
    nombre_diplomado         VARCHAR(200)          NOT NULL,
    gestion_diplomado        VARCHAR(10)           NOT NULL,         -- ej. I-2026
    fecha_inicio_diplomado   DATE,
    nro_avance_academico     VARCHAR(20)           NOT NULL,
    regimen                  regimen_carrera       NOT NULL,
    anio_ultima_nota         INT                   NOT NULL CHECK (anio_ultima_nota BETWEEN 1950 AND 2100),
    periodo_ultima_nota      INT                   CHECK (periodo_ultima_nota IN (1, 2)),
    financiamiento           estado_financiamiento,                  -- trigger (RN-02)
    modulo_reprobado         VARCHAR(150),                           -- RN-03
    fecha_perdida            DATE,
    nro_formulario_emitido   VARCHAR(30)           UNIQUE,           -- Sección III: constancia de entrega
    fecha_entrega            DATE,                                   -- Sección III
    CHECK ((regimen = 'SEMESTRAL') = (periodo_ultima_nota IS NOT NULL)),
    CHECK ((nro_formulario_emitido IS NULL) = (fecha_entrega IS NULL))
);

CREATE TABLE requisito (
    id           SERIAL PRIMARY KEY,
    tipo         tipo_tramite NOT NULL,
    codigo       VARCHAR(30)  NOT NULL,
    descripcion  VARCHAR(255) NOT NULL,
    obligatorio  BOOLEAN      NOT NULL DEFAULT TRUE,
    pais_aplica  VARCHAR(80),                  -- RN-05: NULL = aplica a todos los destinos
    UNIQUE (tipo, codigo)
);

CREATE TABLE tramite_requisito (
    tramite_id          INT     NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    requisito_id        INT     NOT NULL REFERENCES requisito(id),
    cumplido            BOOLEAN NOT NULL DEFAULT FALSE,
    verificado_por      INT REFERENCES usuario(id),
    fecha_verificacion  TIMESTAMP,
    PRIMARY KEY (tramite_id, requisito_id)
);

CREATE TABLE expediente (           -- PDF único compilado con foliación continua
    id             SERIAL PRIMARY KEY,
    tramite_id     INT          NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    version        INT          NOT NULL DEFAULT 1,
    ruta_pdf       VARCHAR(255) NOT NULL,
    total_fojas    INT          NOT NULL CHECK (total_fojas > 0),
    hash_sha256    CHAR(64)     NOT NULL,
    compilado_por  INT REFERENCES usuario(id),
    vigente        BOOLEAN      NOT NULL DEFAULT TRUE,
    UNIQUE (tramite_id, version)
);

-- =====================================================================
-- CU4 - ENRUTAR FLUJO, GUÍA PROCESAL, OBSERVACIONES Y BITÁCORA
-- =====================================================================
CREATE TABLE flujo_paso (
    id               SERIAL PRIMARY KEY,
    tipo             tipo_tramite    NOT NULL,
    destino          destino_tramite,                   -- NULL = aplica a ambos destinos
    orden            INT             NOT NULL,
    unidad_id        INT             NOT NULL REFERENCES unidad_origen(id),
    nombre           VARCHAR(150)    NOT NULL,
    descripcion      TEXT,
    firma_requerida  VARCHAR(150),
    sello_requerido  VARCHAR(150),
    estado_asociado  estado_tramite,                    -- estado en el que el paso queda cumplido (NULL = externo)
    UNIQUE (tipo, orden)
);

CREATE TABLE observacion (
    id                    SERIAL PRIMARY KEY,
    tramite_id            INT                NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    tramite_documento_id  INT REFERENCES tramite_documento(id) ON DELETE SET NULL,
    tipo                  tipo_observacion   NOT NULL,
    descripcion           VARCHAR(500)       NOT NULL,
    estado                estado_observacion NOT NULL DEFAULT 'PENDIENTE',
    registrado_por        INT REFERENCES usuario(id),
    fecha_registro        TIMESTAMP          NOT NULL DEFAULT LOCALTIMESTAMP,
    fecha_subsanacion     TIMESTAMP
);

CREATE TABLE bitacora (             -- RF-10 inalterable: triggers + cadena de hash
    id             BIGSERIAL PRIMARY KEY,
    tramite_id     INT,                   -- sin FK a propósito: nunca se pierde un registro
    usuario_id     INT,
    rol            VARCHAR(30),           -- rol del usuario al momento del evento (texto: el historial no depende del ENUM)
    accion         VARCHAR(60) NOT NULL,
    entidad        VARCHAR(60),
    entidad_id     VARCHAR(40),
    detalle        JSONB,
    fecha          TIMESTAMP   NOT NULL DEFAULT LOCALTIMESTAMP,
    hash_anterior  CHAR(64)    NOT NULL,
    hash_actual    CHAR(64)    NOT NULL
);

-- =====================================================================
-- CU5 - DESPACHAR, PREVISUALIZAR LEGAJOS Y FIRMA DIGITAL
-- =====================================================================
CREATE TABLE certificado_digital (  -- certificado de firma de la autoridad / suplente
    id                SERIAL PRIMARY KEY,
    usuario_id        INT          NOT NULL REFERENCES usuario(id),
    numero_serie      VARCHAR(80)  NOT NULL UNIQUE,
    emisor            VARCHAR(150) NOT NULL,
    fecha_emision     DATE         NOT NULL,
    fecha_expiracion  DATE         NOT NULL,
    activo            BOOLEAN      NOT NULL DEFAULT TRUE,
    CHECK (fecha_expiracion > fecha_emision)
);

CREATE TABLE lote_firma (           -- una sola confirmación para uno o varios expedientes
    id              SERIAL PRIMARY KEY,
    firmante_id     INT              NOT NULL REFERENCES usuario(id),
    certificado_id  INT              NOT NULL REFERENCES certificado_digital(id),
    delegacion_id   INT REFERENCES delegacion_autoridad(id),   -- se llena si firma el suplente
    calidad         calidad_firmante NOT NULL DEFAULT 'TITULAR',  -- trigger
    es_remota       BOOLEAN          NOT NULL DEFAULT FALSE,
    ubicacion       VARCHAR(150),
    ip_origen       VARCHAR(45),
    fecha           TIMESTAMP        NOT NULL DEFAULT LOCALTIMESTAMP
);

CREATE TABLE firma_digital (
    id              SERIAL PRIMARY KEY,
    lote_id         INT       NOT NULL REFERENCES lote_firma(id),
    tramite_id      INT       NOT NULL REFERENCES tramite(id),
    expediente_id   INT       REFERENCES expediente(id),
    hash_documento  CHAR(64)  NOT NULL,
    firma_valor     TEXT      NOT NULL,            -- firma PAdES / PKCS#7 en Base64
    fecha_firma     TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP,
    valida          BOOLEAN   NOT NULL DEFAULT TRUE,
    UNIQUE (lote_id, tramite_id)
);

-- =====================================================================
-- CU6 - CERTIFICADO OFICIAL CON QR Y PORTAL DE SEGUIMIENTO
-- =====================================================================
CREATE SEQUENCE IF NOT EXISTS seq_certificado;

CREATE TABLE certificado_oficial (
    id                   SERIAL PRIMARY KEY,
    tramite_id           INT                NOT NULL REFERENCES tramite(id),
    firma_id             INT                REFERENCES firma_digital(id),
    numero               VARCHAR(30)        UNIQUE,                     -- trigger
    tipo                 tipo_certificado   NOT NULL,                   -- trigger (según el trámite)
    ruta_pdfa            VARCHAR(255)       NOT NULL,
    hash_sha256          CHAR(64)           NOT NULL,
    codigo_verificacion  UUID               NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    leyenda_firma        TEXT,                                          -- trigger: calidad del firmante
    qr_contenido         TEXT               NOT NULL,                   -- la aplicación (mailto al sistema); el trigger lo valida
    fecha_emision        TIMESTAMP          NOT NULL DEFAULT LOCALTIMESTAMP,
    estado               estado_certificado NOT NULL DEFAULT 'VIGENTE',
    fecha_anulacion      TIMESTAMP,                                     -- trigger
    motivo_anulacion     VARCHAR(300),
    anulado_por          INT                REFERENCES usuario(id),     -- trigger
    CHECK ((estado = 'ANULADO') = (fecha_anulacion IS NOT NULL)),
    CHECK (estado = 'VIGENTE' OR motivo_anulacion IS NOT NULL)
);

-- =====================================================================
-- CU7 - MONITOREO, TIEMPOS DE ATENCIÓN, ALERTAS SLA Y NOTIFICACIONES
-- =====================================================================
CREATE TABLE sla_config (
    id                 SERIAL PRIMARY KEY,
    tipo               tipo_tramite UNIQUE,              -- NULL = configuración general
    plazo_horas        NUMERIC(6,2) NOT NULL DEFAULT 72,
    porcentaje_alerta  NUMERIC(5,2) NOT NULL DEFAULT 75 CHECK (porcentaje_alerta BETWEEN 1 AND 99)
);

CREATE TABLE tramite_tiempo_fase (  -- permanencia en cada estado (también sirve de historial)
    id              SERIAL PRIMARY KEY,
    tramite_id      INT            NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    estado          estado_tramite NOT NULL,
    fecha_inicio    TIMESTAMP      NOT NULL,
    fecha_fin       TIMESTAMP,
    horas_habiles   NUMERIC(8,2),
    responsable_id  INT REFERENCES usuario(id)
);

CREATE TABLE alerta_sla (
    id                   SERIAL PRIMARY KEY,
    tramite_id           INT          NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    tipo                 tipo_alerta  NOT NULL,
    horas_transcurridas  NUMERIC(8,2) NOT NULL,
    plazo_horas          NUMERIC(6,2) NOT NULL,
    fecha                TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    atendida             BOOLEAN      NOT NULL DEFAULT FALSE,
    UNIQUE (tramite_id, tipo)
);

-- RF-19: bandeja de notificaciones de cada usuario
CREATE TABLE notificacion (
    id          SERIAL PRIMARY KEY,
    usuario_id  INT               NOT NULL REFERENCES usuario(id),
    tramite_id  INT               REFERENCES tramite(id) ON DELETE CASCADE,
    tipo        tipo_notificacion NOT NULL,
    mensaje     VARCHAR(300)      NOT NULL,
    fecha       TIMESTAMP         NOT NULL DEFAULT LOCALTIMESTAMP,
    leida       BOOLEAN           NOT NULL DEFAULT FALSE,
    enviada     BOOLEAN           NOT NULL DEFAULT FALSE   -- ya se envió por correo al usuario
);

-- ---------------------------------------------------------------------
-- ÍNDICES
-- ---------------------------------------------------------------------
CREATE INDEX ix_tramite_estado      ON tramite(estado);
CREATE INDEX ix_tramite_solicitante ON tramite(solicitante_id);
CREATE INDEX ix_tramite_carrera     ON tramite(carrera_id);
CREATE INDEX ix_tdoc_tramite        ON tramite_documento(tramite_id);
CREATE INDEX ix_obs_tramite         ON observacion(tramite_id, estado);
CREATE INDEX ix_bitacora_tramite    ON bitacora(tramite_id);
CREATE INDEX ix_fase_tramite        ON tramite_tiempo_fase(tramite_id);
CREATE INDEX ix_firma_tramite       ON firma_digital(tramite_id);
CREATE INDEX ix_deleg_vigencia      ON delegacion_autoridad(estado, fecha_inicio, fecha_fin);
CREATE INDEX ix_notif_usuario       ON notificacion(usuario_id, leida);
CREATE INDEX ix_notif_por_enviar    ON notificacion(usuario_id) WHERE NOT enviada;

-- =====================================================================
-- FUNCIONES AUXILIARES DE LOS ENUM
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_estado_nombre(p estado_tramite) RETURNS VARCHAR LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p
        WHEN 'INGRESADO'            THEN 'Ingresado'
        WHEN 'EN_REVISION'          THEN 'En Revisión de Requisitos'
        WHEN 'OBSERVADO'            THEN 'Observado'
        WHEN 'EN_BANDEJA_DESPACHO'  THEN 'En Bandeja de Despacho'
        WHEN 'FIRMADO_DIGITALMENTE' THEN 'Firmado Digitalmente'
        WHEN 'DERIVADO_RECTORADO'   THEN 'Derivado a Rectorado'
        WHEN 'CONCLUIDO'            THEN 'Concluido / Listo para Entrega'
        WHEN 'RECHAZADO'            THEN 'Rechazado' END;
$$;

CREATE OR REPLACE FUNCTION fn_estado_es_final(p estado_tramite) RETURNS BOOLEAN LANGUAGE sql IMMUTABLE AS $$
    SELECT p IN ('CONCLUIDO', 'RECHAZADO');
$$;

-- El reloj SLA se detiene en OBSERVADO (el trámite está en manos del estudiante) y en estados finales
CREATE OR REPLACE FUNCTION fn_estado_cuenta_sla(p estado_tramite) RETURNS BOOLEAN LANGUAGE sql IMMUTABLE AS $$
    SELECT p NOT IN ('OBSERVADO', 'CONCLUIDO', 'RECHAZADO');
$$;

CREATE OR REPLACE FUNCTION fn_usuario_actual() RETURNS INT LANGUAGE sql STABLE AS $$
    SELECT NULLIF(current_setting('app.usuario_id', true), '')::INT;
$$;

CREATE OR REPLACE FUNCTION fn_rol_usuario(p_id INT) RETURNS rol_usuario LANGUAGE sql STABLE AS $$
    SELECT rol FROM usuario WHERE id = p_id;
$$;

-- Periodo académico (1 = primer semestre, 2 = segundo semestre) de una fecha
CREATE OR REPLACE FUNCTION fn_periodo_academico(p TIMESTAMP) RETURNS INT LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN EXTRACT(MONTH FROM p) <= 6 THEN 1 ELSE 2 END;
$$;

-- =====================================================================
-- CU4: BITÁCORA INALTERABLE (RF-10: usuario, rol, acción, fecha y hora)
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_registrar_bitacora(p_tramite INT, p_accion TEXT, p_entidad TEXT,
                                                 p_entidad_id TEXT, p_detalle JSONB)
RETURNS VOID LANGUAGE sql AS $$
    INSERT INTO bitacora(tramite_id, usuario_id, rol, accion, entidad, entidad_id, detalle, hash_anterior, hash_actual)
    VALUES (p_tramite, fn_usuario_actual(), fn_rol_usuario(fn_usuario_actual())::TEXT, p_accion, p_entidad, p_entidad_id,
            p_detalle, '', '');
$$;

CREATE OR REPLACE FUNCTION fn_bitacora_calcular_hash(p_prev TEXT, b bitacora) RETURNS CHAR(64)
LANGUAGE sql STABLE AS $$
    SELECT encode(sha256(convert_to(p_prev || '|' || b.id || '|' ||
           COALESCE(b.tramite_id::TEXT, '') || '|' || COALESCE(b.usuario_id::TEXT, '') || '|' ||
           COALESCE(b.rol::TEXT, '') || '|' || b.accion || '|' || COALESCE(b.detalle::TEXT, '') || '|' ||
           b.fecha::TEXT, 'UTF8')), 'hex');
$$;

CREATE OR REPLACE FUNCTION fn_bitacora_hash() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_prev CHAR(64);
BEGIN
    PERFORM pg_advisory_xact_lock(20260001);
    SELECT hash_actual INTO v_prev FROM bitacora ORDER BY id DESC LIMIT 1;
    NEW.fecha         := clock_timestamp()::TIMESTAMP;
    NEW.rol           := COALESCE(NEW.rol, fn_rol_usuario(NEW.usuario_id)::TEXT);
    NEW.hash_anterior := COALESCE(v_prev, repeat('0', 64));
    NEW.hash_actual   := fn_bitacora_calcular_hash(NEW.hash_anterior, NEW);
    RETURN NEW;
END $$;

CREATE TRIGGER trg_bitacora_hash BEFORE INSERT ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_hash();

CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'La bitácora es inalterable: no se permite %', TG_OP;
END $$;

CREATE TRIGGER trg_bitacora_no_modificar BEFORE UPDATE OR DELETE ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
CREATE TRIGGER trg_bitacora_no_truncar   BEFORE TRUNCATE ON bitacora FOR EACH STATEMENT EXECUTE FUNCTION fn_bitacora_inmutable();

-- Devuelve el id del primer registro alterado, o NULL si la cadena está íntegra
CREATE OR REPLACE FUNCTION fn_verificar_bitacora() RETURNS BIGINT LANGUAGE plpgsql STABLE AS $$
DECLARE r bitacora; v_prev CHAR(64) := repeat('0', 64);
BEGIN
    FOR r IN SELECT * FROM bitacora ORDER BY id LOOP
        IF r.hash_anterior <> v_prev OR r.hash_actual <> fn_bitacora_calcular_hash(v_prev, r) THEN
            RETURN r.id;
        END IF;
        v_prev := r.hash_actual;
    END LOOP;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION fn_auditar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_row JSONB;
BEGIN
    v_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END - 'password';
    PERFORM fn_registrar_bitacora((v_row->>'tramite_id')::INT, TG_OP || '_' || upper(TG_TABLE_NAME::TEXT),
                                  TG_TABLE_NAME::TEXT, COALESCE(v_row->>'id', v_row->>'tramite_id'), v_row);
    RETURN NULL;
END $$;

CREATE TRIGGER trg_aud_usuario     AFTER INSERT OR UPDATE OR DELETE ON usuario              FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_delegacion  AFTER INSERT OR UPDATE OR DELETE ON delegacion_autoridad FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_documento   AFTER INSERT OR UPDATE OR DELETE ON tramite_documento    FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_descuento   AFTER INSERT OR UPDATE           ON solicitud_descuento  FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_requisito   AFTER UPDATE                     ON tramite_requisito    FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_expediente  AFTER INSERT                     ON expediente           FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_observacion AFTER INSERT OR UPDATE           ON observacion          FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_firma       AFTER INSERT OR UPDATE           ON firma_digital        FOR EACH ROW EXECUTE FUNCTION fn_auditar();
CREATE TRIGGER trg_aud_certificado AFTER INSERT OR UPDATE           ON certificado_oficial  FOR EACH ROW EXECUTE FUNCTION fn_auditar();

-- =====================================================================
-- CU7: HORAS HÁBILES
-- 72 horas hábiles = 3 días hábiles completos (excluye sábados y domingos)
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_horas_habiles(p_ini TIMESTAMP, p_fin TIMESTAMP)
RETURNS NUMERIC LANGUAGE sql STABLE AS $$
    SELECT COALESCE(ROUND(SUM(EXTRACT(EPOCH FROM (LEAST(p_fin, d.dia + INTERVAL '1 day')
                                                  - GREATEST(p_ini, d.dia)))::NUMERIC) / 3600.0, 2), 0)
      FROM generate_series(date_trunc('day', p_ini), date_trunc('day', p_fin), INTERVAL '1 day') AS d(dia)
     WHERE p_fin > p_ini
       AND EXTRACT(ISODOW FROM d.dia) < 6;
$$;

-- =====================================================================
-- CU7 / RF-19: NOTIFICACIONES
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_notificar_rol(p_rol rol_usuario, p_tramite INT, p_tipo tipo_notificacion, p_mensaje TEXT)
RETURNS VOID LANGUAGE sql AS $$
    INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
    SELECT id, p_tramite, p_tipo, p_mensaje FROM usuario WHERE rol = p_rol AND activo;
$$;

-- =====================================================================
-- CU2: AUTORIDAD VIGENTE Y DELEGACIONES
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_autoridad_vigente(p_fecha DATE DEFAULT CURRENT_DATE)
RETURNS INT LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT suplente_id FROM delegacion_autoridad
          WHERE estado = 'ACTIVA' AND p_fecha BETWEEN fecha_inicio AND fecha_fin
          ORDER BY id DESC LIMIT 1),
        (SELECT id FROM usuario WHERE rol = 'VICERRECTOR' AND activo ORDER BY id LIMIT 1));
$$;

-- Art. 46 Estatuto Orgánico: Decano activo de la facultad con mejor orden de prelación (primer reemplazo)
CREATE OR REPLACE FUNCTION fn_suplente_estatutario() RETURNS INT LANGUAGE sql STABLE AS $$
    SELECT u.id FROM usuario u
      JOIN carrera c  ON c.id = u.carrera_id
      JOIN facultad f ON f.id = c.facultad_id
     WHERE u.cargo = 'DECANO' AND u.activo
     ORDER BY f.orden_prelacion, u.id LIMIT 1;
$$;

CREATE OR REPLACE FUNCTION fn_finalizar_delegaciones_vencidas() RETURNS INT LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    UPDATE delegacion_autoridad SET estado = 'FINALIZADA' WHERE estado = 'ACTIVA' AND fecha_fin < CURRENT_DATE;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END $$;

-- Anexo Delegaciones: el Vicerrector no se auto-delega; la suplencia la registra el ADMINISTRADOR
-- y por Estatuto Orgánico recae en un Decano (orden de prelación de facultades)
CREATE OR REPLACE FUNCTION fn_trg_delegacion_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_esperado INT;
BEGIN
    NEW.justificacion_prelacion := NULLIF(trim(NEW.justificacion_prelacion), '');
    IF TG_OP = 'INSERT' THEN
        NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    END IF;
    IF fn_rol_usuario(NEW.titular_id) IS DISTINCT FROM 'VICERRECTOR' THEN
        RAISE EXCEPTION 'El titular de la delegación debe tener el rol VICERRECTOR';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuario WHERE id = NEW.suplente_id AND cargo = 'DECANO' AND activo) THEN
        RAISE EXCEPTION 'El suplente debe ser un usuario activo con cargo DECANO (Estatuto Orgánico)';
    END IF;
    -- Art. 46: asume el Decano de la facultad más antigua; si está impedido, el siguiente en la cadena,
    -- dejando constancia de por qué se saltan los de mayor prelación
    v_esperado := fn_suplente_estatutario();
    IF NEW.suplente_id IS DISTINCT FROM v_esperado AND NEW.justificacion_prelacion IS NULL THEN
        RAISE EXCEPTION 'Por orden de prelación corresponde el usuario % (Decano de %). Si está impedido, '
                        'registre la justificación de prelación',
            v_esperado, (SELECT f.nombre FROM usuario u JOIN carrera c ON c.id = u.carrera_id
                          JOIN facultad f ON f.id = c.facultad_id WHERE u.id = v_esperado);
    END IF;
    IF NEW.registrado_por IS NOT NULL AND fn_rol_usuario(NEW.registrado_por) IS DISTINCT FROM 'ADMINISTRADOR' THEN
        RAISE EXCEPTION 'Solo el ADMINISTRADOR registra delegaciones (Resolución Rectoral o del ICU)';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_delegacion_validar BEFORE INSERT OR UPDATE OF titular_id, suplente_id, registrado_por,
                                                       justificacion_prelacion
    ON delegacion_autoridad FOR EACH ROW EXECUTE FUNCTION fn_trg_delegacion_validar();

-- =====================================================================
-- CU3: FOLIACIÓN
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_asignar_folios(p_tramite INT) RETURNS INT LANGUAGE plpgsql AS $$
DECLARE r RECORD; v_folio INT := 1;
BEGIN
    FOR r IN SELECT id, cantidad_paginas FROM tramite_documento
              WHERE tramite_id = p_tramite AND estado <> 'RECHAZADO' ORDER BY id LOOP
        UPDATE tramite_documento SET folio_inicio = v_folio, folio_fin = v_folio + r.cantidad_paginas - 1 WHERE id = r.id;
        v_folio := v_folio + r.cantidad_paginas;
    END LOOP;
    RETURN v_folio - 1;
END $$;

-- =====================================================================
-- CU3: ALTA DEL TRÁMITE
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_trg_tramite_antes_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.codigo IS NULL THEN
        NEW.codigo := CASE NEW.tipo WHEN 'LEGALIZACION' THEN 'LEG-' ELSE 'DES-' END ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_tramite')::TEXT, 6, '0');
    END IF;
    NEW.estado         := 'INGRESADO';
    NEW.pais_destino   := NULLIF(upper(trim(NEW.pais_destino)), '');
    NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    RETURN NEW;
END $$;
CREATE TRIGGER trg_tramite_antes_insert BEFORE INSERT ON tramite FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_antes_insert();

-- RN-05: si cambia el país de destino se quitan los requisitos de país que ya no aplican (y no se cumplieron)
CREATE OR REPLACE FUNCTION fn_trg_tramite_pais() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        NEW.pais_destino := NULLIF(upper(trim(NEW.pais_destino)), '');
        DELETE FROM tramite_requisito tr USING requisito r
         WHERE tr.requisito_id = r.id AND tr.tramite_id = NEW.id AND NOT tr.cumplido
           AND r.pais_aplica IS NOT NULL AND r.pais_aplica IS DISTINCT FROM NEW.pais_destino;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_tramite_pais BEFORE UPDATE OF pais_destino ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_pais();

-- Asigna los requisitos del tipo de trámite; RN-05: los que dependen del país solo si coincide el destino
CREATE OR REPLACE FUNCTION fn_trg_tramite_despues_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO tramite_requisito(tramite_id, requisito_id)
    SELECT NEW.id, id FROM requisito
     WHERE tipo = NEW.tipo AND (pais_aplica IS NULL OR pais_aplica = NEW.pais_destino)
    ON CONFLICT DO NOTHING;
    IF TG_OP = 'INSERT' THEN
        PERFORM fn_notificar_rol('RECEPCION', NEW.id, 'PENDIENTE_REVISION',
                                 'Nuevo trámite ' || NEW.codigo || ' pendiente de revisión de requisitos');
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_tramite_despues_insert AFTER INSERT OR UPDATE OF pais_destino ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_despues_insert();

-- =====================================================================
-- CU4: ENRUTAMIENTO DEL FLUJO (máquina de estados)
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_trg_tramite_validar_estado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_n INT; v_fin estado_financiamiento;
BEGIN
    IF NEW.estado = OLD.estado THEN RETURN NEW; END IF;

    IF NOT (OLD.estado::TEXT || '>' || NEW.estado::TEXT = ANY (ARRAY[
        'INGRESADO>EN_REVISION', 'INGRESADO>RECHAZADO',
        'EN_REVISION>OBSERVADO', 'EN_REVISION>EN_BANDEJA_DESPACHO', 'EN_REVISION>RECHAZADO',
        'OBSERVADO>EN_REVISION', 'OBSERVADO>RECHAZADO',
        'EN_BANDEJA_DESPACHO>FIRMADO_DIGITALMENTE', 'EN_BANDEJA_DESPACHO>OBSERVADO',
        'FIRMADO_DIGITALMENTE>DERIVADO_RECTORADO', 'FIRMADO_DIGITALMENTE>CONCLUIDO',
        'DERIVADO_RECTORADO>CONCLUIDO'])) THEN
        RAISE EXCEPTION 'Transición no permitida: % -> %', OLD.estado, NEW.estado;
    END IF;

    IF NEW.estado = 'OBSERVADO' AND NOT EXISTS
       (SELECT 1 FROM observacion WHERE tramite_id = NEW.id AND estado = 'PENDIENTE') THEN
        RAISE EXCEPTION 'Registre al menos una observación pendiente';
    END IF;

    IF NEW.estado = 'EN_BANDEJA_DESPACHO' THEN
        IF EXISTS (SELECT 1 FROM observacion WHERE tramite_id = NEW.id AND estado = 'PENDIENTE') THEN
            RAISE EXCEPTION 'El trámite % tiene observaciones pendientes', NEW.codigo;
        END IF;
        SELECT COUNT(*) INTO v_n FROM tramite_requisito tr JOIN requisito r ON r.id = tr.requisito_id
         WHERE tr.tramite_id = NEW.id AND r.obligatorio AND NOT tr.cumplido;
        IF v_n > 0 THEN
            RAISE EXCEPTION 'El trámite % tiene % requisito(s) sin validar', NEW.codigo, v_n;
        END IF;

        IF NEW.tipo = 'LEGALIZACION' THEN
            IF NEW.total_fojas = 0 THEN RAISE EXCEPTION 'No se seleccionaron documentos'; END IF;
            -- RN-04: cadena de firmas precedentes completa en cada documento
            SELECT COUNT(*) INTO v_n FROM tramite_documento
             WHERE tramite_id = NEW.id AND estado <> 'RECHAZADO' AND NOT firmas_verificadas;
            IF v_n > 0 THEN
                RAISE EXCEPTION 'El trámite % tiene % documento(s) sin firmas precedentes verificadas', NEW.codigo, v_n;
            END IF;
            -- RF-07 / RN-01: cada documento con su valorado, y el importe acorde a fojas o materias
            SELECT COUNT(*) INTO v_n FROM tramite_documento
             WHERE tramite_id = NEW.id AND estado <> 'RECHAZADO' AND nro_valorado IS NULL;
            IF v_n > 0 THEN
                RAISE EXCEPTION 'El trámite % tiene % documento(s) sin valorado de caja registrado', NEW.codigo, v_n;
            END IF;
            SELECT COUNT(*) INTO v_n FROM tramite_documento
             WHERE tramite_id = NEW.id AND estado <> 'RECHAZADO' AND importe_valorado < arancel_esperado;
            IF v_n > 0 THEN
                RAISE EXCEPTION 'El trámite % tiene % documento(s) cuyo valorado no cubre las fojas o materias',
                                NEW.codigo, v_n;
            END IF;
        ELSE
            -- RN-02: solo pasa a despacho si está dentro del plazo del beneficio
            SELECT financiamiento INTO v_fin FROM solicitud_descuento WHERE tramite_id = NEW.id;
            IF v_fin IS NULL THEN
                RAISE EXCEPTION 'Registre los datos del diplomado y del Avance Académico del trámite %', NEW.codigo;
            ELSIF v_fin <> 'CON_FINANCIAMIENTO' THEN
                RAISE EXCEPTION 'El trámite % está catalogado como % y debe rechazarse', NEW.codigo, v_fin;
            END IF;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM expediente WHERE tramite_id = NEW.id AND vigente) THEN
            RAISE EXCEPTION 'El expediente no ha sido compilado';
        END IF;
    END IF;

    IF NEW.estado = 'FIRMADO_DIGITALMENTE' AND NOT EXISTS
       (SELECT 1 FROM firma_digital WHERE tramite_id = NEW.id AND valida) THEN
        RAISE EXCEPTION 'El trámite % no tiene firma digital válida', NEW.codigo;
    END IF;
    IF NEW.estado = 'DERIVADO_RECTORADO' AND NEW.destino IS DISTINCT FROM 'EXTERIOR' THEN
        RAISE EXCEPTION 'Solo los trámites con destino EXTERIOR se derivan a Rectorado';
    END IF;
    IF NEW.estado = 'CONCLUIDO' THEN
        IF OLD.estado = 'FIRMADO_DIGITALMENTE' AND NEW.destino = 'EXTERIOR' THEN
            RAISE EXCEPTION 'El trámite con destino EXTERIOR debe derivarse antes a Rectorado';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM certificado_oficial WHERE tramite_id = NEW.id AND estado = 'VIGENTE') THEN
            RAISE EXCEPTION 'No se ha emitido el certificado oficial / constancia con QR';
        END IF;
    END IF;

    IF fn_estado_es_final(NEW.estado) THEN NEW.fecha_conclusion := LOCALTIMESTAMP; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_tramite_validar_estado BEFORE UPDATE OF estado ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_validar_estado();

-- Registro de tiempos por fase (CU7), bitácora (CU4) y notificaciones (RF-19)
CREATE OR REPLACE FUNCTION fn_trg_tramite_post_estado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_final BOOLEAN := fn_estado_es_final(NEW.estado); v_autoridad INT;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.estado = OLD.estado THEN RETURN NULL; END IF;

    UPDATE tramite_tiempo_fase
       SET fecha_fin = LOCALTIMESTAMP, horas_habiles = fn_horas_habiles(fecha_inicio, LOCALTIMESTAMP)
     WHERE tramite_id = NEW.id AND fecha_fin IS NULL;

    INSERT INTO tramite_tiempo_fase(tramite_id, estado, fecha_inicio, fecha_fin, horas_habiles, responsable_id)
    VALUES (NEW.id, NEW.estado, LOCALTIMESTAMP, CASE WHEN v_final THEN LOCALTIMESTAMP END,
            CASE WHEN v_final THEN 0 END, fn_usuario_actual());

    PERFORM fn_registrar_bitacora(NEW.id, 'CAMBIO_ESTADO', 'tramite', NEW.id::TEXT,
                                  jsonb_build_object('codigo', NEW.codigo, 'estado', NEW.estado));

    IF TG_OP = 'UPDATE' THEN
        IF NEW.estado = 'EN_BANDEJA_DESPACHO' THEN
            v_autoridad := fn_autoridad_vigente();
            IF v_autoridad IS NOT NULL THEN
                INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
                VALUES (v_autoridad, NEW.id, 'PENDIENTE_FIRMA', 'Trámite ' || NEW.codigo || ' pendiente de firma digital');
            END IF;
        ELSIF NEW.estado = 'DERIVADO_RECTORADO' THEN
            PERFORM fn_notificar_rol('RECTORADO', NEW.id, 'PENDIENTE_RECTORADO',
                                     'Trámite ' || NEW.codigo || ' pendiente de sello institucional final');
        ELSIF NEW.estado = 'EN_REVISION' AND OLD.estado = 'OBSERVADO' THEN
            PERFORM fn_notificar_rol('RECEPCION', NEW.id, 'PENDIENTE_REVISION',
                                     'Trámite ' || NEW.codigo || ' subsanado: pendiente de nueva revisión');
        END IF;
        INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
        VALUES (NEW.solicitante_id, NEW.id, 'CAMBIO_ESTADO',
                'Su trámite ' || NEW.codigo || ' cambió a: ' || fn_estado_nombre(NEW.estado));
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_tramite_post_estado AFTER INSERT OR UPDATE OF estado ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_post_estado();

-- =====================================================================
-- CU3: DOCUMENTOS Y EXPEDIENTE
-- =====================================================================
-- Regla de competencia: solo documentos propios de la UAGRM.
-- RF-07: arancel esperado del valorado según la unidad del catálogo (foja, materia o documento)
CREATE OR REPLACE FUNCTION fn_trg_documento_antes() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE c catalogo_documento;
BEGIN
    IF (SELECT tipo FROM tramite WHERE id = NEW.tramite_id) <> 'LEGALIZACION' THEN
        RAISE EXCEPTION 'Solo los trámites de LEGALIZACION seleccionan documentos del catálogo';
    END IF;
    SELECT * INTO c FROM catalogo_documento WHERE id = NEW.catalogo_documento_id;
    IF c.unidad_cobro = 'MATERIA' AND NEW.cantidad_materias IS NULL THEN
        RAISE EXCEPTION 'El valorado de % se calcula por materia: indique la cantidad de materias', c.nombre;
    END IF;
    NEW.arancel_esperado := c.arancel_referencial * CASE c.unidad_cobro WHEN 'FOJA'    THEN NEW.cantidad_paginas
                                                                        WHEN 'MATERIA' THEN NEW.cantidad_materias
                                                                        ELSE 1 END;
    IF NOT NEW.emitido_por_uagrm THEN NEW.estado := 'RECHAZADO'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_documento_antes BEFORE INSERT OR UPDATE OF catalogo_documento_id, cantidad_paginas,
    cantidad_materias, emitido_por_uagrm ON tramite_documento FOR EACH ROW EXECUTE FUNCTION fn_trg_documento_antes();

CREATE OR REPLACE FUNCTION fn_trg_documento_totales() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_t INT := COALESCE(NEW.tramite_id, OLD.tramite_id);
BEGIN
    UPDATE tramite SET
        total_fojas = (SELECT COALESCE(SUM(cantidad_paginas), 0) FROM tramite_documento
                        WHERE tramite_id = v_t AND estado <> 'RECHAZADO')
     WHERE id = v_t;
    IF TG_OP = 'INSERT' AND NOT NEW.emitido_por_uagrm THEN
        INSERT INTO observacion(tramite_id, tramite_documento_id, tipo, descripcion)
        VALUES (NEW.tramite_id, NEW.id, 'DOCUMENTO_EXTERNO', 'Documento no emitido por la UAGRM: se remite a su entidad de origen.');
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_documento_totales AFTER INSERT OR UPDATE OR DELETE ON tramite_documento
    FOR EACH ROW EXECUTE FUNCTION fn_trg_documento_totales();

-- RF-07 / RF-09: observaciones automáticas del valorado de cada documento.
-- Crea VALORADO_FALTANTE o VALORADO_INSUFICIENTE (sin duplicar las pendientes) y las marca SUBSANADA
-- cuando se registra un valorado que cubre el arancel; si el documento se rechaza, quedan ANULADA.
CREATE OR REPLACE FUNCTION fn_trg_documento_valorado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    v_falta BOOLEAN := NEW.nro_valorado IS NULL;
    v_insuf BOOLEAN := NEW.nro_valorado IS NOT NULL AND NEW.importe_valorado < NEW.arancel_esperado;
    v_doc   VARCHAR := (SELECT nombre FROM catalogo_documento WHERE id = NEW.catalogo_documento_id);
BEGIN
    IF NEW.estado = 'RECHAZADO' THEN
        UPDATE observacion SET estado = 'ANULADA'
         WHERE tramite_documento_id = NEW.id AND estado = 'PENDIENTE'
           AND tipo IN ('VALORADO_FALTANTE', 'VALORADO_INSUFICIENTE');
        RETURN NULL;
    END IF;

    UPDATE observacion SET estado = 'SUBSANADA'
     WHERE tramite_documento_id = NEW.id AND estado = 'PENDIENTE'
       AND ((tipo = 'VALORADO_FALTANTE' AND NOT v_falta) OR (tipo = 'VALORADO_INSUFICIENTE' AND NOT v_insuf));

    IF v_falta AND NOT EXISTS (SELECT 1 FROM observacion WHERE tramite_documento_id = NEW.id
                                  AND tipo = 'VALORADO_FALTANTE' AND estado = 'PENDIENTE') THEN
        INSERT INTO observacion(tramite_id, tramite_documento_id, tipo, descripcion)
        VALUES (NEW.tramite_id, NEW.id, 'VALORADO_FALTANTE',
                v_doc || ': no adjunta su valorado de caja (arancel esperado Bs ' || NEW.arancel_esperado || ').');
    ELSIF v_insuf AND NOT EXISTS (SELECT 1 FROM observacion WHERE tramite_documento_id = NEW.id
                                     AND tipo = 'VALORADO_INSUFICIENTE' AND estado = 'PENDIENTE') THEN
        INSERT INTO observacion(tramite_id, tramite_documento_id, tipo, descripcion)
        VALUES (NEW.tramite_id, NEW.id, 'VALORADO_INSUFICIENTE',
                v_doc || ': el valorado ' || NEW.nro_valorado || ' por Bs ' || NEW.importe_valorado
                || ' no cubre el arancel esperado de Bs ' || NEW.arancel_esperado || '.');
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_documento_valorado AFTER INSERT OR UPDATE OF catalogo_documento_id, cantidad_paginas,
    cantidad_materias, nro_valorado, importe_valorado, emitido_por_uagrm
    ON tramite_documento FOR EACH ROW EXECUTE FUNCTION fn_trg_documento_valorado();

CREATE OR REPLACE FUNCTION fn_trg_expediente_version() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    SELECT COALESCE(MAX(version), 0) + 1 INTO NEW.version FROM expediente WHERE tramite_id = NEW.tramite_id;
    UPDATE expediente SET vigente = FALSE WHERE tramite_id = NEW.tramite_id AND vigente;
    NEW.vigente := TRUE;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_expediente_version BEFORE INSERT ON expediente FOR EACH ROW EXECUTE FUNCTION fn_trg_expediente_version();

-- =====================================================================
-- CU3: DESCUENTO DEL 50% PARA DIPLOMADO (RN-02 y RN-03)
-- =====================================================================
-- RN-02: semestral = hasta 2 periodos académicos después de la última nota; anual = 1 gestión
CREATE OR REPLACE FUNCTION fn_descuento_en_plazo(p_regimen regimen_carrera, p_anio_nota INT, p_periodo_nota INT,
                                                 p_fecha_solicitud TIMESTAMP)
RETURNS BOOLEAN LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_regimen
        WHEN 'SEMESTRAL' THEN (EXTRACT(YEAR FROM p_fecha_solicitud)::INT * 2 + fn_periodo_academico(p_fecha_solicitud))
                              - (p_anio_nota * 2 + p_periodo_nota) <= 2
        ELSE EXTRACT(YEAR FROM p_fecha_solicitud)::INT - p_anio_nota <= 1 END;
$$;

CREATE OR REPLACE FUNCTION fn_trg_descuento() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE t tramite;
BEGIN
    SELECT * INTO t FROM tramite WHERE id = NEW.tramite_id;
    IF t.tipo <> 'DESCUENTO_DIPLOMADO' THEN
        RAISE EXCEPTION 'El trámite % no es de DESCUENTO_DIPLOMADO', t.codigo;
    END IF;
    IF NEW.modulo_reprobado IS NOT NULL THEN                -- RN-03
        NEW.financiamiento := 'PERDIDO_POR_REPROBACION';
        NEW.fecha_perdida  := COALESCE(NEW.fecha_perdida, CURRENT_DATE);
    ELSIF fn_descuento_en_plazo(NEW.regimen, NEW.anio_ultima_nota, NEW.periodo_ultima_nota, t.fecha_ingreso) THEN
        NEW.financiamiento := 'CON_FINANCIAMIENTO';
    ELSE                                                    -- se cataloga de oficio
        NEW.financiamiento := 'SIN_FINANCIAMIENTO';
    END IF;

    -- Sección III: la constancia se entrega una sola vez, con el beneficio vigente y el trámite concluido
    IF TG_OP = 'UPDATE' AND OLD.fecha_entrega IS NOT NULL
       AND (NEW.fecha_entrega IS DISTINCT FROM OLD.fecha_entrega
            OR NEW.nro_formulario_emitido IS DISTINCT FROM OLD.nro_formulario_emitido) THEN
        RAISE EXCEPTION 'La constancia del trámite % ya se entregó el %: la entrega no se modifica',
                        t.codigo, OLD.fecha_entrega;
    END IF;
    IF NEW.fecha_entrega IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.fecha_entrega IS NULL) THEN
        IF NEW.financiamiento <> 'CON_FINANCIAMIENTO' THEN
            RAISE EXCEPTION 'El trámite % está % : no se entrega constancia de descuento', t.codigo, NEW.financiamiento;
        END IF;
        IF t.estado <> 'CONCLUIDO' THEN
            RAISE EXCEPTION 'El trámite % debe estar CONCLUIDO para entregar la constancia (estado: %)', t.codigo, t.estado;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM certificado_oficial WHERE tramite_id = t.id AND estado = 'VIGENTE') THEN
            RAISE EXCEPTION 'El trámite % no tiene una constancia vigente para entregar', t.codigo;
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_descuento BEFORE INSERT OR UPDATE ON solicitud_descuento
    FOR EACH ROW EXECUTE FUNCTION fn_trg_descuento();

CREATE OR REPLACE FUNCTION fn_trg_descuento_observar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.financiamiento = 'SIN_FINANCIAMIENTO'
       AND (TG_OP = 'INSERT' OR OLD.financiamiento IS DISTINCT FROM NEW.financiamiento) THEN
        INSERT INTO observacion(tramite_id, tipo, descripcion)
        VALUES (NEW.tramite_id, 'FUERA_DE_PLAZO',
                'Solicitud fuera del plazo de la RN-02: se cataloga de oficio como Sin Financiamiento.');
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_descuento_observar AFTER INSERT OR UPDATE ON solicitud_descuento
    FOR EACH ROW EXECUTE FUNCTION fn_trg_descuento_observar();

-- =====================================================================
-- CU4: OBSERVACIONES
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_trg_observacion() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    ELSIF NEW.estado = 'SUBSANADA' AND OLD.estado <> 'SUBSANADA' THEN
        NEW.fecha_subsanacion := LOCALTIMESTAMP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_observacion BEFORE INSERT OR UPDATE ON observacion FOR EACH ROW EXECUTE FUNCTION fn_trg_observacion();

-- =====================================================================
-- CU5: FIRMA DIGITAL CONSOLIDADA / REMOTA
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_trg_lote_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF fn_rol_usuario(NEW.firmante_id) = 'VICERRECTOR' THEN
        NEW.delegacion_id := NULL;
        NEW.calidad       := 'TITULAR';
    ELSE
        SELECT id INTO NEW.delegacion_id FROM delegacion_autoridad
         WHERE suplente_id = NEW.firmante_id AND estado = 'ACTIVA'
           AND CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin
         ORDER BY id DESC LIMIT 1;
        IF NEW.delegacion_id IS NULL THEN
            RAISE EXCEPTION 'El usuario % no es Vicerrector/a ni tiene delegación vigente', NEW.firmante_id;
        END IF;
        NEW.calidad := 'INTERINO';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM certificado_digital
                    WHERE id = NEW.certificado_id AND usuario_id = NEW.firmante_id AND activo
                      AND CURRENT_DATE BETWEEN fecha_emision AND fecha_expiracion) THEN
        RAISE EXCEPTION 'Certificado digital inválido, vencido o ajeno al firmante';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_lote_validar BEFORE INSERT ON lote_firma FOR EACH ROW EXECUTE FUNCTION fn_trg_lote_validar();

CREATE OR REPLACE FUNCTION fn_trg_firma_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF (SELECT estado FROM tramite WHERE id = NEW.tramite_id) <> 'EN_BANDEJA_DESPACHO' THEN
        RAISE EXCEPTION 'Solo se firman trámites que están en la bandeja de despacho';
    END IF;
    IF NEW.expediente_id IS NULL THEN
        SELECT id INTO NEW.expediente_id FROM expediente WHERE tramite_id = NEW.tramite_id AND vigente;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_firma_validar BEFORE INSERT ON firma_digital FOR EACH ROW EXECUTE FUNCTION fn_trg_firma_validar();

-- Firma de varios expedientes con una sola confirmación: devuelve cuántos se firmaron
CREATE OR REPLACE FUNCTION fn_firmar_lote(p_lote INT, p_tramites INT[], p_firma_valor TEXT)
RETURNS INT LANGUAGE plpgsql AS $$
DECLARE v_id INT; n INT := 0;
BEGIN
    FOREACH v_id IN ARRAY p_tramites LOOP
        INSERT INTO firma_digital(lote_id, tramite_id, hash_documento, firma_valor)
        SELECT p_lote, v_id, COALESCE(e.hash_sha256, encode(sha256(convert_to(t.codigo, 'UTF8')), 'hex')), p_firma_valor
          FROM tramite t LEFT JOIN expediente e ON e.tramite_id = t.id AND e.vigente
         WHERE t.id = v_id;
        UPDATE tramite SET estado = 'FIRMADO_DIGITALMENTE' WHERE id = v_id;
        n := n + 1;
    END LOOP;
    RETURN n;
END $$;

-- =====================================================================
-- CU6: CERTIFICADO OFICIAL / CONSTANCIA CON QR
-- Si firma un suplente, la leyenda y el QR dejan constancia de la calidad de
-- Vicerrector/a Interino/a y de la resolución de suplencia (Anexo Delegaciones).
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_leyenda_firma(p_firma INT) RETURNS TEXT LANGUAGE sql STABLE AS $$
    SELECT 'Firmado digitalmente por ' || u.nombres || ' ' || u.apellidos ||
           CASE l.calidad
               WHEN 'TITULAR' THEN ' en calidad de Vicerrector/a titular'
               ELSE ' en calidad de Vicerrector/a Interino/a según Resolución ' ||
                    CASE d.tipo_resolucion WHEN 'RECTORAL' THEN 'Rectoral' ELSE 'del ICU' END ||
                    ' N° ' || d.nro_resolucion || ' de ' || to_char(d.fecha_resolucion, 'DD/MM/YYYY') END ||
           CASE WHEN l.es_remota THEN ' (firma remota)' ELSE '' END
      FROM firma_digital f
      JOIN lote_firma l ON l.id = f.lote_id
      JOIN usuario u    ON u.id = l.firmante_id
      LEFT JOIN delegacion_autoridad d ON d.id = l.delegacion_id
     WHERE f.id = p_firma;
$$;

CREATE OR REPLACE FUNCTION fn_trg_certificado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_tipo tipo_tramite;
BEGIN
    SELECT tipo INTO v_tipo FROM tramite WHERE id = NEW.tramite_id;
    NEW.tipo := CASE v_tipo WHEN 'LEGALIZACION' THEN 'LEGALIZACION' ELSE 'CONSTANCIA_DESCUENTO' END::tipo_certificado;
    IF NEW.numero IS NULL THEN
        NEW.numero := CASE NEW.tipo WHEN 'LEGALIZACION' THEN 'CL-' ELSE 'CD-' END ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_certificado')::TEXT, 6, '0');
    END IF;
    IF NEW.firma_id IS NULL THEN
        SELECT id INTO NEW.firma_id FROM firma_digital WHERE tramite_id = NEW.tramite_id AND valida
         ORDER BY fecha_firma DESC LIMIT 1;
    END IF;
    IF NEW.firma_id IS NULL THEN
        RAISE EXCEPTION 'El trámite no tiene firma digital válida para emitir el certificado';
    END IF;
    NEW.leyenda_firma := fn_leyenda_firma(NEW.firma_id);
    -- RF-15: el QR lo arma la aplicación (correo al sistema con VERIFICARCERT); debe llevar el código y el hash
    IF NEW.qr_contenido IS NULL
       OR strpos(NEW.qr_contenido, NEW.codigo_verificacion::TEXT) = 0
       OR strpos(lower(NEW.qr_contenido), lower(NEW.hash_sha256)) = 0 THEN
        RAISE EXCEPTION 'El QR del certificado debe incluir su código de verificación y el hash del expediente';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER trg_certificado BEFORE INSERT ON certificado_oficial FOR EACH ROW EXECUTE FUNCTION fn_trg_certificado();

-- RF-15: un certificado emitido no se modifica; solo puede anularse (definitivo), con motivo, y se avisa al interesado
CREATE OR REPLACE FUNCTION fn_trg_certificado_anular() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.estado = 'ANULADO' THEN
        RAISE EXCEPTION 'El certificado % ya está ANULADO: la anulación es definitiva', OLD.numero;
    END IF;
    IF NEW.estado <> 'ANULADO'
       OR (NEW.tramite_id, NEW.firma_id, NEW.numero, NEW.tipo, NEW.ruta_pdfa, NEW.hash_sha256, NEW.codigo_verificacion,
           NEW.leyenda_firma, NEW.qr_contenido, NEW.fecha_emision)
          IS DISTINCT FROM
          (OLD.tramite_id, OLD.firma_id, OLD.numero, OLD.tipo, OLD.ruta_pdfa, OLD.hash_sha256, OLD.codigo_verificacion,
           OLD.leyenda_firma, OLD.qr_contenido, OLD.fecha_emision) THEN
        RAISE EXCEPTION 'Un certificado emitido no se modifica: solo puede anularse';
    END IF;
    IF NULLIF(btrim(NEW.motivo_anulacion), '') IS NULL THEN
        RAISE EXCEPTION 'Indique el motivo de la anulación del certificado %', OLD.numero;
    END IF;
    NEW.fecha_anulacion := LOCALTIMESTAMP;
    NEW.anulado_por     := fn_usuario_actual();
    INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
    SELECT t.solicitante_id, t.id, 'CAMBIO_ESTADO',
           'El certificado ' || OLD.numero || ' del trámite ' || t.codigo || ' fue ANULADO: ' || btrim(NEW.motivo_anulacion)
      FROM tramite t WHERE t.id = OLD.tramite_id;
    RETURN NEW;
END $$;

CREATE TRIGGER trg_certificado_anular BEFORE UPDATE ON certificado_oficial
    FOR EACH ROW EXECUTE FUNCTION fn_trg_certificado_anular();

CREATE OR REPLACE FUNCTION fn_verificar_certificado(p_codigo VARCHAR, p_hash VARCHAR DEFAULT NULL)
RETURNS TABLE (resultado VARCHAR, numero VARCHAR, tipo tipo_certificado, tramite VARCHAR, titular TEXT,
               fecha_emision TIMESTAMP, leyenda_firma TEXT)
LANGUAGE sql STABLE AS $$
    SELECT (CASE WHEN c.id IS NULL THEN 'NO_ENCONTRADO'
                 WHEN c.estado = 'ANULADO' THEN 'ANULADO'
                 WHEN p_hash IS NOT NULL AND lower(p_hash) <> c.hash_sha256 THEN 'HASH_ALTERADO'
                 ELSE 'VALIDO' END)::VARCHAR,
           c.numero, c.tipo, t.codigo, u.nombres || ' ' || u.apellidos, c.fecha_emision, c.leyenda_firma
      FROM (SELECT 1) x
      LEFT JOIN certificado_oficial c ON c.codigo_verificacion::TEXT = p_codigo
      LEFT JOIN tramite t ON t.id = c.tramite_id
      LEFT JOIN usuario u ON u.id = t.solicitante_id;
$$;

-- =====================================================================
-- VISTAS OPERATIVAS
-- =====================================================================

-- CU7: semáforo SLA
CREATE OR REPLACE VIEW v_semaforo_sla AS
WITH consumo AS (
    SELECT tramite_id,
           SUM(CASE WHEN fn_estado_cuenta_sla(estado)
                    THEN COALESCE(horas_habiles, fn_horas_habiles(fecha_inicio, LOCALTIMESTAMP)) ELSE 0 END) AS horas
      FROM tramite_tiempo_fase GROUP BY tramite_id
)
SELECT t.id AS tramite_id, t.codigo, t.tipo, t.estado, fn_estado_es_final(t.estado) AS es_final, t.prioridad,
       t.fecha_ingreso, COALESCE(c.horas, 0) AS horas_consumidas, s.plazo_horas,
       ROUND(100.0 * COALESCE(c.horas, 0) / s.plazo_horas, 1) AS porcentaje,
       CASE WHEN COALESCE(c.horas, 0) >= s.plazo_horas THEN 'ROJO'
            WHEN COALESCE(c.horas, 0) >= s.plazo_horas * s.porcentaje_alerta / 100 THEN 'AMARILLO'
            ELSE 'VERDE' END AS semaforo
  FROM tramite t
  LEFT JOIN consumo c ON c.tramite_id = t.id
  CROSS JOIN LATERAL (SELECT plazo_horas, porcentaje_alerta FROM sla_config sc
                       WHERE sc.tipo = t.tipo OR sc.tipo IS NULL
                       ORDER BY sc.tipo NULLS LAST LIMIT 1) s;

-- Genera alertas preventivas/vencidas y notifica a Recepción (RF-17 / RF-19); devuelve las alertas nuevas
CREATE OR REPLACE FUNCTION fn_generar_alertas_sla() RETURNS INT LANGUAGE plpgsql AS $$
DECLARE n INT;
BEGIN
    WITH nuevas AS (
        INSERT INTO alerta_sla(tramite_id, tipo, horas_transcurridas, plazo_horas)
        SELECT tramite_id, (CASE semaforo WHEN 'ROJO' THEN 'VENCIDA' ELSE 'PREVENTIVA' END)::tipo_alerta,
               horas_consumidas, plazo_horas
          FROM v_semaforo_sla WHERE NOT es_final AND semaforo IN ('AMARILLO', 'ROJO')
        ON CONFLICT (tramite_id, tipo) DO NOTHING
        RETURNING tramite_id, tipo
    ), avisos AS (
        INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
        SELECT u.id, a.tramite_id, 'ALERTA_SLA',
               'Alerta ' || a.tipo || ' de 72 h hábiles en el trámite ' || t.codigo
          FROM nuevas a JOIN tramite t ON t.id = a.tramite_id
          JOIN usuario u ON u.rol = 'RECEPCION' AND u.activo
    )
    SELECT COUNT(*) INTO n FROM nuevas;
    RETURN n;
END $$;

-- CU5 / RF-11: bandeja de despacho clasificada por prioridad, tipo y destino
CREATE OR REPLACE VIEW v_bandeja_despacho AS
SELECT t.id AS tramite_id, t.codigo, t.tipo, t.destino, t.pais_destino, t.institucion_destino,
       u.nombres || ' ' || u.apellidos AS solicitante, u.registro, c.nombre AS carrera,
       t.prioridad, t.total_fojas, ex.id AS expediente_id, ex.ruta_pdf,
       s.horas_consumidas, s.semaforo, t.fecha_ingreso
  FROM tramite t
  JOIN usuario u        ON u.id = t.solicitante_id
  JOIN carrera c        ON c.id = t.carrera_id
  LEFT JOIN expediente ex ON ex.tramite_id = t.id AND ex.vigente
  JOIN v_semaforo_sla s ON s.tramite_id = t.id
 WHERE t.estado = 'EN_BANDEJA_DESPACHO'
 ORDER BY t.prioridad DESC,
          CASE s.semaforo WHEN 'ROJO' THEN 1 WHEN 'AMARILLO' THEN 2 ELSE 3 END, t.tipo, t.destino, t.fecha_ingreso;

-- CU4 / RF-06: guía procesal
CREATE OR REPLACE VIEW v_guia_procesal AS
SELECT f.tipo, COALESCE(f.destino::TEXT, 'AMBOS') AS destino, f.orden, uo.nombre AS unidad,
       f.nombre AS paso, f.descripcion, f.firma_requerida, f.sello_requerido
  FROM flujo_paso f JOIN unidad_origen uo ON uo.id = f.unidad_id
 ORDER BY f.tipo, f.orden;

-- CU4 / RF-06: origen de cada documento y su cadena de firmas y sellos precedentes (RN-04)
CREATE OR REPLACE VIEW v_ruta_documento AS
SELECT cd.codigo AS documento_codigo, cd.nombre AS documento, ue.nombre AS unidad_emisora,
       fp.orden, uf.nombre AS unidad_firma, fp.cargo_firmante, fp.requiere_sello
  FROM catalogo_documento cd
  JOIN unidad_origen ue ON ue.id = cd.unidad_emisora_id
  LEFT JOIN catalogo_firma_precedente fp ON fp.catalogo_documento_id = cd.id
  LEFT JOIN unidad_origen uf ON uf.id = fp.unidad_id
 ORDER BY cd.codigo, fp.orden;

-- CU4: posición del trámite en el flujo (usa el orden del ENUM estado_tramite)
CREATE OR REPLACE VIEW v_tramite_flujo AS
SELECT t.codigo, f.orden, uo.nombre AS unidad, f.nombre AS paso, f.firma_requerida, f.sello_requerido,
       (f.estado_asociado IS NOT NULL AND t.estado <> 'RECHAZADO' AND t.estado >= f.estado_asociado) AS completado
  FROM tramite t
  JOIN flujo_paso f     ON f.tipo = t.tipo AND (f.destino IS NULL OR f.destino = t.destino)
  JOIN unidad_origen uo ON uo.id = f.unidad_id
 ORDER BY t.codigo, f.orden;

-- CU6 / RF-16: portal de seguimiento estudiantil (Registro + C.I.)
CREATE OR REPLACE VIEW v_seguimiento AS
SELECT u.registro, u.ci, t.codigo, t.tipo, t.destino, t.pais_destino, fn_estado_nombre(t.estado) AS estado,
       t.fecha_ingreso,
       (SELECT MAX(fecha_inicio) FROM tramite_tiempo_fase f WHERE f.tramite_id = t.id) AS ultima_actualizacion,
       (SELECT COUNT(*) FROM observacion o WHERE o.tramite_id = t.id AND o.estado = 'PENDIENTE') AS observaciones_pendientes,
       sd.financiamiento, c.numero AS nro_certificado, c.codigo_verificacion
  FROM tramite t
  JOIN usuario u ON u.id = t.solicitante_id
  LEFT JOIN solicitud_descuento sd ON sd.tramite_id = t.id
  LEFT JOIN certificado_oficial c ON c.tramite_id = t.id AND c.estado = 'VIGENTE';

CREATE OR REPLACE FUNCTION fn_consultar_seguimiento(p_registro VARCHAR, p_ci VARCHAR)
RETURNS SETOF v_seguimiento LANGUAGE sql STABLE AS $$
    SELECT * FROM v_seguimiento WHERE registro = p_registro AND ci = p_ci ORDER BY fecha_ingreso DESC;
$$;

-- =====================================================================
-- CU2: INDICADORES DE GESTIÓN (cálculo dinámico, sin almacenamiento)
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_indicadores_gestion(p_ini DATE, p_fin DATE)
RETURNS TABLE (indicador VARCHAR, valor NUMERIC, unidad VARCHAR)
LANGUAGE sql STABLE AS $$
    WITH concl AS (
        SELECT s.horas_consumidas, s.plazo_horas
          FROM v_semaforo_sla s JOIN tramite t ON t.id = s.tramite_id
         WHERE t.estado = 'CONCLUIDO' AND t.fecha_conclusion::DATE BETWEEN p_ini AND p_fin
    ), ing AS (
        SELECT t.id, t.tipo, EXISTS (SELECT 1 FROM observacion o WHERE o.tramite_id = t.id) AS observado
          FROM tramite t WHERE t.fecha_ingreso::DATE BETWEEN p_ini AND p_fin
    ), fir AS (
        SELECT l.es_remota, l.calidad
          FROM firma_digital f JOIN lote_firma l ON l.id = f.lote_id
         WHERE f.valida AND f.fecha_firma::DATE BETWEEN p_ini AND p_fin
    ), des AS (
        SELECT sd.financiamiento FROM solicitud_descuento sd JOIN ing ON ing.id = sd.tramite_id
    )
    SELECT 'Cumplimiento del plazo de 72 h hábiles'::VARCHAR,
           ROUND(100.0 * COUNT(*) FILTER (WHERE horas_consumidas <= plazo_horas) / NULLIF(COUNT(*), 0), 2), '%'::VARCHAR FROM concl
    UNION ALL SELECT 'Tiempo promedio de despacho', ROUND(AVG(horas_consumidas), 2), 'HORAS' FROM concl
    UNION ALL SELECT 'Trámites concluidos', COUNT(*), 'CANTIDAD' FROM concl
    UNION ALL SELECT 'Trámites ingresados', COUNT(*), 'CANTIDAD' FROM ing
    UNION ALL SELECT 'Trámites con observaciones', ROUND(100.0 * COUNT(*) FILTER (WHERE observado) / NULLIF(COUNT(*), 0), 2), '%' FROM ing
    UNION ALL SELECT 'Solicitudes de descuento Sin Financiamiento', COUNT(*) FILTER (WHERE financiamiento = 'SIN_FINANCIAMIENTO'), 'CANTIDAD' FROM des
    UNION ALL SELECT 'Firmas realizadas en forma remota', ROUND(100.0 * COUNT(*) FILTER (WHERE es_remota) / NULLIF(COUNT(*), 0), 2), '%' FROM fir
    UNION ALL SELECT 'Firmas realizadas por autoridad interina', COUNT(*) FILTER (WHERE calidad = 'INTERINO'), 'CANTIDAD' FROM fir
    UNION ALL SELECT 'Delegaciones de autoridad registradas', COUNT(*), 'CANTIDAD' FROM delegacion_autoridad
               WHERE fecha_inicio <= p_fin AND fecha_fin >= p_ini;
$$;

-- =====================================================================
-- CU8 - REPORTES Y ESTADÍSTICAS (RF-18)
-- =====================================================================
CREATE OR REPLACE VIEW v_rep_tramites AS
SELECT t.id, t.codigo, t.tipo, t.destino, t.pais_destino, f.nombre AS facultad, c.nombre AS carrera, t.estado, t.prioridad,
       t.total_fojas, t.fecha_ingreso, t.fecha_conclusion, s.horas_consumidas, s.semaforo
  FROM tramite t
  JOIN carrera c        ON c.id = t.carrera_id
  JOIN facultad f       ON f.id = c.facultad_id
  JOIN v_semaforo_sla s ON s.tramite_id = t.id;

CREATE OR REPLACE VIEW v_rep_por_carrera AS
SELECT facultad, carrera, tipo, COUNT(*) AS total,
       COUNT(*) FILTER (WHERE estado = 'CONCLUIDO') AS concluidos,
       COUNT(*) FILTER (WHERE fecha_conclusion IS NULL) AS en_proceso,
       SUM(total_fojas) AS fojas
  FROM v_rep_tramites GROUP BY facultad, carrera, tipo ORDER BY facultad, carrera;

CREATE OR REPLACE VIEW v_rep_tiempos_despacho AS
SELECT date_trunc('month', fecha_conclusion)::DATE AS mes, tipo, destino, COUNT(*) AS concluidos,
       ROUND(AVG(horas_consumidas), 2) AS horas_promedio, MAX(horas_consumidas) AS horas_max,
       ROUND(100.0 * COUNT(*) FILTER (WHERE semaforo <> 'ROJO') / COUNT(*), 1) AS pct_dentro_72h
  FROM v_rep_tramites WHERE estado = 'CONCLUIDO'
 GROUP BY 1, 2, 3 ORDER BY 1 DESC;

-- RF-18: valorados de caja registrados por Recepción (dato estadístico: el área no cobra)
CREATE OR REPLACE VIEW v_rep_valorados AS
SELECT date_trunc('month', t.fecha_ingreso)::DATE AS mes, cd.nombre AS documento, c.nombre AS carrera,
       COUNT(*) AS valorados, SUM(d.importe_valorado) AS total_bs
  FROM tramite_documento d
  JOIN tramite t             ON t.id = d.tramite_id
  JOIN carrera c             ON c.id = t.carrera_id
  JOIN catalogo_documento cd ON cd.id = d.catalogo_documento_id
 WHERE d.nro_valorado IS NOT NULL AND d.estado <> 'RECHAZADO'
 GROUP BY 1, 2, 3 ORDER BY 1 DESC, 2, 3;

CREATE OR REPLACE VIEW v_rep_descuentos AS
SELECT sd.unidad_postgrado, sd.gestion_diplomado, sd.financiamiento, COUNT(*) AS solicitudes
  FROM solicitud_descuento sd
 GROUP BY 1, 2, 3 ORDER BY 1, 2, 3;

CREATE OR REPLACE VIEW v_rep_observaciones AS
SELECT tipo, COUNT(*) AS total, COUNT(*) FILTER (WHERE estado = 'PENDIENTE') AS pendientes
  FROM observacion GROUP BY tipo ORDER BY total DESC;

-- =====================================================================
-- DATOS INICIALES DE CATÁLOGO
-- =====================================================================
-- Orden de prelación oficial (Art. 46 del Estatuto Orgánico). anio_creacion = año en que obtuvo rango de facultad.
INSERT INTO facultad(codigo, nombre, orden_prelacion, anio_creacion) VALUES
 ('FCJPS', 'Ciencias Jurídicas, Políticas, Sociales y Relaciones Internacionales',                    1, 1879),
 ('FCEE',  'Ciencias Económicas y Empresariales',                                                     2, 1939),
 ('FCV',   'Ciencias Veterinarias',                                                                   3, 1940),
 ('FCET',  'Ciencias Exactas y Tecnología',                                                           4, 1967),
 ('FCA',   'Ciencias Agrícolas',                                                                      5, 1970),
 ('FPOLI', 'Politécnica',                                                                             6, 1987),
 ('FCSH',  'Ciencias de la Salud Humana',                                                             7, 1992),
 ('FHUM',  'Humanidades',                                                                             8, 1993),
 ('FCHDA', 'Ciencias del Hábitat, Diseño y Arte',                                                     9, 1997),
 ('FICH',  'Integral del Chaco',                                                                     10, 2000),
 ('FCCA',  'Ciencias Contables, Auditoría, Sistemas de Control de Gestión y Finanzas',               11, 2002),
 ('FINE',  'Integral del Noreste',                                                                   12, 2014),
 ('FINOR', 'Integral del Norte',                                                                     13, 2007),
 ('FIVC',  'Integral de los Valles Cruceños',                                                        14, 2010),
 ('FINI',  'Integral del Ichilo',                                                                    15, 2010),
 ('FCFB',  'Ciencias Farmacéuticas y Bioquímicas',                                                   16, 2011),
 ('FICCT', 'Ingeniería en Ciencias de la Computación y Telecomunicaciones',                          17, 2012),
 ('FAIC',  'Integral Chiquitana',                                                                    18, 2015);

INSERT INTO carrera(codigo, nombre, facultad_id)
SELECT x.codigo, x.nombre, f.id FROM (VALUES
 ('187-3', 'Ingeniería de Sistemas',         'FICCT'),
 ('187-4', 'Ingeniería Informática',         'FICCT'),
 ('CPA',   'Auditoría / Contaduría Pública', 'FCCA'),
 ('ICOM',  'Ingeniería Comercial',           'FCEE'),
 ('MED',   'Medicina',                       'FCSH'),
 ('DER',   'Derecho',                        'FCJPS')
) AS x(codigo, nombre, facultad) JOIN facultad f ON f.codigo = x.facultad;

INSERT INTO unidad_origen(codigo, nombre) VALUES
 ('CARRERA',        'Dirección de Carrera'),
 ('DECANATO',       'Decanato / Vicedecanato'),
 ('ADMISIONES',     'Departamento de Admisiones y Registro'),
 ('DAGA',           'Dirección de Acreditación y Gestión Académica (DAGA/DICA)'),
 ('CAJA',           'Caja / Tesorería'),
 ('CPD',            'Centro de Procesamiento de Datos (CPD) facultativo'),
 ('VICERRECTORADO', 'Vicerrectorado - Ventanilla de Legalizaciones'),
 ('RECTORADO',      'Rectorado'),
 ('POSTGRADO',      'Unidad de Postgrado');

-- Aranceles referenciales para verificar el valorado (RN-01: Bs. 15 por foja de notas, Bs. 10 por materia
-- analítica, Bs. 45 por plan o histórico). Los demás montos: verificar con el tarifario oficial de Tesorería.
INSERT INTO catalogo_documento(codigo, nombre, unidad_emisora_id, unidad_cobro, arancel_referencial)
SELECT x.cod, x.nom, u.id, x.cobro::unidad_cobro, x.arancel FROM (VALUES
 ('PROG_ANALITICO',  'Programas analíticos',                               'CARRERA', 'MATERIA', 10),
 ('PLAN_ESTUDIOS',   'Plan de estudios con carga horaria',                 'CARRERA', 'DOCUMENTO', 45),
 ('MALLA',           'Malla curricular',                                   'CARRERA', 'DOCUMENTO', 15),
 ('EQUIVALENCIAS',   'Tablas de equivalencias o convalidaciones',          'CARRERA', 'DOCUMENTO', 15),
 ('CERT_NOTAS',      'Certificado de notas',                               'ADMISIONES', 'FOJA', 15),
 ('HISTORICO',       'Histórico académico',                                'ADMISIONES', 'DOCUMENTO', 45),
 ('VENC_PLAN',       'Certificado de vencimiento de plan de estudios',     'ADMISIONES', 'DOCUMENTO', 15),
 ('ALUMNO_REGULAR',  'Certificado de alumno regular (activo / pasivo)',    'ADMISIONES', 'DOCUMENTO', 15),
 ('FUNC_INSTITUC',   'Certificado de funcionamiento institucional',        'DAGA', 'DOCUMENTO', 15),
 ('RES_VICERRECT',   'Resolución vicerrectoral (ej. 290/07, 129/2010)',    'VICERRECTORADO', 'DOCUMENTO', 15),
 ('CARGA_INTERNADO', 'Carga horaria de internado rotatorio',               'CARRERA', 'DOCUMENTO', 15),
 ('NOMINA_DOCENTE',  'Nómina de docentes titulares',                       'CARRERA', 'FOJA', 15),
 ('CONVERSION_NOTAS','Certificado de conversión de notas escala 1-10',     'ADMISIONES', 'DOCUMENTO', 15),
 ('MERCOSUR',        'Certificado académico de acreditación al Mercosur',  'DAGA', 'DOCUMENTO', 15),
 ('PROMEDIO',        'Certificado de promedio general o récord académico', 'ADMISIONES', 'DOCUMENTO', 15),
 ('PENSUM_MEDICINA', 'Certificado de pensum de Medicina',                  'CARRERA', 'DOCUMENTO', 15),
 ('HORAS_RELOJ',     'Certificado de horas reloj',                         'CARRERA', 'DOCUMENTO', 15)
) AS x(cod, nom, unidad, cobro, arancel) JOIN unidad_origen u ON u.codigo = x.unidad;

-- RN-04 / RF-06: firmas y sellos previos por documento
INSERT INTO catalogo_firma_precedente(catalogo_documento_id, orden, unidad_id, cargo_firmante, requiere_sello)
SELECT d.id, x.orden, u.id, x.cargo, x.sello FROM (VALUES
 ('PROG_ANALITICO',  1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('PROG_ANALITICO',  2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('PROG_ANALITICO',  3, 'DAGA',       'Jefe DAGA / DICA',                   TRUE),
 ('PLAN_ESTUDIOS',   1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('PLAN_ESTUDIOS',   2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('PLAN_ESTUDIOS',   3, 'DAGA',       'Jefe DAGA / DICA',                   TRUE),
 ('MALLA',           1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('MALLA',           2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('EQUIVALENCIAS',   1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('EQUIVALENCIAS',   2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('CERT_NOTAS',      1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('HISTORICO',       1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('VENC_PLAN',       1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('ALUMNO_REGULAR',  1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('CONVERSION_NOTAS',1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('PROMEDIO',        1, 'ADMISIONES', 'Jefe de Admisiones y Registro',      TRUE),
 ('FUNC_INSTITUC',   1, 'DAGA',       'Jefe DAGA / DICA',                   TRUE),
 ('MERCOSUR',        1, 'DAGA',       'Jefe DAGA / DICA',                   TRUE),
 ('CARGA_INTERNADO', 1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('CARGA_INTERNADO', 2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('NOMINA_DOCENTE',  1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('NOMINA_DOCENTE',  2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('PENSUM_MEDICINA', 1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('PENSUM_MEDICINA', 2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('HORAS_RELOJ',     1, 'CARRERA',    'Director de Carrera',                TRUE),
 ('HORAS_RELOJ',     2, 'DECANATO',   'Decano / Vicedecano',                TRUE),
 ('RES_VICERRECT',   1, 'VICERRECTORADO', 'Vicerrector/a',                  TRUE)
) AS x(doc, orden, unidad, cargo, sello)
JOIN catalogo_documento d ON d.codigo = x.doc
JOIN unidad_origen u      ON u.codigo = x.unidad;

INSERT INTO requisito(tipo, codigo, descripcion, obligatorio, pais_aplica) VALUES
 ('LEGALIZACION', 'CARTA',          'Carta de solicitud dirigida a la Vicerrectora con detalle de ítems, páginas y país de destino', TRUE, NULL),
 ('LEGALIZACION', 'RECIBO_PAGO',    'Valorado de caja de cada documento a legalizar (N° e importe)',                                              TRUE, NULL),
 ('LEGALIZACION', 'FIRMAS_PREVIAS', 'Firmas y sellos precedentes completos (Carrera, Decanato, Admisiones, DAGA)',                TRUE, NULL),
 ('LEGALIZACION', 'FOLIACION',      'Legajo anillado y foliado correlativamente en bolígrafo azul o negro',                       TRUE, NULL),
 ('LEGALIZACION', 'FOJAS_SEPARADAS','Certificados tramitados y legalizados en fojas independientes y separadas (RN-05)',           TRUE, 'ARGENTINA'),
 ('DESCUENTO_DIPLOMADO', 'GRL',        'Inscripción de la materia GRL (modalidad de graduación) en el CPD facultativo',             TRUE, NULL),
 ('DESCUENTO_DIPLOMADO', 'CARTA',      'Carta a la Vicerrectora solicitando el 50%, con unidad de postgrado, registro, nombre y firma', TRUE, NULL),
 ('DESCUENTO_DIPLOMADO', 'FORMULARIO', 'Formulario de solicitud de descuento (Secciones I y II) debidamente llenado',               TRUE, NULL),
 ('DESCUENTO_DIPLOMADO', 'AVANCE',     'Fotocopia simple del Avance Académico (pago de Bs. 50 en Caja N° 6)',                        TRUE, NULL);

INSERT INTO flujo_paso(tipo, destino, orden, unidad_id, nombre, descripcion, firma_requerida, sello_requerido, estado_asociado)
SELECT x.tipo::tipo_tramite, x.dest::destino_tramite, x.orden, u.id, x.nombre, x.descr, x.firma, x.sello, x.estado::estado_tramite
FROM (VALUES
 -- Flujo A: Legalización de documentos académicos
 ('LEGALIZACION', NULL,       1, 'CARRERA',        'Emisión en origen', 'Programas, mallas y planes anillados y foliados en Carrera y Decanato; notas e históricos en Admisiones', 'Director de Carrera, Decano/Vicedecano o Jefe de Admisiones', 'Sello de Carrera / Decanato / Admisiones', 'INGRESADO'),
 ('LEGALIZACION', NULL,       2, 'DAGA',           'Visado DAGA / DICA', 'Firma y sello de acreditación', 'Jefe DAGA / DICA', 'Sello DAGA', 'INGRESADO'),
 ('LEGALIZACION', NULL,       3, 'CAJA',           'Pago de valorados', 'El interesado adquiere en Caja los valorados de cada documento (RN-01)', NULL, 'Comprobante de caja', 'INGRESADO'),
 ('LEGALIZACION', NULL,       4, 'VICERRECTORADO', 'Recepción y cotejo', 'Cotejo de la carta, documentos con cantidad de páginas por ítem y registro del valorado de cada documento', NULL, NULL, 'EN_REVISION'),
 ('LEGALIZACION', NULL,       5, 'VICERRECTORADO', 'Certificación y compilación', 'Emisión de la certificación y compilación del expediente', NULL, NULL, 'EN_BANDEJA_DESPACHO'),
 ('LEGALIZACION', NULL,       6, 'VICERRECTORADO', 'Firma digital de la autoridad', 'Firma consolidada del expediente', 'Vicerrector/a o Vicerrector/a Interino/a', 'Sello digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'),
 ('LEGALIZACION', 'EXTERIOR', 7, 'RECTORADO',      'Sello final institucional', 'Firma y sello de Rectorado previo a la Apostilla de La Haya en Cancillería', 'Rector/a', 'Sello institucional UAGRM', 'DERIVADO_RECTORADO'),
 ('LEGALIZACION', NULL,       8, 'VICERRECTORADO', 'Entrega al interesado', 'Entrega del documento legalizado con certificado QR', NULL, NULL, 'CONCLUIDO'),
 -- Flujo B: Descuento del 50% para diplomado
 ('DESCUENTO_DIPLOMADO', NULL, 1, 'CPD',            'Inscripción GRL', 'Inscripción de la materia GRL (modalidad de graduación)', NULL, NULL, 'INGRESADO'),
 ('DESCUENTO_DIPLOMADO', NULL, 2, 'CAJA',           'Pago del Avance Académico', 'Pago de Bs. 50 en Caja N° 6 del campus', NULL, 'Comprobante de caja', 'INGRESADO'),
 ('DESCUENTO_DIPLOMADO', NULL, 3, 'ADMISIONES',     'Emisión del Avance Académico', 'Avance Académico para modalidad de graduación', 'Jefe de Admisiones y Registro', 'Sello de Admisiones', 'INGRESADO'),
 ('DESCUENTO_DIPLOMADO', NULL, 4, 'VICERRECTORADO', 'Verificación de recaudos y plazo', 'Carta, formulario, avance y GRL; plazo RN-02 (2 semestres o 1 gestión)', NULL, NULL, 'EN_REVISION'),
 ('DESCUENTO_DIPLOMADO', NULL, 5, 'VICERRECTORADO', 'Envío a despacho', 'Compilación del expediente y envío a bandeja', NULL, NULL, 'EN_BANDEJA_DESPACHO'),
 ('DESCUENTO_DIPLOMADO', NULL, 6, 'VICERRECTORADO', 'Firma y autorización', 'Autorización del descuento del 50%', 'Vicerrector/a o Vicerrector/a Interino/a', 'Sello digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'),
 ('DESCUENTO_DIPLOMADO', NULL, 7, 'VICERRECTORADO', 'Entrega de constancia', 'Constancia de entrega firmada por el estudiante (Sección III)', 'Estudiante', NULL, 'CONCLUIDO'),
 ('DESCUENTO_DIPLOMADO', NULL, 8, 'POSTGRADO',      'Presentación en postgrado', 'El estudiante presenta la constancia en la Unidad de Postgrado (instancia externa)', NULL, NULL, NULL)
) AS x(tipo, dest, orden, unidad, nombre, descr, firma, sello, estado)
JOIN unidad_origen u ON u.codigo = x.unidad;

INSERT INTO sla_config(tipo, plazo_horas, porcentaje_alerta) VALUES (NULL, 72, 75);


-- 2. Tabla de tokens de sesión para Laravel Sanctum
CREATE TABLE personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT NOT NULL,
    name TEXT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
CREATE INDEX ix_pat_tokenable ON personal_access_tokens (tokenable_type, tokenable_id);

-- 3. RBAC: Permisos por rol (complementa tu ENUM rol_usuario)
CREATE TABLE permiso (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    modulo VARCHAR(60) NOT NULL
);

CREATE TABLE rol_permiso (
    rol rol_usuario NOT NULL,
    permiso_id INT NOT NULL REFERENCES permiso(id) ON DELETE CASCADE,
    PRIMARY KEY (rol, permiso_id)
);

-- 4. Menú Dinámico por Rol (Req. 2 INF513)
CREATE TABLE menu_opcion (
    id SERIAL PRIMARY KEY,
    rol rol_usuario NOT NULL,
    padre_id INT REFERENCES menu_opcion(id) ON DELETE CASCADE,
    titulo VARCHAR(100) NOT NULL,
    ruta VARCHAR(150),
    icono VARCHAR(50),
    orden INT NOT NULL DEFAULT 1,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- 5. Bitácora de Accesos y Tráfico Web (Req. 4 INF513)
CREATE TABLE bitacora_acceso (
    id BIGSERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuario(id) ON DELETE SET NULL,
    email_intento VARCHAR(150),
    accion VARCHAR(50) NOT NULL, -- LOGIN_EXITOSO, LOGIN_FALLIDO, LOGOUT, NAVEGACION
    ruta VARCHAR(200),
    metodo VARCHAR(10),
    ip_origen VARCHAR(45),
    user_agent TEXT,
    fecha TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP
);
CREATE INDEX ix_bitacora_acceso_fecha ON bitacora_acceso(fecha DESC);
CREATE INDEX ix_bitacora_acceso_ruta  ON bitacora_acceso(ruta);

-- 6. Contador de Visitas por Página (Req. 7 INF513)
CREATE TABLE contador_visita (
    ruta VARCHAR(150) PRIMARY KEY,
    titulo_pagina VARCHAR(120) NOT NULL,
    visitas BIGINT NOT NULL DEFAULT 0,
    ultima_visita TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP
);

-- =====================================================================
-- DATOS INICIALES DE MENÚS DINÁMICOS POR ROL
-- =====================================================================
INSERT INTO menu_opcion (rol, titulo, ruta, icono, orden) VALUES
-- ADMINISTRADOR
('ADMINISTRADOR', 'Dashboard y Estadísticas', '/dashboard', 'LayoutDashboard', 1),
('ADMINISTRADOR', 'Gestión de Usuarios', '/usuarios', 'Users', 2),
('ADMINISTRADOR', 'Delegación de Autoridad', '/delegaciones', 'ShieldCheck', 3),
('ADMINISTRADOR', 'Catálogo y Requisitos', '/catalogos', 'BookOpen', 4),
('ADMINISTRADOR', 'Bitácora y Auditoría', '/bitacora', 'FileSearch', 5),
('ADMINISTRADOR', 'Reportes Institucionales', '/reportes', 'BarChart3', 6),

-- VICERRECTOR / SUPLENTE
('VICERRECTOR', 'Dashboard Ejecutivo', '/dashboard', 'LayoutDashboard', 1),
('VICERRECTOR', 'Bandeja de Despacho y Firma', '/despacho', 'PenTool', 2),
('VICERRECTOR', 'Monitoreo SLA (72h)', '/monitoreo-sla', 'Clock', 3),
('VICERRECTOR', 'Reportes e Indicadores', '/reportes', 'BarChart3', 4),

('SUPLENTE', 'Dashboard Interino', '/dashboard', 'LayoutDashboard', 1),
('SUPLENTE', 'Bandeja de Despacho y Firma', '/despacho', 'PenTool', 2),
('SUPLENTE', 'Monitoreo SLA (72h)', '/monitoreo-sla', 'Clock', 3),

-- RECEPCION
('RECEPCION', 'Panel de Atención', '/dashboard', 'LayoutDashboard', 1),
('RECEPCION', 'Nuevo Trámite', '/tramites/nuevo', 'FilePlus', 2),
('RECEPCION', 'Revisión y Expedientes', '/tramites', 'FolderCheck', 3),
('RECEPCION', 'Alertas SLA', '/monitoreo-sla', 'Bell', 4),
('RECEPCION', 'Emisión de Certificados QR', '/certificados', 'QrCode', 5),

-- RECTORADO
('RECTORADO', 'Bandeja Apostilla / Exterior', '/rectorado', 'Globe', 1),

-- ESTUDIANTE
('ESTUDIANTE', 'Mis Trámites', '/mis-tramites', 'FileText', 1),
('ESTUDIANTE', 'Solicitar Trámite', '/solicitar', 'PlusCircle', 2),
('ESTUDIANTE', 'Guía Procesal y Requisitos', '/guia', 'HelpCircle', 3);


-- 1. Trigger: Solo RECTORADO registra delegaciones
CREATE OR REPLACE FUNCTION fn_delegacion_validar()
RETURNS TRIGGER AS $$
DECLARE
    v_rol_reg rol_usuario;
BEGIN
    SELECT rol INTO v_rol_reg FROM usuario WHERE id = NEW.registrado_por;
    IF v_rol_reg <> 'RECTORADO' THEN
        RAISE EXCEPTION 'Solo RECTORADO puede registrar delegaciones (Resolución Rectoral/ICU).';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 2. Asegurar que RECTORADO tenga acceso al menú /delegaciones
INSERT INTO menu_opcion (rol, titulo, ruta, icono, orden, activo)
SELECT 'RECTORADO', 'Delegación de Autoridad', '/delegaciones', 'FileSignature', 2, TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM menu_opcion WHERE rol = 'RECTORADO' AND ruta = '/delegaciones'
);


-- 1. Eliminar función suelta del final que no estaba conectada al trigger
DROP FUNCTION IF EXISTS fn_delegacion_validar();

-- 2. Agregar columnas opcionales de PDF y revocación en delegacion_autoridad
ALTER TABLE delegacion_autoridad
    ADD COLUMN IF NOT EXISTS archivo_resolucion_url VARCHAR(255),
    ADD COLUMN IF NOT EXISTS revocado_por INT REFERENCES usuario(id),
    ADD COLUMN IF NOT EXISTS fecha_revocacion TIMESTAMP,
    ADD COLUMN IF NOT EXISTS motivo_revocacion VARCHAR(255);

-- 3. Actualizar la función real del trigger trg_delegacion_validar para que valide RECTORADO
CREATE OR REPLACE FUNCTION fn_trg_delegacion_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_esperado INT;
BEGIN
    NEW.justificacion_prelacion := NULLIF(trim(NEW.justificacion_prelacion), '');
    IF TG_OP = 'INSERT' THEN
        NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    END IF;
    IF fn_rol_usuario(NEW.titular_id) IS DISTINCT FROM 'VICERRECTOR' THEN
        RAISE EXCEPTION 'El titular de la delegación debe tener el rol VICERRECTOR';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM usuario WHERE id = NEW.suplente_id AND cargo = 'DECANO' AND activo) THEN
        RAISE EXCEPTION 'El suplente debe ser un usuario activo con cargo DECANO (Estatuto Orgánico)';
    END IF;
    v_esperado := fn_suplente_estatutario();
    IF NEW.suplente_id IS DISTINCT FROM v_esperado AND NEW.justificacion_prelacion IS NULL THEN
        RAISE EXCEPTION 'Por orden de prelación corresponde el usuario % (Decano de %). Si está impedido, registre la justificación de prelación',
            v_esperado, (SELECT f.nombre FROM usuario u JOIN carrera c ON c.id = u.carrera_id
                          JOIN facultad f ON f.id = c.facultad_id WHERE u.id = v_esperado);
    END IF;
    IF NEW.registrado_por IS NOT NULL AND fn_rol_usuario(NEW.registrado_por) IS DISTINCT FROM 'RECTORADO' THEN
        RAISE EXCEPTION 'Solo RECTORADO registra delegaciones (Resolución Rectoral o del ICU)';
    END IF;
    RETURN NEW;
END $$;


-- #####################################################################
--  CU5 - DESPACHO, PREVISUALIZACIÓN DE LEGAJOS Y FIRMA DIGITAL PAdES
--  La firma criptográfica PAdES la genera el microservicio /firmador (Java):
--  cada expediente tiene su propia firma CMS, su hash y su PDF firmado.
-- #####################################################################
-- ---------------------------------------------------------------------
-- 1. Firma digital: PDF firmado resultante (PAdES, guardado incremental)
-- ---------------------------------------------------------------------
ALTER TABLE firma_digital ADD COLUMN IF NOT EXISTS ruta_pdf_firmado VARCHAR(255);
ALTER TABLE firma_digital ADD COLUMN IF NOT EXISTS hash_pdf_firmado CHAR(64);

-- ---------------------------------------------------------------------
-- 2. Firma consolidada por lote con una firma CMS por expediente (RF-12, RF-13)
--    Una sola confirmación del firmante; la función es atómica: si un expediente falla
--    se revierte todo el lote (ROLLBACK de la transacción que la invoca).
--    Tras firmar: NACIONAL queda FIRMADO_DIGITALMENTE (listo para CU6);
--                 EXTERIOR se deriva a DERIVADO_RECTORADO (SF-2).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_firmar_lote(p_lote INT, p_tramites INT[], p_firmas TEXT[], p_hashes TEXT[],
                                          p_rutas TEXT[], p_hashes_firmados TEXT[])
RETURNS INT LANGUAGE plpgsql AS $$
DECLARE
    v_total INT := COALESCE(array_length(p_tramites, 1), 0);
    v_tramite tramite;
BEGIN
    IF v_total = 0 THEN
        RAISE EXCEPTION 'El lote de firma no contiene expedientes';
    END IF;
    IF v_total <> array_length(p_firmas, 1) OR v_total <> array_length(p_hashes, 1)
       OR v_total <> array_length(p_rutas, 1) OR v_total <> array_length(p_hashes_firmados, 1) THEN
        RAISE EXCEPTION 'Datos del lote incompletos: cada expediente requiere su firma, hash y PDF firmado';
    END IF;

    FOR i IN 1..v_total LOOP
        SELECT * INTO v_tramite FROM tramite WHERE id = p_tramites[i] FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'El trámite % no existe', p_tramites[i];
        END IF;

        INSERT INTO firma_digital(lote_id, tramite_id, hash_documento, firma_valor, ruta_pdf_firmado, hash_pdf_firmado)
        VALUES (p_lote, v_tramite.id, p_hashes[i], p_firmas[i], p_rutas[i], p_hashes_firmados[i]);

        UPDATE tramite SET estado = 'FIRMADO_DIGITALMENTE' WHERE id = v_tramite.id;
        IF v_tramite.destino = 'EXTERIOR' THEN
            UPDATE tramite SET estado = 'DERIVADO_RECTORADO' WHERE id = v_tramite.id;
        END IF;
    END LOOP;
    RETURN v_total;
END $$;

-- ---------------------------------------------------------------------
-- 3. SF-2: Visado final de Rectorado (sello institucional previo a Apostilla)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS visado_rectorado (
    id                SERIAL PRIMARY KEY,
    tramite_id        INT          NOT NULL UNIQUE REFERENCES tramite(id),
    usuario_id        INT          NOT NULL REFERENCES usuario(id),
    ruta_pdf_sellado  VARCHAR(255) NOT NULL,
    hash_sha256       CHAR(64)     NOT NULL,
    observacion       VARCHAR(300),
    fecha             TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP
);

CREATE OR REPLACE FUNCTION fn_trg_visado_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF fn_rol_usuario(NEW.usuario_id) IS DISTINCT FROM 'RECTORADO' THEN
        RAISE EXCEPTION 'Solo RECTORADO confirma el sello institucional final';
    END IF;
    IF (SELECT estado FROM tramite WHERE id = NEW.tramite_id) IS DISTINCT FROM 'DERIVADO_RECTORADO' THEN
        RAISE EXCEPTION 'Solo se visan trámites derivados a Rectorado';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM firma_digital WHERE tramite_id = NEW.tramite_id AND valida) THEN
        RAISE EXCEPTION 'El expediente no tiene la firma digital del Vicerrectorado';
    END IF;
    RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS trg_visado_validar ON visado_rectorado;
CREATE TRIGGER trg_visado_validar BEFORE INSERT ON visado_rectorado
    FOR EACH ROW EXECUTE FUNCTION fn_trg_visado_validar();

-- El visado es inalterable (igual que la firma): no se modifica ni se borra
CREATE OR REPLACE FUNCTION fn_trg_visado_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El visado de Rectorado es inalterable';
END $$;
DROP TRIGGER IF EXISTS trg_visado_inmutable ON visado_rectorado;
CREATE TRIGGER trg_visado_inmutable BEFORE UPDATE OR DELETE ON visado_rectorado
    FOR EACH ROW EXECUTE FUNCTION fn_trg_visado_inmutable();

-- Bitácora inmutable (CU4)
DROP TRIGGER IF EXISTS trg_aud_visado ON visado_rectorado;
CREATE TRIGGER trg_aud_visado AFTER INSERT ON visado_rectorado FOR EACH ROW EXECUTE FUNCTION fn_auditar();
DROP TRIGGER IF EXISTS trg_aud_lote_firma ON lote_firma;
CREATE TRIGGER trg_aud_lote_firma AFTER INSERT ON lote_firma FOR EACH ROW EXECUTE FUNCTION fn_auditar();

-- Un trámite al EXTERIOR no concluye sin el visado de Rectorado
CREATE OR REPLACE FUNCTION fn_trg_tramite_requiere_visado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.estado = 'DERIVADO_RECTORADO' AND NEW.estado = 'CONCLUIDO'
       AND NOT EXISTS (SELECT 1 FROM visado_rectorado WHERE tramite_id = NEW.id) THEN
        RAISE EXCEPTION 'El trámite % requiere el visado final de Rectorado antes de concluir', NEW.codigo;
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_tramite_requiere_visado ON tramite;
CREATE TRIGGER trg_tramite_requiere_visado BEFORE UPDATE OF estado ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_requiere_visado();

-- ---------------------------------------------------------------------
-- 4. Bandeja de despacho (RF-11): se agrega el estado de la versión vigente del legajo
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_bandeja_despacho AS
SELECT t.id AS tramite_id, t.codigo, t.tipo, t.destino, t.pais_destino, t.institucion_destino,
       u.nombres || ' ' || u.apellidos AS solicitante, u.registro, c.nombre AS carrera,
       t.prioridad, t.total_fojas, ex.id AS expediente_id, ex.ruta_pdf,
       s.horas_consumidas, s.semaforo, t.fecha_ingreso,
       ex.version AS expediente_version, ex.hash_sha256 AS expediente_hash, s.plazo_horas
  FROM tramite t
  JOIN usuario u        ON u.id = t.solicitante_id
  JOIN carrera c        ON c.id = t.carrera_id
  LEFT JOIN expediente ex ON ex.tramite_id = t.id AND ex.vigente
  JOIN v_semaforo_sla s ON s.tramite_id = t.id
 WHERE t.estado = 'EN_BANDEJA_DESPACHO'
 ORDER BY t.prioridad DESC,
          CASE s.semaforo WHEN 'ROJO' THEN 1 WHEN 'AMARILLO' THEN 2 ELSE 3 END, t.tipo, t.destino, t.fecha_ingreso;


-- #####################################################################
--  CU6 - CERTIFICADO OFICIAL CON QR Y PORTAL DE SEGUIMIENTO
--  Garantías adicionales sobre certificado_oficial (definido arriba): un único certificado VIGENTE
--  por trámite, reserva del correlativo y validación del estado antes de emitir.
-- #####################################################################
-- 1. Un único certificado VIGENTE por trámite (una reemisión exige anular antes el anterior)
CREATE UNIQUE INDEX IF NOT EXISTS ux_certificado_vigente_por_tramite
    ON certificado_oficial (tramite_id) WHERE estado = 'VIGENTE';

-- 2. Reserva del correlativo antes de generar el PDF/A (el número se imprime en el documento).
--    Mismo formato que fn_trg_certificado, que respeta el número recibido.
CREATE OR REPLACE FUNCTION fn_reservar_numero_certificado(p_tramite INT) RETURNS VARCHAR LANGUAGE plpgsql AS $$
DECLARE v_tipo tipo_tramite;
BEGIN
    SELECT tipo INTO v_tipo FROM tramite WHERE id = p_tramite;
    IF v_tipo IS NULL THEN
        RAISE EXCEPTION 'El trámite % no existe', p_tramite;
    END IF;
    RETURN CASE v_tipo WHEN 'LEGALIZACION' THEN 'CL-' ELSE 'CD-' END ||
           to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_certificado')::TEXT, 6, '0');
END $$;

-- 3. RF-14: solo se emite para expedientes listos:
--    NACIONAL firmado digitalmente, EXTERIOR con visado de Rectorado, o reemisión de un trámite CONCLUIDO
--    cuyo certificado anterior fue ANULADO. La constancia de descuento exige CON_FINANCIAMIENTO.
CREATE OR REPLACE FUNCTION fn_trg_certificado_emision() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE t tramite; v_fin estado_financiamiento;
BEGIN
    SELECT * INTO t FROM tramite WHERE id = NEW.tramite_id;
    IF NOT (
        (t.estado = 'FIRMADO_DIGITALMENTE' AND t.destino = 'NACIONAL')
        OR (t.estado = 'DERIVADO_RECTORADO' AND EXISTS (SELECT 1 FROM visado_rectorado v WHERE v.tramite_id = t.id))
        OR t.estado = 'CONCLUIDO'
    ) THEN
        RAISE EXCEPTION 'El trámite % no está listo para emitir el certificado (estado %)', t.codigo, t.estado;
    END IF;
    IF t.tipo = 'DESCUENTO_DIPLOMADO' THEN
        SELECT financiamiento INTO v_fin FROM solicitud_descuento WHERE tramite_id = t.id;
        IF v_fin IS DISTINCT FROM 'CON_FINANCIAMIENTO' THEN
            RAISE EXCEPTION 'El trámite % está catalogado como %: no corresponde constancia de descuento', t.codigo, v_fin;
        END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM expediente e WHERE e.tramite_id = t.id AND e.vigente) THEN
        RAISE EXCEPTION 'El trámite % no tiene un expediente digital vigente', t.codigo;
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_certificado_emision ON certificado_oficial;
CREATE TRIGGER trg_certificado_emision BEFORE INSERT ON certificado_oficial
    FOR EACH ROW EXECUTE FUNCTION fn_trg_certificado_emision();

-- 4. SF-3: las autoridades acceden a los certificados emitidos para anularlos
INSERT INTO menu_opcion (rol, titulo, ruta, icono, orden, activo)
SELECT r.rol::rol_usuario, 'Certificados Emitidos', '/certificados', 'QrCode', 5, TRUE
  FROM (VALUES ('VICERRECTOR'), ('SUPLENTE'), ('RECTORADO')) AS r(rol)
 WHERE NOT EXISTS (SELECT 1 FROM menu_opcion m WHERE m.rol = r.rol::rol_usuario AND m.ruta = '/certificados');


-- #####################################################################
--  CU7 - MONITOREO SLA: ATENCIÓN DE ALERTAS Y PARAMETRIZACIÓN
--  Trazabilidad de la atención de alertas (SF-2) y de la parametrización de umbrales (SF-3).
-- #####################################################################
-- 1. SF-2: quién y cuándo atendió cada alerta
ALTER TABLE alerta_sla ADD COLUMN IF NOT EXISTS atendida_por   INT REFERENCES usuario(id);
ALTER TABLE alerta_sla ADD COLUMN IF NOT EXISTS fecha_atencion TIMESTAMP;

-- Una alerta solo cambia para marcarse atendida (una vez); sus datos de origen no se alteran
CREATE OR REPLACE FUNCTION fn_trg_alerta_atender() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.tramite_id, NEW.tipo, NEW.horas_transcurridas, NEW.plazo_horas, NEW.fecha)
       IS DISTINCT FROM (OLD.tramite_id, OLD.tipo, OLD.horas_transcurridas, OLD.plazo_horas, OLD.fecha) THEN
        RAISE EXCEPTION 'Los datos de una alerta SLA no se modifican: solo puede marcarse como atendida';
    END IF;
    IF OLD.atendida THEN
        RAISE EXCEPTION 'La alerta % ya fue atendida', OLD.id;
    END IF;
    IF NEW.atendida THEN
        NEW.fecha_atencion := LOCALTIMESTAMP;
        NEW.atendida_por   := COALESCE(NEW.atendida_por, fn_usuario_actual());
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_alerta_atender ON alerta_sla;
CREATE TRIGGER trg_alerta_atender BEFORE UPDATE ON alerta_sla FOR EACH ROW EXECUTE FUNCTION fn_trg_alerta_atender();

-- 2. SF-3: el plazo debe ser positivo (el porcentaje ya tiene CHECK 1..99)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sla_config_plazo') THEN
        ALTER TABLE sla_config ADD CONSTRAINT ck_sla_config_plazo CHECK (plazo_horas > 0);
    END IF;
END $$;

-- La configuración general (tipo NULL) es obligatoria: v_semaforo_sla la usa por defecto
CREATE OR REPLACE FUNCTION fn_trg_sla_config_general() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.tipo IS NULL THEN
        RAISE EXCEPTION 'La configuración SLA general no puede eliminarse';
    END IF;
    RETURN OLD;
END $$;
DROP TRIGGER IF EXISTS trg_sla_config_general ON sla_config;
CREATE TRIGGER trg_sla_config_general BEFORE DELETE ON sla_config FOR EACH ROW EXECUTE FUNCTION fn_trg_sla_config_general();

-- Un solo registro general (UNIQUE no impide varios NULL)
CREATE UNIQUE INDEX IF NOT EXISTS ux_sla_config_general ON sla_config ((tipo IS NULL)) WHERE tipo IS NULL;

-- 3. Bitácora inmutable (CU4): alertas y cambios de parametrización
DROP TRIGGER IF EXISTS trg_aud_alerta_sla ON alerta_sla;
CREATE TRIGGER trg_aud_alerta_sla AFTER INSERT OR UPDATE ON alerta_sla FOR EACH ROW EXECUTE FUNCTION fn_auditar();
DROP TRIGGER IF EXISTS trg_aud_sla_config ON sla_config;
CREATE TRIGGER trg_aud_sla_config AFTER INSERT OR UPDATE OR DELETE ON sla_config FOR EACH ROW EXECUTE FUNCTION fn_auditar();

-- 4. Menú: Rectorado y Administrador también acceden al monitor SLA
INSERT INTO menu_opcion (rol, titulo, ruta, icono, orden, activo)
SELECT r.rol::rol_usuario, 'Monitoreo SLA (72h)', '/monitoreo-sla', 'Clock', r.orden, TRUE
  FROM (VALUES ('RECTORADO', 3), ('ADMINISTRADOR', 7)) AS r(rol, orden)
 WHERE NOT EXISTS (SELECT 1 FROM menu_opcion m WHERE m.rol = r.rol::rol_usuario AND m.ruta = '/monitoreo-sla');
