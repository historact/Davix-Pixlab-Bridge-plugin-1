const express = require('express');
const router = express.Router();

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

router.use(requireToken);

router.get('/internal/admin/keys', async (req, res) => {
  const page = Math.max(parseInt(req.query.page, 10) || 1, 1);
  const perPage = Math.min(Math.max(parseInt(req.query.per_page, 10) || 20, 1), 100);
  const search = req.query.search ? String(req.query.search) : '';
  const offset = (page - 1) * perPage;

  const pool = res.app.get('db');
  const { rows, count } = await pool.keys.list({ offset, limit: perPage, search });
  res.json({ status: 'ok', page, per_page: perPage, total: count, items: rows });
});

router.post('/internal/admin/key/provision', async (req, res) => {
  const { customer_email, plan_slug, subscription_id, order_id } = req.body;
  const pool = res.app.get('db');
  const result = await pool.keys.provision({ customer_email, plan_slug, subscription_id, order_id });
  res.json({ status: 'ok', action: result.action, key: result.key, key_prefix: result.key_prefix, key_last4: result.key_last4 });
});

router.post('/internal/admin/key/disable', async (req, res) => {
  const { subscription_id, customer_email } = req.body;
  const pool = res.app.get('db');
  const affected = await pool.keys.disable({ subscription_id, customer_email });
  res.json({ status: 'ok', action: 'disabled', affected });
});

router.post('/internal/admin/key/rotate', async (req, res) => {
  const { subscription_id, customer_email } = req.body;
  const pool = res.app.get('db');
  const result = await pool.keys.rotate({ subscription_id, customer_email });
  res.json({ status: 'ok', action: 'rotated', key: result.key, key_prefix: result.key_prefix, key_last4: result.key_last4 });
});

router.get('/internal/admin/plans', async (req, res) => {
  const pool = res.app.get('db');
  const plans = await pool.plans.list();
  res.json({ status: 'ok', items: plans });
});

router.post('/internal/wp-sync/plan', async (req, res) => {
  const pool = res.app.get('db');
  const payload = req.body || {};
  const result = await pool.plans.upsertFromWp ? pool.plans.upsertFromWp(payload) : pool.plans.upsert(payload);
  res.json({ status: 'ok', action: result && result.action ? result.action : 'upserted', plan_slug: payload.plan_slug });
});

module.exports = router;
