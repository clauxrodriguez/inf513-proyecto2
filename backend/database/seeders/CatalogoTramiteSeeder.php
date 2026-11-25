<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Unidades de origen, tipos de trámite, documentos legalizables con su cadena de firmas,
 * requisitos y guía procesal del flujo.
 */
class CatalogoTramiteSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('unidad_origen')->insert([
            ['codigo' => 'CARRERA', 'nombre' => 'Dirección de Carrera'],
            ['codigo' => 'DECANATO', 'nombre' => 'Decanato / Vicedecanato'],
            ['codigo' => 'ADMISIONES', 'nombre' => 'Departamento de Admisiones y Registro'],
            ['codigo' => 'DAGA', 'nombre' => 'Dirección de Acreditación y Gestión Académica (DAGA/DICA)'],
            ['codigo' => 'CPD', 'nombre' => 'Centro de Procesamiento de Datos Facultativo'],
            ['codigo' => 'TESORERIA', 'nombre' => 'Caja / Tesorería'],
            ['codigo' => 'VICERRECTORADO', 'nombre' => 'Vicerrectorado - Ventanilla de Legalizaciones'],
            ['codigo' => 'RECTORADO', 'nombre' => 'Rectorado'],
            ['codigo' => 'POSTGRADO', 'nombre' => 'Unidad de Postgrado'],
        ]);

        DB::table('tipo_tramite')->insert([
            ['codigo' => 'LEGALIZACION', 'nombre' => 'Legalización de Documentos Académicos', 'descripcion' => 'Certificación nacional y exterior (Apostilla)', 'prefijo' => 'LEG'],
            ['codigo' => 'DESCUENTO_DIPLOMADO', 'nombre' => 'Descuento del 50% para Diplomado', 'descripcion' => 'Beneficio Res. Vicerrectoral 01-28/2013', 'prefijo' => 'DIP'],
        ]);

        $unidades = DB::table('unidad_origen')->pluck('id', 'codigo');
        $tipos = DB::table('tipo_tramite')->pluck('id', 'codigo');

        $this->documentos($unidades->all());
        $this->cadenaDeFirmas($unidades->all());
        $this->requisitos($tipos->all());
        $this->flujo($tipos->all(), $unidades->all());
    }

    /**
     * @param  array<string, int>  $unidades
     */
    private function documentos(array $unidades): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}> $documentos código, nombre, unidad emisora, cobro, tarifa */
        $documentos = [
            ['PROG_ANALITICO', 'Programas analíticos', 'CARRERA', 'MATERIA', 10],
            ['PLAN_ESTUDIOS', 'Plan de estudios con carga horaria', 'CARRERA', 'DOCUMENTO', 45],
            ['MALLA', 'Malla curricular', 'CARRERA', 'DOCUMENTO', 45],
            ['EQUIVALENCIAS', 'Tablas de equivalencias o convalidaciones', 'CARRERA', 'FOJA', 15],
            ['CERT_NOTAS', 'Certificado de notas', 'ADMISIONES', 'FOJA', 15],
            ['HISTORICO', 'Histórico académico', 'ADMISIONES', 'DOCUMENTO', 45],
            ['VENC_PLAN', 'Certificado de vencimiento de plan de estudios', 'ADMISIONES', 'DOCUMENTO', 15],
            ['ALUMNO_REGULAR', 'Certificado de alumno regular (activo / pasivo)', 'ADMISIONES', 'DOCUMENTO', 15],
            ['FUNC_INSTITUC', 'Certificado de funcionamiento institucional', 'DAGA', 'DOCUMENTO', 15],
            ['RES_VICERRECT', 'Resolución vicerrectoral', 'VICERRECTORADO', 'FOJA', 15],
            ['CARGA_INTERNADO', 'Carga horaria de internado rotatorio', 'CARRERA', 'FOJA', 15],
            ['NOMINA_DOCENTE', 'Nómina de docentes titulares', 'CARRERA', 'FOJA', 15],
            ['CONVERSION_NOTAS', 'Certificado de conversión de notas escala 1-10', 'ADMISIONES', 'DOCUMENTO', 15],
            ['MERCOSUR', 'Certificado académico de acreditación al Mercosur', 'DAGA', 'DOCUMENTO', 15],
            ['PROMEDIO', 'Certificado de promedio general o récord académico', 'ADMISIONES', 'DOCUMENTO', 15],
            ['PENSUM_MEDICINA', 'Certificado de pensum de Medicina', 'CARRERA', 'DOCUMENTO', 45],
            ['HORAS_RELOJ', 'Certificado de horas reloj', 'CARRERA', 'DOCUMENTO', 15],
        ];

        DB::table('catalogo_documento')->insert(array_map(fn (array $documento): array => [
            'codigo' => $documento[0],
            'nombre' => $documento[1],
            'unidad_emisora_id' => $unidades[$documento[2]],
            'tipo_cobro' => $documento[3],
            'tarifa' => $documento[4],
        ], $documentos));
    }

    /**
     * Firmas digitales precedentes en origen. Todo documento necesita al menos una firma:
     * sin ella nunca queda con firmas_origen_ok y el trámite no puede pasar a INGRESADO.
     *
     * @param  array<string, int>  $unidades
     */
    private function cadenaDeFirmas(array $unidades): void
    {
        $carrera = 'Director / Jefe de Carrera';
        $decano = 'Decano / Vicedecano';
        $daga = 'Jefe de DAGA / DICA';
        $admisiones = 'Jefe de Admisiones y Registro';

        /** @var array<string, list<array{0: string, 1: string}>> $cadenas documento => [unidad, cargo] en orden */
        $cadenas = [
            'PROG_ANALITICO' => [['CARRERA', $carrera], ['DECANATO', $decano], ['DAGA', $daga]],
            'PLAN_ESTUDIOS' => [['CARRERA', $carrera], ['DECANATO', $decano], ['DAGA', $daga]],
            'MALLA' => [['CARRERA', $carrera], ['DECANATO', $decano], ['DAGA', $daga]],
            'EQUIVALENCIAS' => [['CARRERA', $carrera], ['DECANATO', $decano]],
            'CARGA_INTERNADO' => [['CARRERA', $carrera], ['DECANATO', $decano]],
            'NOMINA_DOCENTE' => [['CARRERA', $carrera], ['DECANATO', $decano]],
            'PENSUM_MEDICINA' => [['CARRERA', $carrera], ['DECANATO', $decano]],
            'HORAS_RELOJ' => [['CARRERA', $carrera], ['DECANATO', $decano]],
            'CERT_NOTAS' => [['ADMISIONES', $admisiones]],
            'HISTORICO' => [['ADMISIONES', $admisiones]],
            'VENC_PLAN' => [['ADMISIONES', $admisiones]],
            'ALUMNO_REGULAR' => [['ADMISIONES', $admisiones]],
            'PROMEDIO' => [['ADMISIONES', $admisiones]],
            'CONVERSION_NOTAS' => [['ADMISIONES', $admisiones]],
            'FUNC_INSTITUC' => [['DAGA', $daga]],
            'MERCOSUR' => [['DAGA', $daga]],
            'RES_VICERRECT' => [['VICERRECTORADO', 'Vicerrector/a']],
        ];

        $documentos = DB::table('catalogo_documento')->pluck('id', 'codigo');
        $filas = [];

        foreach ($cadenas as $documento => $firmas) {
            foreach ($firmas as $indice => [$unidad, $cargo]) {
                $filas[] = [
                    'catalogo_documento_id' => $documentos[$documento],
                    'unidad_origen_id' => $unidades[$unidad],
                    'orden' => $indice + 1,
                    'cargo_firmante' => $cargo,
                    'requiere_sello' => true,
                ];
            }
        }

        DB::table('catalogo_firma_orden')->insert($filas);
    }

    /**
     * @param  array<string, int>  $tipos
     */
    private function requisitos(array $tipos): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: bool}> $requisitos tipo, código, descripción, obligatorio */
        $requisitos = [
            ['LEGALIZACION', 'CARTA', 'Solicitud digital dirigida al Vicerrectorado con detalle de ítems y páginas', true],
            ['LEGALIZACION', 'COMPROBANTE', 'Comprobante de caja validado por el importe esperado', true],
            ['LEGALIZACION', 'FIRMAS_PREVIAS', 'Firmas digitales precedentes completas (Carrera, Decanato, Admisiones, DAGA)', true],
            ['LEGALIZACION', 'FOLIACION', 'Expediente digital foliado correlativamente', true],
            ['LEGALIZACION', 'FOJAS_SEPARADAS', 'Certificados en fojas independientes (cuando el país de destino lo exige)', false],
            ['DESCUENTO_DIPLOMADO', 'CARTA', 'Solicitud formal al Vicerrectorado indicando Unidad de Postgrado y diplomado', true],
            ['DESCUENTO_DIPLOMADO', 'FORMULARIO', 'Formulario web oficial de datos personales completo', true],
            ['DESCUENTO_DIPLOMADO', 'AVANCE', 'Avance Académico validado por Admisiones (Bs 50 en Caja)', true],
            ['DESCUENTO_DIPLOMADO', 'GRL', 'Inscripción de la materia GRL verificada en el CPD facultativo', true],
        ];

        DB::table('requisito')->insert(array_map(fn (array $requisito): array => [
            'tipo_tramite_id' => $tipos[$requisito[0]],
            'codigo' => $requisito[1],
            'descripcion' => $requisito[2],
            'obligatorio' => $requisito[3],
        ], $requisitos));
    }

    /**
     * @param  array<string, int>  $tipos
     * @param  array<string, int>  $unidades
     */
    private function flujo(array $tipos, array $unidades): void
    {
        /** @var list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: string, 6: ?string, 7: ?string, 8: string}> $pasos */
        $pasos = [
            ['LEGALIZACION', null, 1, 'CARRERA', 'Emisión y firma en origen', 'Generación estandarizada y firma digital en Carrera / Decanato / Admisiones', 'Director de Carrera, Decano o Jefe de Admisiones', 'Firma Digital PAdES Origen', 'EN_FIRMA_ORIGEN'],
            ['LEGALIZACION', null, 2, 'DAGA', 'Visado digital DAGA / DICA', 'Validación y firma digital de acreditación académica', 'Jefe DAGA / DICA', 'Firma Digital DAGA', 'INGRESADO'],
            ['LEGALIZACION', null, 3, 'TESORERIA', 'Validación de recibos de caja', 'Registro y verificación de recibos emitidos por Tesorería según arancel', null, 'Comprobante de Caja Validado', 'INGRESADO'],
            ['LEGALIZACION', null, 4, 'VICERRECTORADO', 'Recepción y cotejo automático', 'Verificación criptográfica de firmas previas, requisitos y recibos', null, null, 'EN_REVISION'],
            ['LEGALIZACION', null, 5, 'VICERRECTORADO', 'Compilación de expediente', 'Ensamblaje en PDF/A con foliación continua y huella SHA-256', null, null, 'EN_BANDEJA_DESPACHO'],
            ['LEGALIZACION', null, 6, 'VICERRECTORADO', 'Firma digital de despacho', 'Firma digital consolidada por lotes o remota', 'Vicerrector/a o Suplente', 'Firma Digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'],
            ['LEGALIZACION', 'EXTERIOR', 7, 'RECTORADO', 'Visado institucional Rectorado', 'Firma digital final institucional previa a la Apostilla en Cancillería', 'Rector/a', 'Firma Digital Rectorado', 'DERIVADO_RECTORADO'],
            ['LEGALIZACION', null, 8, 'VICERRECTORADO', 'Certificación QR y entrega', 'Emisión de Certificado Oficial PDF/A con código QR verificable', null, null, 'CONCLUIDO'],
            ['DESCUENTO_DIPLOMADO', null, 1, 'CPD', 'Inscripción de materia GRL', 'Verificación de inscripción GRL en el CPD facultativo', null, null, 'INGRESADO'],
            ['DESCUENTO_DIPLOMADO', null, 2, 'ADMISIONES', 'Avance Académico', 'Validación de recibo Bs 50 y Avance Académico', 'Jefe de Admisiones', 'Firma Digital Admisiones', 'INGRESADO'],
            ['DESCUENTO_DIPLOMADO', null, 3, 'VICERRECTORADO', 'Verificación de recaudos y plazo', 'Control automático de vigencia (1 año / 2 semestres desde última nota)', null, null, 'EN_REVISION'],
            ['DESCUENTO_DIPLOMADO', null, 4, 'VICERRECTORADO', 'Firma de constancia del 50%', 'Firma digital de la constancia de beneficio', 'Vicerrector/a o Suplente', 'Firma Digital Vicerrectorado', 'FIRMADO_DIGITALMENTE'],
            ['DESCUENTO_DIPLOMADO', null, 5, 'POSTGRADO', 'Emisión de constancia QR', 'Entrega digital para presentación en Unidad de Postgrado', null, null, 'CONCLUIDO'],
        ];

        DB::table('flujo_paso')->insert(array_map(fn (array $paso): array => [
            'tipo_tramite_id' => $tipos[$paso[0]],
            'destino' => $paso[1],
            'orden' => $paso[2],
            'unidad_id' => $unidades[$paso[3]],
            'nombre' => $paso[4],
            'descripcion' => $paso[5],
            'firma_requerida' => $paso[6],
            'sello_requerido' => $paso[7],
            'estado_asociado' => $paso[8],
        ], $pasos));
    }
}
