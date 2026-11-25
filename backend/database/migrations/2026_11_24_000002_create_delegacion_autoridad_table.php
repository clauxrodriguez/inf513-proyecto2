<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delegación de autoridad (suplencias) del Vicerrector.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('delegacion_autoridad');
    }
};
