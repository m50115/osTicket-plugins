<?php
namespace OstWorkflow;

/**
 * Build identity. `SHA` is stamped at build time (see README, "Building");
 * a folder install in a sandbox reports 'dev'. GET /config exposes it so the
 * deployed artifact can be identified (PP-15) — the manifest version is not
 * reliable (osTicket rewrites `version` on every load).
 */
final class Build {
    const VERSION = '0.1';
    const SHA = 'dev';
    const API_VERSIONS = ['v1'];
}
