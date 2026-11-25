<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guía procesal, observaciones, bitácora de negocio (inalterable), bitácora de accesos web
 * y contador de visitas por página.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flujo_paso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_tramite_id')->constrained('tipo_tramite');
            $table->enum('destino', ['NACIONAL', 'EXTERIOR'])->nullable();
            $table->integer('orden');
            $table->foreignId('unidad_id')->constrained('unidad_origen');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->string('firma_requerida', 150)->nullable();
            $table->string('sello_requerido', 150)->nullable();
            $table->string('estado_asociado', 25)->nullable();
            $table->unique(['tipo_tramite_id', 'orden']);
        });

        Schema::create('observacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramite')->cascadeOnDelete();
            $table->foreignId('tramite_documento_id')->nullable()->constrained('tramite_documento')->nullOnDelete();
            $table->enum('tipo', [
                'FOJA_ILEGIBLE', 'SELLO_FALTANTE', 'FIRMA_FALTANTE', 'PAGO_INSUFICIENTE',
                'FOLIACION_INCORRECTA', 'DOCUMENTO_EXTERNO', 'FUERA_DE_PLAZO', 'OTRO',
            ]);
            $table->string('descripcion', 500);
            $table->enum('estado', ['PENDIENTE', 'SUBSANADA', 'ANULADA'])->default('PENDIENTE');
            $table->foreignId('registrado_por')->nullable()->constrained('usuario');
            $table->timestamp('fecha_registro')->useCurrent();
            $table->timestamp('fecha_subsanacion')->nullable();
            $table->index(['tramite_id', 'estado']);
        });

        // Inalterable: sus FK no tienen acción de borrado, porque un SET NULL sería un UPDATE prohibido.
        // Cada evento apunta con FK explícitas a lo que afectó: el trámite y, según la acción, la delegación,
        // el certificado oficial (certificado_id, en 000005) o el pago en línea (transaccion_pago_id, en 000008).
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->nullable()->index()->constrained('tramite');
            $table->foreignId('usuario_id')->nullable()->constrained('usuario');
            $table->string('accion', 60);
            $table->foreignId('delegacion_id')->nullable()->index()->constrained('delegacion_autoridad');
            $table->jsonb('detalle')->nullable();
            $table->timestamp('fecha', 6)->useCurrent();
            $table->char('hash_anterior', 64);
            $table->char('hash_actual', 64);
        });

        Schema::create('bitacora_acceso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuario')->nullOnDelete();
            $table->string('email_intento', 150)->nullable();
            $table->enum('accion', ['LOGIN_OK', 'LOGIN_FALLIDO', 'LOGOUT', 'ACCESO_RECURSO']);
            $table->string('ruta', 255)->index();
            $table->string('metodo_http', 10)->default('GET');
            $table->string('ip_origen', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('fecha')->useCurrent();
            $table->index(['usuario_id', 'fecha']);
        });

        // Clave por ruta: cuenta también las páginas públicas; menu_opcion_id se llena si la página está en el menú
        Schema::create('contador_visita', function (Blueprint $table) {
            $table->id();
            $table->string('ruta', 150)->unique();
            $table->string('titulo_pagina', 120);
            $table->foreignId('menu_opcion_id')->nullable()->constrained('menu_opcion')->nullOnDelete();
            $table->bigInteger('visitas')->default(0);
            $table->timestamp('ultima_visita')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contador_visita');
        Schema::dropIfExists('bitacora_acceso');
        Schema::dropIfExists('bitacora');
        Schema::dropIfExists('observacion');
        Schema::dropIfExists('flujo_paso');
    }
};
