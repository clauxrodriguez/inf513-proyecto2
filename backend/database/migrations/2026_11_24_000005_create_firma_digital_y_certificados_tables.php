<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firma digital en cadena (origen, despacho y rectorado) y certificado oficial con QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificado_digital', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuario');
            $table->string('numero_serie', 80)->unique();
            $table->string('emisor', 150);
            $table->date('fecha_emision');
            $table->date('fecha_expiracion');
            $table->boolean('activo')->default(true);
        });

        DB::statement('ALTER TABLE certificado_digital
            ADD CONSTRAINT ck_certificado_vigencia CHECK (fecha_expiracion > fecha_emision)');

        Schema::create('lote_firma', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firmante_id')->constrained('usuario');
            $table->foreignId('certificado_id')->constrained('certificado_digital');
            $table->foreignId('delegacion_id')->nullable()->constrained('delegacion_autoridad');
            $table->boolean('es_remota')->default(false);
            $table->string('ubicacion', 150)->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->timestamp('fecha')->useCurrent();
        });

        Schema::create('firma_digital', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lote_firma');
            $table->foreignId('tramite_id')->index()->constrained('tramite')->cascadeOnDelete();
            $table->foreignId('tramite_documento_id')->nullable()->constrained('tramite_documento')->cascadeOnDelete();
            $table->foreignId('expediente_id')->nullable()->constrained('expediente')->cascadeOnDelete();
            $table->integer('orden_firma')->default(1);
            $table->enum('etapa', ['ORIGEN', 'DESPACHO', 'RECTORADO'])->default('DESPACHO');
            $table->char('hash_documento', 64);
            $table->text('firma_valor');
            $table->timestamp('fecha_firma')->useCurrent();
            $table->boolean('valida')->default(true);
        });

        Schema::create('certificado_oficial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramite');
            $table->foreignId('firma_id')->nullable()->constrained('firma_digital');
            $table->string('numero', 30)->nullable()->unique();
            $table->enum('tipo', ['LEGALIZACION', 'CONSTANCIA_DIPLOMADO']);
            $table->string('ruta_pdfa', 255);
            $table->char('hash_sha256', 64);
            $table->uuid('codigo_verificacion')->unique()->default(new Expression('gen_random_uuid()'));
            $table->text('qr_contenido')->nullable();
            $table->timestamp('fecha_emision')->useCurrent();
            $table->enum('estado', ['VIGENTE', 'ANULADO'])->default('VIGENTE');
        });

        // Bitácora: evento sobre un certificado oficial (p. ej. su anulación, con el motivo en el detalle)
        Schema::table('bitacora', function (Blueprint $table) {
            $table->foreignId('certificado_id')->nullable()->index()->constrained('certificado_oficial');
        });
    }

    public function down(): void
    {
        Schema::table('bitacora', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificado_id');
        });
        Schema::dropIfExists('certificado_oficial');
        Schema::dropIfExists('firma_digital');
        Schema::dropIfExists('lote_firma');
        Schema::dropIfExists('certificado_digital');
    }
};
