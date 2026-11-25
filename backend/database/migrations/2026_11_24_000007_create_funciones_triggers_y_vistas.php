<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas que viven en PostgreSQL: correlativos, bitácora inalterable con cadena SHA-256, horas hábiles,
 * máquina de estados del trámite, totales y validaciones de firma, certificado con QR y vistas operativas.
 * No requiere extensiones: sha256() y gen_random_uuid() son nativas desde PostgreSQL 13.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SEQUENCE IF NOT EXISTS seq_tramite;
            CREATE SEQUENCE IF NOT EXISTS seq_certificado;

            -- Usuario de la petición (lo fija el middleware SetPostgresAuditUser)
            CREATE OR REPLACE FUNCTION fn_usuario_actual() RETURNS BIGINT LANGUAGE sql STABLE AS $$
                SELECT NULLIF(current_setting('app.usuario_id', true), '')::BIGINT;
            $$;

            -- -----------------------------------------------------------------
            -- Bitácora inalterable con cadena de hash SHA-256
            -- -----------------------------------------------------------------
            -- Cada evento referencia con FK explícitas lo que afectó (trámite y, según la acción, la delegación,
            -- el certificado oficial o el pago en línea). plpgsql: transaccion_pago_id se crea en la migración de pagos.
            CREATE OR REPLACE FUNCTION fn_registrar_bitacora(p_tramite BIGINT, p_accion TEXT, p_detalle JSONB,
                                                             p_delegacion BIGINT DEFAULT NULL,
                                                             p_certificado BIGINT DEFAULT NULL,
                                                             p_transaccion_pago BIGINT DEFAULT NULL)
            RETURNS VOID LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO bitacora(tramite_id, usuario_id, accion, delegacion_id, certificado_id, transaccion_pago_id,
                                     detalle, hash_anterior, hash_actual)
                VALUES (p_tramite, fn_usuario_actual(), p_accion, p_delegacion, p_certificado, p_transaccion_pago,
                        p_detalle, '', '');
            END $$;

            CREATE OR REPLACE FUNCTION fn_bitacora_hash() RETURNS TRIGGER LANGUAGE plpgsql AS $$
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
            CREATE TRIGGER trg_bitacora_hash BEFORE INSERT ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_hash();

            CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La bitácora es inalterable: no se permite %', TG_OP;
            END $$;
            CREATE TRIGGER trg_bitacora_no_modificar BEFORE UPDATE OR DELETE ON bitacora
                FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
            CREATE TRIGGER trg_bitacora_no_truncar BEFORE TRUNCATE ON bitacora
                FOR EACH STATEMENT EXECUTE FUNCTION fn_bitacora_inmutable();

            -- -----------------------------------------------------------------
            -- Funciones de cálculo
            -- -----------------------------------------------------------------
            CREATE OR REPLACE FUNCTION fn_horas_habiles(p_ini TIMESTAMP, p_fin TIMESTAMP)
            RETURNS NUMERIC LANGUAGE sql STABLE AS $$
                SELECT COALESCE(ROUND(SUM(EXTRACT(EPOCH FROM (LEAST(p_fin, d.dia + INTERVAL '1 day')
                                                              - GREATEST(p_ini, d.dia)))::NUMERIC) / 3600.0, 2), 0)
                  FROM generate_series(date_trunc('day', p_ini), date_trunc('day', p_fin), INTERVAL '1 day') AS d(dia)
                 WHERE p_fin > p_ini
                   AND EXTRACT(ISODOW FROM d.dia) < 6
                   AND NOT EXISTS (SELECT 1 FROM feriado f WHERE f.fecha = d.dia::DATE);
            $$;

            -- Vicerrector titular o el suplente con delegación activa
            CREATE OR REPLACE FUNCTION fn_autoridad_vigente(p_fecha DATE DEFAULT CURRENT_DATE)
            RETURNS BIGINT LANGUAGE sql STABLE AS $$
                SELECT COALESCE(
                    (SELECT suplente_id FROM delegacion_autoridad
                      WHERE estado = 'ACTIVA' AND p_fecha BETWEEN fecha_inicio AND fecha_fin
                      ORDER BY fecha_registro DESC LIMIT 1),
                    (SELECT u.id FROM usuario u JOIN usuario_rol ur ON ur.usuario_id = u.id JOIN rol r ON r.id = ur.rol_id
                      WHERE r.codigo = 'VICERRECTOR' AND u.activo ORDER BY u.id LIMIT 1));
            $$;

            CREATE OR REPLACE FUNCTION fn_importe_esperado(p_tramite BIGINT) RETURNS NUMERIC LANGUAGE sql STABLE AS $$
                SELECT COALESCE(SUM(c.tarifa * CASE c.tipo_cobro WHEN 'FOJA'    THEN td.cantidad_paginas
                                                                 WHEN 'MATERIA' THEN COALESCE(td.cantidad_materias, 0)
                                                                 ELSE 1 END), 0)
                  FROM tramite_documento td JOIN catalogo_documento c ON c.id = td.catalogo_documento_id
                 WHERE td.tramite_id = p_tramite AND td.estado <> 'RECHAZADO';
            $$;

            CREATE OR REPLACE FUNCTION fn_asignar_folios(p_tramite BIGINT) RETURNS INT LANGUAGE plpgsql AS $$
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

            -- -----------------------------------------------------------------
            -- Descuento de diplomado: plazo de 1 año desde la última nota
            -- -----------------------------------------------------------------
            CREATE OR REPLACE FUNCTION fn_trg_solicitud_descuento() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                NEW.fecha_limite   := (NEW.fecha_ultima_nota + INTERVAL '1 year')::DATE;
                NEW.dentro_plazo   := NEW.fecha_solicitud <= NEW.fecha_limite;
                NEW.financiamiento := CASE WHEN NEW.dentro_plazo THEN 'CON_DESCUENTO_50' ELSE 'SIN_FINANCIAMIENTO' END;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_solicitud_descuento BEFORE INSERT OR UPDATE ON solicitud_descuento
                FOR EACH ROW EXECUTE FUNCTION fn_trg_solicitud_descuento();

            -- -----------------------------------------------------------------
            -- Alta de trámite: correlativo, destino obligatorio y requisitos
            -- -----------------------------------------------------------------
            CREATE OR REPLACE FUNCTION fn_trg_tramite_antes_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
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
            CREATE TRIGGER trg_tramite_antes_insert BEFORE INSERT ON tramite
                FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_antes_insert();

            CREATE OR REPLACE FUNCTION fn_trg_tramite_despues_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO tramite_requisito(tramite_id, requisito_id)
                SELECT NEW.id, id FROM requisito WHERE tipo_tramite_id = NEW.tipo_tramite_id;
                RETURN NULL;
            END $$;
            CREATE TRIGGER trg_tramite_despues_insert AFTER INSERT ON tramite
                FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_despues_insert();

            -- -----------------------------------------------------------------
            -- Máquina de estados: Origen facultativo -> Vicerrectorado -> Rectorado
            -- -----------------------------------------------------------------
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
            CREATE TRIGGER trg_tramite_validar_estado BEFORE UPDATE OF estado ON tramite
                FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_validar_estado();

            -- Tiempos por fase (SLA), notificación al solicitante y bitácora en cada cambio de estado
            CREATE OR REPLACE FUNCTION fn_trg_tramite_post_estado() RETURNS TRIGGER LANGUAGE plpgsql AS $$
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
            CREATE TRIGGER trg_tramite_post_estado AFTER INSERT OR UPDATE OF estado ON tramite
                FOR EACH ROW EXECUTE FUNCTION fn_trg_tramite_post_estado();

            -- -----------------------------------------------------------------
            -- Totales del trámite (fojas, importe esperado e importe pagado)
            -- -----------------------------------------------------------------
            CREATE OR REPLACE FUNCTION fn_trg_documento_totales() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE v_t BIGINT := COALESCE(NEW.tramite_id, OLD.tramite_id);
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

            CREATE OR REPLACE FUNCTION fn_trg_comprobante_antes_insert() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                NEW.registrado_por := COALESCE(NEW.registrado_por, fn_usuario_actual());
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_comprobante_antes_insert BEFORE INSERT ON comprobante_caja
                FOR EACH ROW EXECUTE FUNCTION fn_trg_comprobante_antes_insert();

            CREATE OR REPLACE FUNCTION fn_trg_comprobante_total() RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE v_t BIGINT := COALESCE(NEW.tramite_id, OLD.tramite_id);
            BEGIN
                UPDATE tramite
                   SET importe_pagado = (SELECT COALESCE(SUM(importe), 0) FROM comprobante_caja
                                          WHERE tramite_id = v_t AND concepto = 'LEGALIZACION')
                 WHERE id = v_t;
                RETURN NULL;
            END $$;
            CREATE TRIGGER trg_comprobante_total AFTER INSERT OR UPDATE OR DELETE ON comprobante_caja
                FOR EACH ROW EXECUTE FUNCTION fn_trg_comprobante_total();

            -- -----------------------------------------------------------------
            -- Firma digital: firmante autorizado y cadena de firmas en origen
            -- -----------------------------------------------------------------
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
            CREATE TRIGGER trg_lote_validar BEFORE INSERT ON lote_firma
                FOR EACH ROW EXECUTE FUNCTION fn_trg_lote_validar();

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
            CREATE TRIGGER trg_firma_origen_check AFTER INSERT ON firma_digital
                FOR EACH ROW EXECUTE FUNCTION fn_trg_firma_origen_check();

            -- -----------------------------------------------------------------
            -- Certificado oficial con QR
            -- -----------------------------------------------------------------
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
                -- app.url_validador lo fija la aplicación en cada transacción (FRONTEND_URL/validar-certificado)
                NEW.qr_contenido := COALESCE(NULLIF(current_setting('app.url_validador', true), ''),
                                             'http://localhost:5173/validar-certificado') ||
                                    '?codigo=' || NEW.codigo_verificacion || '&hash=' || NEW.hash_sha256;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_certificado BEFORE INSERT ON certificado_oficial
                FOR EACH ROW EXECUTE FUNCTION fn_trg_certificado();

            -- -----------------------------------------------------------------
            -- Vistas operativas: semáforo SLA y bandeja de despacho
            -- -----------------------------------------------------------------
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
                   t.pais_destino, t.institucion_destino, u.nombres || ' ' || u.apellidos AS solicitante, u.registro,
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
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS v_bandeja_despacho;
            DROP VIEW IF EXISTS v_semaforo_sla;

            DROP TRIGGER IF EXISTS trg_certificado ON certificado_oficial;
            DROP TRIGGER IF EXISTS trg_firma_origen_check ON firma_digital;
            DROP TRIGGER IF EXISTS trg_lote_validar ON lote_firma;
            DROP TRIGGER IF EXISTS trg_comprobante_total ON comprobante_caja;
            DROP TRIGGER IF EXISTS trg_comprobante_antes_insert ON comprobante_caja;
            DROP TRIGGER IF EXISTS trg_documento_totales ON tramite_documento;
            DROP TRIGGER IF EXISTS trg_tramite_post_estado ON tramite;
            DROP TRIGGER IF EXISTS trg_tramite_validar_estado ON tramite;
            DROP TRIGGER IF EXISTS trg_tramite_despues_insert ON tramite;
            DROP TRIGGER IF EXISTS trg_tramite_antes_insert ON tramite;
            DROP TRIGGER IF EXISTS trg_solicitud_descuento ON solicitud_descuento;
            DROP TRIGGER IF EXISTS trg_bitacora_no_truncar ON bitacora;
            DROP TRIGGER IF EXISTS trg_bitacora_no_modificar ON bitacora;
            DROP TRIGGER IF EXISTS trg_bitacora_hash ON bitacora;

            DROP FUNCTION IF EXISTS fn_trg_certificado();
            DROP FUNCTION IF EXISTS fn_trg_firma_origen_check();
            DROP FUNCTION IF EXISTS fn_trg_lote_validar();
            DROP FUNCTION IF EXISTS fn_trg_comprobante_total();
            DROP FUNCTION IF EXISTS fn_trg_comprobante_antes_insert();
            DROP FUNCTION IF EXISTS fn_trg_documento_totales();
            DROP FUNCTION IF EXISTS fn_trg_tramite_post_estado();
            DROP FUNCTION IF EXISTS fn_trg_tramite_validar_estado();
            DROP FUNCTION IF EXISTS fn_trg_tramite_despues_insert();
            DROP FUNCTION IF EXISTS fn_trg_tramite_antes_insert();
            DROP FUNCTION IF EXISTS fn_trg_solicitud_descuento();
            DROP FUNCTION IF EXISTS fn_asignar_folios(BIGINT);
            DROP FUNCTION IF EXISTS fn_importe_esperado(BIGINT);
            DROP FUNCTION IF EXISTS fn_autoridad_vigente(DATE);
            DROP FUNCTION IF EXISTS fn_horas_habiles(TIMESTAMP, TIMESTAMP);
            DROP FUNCTION IF EXISTS fn_bitacora_inmutable();
            DROP FUNCTION IF EXISTS fn_bitacora_hash();
            DROP FUNCTION IF EXISTS fn_registrar_bitacora(BIGINT, TEXT, JSONB, BIGINT, BIGINT, BIGINT);
            DROP FUNCTION IF EXISTS fn_usuario_actual();

            DROP SEQUENCE IF EXISTS seq_certificado;
            DROP SEQUENCE IF EXISTS seq_tramite;
            SQL);
    }
};
