<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos, cadena de firmas en origen, trámites, documentos, recibos de caja, requisitos,
 * expediente compilado y solicitud de descuento de diplomado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidad_origen', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
        });

        Schema::create('tipo_tramite', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 120);
            $table->string('descripcion', 255)->nullable();
            $table->string('prefijo', 5);
        });

        Schema::create('catalogo_documento', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 200);
            $table->foreignId('unidad_emisora_id')->constrained('unidad_origen');
            $table->enum('tipo_cobro', ['FOJA', 'MATERIA', 'DOCUMENTO']);
            $table->decimal('tarifa', 10, 2);
            $table->boolean('activo')->default(true);
        });

        DB::statement('ALTER TABLE catalogo_documento ADD CONSTRAINT ck_catalogo_tarifa CHECK (tarifa >= 0)');

        Schema::create('catalogo_firma_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_documento_id')->constrained('catalogo_documento')->cascadeOnDelete();
            $table->foreignId('unidad_origen_id')->constrained('unidad_origen');
            $table->integer('orden');
            $table->string('cargo_firmante', 100);
            $table->boolean('requiere_sello')->default(true);
            $table->unique(['catalogo_documento_id', 'orden']);
        });

        Schema::create('tramite', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 25)->nullable()->unique();
            $table->foreignId('tipo_tramite_id')->constrained('tipo_tramite');
            $table->foreignId('solicitante_id')->index()->constrained('usuario');
            $table->foreignId('carrera_id')->index()->constrained('carrera');
            $table->enum('destino', ['NACIONAL', 'EXTERIOR'])->nullable();
            $table->string('pais_destino', 80)->nullable();
            $table->string('institucion_destino', 200)->nullable();
            $table->enum('prioridad', ['NORMAL', 'ALTA', 'URGENTE'])->default('NORMAL');
            $table->enum('estado', [
                'SOLICITADO_ORIGEN', 'EN_FIRMA_ORIGEN', 'INGRESADO', 'EN_REVISION', 'OBSERVADO',
                'EN_BANDEJA_DESPACHO', 'FIRMADO_DIGITALMENTE', 'DERIVADO_RECTORADO', 'CONCLUIDO', 'RECHAZADO',
            ])->default('SOLICITADO_ORIGEN')->index();
            $table->integer('total_fojas')->default(0);
            $table->decimal('importe_esperado', 10, 2)->default(0);
            $table->decimal('importe_pagado', 10, 2)->default(0);
            $table->foreignId('registrado_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_ingreso')->useCurrent();
            $table->timestamp('fecha_conclusion')->nullable();
        });

        DB::statement("ALTER TABLE tramite ADD CONSTRAINT ck_tramite_pais_exterior
            CHECK (destino IS DISTINCT FROM 'EXTERIOR' OR pais_destino IS NOT NULL)");

        Schema::create('tramite_documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->index()->constrained('tramite')->cascadeOnDelete();
            $table->foreignId('catalogo_documento_id')->constrained('catalogo_documento');
            $table->integer('cantidad_paginas');
            $table->integer('cantidad_materias')->nullable();
            $table->boolean('emitido_por_uagrm')->default(true);
            $table->string('archivo_ruta', 255)->nullable();
            $table->integer('folio_inicio')->nullable();
            $table->integer('folio_fin')->nullable();
            $table->boolean('firmas_origen_ok')->default(false);
            $table->enum('estado', ['PENDIENTE', 'EN_FIRMA', 'VALIDO', 'OBSERVADO', 'RECHAZADO'])->default('PENDIENTE');
        });

        DB::statement('ALTER TABLE tramite_documento
            ADD CONSTRAINT ck_tdoc_paginas CHECK (cantidad_paginas > 0),
            ADD CONSTRAINT ck_tdoc_materias CHECK (cantidad_materias >= 0)');

        Schema::create('comprobante_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->index()->constrained('tramite')->cascadeOnDelete();
            $table->string('codigo_recibo', 30)->unique();
            $table->enum('concepto', ['EMISION_ORIGEN', 'LEGALIZACION', 'AVANCE_ACADEMICO']);
            $table->decimal('importe', 10, 2);
            $table->date('fecha_pago');
            $table->foreignId('registrado_por')->nullable()->constrained('usuario');
        });

        DB::statement('ALTER TABLE comprobante_caja ADD CONSTRAINT ck_comprobante_importe CHECK (importe > 0)');

        Schema::create('requisito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_tramite_id')->constrained('tipo_tramite');
            $table->string('codigo', 30);
            $table->string('descripcion', 255);
            $table->boolean('obligatorio')->default(true);
            $table->unique(['tipo_tramite_id', 'codigo']);
        });

        Schema::create('tramite_requisito', function (Blueprint $table) {
            $table->foreignId('tramite_id')->constrained('tramite')->cascadeOnDelete();
            $table->foreignId('requisito_id')->constrained('requisito');
            $table->boolean('cumplido')->default(false);
            $table->foreignId('verificado_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_verificacion')->nullable();
            $table->primary(['tramite_id', 'requisito_id']);
        });

        Schema::create('expediente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramite')->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('ruta_pdf', 255);
            $table->integer('total_fojas');
            $table->char('hash_sha256', 64);
            $table->foreignId('compilado_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_compilacion')->useCurrent();
            $table->boolean('vigente')->default(true);
            $table->unique(['tramite_id', 'version']);
        });

        DB::statement('ALTER TABLE expediente ADD CONSTRAINT ck_expediente_fojas CHECK (total_fojas > 0)');

        Schema::create('solicitud_descuento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->unique()->constrained('tramite')->cascadeOnDelete();
            $table->string('unidad_postgrado', 150);
            $table->string('nombre_diplomado', 200);
            $table->string('nro_avance_academico', 20);
            $table->date('fecha_ultima_nota');
            $table->date('fecha_solicitud')->default(new Expression('CURRENT_DATE'));
            $table->date('fecha_limite')->nullable();
            $table->boolean('dentro_plazo')->nullable();
            $table->string('financiamiento', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_descuento');
        Schema::dropIfExists('expediente');
        Schema::dropIfExists('tramite_requisito');
        Schema::dropIfExists('requisito');
        Schema::dropIfExists('comprobante_caja');
        Schema::dropIfExists('tramite_documento');
        Schema::dropIfExists('tramite');
        Schema::dropIfExists('catalogo_firma_orden');
        Schema::dropIfExists('catalogo_documento');
        Schema::dropIfExists('tipo_tramite');
        Schema::dropIfExists('unidad_origen');
    }
};
