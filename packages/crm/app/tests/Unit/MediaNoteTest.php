<?php

namespace Tests\Unit;

use App\Services\Agent\MediaNote;
use PHPUnit\Framework\TestCase;

class MediaNoteTest extends TestCase
{
    public function test_it_removes_duplicate_audio_segments(): void
    {
        $note = "Áudio do artista: primeira fala.\n"
            ."Áudio do artista: primeira fala.\n"
            ."Áudio do artista: segunda fala.";

        $this->assertSame(
            "Áudio do artista: primeira fala.\nÁudio do artista: segunda fala.",
            MediaNote::dedupe($note),
        );
    }

    public function test_it_removes_a_segment_repeated_inside_one_line(): void
    {
        $note = 'Áudio do artista: mesma fala. Áudio do artista: mesma fala.';

        $this->assertSame('Áudio do artista: mesma fala.', MediaNote::dedupe($note));
    }

    public function test_it_keeps_distinct_image_and_audio_segments(): void
    {
        $note = "Imagem enviada pelo artista: tabela de preços.\n"
            .'Áudio do artista: fala diferente.';

        $this->assertSame($note, MediaNote::dedupe($note));
    }
}
