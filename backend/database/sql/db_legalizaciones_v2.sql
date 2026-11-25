--
-- Sistema de Legalizaciones UAGRM - esquema de la base db_legalizaciones_v2
-- Generado con pg_dump --schema-only a partir de las migraciones de Laravel (database/migrations).
-- Incluye tablas, restricciones, índices, secuencias, funciones, triggers y vistas, y al final
-- las 18 facultades en su orden de prelación institucional. El resto de los datos iniciales
-- se carga con: php artisan db:seed
--
--
-- PostgreSQL database dump
--

\restrict j7SpdFf3BvUgGXzvJUaKxRMb01L1fRJoQc6QIa8RCtqqRaM8eZjGWc8vLyzw1tA

-- Dumped from database version 17.12
-- Dumped by pg_dump version 17.12

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: fn_asignar_folios(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_asignar_folios(p_tramite bigint) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE r RECORD; v_folio INT := 1;
BEGIN
    FOR r IN SELECT id, cantidad_paginas FROM tramite_documento
              WHERE tramite_id = p_tramite AND estado <> 'RECHAZADO' ORDER BY id LOOP
        UPDATE tramite_documento SET folio_inicio = v_folio, folio_fin = v_folio + r.cantidad_paginas - 1
         WHERE id = r.id;
        v_folio := v_folio + r.cantidad_paginas;
    END LOOP;
    RETURN v_folio - 1;
END $$;


--
-- Name: fn_autoridad_vigente(date); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_autoridad_vigente(p_fecha date DEFAULT CURRENT_DATE) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
    SELECT COALESCE(
        (SELECT suplente_id FROM delegacion_autoridad
          WHERE estado = 'ACTIVA' AND p_fecha BETWEEN fecha_inicio AND fecha_fin
          ORDER BY fecha_registro DESC LIMIT 1),
        (SELECT u.id FROM usuario u JOIN usuario_rol ur ON ur.usuario_id = u.id JOIN rol r ON r.id = ur.rol_id
          WHERE r.codigo = 'VICERRECTOR' AND u.activo ORDER BY u.id LIMIT 1));
$$;


--
-- Name: fn_bitacora_hash(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_bitacora_hash() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE v_prev CHAR(64);
BEGIN
    PERFORM pg_advisory_xact_lock(20260001);
    SELECT hash_actual INTO v_prev FROM bitacora ORDER BY id DESC LIMIT 1;
    NEW.fecha         := clock_timestamp()::TIMESTAMP;
    NEW.hash_anterior := COALESCE(v_prev, repeat('0', 64));
    NEW.hash_actual   := encode(sha256(convert_to(
                             NEW.hash_anterior || '|' || NEW.id || '|' ||
                             COALESCE(NEW.tramite_id::TEXT, '') || '|' || COALESCE(NEW.usuario_id::TEXT, '') || '|' ||
                             NEW.accion || '|' || COALESCE(NEW.delegacion_id::TEXT, '') || '|' ||
                             COALESCE(NEW.certificado_id::TEXT, '') || '|' || COALESCE(NEW.transaccion_pago_id::TEXT, '') || '|' ||
                             COALESCE(NEW.detalle::TEXT, '') || '|' || NEW.fecha::TEXT, 'UTF8')), 'hex');
    RETURN NEW;
END $$;


--
-- Name: fn_bitacora_inmutable(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_bitacora_inmutable() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'La bitácora es inalterable: no se permite %', TG_OP;
END $$;


--
-- Name: fn_horas_habiles(timestamp without time zone, timestamp without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_horas_habiles(p_ini timestamp without time zone, p_fin timestamp without time zone) RETURNS numeric
    LANGUAGE sql STABLE
    AS $$
    SELECT COALESCE(ROUND(SUM(EXTRACT(EPOCH FROM (LEAST(p_fin, d.dia + INTERVAL '1 day')
                                                  - GREATEST(p_ini, d.dia)))::NUMERIC) / 3600.0, 2), 0)
      FROM generate_series(date_trunc('day', p_ini), date_trunc('day', p_fin), INTERVAL '1 day') AS d(dia)
     WHERE p_fin > p_ini
       AND EXTRACT(ISODOW FROM d.dia) < 6
       AND NOT EXISTS (SELECT 1 FROM feriado f WHERE f.fecha = d.dia::DATE);
$$;


--
-- Name: fn_importe_esperado(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_importe_esperado(p_tramite bigint) RETURNS numeric
    LANGUAGE sql STABLE
    AS $$
    SELECT COALESCE(SUM(c.tarifa * CASE c.tipo_cobro WHEN 'FOJA'    THEN td.cantidad_paginas
                                                     WHEN 'MATERIA' THEN COALESCE(td.cantidad_materias, 0)
                                                     ELSE 1 END), 0)
      FROM tramite_documento td JOIN catalogo_documento c ON c.id = td.catalogo_documento_id
     WHERE td.tramite_id = p_tramite AND td.estado <> 'RECHAZADO';
$$;


--
-- Name: fn_registrar_bitacora(bigint, text, jsonb, bigint, bigint, bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_registrar_bitacora(p_tramite bigint, p_accion text, p_detalle jsonb, p_delegacion bigint DEFAULT NULL::bigint, p_certificado bigint DEFAULT NULL::bigint, p_transaccion_pago bigint DEFAULT NULL::bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    INSERT INTO bitacora(tramite_id, usuario_id, accion, delegacion_id, certificado_id, transaccion_pago_id,
                         detalle, hash_anterior, hash_actual)
    VALUES (p_tramite, fn_usuario_actual(), p_accion, p_delegacion, p_certificado, p_transaccion_pago,
            p_detalle, '', '');
END $$;


--
-- Name: fn_trg_certificado(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_certificado() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.numero IS NULL THEN
        NEW.numero := CASE NEW.tipo WHEN 'LEGALIZACION' THEN 'CL-' ELSE 'CD-' END ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_certificado')::TEXT, 6, '0');
    END IF;
    IF NEW.firma_id IS NULL THEN
        SELECT id INTO NEW.firma_id FROM firma_digital WHERE tramite_id = NEW.tramite_id AND valida
         ORDER BY fecha_firma DESC LIMIT 1;
    END IF;
    -- app.url_validador lo fija la aplicación en cada transacción (FRONTEND_URL/validar-certificado)
    NEW.qr_contenido := COALESCE(NULLIF(current_setting('app.url_validador', true), ''),
                                 'http://localhost:5173/validar-certificado') ||
                        '?codigo=' || NEW.codigo_verificacion || '&hash=' || NEW.hash_sha256;
    RETURN NEW;
END $$;


--
-- Name: fn_trg_comprobante_antes_insert(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_comprobante_antes_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    RETURN NEW;
END $$;


--
-- Name: fn_trg_comprobante_total(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_comprobante_total() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE v_t BIGINT := COALESCE(NEW.tramite_id, OLD.tramite_id);
BEGIN
    UPDATE tramite
       SET importe_pagado = (SELECT COALESCE(SUM(importe), 0) FROM comprobante_caja
                              WHERE tramite_id = v_t AND concepto = 'LEGALIZACION')
     WHERE id = v_t;
    RETURN NULL;
END $$;


--
-- Name: fn_trg_documento_totales(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_documento_totales() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE v_t BIGINT := COALESCE(NEW.tramite_id, OLD.tramite_id);
BEGIN
    UPDATE tramite SET
        total_fojas = (SELECT COALESCE(SUM(cantidad_paginas), 0) FROM tramite_documento
                        WHERE tramite_id = v_t AND estado <> 'RECHAZADO'),
        importe_esperado = fn_importe_esperado(v_t)
     WHERE id = v_t;
    RETURN NULL;
END $$;


--
-- Name: fn_trg_firma_origen_check(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_firma_origen_check() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: fn_trg_lote_validar(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_lote_validar() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: fn_trg_solicitud_descuento(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_solicitud_descuento() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.fecha_limite   := (NEW.fecha_ultima_nota + INTERVAL '1 year')::DATE;
    NEW.dentro_plazo   := NEW.fecha_solicitud <= NEW.fecha_limite;
    NEW.financiamiento := CASE WHEN NEW.dentro_plazo THEN 'CON_DESCUENTO_50' ELSE 'SIN_FINANCIAMIENTO' END;
    RETURN NEW;
END $$;


--
-- Name: fn_trg_tramite_antes_insert(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_tramite_antes_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.codigo IS NULL THEN
        NEW.codigo := (SELECT prefijo FROM tipo_tramite WHERE id = NEW.tipo_tramite_id) || '-' ||
                      to_char(LOCALTIMESTAMP, 'YYYY') || '-' || lpad(nextval('seq_tramite')::TEXT, 6, '0');
    END IF;
    IF NEW.destino IS NULL AND (SELECT codigo FROM tipo_tramite WHERE id = NEW.tipo_tramite_id) = 'LEGALIZACION' THEN
        RAISE EXCEPTION 'Una legalización debe indicar el destino (NACIONAL / EXTERIOR)';
    END IF;
    -- El estudiante es el único iniciador de trámites
    IF NOT EXISTS (SELECT 1 FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id
                    WHERE ur.usuario_id = NEW.solicitante_id AND r.codigo = 'ESTUDIANTE') THEN
        RAISE EXCEPTION 'Solo un estudiante puede ser el solicitante de un trámite';
    END IF;
    NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
    RETURN NEW;
END $$;


--
-- Name: fn_trg_tramite_despues_insert(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_tramite_despues_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    INSERT INTO tramite_requisito(tramite_id, requisito_id)
    SELECT NEW.id, id FROM requisito WHERE tipo_tramite_id = NEW.tipo_tramite_id;
    RETURN NULL;
END $$;


--
-- Name: fn_trg_tramite_post_estado(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_tramite_post_estado() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE v_final BOOLEAN;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.estado = OLD.estado THEN RETURN NULL; END IF;
    v_final := NEW.estado IN ('CONCLUIDO', 'RECHAZADO');

    UPDATE tramite_tiempo_fase
       SET fecha_fin = LOCALTIMESTAMP, horas_habiles = fn_horas_habiles(fecha_inicio, LOCALTIMESTAMP)
     WHERE tramite_id = NEW.id AND fecha_fin IS NULL;

    INSERT INTO tramite_tiempo_fase(tramite_id, estado, fecha_inicio, fecha_fin, horas_habiles, responsable_id)
    VALUES (NEW.id, NEW.estado, LOCALTIMESTAMP, CASE WHEN v_final THEN LOCALTIMESTAMP END,
            CASE WHEN v_final THEN 0 END, fn_usuario_actual());

    INSERT INTO notificacion(usuario_id, tramite_id, tipo, mensaje)
    VALUES (NEW.solicitante_id, NEW.id, 'CAMBIO_ESTADO',
            'Su trámite ' || NEW.codigo || ' cambió al estado: ' || NEW.estado);

    PERFORM fn_registrar_bitacora(NEW.id, 'CAMBIO_ESTADO',
                                  jsonb_build_object('codigo', NEW.codigo, 'estado', NEW.estado));
    RETURN NULL;
END $$;


--
-- Name: fn_trg_tramite_validar_estado(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_trg_tramite_validar_estado() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
        IF EXISTS (SELECT 1 FROM tramite_documento
                    WHERE tramite_id = NEW.id AND estado <> 'RECHAZADO' AND NOT firmas_origen_ok) THEN
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
        IF OLD.estado = 'DERIVADO_RECTORADO' AND NOT EXISTS
           (SELECT 1 FROM firma_digital WHERE tramite_id = NEW.id AND etapa = 'RECTORADO' AND valida) THEN
            RAISE EXCEPTION 'El trámite % no tiene la firma digital de Rectorado', NEW.codigo;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM certificado_oficial WHERE tramite_id = NEW.id AND estado = 'VIGENTE') THEN
            RAISE EXCEPTION 'No se ha emitido el certificado oficial con QR';
        END IF;
        NEW.fecha_conclusion := LOCALTIMESTAMP;
    END IF;

    RETURN NEW;
END $$;


--
-- Name: fn_usuario_actual(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_usuario_actual() RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
    SELECT NULLIF(current_setting('app.usuario_id', true), '')::BIGINT;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: alerta_sla; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.alerta_sla (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    tipo character varying(255) NOT NULL,
    horas_transcurridas numeric(8,2) NOT NULL,
    plazo_horas numeric(6,2) NOT NULL,
    fecha_generacion timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    atendida boolean DEFAULT false NOT NULL,
    atendida_por bigint,
    fecha_atencion timestamp(0) without time zone,
    CONSTRAINT alerta_sla_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['PREVENTIVA'::character varying, 'VENCIDA'::character varying])::text[])))
);


--
-- Name: alerta_sla_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.alerta_sla_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: alerta_sla_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.alerta_sla_id_seq OWNED BY public.alerta_sla.id;


--
-- Name: bitacora; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bitacora (
    id bigint NOT NULL,
    tramite_id bigint,
    usuario_id bigint,
    accion character varying(60) NOT NULL,
    delegacion_id bigint,
    detalle jsonb,
    fecha timestamp(6) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    hash_anterior character(64) NOT NULL,
    hash_actual character(64) NOT NULL,
    certificado_id bigint,
    transaccion_pago_id bigint
);


--
-- Name: bitacora_acceso; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bitacora_acceso (
    id bigint NOT NULL,
    usuario_id bigint,
    email_intento character varying(150),
    accion character varying(255) NOT NULL,
    ruta character varying(255) NOT NULL,
    metodo_http character varying(10) DEFAULT 'GET'::character varying NOT NULL,
    ip_origen character varying(45),
    user_agent text,
    fecha timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT bitacora_acceso_accion_check CHECK (((accion)::text = ANY ((ARRAY['LOGIN_OK'::character varying, 'LOGIN_FALLIDO'::character varying, 'LOGOUT'::character varying, 'ACCESO_RECURSO'::character varying])::text[])))
);


--
-- Name: bitacora_acceso_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bitacora_acceso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bitacora_acceso_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bitacora_acceso_id_seq OWNED BY public.bitacora_acceso.id;


--
-- Name: bitacora_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.bitacora_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: bitacora_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.bitacora_id_seq OWNED BY public.bitacora.id;


--
-- Name: carrera; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.carrera (
    id bigint NOT NULL,
    facultad_id bigint NOT NULL,
    codigo character varying(15) NOT NULL,
    nombre character varying(150) NOT NULL,
    regimen character varying(255) DEFAULT 'SEMESTRAL'::character varying NOT NULL,
    CONSTRAINT carrera_regimen_check CHECK (((regimen)::text = ANY ((ARRAY['SEMESTRAL'::character varying, 'ANUAL'::character varying])::text[])))
);


--
-- Name: carrera_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.carrera_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: carrera_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.carrera_id_seq OWNED BY public.carrera.id;


--
-- Name: catalogo_documento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.catalogo_documento (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(200) NOT NULL,
    unidad_emisora_id bigint NOT NULL,
    tipo_cobro character varying(255) NOT NULL,
    tarifa numeric(10,2) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    CONSTRAINT catalogo_documento_tipo_cobro_check CHECK (((tipo_cobro)::text = ANY ((ARRAY['FOJA'::character varying, 'MATERIA'::character varying, 'DOCUMENTO'::character varying])::text[]))),
    CONSTRAINT ck_catalogo_tarifa CHECK ((tarifa >= (0)::numeric))
);


--
-- Name: catalogo_documento_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.catalogo_documento_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: catalogo_documento_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.catalogo_documento_id_seq OWNED BY public.catalogo_documento.id;


--
-- Name: catalogo_firma_orden; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.catalogo_firma_orden (
    id bigint NOT NULL,
    catalogo_documento_id bigint NOT NULL,
    unidad_origen_id bigint NOT NULL,
    orden integer NOT NULL,
    cargo_firmante character varying(100) NOT NULL,
    requiere_sello boolean DEFAULT true NOT NULL
);


--
-- Name: catalogo_firma_orden_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.catalogo_firma_orden_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: catalogo_firma_orden_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.catalogo_firma_orden_id_seq OWNED BY public.catalogo_firma_orden.id;


--
-- Name: certificado_digital; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.certificado_digital (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    numero_serie character varying(80) NOT NULL,
    emisor character varying(150) NOT NULL,
    fecha_emision date NOT NULL,
    fecha_expiracion date NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    CONSTRAINT ck_certificado_vigencia CHECK ((fecha_expiracion > fecha_emision))
);


--
-- Name: certificado_digital_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.certificado_digital_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: certificado_digital_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.certificado_digital_id_seq OWNED BY public.certificado_digital.id;


--
-- Name: certificado_oficial; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.certificado_oficial (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    firma_id bigint,
    numero character varying(30),
    tipo character varying(255) NOT NULL,
    ruta_pdfa character varying(255) NOT NULL,
    hash_sha256 character(64) NOT NULL,
    codigo_verificacion uuid DEFAULT gen_random_uuid() NOT NULL,
    qr_contenido text,
    fecha_emision timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    estado character varying(255) DEFAULT 'VIGENTE'::character varying NOT NULL,
    CONSTRAINT certificado_oficial_estado_check CHECK (((estado)::text = ANY ((ARRAY['VIGENTE'::character varying, 'ANULADO'::character varying])::text[]))),
    CONSTRAINT certificado_oficial_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['LEGALIZACION'::character varying, 'CONSTANCIA_DIPLOMADO'::character varying])::text[])))
);


--
-- Name: certificado_oficial_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.certificado_oficial_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: certificado_oficial_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.certificado_oficial_id_seq OWNED BY public.certificado_oficial.id;


--
-- Name: comprobante_caja; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comprobante_caja (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    codigo_recibo character varying(30) NOT NULL,
    concepto character varying(255) NOT NULL,
    importe numeric(10,2) NOT NULL,
    fecha_pago date NOT NULL,
    registrado_por bigint,
    CONSTRAINT ck_comprobante_importe CHECK ((importe > (0)::numeric)),
    CONSTRAINT comprobante_caja_concepto_check CHECK (((concepto)::text = ANY ((ARRAY['EMISION_ORIGEN'::character varying, 'LEGALIZACION'::character varying, 'AVANCE_ACADEMICO'::character varying])::text[])))
);


--
-- Name: comprobante_caja_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comprobante_caja_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comprobante_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comprobante_caja_id_seq OWNED BY public.comprobante_caja.id;


--
-- Name: contador_visita; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contador_visita (
    id bigint NOT NULL,
    ruta character varying(150) NOT NULL,
    titulo_pagina character varying(120) NOT NULL,
    menu_opcion_id bigint,
    visitas bigint DEFAULT '0'::bigint NOT NULL,
    ultima_visita timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: contador_visita_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contador_visita_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contador_visita_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contador_visita_id_seq OWNED BY public.contador_visita.id;


--
-- Name: delegacion_autoridad; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.delegacion_autoridad (
    id bigint NOT NULL,
    titular_id bigint NOT NULL,
    suplente_id bigint NOT NULL,
    nro_resolucion character varying(50) NOT NULL,
    fecha_resolucion date NOT NULL,
    motivo character varying(255) NOT NULL,
    detalle_motivo character varying(255),
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    estado character varying(255) DEFAULT 'ACTIVA'::character varying NOT NULL,
    registrado_por bigint,
    fecha_registro timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_delegacion_fechas CHECK ((fecha_fin >= fecha_inicio)),
    CONSTRAINT ck_delegacion_personas CHECK ((titular_id <> suplente_id)),
    CONSTRAINT delegacion_autoridad_estado_check CHECK (((estado)::text = ANY ((ARRAY['ACTIVA'::character varying, 'FINALIZADA'::character varying, 'REVOCADA'::character varying])::text[]))),
    CONSTRAINT delegacion_autoridad_motivo_check CHECK (((motivo)::text = ANY ((ARRAY['VIAJE'::character varying, 'COMISION_OFICIAL'::character varying, 'AUSENCIA'::character varying, 'VACACION'::character varying, 'OTRO'::character varying])::text[])))
);


--
-- Name: delegacion_autoridad_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.delegacion_autoridad_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: delegacion_autoridad_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.delegacion_autoridad_id_seq OWNED BY public.delegacion_autoridad.id;


--
-- Name: expediente; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.expediente (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    ruta_pdf character varying(255) NOT NULL,
    total_fojas integer NOT NULL,
    hash_sha256 character(64) NOT NULL,
    compilado_por bigint,
    fecha_compilacion timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    vigente boolean DEFAULT true NOT NULL,
    CONSTRAINT ck_expediente_fojas CHECK ((total_fojas > 0))
);


--
-- Name: expediente_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.expediente_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: expediente_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.expediente_id_seq OWNED BY public.expediente.id;


--
-- Name: facultad; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.facultad (
    id bigint NOT NULL,
    codigo character varying(20) NOT NULL,
    nombre character varying(150) NOT NULL,
    anio_creacion integer,
    orden_prelacion integer NOT NULL
);


--
-- Name: facultad_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.facultad_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: facultad_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.facultad_id_seq OWNED BY public.facultad.id;


--
-- Name: feriado; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feriado (
    fecha date NOT NULL,
    sla_config_id bigint DEFAULT '1'::bigint NOT NULL,
    descripcion character varying(120) NOT NULL,
    registrado_por bigint
);


--
-- Name: firma_digital; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.firma_digital (
    id bigint NOT NULL,
    lote_id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    tramite_documento_id bigint,
    expediente_id bigint,
    orden_firma integer DEFAULT 1 NOT NULL,
    etapa character varying(255) DEFAULT 'DESPACHO'::character varying NOT NULL,
    hash_documento character(64) NOT NULL,
    firma_valor text NOT NULL,
    fecha_firma timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    valida boolean DEFAULT true NOT NULL,
    CONSTRAINT firma_digital_etapa_check CHECK (((etapa)::text = ANY ((ARRAY['ORIGEN'::character varying, 'DESPACHO'::character varying, 'RECTORADO'::character varying])::text[])))
);


--
-- Name: firma_digital_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.firma_digital_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: firma_digital_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.firma_digital_id_seq OWNED BY public.firma_digital.id;


--
-- Name: flujo_paso; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.flujo_paso (
    id bigint NOT NULL,
    tipo_tramite_id bigint NOT NULL,
    destino character varying(255),
    orden integer NOT NULL,
    unidad_id bigint NOT NULL,
    nombre character varying(150) NOT NULL,
    descripcion text,
    firma_requerida character varying(150),
    sello_requerido character varying(150),
    estado_asociado character varying(25),
    CONSTRAINT flujo_paso_destino_check CHECK (((destino)::text = ANY ((ARRAY['NACIONAL'::character varying, 'EXTERIOR'::character varying])::text[])))
);


--
-- Name: flujo_paso_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.flujo_paso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: flujo_paso_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.flujo_paso_id_seq OWNED BY public.flujo_paso.id;


--
-- Name: lote_firma; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lote_firma (
    id bigint NOT NULL,
    firmante_id bigint NOT NULL,
    certificado_id bigint NOT NULL,
    delegacion_id bigint,
    es_remota boolean DEFAULT false NOT NULL,
    ubicacion character varying(150),
    ip_origen character varying(45),
    fecha timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: lote_firma_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.lote_firma_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: lote_firma_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.lote_firma_id_seq OWNED BY public.lote_firma.id;


--
-- Name: menu_opcion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.menu_opcion (
    id bigint NOT NULL,
    padre_id bigint,
    permiso_id bigint,
    titulo character varying(100) NOT NULL,
    ruta character varying(150),
    icono character varying(50),
    orden integer DEFAULT 1 NOT NULL,
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: menu_opcion_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.menu_opcion_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: menu_opcion_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.menu_opcion_id_seq OWNED BY public.menu_opcion.id;


--
-- Name: metodo_pago_usuario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.metodo_pago_usuario (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    stripe_payment_method_id character varying(100) NOT NULL,
    marca character varying(30) NOT NULL,
    ultimos_cuatro character(4) NOT NULL,
    mes_expiracion smallint NOT NULL,
    anio_expiracion smallint NOT NULL,
    predeterminado boolean DEFAULT false NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    fecha_registro timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_metodo_mes CHECK (((mes_expiracion >= 1) AND (mes_expiracion <= 12))),
    CONSTRAINT ck_metodo_ultimos CHECK ((ultimos_cuatro ~ '^[0-9]{4}$'::text))
);


--
-- Name: metodo_pago_usuario_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.metodo_pago_usuario_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: metodo_pago_usuario_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.metodo_pago_usuario_id_seq OWNED BY public.metodo_pago_usuario.id;


--
-- Name: notificacion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notificacion (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    tramite_id bigint,
    tipo character varying(30) NOT NULL,
    mensaje character varying(500) NOT NULL,
    leida boolean DEFAULT false NOT NULL,
    fecha_creacion timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: notificacion_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notificacion_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notificacion_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notificacion_id_seq OWNED BY public.notificacion.id;


--
-- Name: observacion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.observacion (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    tramite_documento_id bigint,
    tipo character varying(255) NOT NULL,
    descripcion character varying(500) NOT NULL,
    estado character varying(255) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    registrado_por bigint,
    fecha_registro timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_subsanacion timestamp(0) without time zone,
    CONSTRAINT observacion_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'SUBSANADA'::character varying, 'ANULADA'::character varying])::text[]))),
    CONSTRAINT observacion_tipo_check CHECK (((tipo)::text = ANY ((ARRAY['FOJA_ILEGIBLE'::character varying, 'SELLO_FALTANTE'::character varying, 'FIRMA_FALTANTE'::character varying, 'PAGO_INSUFICIENTE'::character varying, 'FOLIACION_INCORRECTA'::character varying, 'DOCUMENTO_EXTERNO'::character varying, 'FUERA_DE_PLAZO'::character varying, 'OTRO'::character varying])::text[])))
);


--
-- Name: observacion_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.observacion_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: observacion_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.observacion_id_seq OWNED BY public.observacion.id;


--
-- Name: permiso; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permiso (
    id bigint NOT NULL,
    codigo character varying(50) NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion character varying(255)
);


--
-- Name: permiso_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permiso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permiso_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permiso_id_seq OWNED BY public.permiso.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: requisito; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.requisito (
    id bigint NOT NULL,
    tipo_tramite_id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    descripcion character varying(255) NOT NULL,
    obligatorio boolean DEFAULT true NOT NULL
);


--
-- Name: requisito_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.requisito_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: requisito_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.requisito_id_seq OWNED BY public.requisito.id;


--
-- Name: rol; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rol (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(80) NOT NULL,
    descripcion character varying(255),
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: rol_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rol_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rol_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rol_id_seq OWNED BY public.rol.id;


--
-- Name: rol_permiso; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rol_permiso (
    rol_id bigint NOT NULL,
    permiso_id bigint NOT NULL,
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: seq_certificado; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seq_certificado
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seq_tramite; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seq_tramite
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sla_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sla_config (
    id bigint NOT NULL,
    tipo_tramite_id bigint,
    plazo_horas numeric(6,2) DEFAULT '72'::numeric NOT NULL,
    porcentaje_alerta numeric(5,2) DEFAULT '75'::numeric NOT NULL,
    CONSTRAINT ck_sla_porcentaje CHECK (((porcentaje_alerta >= (1)::numeric) AND (porcentaje_alerta <= (99)::numeric)))
);


--
-- Name: sla_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sla_config_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sla_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sla_config_id_seq OWNED BY public.sla_config.id;


--
-- Name: solicitud_descuento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.solicitud_descuento (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    unidad_postgrado character varying(150) NOT NULL,
    nombre_diplomado character varying(200) NOT NULL,
    nro_avance_academico character varying(20) NOT NULL,
    fecha_ultima_nota date NOT NULL,
    fecha_solicitud date DEFAULT CURRENT_DATE NOT NULL,
    fecha_limite date,
    dentro_plazo boolean,
    financiamiento character varying(20)
);


--
-- Name: solicitud_descuento_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.solicitud_descuento_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: solicitud_descuento_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.solicitud_descuento_id_seq OWNED BY public.solicitud_descuento.id;


--
-- Name: tipo_tramite; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tipo_tramite (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(120) NOT NULL,
    descripcion character varying(255),
    prefijo character varying(5) NOT NULL
);


--
-- Name: tipo_tramite_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tipo_tramite_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tipo_tramite_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tipo_tramite_id_seq OWNED BY public.tipo_tramite.id;


--
-- Name: tramite; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tramite (
    id bigint NOT NULL,
    codigo character varying(25),
    tipo_tramite_id bigint NOT NULL,
    solicitante_id bigint NOT NULL,
    carrera_id bigint NOT NULL,
    destino character varying(255),
    pais_destino character varying(80),
    institucion_destino character varying(200),
    prioridad character varying(255) DEFAULT 'NORMAL'::character varying NOT NULL,
    estado character varying(255) DEFAULT 'SOLICITADO_ORIGEN'::character varying NOT NULL,
    total_fojas integer DEFAULT 0 NOT NULL,
    importe_esperado numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    importe_pagado numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    registrado_por bigint,
    fecha_ingreso timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_conclusion timestamp(0) without time zone,
    CONSTRAINT ck_tramite_pais_exterior CHECK ((((destino)::text IS DISTINCT FROM 'EXTERIOR'::text) OR (pais_destino IS NOT NULL))),
    CONSTRAINT tramite_destino_check CHECK (((destino)::text = ANY ((ARRAY['NACIONAL'::character varying, 'EXTERIOR'::character varying])::text[]))),
    CONSTRAINT tramite_estado_check CHECK (((estado)::text = ANY ((ARRAY['SOLICITADO_ORIGEN'::character varying, 'EN_FIRMA_ORIGEN'::character varying, 'INGRESADO'::character varying, 'EN_REVISION'::character varying, 'OBSERVADO'::character varying, 'EN_BANDEJA_DESPACHO'::character varying, 'FIRMADO_DIGITALMENTE'::character varying, 'DERIVADO_RECTORADO'::character varying, 'CONCLUIDO'::character varying, 'RECHAZADO'::character varying])::text[]))),
    CONSTRAINT tramite_prioridad_check CHECK (((prioridad)::text = ANY ((ARRAY['NORMAL'::character varying, 'ALTA'::character varying, 'URGENTE'::character varying])::text[])))
);


--
-- Name: tramite_documento; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tramite_documento (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    catalogo_documento_id bigint NOT NULL,
    cantidad_paginas integer NOT NULL,
    cantidad_materias integer,
    emitido_por_uagrm boolean DEFAULT true NOT NULL,
    archivo_ruta character varying(255),
    folio_inicio integer,
    folio_fin integer,
    firmas_origen_ok boolean DEFAULT false NOT NULL,
    estado character varying(255) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    CONSTRAINT ck_tdoc_materias CHECK ((cantidad_materias >= 0)),
    CONSTRAINT ck_tdoc_paginas CHECK ((cantidad_paginas > 0)),
    CONSTRAINT tramite_documento_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_FIRMA'::character varying, 'VALIDO'::character varying, 'OBSERVADO'::character varying, 'RECHAZADO'::character varying])::text[])))
);


--
-- Name: tramite_documento_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tramite_documento_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tramite_documento_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tramite_documento_id_seq OWNED BY public.tramite_documento.id;


--
-- Name: tramite_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tramite_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tramite_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tramite_id_seq OWNED BY public.tramite.id;


--
-- Name: tramite_requisito; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tramite_requisito (
    tramite_id bigint NOT NULL,
    requisito_id bigint NOT NULL,
    cumplido boolean DEFAULT false NOT NULL,
    verificado_por bigint,
    fecha_verificacion timestamp(0) without time zone
);


--
-- Name: tramite_tiempo_fase; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tramite_tiempo_fase (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    estado character varying(25) NOT NULL,
    fecha_inicio timestamp(0) without time zone NOT NULL,
    fecha_fin timestamp(0) without time zone,
    horas_habiles numeric(8,2),
    responsable_id bigint
);


--
-- Name: tramite_tiempo_fase_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tramite_tiempo_fase_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tramite_tiempo_fase_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tramite_tiempo_fase_id_seq OWNED BY public.tramite_tiempo_fase.id;


--
-- Name: transaccion_pago; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transaccion_pago (
    id bigint NOT NULL,
    tramite_id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    metodo_pago_id bigint,
    comprobante_caja_id bigint,
    stripe_payment_intent_id character varying(100) NOT NULL,
    monto numeric(10,2) NOT NULL,
    moneda character(3) NOT NULL,
    estado character varying(255) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    mensaje_error character varying(255),
    recibo_url character varying(500),
    fecha_creacion timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_pago timestamp(0) without time zone,
    CONSTRAINT ck_transaccion_monto CHECK ((monto > (0)::numeric)),
    CONSTRAINT transaccion_pago_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'REQUIERE_ACCION'::character varying, 'EXITOSO'::character varying, 'FALLIDO'::character varying, 'CANCELADO'::character varying])::text[])))
);


--
-- Name: transaccion_pago_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.transaccion_pago_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: transaccion_pago_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.transaccion_pago_id_seq OWNED BY public.transaccion_pago.id;


--
-- Name: unidad_origen; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.unidad_origen (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(150) NOT NULL
);


--
-- Name: unidad_origen_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.unidad_origen_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: unidad_origen_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.unidad_origen_id_seq OWNED BY public.unidad_origen.id;


--
-- Name: usuario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuario (
    id bigint NOT NULL,
    nombres character varying(100) NOT NULL,
    apellidos character varying(100) NOT NULL,
    ci character varying(20) NOT NULL,
    registro character varying(20),
    email character varying(150) NOT NULL,
    telefono character varying(30),
    password_hash character varying(255) NOT NULL,
    cargo character varying(120),
    activo boolean DEFAULT true NOT NULL,
    fecha_creacion timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    stripe_customer_id character varying(100)
);


--
-- Name: usuario_carrera; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuario_carrera (
    usuario_id bigint NOT NULL,
    carrera_id bigint NOT NULL,
    fecha_ingreso date DEFAULT CURRENT_DATE NOT NULL
);


--
-- Name: usuario_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usuario_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuario_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usuario_id_seq OWNED BY public.usuario.id;


--
-- Name: usuario_rol; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuario_rol (
    usuario_id bigint NOT NULL,
    rol_id bigint NOT NULL
);


--
-- Name: v_semaforo_sla; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_semaforo_sla AS
 WITH consumo AS (
         SELECT f.tramite_id,
            sum(
                CASE
                    WHEN ((f.estado)::text <> ALL ((ARRAY['SOLICITADO_ORIGEN'::character varying, 'EN_FIRMA_ORIGEN'::character varying, 'OBSERVADO'::character varying, 'CONCLUIDO'::character varying, 'RECHAZADO'::character varying])::text[])) THEN COALESCE(f.horas_habiles, public.fn_horas_habiles(f.fecha_inicio, LOCALTIMESTAMP))
                    ELSE (0)::numeric
                END) AS horas
           FROM public.tramite_tiempo_fase f
          GROUP BY f.tramite_id
        )
 SELECT t.id AS tramite_id,
    t.codigo,
    t.estado,
    ((t.estado)::text = ANY ((ARRAY['CONCLUIDO'::character varying, 'RECHAZADO'::character varying])::text[])) AS es_final,
    t.prioridad,
    t.fecha_ingreso,
    COALESCE(c.horas, (0)::numeric) AS horas_consumidas,
    s.plazo_horas,
    round(((100.0 * COALESCE(c.horas, (0)::numeric)) / s.plazo_horas), 1) AS porcentaje,
        CASE
            WHEN (COALESCE(c.horas, (0)::numeric) >= s.plazo_horas) THEN 'ROJO'::text
            WHEN (COALESCE(c.horas, (0)::numeric) >= ((s.plazo_horas * s.porcentaje_alerta) / (100)::numeric)) THEN 'AMARILLO'::text
            ELSE 'VERDE'::text
        END AS semaforo
   FROM ((public.tramite t
     LEFT JOIN consumo c ON ((c.tramite_id = t.id)))
     CROSS JOIN LATERAL ( SELECT sc.plazo_horas,
            sc.porcentaje_alerta
           FROM public.sla_config sc
          WHERE ((sc.tipo_tramite_id = t.tipo_tramite_id) OR (sc.tipo_tramite_id IS NULL))
          ORDER BY sc.tipo_tramite_id
         LIMIT 1) s);


--
-- Name: v_bandeja_despacho; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_bandeja_despacho AS
 SELECT t.id AS tramite_id,
    t.codigo,
    tt.nombre AS tipo_tramite,
    COALESCE(t.destino, '-'::character varying) AS destino,
    t.pais_destino,
    t.institucion_destino,
    (((u.nombres)::text || ' '::text) || (u.apellidos)::text) AS solicitante,
    u.registro,
    c.nombre AS carrera,
    f.nombre AS facultad,
    t.prioridad,
    t.total_fojas,
    ex.id AS expediente_id,
    ex.ruta_pdf,
    s.horas_consumidas,
    s.semaforo,
    t.fecha_ingreso
   FROM ((((((public.tramite t
     JOIN public.tipo_tramite tt ON ((tt.id = t.tipo_tramite_id)))
     JOIN public.usuario u ON ((u.id = t.solicitante_id)))
     JOIN public.carrera c ON ((c.id = t.carrera_id)))
     JOIN public.facultad f ON ((f.id = c.facultad_id)))
     LEFT JOIN public.expediente ex ON (((ex.tramite_id = t.id) AND ex.vigente)))
     JOIN public.v_semaforo_sla s ON ((s.tramite_id = t.id)))
  WHERE ((t.estado)::text = 'EN_BANDEJA_DESPACHO'::text);


--
-- Name: alerta_sla id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerta_sla ALTER COLUMN id SET DEFAULT nextval('public.alerta_sla_id_seq'::regclass);


--
-- Name: bitacora id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora ALTER COLUMN id SET DEFAULT nextval('public.bitacora_id_seq'::regclass);


--
-- Name: bitacora_acceso id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora_acceso ALTER COLUMN id SET DEFAULT nextval('public.bitacora_acceso_id_seq'::regclass);


--
-- Name: carrera id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carrera ALTER COLUMN id SET DEFAULT nextval('public.carrera_id_seq'::regclass);


--
-- Name: catalogo_documento id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_documento ALTER COLUMN id SET DEFAULT nextval('public.catalogo_documento_id_seq'::regclass);


--
-- Name: catalogo_firma_orden id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_firma_orden ALTER COLUMN id SET DEFAULT nextval('public.catalogo_firma_orden_id_seq'::regclass);


--
-- Name: certificado_digital id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_digital ALTER COLUMN id SET DEFAULT nextval('public.certificado_digital_id_seq'::regclass);


--
-- Name: certificado_oficial id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial ALTER COLUMN id SET DEFAULT nextval('public.certificado_oficial_id_seq'::regclass);


--
-- Name: comprobante_caja id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_caja ALTER COLUMN id SET DEFAULT nextval('public.comprobante_caja_id_seq'::regclass);


--
-- Name: contador_visita id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contador_visita ALTER COLUMN id SET DEFAULT nextval('public.contador_visita_id_seq'::regclass);


--
-- Name: delegacion_autoridad id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delegacion_autoridad ALTER COLUMN id SET DEFAULT nextval('public.delegacion_autoridad_id_seq'::regclass);


--
-- Name: expediente id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expediente ALTER COLUMN id SET DEFAULT nextval('public.expediente_id_seq'::regclass);


--
-- Name: facultad id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facultad ALTER COLUMN id SET DEFAULT nextval('public.facultad_id_seq'::regclass);


--
-- Name: firma_digital id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital ALTER COLUMN id SET DEFAULT nextval('public.firma_digital_id_seq'::regclass);


--
-- Name: flujo_paso id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.flujo_paso ALTER COLUMN id SET DEFAULT nextval('public.flujo_paso_id_seq'::regclass);


--
-- Name: lote_firma id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lote_firma ALTER COLUMN id SET DEFAULT nextval('public.lote_firma_id_seq'::regclass);


--
-- Name: menu_opcion id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.menu_opcion ALTER COLUMN id SET DEFAULT nextval('public.menu_opcion_id_seq'::regclass);


--
-- Name: metodo_pago_usuario id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metodo_pago_usuario ALTER COLUMN id SET DEFAULT nextval('public.metodo_pago_usuario_id_seq'::regclass);


--
-- Name: notificacion id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacion ALTER COLUMN id SET DEFAULT nextval('public.notificacion_id_seq'::regclass);


--
-- Name: observacion id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.observacion ALTER COLUMN id SET DEFAULT nextval('public.observacion_id_seq'::regclass);


--
-- Name: permiso id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permiso ALTER COLUMN id SET DEFAULT nextval('public.permiso_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: requisito id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requisito ALTER COLUMN id SET DEFAULT nextval('public.requisito_id_seq'::regclass);


--
-- Name: rol id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol ALTER COLUMN id SET DEFAULT nextval('public.rol_id_seq'::regclass);


--
-- Name: sla_config id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sla_config ALTER COLUMN id SET DEFAULT nextval('public.sla_config_id_seq'::regclass);


--
-- Name: solicitud_descuento id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solicitud_descuento ALTER COLUMN id SET DEFAULT nextval('public.solicitud_descuento_id_seq'::regclass);


--
-- Name: tipo_tramite id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipo_tramite ALTER COLUMN id SET DEFAULT nextval('public.tipo_tramite_id_seq'::regclass);


--
-- Name: tramite id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite ALTER COLUMN id SET DEFAULT nextval('public.tramite_id_seq'::regclass);


--
-- Name: tramite_documento id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_documento ALTER COLUMN id SET DEFAULT nextval('public.tramite_documento_id_seq'::regclass);


--
-- Name: tramite_tiempo_fase id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_tiempo_fase ALTER COLUMN id SET DEFAULT nextval('public.tramite_tiempo_fase_id_seq'::regclass);


--
-- Name: transaccion_pago id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago ALTER COLUMN id SET DEFAULT nextval('public.transaccion_pago_id_seq'::regclass);


--
-- Name: unidad_origen id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidad_origen ALTER COLUMN id SET DEFAULT nextval('public.unidad_origen_id_seq'::regclass);


--
-- Name: usuario id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario ALTER COLUMN id SET DEFAULT nextval('public.usuario_id_seq'::regclass);


--
-- Name: alerta_sla alerta_sla_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerta_sla
    ADD CONSTRAINT alerta_sla_pkey PRIMARY KEY (id);


--
-- Name: alerta_sla alerta_sla_tramite_id_tipo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerta_sla
    ADD CONSTRAINT alerta_sla_tramite_id_tipo_unique UNIQUE (tramite_id, tipo);


--
-- Name: bitacora_acceso bitacora_acceso_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora_acceso
    ADD CONSTRAINT bitacora_acceso_pkey PRIMARY KEY (id);


--
-- Name: bitacora bitacora_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_pkey PRIMARY KEY (id);


--
-- Name: carrera carrera_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carrera
    ADD CONSTRAINT carrera_codigo_unique UNIQUE (codigo);


--
-- Name: carrera carrera_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carrera
    ADD CONSTRAINT carrera_pkey PRIMARY KEY (id);


--
-- Name: catalogo_documento catalogo_documento_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_documento
    ADD CONSTRAINT catalogo_documento_codigo_unique UNIQUE (codigo);


--
-- Name: catalogo_documento catalogo_documento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_documento
    ADD CONSTRAINT catalogo_documento_pkey PRIMARY KEY (id);


--
-- Name: catalogo_firma_orden catalogo_firma_orden_catalogo_documento_id_orden_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_firma_orden
    ADD CONSTRAINT catalogo_firma_orden_catalogo_documento_id_orden_unique UNIQUE (catalogo_documento_id, orden);


--
-- Name: catalogo_firma_orden catalogo_firma_orden_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_firma_orden
    ADD CONSTRAINT catalogo_firma_orden_pkey PRIMARY KEY (id);


--
-- Name: certificado_digital certificado_digital_numero_serie_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_digital
    ADD CONSTRAINT certificado_digital_numero_serie_unique UNIQUE (numero_serie);


--
-- Name: certificado_digital certificado_digital_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_digital
    ADD CONSTRAINT certificado_digital_pkey PRIMARY KEY (id);


--
-- Name: certificado_oficial certificado_oficial_codigo_verificacion_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial
    ADD CONSTRAINT certificado_oficial_codigo_verificacion_unique UNIQUE (codigo_verificacion);


--
-- Name: certificado_oficial certificado_oficial_numero_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial
    ADD CONSTRAINT certificado_oficial_numero_unique UNIQUE (numero);


--
-- Name: certificado_oficial certificado_oficial_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial
    ADD CONSTRAINT certificado_oficial_pkey PRIMARY KEY (id);


--
-- Name: comprobante_caja comprobante_caja_codigo_recibo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_caja
    ADD CONSTRAINT comprobante_caja_codigo_recibo_unique UNIQUE (codigo_recibo);


--
-- Name: comprobante_caja comprobante_caja_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_caja
    ADD CONSTRAINT comprobante_caja_pkey PRIMARY KEY (id);


--
-- Name: contador_visita contador_visita_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contador_visita
    ADD CONSTRAINT contador_visita_pkey PRIMARY KEY (id);


--
-- Name: contador_visita contador_visita_ruta_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contador_visita
    ADD CONSTRAINT contador_visita_ruta_unique UNIQUE (ruta);


--
-- Name: delegacion_autoridad delegacion_autoridad_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delegacion_autoridad
    ADD CONSTRAINT delegacion_autoridad_pkey PRIMARY KEY (id);


--
-- Name: expediente expediente_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expediente
    ADD CONSTRAINT expediente_pkey PRIMARY KEY (id);


--
-- Name: expediente expediente_tramite_id_version_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expediente
    ADD CONSTRAINT expediente_tramite_id_version_unique UNIQUE (tramite_id, version);


--
-- Name: facultad facultad_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facultad
    ADD CONSTRAINT facultad_codigo_unique UNIQUE (codigo);


--
-- Name: facultad facultad_orden_prelacion_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facultad
    ADD CONSTRAINT facultad_orden_prelacion_unique UNIQUE (orden_prelacion);


--
-- Name: facultad facultad_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facultad
    ADD CONSTRAINT facultad_pkey PRIMARY KEY (id);


--
-- Name: feriado feriado_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriado
    ADD CONSTRAINT feriado_pkey PRIMARY KEY (fecha);


--
-- Name: firma_digital firma_digital_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital
    ADD CONSTRAINT firma_digital_pkey PRIMARY KEY (id);


--
-- Name: flujo_paso flujo_paso_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.flujo_paso
    ADD CONSTRAINT flujo_paso_pkey PRIMARY KEY (id);


--
-- Name: flujo_paso flujo_paso_tipo_tramite_id_orden_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.flujo_paso
    ADD CONSTRAINT flujo_paso_tipo_tramite_id_orden_unique UNIQUE (tipo_tramite_id, orden);


--
-- Name: lote_firma lote_firma_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lote_firma
    ADD CONSTRAINT lote_firma_pkey PRIMARY KEY (id);


--
-- Name: menu_opcion menu_opcion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.menu_opcion
    ADD CONSTRAINT menu_opcion_pkey PRIMARY KEY (id);


--
-- Name: metodo_pago_usuario metodo_pago_usuario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metodo_pago_usuario
    ADD CONSTRAINT metodo_pago_usuario_pkey PRIMARY KEY (id);


--
-- Name: metodo_pago_usuario metodo_pago_usuario_stripe_payment_method_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metodo_pago_usuario
    ADD CONSTRAINT metodo_pago_usuario_stripe_payment_method_id_unique UNIQUE (stripe_payment_method_id);


--
-- Name: notificacion notificacion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacion
    ADD CONSTRAINT notificacion_pkey PRIMARY KEY (id);


--
-- Name: observacion observacion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.observacion
    ADD CONSTRAINT observacion_pkey PRIMARY KEY (id);


--
-- Name: permiso permiso_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permiso
    ADD CONSTRAINT permiso_codigo_unique UNIQUE (codigo);


--
-- Name: permiso permiso_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permiso
    ADD CONSTRAINT permiso_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: requisito requisito_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requisito
    ADD CONSTRAINT requisito_pkey PRIMARY KEY (id);


--
-- Name: requisito requisito_tipo_tramite_id_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requisito
    ADD CONSTRAINT requisito_tipo_tramite_id_codigo_unique UNIQUE (tipo_tramite_id, codigo);


--
-- Name: rol rol_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol
    ADD CONSTRAINT rol_codigo_unique UNIQUE (codigo);


--
-- Name: rol_permiso rol_permiso_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_pkey PRIMARY KEY (rol_id, permiso_id);


--
-- Name: rol rol_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol
    ADD CONSTRAINT rol_pkey PRIMARY KEY (id);


--
-- Name: sla_config sla_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sla_config
    ADD CONSTRAINT sla_config_pkey PRIMARY KEY (id);


--
-- Name: sla_config sla_config_tipo_tramite_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sla_config
    ADD CONSTRAINT sla_config_tipo_tramite_id_unique UNIQUE (tipo_tramite_id);


--
-- Name: solicitud_descuento solicitud_descuento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solicitud_descuento
    ADD CONSTRAINT solicitud_descuento_pkey PRIMARY KEY (id);


--
-- Name: solicitud_descuento solicitud_descuento_tramite_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solicitud_descuento
    ADD CONSTRAINT solicitud_descuento_tramite_id_unique UNIQUE (tramite_id);


--
-- Name: tipo_tramite tipo_tramite_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipo_tramite
    ADD CONSTRAINT tipo_tramite_codigo_unique UNIQUE (codigo);


--
-- Name: tipo_tramite tipo_tramite_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tipo_tramite
    ADD CONSTRAINT tipo_tramite_pkey PRIMARY KEY (id);


--
-- Name: tramite tramite_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_codigo_unique UNIQUE (codigo);


--
-- Name: tramite_documento tramite_documento_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_documento
    ADD CONSTRAINT tramite_documento_pkey PRIMARY KEY (id);


--
-- Name: tramite tramite_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_pkey PRIMARY KEY (id);


--
-- Name: tramite_requisito tramite_requisito_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_requisito
    ADD CONSTRAINT tramite_requisito_pkey PRIMARY KEY (tramite_id, requisito_id);


--
-- Name: tramite_tiempo_fase tramite_tiempo_fase_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_tiempo_fase
    ADD CONSTRAINT tramite_tiempo_fase_pkey PRIMARY KEY (id);


--
-- Name: transaccion_pago transaccion_pago_comprobante_caja_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_comprobante_caja_id_unique UNIQUE (comprobante_caja_id);


--
-- Name: transaccion_pago transaccion_pago_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_pkey PRIMARY KEY (id);


--
-- Name: transaccion_pago transaccion_pago_stripe_payment_intent_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_stripe_payment_intent_id_unique UNIQUE (stripe_payment_intent_id);


--
-- Name: unidad_origen unidad_origen_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidad_origen
    ADD CONSTRAINT unidad_origen_codigo_unique UNIQUE (codigo);


--
-- Name: unidad_origen unidad_origen_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.unidad_origen
    ADD CONSTRAINT unidad_origen_pkey PRIMARY KEY (id);


--
-- Name: usuario_carrera usuario_carrera_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_carrera
    ADD CONSTRAINT usuario_carrera_pkey PRIMARY KEY (usuario_id, carrera_id);


--
-- Name: usuario usuario_ci_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_ci_unique UNIQUE (ci);


--
-- Name: usuario usuario_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_email_unique UNIQUE (email);


--
-- Name: usuario usuario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_pkey PRIMARY KEY (id);


--
-- Name: usuario usuario_registro_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_registro_unique UNIQUE (registro);


--
-- Name: usuario_rol usuario_rol_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_rol
    ADD CONSTRAINT usuario_rol_pkey PRIMARY KEY (usuario_id, rol_id);


--
-- Name: usuario usuario_stripe_customer_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario
    ADD CONSTRAINT usuario_stripe_customer_id_unique UNIQUE (stripe_customer_id);


--
-- Name: bitacora_acceso_ruta_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_acceso_ruta_index ON public.bitacora_acceso USING btree (ruta);


--
-- Name: bitacora_acceso_usuario_id_fecha_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_acceso_usuario_id_fecha_index ON public.bitacora_acceso USING btree (usuario_id, fecha);


--
-- Name: bitacora_certificado_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_certificado_id_index ON public.bitacora USING btree (certificado_id);


--
-- Name: bitacora_delegacion_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_delegacion_id_index ON public.bitacora USING btree (delegacion_id);


--
-- Name: bitacora_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_tramite_id_index ON public.bitacora USING btree (tramite_id);


--
-- Name: bitacora_transaccion_pago_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX bitacora_transaccion_pago_id_index ON public.bitacora USING btree (transaccion_pago_id);


--
-- Name: comprobante_caja_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX comprobante_caja_tramite_id_index ON public.comprobante_caja USING btree (tramite_id);


--
-- Name: firma_digital_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX firma_digital_tramite_id_index ON public.firma_digital USING btree (tramite_id);


--
-- Name: metodo_pago_usuario_usuario_id_activo_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX metodo_pago_usuario_usuario_id_activo_index ON public.metodo_pago_usuario USING btree (usuario_id, activo);


--
-- Name: notificacion_usuario_id_leida_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notificacion_usuario_id_leida_index ON public.notificacion USING btree (usuario_id, leida);


--
-- Name: observacion_tramite_id_estado_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX observacion_tramite_id_estado_index ON public.observacion USING btree (tramite_id, estado);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: tramite_carrera_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tramite_carrera_id_index ON public.tramite USING btree (carrera_id);


--
-- Name: tramite_documento_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tramite_documento_tramite_id_index ON public.tramite_documento USING btree (tramite_id);


--
-- Name: tramite_estado_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tramite_estado_index ON public.tramite USING btree (estado);


--
-- Name: tramite_solicitante_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tramite_solicitante_id_index ON public.tramite USING btree (solicitante_id);


--
-- Name: tramite_tiempo_fase_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tramite_tiempo_fase_tramite_id_index ON public.tramite_tiempo_fase USING btree (tramite_id);


--
-- Name: transaccion_pago_tramite_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX transaccion_pago_tramite_id_index ON public.transaccion_pago USING btree (tramite_id);


--
-- Name: ux_transaccion_exitosa_por_tramite; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_transaccion_exitosa_por_tramite ON public.transaccion_pago USING btree (tramite_id) WHERE ((estado)::text = 'EXITOSO'::text);


--
-- Name: bitacora trg_bitacora_hash; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_bitacora_hash BEFORE INSERT ON public.bitacora FOR EACH ROW EXECUTE FUNCTION public.fn_bitacora_hash();


--
-- Name: bitacora trg_bitacora_no_modificar; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_bitacora_no_modificar BEFORE DELETE OR UPDATE ON public.bitacora FOR EACH ROW EXECUTE FUNCTION public.fn_bitacora_inmutable();


--
-- Name: bitacora trg_bitacora_no_truncar; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_bitacora_no_truncar BEFORE TRUNCATE ON public.bitacora FOR EACH STATEMENT EXECUTE FUNCTION public.fn_bitacora_inmutable();


--
-- Name: certificado_oficial trg_certificado; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_certificado BEFORE INSERT ON public.certificado_oficial FOR EACH ROW EXECUTE FUNCTION public.fn_trg_certificado();


--
-- Name: comprobante_caja trg_comprobante_antes_insert; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_comprobante_antes_insert BEFORE INSERT ON public.comprobante_caja FOR EACH ROW EXECUTE FUNCTION public.fn_trg_comprobante_antes_insert();


--
-- Name: comprobante_caja trg_comprobante_total; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_comprobante_total AFTER INSERT OR DELETE OR UPDATE ON public.comprobante_caja FOR EACH ROW EXECUTE FUNCTION public.fn_trg_comprobante_total();


--
-- Name: tramite_documento trg_documento_totales; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_documento_totales AFTER INSERT OR DELETE OR UPDATE ON public.tramite_documento FOR EACH ROW EXECUTE FUNCTION public.fn_trg_documento_totales();


--
-- Name: firma_digital trg_firma_origen_check; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_firma_origen_check AFTER INSERT ON public.firma_digital FOR EACH ROW EXECUTE FUNCTION public.fn_trg_firma_origen_check();


--
-- Name: lote_firma trg_lote_validar; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lote_validar BEFORE INSERT ON public.lote_firma FOR EACH ROW EXECUTE FUNCTION public.fn_trg_lote_validar();


--
-- Name: solicitud_descuento trg_solicitud_descuento; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_solicitud_descuento BEFORE INSERT OR UPDATE ON public.solicitud_descuento FOR EACH ROW EXECUTE FUNCTION public.fn_trg_solicitud_descuento();


--
-- Name: tramite trg_tramite_antes_insert; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tramite_antes_insert BEFORE INSERT ON public.tramite FOR EACH ROW EXECUTE FUNCTION public.fn_trg_tramite_antes_insert();


--
-- Name: tramite trg_tramite_despues_insert; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tramite_despues_insert AFTER INSERT ON public.tramite FOR EACH ROW EXECUTE FUNCTION public.fn_trg_tramite_despues_insert();


--
-- Name: tramite trg_tramite_post_estado; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tramite_post_estado AFTER INSERT OR UPDATE OF estado ON public.tramite FOR EACH ROW EXECUTE FUNCTION public.fn_trg_tramite_post_estado();


--
-- Name: tramite trg_tramite_validar_estado; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tramite_validar_estado BEFORE UPDATE OF estado ON public.tramite FOR EACH ROW EXECUTE FUNCTION public.fn_trg_tramite_validar_estado();


--
-- Name: alerta_sla alerta_sla_atendida_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerta_sla
    ADD CONSTRAINT alerta_sla_atendida_por_foreign FOREIGN KEY (atendida_por) REFERENCES public.usuario(id);


--
-- Name: alerta_sla alerta_sla_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerta_sla
    ADD CONSTRAINT alerta_sla_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: bitacora_acceso bitacora_acceso_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora_acceso
    ADD CONSTRAINT bitacora_acceso_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id) ON DELETE SET NULL;


--
-- Name: bitacora bitacora_certificado_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_certificado_id_foreign FOREIGN KEY (certificado_id) REFERENCES public.certificado_oficial(id);


--
-- Name: bitacora bitacora_delegacion_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_delegacion_id_foreign FOREIGN KEY (delegacion_id) REFERENCES public.delegacion_autoridad(id);


--
-- Name: bitacora bitacora_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id);


--
-- Name: bitacora bitacora_transaccion_pago_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_transaccion_pago_id_foreign FOREIGN KEY (transaccion_pago_id) REFERENCES public.transaccion_pago(id);


--
-- Name: bitacora bitacora_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bitacora
    ADD CONSTRAINT bitacora_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id);


--
-- Name: carrera carrera_facultad_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.carrera
    ADD CONSTRAINT carrera_facultad_id_foreign FOREIGN KEY (facultad_id) REFERENCES public.facultad(id);


--
-- Name: catalogo_documento catalogo_documento_unidad_emisora_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_documento
    ADD CONSTRAINT catalogo_documento_unidad_emisora_id_foreign FOREIGN KEY (unidad_emisora_id) REFERENCES public.unidad_origen(id);


--
-- Name: catalogo_firma_orden catalogo_firma_orden_catalogo_documento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_firma_orden
    ADD CONSTRAINT catalogo_firma_orden_catalogo_documento_id_foreign FOREIGN KEY (catalogo_documento_id) REFERENCES public.catalogo_documento(id) ON DELETE CASCADE;


--
-- Name: catalogo_firma_orden catalogo_firma_orden_unidad_origen_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogo_firma_orden
    ADD CONSTRAINT catalogo_firma_orden_unidad_origen_id_foreign FOREIGN KEY (unidad_origen_id) REFERENCES public.unidad_origen(id);


--
-- Name: certificado_digital certificado_digital_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_digital
    ADD CONSTRAINT certificado_digital_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id);


--
-- Name: certificado_oficial certificado_oficial_firma_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial
    ADD CONSTRAINT certificado_oficial_firma_id_foreign FOREIGN KEY (firma_id) REFERENCES public.firma_digital(id);


--
-- Name: certificado_oficial certificado_oficial_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.certificado_oficial
    ADD CONSTRAINT certificado_oficial_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id);


--
-- Name: comprobante_caja comprobante_caja_registrado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_caja
    ADD CONSTRAINT comprobante_caja_registrado_por_foreign FOREIGN KEY (registrado_por) REFERENCES public.usuario(id);


--
-- Name: comprobante_caja comprobante_caja_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobante_caja
    ADD CONSTRAINT comprobante_caja_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: contador_visita contador_visita_menu_opcion_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contador_visita
    ADD CONSTRAINT contador_visita_menu_opcion_id_foreign FOREIGN KEY (menu_opcion_id) REFERENCES public.menu_opcion(id) ON DELETE SET NULL;


--
-- Name: delegacion_autoridad delegacion_autoridad_registrado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delegacion_autoridad
    ADD CONSTRAINT delegacion_autoridad_registrado_por_foreign FOREIGN KEY (registrado_por) REFERENCES public.usuario(id);


--
-- Name: delegacion_autoridad delegacion_autoridad_suplente_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delegacion_autoridad
    ADD CONSTRAINT delegacion_autoridad_suplente_id_foreign FOREIGN KEY (suplente_id) REFERENCES public.usuario(id);


--
-- Name: delegacion_autoridad delegacion_autoridad_titular_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.delegacion_autoridad
    ADD CONSTRAINT delegacion_autoridad_titular_id_foreign FOREIGN KEY (titular_id) REFERENCES public.usuario(id);


--
-- Name: expediente expediente_compilado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expediente
    ADD CONSTRAINT expediente_compilado_por_foreign FOREIGN KEY (compilado_por) REFERENCES public.usuario(id);


--
-- Name: expediente expediente_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.expediente
    ADD CONSTRAINT expediente_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: feriado feriado_registrado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriado
    ADD CONSTRAINT feriado_registrado_por_foreign FOREIGN KEY (registrado_por) REFERENCES public.usuario(id) ON DELETE SET NULL;


--
-- Name: feriado feriado_sla_config_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feriado
    ADD CONSTRAINT feriado_sla_config_id_foreign FOREIGN KEY (sla_config_id) REFERENCES public.sla_config(id) ON DELETE CASCADE;


--
-- Name: firma_digital firma_digital_expediente_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital
    ADD CONSTRAINT firma_digital_expediente_id_foreign FOREIGN KEY (expediente_id) REFERENCES public.expediente(id) ON DELETE CASCADE;


--
-- Name: firma_digital firma_digital_lote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital
    ADD CONSTRAINT firma_digital_lote_id_foreign FOREIGN KEY (lote_id) REFERENCES public.lote_firma(id);


--
-- Name: firma_digital firma_digital_tramite_documento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital
    ADD CONSTRAINT firma_digital_tramite_documento_id_foreign FOREIGN KEY (tramite_documento_id) REFERENCES public.tramite_documento(id) ON DELETE CASCADE;


--
-- Name: firma_digital firma_digital_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.firma_digital
    ADD CONSTRAINT firma_digital_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: flujo_paso flujo_paso_tipo_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.flujo_paso
    ADD CONSTRAINT flujo_paso_tipo_tramite_id_foreign FOREIGN KEY (tipo_tramite_id) REFERENCES public.tipo_tramite(id);


--
-- Name: flujo_paso flujo_paso_unidad_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.flujo_paso
    ADD CONSTRAINT flujo_paso_unidad_id_foreign FOREIGN KEY (unidad_id) REFERENCES public.unidad_origen(id);


--
-- Name: lote_firma lote_firma_certificado_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lote_firma
    ADD CONSTRAINT lote_firma_certificado_id_foreign FOREIGN KEY (certificado_id) REFERENCES public.certificado_digital(id);


--
-- Name: lote_firma lote_firma_delegacion_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lote_firma
    ADD CONSTRAINT lote_firma_delegacion_id_foreign FOREIGN KEY (delegacion_id) REFERENCES public.delegacion_autoridad(id);


--
-- Name: lote_firma lote_firma_firmante_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lote_firma
    ADD CONSTRAINT lote_firma_firmante_id_foreign FOREIGN KEY (firmante_id) REFERENCES public.usuario(id);


--
-- Name: menu_opcion menu_opcion_padre_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.menu_opcion
    ADD CONSTRAINT menu_opcion_padre_id_foreign FOREIGN KEY (padre_id) REFERENCES public.menu_opcion(id) ON DELETE CASCADE;


--
-- Name: menu_opcion menu_opcion_permiso_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.menu_opcion
    ADD CONSTRAINT menu_opcion_permiso_id_foreign FOREIGN KEY (permiso_id) REFERENCES public.permiso(id) ON DELETE SET NULL;


--
-- Name: metodo_pago_usuario metodo_pago_usuario_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.metodo_pago_usuario
    ADD CONSTRAINT metodo_pago_usuario_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id) ON DELETE CASCADE;


--
-- Name: notificacion notificacion_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacion
    ADD CONSTRAINT notificacion_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: notificacion notificacion_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificacion
    ADD CONSTRAINT notificacion_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id) ON DELETE CASCADE;


--
-- Name: observacion observacion_registrado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.observacion
    ADD CONSTRAINT observacion_registrado_por_foreign FOREIGN KEY (registrado_por) REFERENCES public.usuario(id);


--
-- Name: observacion observacion_tramite_documento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.observacion
    ADD CONSTRAINT observacion_tramite_documento_id_foreign FOREIGN KEY (tramite_documento_id) REFERENCES public.tramite_documento(id) ON DELETE SET NULL;


--
-- Name: observacion observacion_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.observacion
    ADD CONSTRAINT observacion_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: personal_access_tokens personal_access_tokens_tokenable_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_tokenable_id_foreign FOREIGN KEY (tokenable_id) REFERENCES public.usuario(id) ON DELETE CASCADE;


--
-- Name: requisito requisito_tipo_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requisito
    ADD CONSTRAINT requisito_tipo_tramite_id_foreign FOREIGN KEY (tipo_tramite_id) REFERENCES public.tipo_tramite(id);


--
-- Name: rol_permiso rol_permiso_permiso_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_permiso_id_foreign FOREIGN KEY (permiso_id) REFERENCES public.permiso(id) ON DELETE CASCADE;


--
-- Name: rol_permiso rol_permiso_rol_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_rol_id_foreign FOREIGN KEY (rol_id) REFERENCES public.rol(id) ON DELETE CASCADE;


--
-- Name: sla_config sla_config_tipo_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sla_config
    ADD CONSTRAINT sla_config_tipo_tramite_id_foreign FOREIGN KEY (tipo_tramite_id) REFERENCES public.tipo_tramite(id);


--
-- Name: solicitud_descuento solicitud_descuento_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solicitud_descuento
    ADD CONSTRAINT solicitud_descuento_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: tramite tramite_carrera_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_carrera_id_foreign FOREIGN KEY (carrera_id) REFERENCES public.carrera(id);


--
-- Name: tramite_documento tramite_documento_catalogo_documento_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_documento
    ADD CONSTRAINT tramite_documento_catalogo_documento_id_foreign FOREIGN KEY (catalogo_documento_id) REFERENCES public.catalogo_documento(id);


--
-- Name: tramite_documento tramite_documento_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_documento
    ADD CONSTRAINT tramite_documento_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: tramite tramite_registrado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_registrado_por_foreign FOREIGN KEY (registrado_por) REFERENCES public.usuario(id);


--
-- Name: tramite_requisito tramite_requisito_requisito_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_requisito
    ADD CONSTRAINT tramite_requisito_requisito_id_foreign FOREIGN KEY (requisito_id) REFERENCES public.requisito(id);


--
-- Name: tramite_requisito tramite_requisito_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_requisito
    ADD CONSTRAINT tramite_requisito_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: tramite_requisito tramite_requisito_verificado_por_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_requisito
    ADD CONSTRAINT tramite_requisito_verificado_por_foreign FOREIGN KEY (verificado_por) REFERENCES public.usuario(id);


--
-- Name: tramite tramite_solicitante_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_solicitante_id_foreign FOREIGN KEY (solicitante_id) REFERENCES public.usuario(id);


--
-- Name: tramite_tiempo_fase tramite_tiempo_fase_responsable_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_tiempo_fase
    ADD CONSTRAINT tramite_tiempo_fase_responsable_id_foreign FOREIGN KEY (responsable_id) REFERENCES public.usuario(id);


--
-- Name: tramite_tiempo_fase tramite_tiempo_fase_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite_tiempo_fase
    ADD CONSTRAINT tramite_tiempo_fase_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id) ON DELETE CASCADE;


--
-- Name: tramite tramite_tipo_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tramite
    ADD CONSTRAINT tramite_tipo_tramite_id_foreign FOREIGN KEY (tipo_tramite_id) REFERENCES public.tipo_tramite(id);


--
-- Name: transaccion_pago transaccion_pago_comprobante_caja_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_comprobante_caja_id_foreign FOREIGN KEY (comprobante_caja_id) REFERENCES public.comprobante_caja(id) ON DELETE SET NULL;


--
-- Name: transaccion_pago transaccion_pago_metodo_pago_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_metodo_pago_id_foreign FOREIGN KEY (metodo_pago_id) REFERENCES public.metodo_pago_usuario(id) ON DELETE SET NULL;


--
-- Name: transaccion_pago transaccion_pago_tramite_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_tramite_id_foreign FOREIGN KEY (tramite_id) REFERENCES public.tramite(id);


--
-- Name: transaccion_pago transaccion_pago_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transaccion_pago
    ADD CONSTRAINT transaccion_pago_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id);


--
-- Name: usuario_carrera usuario_carrera_carrera_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_carrera
    ADD CONSTRAINT usuario_carrera_carrera_id_foreign FOREIGN KEY (carrera_id) REFERENCES public.carrera(id) ON DELETE CASCADE;


--
-- Name: usuario_carrera usuario_carrera_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_carrera
    ADD CONSTRAINT usuario_carrera_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id) ON DELETE CASCADE;


--
-- Name: usuario_rol usuario_rol_rol_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_rol
    ADD CONSTRAINT usuario_rol_rol_id_foreign FOREIGN KEY (rol_id) REFERENCES public.rol(id) ON DELETE CASCADE;


--
-- Name: usuario_rol usuario_rol_usuario_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuario_rol
    ADD CONSTRAINT usuario_rol_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES public.usuario(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict j7SpdFf3BvUgGXzvJUaKxRMb01L1fRJoQc6QIa8RCtqqRaM8eZjGWc8vLyzw1tA


--
-- Facultades de la UAGRM en orden de prelación institucional (antigüedad histórica y normativa
-- estatutaria). orden_prelacion = 1 es la más antigua: su Decano es el suplente natural del Vicerrector.
--

INSERT INTO public.facultad (codigo, nombre, anio_creacion, orden_prelacion) VALUES
    ('FCJPSRI', 'Facultad de Ciencias Jurídicas, Políticas, Sociales y Relaciones Internacionales', 1879, 1),
    ('FCEE', 'Facultad de Ciencias Económicas y Empresariales', 1939, 2),
    ('FCET', 'Facultad de Ciencias Exactas y Tecnología', 1940, 3),
    ('FCA', 'Facultad de Ciencias Agrícolas', 1940, 4),
    ('FCV', 'Facultad de Ciencias Veterinarias', 1940, 5),
    ('FH', 'Facultad de Humanidades', 1973, 6),
    ('FCSH', 'Facultad de Ciencias de la Salud Humana', 1979, 7),
    ('FCAPU', 'Facultad de Ciencias del Hábitat, Diseño y Arte', 1983, 8),
    ('FCAF', 'Facultad de Auditoría Financiera o Contaduría Pública', 1995, 9),
    ('FP', 'Facultad Politécnica', 1995, 10),
    ('FINOR', 'Facultad Integral del Norte (Montero)', 2004, 11),
    ('FICH', 'Facultad Integral del Chaco (Camiri)', 2004, 12),
    ('FICHT', 'Facultad Integral Chiquitana (San Ignacio de Velasco)', 2006, 13),
    ('FIV', 'Facultad Integral de los Valles Cruceños (Vallegrande)', 2008, 14),
    ('FICCT', 'Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones', 2011, 15),
    ('FCF', 'Facultad de Ciencias Farmacéuticas y Bioquímicas', 2012, 16),
    ('FII', 'Facultad Integral de Ichilo (Yapacaní)', 2012, 17),
    ('FINI', 'Facultad Integral del Noreste (San Julián)', 2015, 18);
