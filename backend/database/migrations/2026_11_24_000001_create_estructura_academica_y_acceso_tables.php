<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura académica, usuarios, RBAC (rol - permiso), menú dinámico y tokens de Sanctum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facultad', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 150);
            $table->integer('anio_creacion')->nullable();
            $table->integer('orden_prelacion')->unique();
        });

        Schema::create('carrera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facultad_id')->constrained('facultad');
            $table->string('codigo', 15)->unique();
            $table->string('nombre', 150);
            $table->enum('regimen', ['SEMESTRAL', 'ANUAL'])->default('SEMESTRAL');
        });

        Schema::create('rol', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 80);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
        });

        Schema::create('permiso', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
        });

        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('rol')->cascadeOnDelete();
            $table->foreignId('permiso_id')->constrained('permiso')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->primary(['rol_id', 'permiso_id']);
        });

        Schema::create('menu_opcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('padre_id')->nullable()->constrained('menu_opcion')->cascadeOnDelete();
            $table->foreignId('permiso_id')->nullable()->constrained('permiso')->nullOnDelete();
            $table->string('titulo', 100);
            $table->string('ruta', 150)->nullable();
            $table->string('icono', 50)->nullable();
            $table->integer('orden')->default(1);
            $table->boolean('activo')->default(true);
        });

        Schema::create('usuario', function (Blueprint $table) {
            $table->id();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('ci', 20)->unique();
            $table->string('registro', 20)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('telefono', 30)->nullable();
            $table->string('password_hash', 255);
            $table->string('cargo', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('fecha_creacion')->useCurrent();
        });

        Schema::create('usuario_rol', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('usuario')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('rol')->cascadeOnDelete();
            $table->primary(['usuario_id', 'rol_id']);
        });

        Schema::create('usuario_carrera', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('usuario')->cascadeOnDelete();
            $table->foreignId('carrera_id')->constrained('carrera')->cascadeOnDelete();
            $table->date('fecha_ingreso')->default(new Expression('CURRENT_DATE'));
            $table->primary(['usuario_id', 'carrera_id']);
        });

        // Tokens Bearer de Sanctum: el único modelo autenticable es usuario, por eso lleva FK real
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('tokenable_id')->references('id')->on('usuario')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('usuario_carrera');
        Schema::dropIfExists('usuario_rol');
        Schema::dropIfExists('usuario');
        Schema::dropIfExists('menu_opcion');
        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('permiso');
        Schema::dropIfExists('rol');
        Schema::dropIfExists('carrera');
        Schema::dropIfExists('facultad');
    }
};
