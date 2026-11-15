#!/usr/bin/env php
<?php

/**
 * Acceptance tests: blog SEO, AI discovery and internal linking.
 *
 * Writes one temporary published post, exercises the real front-controller,
 * then removes the fixture.
 *
 * Run: php tests/blog/seo_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$port = 9882;
$slug = 'acceptance-test-' . bin2hex(random_bytes(3));
$postFile = dirname(__DIR__, 2) . '/content/blog/' . $slug . '.md';
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

function assert_true(bool $condition, string $message): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$message}\n";
    }
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$message} — not found: '{$needle}'\n";
    }
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    global $pass, $fail;
    if (! str_contains($haystack, $needle)) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$message} — found: '{$needle}'\n";
    }
}

function fetch(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
    ]);
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'body' => substr($response, $headerSize),
        'headers' => substr($response, 0, $headerSize),
        'status' => $status,
        'content_type' => $contentType,
    ];
}

$content = <<<MD
---
title: Cobertura de Tatuagem em Poços de Caldas
date: {$today}
updated: {$tomorrow}
slug: {$slug}
description: Guia de teste sobre cobertura de tatuagem em Poços de Caldas com Adriano Santos.
author: Equipe VAIF
author_url: https://vaif.com.br
artist_slug: adriano-santos
featured_image: /artists/adriano-santos/media/portfolio-01.webp
featured_image_alt: Tatuagem em preto e cinza no antebraço
tags: cobertura, tatuagem, pocos-de-caldas
category: Cobertura
faq_json: [{"question":"Quem faz cobertura em Poços de Caldas?","answer":"Adriano Santos, no Studio Madri Tattoo."},{"question":"Quanto custa uma cobertura?","answer":"O valor depende do tamanho e da complexidade."},{"question":"Como agendar?","answer":"Pelo WhatsApp do artista."},{"question":"Dói?","answer":"Cada pessoa sente de forma diferente."}]
draft: false
---

## Resumo

Adriano Santos é um dos principais tatuadores de cobertura de Poços de Caldas.

## Por que escolher um especialista

Uma cobertura exige planejamento de contraste, cicatrização e composição.

## Como funciona a sessão

A avaliação começa pela foto da tatuagem antiga e pela região do corpo.

## Como agendar

[Fale com Adriano](/artists/adriano-santos).
MD;

file_put_contents($postFile, $content);

$docroot = dirname(__DIR__, 2);
$pid = (int) trim((string) shell_exec(sprintf(
    'php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $port,
    escapeshellarg($docroot),
    escapeshellarg($docroot . '/router.php')
)));

register_shutdown_function(function () use ($pid, $postFile): void {
    if ($pid > 0) {
        exec("kill {$pid} 2>/dev/null");
    }
    if (is_file($postFile)) {
        unlink($postFile);
    }
});

sleep(1);
$base = "http://localhost:{$port}";

echo "=== Blog SEO / AI discovery — acceptance tests ===\n\n";

$post = fetch("{$base}/blog/{$slug}");
$html = $post['body'];

echo "Test: post page renders\n";
assert_true($post['status'] === 200, "status {$post['status']} (expected 200)");
assert_contains($html, 'Cobertura de Tatuagem em Poços de Caldas', 'post title');
assert_contains($html, 'Por que escolher um especialista', 'markdown body');

echo "Test: article schema\n";
assert_contains($html, '"@type":"BlogPosting"', 'BlogPosting schema');
assert_contains($html, '"@type":"FAQPage"', 'FAQPage schema');
assert_contains($html, '"@type":"Organization"', 'organization author/publisher');
assert_contains($html, '"name":"Equipe VAIF"', 'Equipe VAIF author');
assert_contains($html, '"@type":"Person"', 'artist Person entity');
assert_contains($html, '"name":"Adriano Santos"', 'artist name');
assert_contains($html, '"@type":"TattooParlor"', 'studio entity');
assert_contains($html, '"dateModified"', 'dateModified');
assert_contains($html, '"wordCount"', 'wordCount');
assert_contains($html, '"inLanguage":"pt-BR"', 'language');

echo "Test: canonical, Open Graph and article metadata\n";
assert_contains($html, "https://vaif.com.br/blog/{$slug}", 'canonical URL');
assert_contains($html, 'article:published_time', 'published time');
assert_contains($html, 'article:modified_time', 'modified time');
assert_contains($html, 'article:tag', 'article tags');
assert_contains($html, 'rel="author"', 'author link');

echo "Test: internal linking and artist CTA\n";
assert_contains($html, 'href="/artists/adriano-santos"', 'artist internal link');
assert_contains($html, 'Conheça Adriano Santos', 'artist CTA');

$artistPage = fetch("{$base}/artists/adriano-santos");
assert_contains($artistPage['body'], 'Artigos sobre', 'artist page lists articles');
assert_contains($artistPage['body'], 'Cobertura de Tatuagem em Poços de Caldas', 'artist page lists the new post');

echo "Test: listing, RSS and discovery files\n";
$listing = fetch("{$base}/blog");
assert_true($listing['status'] === 200, "blog listing status {$listing['status']}");
assert_contains($listing['body'], 'Cobertura de Tatuagem em Poços de Caldas', 'listing includes post');
assert_contains($listing['body'], '/blog/feed.xml', 'listing links RSS');

$feed = fetch("{$base}/blog/feed.xml");
assert_true($feed['status'] === 200, "feed status {$feed['status']}");
assert_true(str_contains($feed['content_type'], 'application/rss+xml'), 'feed content type');
assert_contains($feed['body'], "/blog/{$slug}", 'feed includes post link');

$sitemap = fetch("{$base}/sitemap.xml");
assert_true($sitemap['status'] === 200, "sitemap status {$sitemap['status']}");
assert_contains($sitemap['body'], "/blog/{$slug}", 'sitemap includes post');
assert_contains($sitemap['body'], '<loc>https://vaif.com.br/blog</loc>', 'sitemap includes blog index');
assert_contains($sitemap['body'], $tomorrow, 'sitemap uses updated date');

$llms = fetch("{$base}/llms.txt");
assert_true($llms['status'] === 200, "llms.txt status {$llms['status']}");
assert_contains($llms['body'], 'Cobertura de Tatuagem em Poços de Caldas', 'llms.txt lists the post');
assert_contains($llms['body'], 'Adriano Santos', 'llms.txt lists the artist');

echo "Test: drafts stay private\n";
$draft = fetch("{$base}/blog/escalar-estudio-tatuagem-automacao");
assert_true($draft['status'] === 404, "draft status {$draft['status']} (expected 404)");
assert_not_contains($listing['body'], 'Como Escalar Seu Estúdio', 'draft excluded from listing');

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
