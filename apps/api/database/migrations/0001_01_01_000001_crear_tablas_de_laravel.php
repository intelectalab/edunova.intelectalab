<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas propias de Laravel que el esquema del kit no trae.
 * «users» ya existe en el esquema; sesiones, caché y colas usan Redis.
 * Son tablas globales (sin institucion_id), como las de docs/rls-tablas-globales.md.
 */
return new class extends Migration
{
    private const TABLAS = ['personal_access_tokens', 'password_reset_tokens', 'failed_jobs', 'job_batches'];

    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $tablas = implode(', ', self::TABLAS);
        DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tablas} TO edunova_app, edunova_plataforma;");
        DB::unprepared('GRANT USAGE, SELECT ON SEQUENCE personal_access_tokens_id_seq, failed_jobs_id_seq TO edunova_app, edunova_plataforma;');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
