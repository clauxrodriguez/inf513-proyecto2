-- =====================================================================
-- DATOS INICIALES COMPLETOS DE CATÁLOGO
-- =====================================================================

-- 1. Roles del sistema
INSERT INTO rol(codigo, nombre, descripcion) VALUES
 ('ADMIN',            'Administrador del Sistema',     'Configuración global, usuarios, roles, permisos y auditoría'),
 ('DIRECTOR_CARRERA', 'Director / Jefe de Carrera',    'Emisión y primera firma digital en origen'),
 ('DECANO',           'Decano / Vicedecano',           'Validación y firma digital facultativa'),
 ('ADMISIONES',       'Jefe de Admisiones y Registro', 'Emisión y firma digital de certificados de notas e históricos'),
 ('DAGA',             'Jefe de DAGA / DICA',           'Visado y firma digital de acreditación académica'),
 ('RECEPCION',        'Recepción / Ventanilla',   'Recepción, validación de requisitos, recibos de caja y compilación'),
 ('VICERRECTOR',      'Vicerrector/a',                 'Autoridad académica de despacho y firma digital'),
 ('RECTOR',           'Rector/a',                      'Firma digital institucional final para trámites al exterior'),
 ('ESTUDIANTE',       'Estudiante / Egresado',        'Solicitante de trámites y seguimiento web');

-- 2. Permisos RBAC
INSERT INTO permiso(codigo, nombre, descripcion) VALUES
 ('DASHBOARD_VER',        'Ver Dashboard',                'Acceso al panel principal e indicadores'),
 ('USUARIOS_GESTIONAR',   'Gestionar Usuarios y Roles',   'Administración de cuentas, roles y permisos'),
 ('DELEGACION_GESTIONAR', 'Gestionar Delegaciones',       'Registro y revocación de suplencias interinas'),
 ('TRAMITES_SOLICITAR',   'Solicitar Trámites',           'Creación de solicitudes de legalización y descuento'),
 ('TRAMITES_ORIGEN',      'Bandeja de Emisión Origen',    'Emisión y firma digital en Carrera, Decanato, Admisiones y DAGA'),
 ('TRAMITES_RECEPCION',   'Ventanilla y Compilación',     'Cotejo, validación de recibos de caja, requisitos y compilación PDF/A'),
 ('DESPACHO_FIRMAR',      'Bandeja de Despacho y Firma',  'Previsualización y firma digital por lotes o remota'),
 ('RECTORADO_VISAR',      'Visado Final Rectorado',       'Firma institucional para trámites con destino al exterior'),
 ('SLA_MONITOREAR',       'Monitoreo de Alertas SLA',     'Control de tiempos de 72h hábiles y atención de alertas'),
 ('REPORTES_VER',         'Reportes y Estadísticas',      'Generación de reportes gerenciales en PDF y Excel'),
 ('BITACORA_AUDITAR',     'Auditoría y Bitácora',         'Consulta de bitácora SHA-256 y accesos web');

-- 3. Matriz de Acceso (Rol - Permiso)
INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r CROSS JOIN permiso p WHERE r.codigo = 'ADMIN';

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo IN ('DASHBOARD_VER','TRAMITES_ORIGEN','SLA_MONITOREAR')
 WHERE r.codigo IN ('DIRECTOR_CARRERA','DECANO','ADMISIONES','DAGA');

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo = 'DESPACHO_FIRMAR' WHERE r.codigo = 'DECANO';

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo IN ('DASHBOARD_VER','TRAMITES_RECEPCION','SLA_MONITOREAR','REPORTES_VER')
 WHERE r.codigo = 'RECEPCION';

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo IN ('DASHBOARD_VER','DESPACHO_FIRMAR','SLA_MONITOREAR','REPORTES_VER')
 WHERE r.codigo = 'VICERRECTOR';

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo IN ('DASHBOARD_VER','DELEGACION_GESTIONAR','RECTORADO_VISAR','REPORTES_VER')
 WHERE r.codigo = 'RECTOR';

INSERT INTO rol_permiso(rol_id, permiso_id)
SELECT r.id, p.id FROM rol r JOIN permiso p ON p.codigo IN ('DASHBOARD_VER','TRAMITES_SOLICITAR')
 WHERE r.codigo = 'ESTUDIANTE';

-- 4. Menú Dinámico (React) y Contadores de Visitas
INSERT INTO menu_opcion(id, padre_id, permiso_id, titulo, ruta, icono, orden) VALUES
 (1,  NULL, (SELECT id FROM permiso WHERE codigo = 'DASHBOARD_VER'),        'Inicio / Dashboard',        '/dashboard',          'LayoutDashboard', 1),
 (2,  NULL, (SELECT id FROM permiso WHERE codigo = 'TRAMITES_SOLICITAR'),   'Mis Trámites',            '/mis-tramites',       'FileText',        2),
 (3,  NULL, (SELECT id FROM permiso WHERE codigo = 'TRAMITES_ORIGEN'),      'Bandeja Facultativa',     '/bandeja-origen',     'Building2',       3),
 (4,  NULL, (SELECT id FROM permiso WHERE codigo = 'TRAMITES_RECEPCION'),   'Ventanilla Legalización', '/recepcion',          'Inbox',           4),
 (5,  NULL, (SELECT id FROM permiso WHERE codigo = 'DESPACHO_FIRMAR'),      'Despacho y Firma Digital','/despacho-firma',     'FileSignature',   5),
 (6,  NULL, (SELECT id FROM permiso WHERE codigo = 'RECTORADO_VISAR'),      'Visado Rectorado',        '/rectorado',          'Award',           6),
 (7,  NULL, (SELECT id FROM permiso WHERE codigo = 'DELEGACION_GESTIONAR'), 'Delegación de Autoridad', '/delegaciones',       'UserCheck',       7),
 (8,  NULL, (SELECT id FROM permiso WHERE codigo = 'SLA_MONITOREAR'),       'Monitor SLA (72h)',       '/monitor-sla',        'Clock',           8),
 (9,  NULL, (SELECT id FROM permiso WHERE codigo = 'REPORTES_VER'),         'Reportes y Estadísticas', '/reportes',           'BarChart3',       9),
 (10, NULL, (SELECT id FROM permiso WHERE codigo = 'USUARIOS_GESTIONAR'),   'Usuarios y Roles',        '/admin/usuarios',     'Users',          10),
 (11, NULL, (SELECT id FROM permiso WHERE codigo = 'BITACORA_AUDITAR'),     'Bitácora y Auditoría',    '/admin/bitacora',     'ShieldCheck',    11);

SELECT setval('menu_opcion_id_seq', 11);

INSERT INTO contador_visita(menu_opcion_id, visitas)
SELECT id, 0 FROM menu_opcion;

-- 5. Facultades y Carreras
INSERT INTO facultad(codigo, nombre, anio_creacion, orden_prelacion) VALUES
 ('FCJPS', 'Facultad de Ciencias Jurídicas, Políticas, Sociales y Relaciones Internacionales', 1879, 1),
 ('FCE',   'Facultad de Ciencias Económicas y Empresariales',                                  1939, 2),
 ('FCSH',  'Facultad de Ciencias de la Salud Humana',                                          1978, 3),
 ('FICCT', 'Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones',        2012, 4);

INSERT INTO carrera(facultad_id, codigo, nombre, regimen) VALUES
 ((SELECT id FROM facultad WHERE codigo = 'FCJPS'), 'DER',   'Derecho',                        'ANUAL'),
 ((SELECT id FROM facultad WHERE codigo = 'FCE'),   'CPA',   'Auditoría / Contaduría Pública', 'SEMESTRAL'),
 ((SELECT id FROM facultad WHERE codigo = 'FCE'),   'ICOM',  'Ingeniería Comercial',           'SEMESTRAL'),
 ((SELECT id FROM facultad WHERE codigo = 'FCSH'),  'MED',   'Medicina',                       'ANUAL'),
 ((SELECT id FROM facultad WHERE codigo = 'FICCT'), '187-3', 'Ingeniería de Sistemas',         'SEMESTRAL'),
 ((SELECT id FROM facultad WHERE codigo = 'FICCT'), '187-4', 'Ingeniería Informática',         'SEMESTRAL');

-- 6. Unidades de Origen y Tipos de Trámite
INSERT INTO unidad_origen(codigo, nombre) VALUES
 ('CARRERA',        'Dirección de Carrera'),
 ('DECANATO',       'Decanato / Vicedecanato'),
 ('ADMISIONES',     'Departamento de Admisiones y Registro'),
 ('DAGA',           'Dirección de Acreditación y Gestión Académica (DAGA/DICA)'),
 ('CPD',            'Centro de Procesamiento de Datos Facultativo'),
 ('TESORERIA',      'Caja / Tesorería'),
 ('VICERRECTORADO', 'Vicerrectorado - Ventanilla de Legalizaciones'),
 ('RECTORADO',      'Rectorado'),
 ('POSTGRADO',      'Unidad de Postgrado');

INSERT INTO tipo_tramite(codigo, nombre, descripcion, prefijo) VALUES
 ('LEGALIZACION',        'Legalización de Documentos Académicos', 'Certificación nacional y exterior (Apostilla)', 'LEG'),
 ('DESCUENTO_DIPLOMADO', 'Descuento del 50% para Diplomado',      'Beneficio Res. Vicerrectoral 01-28/2013',      'DIP');

-- 7. Catálogo de Documentos Legalizables
INSERT INTO catalogo_documento(codigo, nombre, unidad_emisora_id, tipo_cobro, tarifa)
SELECT x.cod, x.nom, u.id, x.cobro, x.tarifa FROM (VALUES
 ('PROG_ANALITICO',  'Programas analíticos',                               'CARRERA',        'MATERIA',   10),
 ('PLAN_ESTUDIOS',   'Plan de estudios con carga horaria',                 'CARRERA',        'DOCUMENTO', 45),
 ('MALLA',           'Malla curricular',                                   'CARRERA',        'DOCUMENTO', 45),
 ('EQUIVALENCIAS',   'Tablas de equivalencias o convalidaciones',          'CARRERA',        'FOJA',      15),
 ('CERT_NOTAS',      'Certificado de notas',                               'ADMISIONES',     'FOJA',      15),
 ('HISTORICO',       'Histórico académico',                                'ADMISIONES',     'DOCUMENTO', 45),
 ('VENC_PLAN',       'Certificado de vencimiento de plan de estudios',     'ADMISIONES',     'DOCUMENTO', 15),
 ('ALUMNO_REGULAR',  'Certificado de alumno regular (activo / pasivo)',    'ADMISIONES',     'DOCUMENTO', 15),
 ('FUNC_INSTITUC',   'Certificado de funcionamiento institucional',        'DAGA',           'DOCUMENTO', 15),
 ('RES_VICERRECT',   'Resolución vicerrectoral',                           'VICERRECTORADO', 'FOJA',      15),
 ('CARGA_INTERNADO', 'Carga horaria de internado rotatorio',               'CARRERA',        'FOJA',      15),
 ('NOMINA_DOCENTE',  'Nómina de docentes titulares',                       'CARRERA',        'FOJA',      15),
 ('CONVERSION_NOTAS','Certificado de conversión de notas escala 1-10',     'ADMISIONES',     'DOCUMENTO', 15),
 ('MERCOSUR',        'Certificado académico de acreditación al Mercosur',  'DAGA',           'DOCUMENTO', 15),
 ('PROMEDIO',        'Certificado de promedio general o récord académico', 'ADMISIONES',     'DOCUMENTO', 15),
 ('PENSUM_MEDICINA', 'Certificado de pensum de Medicina',                  'CARRERA',        'DOCUMENTO', 45),
 ('HORAS_RELOJ',     'Certificado de horas reloj',                         'CARRERA',        'DOCUMENTO', 15)
) AS x(cod, nom, unidad, cobro, tarifa) JOIN unidad_origen u ON u.codigo = x.unidad;

-- 8. Cadena de Firmas Digitales Precedentes en Origen
INSERT INTO catalogo_firma_orden(catalogo_documento_id, unidad_origen_id, orden, cargo_firmante, requiere_sello)
SELECT cd.id, uo.id, x.ord, x.cargo, TRUE FROM (VALUES
 ('PROG_ANALITICO', 'CARRERA',    1, 'Director / Jefe de Carrera'),
 ('PROG_ANALITICO', 'DECANATO',   2, 'Decano / Vicedecano'),
 ('PROG_ANALITICO', 'DAGA',       3, 'Jefe de DAGA / DICA'),
 ('PLAN_ESTUDIOS',  'CARRERA',    1, 'Director / Jefe de Carrera'),
 ('PLAN_ESTUDIOS',  'DECANATO',   2, 'Decano / Vicedecano'),
 ('PLAN_ESTUDIOS',  'DAGA',       3, 'Jefe de DAGA / DICA'),
 ('MALLA',          'CARRERA',    1, 'Director / Jefe de Carrera'),
 ('MALLA',          'DECANATO',   2, 'Decano / Vicedecano'),
 ('MALLA',          'DAGA',       3, 'Jefe de DAGA / DICA'),
 ('EQUIVALENCIAS',  'CARRERA',    1, 'Director / Jefe de Carrera'),
 ('EQUIVALENCIAS',  'DECANATO',   2, 'Decano / Vicedecano'),
 ('CERT_NOTAS',     'ADMISIONES', 1, 'Jefe de Admisiones y Registro'),
 ('HISTORICO',      'ADMISIONES', 1, 'Jefe de Admisiones y Registro'),
 ('VENC_PLAN',      'ADMISIONES', 1, 'Jefe de Admisiones y Registro'),
 ('ALUMNO_REGULAR', 'ADMISIONES', 1, 'Jefe de Admisiones y Registro'),
 ('PROMEDIO',       'ADMISIONES', 1, 'Jefe de Admisiones y Registro'),
 ('FUNC_INSTITUC',  'DAGA',       1, 'Jefe de DAGA / DICA'),
 ('MERCOSUR',       'DAGA',       1, 'Jefe de DAGA / DICA')
) AS x(doc, uni, ord, cargo)
JOIN catalogo_documento cd ON cd.codigo = x.doc
JOIN unidad_origen uo      ON uo.codigo = x.uni;

-- 9. Requisitos por Tipo de Trámite
INSERT INTO requisito(tipo_tramite_id, codigo, descripcion, obligatorio)
SELECT tt.id, x.cod, x.descr, x.oblig FROM (VALUES
 ('LEGALIZACION', 'CARTA',          'Solicitud digital dirigida al Vicerrectorado con detalle de ítems y páginas', TRUE),
 ('LEGALIZACION', 'COMPROBANTE',    'Comprobante de caja validado por el importe esperado',                        TRUE),
 ('LEGALIZACION', 'FIRMAS_PREVIAS', 'Firmas digitales precedentes completas (Carrera, Decanato, Admisiones, DAGA)', TRUE),
 ('LEGALIZACION', 'FOLIACION',      'Expediente digital foliado correlativamente',                                  TRUE),
 ('LEGALIZACION', 'FOJAS_SEPARADAS','Certificados en fojas independientes (cuando el país de destino lo exige)',      FALSE),
 ('DESCUENTO_DIPLOMADO', 'CARTA',      'Solicitud formal al Vicerrectorado indicando Unidad de Postgrado y diplomado', TRUE),
 ('DESCUENTO_DIPLOMADO', 'FORMULARIO', 'Formulario web oficial de datos personales completo',                            TRUE),
 ('DESCUENTO_DIPLOMADO', 'AVANCE',     'Avance Académico validado por Admisiones (Bs 50 en Caja)',                      TRUE),
 ('DESCUENTO_DIPLOMADO', 'GRL',        'Inscripción de la materia GRL verificada en el CPD facultativo',                 TRUE)
) AS x(tipo, cod, descr, oblig) JOIN tipo_tramite tt ON tt.codigo = x.tipo;

-- 10. Guía Procesal del Flujo
INSERT INTO flujo_paso(tipo_tramite_id, destino, orden, unidad_id, nombre, descripcion, firma_requerida, sello_requerido, estado_asociado)
SELECT tt.id, x.dest, x.orden, u.id, x.nombre, x.descr, x.firma, x.sello, x.estado FROM (VALUES
 ('LEGALIZACION', NULL,       1, 'CARRERA',        'Emisión y firma en origen', 'Generación estandarizada y firma digital en Carrera / Decanato / Admisiones', 'Director de Carrera, Decano o Jefe de Admisiones', 'Firma Digital PAdES Origen', 'EN_FIRMA_ORIGEN'),
 ('LEGALIZACION', NULL,       2, 'DAGA',           'Visado digital DAGA / DICA', 'Validación y firma digital de acreditación académica', 'Jefe DAGA / DICA', 'Firma Digital DAGA', 'INGRESADO'),
 ('LEGALIZACION', NULL,       3, 'TESORERIA',      'Validación de recibos de caja', 'Registro y verificación de recibos emitidos por Tesorería según arancel', NULL, 'Comprobante de Caja Validado', 'INGRESADO'),
 ('LEGALIZACION', NULL,       4, 'VICERRECTORADO', 'Recepción y cotejo automático', 'Verificación criptográfica de firmas previas, requisitos y recibos', NULL, NULL, 'EN_REVISION'),
 ('LEGALIZACION', NULL,       5, 'VICERRECTORADO', 'Compilación de expediente', 'Ensamblaje en PDF/A con foliación continua y huella SHA-256', NULL, NULL, 'EN_BANDEJA_DESPACHO'),
 ('LEGALIZACION', NULL,       6, 'VICERRECTORADO', 'Firma digital de despacho', 'Firma digital consolidada por lotes o remota', 'Vicerrector/a o Suplente', 'Firma Digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'),
 ('LEGALIZACION', 'EXTERIOR', 7, 'RECTORADO',      'Visado institucional Rectorado', 'Firma digital final institucional previa a la Apostilla en Cancillería', 'Rector/a', 'Firma Digital Rectorado', 'DERIVADO_RECTORADO'),
 ('LEGALIZACION', NULL,       8, 'VICERRECTORADO', 'Certificación QR y entrega', 'Emisión de Certificado Oficial PDF/A con código QR verificable', NULL, NULL, 'CONCLUIDO'),
 ('DESCUENTO_DIPLOMADO', NULL, 1, 'CPD',            'Inscripción de materia GRL', 'Verificación de inscripción GRL en el CPD facultativo', NULL, NULL, 'INGRESADO'),
 ('DESCUENTO_DIPLOMADO', NULL, 2, 'ADMISIONES',     'Avance Académico', 'Validación de recibo Bs 50 y Avance Académico', 'Jefe de Admisiones', 'Firma Digital Admisiones', 'INGRESADO'),
 ('DESCUENTO_DIPLOMADO', NULL, 3, 'VICERRECTORADO', 'Verificación de recaudos y plazo', 'Control automático de vigencia (1 año / 2 semestres desde última nota)', NULL, NULL, 'EN_REVISION'),
 ('DESCUENTO_DIPLOMADO', NULL, 4, 'VICERRECTORADO', 'Firma de constancia del 50%', 'Firma digital de la constancia de beneficio', 'Vicerrector/a o Suplente', 'Firma Digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'),
 ('DESCUENTO_DIPLOMADO', NULL, 5, 'POSTGRADO',      'Emisión de constancia QR', 'Entrega digital para presentación en Unidad de Postgrado', NULL, NULL, 'CONCLUIDO')
) AS x(tipo, dest, orden, unidad, nombre, descr, firma, sello, estado)
JOIN tipo_tramite tt ON tt.codigo = x.tipo
JOIN unidad_origen u ON u.codigo = x.unidad;

-- 11. Configuración SLA (72 horas hábiles) e Indicadores de Gestión
INSERT INTO sla_config(tipo_tramite_id, plazo_horas, porcentaje_alerta) VALUES (NULL, 72, 75);

INSERT INTO indicador_gestion(codigo, nombre, unidad_medida, meta) VALUES
 ('IND_CUMPLIMIENTO_SLA',    '% de trámites concluidos dentro de 72 h hábiles', '%',        95),
 ('IND_TIEMPO_PROMEDIO',     'Tiempo promedio de despacho',                     'HORAS',    48),
 ('IND_TRAMITES_INGRESADOS', 'Trámites ingresados',                             'CANTIDAD', NULL),
 ('IND_TRAMITES_CONCLUIDOS', 'Trámites concluidos',                             'CANTIDAD', NULL),
 ('IND_TASA_OBSERVACION',    '% de trámites con observaciones',                 '%',        10),
 ('IND_RECAUDACION',         'Recaudación por valorados',                       'BS',       NULL),
 ('IND_FIRMAS_REMOTAS',      '% de firmas realizadas en forma remota',          '%',        NULL),
 ('IND_FIRMAS_SUPLENTE',     'Firmas realizadas por autoridad suplente',        'CANTIDAD', NULL);

-- 12. Feriados Oficiales 2026
INSERT INTO feriado(fecha, sla_config_id, descripcion) VALUES
 ('2026-01-01', 1, 'Año Nuevo'),
 ('2026-01-22', 1, 'Día del Estado Plurinacional'),
 ('2026-02-16', 1, 'Carnaval'),
 ('2026-02-17', 1, 'Carnaval'),
 ('2026-04-03', 1, 'Viernes Santo'),
 ('2026-05-01', 1, 'Día del Trabajo'),
 ('2026-06-04', 1, 'Corpus Christi'),
 ('2026-06-22', 1, 'Año Nuevo Andino Amazónico'),
 ('2026-08-06', 1, 'Día de la Patria'),
 ('2026-09-24', 1, 'Aniversario de Santa Cruz'),
 ('2026-11-02', 1, 'Día de Todos los Santos'),
 ('2026-12-25', 1, 'Navidad');