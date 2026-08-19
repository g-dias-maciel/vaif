# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Brazilian high-end tattoo artists at two stages:
- **Breaking through (R$5–10k/mo):** Have a base clientele but stuck — working too many hours on admin instead of tattooing, missing leads, unable to reach the next revenue tier.
- **Scaling further (R$15k+/mo):** Already successful — need systems to handle volume, free their time, and grow without adding headcount.

Both segments arrive frustrated with administrative overload and skeptical of marketing agencies (often burned by generic agencies that don't understand tattoo industry economics).

## Product Purpose

VAIF is a growth agency built exclusively for tattoo studios in Brazil. It replaces manual lead management with an automated capture machine — Meta/Google Ads, an AI SDR agent on WhatsApp, a CRM, and booking automation — so artists earn more while spending less time on non-tattooing work.

The landing page (`packages/lp/`) is the agency's public storefront and primary lead generation surface. Its job is to convert visiting artists into qualified consultation requests via the qualifying form.

## Positioning

VAIF is the only growth agency in Brazil purpose-built for tattoo studios — not a generic marketing shop that also serves dentists and lawyers. The agency speaks tattoo-industry economics natively (ticket médio, sessões, horas de agulha) and proves results with real studio revenue numbers rather than vanity metrics.

## Operating Context

Tattoo artists work 8–12 hour sessions, check WhatsApp sporadically between clients, and lose leads daily due to slow response. They need proof before trusting an agency: calculators that reveal their hidden loss, real artist results, and a process that respects their craft identity. Their purchase decision is emotional (regaining time, dignity, and revenue) wrapped in a rational frame (ROI, systems, automation).

## Capabilities and Constraints

**Surfaces:** Main landing page (`index.php`), calculator funnel (`calculadora.php`), self-serve onboarding portal (`onboard/`).

**Capabilities:** Hero section, value proposition grid (3 pillars), AI SDR demo dashboard, services grid (6 services), calculator teaser, qualifying lead form with WhatsApp/Instagram fields, footer with navigation and contact.

**Backend:** PHP 8 API endpoints — lead submission, calendar slot inventory, booking confirmation — all forward to n8n webhooks. MySQL/MariaDB leads database.

**Analytics:** Matomo (self-hosted) + Facebook Pixel (ID: `752550821217294`).

**Constraints:** Portuguese-only (pt-BR), Brazilian market, no framework dependency (pure HTML/CSS/JS), deployed via Coolify.

## Brand Commitments

- **Name:** VAIF — final and locked.
- **Logo:** `packages/lp/img/vaif_logo.png` — final and locked.
- **Visual world, palette, typography, voice:** Open to evolution. The current gold-on-black luxury aesthetic is incumbent but not binding.

## Evidence on Hand

- 40+ studios served across Brazil.
- R$18k average monthly revenue increase per studio.
- 78% lead-to-booking conversion rate.
- R$12 average cost per qualified lead.
- Artist result screenshots: `packages/lp/img/rsilva_resultado.png`, `dinho_resultado.png`, `guitattoo_resultado.jpeg`.
- Client roster (Instagram handles in marquee): Jhonatan Masters, Rodrigo Silva, Sergio Moraes, Kleber Rocker, Bueno Tattoo, Dinho Tattoo.
- **Absences:** No video testimonials, no case study write-ups, no third-party press or awards.

## Product Principles

1. **Prove before you promise.** The calculator reveals the artist's hidden loss before VAIF pitches recovery — evidence first, claim second.
2. **Automation that doesn't feel automated.** The AI SDR agent speaks the artist's tone of voice. Leads should never know they're talking to software.
3. **Premium positioning serves premium artists.** Copy, pricing signals, and qualification logic filter out bargain-seekers upfront. VAIF works for artists who already have earning power.
4. **Results over vanity metrics.** Every number on the page is a real studio revenue outcome, not an impression count or click rate.
5. **Tattoo-industry fluency.** Every headline, stat, and form field speaks the artist's world — sessões, ticket médio, horas de agulha, Direct, portfólio.

## Accessibility & Inclusion

No product-specific accessibility requirements established. The page targets a Brazilian Portuguese-speaking audience; no i18n requirement.
