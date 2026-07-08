#!/usr/bin/env node
/**
 * WP Portal Bridge — live integration security tests.
 *
 * Fires the full tunnel security matrix at a REAL WordPress install running
 * the plugin, using the same PORTAL-BRIDGE-V1 signer the Portal adapter uses.
 *
 *   1. valid signed request        → 200, contract payload
 *   2. unsigned request            → 401 generic
 *   3. invalid signature           → 401 generic
 *   4. stale timestamp (> skew)    → 401 generic
 *   5. replayed nonce              → 401 generic
 *   6. tampered body hash          → 401 generic
 *   7. unknown key id              → 401 generic
 *   8. draft content by path       → 404 (never leaks)
 *   9. response signature          → verifies against TMK
 *
 * Usage:
 *   WPB_BASE_URL=http://127.0.0.1:8080 WPB_TMK=portal_tmk_live_xxx WPB_KEY_ID=wpb_xxx \
 *     node tests/integration/run-security-tests.mjs [--draft-path /draft-page/]
 */

import { createHmac, createHash, randomBytes } from "node:crypto";

const BASE = process.env.WPB_BASE_URL;
const TMK = process.env.WPB_TMK;
const KEY_ID = process.env.WPB_KEY_ID;
const DRAFT_PATH = process.argv.includes("--draft-path")
  ? process.argv[process.argv.indexOf("--draft-path") + 1]
  : null;

if (!BASE || !TMK || !KEY_ID) {
  console.error("Set WPB_BASE_URL, WPB_TMK, WPB_KEY_ID");
  process.exit(1);
}

const EMPTY_SHA = createHash("sha256").update("").digest("hex");

function sign(secret, canonical) {
  return createHmac("sha256", secret).update(canonical, "utf8").digest("hex");
}

function headersFor({ method, pathWithQuery, body = null, timestamp = null, nonce = null, keyId = KEY_ID, corruptSignature = false, corruptBodySha = false }) {
  const ts = timestamp ?? Math.floor(Date.now() / 1000);
  const n = nonce ?? `itest-${randomBytes(8).toString("hex")}`;
  const bodySha = corruptBodySha
    ? createHash("sha256").update("tampered").digest("hex")
    : body === null
      ? EMPTY_SHA
      : createHash("sha256").update(body, "utf8").digest("hex");
  const canonical = ["PORTAL-BRIDGE-V1", method.toUpperCase(), pathWithQuery, String(ts), n, bodySha].join("\n");
  let signature = sign(TMK, canonical);
  if (corruptSignature) signature = signature.replace(/^./, signature[0] === "0" ? "1" : "0");
  return {
    nonce: n,
    headers: {
      "X-Portal-Timestamp": String(ts),
      "X-Portal-Nonce": n,
      "X-Portal-Key-Id": keyId,
      "X-Portal-Body-SHA256": bodySha,
      "X-Portal-Signature": signature,
    },
  };
}

async function call(pathWithQuery, opts = {}) {
  const { headers, nonce } = headersFor({ method: "GET", pathWithQuery, ...opts });
  const res = await fetch(BASE + pathWithQuery, { headers: opts.unsigned ? {} : headers });
  const text = await res.text();
  return { status: res.status, text, nonce, headers: res.headers };
}

let passed = 0;
let failed = 0;
function check(name, condition, detail = "") {
  if (condition) {
    passed++;
    console.log(`  ✓ ${name}`);
  } else {
    failed++;
    console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ""}`);
  }
}

const HEALTH = "/wp-json/wp-portal-bridge/v1/health";

console.log(`\nWP Portal Bridge security matrix → ${BASE}\n`);

// 1. Valid signed request.
{
  const r = await call(HEALTH);
  check("valid signed request → 200", r.status === 200, `got ${r.status}: ${r.text.slice(0, 120)}`);
  let payload = null;
  try { payload = JSON.parse(r.text); } catch {}
  check("health payload ok:true", payload && payload.ok === true);
  check("health exposes key ids, never secrets", payload && !JSON.stringify(payload).includes(TMK));

  // 9. Response signature.
  const respSig = r.headers.get("x-portal-response-signature");
  const respTs = r.headers.get("x-portal-response-timestamp");
  if (respSig && respTs) {
    const canonical = ["PORTAL-BRIDGE-RESPONSE-V1", r.nonce, "200", respTs, createHash("sha256").update(r.text, "utf8").digest("hex")].join("\n");
    check("response signature verifies", sign(TMK, canonical) === respSig, "mismatch");
  } else {
    check("response signature present", false, "headers missing");
  }
}

// 2. Unsigned.
{
  const r = await call(HEALTH, { unsigned: true });
  check("unsigned request → 401", r.status === 401, `got ${r.status}`);
  check("unsigned error is generic", !/timestamp|nonce|signature|key/i.test(JSON.parse(r.text).message ?? ""), r.text.slice(0, 120));
}

// 3. Invalid signature.
{
  const r = await call(HEALTH, { corruptSignature: true });
  check("corrupted signature → 401", r.status === 401, `got ${r.status}`);
}

// 4. Stale timestamp.
{
  const r = await call(HEALTH, { timestamp: Math.floor(Date.now() / 1000) - 3600 });
  check("stale timestamp (1h old) → 401", r.status === 401, `got ${r.status}`);
}

// 5. Replayed nonce.
{
  const nonce = `replay-${randomBytes(6).toString("hex")}`;
  const first = await call(HEALTH, { nonce });
  const second = await call(HEALTH, { nonce });
  check("first use of nonce → 200", first.status === 200, `got ${first.status}`);
  check("replayed nonce → 401", second.status === 401, `got ${second.status}`);
}

// 6. Tampered body hash.
{
  const r = await call(HEALTH, { corruptBodySha: true });
  check("tampered body hash → 401", r.status === 401, `got ${r.status}`);
}

// 7. Unknown key id.
{
  const r = await call(HEALTH, { keyId: "wpb_doesnotexist" });
  check("unknown key id → 401", r.status === 401, `got ${r.status}`);
}

// 8. Draft exclusion.
if (DRAFT_PATH) {
  const q = `/wp-json/wp-portal-bridge/v1/route?path=${encodeURIComponent(DRAFT_PATH)}`;
  const r = await call(q);
  check(`draft path ${DRAFT_PATH} → 404 (never leaks)`, r.status === 404, `got ${r.status}: ${r.text.slice(0, 120)}`);
} else {
  console.log("  – draft exclusion skipped (pass --draft-path /some-draft/)");
}

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);
