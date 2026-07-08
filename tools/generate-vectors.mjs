#!/usr/bin/env node
/**
 * WP Portal Bridge — HMAC golden vector generator.
 *
 * This file is the canonical, executable definition of the PORTAL-BRIDGE-V1
 * signing algorithm. It emits tests/vectors/hmac-vectors.json, which is
 * asserted verbatim by BOTH implementations:
 *
 *   - PHP   → tests/test-auth-vectors.php   (wp-portal-bridge plugin)
 *   - TS/JS → portal-source-wordpress test  (Portal framework adapter)
 *
 * If either side drifts from these vectors, its test suite fails.
 *
 * ── Canonical request string (PORTAL-BRIDGE-V1) ─────────────────────────────
 *
 *   PORTAL-BRIDGE-V1 \n
 *   METHOD           \n   uppercase HTTP method
 *   PATH_WITH_QUERY  \n   URL pathname + "?" + RAW query string, EXACTLY as
 *                         sent on the wire. The verifier compares against the
 *                         raw request target (PHP: $_SERVER['REQUEST_URI']).
 *                         Neither side ever re-encodes or re-orders the query.
 *   TIMESTAMP        \n   unix seconds, base-10 string
 *   NONCE            \n   caller-generated unique string
 *   BODY_SHA256           lowercase hex sha256 of the raw request body;
 *                         for bodyless requests: sha256 of the empty string
 *
 *   signature = lowercase hex hmac-sha256(TMK_SECRET, canonical)
 *
 * ── Canonical response string (PORTAL-BRIDGE-RESPONSE-V1) ───────────────────
 *
 *   PORTAL-BRIDGE-RESPONSE-V1 \n
 *   REQUEST_NONCE             \n
 *   STATUS_CODE               \n
 *   RESPONSE_TIMESTAMP        \n
 *   RESPONSE_BODY_SHA256
 *
 * Usage: node tools/generate-vectors.mjs
 */

import { createHmac, createHash } from "node:crypto";
import { writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));

export const EMPTY_BODY_SHA256 =
  "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

export function sha256Hex(input) {
  return createHash("sha256").update(input, "utf8").digest("hex");
}

export function canonicalRequest(method, pathWithQuery, timestamp, nonce, bodySha256) {
  return [
    "PORTAL-BRIDGE-V1",
    method.toUpperCase(),
    pathWithQuery,
    String(timestamp),
    nonce,
    bodySha256,
  ].join("\n");
}

export function canonicalResponse(requestNonce, statusCode, responseTimestamp, bodySha256) {
  return [
    "PORTAL-BRIDGE-RESPONSE-V1",
    requestNonce,
    String(statusCode),
    String(responseTimestamp),
    bodySha256,
  ].join("\n");
}

export function sign(tmkSecret, canonical) {
  return createHmac("sha256", tmkSecret).update(canonical, "utf8").digest("hex");
}

/**
 * Deterministic key id (fingerprint) for a TMK secret: wpb_ + first 12 hex
 * of sha256(secret). Lets Portal derive the X-Portal-Key-Id from PORTAL_TMK
 * alone — the env block stays exactly two variables.
 */
export function deriveKeyId(tmkSecret) {
  return "wpb_" + createHash("sha256").update(tmkSecret, "utf8").digest("hex").slice(0, 12);
}

// ── Fixed test inputs (never change these — they are the contract) ───────────

const TMK = "portal_tmk_test_5f2a9c1d3e8b4a7f6c0d9e2b1a8f7c6d5e4b3a2f1c0d9e8b";
const KEY_ID = "wpb_testkey1";
const TS = 1751980000;

const cases = [
  {
    name: "get_no_query",
    note: "Plain GET, no query string, empty body hashes to sha256('')",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/health",
    timestamp: TS,
    nonce: "nonce-0001-health",
    body: null,
  },
  {
    name: "get_encoded_path_param",
    note: "Percent-encoded query value is signed exactly as sent — never decoded",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/route?path=%2Fabout%2F",
    timestamp: TS,
    nonce: "nonce-0002-encoded",
    body: null,
  },
  {
    name: "get_unencoded_path_param",
    note: "Same logical value, raw slashes in query — DIFFERENT signature than the encoded form, by design",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/route?path=/about/",
    timestamp: TS,
    nonce: "nonce-0003-raw",
    body: null,
  },
  {
    name: "get_query_order_ab",
    note: "Query parameter order is significant: a=1&b=2",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/routes?page=1&perPage=100",
    timestamp: TS,
    nonce: "nonce-0004-order-ab",
    body: null,
  },
  {
    name: "get_query_order_ba",
    note: "Reversed order perPage=100&page=1 is a different canonical string and signature",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/routes?perPage=100&page=1",
    timestamp: TS,
    nonce: "nonce-0005-order-ba",
    body: null,
  },
  {
    name: "get_trailing_slash",
    note: "Trailing slash on the endpoint path changes the signature",
    method: "GET",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/menus/",
    timestamp: TS,
    nonce: "nonce-0006-trailing",
    body: null,
  },
  {
    name: "get_subdirectory_install",
    note: "Subdirectory WordPress installs sign the full path from the origin",
    method: "GET",
    pathWithQuery: "/blog/wp-json/wp-portal-bridge/v1/site",
    timestamp: TS,
    nonce: "nonce-0007-subdir",
    body: null,
  },
  {
    name: "post_json_body",
    note: "POST with a JSON body: BODY_SHA256 is the sha256 of the exact raw bytes",
    method: "POST",
    pathWithQuery: "/wp-json/wp-portal-bridge/v1/snapshot",
    timestamp: TS,
    nonce: "nonce-0008-post",
    body: '{"mode":"full"}',
  },
];

const responseCases = [
  {
    name: "response_health_ok",
    note: "Response signing binds the reply to the request nonce",
    requestNonce: "nonce-0001-health",
    statusCode: 200,
    responseTimestamp: TS + 1,
    responseBody: '{"ok":true}',
  },
  {
    name: "response_unauthorized",
    requestNonce: "nonce-0009-bad",
    statusCode: 401,
    responseTimestamp: TS + 2,
    responseBody: '{"code":"wpb_unauthorized"}',
  },
];

const vectors = {
  version: "PORTAL-BRIDGE-V1",
  generatedBy: "wp-portal-bridge/tools/generate-vectors.mjs",
  tmk: TMK,
  keyId: KEY_ID,
  derivedKeyId: deriveKeyId(TMK),
  emptyBodySha256: EMPTY_BODY_SHA256,
  requests: cases.map((c) => {
    const bodySha = c.body === null ? EMPTY_BODY_SHA256 : sha256Hex(c.body);
    const canonical = canonicalRequest(c.method, c.pathWithQuery, c.timestamp, c.nonce, bodySha);
    return {
      name: c.name,
      note: c.note,
      method: c.method,
      pathWithQuery: c.pathWithQuery,
      timestamp: c.timestamp,
      nonce: c.nonce,
      body: c.body,
      bodySha256: bodySha,
      canonical,
      signature: sign(TMK, canonical),
    };
  }),
  responses: responseCases.map((c) => {
    const bodySha = sha256Hex(c.responseBody);
    const canonical = canonicalResponse(c.requestNonce, c.statusCode, c.responseTimestamp, bodySha);
    return {
      name: c.name,
      note: c.note ?? "",
      requestNonce: c.requestNonce,
      statusCode: c.statusCode,
      responseTimestamp: c.responseTimestamp,
      responseBody: c.responseBody,
      responseBodySha256: bodySha,
      canonical,
      signature: sign(TMK, canonical),
    };
  }),
};

const isMain = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1];
if (isMain) {
  const out = join(__dirname, "..", "tests", "vectors", "hmac-vectors.json");
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, JSON.stringify(vectors, null, 2) + "\n");
  console.log(`wrote ${out}`);
  console.log(`${vectors.requests.length} request vectors, ${vectors.responses.length} response vectors`);
}
