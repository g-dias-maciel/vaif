Type: research
Status: resolved
Blocked by: none

## Question

How can n8n trigger the creation of a new artist page when a contract closes and payment is accepted?

## Answer

See full research: [research/04-n8n-page-creation.md](research/04-n8n-page-creation.md)

**Recommended approach:** n8n HTTP Request node → PHP endpoint (`POST /api/artists/create.php`). PHP writes to its own container filesystem — no SSH needed.

**Critical prerequisite:** A persistent Docker volume for artist configs to survive Coolify redeploys.

**Minimal viable version:** Single webhook trigger → PHP creates dirs + config file. No image upload, no payment double-check.

**Fully automated:** Dual triggers (contract + payment), image download and write, atomic temp-file writes, rollback on failure, admin notification.

**Key constraint:** The PHP endpoint approach requires the endpoint to be secured (token/auth) and the Docker volume to be configured in Coolify.
