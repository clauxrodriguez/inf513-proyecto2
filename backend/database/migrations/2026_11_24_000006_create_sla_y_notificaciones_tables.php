<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SLA de 72 horas hábiles (configuración, feriados, tiempos por fase y alertas) y notificaciones web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_tramite_id')->nullable()->unique()->constrained('tipo_tramite');
            $table->decimal('plazo_horas', 6, 2)->default(72);
            $table->decimal('porcentaje_alerta', 5, 2)->default(75);
        });

        DB::statement('ALTER TABLE sla_config
            ADD CONSTRAINT ck_sla_porcentaje CHECK (porcentaje_alerta BETWEEN 1 AND 99)');

        Schema::create('feriado', function (Blueprint $table) {
            $table->date('fecha')->primary();
            $table->foreignId('sla_config_id')->default(1)->constrained('sla_config')->cascadeOnDelete();
            $table->string('descripcion', 120);
            $table->foreignId('registrado_por')->nullable()->constrained('usuario')->nullOnDelete();
        });

        Schema::create('tramite_tiempo_fase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->index()->constrained('tramite')->cascadeOnDelete();
            $table->string('estado', 25);
            $table->timestamp('fecha_inicio');
            $table->timestamp('fecha_fin')->nullable();
            $table->decimal('horas_habiles', 8, 2)->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('usuario');
        });

        Schema::create('alerta_sla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramite')->cascadeOnDelete();
            $table->enum('tipo', ['PREVENTIVA', 'VENCIDA']);
            $table->decimal('horas_transcurridas', 8, 2);
            $table->decimal('plazo_horas', 6, 2);
            $table->timestamp('fecha_generacion')->useCurrent();
            $table->boolean('atendida')->default(false);
            $table->foreignId('atendida_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_atencion')->nullable();
            $table->unique(['tramite_id', 'tipo']);
        });

        Schema::create('notificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuario')->cascadeOnDelete();
            $table->foreignId('tramite_id')->nullable()->constrained('tramite')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('mensaje', 500);
            $table->boolean('leida')->default(false);
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->index(['usuario_id', 'leida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion');
        Schema::dropIfExists('alerta_sla');
        Schema::dropIfExists('tramite_tiempo_fase');
        Schema::dropIfExists('feriado');
        Schema::dropIfExists('sla_config');
    }
};
