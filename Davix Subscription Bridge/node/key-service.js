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
    const { rows } = await runner.query('SELECT * FROM api_keys WHERE subscription_id = $1 LIMIT 1', [subscriptionId]);
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
    const { rows } = await runner.query('SELECT * FROM api_keys WHERE customer_email = $1 ORDER BY updated_at DESC LIMIT 1', [customerEmail]);
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
      `INSERT INTO api_keys (subscription_id, customer_email, plan_slug, license_key, key_hash, key_prefix, key_last4, order_id, status)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8, COALESCE($9,'active'))
       ON CONFLICT (subscription_id) DO NOTHING`,
      [
        payload.subscription_id || null,
        payload.customer_email,
        payload.plan_slug,
        payload.license_key || null,
        payload.key_hash,
        payload.key_prefix,
        payload.key_last4,
        payload.order_id || null,
        payload.status || 'active',
      ],
    );
  }
  return null;
}

async function updateExistingKey(service, subscriptionId, payload) {
  if (typeof service.updateBySubscriptionId === 'function') {
    return service.updateBySubscriptionId(subscriptionId, payload);
  }
  if (typeof service.update === 'function') {
    return service.update(subscriptionId, payload);
  }
  const runner = service.db || service.pool || service;
  if (runner && typeof runner.query === 'function') {
    await runner.query(
      `UPDATE api_keys
       SET customer_email = COALESCE($2, customer_email),
           plan_slug = COALESCE($3, plan_slug),
           license_key = $4,
           key_hash = $5,
           key_prefix = $6,
           key_last4 = $7,
           order_id = COALESCE($8, order_id),
           status = COALESCE($9, status)
       WHERE subscription_id = $1`,
      [
        subscriptionId,
        payload.customer_email || null,
        payload.plan_slug || null,
        payload.license_key || null,
        payload.key_hash || null,
        payload.key_prefix || null,
        payload.key_last4 || null,
        payload.order_id || null,
        payload.status || null,
      ],
    );
  }
  return null;
}

async function activateOrProvisionKey(service, params) {
  if (!service) return {};
  const { subscription_id: subscriptionId, customer_email: customerEmail, plan_slug: planSlug, order_id: orderId } = params;
  const lookupByEmail = !subscriptionId;
  const existing = lookupByEmail ? await fetchByEmail(service, customerEmail) : await fetchBySubscription(service, subscriptionId);

  if (!existing) {
    const parts = generateKeyParts(params.license_key);
    const payload = {
      subscription_id: subscriptionId || null,
      customer_email: customerEmail,
      plan_slug: planSlug,
      order_id: orderId || null,
      license_key: parts.plaintextKey,
      key_hash: parts.key_hash,
      key_prefix: parts.key_prefix,
      key_last4: parts.key_last4,
      status: params.status || 'active',
    };
    await saveNewKey(service, payload);
    return { action: 'created', ...parts };
  }

  let repairParts = null;
  if (!existing.key_hash) {
    repairParts = generateKeyParts();
  }

  const updatePayload = {
    customer_email: customerEmail || existing.customer_email,
    plan_slug: planSlug || existing.plan_slug,
    order_id: orderId || existing.order_id,
    license_key: repairParts ? repairParts.plaintextKey : existing.license_key,
    key_hash: repairParts ? repairParts.key_hash : existing.key_hash,
    key_prefix: repairParts ? repairParts.key_prefix : existing.key_prefix,
    key_last4: repairParts ? repairParts.key_last4 : existing.key_last4,
    status: params.status || existing.status || 'active',
  };

  await updateExistingKey(service, subscriptionId || existing.subscription_id, updatePayload);
  return {
    action: repairParts ? 'repaired' : 'existing',
    key: repairParts ? repairParts.plaintextKey : undefined,
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
      const { rowCount } = await runner.query('UPDATE api_keys SET status = $2 WHERE subscription_id = $1', [subscriptionId, 'disabled']);
      return rowCount || 0;
    }
    const { rowCount } = await runner.query('UPDATE api_keys SET status = $2 WHERE customer_email = $1', [customerEmail, 'disabled']);
    return rowCount || 0;
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
