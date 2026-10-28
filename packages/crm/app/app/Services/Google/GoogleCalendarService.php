<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google Calendar client for a service account.
 *
 * We deliberately avoid the heavy google/apiclient package: the integration
 * only needs a signed JWT, a token exchange, and five REST calls. The service
 * account never sees a consent screen — each Artist shares their calendar with
 * its e-mail address instead.
 */
class GoogleCalendarService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://www.googleapis.com/calendar/v3/';

    private const SCOPES = 'https://www.googleapis.com/auth/calendar.events '
        .'https://www.googleapis.com/auth/calendar.readonly';

    /** @var array<string, mixed>|null */
    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (GoogleCalendarException) {
            return false;
        }
    }

    public function serviceAccountEmail(): ?string
    {
        try {
            return $this->credentials()['client_email'] ?? null;
        } catch (GoogleCalendarException) {
            return null;
        }
    }

    /**
     * The calendars this service account can write to. A calendar appears here
     * after the Artist shares it (Google sometimes takes a minute to list it).
     *
     * @return array<int, array{id: string, summary: string, timezone: ?string, access_role: ?string, primary: bool}>
     */
    public function listCalendars(): array
    {
        $data = $this->request('get', 'users/me/calendarList', [
            'minAccessRole' => 'writer',
            'maxResults' => 250,
        ]);

        return array_map(fn (array $calendar) => [
            'id' => (string) ($calendar['id'] ?? ''),
            'summary' => (string) ($calendar['summary'] ?? $calendar['id'] ?? ''),
            'timezone' => $calendar['timeZone'] ?? null,
            'access_role' => $calendar['accessRole'] ?? null,
            'primary' => (bool) ($calendar['primary'] ?? false),
        ], array_values(array_filter(
            $data['items'] ?? [],
            fn (array $calendar) => ($calendar['id'] ?? '') !== '',
        )));
    }

    /**
     * Read one calendar's metadata, which also proves the service account has
     * access before we save the connection.
     *
     * @return array{id: string, summary: string, timezone: ?string, access_role: ?string}
     */
    public function getCalendar(string $calendarId): array
    {
        $data = $this->request('get', 'calendars/'.$this->encode($calendarId));

        return [
            'id' => (string) ($data['id'] ?? $calendarId),
            'summary' => (string) ($data['summary'] ?? $calendarId),
            'timezone' => $data['timeZone'] ?? null,
            'access_role' => $data['accessRole'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function createEvent(string $calendarId, array $event): array
    {
        return $this->request(
            'post',
            'calendars/'.$this->encode($calendarId).'/events',
            ['sendUpdates' => 'none'],
            $event,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function updateEvent(string $calendarId, string $eventId, array $event): array
    {
        return $this->request(
            'patch',
            'calendars/'.$this->encode($calendarId).'/events/'.$this->encode($eventId),
            ['sendUpdates' => 'none'],
            $event,
        );
    }

    /**
     * Delete an event. A 404/410 means somebody already removed it in Google,
     * which is the state we wanted anyway.
     */
    public function deleteEvent(string $calendarId, string $eventId): void
    {
        try {
            $this->request(
                'delete',
                'calendars/'.$this->encode($calendarId).'/events/'.$this->encode($eventId),
                ['sendUpdates' => 'none'],
            );
        } catch (GoogleCalendarException $e) {
            if (! $e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * A short-lived OAuth access token minted from the service-account key.
     */
    public function accessToken(): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'google-calendar-token:'.sha1((string) $credentials['client_email']);

        return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($credentials) {
            return $this->requestToken($credentials);
        });
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function requestToken(array $credentials): string
    {
        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPES,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new GoogleCalendarException('Não foi possível assinar as credenciais do Google Agenda.');
        }

        try {
            $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header.'.'.$claims.'.'.$this->base64Url($signature),
            ]);
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Não foi possível falar com o Google Agenda. Tente novamente.');
        }

        if ($response->failed()) {
            throw new GoogleCalendarException(
                'Não foi possível autenticar no Google Agenda: '.$this->errorMessage($response),
                $response->status(),
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new GoogleCalendarException('O Google não devolveu um token de acesso.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = self::API_BASE.$path;

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $client = Http::withToken($this->accessToken())->acceptJson()->timeout(20);

        try {
            /** @var Response $response */
            $response = match ($method) {
                'get' => $client->get($url),
                'post' => $client->post($url, $body ?? []),
                'patch' => $client->patch($url, $body ?? []),
                'delete' => $client->delete($url),
                default => throw new GoogleCalendarException('Método Google Calendar inválido.'),
            };
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Não foi possível falar com o Google Agenda. Tente novamente.');
        }

        if ($response->failed()) {
            throw new GoogleCalendarException(
                $this->errorMessage($response),
                $response->status(),
            );
        }

        return $response->json() ?: [];
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->json('error');

        if (is_array($message)) {
            $message = $message['message'] ?? null;
        }

        return is_string($message) && $message !== ''
            ? $message
            : 'Erro '.$response->status().' do Google Agenda.';
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $raw = config('services.google_calendar.credentials');

        if (! is_string($raw) || trim($raw) === '') {
            throw new GoogleCalendarException(
                'Google Agenda não configurada no servidor (GOOGLE_SERVICE_ACCOUNT_JSON).',
            );
        }

        $raw = trim($raw);

        if (is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        } elseif (! str_starts_with($raw, '{')) {
            $decoded = base64_decode($raw, true);
            if (is_string($decoded) && str_starts_with(ltrim($decoded), '{')) {
                $raw = $decoded;
            }
        }

        $data = json_decode($raw, true);

        if (! is_array($data)
            || empty($data['client_email'])
            || empty($data['private_key'])) {
            throw new GoogleCalendarException('Credenciais do Google Agenda inválidas.');
        }

        return $this->credentials = $data;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
