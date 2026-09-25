<?php
/**
 * ENGINE HOP (v3.9) – one link of the self-perpetuating request chain. Called only by the application itself
 * (Engine::fireHop) with the generated token; never by a person, never by cron.
 *
 *   tick (dispatch due targets per plan interval → process queue inline when no worker is alive)
 *   → keep the background worker alive when the server allows spawning
 *   → wait until the minute is over → fire the next hop → exit
 *
 * See includes/Engine.php for the full architecture.
 */
define('ENGINE_HOP', true);
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/init.php';
Engine::hop();
