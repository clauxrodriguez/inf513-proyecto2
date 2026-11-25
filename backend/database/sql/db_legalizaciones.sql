-- =====================================================================
--  PLATAFORMA WEB DE GESTIÓN DOCUMENTAL, LEGALIZACIONES Y FIRMA DIGITAL
--  Universidad Autónoma Gabriel René Moreno (UAGRM) - Stack: Laravel + React + PostgreSQL
--  Flujo Integral: Jefaturas de Carrera / Facultades -> Vicerrectorado -> Rectorado
-- =====================================================================

DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public;
SET search_path TO public;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- =====================================================================
-- 1. ESTRUCTURA ACADÉMICA, USUARIOS, RBAC Y MENÚ DINÁMICO
-- =====================================================================
CREATE TABLE facultad (
    id               SERIAL PRIMARY KEY,
    codigo           VARCHAR(20)  NOT NULL UNIQUE,
    nombre           VARCHAR(150) NOT NULL,
    anio_creacion    INT,
    orden_prelacion  INT          NOT NULL UNIQUE
);

CREATE TABLE carrera (
    id           SERIAL PRIMARY KEY,
    facultad_id  INT          NOT NULL REFERENCES facultad(id),
    codigo       VARCHAR(15)  NOT NULL UNIQUE,
    nombre       VARCHAR(150) NOT NULL,
    regimen      VARCHAR(10)  NOT NULL DEFAULT 'SEMESTRAL' CHECK (regimen IN ('SEMESTRAL','ANUAL'))
);

CREATE TABLE rol (
    id           SERIAL PRIMARY KEY,
    codigo       VARCHAR(30)  NOT NULL UNIQUE,
    nombre       VARCHAR(80)  NOT NULL,
    descripcion  VARCHAR(255),
    activo       BOOLEAN      NOT NULL DEFAULT TRUE
);

CREATE TABLE permiso (
    id           SERIAL PRIMARY KEY,
    codigo       VARCHAR(50)  NOT NULL UNIQUE,
    nombre       VARCHAR(100) NOT NULL,
    descripcion  VARCHAR(255)
);

CREATE TABLE rol_permiso (
    rol_id      INT     NOT NULL REFERENCES rol(id) ON DELETE CASCADE,
    permiso_id  INT     NOT NULL REFERENCES permiso(id) ON DELETE CASCADE,
    activo      BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (rol_id, permiso_id)
);

CREATE TABLE menu_opcion (
    id          SERIAL PRIMARY KEY,
    padre_id    INT          REFERENCES menu_opcion(id) ON DELETE CASCADE,
    permiso_id  INT          REFERENCES permiso(id) ON DELETE SET NULL,
    titulo      VARCHAR(100) NOT NULL,
    ruta        VARCHAR(150),
    icono       VARCHAR(50),
    orden       INT          NOT NULL DEFAULT 1,
    activo      BOOLEAN      NOT NULL DEFAULT TRUE
);

CREATE TABLE usuario (
    id              SERIAL PRIMARY KEY,
    nombres         VARCHAR(100) NOT NULL,
    apellidos       VARCHAR(100) NOT NULL,
    ci              VARCHAR(20)  NOT NULL UNIQUE,
    registro        VARCHAR(20)  UNIQUE,
    email           VARCHAR(150) NOT NULL UNIQUE,
    telefono        VARCHAR(30),
    password_hash   VARCHAR(100) NOT NULL,
    cargo           VARCHAR(120),
    activo          BOOLEAN      NOT NULL DEFAULT TRUE,
    fecha_creacion  TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP
);

CREATE TABLE usuario_rol (
    usuario_id  INT NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    rol_id      INT NOT NULL REFERENCES rol(id) ON DELETE CASCADE,
    PRIMARY KEY (usuario_id, rol_id)
);

CREATE TABLE usuario_carrera (
    usuario_id     INT  NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    carrera_id     INT  NOT NULL REFERENCES carrera(id) ON DELETE CASCADE,
    fecha_ingreso  DATE NOT NULL DEFAULT CURRENT_DATE,
    PRIMARY KEY (usuario_id, carrera_id)
);

-- =====================================================================
-- 2. DELEGACIÓN DE AUTORIDAD E INDICADORES DE GESTIÓN
-- =====================================================================
CREATE TABLE delegacion_autoridad (
    id                SERIAL PRIMARY KEY,
    titular_id        INT          NOT NULL REFERENCES usuario(id),
    suplente_id       INT          NOT NULL REFERENCES usuario(id),
    nro_resolucion    VARCHAR(50)  NOT NULL,
    fecha_resolucion  DATE         NOT NULL,
    motivo            VARCHAR(20)  NOT NULL CHECK (motivo IN ('VIAJE','COMISION_OFICIAL','AUSENCIA','VACACION','OTRO')),
    detalle_motivo    VARCHAR(255),
    fecha_inicio      DATE         NOT NULL,
    fecha_fin         DATE         NOT NULL,
    estado            VARCHAR(12)  NOT NULL DEFAULT 'ACTIVA' CHECK (estado IN ('ACTIVA','FINALIZADA','REVOCADA')),
    registrado_por    INT          REFERENCES usuario(id),
    fecha_registro    TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    CHECK (fecha_fin >= fecha_inicio),
    CHECK (titular_id <> suplente_id)
);

CREATE TABLE indicador_gestion (
    id             SERIAL PRIMARY KEY,
    codigo         VARCHAR(40)  NOT NULL UNIQUE,
    nombre         VARCHAR(150) NOT NULL,
    unidad_medida  VARCHAR(20)  NOT NULL,
    meta           NUMERIC(12,2)
);

CREATE TABLE indicador_valor (
    id              SERIAL PRIMARY KEY,
    indicador_id    INT  NOT NULL REFERENCES indicador_gestion(id),
    periodo_inicio  DATE NOT NULL,
    periodo_fin     DATE NOT NULL,
    valor           NUMERIC(14,2),
    fecha_calculo   TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP,
    UNIQUE (indicador_id, periodo_inicio, periodo_fin)
);

-- =====================================================================
-- 3. CATÁLOGOS, ORDEN DE FIRMAS DESDE FACULTAD, TRÁMITES Y RECIBOS DE CAJA
-- =====================================================================
CREATE TABLE unidad_origen (
    id      SERIAL PRIMARY KEY,
    codigo  VARCHAR(30)  NOT NULL UNIQUE,
    nombre  VARCHAR(150) NOT NULL
);

CREATE TABLE tipo_tramite (
    id           SERIAL PRIMARY KEY,
    codigo       VARCHAR(30)  NOT NULL UNIQUE,
    nombre       VARCHAR(120) NOT NULL,
    descripcion  VARCHAR(255),
    prefijo      VARCHAR(5)   NOT NULL
);

CREATE TABLE catalogo_documento (
    id                 SERIAL PRIMARY KEY,
    codigo             VARCHAR(30)   NOT NULL UNIQUE,
    nombre             VARCHAR(200)  NOT NULL,
    unidad_emisora_id  INT           NOT NULL REFERENCES unidad_origen(id),
    tipo_cobro         VARCHAR(10)   NOT NULL CHECK (tipo_cobro IN ('FOJA','MATERIA','DOCUMENTO')),
    tarifa             NUMERIC(10,2) NOT NULL CHECK (tarifa >= 0),
    activo             BOOLEAN       NOT NULL DEFAULT TRUE
);

CREATE TABLE catalogo_firma_orden (
    id                     SERIAL PRIMARY KEY,
    catalogo_documento_id  INT          NOT NULL REFERENCES catalogo_documento(id) ON DELETE CASCADE,
    unidad_origen_id       INT          NOT NULL REFERENCES unidad_origen(id),
    orden                  INT          NOT NULL,
    cargo_firmante         VARCHAR(100) NOT NULL,
    requiere_sello         BOOLEAN      NOT NULL DEFAULT TRUE,
    UNIQUE (catalogo_documento_id, orden)
);

CREATE SEQUENCE seq_tramite;

CREATE TABLE tramite (
    id                   SERIAL PRIMARY KEY,
    codigo               VARCHAR(25)   UNIQUE,
    tipo_tramite_id      INT           NOT NULL REFERENCES tipo_tramite(id),
    solicitante_id       INT           NOT NULL REFERENCES usuario(id),
    carrera_id           INT           NOT NULL REFERENCES carrera(id),
    destino              VARCHAR(10)   CHECK (destino IN ('NACIONAL','EXTERIOR')),
    institucion_destino  VARCHAR(200),
    finalidad            VARCHAR(255),
    prioridad            VARCHAR(10)   NOT NULL DEFAULT 'NORMAL' CHECK (prioridad IN ('NORMAL','ALTA','URGENTE')),
    estado               VARCHAR(25)   NOT NULL DEFAULT 'SOLICITADO_ORIGEN'
                         CHECK (estado IN ('SOLICITADO_ORIGEN','EN_FIRMA_ORIGEN','INGRESADO','EN_REVISION',
                                           'OBSERVADO','EN_BANDEJA_DESPACHO','FIRMADO_DIGITALMENTE',
                                           'DERIVADO_RECTORADO','CONCLUIDO','RECHAZADO')),
    total_fojas          INT           NOT NULL DEFAULT 0,
    importe_esperado     NUMERIC(10,2) NOT NULL DEFAULT 0,
    importe_pagado       NUMERIC(10,2) NOT NULL DEFAULT 0,
    registrado_por       INT           REFERENCES usuario(id),
    fecha_ingreso        TIMESTAMP     NOT NULL DEFAULT LOCALTIMESTAMP,
    fecha_conclusion     TIMESTAMP
);

CREATE TABLE tramite_documento (
    id                     SERIAL PRIMARY KEY,
    tramite_id             INT         NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    catalogo_documento_id  INT         NOT NULL REFERENCES catalogo_documento(id),
    cantidad_paginas       INT         NOT NULL CHECK (cantidad_paginas > 0),
    cantidad_materias      INT         CHECK (cantidad_materias >= 0),
    emitido_por_uagrm      BOOLEAN     NOT NULL DEFAULT TRUE,
    archivo_ruta           VARCHAR(255),
    folio_inicio           INT,
    folio_fin              INT,
    firmas_origen_ok       BOOLEAN     NOT NULL DEFAULT FALSE,
    estado                 VARCHAR(15) NOT NULL DEFAULT 'PENDIENTE'
                           CHECK (estado IN ('PENDIENTE','EN_FIRMA','VALIDO','OBSERVADO','RECHAZADO'))
);

-- Validación de recibos y registro de importes emitidos por Caja/Tesorería
CREATE TABLE comprobante_caja (
    id              SERIAL PRIMARY KEY,
    tramite_id      INT           NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    codigo_recibo   VARCHAR(30)   NOT NULL UNIQUE,
    concepto        VARCHAR(20)   NOT NULL CHECK (concepto IN ('EMISION_ORIGEN','LEGALIZACION','AVANCE_ACADEMICO')),
    importe         NUMERIC(10,2) NOT NULL CHECK (importe > 0),
    fecha_pago      DATE          NOT NULL,
    registrado_por  INT           REFERENCES usuario(id)
);

CREATE TABLE requisito (
    id               SERIAL PRIMARY KEY,
    tipo_tramite_id  INT          NOT NULL REFERENCES tipo_tramite(id),
    codigo           VARCHAR(30)  NOT NULL,
    descripcion      VARCHAR(255) NOT NULL,
    obligatorio      BOOLEAN      NOT NULL DEFAULT TRUE,
    UNIQUE (tipo_tramite_id, codigo)
);

CREATE TABLE tramite_requisito (
    tramite_id          INT       NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    requisito_id        INT       NOT NULL REFERENCES requisito(id),
    cumplido            BOOLEAN   NOT NULL DEFAULT FALSE,
    verificado_por      INT       REFERENCES usuario(id),
    fecha_verificacion  TIMESTAMP,
    PRIMARY KEY (tramite_id, requisito_id)
);

CREATE TABLE expediente (
    id                 SERIAL PRIMARY KEY,
    tramite_id         INT          NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    version            INT          NOT NULL DEFAULT 1,
    ruta_pdf           VARCHAR(255) NOT NULL,
    total_fojas        INT          NOT NULL CHECK (total_fojas > 0),
    hash_sha256        CHAR(64)     NOT NULL,
    compilado_por      INT          REFERENCES usuario(id),
    fecha_compilacion  TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    vigente            BOOLEAN      NOT NULL DEFAULT TRUE,
    UNIQUE (tramite_id, version)
);

CREATE TABLE solicitud_descuento (
    id                    SERIAL PRIMARY KEY,
    tramite_id            INT          NOT NULL UNIQUE REFERENCES tramite(id) ON DELETE CASCADE,
    unidad_postgrado      VARCHAR(150) NOT NULL,
    nombre_diplomado      VARCHAR(200) NOT NULL,
    nro_avance_academico  VARCHAR(20)  NOT NULL,
    fecha_ultima_nota     DATE         NOT NULL,
    fecha_solicitud       DATE         NOT NULL DEFAULT CURRENT_DATE,
    fecha_limite          DATE,
    dentro_plazo          BOOLEAN,
    financiamiento        VARCHAR(20)
);

-- =====================================================================
-- 4. FLUJO PROCESAL, OBSERVACIONES Y BITÁCORAS (NEGOCIO Y ACCESO WEB)
-- =====================================================================
CREATE TABLE flujo_paso (
    id               SERIAL PRIMARY KEY,
    tipo_tramite_id  INT          NOT NULL REFERENCES tipo_tramite(id),
    destino          VARCHAR(10)  CHECK (destino IN ('NACIONAL','EXTERIOR')),
    orden            INT          NOT NULL,
    unidad_id        INT          NOT NULL REFERENCES unidad_origen(id),
    nombre           VARCHAR(150) NOT NULL,
    descripcion      TEXT,
    firma_requerida  VARCHAR(150),
    sello_requerido  VARCHAR(150),
    estado_asociado  VARCHAR(25),
    UNIQUE (tipo_tramite_id, orden)
);

CREATE TABLE observacion (
    id                    SERIAL PRIMARY KEY,
    tramite_id            INT          NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    tramite_documento_id  INT          REFERENCES tramite_documento(id) ON DELETE SET NULL,
    tipo                  VARCHAR(25)  NOT NULL CHECK (tipo IN ('FOJA_ILEGIBLE','SELLO_FALTANTE','FIRMA_FALTANTE',
                                       'PAGO_INSUFICIENTE','FOLIACION_INCORRECTA','DOCUMENTO_EXTERNO','FUERA_DE_PLAZO','OTRO')),
    descripcion           VARCHAR(500) NOT NULL,
    estado                VARCHAR(12)  NOT NULL DEFAULT 'PENDIENTE' CHECK (estado IN ('PENDIENTE','SUBSANADA','ANULADA')),
    registrado_por        INT          REFERENCES usuario(id),
    fecha_registro        TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    fecha_subsanacion     TIMESTAMP
);

CREATE TABLE bitacora (
    id             BIGSERIAL PRIMARY KEY,
    tramite_id     INT          REFERENCES tramite(id) ON DELETE SET NULL,
    usuario_id     INT          REFERENCES usuario(id) ON DELETE SET NULL,
    accion         VARCHAR(60)  NOT NULL,
    entidad        VARCHAR(60),
    entidad_id     VARCHAR(40),
    detalle        JSONB,
    fecha          TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    hash_anterior  CHAR(64)     NOT NULL,
    hash_actual    CHAR(64)     NOT NULL
);

CREATE TABLE bitacora_acceso (
    id             BIGSERIAL PRIMARY KEY,
    usuario_id     INT          REFERENCES usuario(id) ON DELETE SET NULL,
    email_intento  VARCHAR(150),
    accion         VARCHAR(30)  NOT NULL CHECK (accion IN ('LOGIN_OK','LOGIN_FALLIDO','LOGOUT','ACCESO_RECURSO')),
    ruta           VARCHAR(255) NOT NULL,
    metodo_http    VARCHAR(10)  NOT NULL DEFAULT 'GET',
    ip_origen      VARCHAR(45),
    user_agent     TEXT,
    fecha          TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP
);

CREATE TABLE contador_visita (
    id              SERIAL PRIMARY KEY,
    menu_opcion_id  INT       NOT NULL UNIQUE REFERENCES menu_opcion(id) ON DELETE CASCADE,
    visitas         BIGINT    NOT NULL DEFAULT 0,
    ultima_visita   TIMESTAMP NOT NULL DEFAULT LOCALTIMESTAMP
);

-- =====================================================================
-- 5. FIRMA DIGITAL EN CADENA (ORIGEN FACULTATIVO Y DESPACHO CENTRAL)
-- =====================================================================
CREATE TABLE certificado_digital (
    id                SERIAL PRIMARY KEY,
    usuario_id        INT          NOT NULL REFERENCES usuario(id),
    numero_serie      VARCHAR(80)  NOT NULL UNIQUE,
    emisor            VARCHAR(150) NOT NULL,
    fecha_emision     DATE         NOT NULL,
    fecha_expiracion  DATE         NOT NULL,
    activo            BOOLEAN      NOT NULL DEFAULT TRUE,
    CHECK (fecha_expiracion > fecha_emision)
);

CREATE TABLE lote_firma (
    id              SERIAL PRIMARY KEY,
    firmante_id     INT          NOT NULL REFERENCES usuario(id),
    certificado_id  INT          NOT NULL REFERENCES certificado_digital(id),
    delegacion_id   INT          REFERENCES delegacion_autoridad(id),
    es_remota       BOOLEAN      NOT NULL DEFAULT FALSE,
    ubicacion       VARCHAR(150),
    ip_origen       VARCHAR(45),
    fecha           TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP
);

CREATE TABLE firma_digital (
    id                    SERIAL PRIMARY KEY,
    lote_id               INT         NOT NULL REFERENCES lote_firma(id),
    tramite_id            INT         NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    tramite_documento_id  INT         REFERENCES tramite_documento(id) ON DELETE CASCADE,
    expediente_id         INT         REFERENCES expediente(id) ON DELETE CASCADE,
    orden_firma           INT         NOT NULL DEFAULT 1,
    etapa                 VARCHAR(15) NOT NULL DEFAULT 'DESPACHO' CHECK (etapa IN ('ORIGEN','DESPACHO','RECTORADO')),
    hash_documento        CHAR(64)    NOT NULL,
    firma_valor           TEXT        NOT NULL,
    fecha_firma           TIMESTAMP   NOT NULL DEFAULT LOCALTIMESTAMP,
    valida                BOOLEAN     NOT NULL DEFAULT TRUE
);

-- =====================================================================
-- 6. CERTIFICADO OFICIAL CON QR
-- =====================================================================
CREATE SEQUENCE seq_certificado;

CREATE TABLE certificado_oficial (
    id                   SERIAL PRIMARY KEY,
    tramite_id           INT          NOT NULL REFERENCES tramite(id),
    firma_id             INT          REFERENCES firma_digital(id),
    numero               VARCHAR(30)  UNIQUE,
    tipo                 VARCHAR(25)  NOT NULL CHECK (tipo IN ('LEGALIZACION','CONSTANCIA_DIPLOMADO')),
    ruta_pdfa            VARCHAR(255) NOT NULL,
    hash_sha256          CHAR(64)     NOT NULL,
    codigo_verificacion  UUID         NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    qr_contenido         TEXT,
    fecha_emision        TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    estado               VARCHAR(10)  NOT NULL DEFAULT 'VIGENTE' CHECK (estado IN ('VIGENTE','ANULADO'))
);

-- =====================================================================
-- 7. SLA (72 HORAS HÁBILES) Y NOTIFICACIONES WEB
-- =====================================================================
CREATE TABLE sla_config (
    id                 SERIAL PRIMARY KEY,
    tipo_tramite_id    INT UNIQUE REFERENCES tipo_tramite(id),
    plazo_horas        NUMERIC(6,2) NOT NULL DEFAULT 72,
    porcentaje_alerta  NUMERIC(5,2) NOT NULL DEFAULT 75 CHECK (porcentaje_alerta BETWEEN 1 AND 99)
);

CREATE TABLE feriado (
    fecha           DATE         PRIMARY KEY,
    sla_config_id   INT          NOT NULL DEFAULT 1 REFERENCES sla_config(id) ON DELETE CASCADE,
    descripcion     VARCHAR(120) NOT NULL,
    registrado_por  INT          REFERENCES usuario(id) ON DELETE SET NULL
);

CREATE TABLE tramite_tiempo_fase (
    id              SERIAL PRIMARY KEY,
    tramite_id      INT         NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    estado          VARCHAR(25) NOT NULL,
    fecha_inicio    TIMESTAMP   NOT NULL,
    fecha_fin       TIMESTAMP,
    horas_habiles   NUMERIC(8,2),
    responsable_id  INT         REFERENCES usuario(id)
);

CREATE TABLE alerta_sla (
    id                   SERIAL PRIMARY KEY,
    tramite_id           INT          NOT NULL REFERENCES tramite(id) ON DELETE CASCADE,
    tipo                 VARCHAR(12)  NOT NULL CHECK (tipo IN ('PREVENTIVA','VENCIDA')),
    horas_transcurridas  NUMERIC(8,2) NOT NULL,
    plazo_horas          NUMERIC(6,2) NOT NULL,
    fecha_generacion     TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP,
    atendida             BOOLEAN      NOT NULL DEFAULT FALSE,
    atendida_por         INT          REFERENCES usuario(id),
    fecha_atencion       TIMESTAMP,
    UNIQUE (tramite_id, tipo)
);

CREATE TABLE notificacion (
    id              SERIAL PRIMARY KEY,
    usuario_id      INT          NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    tramite_id      INT          REFERENCES tramite(id) ON DELETE CASCADE,
    tipo            VARCHAR(30)  NOT NULL,
    mensaje         VARCHAR(500) NOT NULL,
    leida           BOOLEAN      NOT NULL DEFAULT FALSE,
    fecha_creacion  TIMESTAMP    NOT NULL DEFAULT LOCALTIMESTAMP
);

-- =====================================================================
-- ÍNDICES
-- =====================================================================
CREATE INDEX ix_tramite_estado       ON tramite(estado);
CREATE INDEX ix_tramite_solicitante  ON tramite(solicitante_id);
CREATE INDEX ix_tramite_carrera      ON tramite(carrera_id);
CREATE INDEX ix_tdoc_tramite         ON tramite_documento(tramite_id);
CREATE INDEX ix_comp_tramite         ON comprobante_caja(tramite_id);
CREATE INDEX ix_obs_tramite          ON observacion(tramite_id, estado);
CREATE INDEX ix_bitacora_tramite     ON bitacora(tramite_id);
CREATE INDEX ix_acceso_usuario_fecha ON bitacora_acceso(usuario_id, fecha);
CREATE INDEX ix_fase_tramite         ON tramite_tiempo_fase(tramite_id);
CREATE INDEX ix_firma_tramite        ON firma_digital(tramite_id);
CREATE INDEX ix_notif_usuario        ON notificacion(usuario_id, leida);

-- =====================================================================
-- FUNCIONES Y TRIGGERS
-- =====================================================================
CREATE OR REPLACE FUNCTION fn_usuario_actual() RETURNS INT LANGUAGE sql STABLE AS $$
    SELECT NULLIF(current_setting('app.usuario_id', true), '')::INT;
$$;

-- Bitácora inalterable SHA-256
CREATE OR REPLACE FUNCTION fn_registrar_bitacora(p_tramite INT, p_accion TEXT, p_entidad TEXT,
                                                 p_entidad_id TEXT, p_detalle JSONB)
RETURNS VOID LANGUAGE sql AS $$
    INSERT INTO bitacora(tramite_id, usuario_id, accion, entidad, entidad_id, detalle, hash_anterior, hash_actual)
    VALUES (p_tramite, fn_usuario_actual(), p_accion, p_entidad, p_entidad_id, p_detalle, '', '');
$$;

CREATE OR REPLACE FUNCTION fn_bitacora_hash() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_prev CHAR(64);
BEGIN
    PERFORM pg_advisory_xact_lock(20260001);
    SELECT hash_actual INTO v_prev FROM bitacora ORDER BY id DESC LIMIT 1;
    NEW.fecha         := clock_timestamp()::TIMESTAMP;
    NEW.hash_anterior := COALESCE(v_prev, repeat('0', 64));
    NEW.hash_actual   := encode(digest(NEW.hash_anterior || '|' || NEW.id || '|' ||
                         COALESCE(NEW.tramite_id::TEXT, '') || '|' || COALESCE(NEW.usuario_id::TEXT, '') || '|' ||
                         NEW.accion || '|' || COALESCE(NEW.detalle::TEXT, '') || '|' || NEW.fecha::TEXT, 'sha256'), 'hex');
    RETURN NEW;
END $$;
CREATE TRIGGER trg_bitacora_hash BEFORE INSERT ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_hash();

CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'La bitácora es inalterable: no se permite %', TG_OP;
END $$;
CREATE TRIGGER trg_bitacora_no_modificar BEFORE UPDATE OR DELETE ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
CREATE TRIGGER trg_bitacora_no_truncar   BEFORE TRUNCATE ON bitacora FOR EACH STATEMENT EXECUTE FUNCTION fn_bitacora_inmutable();

-- Horas hábiles SLA
CREATE OR REPLACE FUNCTION fn_horas_habiles(p_ini TIMESTAMP, p_fin TIMESTAMP)
RETURNS NUMERIC LANGUAGE sql STABLE AS $$
    SELECT COALESCE(ROUND(SUM(EXTRACT(EPOCH FROM (LEAST(p_fin, d.dia + INTERVAL '1 day')
                                                  - GREATEST(p_ini, d.dia)))::NUMERIC) / 3600.0, 2), 0)
      FROM generate_series(date_trunc('day', p_ini), date_trunc('day', p_fin), INTERVAL '1 day') AS d(dia)
     WHERE p_fin > p_ini
       AND EXTRACT(ISODOW FROM d.dia) < 6
       AND NOT EXISTS (SELECT 1 FROM feriado f WHERE f.fecha = d.dia::DATE);
$$;

-- Autoridad vigente (Titular o Suplente)
CREATE OR REPLACE FUNCTION fn_autoridad_vigente(p_fecha DATE DEFAULT CURRENT_DATE)
RETURNS INT LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT suplente_id FROM delegacion_autoridad
          WHERE estado = 'ACTIVA' AND p_fecha BETWEEN fecha_inicio AND fecha_fin
          ORDER BY fecha_registro DESC LIMIT 1),
        (SELECT u.id FROM usuario u JOIN usuario_rol ur ON ur.usuario_id = u.id JOIN rol r ON r.id = ur.rol_id
          WHERE r.codigo = 'VICERRECTOR' AND u.activo ORDER BY u.id LIMIT 1));
$$;

-- Cálculo de aranceles esperados y foliación continua
CREATE OR REPLACE FUNCTION fn_importe_esperado(p_tramite INT) RETURNS NUMERIC LANGUAGE sql STABLE AS $$
    SELECT COALESCE(SUM(c.tarifa * CASE c.tipo_cobro WHEN 'FOJA'    THEN td.cantidad_paginas
                                                     WHEN 'MATERIA' THEN COALESCE(td.cantidad_materias, 0)
                                                     ELSE 1 END), 0)
      FROM tramite_documento td JOIN catalogo_documento c ON c.id = td.catalogo_documento_id
     WHERE td.tramite_id = p_tramite AND td.estado <> 'RECHAZADO';
$$;

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

-- Plazo de descuento de diplomado
CREATE OR REPLACE FUNCTION fn_trg_solicitud_descuento() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.fecha_limite   := (NEW.fecha_ultima_nota + INTERVAL '1 year')::DATE;
    NEW.dentro_plazo   := NEW.fecha_solicitud <= NEW.fecha_limite;
    NEW.financiamiento := CASE WHEN NEW.dentro_plazo THEN 'CON_DESCUENTO_50' ELSE 'SIN_FINANCIAMIENTO' END;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_solicitud_descuento BEFORE INSERT OR UPDATE ON solicitud_descuento
    FOR EACH ROW EXECUTE FUNCTION fn_trg_solicitud_descuento();

-- Alta de trámite
CREATE OR REPLACE FUNCTION fn_trg_tramite_antes_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.codigo IS NULL THEN
        NEW.codigo := (SELECT prefijo FROM tipo_tramite WHERE id = NEW.tipo_tramite_id) || '-' ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_tramite')::TEXT, 6, '0');
    END IF;
    IF NEW.destino IS NULL AND (SELECT codigo FROM tipo_tramite WHERE id = NEW.tipo_tramite_id) = 'LEGALIZACION' THEN
        RAISE EXCEPTION 'Una legalización debe indicar el destino (NACIONAL / EXTERIOR)';
    END IF;
    NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    RETURN NEW;
END $$;
CREATE TRIGGER trg_tramite_antes_insert BEFORE INSERT ON tramite FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_antes_insert();

CREATE OR REPLACE FUNCTION fn_trg_tramite_despues_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO tramite_requisito(tramite_id, requisito_id)
    SELECT NEW.id, id FROM requisito WHERE tipo_tramite_id = NEW.tipo_tramite_id;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_tramite_despues_insert AFTER INSERT ON tramite FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_despues_insert();

-- Máquina de estados completa (Origen Facultativo -> Vicerrectorado -> Rectorado)
CREATE OR REPLACE FUNCTION fn_trg_tramite_validar_estado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_tipo VARCHAR; v_n INT;
BEGIN
    IF NEW.estado = OLD.estado THEN RETURN NEW; END IF;
    SELECT codigo INTO v_tipo FROM tipo_tramite WHERE id = NEW.tipo_tramite_id;

    IF NOT (OLD.estado || '>' || NEW.estado = ANY (ARRAY[
        'SOLICITADO_ORIGEN>EN_FIRMA_ORIGEN', 'SOLICITADO_ORIGEN>OBSERVADO', 'SOLICITADO_ORIGEN>RECHAZADO',
        'EN_FIRMA_ORIGEN>INGRESADO', 'EN_FIRMA_ORIGEN>OBSERVADO', 'EN_FIRMA_ORIGEN>RECHAZADO',
        'INGRESADO>EN_REVISION', 'INGRESADO>RECHAZADO',
        'EN_REVISION>OBSERVADO', 'EN_REVISION>EN_BANDEJA_DESPACHO', 'EN_REVISION>RECHAZADO',
        'OBSERVADO>EN_FIRMA_ORIGEN', 'OBSERVADO>EN_REVISION', 'OBSERVADO>RECHAZADO',
        'EN_BANDEJA_DESPACHO>FIRMADO_DIGITALMENTE', 'EN_BANDEJA_DESPACHO>OBSERVADO',
        'FIRMADO_DIGITALMENTE>DERIVADO_RECTORADO', 'FIRMADO_DIGITALMENTE>CONCLUIDO',
        'DERIVADO_RECTORADO>CONCLUIDO'])) THEN
        RAISE EXCEPTION 'Transición de estado no permitida: % -> %', OLD.estado, NEW.estado;
    END IF;

    IF NEW.estado = 'INGRESADO' AND v_tipo = 'LEGALIZACION' THEN
        IF EXISTS (SELECT 1 FROM tramite_documento WHERE tramite_id = NEW.id AND estado <> 'RECHAZADO' AND NOT firmas_origen_ok) THEN
            RAISE EXCEPTION 'Faltan firmas digitales precedentes de facultad en el trámite %', NEW.codigo;
        END IF;
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
        IF v_tipo = 'LEGALIZACION' THEN
            IF NEW.importe_esperado = 0 THEN RAISE EXCEPTION 'No se seleccionaron documentos'; END IF;
            IF NEW.importe_pagado < NEW.importe_esperado THEN
                RAISE EXCEPTION 'Recibo insuficiente (registrado Bs %, esperado Bs %)', NEW.importe_pagado, NEW.importe_esperado;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM expediente WHERE tramite_id = NEW.id AND vigente) THEN
                RAISE EXCEPTION 'El expediente no ha sido compilado';
            END IF;
        ELSIF NOT EXISTS (SELECT 1 FROM solicitud_descuento WHERE tramite_id = NEW.id AND dentro_plazo) THEN
            RAISE EXCEPTION 'Solicitud de descuento fuera de plazo (SIN FINANCIAMIENTO)';
        END IF;
    END IF;

    IF NEW.estado = 'FIRMADO_DIGITALMENTE' AND NOT EXISTS
       (SELECT 1 FROM firma_digital WHERE tramite_id = NEW.id AND etapa = 'DESPACHO' AND valida) THEN
        RAISE EXCEPTION 'El trámite % no tiene firma digital válida de despacho', NEW.codigo;
    END IF;

    IF NEW.estado = 'DERIVADO_RECTORADO' AND NEW.destino IS DISTINCT FROM 'EXTERIOR' THEN
        RAISE EXCEPTION 'Solo los trámites con destino EXTERIOR se derivan a Rectorado';
    END IF;

    IF NEW.estado = 'CONCLUIDO' THEN
        IF OLD.estado = 'FIRMADO_DIGITALMENTE' AND NEW.destino = 'EXTERIOR' THEN
            RAISE EXCEPTION 'El trámite con destino EXTERIOR debe derivarse antes a Rectorado';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM certificado_oficial WHERE tramite_id = NEW.id AND estado = 'VIGENTE') THEN
            RAISE EXCEPTION 'No se ha emitido el certificado oficial con QR';
        END IF;
        NEW.fecha_conclusion := LOCALTIMESTAMP;
    END IF;

    RETURN NEW;
END $$;
CREATE TRIGGER trg_tramite_validar_estado BEFORE UPDATE OF estado ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_validar_estado();

-- Registro de tiempos SLA, notificaciones y bitácora al cambiar estado
CREATE OR REPLACE FUNCTION fn_trg_tramite_post_estado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_final BOOLEAN;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.estado = OLD.estado THEN RETURN NULL; END IF;
    v_final := NEW.estado IN ('CONCLUIDO','RECHAZADO');

    UPDATE tramite_tiempo_fase
       SET fecha_fin = LOCALTIMESTAMP, horas_habiles = fn_horas_habiles(fecha_inicio, LOCALTIMESTAMP)
     WHERE tramite_id = NEW.id AND fecha_fin IS NULL;

    INSERT INTO tramite_tiempo_fase(tramite_id, estado, fecha_inicio, fecha_fin, horas_habiles, responsable_id)
    VALUES (NEW.id, NEW.estado, LOCALTIMESTAMP, CASE WHEN v_final THEN LOCALTIMESTAMP END,
            CASE WHEN v_final THEN 0 END, fn_usuario_actual());

    INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
    VALUES (NEW.solicitante_id, NEW.id, 'CAMBIO_ESTADO',
            'Su trámite ' || NEW.codigo || ' cambió al estado: ' || NEW.estado);

    PERFORM fn_registrar_bitacora(NEW.id, 'CAMBIO_ESTADO', 'tramite', NEW.id::TEXT,
                                  jsonb_build_object('codigo', NEW.codigo, 'estado', NEW.estado));
    RETURN NULL;
END $$;
CREATE TRIGGER trg_tramite_post_estado AFTER INSERT OR UPDATE OF estado ON tramite
    FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_post_estado();

-- Totales de documentos y validación de importe de recibos de caja
CREATE OR REPLACE FUNCTION fn_trg_documento_totales() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_t INT := COALESCE(NEW.tramite_id, OLD.tramite_id);
BEGIN
    UPDATE tramite SET
        total_fojas = (SELECT COALESCE(SUM(cantidad_paginas), 0) FROM tramite_documento
                        WHERE tramite_id = v_t AND estado <> 'RECHAZADO'),
        importe_esperado = fn_importe_esperado(v_t)
     WHERE id = v_t;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_documento_totales AFTER INSERT OR UPDATE OR DELETE ON tramite_documento
    FOR EACH ROW EXECUTE FUNCTION fn_trg_documento_totales();

-- 1. Asigna automáticamente quién registró el recibo antes de insertar
CREATE OR REPLACE FUNCTION fn_trg_comprobante_antes_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    RETURN NEW;
END $$;

CREATE TRIGGER trg_comprobante_antes_insert
    BEFORE INSERT ON comprobante_caja
    FOR EACH ROW EXECUTE FUNCTION fn_trg_comprobante_antes_insert();

-- 2. Recalcula el importe pagado en el trámite después de insertar, actualizar o eliminar
CREATE OR REPLACE FUNCTION fn_trg_comprobante_total() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_t INT := COALESCE(NEW.tramite_id, OLD.tramite_id);
BEGIN
    UPDATE tramite
       SET importe_pagado = (SELECT COALESCE(SUM(importe), 0)
                               FROM comprobante_caja
                              WHERE tramite_id = v_t AND concepto = 'LEGALIZACION')
     WHERE id = v_t;
    RETURN NULL;
END $$;

CREATE TRIGGER trg_comprobante_total
    AFTER INSERT OR UPDATE OR DELETE ON comprobante_caja
    FOR EACH ROW EXECUTE FUNCTION fn_trg_comprobante_total();

-- Validación de lote de firma (Autoridades de Facultad, Vicerrector/Suplente y Rector)
CREATE OR REPLACE FUNCTION fn_trg_lote_validar() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id
                    WHERE ur.usuario_id = NEW.firmante_id
                      AND r.codigo IN ('DIRECTOR_CARRERA','DECANO','ADMISIONES','DAGA','VICERRECTOR','RECTOR')) THEN
        SELECT id INTO NEW.delegacion_id FROM delegacion_autoridad
         WHERE suplente_id = NEW.firmante_id AND estado = 'ACTIVA'
           AND CURRENT_DATE BETWEEN fecha_inicio AND fecha_fin
         ORDER BY fecha_registro DESC LIMIT 1;
        IF NEW.delegacion_id IS NULL THEN
            RAISE EXCEPTION 'El usuario % no tiene rol de autoridad firmante ni delegación vigente', NEW.firmante_id;
        END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM certificado_digital
                    WHERE id = NEW.certificado_id AND usuario_id = NEW.firmante_id AND activo
                      AND CURRENT_DATE BETWEEN fecha_emision AND fecha_expiracion) THEN
        RAISE EXCEPTION 'Certificado digital inválido, vencido o ajeno al firmante';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_lote_validar BEFORE INSERT ON lote_firma FOR EACH ROW EXECUTE FUNCTION fn_trg_lote_validar();

-- Auto-verificación de cadena de firmas en origen por documento
CREATE OR REPLACE FUNCTION fn_trg_firma_origen_check() RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE v_req INT; v_act INT;
BEGIN
    IF NEW.etapa = 'ORIGEN' AND NEW.tramite_documento_id IS NOT NULL AND NEW.valida THEN
        SELECT COUNT(*) INTO v_req FROM catalogo_firma_orden cfo
          JOIN tramite_documento td ON td.catalogo_documento_id = cfo.catalogo_documento_id
         WHERE td.id = NEW.tramite_documento_id;

        SELECT COUNT(DISTINCT orden_firma) INTO v_act FROM firma_digital
         WHERE tramite_documento_id = NEW.tramite_documento_id AND etapa = 'ORIGEN' AND valida;

        IF v_act >= v_req THEN
            UPDATE tramite_documento SET firmas_origen_ok = TRUE, estado = 'VALIDO'
             WHERE id = NEW.tramite_documento_id;
        END IF;
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER trg_firma_origen_check AFTER INSERT ON firma_digital FOR EACH ROW EXECUTE FUNCTION fn_trg_firma_origen_check();

-- Certificado Oficial con QR
CREATE OR REPLACE FUNCTION fn_trg_certificado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.numero IS NULL THEN
        NEW.numero := CASE NEW.tipo WHEN 'LEGALIZACION' THEN 'CL-' ELSE 'CD-' END ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_certificado')::TEXT, 6, '0');
    END IF;
    IF NEW.firma_id IS NULL THEN
        SELECT id INTO NEW.firma_id FROM firma_digital WHERE tramite_id = NEW.tramite_id AND valida
         ORDER BY fecha_firma DESC LIMIT 1;
    END IF;
    NEW.qr_contenido := 'https://legalizaciones.uagrm.edu.bo/verificar?c=' || NEW.codigo_verificacion ||
                        '&h=' || NEW.hash_sha256;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_certificado BEFORE INSERT ON certificado_oficial FOR EACH ROW EXECUTE FUNCTION fn_trg_certificado();

-- =====================================================================
-- VISTAS OPERATIVAS Y DASHBOARD
-- =====================================================================
CREATE OR REPLACE VIEW v_semaforo_sla AS
WITH consumo AS (
    SELECT f.tramite_id,
           SUM(CASE WHEN f.estado NOT IN ('SOLICITADO_ORIGEN','EN_FIRMA_ORIGEN','OBSERVADO','CONCLUIDO','RECHAZADO')
                    THEN COALESCE(f.horas_habiles, fn_horas_habiles(f.fecha_inicio, LOCALTIMESTAMP))
                    ELSE 0 END) AS horas
      FROM tramite_tiempo_fase f
     GROUP BY f.tramite_id
)
SELECT t.id AS tramite_id, t.codigo, t.estado, (t.estado IN ('CONCLUIDO','RECHAZADO')) AS es_final,
       t.prioridad, t.fecha_ingreso, COALESCE(c.horas, 0) AS horas_consumidas, s.plazo_horas,
       ROUND(100.0 * COALESCE(c.horas, 0) / s.plazo_horas, 1) AS porcentaje,
       CASE WHEN COALESCE(c.horas, 0) >= s.plazo_horas THEN 'ROJO'
            WHEN COALESCE(c.horas, 0) >= s.plazo_horas * s.porcentaje_alerta / 100 THEN 'AMARILLO'
            ELSE 'VERDE' END AS semaforo
  FROM tramite t
  LEFT JOIN consumo c ON c.tramite_id = t.id
  CROSS JOIN LATERAL (SELECT plazo_horas, porcentaje_alerta FROM sla_config sc
                       WHERE sc.tipo_tramite_id = t.tipo_tramite_id OR sc.tipo_tramite_id IS NULL
                       ORDER BY sc.tipo_tramite_id NULLS LAST LIMIT 1) s;

CREATE OR REPLACE VIEW v_bandeja_despacho AS
SELECT t.id AS tramite_id, t.codigo, tt.nombre AS tipo_tramite, COALESCE(t.destino, '-') AS destino,
       t.institucion_destino, u.nombres || ' ' || u.apellidos AS solicitante, u.registro,
       c.nombre AS carrera, f.nombre AS facultad, t.prioridad, t.total_fojas,
       ex.id AS expediente_id, ex.ruta_pdf, s.horas_consumidas, s.semaforo, t.fecha_ingreso
  FROM tramite t
  JOIN tipo_tramite tt    ON tt.id = t.tipo_tramite_id
  JOIN usuario u          ON u.id = t.solicitante_id
  JOIN carrera c          ON c.id = t.carrera_id
  JOIN facultad f         ON f.id = c.facultad_id
  LEFT JOIN expediente ex ON ex.tramite_id = t.id AND ex.vigente
  JOIN v_semaforo_sla s   ON s.tramite_id = t.id
 WHERE t.estado = 'EN_BANDEJA_DESPACHO';

