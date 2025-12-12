const crypto = require('crypto');

function generateKeyParts(rawKey) {
  const plaintextKey = rawKey && rawKey.trim() !== '' ? rawKey : crypto.randomBytes(24).toString('base64url');
  const keyHash = crypto.createHash('sha256').update(plaintextKey).digest('hex');
  return {
    plaintextKey,
    key_hash: keyHash,
    key_prefix: plaintextKey.substring(0, 10),
    key_last4: plaintextKey.slice(-4),
  };
}

async function fetchBySubscription(service, subscriptionId) {
  if (!subscriptionId) return null;
  if (typeof service.findBySubscriptionId === 'function') {
    return service.findBySubscriptionId(subscriptionId);
  }
  const runner = service.db || service.pool || service;
  if (runner && typeof runner.query === 'function') {
    const [rows] = await runner.query('SELECT * FROM api_keys WHERE subscription_id = ? LIMIT 1', [subscriptionId]);
    return rows && rows[0] ? rows[0] : null;
  }
  return null;
}

async function fetchByEmail(service, customerEmail) {
  if (!customerEmail) return null;
  if (typeof service.findByEmail === 'function') {
    return service.findByEmail(customerEmail);
  }
  const runner = service.db || service.pool || service;
  if (runner && typeof runner.query === 'function') {
    const [rows] = await runner.query(
      'SELECT * FROM api_keys WHERE customer_email = ? ORDER BY updated_at DESC LIMIT 1',
      [customerEmail],
    );
    return rows && rows[0] ? rows[0] : null;
  }
  return null;
}

async function saveNewKey(service, payload) {
  if (typeof service.insert === 'function') {
    return service.insert(payload);
  }
  if (typeof service.provision === 'function') {
    return service.provision(payload);
  }
  const runner = service.db || service.pool || service;
  if (runner && typeof runner.query === 'function') {
    await runner.query(
      `INSERT INTO api_keys (subscription_id, customer_email, plan_id, order_id, status, license_key, key_hash, key_prefix, key_last4)
       VALUES (?,?,?,?,COALESCE(?, 'active'), ?,?,?,?)`,
      [
        payload.subscription_id || null,
        payload.customer_email || null,
        payload.plan_id || null,
        payload.order_id || null,
        payload.status || 'active',
        payload.license_key || null,
        payload.key_hash,
        payload.key_prefix,
        payload.key_last4,
      ],
    );
  }
  return null;
}

async function updateExistingKey(service, params) {
  const runner = service.db || service.pool || service;
  const subscriptionId = params.subscription_id;
  const customerEmail = params.customer_email;

  if (typeof service.updateBySubscriptionId === 'function' && subscriptionId) {
    return service.updateBySubscriptionId(subscriptionId, params);
  }
  if (typeof service.update === 'function' && subscriptionId) {
    return service.update(subscriptionId, params);
  }
  if (runner && typeof runner.query === 'function') {
    const identifierClause = subscriptionId ? 'subscription_id = ?' : 'customer_email = ?';
    const identifierValue = subscriptionId || customerEmail;
    await runner.query(
      `UPDATE api_keys
         SET customer_email = COALESCE(?, customer_email),
             plan_id = COALESCE(?, plan_id),
             order_id = COALESCE(?, order_id),
             status = COALESCE(?, status),
             license_key = ?,
             key_hash = ?,
             key_prefix = ?,
             key_last4 = ?,
             updated_at = NOW()
       WHERE ${identifierClause}`,
      [
        params.customer_email || null,
        params.plan_id || null,
        params.order_id || null,
        params.status || null,
        params.license_key || null,
        params.key_hash || null,
        params.key_prefix || null,
        params.key_last4 || null,
        identifierValue,
      ],
    );
  }
  return null;
}

async function activateOrProvisionKey(service, params) {
  if (!service) return {};
  const { subscription_id: subscriptionId, customer_email: customerEmail, plan_id: planId, plan_slug: planSlug, order_id: orderId } = params;

  const existing = subscriptionId
    ? await fetchBySubscription(service, subscriptionId)
    : await fetchByEmail(service, customerEmail);

  if (!existing) {
    const parts = generateKeyParts(params.license_key);
    const payload = {
      subscription_id: subscriptionId || null,
      customer_email: customerEmail || null,
      plan_id: planId || null,
      plan_slug: planSlug || null,
      order_id: orderId || null,
      license_key: parts.plaintextKey || null,
      key_hash: parts.key_hash,
      key_prefix: parts.key_prefix,
      key_last4: parts.key_last4,
      status: params.status || 'active',
    };
    await saveNewKey(service, payload);
    return { action: 'created', ...parts, key_prefix: parts.key_prefix, key_last4: parts.key_last4 };
  }

  let repairParts = null;
  if (!existing.key_hash || existing.key_hash === '') {
    repairParts = generateKeyParts();
  }

  const updatePayload = {
    subscription_id: subscriptionId || existing.subscription_id || null,
    customer_email: customerEmail || existing.customer_email || null,
    plan_id: planId || existing.plan_id || null,
    plan_slug: planSlug || existing.plan_slug || null,
    order_id: orderId || existing.order_id || null,
    license_key: repairParts ? repairParts.plaintextKey : existing.license_key || null,
    key_hash: repairParts ? repairParts.key_hash : existing.key_hash || null,
    key_prefix: repairParts ? repairParts.key_prefix : existing.key_prefix || null,
    key_last4: repairParts ? repairParts.key_last4 : existing.key_last4 || null,
    status: params.status || existing.status || 'active',
  };

  await updateExistingKey(service, updatePayload);
  return {
    action: repairParts ? 'repaired' : 'updated',
    key: repairParts ? repairParts.plaintextKey : undefined,
    plaintextKey: repairParts ? repairParts.plaintextKey : undefined,
    key_prefix: repairParts ? repairParts.key_prefix : existing.key_prefix,
    key_last4: repairParts ? repairParts.key_last4 : existing.key_last4,
  };
}

async function disableCustomerKey(service, params) {
  if (!service) return 0;
  const { subscription_id: subscriptionId, customer_email: customerEmail } = params;
  if (subscriptionId && typeof service.disableBySubscriptionId === 'function') {
    return service.disableBySubscriptionId(subscriptionId);
  }
  if (!subscriptionId && typeof service.disableByEmail === 'function') {
    return service.disableByEmail(customerEmail);
  }
  const runner = service.db || service.pool || service;
  if (runner && typeof runner.query === 'function') {
    if (subscriptionId) {
      const [result] = await runner.query('UPDATE api_keys SET status = ? WHERE subscription_id = ?', ['disabled', subscriptionId]);
      return result && typeof result.affectedRows === 'number' ? result.affectedRows : 0;
    }
    const [result] = await runner.query('UPDATE api_keys SET status = ? WHERE customer_email = ?', ['disabled', customerEmail]);
    return result && typeof result.affectedRows === 'number' ? result.affectedRows : 0;
  }
  if (typeof service.disable === 'function') {
    return service.disable(params);
  }
  return 0;
}

function enhanceKeysService(service) {
  if (!service || service.__davixEnhanced) {
    return;
  }
  if (typeof service.activateOrProvisionKey !== 'function') {
    service.activateOrProvisionKey = (params) => activateOrProvisionKey(service, params);
  }
  if (typeof service.disableCustomerKey !== 'function') {
    service.disableCustomerKey = (params) => disableCustomerKey(service, params);
  }
  service.__davixEnhanced = true;
}

module.exports = {
  activateOrProvisionKey,
  disableCustomerKey,
  enhanceKeysService,
  generateKeyParts,
};
