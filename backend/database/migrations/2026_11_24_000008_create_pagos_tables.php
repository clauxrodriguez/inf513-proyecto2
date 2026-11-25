<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos en línea al contado con Stripe: tarjetas tokenizadas por usuario (sin datos sensibles)
 * y transacciones de pago. Un pago exitoso se registra además como recibo en comprobante_caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuario', function (Blueprint $table) {
            $table->string('stripe_customer_id', 100)->nullable()->unique();
        });

        Schema::create('metodo_pago_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuario')->cascadeOnDelete();
            $table->string('stripe_payment_method_id', 100)->unique();
            $table->string('marca', 30);
            $table->char('ultimos_cuatro', 4);
            $table->smallInteger('mes_expiracion');
            $table->smallInteger('anio_expiracion');
            $table->boolean('predeterminado')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamp('fecha_registro')->useCurrent();
            $table->index(['usuario_id', 'activo']);
        });

        DB::statement('ALTER TABLE metodo_pago_usuario
            ADD CONSTRAINT ck_metodo_mes CHECK (mes_expiracion BETWEEN 1 AND 12),
            ADD CONSTRAINT ck_metodo_ultimos CHECK (ultimos_cuatro ~ \'^[0-9]{4}$\')');

        Schema::create('transaccion_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->index()->constrained('tramite');
            $table->foreignId('usuario_id')->constrained('usuario');
            $table->foreignId('metodo_pago_id')->nullable()->constrained('metodo_pago_usuario')->nullOnDelete();
            $table->foreignId('comprobante_caja_id')->nullable()->unique()->constrained('comprobante_caja')->nullOnDelete();
            $table->string('stripe_payment_intent_id', 100)->unique();
            $table->decimal('monto', 10, 2);
            $table->char('moneda', 3);
            $table->enum('estado', ['PENDIENTE', 'REQUIERE_ACCION', 'EXITOSO', 'FALLIDO', 'CANCELADO'])->default('PENDIENTE');
            $table->string('mensaje_error', 255)->nullable();
            $table->string('recibo_url', 500)->nullable();
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->timestamp('fecha_pago')->nullable();
        });

        // Varios intentos por trámite, pero un único pago EXITOSO (pago al contado)
        DB::statement('ALTER TABLE transaccion_pago ADD CONSTRAINT ck_transaccion_monto CHECK (monto > 0)');
        DB::statement("CREATE UNIQUE INDEX ux_transaccion_exitosa_por_tramite ON transaccion_pago (tramite_id) WHERE estado = 'EXITOSO'");
    }

    public function down(): void
    {
        Schema::dropIfExists('transaccion_pago');
        Schema::dropIfExists('metodo_pago_usuario');
        Schema::table('usuario', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
        });
    }
};
