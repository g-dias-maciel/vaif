<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\CalendarSlot;
use App\Models\Lead;
use App\Models\Tattoo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private const BRUNO_ID = 'b0000000-0000-0000-0000-000000000001';

    public function run(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        // VAIF staff user (always).
        User::firstOrCreate(
            ['email' => 'admin@vaif.com.br'],
            [
                'name' => 'VAIF Staff',
                'password' => Hash::make('password'),
                'role' => User::ROLE_STAFF,
            ],
        );

        // Demo artist: created on sqlite, looked up on postgres.
        $artist = $isSqlite ? $this->seedArtist() : Artist::find(self::BRUNO_ID);

        if ($artist) {
            User::firstOrCreate(
                ['email' => 'bruno@vaif.com.br'],
                [
                    'name' => 'Bruno',
                    'password' => Hash::make('password'),
                    'role' => User::ROLE_ARTIST,
                    'artist_id' => self::BRUNO_ID,
                ],
            );
        }

        // Demo data only makes sense on a fresh local (sqlite) DB.
        if ($isSqlite) {
            $this->seedDemoLeads();
            $this->seedDemoTattoos();
            $this->seedDemoBookings();
            $this->seedDemoPricing();
            $this->seedDemoReturningCustomers();
            $this->seedDemoPromotions();
            $this->seedDemoPromotionQuotes();
        }
    }

    private function seedDemoPromotionQuotes(): void
    {
        // Mariana accepted a "Leve 2" quote — still inside the 7-day window.
        \App\Models\PromotionQuote::firstOrCreate(
            ['id' => '55555555-0000-0000-0000-000000000001'],
            [
                'promotion_id' => '44444444-0000-0000-0000-000000000001',
                'lead_id' => '11111111-0000-0000-0000-000000000003',
                'artist_id' => self::BRUNO_ID,
                'placement' => 'costas',
                'body_zone' => 'medio',
                'first_price' => 140000,
                'second_cap_price' => 70000,
                'quoted_at' => now()->subDays(2),
                'valid_until' => now()->addDays(5),
                'status' => 'open',
            ],
        );

        // João was quoted during a previous promotion; the window has passed.
        \App\Models\PromotionQuote::firstOrCreate(
            ['id' => '55555555-0000-0000-0000-000000000002'],
            [
                'promotion_id' => '44444444-0000-0000-0000-000000000001',
                'lead_id' => '11111111-0000-0000-0000-000000000004',
                'artist_id' => self::BRUNO_ID,
                'placement' => 'braco_externo',
                'body_zone' => 'grande',
                'first_price' => 180000,
                'second_cap_price' => 90000,
                'quoted_at' => now()->subDays(10),
                'valid_until' => now()->subDays(3),
                'status' => 'open',
            ],
        );
    }

    private function seedDemoPromotions(): void
    {
        DB::table('promotion_types')->insertOrIgnore([
            'key' => 'bogo_same_value',
            'name' => 'Leve 2 pelo valor de {multiplier}x',
            'description' => 'Compre 1 tatuagem e leve outra de mesmo valor ou menor.',
            'config_spec' => json_encode(['multiplier' => 2.0, 'quote_valid_days' => 7]),
            'active' => true,
            'created_at' => now(),
        ]);

        \App\Models\Promotion::firstOrCreate(
            ['id' => '44444444-0000-0000-0000-000000000001'],
            [
                'artist_id' => self::BRUNO_ID,
                'type_key' => 'bogo_same_value',
                'name' => 'Leve 2 pelo valor de 2x',
                'multiplier' => 2.0,
                'audience' => 'all',
                'conditions' => [],
                'quote_valid_days' => 7,
                'status' => 'active',
                'activated_at' => now(),
            ],
        );

        \App\Models\Promotion::firstOrCreate(
            ['id' => '44444444-0000-0000-0000-000000000002'],
            [
                'artist_id' => self::BRUNO_ID,
                'type_key' => 'bogo_same_value',
                'name' => 'Leve 2 de Verão',
                'multiplier' => 2.0,
                'audience' => 'new',
                'conditions' => [],
                'quote_valid_days' => 7,
                'status' => 'ended',
                'activated_at' => now()->subMonths(4),
                'ended_at' => now()->subMonths(3),
            ],
        );
    }

    private function seedDemoReturningCustomers(): void
    {
        DB::table('returning_customers')->insertOrIgnore([
            'artist_id' => self::BRUNO_ID,
            'telefone' => '5511988880001', // Ana — old client returning
            'nome' => 'Ana',
            'source' => 'imported',
            'confirmed_at' => now()->subMonths(6),
        ]);
    }

    private function seedDemoPricing(): void
    {
        $rows = [
            // região perna
            ['perna', 'panturrilha', 'pequeno', 25000, 90],
            ['perna', 'panturrilha', 'medio', 50000, 120],
            ['perna', 'panturrilha', 'grande', 80000, 180],
            ['perna', 'panturrilha', 'fechamento', 110000, 240],
            ['perna', 'coxa', 'pequeno', 30000, 90],
            ['perna', 'coxa', 'medio', 60000, 120],
            ['perna', 'coxa', 'grande', 90000, 180],
            ['perna', 'coxa', 'fechamento', 140000, 300],
            // região braco
            ['braco', 'antebraco', 'pequeno', 30000, 90],
            ['braco', 'antebraco', 'medio', 60000, 120],
            ['braco', 'antebraco', 'grande', 90000, 180],
            ['braco', 'antebraco', 'fechamento', 120000, 240],
            ['braco', 'braco_externo', 'pequeno', 30000, 90],
            ['braco', 'braco_externo', 'medio', 60000, 120],
            ['braco', 'braco_externo', 'grande', 90000, 180],
            ['braco', 'braco_externo', 'fechamento', 150000, 300],
            // região tronco
            ['tronco', 'costas', 'pequeno', 35000, 90],
            ['tronco', 'costas', 'medio', 70000, 150],
            ['tronco', 'costas', 'grande', 120000, 240],
            ['tronco', 'costas', 'fechamento', 200000, 360],
            ['tronco', 'peito', 'pequeno', 30000, 90],
            ['tronco', 'peito', 'medio', 60000, 120],
            ['tronco', 'peito', 'grande', 100000, 180],
            ['tronco', 'peito', 'fechamento', 160000, 300],
        ];

        foreach ($rows as [$region, $placement, $bodyZone, $price, $duration]) {
            \App\Models\Pricing::firstOrCreate(
                ['artist_id' => self::BRUNO_ID, 'placement' => $placement, 'body_zone' => $bodyZone],
                [
                    'region' => $region,
                    'table_price' => $price,
                    'session_duration_min' => $duration,
                    'buffer_min' => 30,
                ],
            );
        }
    }

    private function seedArtist(): Artist
    {
        return Artist::firstOrCreate(
            ['id' => self::BRUNO_ID],
            [
                'nome' => 'Bruno',
                'display_name' => 'Bruno',
                'status' => 'live',
                'timezone' => 'America/Sao_Paulo',
                'instagram_handle' => '@bruno.tattoo',
                'whatsapp_number' => '5511999990001',
                'wa_session_slug' => 'bruno-tattoo',
                'working_hours' => [
                    'seg' => ['09:00-12:00', '14:00-18:00'],
                    'ter' => ['09:00-12:00', '14:00-18:00'],
                    'qua' => ['09:00-12:00', '14:00-18:00'],
                    'qui' => ['09:00-12:00', '14:00-18:00'],
                    'sex' => ['09:00-12:00', '14:00-18:00'],
                    'sab' => ['09:00-13:00'],
                ],
            ],
        );
    }

    private function lead(string $id, array $attrs): void
    {
        Lead::firstOrCreate(
            ['id' => $id],
            array_merge(['artist_id' => self::BRUNO_ID], $attrs),
        );
    }

    private function tattoo(string $id, string $leadId, array $attrs): void
    {
        Tattoo::firstOrCreate(
            ['id' => $id],
            array_merge(['lead_id' => $leadId, 'artist_id' => self::BRUNO_ID], $attrs),
        );
    }

    private function booking(string $id, string $leadId, string $tattooId, $start, int $durationMin): void
    {
        CalendarSlot::firstOrCreate(
            ['id' => $id],
            [
                'artist_id' => self::BRUNO_ID,
                'lead_id' => $leadId,
                'tattoo_id' => $tattooId,
                'type' => 'booked',
                'start_at' => $start,
                'end_at' => $start->copy()->addMinutes($durationMin),
            ],
        );
    }

    private function seedDemoLeads(): void
    {
        $this->lead('11111111-0000-0000-0000-000000000001', [
            'nome' => 'Ana', 'telefone' => '5511988880001', 'lead_source' => 'instagram',
        ]);

        $this->lead('11111111-0000-0000-0000-000000000002', [
            'nome' => 'Carlos', 'telefone' => '5511988880002', 'lead_source' => 'whatsapp',
            'created_at' => now()->subDays(5),
        ]);

        $this->lead('11111111-0000-0000-0000-000000000003', [
            'nome' => 'Mariana', 'telefone' => '5511988880003', 'lead_source' => 'ads',
            'created_at' => now()->subMonths(1),
        ]);

        $this->lead('11111111-0000-0000-0000-000000000004', [
            'nome' => 'João', 'telefone' => '5511988880004', 'lead_source' => 'whatsapp',
            'created_at' => now()->subMonths(2),
        ]);

        $this->lead('11111111-0000-0000-0000-000000000005', [
            'nome' => 'Fernanda', 'telefone' => '5511988880005', 'lead_source' => 'instagram',
        ]);

        $this->lead('11111111-0000-0000-0000-000000000006', [
            'nome' => 'Rafael', 'telefone' => '5511988880006', 'lead_source' => 'ads',
        ]);

        $this->lead('11111111-0000-0000-0000-000000000007', [
            'nome' => 'Pedro', 'telefone' => '5511988880007', 'lead_source' => 'whatsapp',
            'created_at' => now()->subMonths(3),
        ]);

        $this->lead('11111111-0000-0000-0000-000000000008', [
            'nome' => 'Carla', 'telefone' => '5511988880008', 'lead_source' => 'instagram',
            'created_at' => now()->subMonths(2),
        ]);

        $this->lead('11111111-0000-0000-0000-000000000009', [
            'nome' => 'Gustavo', 'telefone' => '5511988880009', 'lead_source' => 'ads',
            'created_at' => now()->subDay(),
        ]);
    }

    private function seedDemoTattoos(): void
    {
        // Ana — returning client with a past tattoo + a new inquiry.
        $this->tattoo('33333333-0000-0000-0000-000000000011', '11111111-0000-0000-0000-000000000001', [
            'placement' => 'costas', 'body_zone' => 'medio', 'style' => 'realismo',
            'pipeline_status' => 'fechado',
            'table_price' => 40000, 'deposit_amount' => 12000, 'deposit_status' => 'confirmado',
            'booked_date' => now()->subMonths(6)->setTime(10, 0),
            'session_duration_min' => 150, 'buffer_min' => 30,
            'created_at' => now()->subMonths(6),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000001', '11111111-0000-0000-0000-000000000001', [
            'pipeline_status' => 'novo',
            'created_at' => now(),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000002', '11111111-0000-0000-0000-000000000002', [
            'placement' => 'antebraco', 'body_zone' => 'pequeno', 'style' => 'realismo',
            'primeira_tatuagem' => true,
            'pipeline_status' => 'qualificando',
            'created_at' => now()->subDays(5),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000003', '11111111-0000-0000-0000-000000000003', [
            'placement' => 'costas', 'body_zone' => 'medio', 'style' => 'blackwork',
            'tipo_tatuagem' => 'nova', 'significado' => 'Fênix representando recomeço',
            'table_price' => 70000, 'negotiated_price' => 65000,
            'pipeline_status' => 'orcamento_enviado',
            'created_at' => now()->subMonths(1),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000004', '11111111-0000-0000-0000-000000000004', [
            'placement' => 'braco_externo', 'body_zone' => 'grande', 'style' => 'old_school',
            'table_price' => 90000, 'deposit_amount' => 27000, 'deposit_status' => 'aguardando_confirmacao',
            'pipeline_status' => 'aguardando_deposito',
            'created_at' => now()->subMonths(2),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000005', '11111111-0000-0000-0000-000000000005', [
            'placement' => 'panturrilha', 'body_zone' => 'medio', 'style' => 'realismo',
            'primeira_tatuagem' => false, 'tipo_tatuagem' => 'cobertura',
            'significado' => 'Homenagem à mãe',
            'reference_pics' => ['https://placehold.co/300x300?text=Ref+1', 'https://placehold.co/300x300?text=Ref+2'],
            'table_price' => 50000, 'deposit_amount' => 15000, 'deposit_status' => 'confirmado',
            'pipeline_status' => 'agendado',
            'booked_date' => now()->addDays(1)->setTime(14, 0),
            'session_duration_min' => 120, 'buffer_min' => 30,
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000006', '11111111-0000-0000-0000-000000000006', [
            'placement' => 'peito', 'body_zone' => 'grande', 'style' => 'blackwork',
            'table_price' => 100000, 'deposit_status' => 'confirmado',
            'pipeline_status' => 'agendado',
            'booked_date' => now()->addDays(2)->setTime(10, 0),
            'session_duration_min' => 180, 'buffer_min' => 30,
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000007', '11111111-0000-0000-0000-000000000007', [
            'placement' => 'perna', 'body_zone' => 'grande', 'style' => 'old_school',
            'pipeline_status' => 'perdido',
            'created_at' => now()->subMonths(3),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000008', '11111111-0000-0000-0000-000000000008', [
            'placement' => 'costas', 'body_zone' => 'grande', 'style' => 'blackwork',
            'tipo_tatuagem' => 'cobertura', 'primeira_tatuagem' => false,
            'table_price' => 60000, 'deposit_amount' => 18000, 'deposit_status' => 'confirmado',
            'pipeline_status' => 'fechado',
            'booked_date' => now()->subMonths(2)->setTime(10, 0),
            'session_duration_min' => 180, 'buffer_min' => 30,
            'created_at' => now()->subMonths(2),
        ]);

        $this->tattoo('33333333-0000-0000-0000-000000000009', '11111111-0000-0000-0000-000000000009', [
            'placement' => 'costas', 'body_zone' => 'medio', 'style' => 'blackwork',
            'table_price' => 70000, 'deposit_amount' => 21000, 'deposit_status' => 'aguardando_confirmacao',
            'pipeline_status' => 'agendado',
            'booked_date' => now()->addDays(3)->setTime(10, 0),
            'session_duration_min' => 150, 'buffer_min' => 30,
            'created_at' => now()->subDay(),
        ]);
    }

    private function seedDemoBookings(): void
    {
        $this->booking(
            '22222222-0000-0000-0000-000000000001',
            '11111111-0000-0000-0000-000000000005',
            '33333333-0000-0000-0000-000000000005',
            now()->addDays(1)->setTime(14, 0),
            120,
        );

        $this->booking(
            '22222222-0000-0000-0000-000000000002',
            '11111111-0000-0000-0000-000000000006',
            '33333333-0000-0000-0000-000000000006',
            now()->addDays(2)->setTime(10, 0),
            180,
        );

        $this->booking(
            '22222222-0000-0000-0000-000000000003',
            '11111111-0000-0000-0000-000000000009',
            '33333333-0000-0000-0000-000000000009',
            now()->addDays(3)->setTime(10, 0),
            150,
        );
    }
}
