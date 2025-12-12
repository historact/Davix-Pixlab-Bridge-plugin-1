const express = require('express');
const router = express.Router();

let pool = null;
try {
  // Prefer the shared mysql2 pool when available
  pool = require('../db');
} catch (err) {
  pool = null;
}

let sendError = null;
try {
  // Standardized error helper if present in the project
  sendError = require('../utils/errorResponse');
} catch (err) {
  sendError = null;
}

const { activateOrProvisionKey, disableCustomerKey, generateKeyParts } = require('./key-service');

function getToken(req) {
  return req.headers['x-davix-bridge-token'];
}

function requireToken(req, res, next) {
  const envToken = process.env.SUBSCRIPTION_BRIDGE_TOKEN || process.env.X_DAVIX_BRIDGE_TOKEN;
  if (!envToken || getToken(req) !== envToken) {
    return res.status(401).json({ status: 'error', message: 'unauthorized' });
  }
  return next();
}

function getPool(req) {
  return (req.app && req.app.get && req.app.get('db')) || pool;
}

function respondError(res, statusCode, code, message, extra = {}) {
  if (typeof sendError === 'function') {
    return sendError(res, statusCode, code, message, extra);
  }
  return res.status(statusCode).json({ status: 'error', code, message, ...extra });
}

async function columnExists(db, table, column) {
  if (!db || typeof db.query !== 'function') return false;
  columnExists.cache = columnExists.cache || {};
  const cacheKey = `${table}.${column}`;
  if (cacheKey in columnExists.cache) {
    return columnExists.cache[cacheKey];
  }
  const [rows] = await db.query(
    'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [table, column],
  );
  columnExists.cache[cacheKey] = rows && rows.length > 0;
  return columnExists.cache[cacheKey];
}

async function fetchPlanBySlug(db, planSlug) {
  if (!db || typeof db.query !== 'function') return null;
  const [rows] = await db.query('SELECT * FROM plans WHERE plan_slug = ? LIMIT 1', [planSlug]);
  return rows && rows[0] ? rows[0] : null;
}

router.use(requireToken);

// List API keys for the admin table with pagination and optional search
router.get('/internal/admin/keys', async (req, res) => {
  const page = Math.max(parseInt(req.query.page, 10) || 1, 1);
  const perPage = Math.min(Math.max(parseInt(req.query.per_page, 10) || 20, 1), 100);
  const search = req.query.search ? String(req.query.search) : '';
  const offset = (page - 1) * perPage;

  const db = getPool(req);
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  try {
    const conditions = [];
    const params = [];
    if (search) {
      conditions.push('(k.customer_email LIKE ? OR k.subscription_id LIKE ? OR k.key_prefix LIKE ?)');
      const like = `%${search}%`;
      params.push(like, like, like);
    }

    const whereClause = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';

    const [rows] = await db.query(
      `SELECT k.subscription_id, k.customer_email, COALESCE(p.plan_slug, k.plan_slug) AS plan_slug, k.status, k.key_prefix, k.key_last4, k.updated_at
         FROM api_keys k
         LEFT JOIN plans p ON k.plan_id = p.id
         ${whereClause}
         ORDER BY k.updated_at DESC
         LIMIT ? OFFSET ?`,
      [...params, perPage, offset],
    );

    const [countRows] = await db.query(
      `SELECT COUNT(*) as total
         FROM api_keys k
         LEFT JOIN plans p ON k.plan_id = p.id
         ${whereClause}`,
      params,
    );

    const total = countRows && countRows[0] ? countRows[0].total : 0;
    return res.json({ status: 'ok', page, per_page: perPage, total, items: rows || [] });
  } catch (err) {
    return respondError(res, 500, 'db_error', 'Failed to fetch keys', { error: err.message });
  }
});

// Provision or activate a key manually from the admin screen
router.post('/internal/admin/key/provision', async (req, res) => {
  const { customer_email, plan_slug: rawPlanSlug, subscription_id, order_id } = req.body || {};
  const db = getPool(req);
  const planSlug = rawPlanSlug ? String(rawPlanSlug).trim() : '';
  if (!planSlug) {
    return respondError(res, 400, 'invalid_plan', 'plan_slug is required');
  }
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  try {
    const plan = await fetchPlanBySlug(db, planSlug);
    if (!plan) {
      return respondError(res, 404, 'plan_not_found', 'Plan not found');
    }

    const result = await activateOrProvisionKey(db, {
      customer_email,
      plan_id: plan.id,
      plan_slug: planSlug,
      subscription_id,
      order_id,
    });

    return res.json({
      status: 'ok',
      action: result.action || 'created',
      key: result.key || result.plaintextKey || null,
      key_prefix: result.key_prefix,
      key_last4: result.key_last4,
      plan_id: plan.id,
      subscription_id: subscription_id || null,
    });
  } catch (err) {
    return respondError(res, 500, 'provision_failed', 'Failed to provision key', { error: err.message });
  }
});

// Disable a key either by subscription_id (preferred) or by customer_email
router.post('/internal/admin/key/disable', async (req, res) => {
  const { subscription_id, customer_email } = req.body || {};
  const db = getPool(req);
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  try {
    const affected = await disableCustomerKey(db, { subscription_id, customer_email });
    return res.json({ status: 'ok', action: 'disabled', affected });
  } catch (err) {
    return respondError(res, 500, 'disable_failed', 'Failed to disable key', { error: err.message });
  }
});

// Rotate (regenerate) a key, returning the plaintext once
router.post('/internal/admin/key/rotate', async (req, res) => {
  const { subscription_id, customer_email } = req.body || {};
  const db = getPool(req);
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  if (!subscription_id && !customer_email) {
    return respondError(res, 400, 'missing_identifier', 'subscription_id or customer_email is required');
  }

  try {
    const identifierClause = subscription_id ? 'subscription_id = ?' : 'customer_email = ?';
    const identifierValue = subscription_id || customer_email;
    const [existingRows] = await db.query(`SELECT * FROM api_keys WHERE ${identifierClause} ORDER BY updated_at DESC LIMIT 1`, [identifierValue]);
    const existing = existingRows && existingRows[0];
    if (!existing) {
      return respondError(res, 404, 'not_found', 'Key not found');
    }

    const parts = generateKeyParts();
    const setFields = ['key_hash = ?', 'key_prefix = ?', 'key_last4 = ?', 'license_key = ?', 'updated_at = NOW()'];
    const params = [parts.key_hash, parts.key_prefix, parts.key_last4, parts.plaintextKey];

    if (await columnExists(db, 'api_keys', 'rotated_at')) {
      setFields.push('rotated_at = NOW()');
    }

    const query = `UPDATE api_keys SET ${setFields.join(', ')} WHERE ${identifierClause}`;
    await db.query(query, [...params, identifierValue]);

    return res.json({
      status: 'ok',
      action: 'rotated',
      key: parts.plaintextKey,
      key_prefix: parts.key_prefix,
      key_last4: parts.key_last4,
      subscription_id: existing.subscription_id || subscription_id || null,
    });
  } catch (err) {
    return respondError(res, 500, 'rotate_failed', 'Failed to rotate key', { error: err.message });
  }
});

// List plans for the admin dropdown
router.get('/internal/admin/plans', async (req, res) => {
  const db = getPool(req);
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  try {
    const [rows] = await db.query(
      'SELECT id, plan_slug, name, monthly_quota_files, billing_period, is_free FROM plans ORDER BY created_at DESC',
    );
    return res.json({ status: 'ok', items: rows || [] });
  } catch (err) {
    return respondError(res, 500, 'db_error', 'Failed to fetch plans', { error: err.message });
  }
});

// Sync or upsert a plan coming from WordPress
router.post('/internal/wp-sync/plan', async (req, res) => {
  const db = getPool(req);
  if (!db || typeof db.query !== 'function') {
    return respondError(res, 500, 'db_unavailable', 'Database connection not available');
  }

  const payload = req.body || {};
  const planSlug = payload.plan_slug ? String(payload.plan_slug).trim() : '';
  if (!planSlug) {
    return respondError(res, 400, 'invalid_plan', 'plan_slug is required');
  }

  try {
    const includeMaxDimension = await columnExists(db, 'plans', 'max_dimension_px');

    const columns = [
      'plan_slug',
      'name',
      'billing_period',
      'monthly_quota_files',
      'max_files_per_request',
      'max_total_upload_mb',
      'timeout_seconds',
      'allow_h2i',
      'allow_image',
      'allow_pdf',
      'allow_tools',
      'is_free',
      'description',
    ];
    if (includeMaxDimension) {
      columns.splice(6, 0, 'max_dimension_px');
    }

    const values = [
      planSlug,
      payload.name || null,
      payload.billing_period || null,
      payload.monthly_quota_files != null ? payload.monthly_quota_files : null,
      payload.max_files_per_request != null ? payload.max_files_per_request : null,
      payload.max_total_upload_mb != null ? payload.max_total_upload_mb : null,
      payload.timeout_seconds != null ? payload.timeout_seconds : null,
      payload.allow_h2i != null ? payload.allow_h2i : null,
      payload.allow_image != null ? payload.allow_image : null,
      payload.allow_pdf != null ? payload.allow_pdf : null,
      payload.allow_tools != null ? payload.allow_tools : null,
      payload.is_free != null ? payload.is_free : null,
      payload.description || null,
    ];

    if (includeMaxDimension) {
      values.splice(6, 0, payload.max_dimension_px != null ? payload.max_dimension_px : null);
    }

    const updateAssignments = columns
      .filter((col) => col !== 'plan_slug')
      .map((col) => `${col} = VALUES(${col})`)
      .join(', ');

    const placeholders = columns.map(() => '?').join(', ');
    const query = `INSERT INTO plans (${columns.join(', ')}) VALUES (${placeholders}) ON DUPLICATE KEY UPDATE ${updateAssignments}`;
    await db.query(query, values);

    return res.json({ status: 'ok', action: 'upserted', plan_slug: planSlug });
  } catch (err) {
    return respondError(res, 500, 'plan_sync_failed', 'Failed to sync plan', { error: err.message });
  }
});

module.exports = router;
