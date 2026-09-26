<?php
/**
 * AI / LLM configuration for erpAgro Q&A
 *
 * Supports any OpenAI-compatible API:
 *   • OpenAI          — https://api.openai.com/v1
 *   • Groq            — https://api.groq.com/openai/v1
 *   • Together AI     — https://api.together.xyz/v1
 *   • Local Ollama    — http://localhost:11434/v1
 *   • Any other proxy
 *
 * Priority (highest → lowest):
 *   1. $_SESSION['agro_ai_*']   set via in-page config panel
 *   2. Environment variables    AGRO_AI_KEY, AGRO_AI_ENDPOINT, AGRO_AI_MODEL
 *   3. Compile-time constants   defined below (empty = disabled)
 */

// ── Compile-time defaults (edit here OR leave empty and use the in-page panel) ──
if (!defined('AGRO_AI_ENDPOINT')) define('AGRO_AI_ENDPOINT', 'https://api.groq.com/openai/v1');
if (!defined('AGRO_AI_MODEL'))    define('AGRO_AI_MODEL',    'llama-3.3-70b-versatile');
if (!defined('AGRO_AI_KEY'))      define('AGRO_AI_KEY',      '');   // ← paste key here, or use in-page panel
if (!defined('AGRO_AI_TIMEOUT'))  define('AGRO_AI_TIMEOUT',  18);   // seconds

// ── Runtime resolution ────────────────────────────────────────────────────────

function agro_ai_key(): string {
    if (!empty($_SESSION['agro_ai_key']))      return (string)$_SESSION['agro_ai_key'];
    if (!empty($_ENV['AGRO_AI_KEY']))          return (string)$_ENV['AGRO_AI_KEY'];
    if (!empty(getenv('AGRO_AI_KEY')))         return (string)getenv('AGRO_AI_KEY');
    return (string)AGRO_AI_KEY;
}

function agro_ai_endpoint(): string {
    if (!empty($_SESSION['agro_ai_endpoint'])) return rtrim((string)$_SESSION['agro_ai_endpoint'], '/');
    if (!empty($_ENV['AGRO_AI_ENDPOINT']))      return rtrim((string)$_ENV['AGRO_AI_ENDPOINT'], '/');
    if (!empty(getenv('AGRO_AI_ENDPOINT')))     return rtrim((string)getenv('AGRO_AI_ENDPOINT'), '/');
    return rtrim((string)AGRO_AI_ENDPOINT, '/');
}

function agro_ai_model(): string {
    if (!empty($_SESSION['agro_ai_model']))    return (string)$_SESSION['agro_ai_model'];
    if (!empty($_ENV['AGRO_AI_MODEL']))        return (string)$_ENV['AGRO_AI_MODEL'];
    if (!empty(getenv('AGRO_AI_MODEL')))       return (string)getenv('AGRO_AI_MODEL');
    return (string)AGRO_AI_MODEL;
}

/**
 * Returns true when an API key is configured and curl is available.
 */
function agro_ai_available(): bool {
    return function_exists('curl_init') && agro_ai_key() !== '';
}

/**
 * Send a chat completion request to the configured LLM.
 *
 * @param  string $systemPrompt  The system instruction
 * @param  string $userPrompt    The user message
 * @return string|null           The assistant reply, or null on failure
 */
function agro_ai_chat(string $systemPrompt, string $userPrompt): ?string {
    if (!agro_ai_available()) return null;

    $payload = json_encode([
        'model'       => agro_ai_model(),
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => 600,
        'temperature' => 0.3,   // low temperature → consistent, factual tone
    ]);

    $ch = curl_init(agro_ai_endpoint() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => AGRO_AI_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . agro_ai_key(),
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300 || !$raw) {
        error_log("[agro_ai] HTTP {$code} err={$err}");
        return null;
    }

    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}
