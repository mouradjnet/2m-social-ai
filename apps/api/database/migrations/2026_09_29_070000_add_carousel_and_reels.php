<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrossel e Reels no Instagram (Etapa 3). Limites da Meta consultados em
 * 29/09/2026: carrossel de 2 a 10 itens (esta versao: so imagens), Reel com um
 * video (e capa opcional).
 *
 * - `content_slides`: as imagens do carrossel, em ordem. Trocar os slides grava
 *   revisao, e o PublishGate pede nova aprovacao (ADR-13).
 * - `contents.video_asset_id`: o video do Reel. A `image_asset_id` da peca vira a capa.
 * - `publications.media_type` + `media`: o snapshot do que foi aprovado — as URLs
 *   das imagens do carrossel, ou do video e da capa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            // Apagar a imagem tira o slide do rascunho; de peca aprovada a biblioteca
            // nem deixa apagar (AssetController::destroy).
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unique(['content_id', 'position']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('video_asset_id')->nullable()->after('image_asset_id')->constrained('assets')->nullOnDelete();
        });

        Schema::table('publications', function (Blueprint $table) {
            $table->string('media_type', 20)->default('IMAGE')->after('asset_id');
            $table->jsonb('media')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'media']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_asset_id');
        });

        Schema::dropIfExists('content_slides');
    }
};
