<?php

namespace Tests\Unit;

use App\Services\Agent\CoverPhoto;
use PHPUnit\Framework\TestCase;

class CoverPhotoTest extends TestCase
{
    public function test_a_photo_classified_as_the_current_tattoo_counts(): void
    {
        $this->assertTrue(CoverPhoto::received(
            [],
            ['papel' => 'tatuagem_atual'],
            ['lead_photos' => true, 'mentions_coverup' => true],
            'cover_up',
        ));
    }

    public function test_a_reference_photo_does_not_count_as_the_current_tattoo(): void
    {
        $this->assertFalse(CoverPhoto::received(
            [],
            ['papel' => 'referencia'],
            ['lead_photos' => true, 'mentions_coverup' => true],
            'cover_up',
        ));
    }

    public function test_a_historical_current_tattoo_photo_still_counts(): void
    {
        $this->assertTrue(CoverPhoto::received(
            ['papel' => 'tatuagem_atual'],
            ['papel' => 'referencia'],
            ['lead_photos' => true],
            'cover_up',
        ));
    }

    public function test_a_legacy_cover_up_photo_without_role_is_the_fallback(): void
    {
        $this->assertTrue(CoverPhoto::received(
            ['assunto' => 'tatuagem antiga'],
            null,
            ['lead_photos' => true, 'mentions_coverup' => true],
            null,
        ));
    }

    public function test_a_reference_photo_in_a_new_tattoo_chat_is_not_a_cover_photo(): void
    {
        $this->assertFalse(CoverPhoto::received(
            [],
            ['papel' => 'referencia'],
            ['lead_photos' => true],
            'new',
        ));
    }
}
