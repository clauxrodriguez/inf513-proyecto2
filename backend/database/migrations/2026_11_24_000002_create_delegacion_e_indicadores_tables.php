<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delegación de autoridad (suplencias) e indicadores de gestión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegacion_autoridad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titular_id')->constrained('usuario');
            $table->foreignId('suplente_id')->constrained('usuario');
            $table->string('nro_resolucion', 50);
            $table->date('fecha_resolucion');
            $table->enum('motivo', ['VIAJE', 'COMISION_OFICIAL', 'AUSENCIA', 'VACACION', 'OTRO']);
            $table->string('detalle_motivo', 255)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->enum('estado', ['ACTIVA', 'FINALIZADA', 'REVOCADA'])->default('ACTIVA');
            $table->foreignId('registrado_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_registro')->useCurrent();
        });

        DB::statement('ALTER TABLE delegacion_autoridad
            ADD CONSTRAINT ck_delegacion_fechas CHECK (fecha_fin >= fecha_inicio),
            ADD CONSTRAINT ck_delegacion_personas CHECK (titular_id <> suplente_id)');

        Schema::create('indicador_gestion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 150);
            $table->string('unidad_medida', 20);
            $table->decimal('meta', 12, 2)->nullable();
        });

        Schema::create('indicador_valor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicador_id')->constrained('indicador_gestion');
            $table->date('periodo_inicio');
            $table->date('periodo_fin');
            $table->decimal('valor', 14, 2)->nullable();
            $table->timestamp('fecha_calculo')->useCurrent();
            $table->unique(['indicador_id', 'periodo_inicio', 'periodo_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicador_valor');
        Schema::dropIfExists('indicador_gestion');
        Schema::dropIfExists('delegacion_autoridad');
    }
};
