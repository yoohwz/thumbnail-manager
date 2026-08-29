import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
import {dirname, join, resolve} from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const adminSource = readFileSync(join(root, 'js/admin.js'), 'utf8');
const sizesSource = readFileSync(join(root, 'js/admin-sizes.js'), 'utf8');
const adminPhpSource = readFileSync(join(root, 'inc/admin/assets.php'), 'utf8');

function deferred(result) {
  const callbacks = {done: [], fail: [], always: []};
  let outcome = result || null;
  const promise = {
    done(callback) {
      callbacks.done.push(callback);
      if (outcome && outcome.kind === 'done') {
        callback(outcome.value);
      }
      return promise;
    },
    fail(callback) {
      callbacks.fail.push(callback);
      if (outcome && outcome.kind === 'fail') {
        callback(outcome.value);
      }
      return promise;
    },
    always(callback) {
      callbacks.always.push(callback);
      if (outcome) {
        callback(outcome.value);
      }
      return promise;
    },
    resolve(value) {
      if (outcome) {
        return;
      }
      outcome = {kind: 'done', value};
      callbacks.done.forEach((callback) => callback(value));
      callbacks.always.forEach((callback) => callback(value));
    },
    reject(value) {
      if (outcome) {
        return;
      }
      outcome = {kind: 'fail', value};
      callbacks.fail.forEach((callback) => callback(value));
      callbacks.always.forEach((callback) => callback(value));
    }
  };
  return promise;
}

function createHarness(options = {}) {
  const posts = [];
  const timers = [];
  const alerts = [];
  const handlers = [];
  const storageReads = [];
  const storage = new Map(Object.entries(options.storage || {}));
  const document = {};
  const cache = new Map();
  const objectCache = new WeakMap();

  function makeCollection(selector, elements = [{}]) {
    const collection = {
      selector,
      elements,
      length: elements.length,
      on(event, delegatedSelector, callback) {
        if (typeof delegatedSelector === 'function') {
          callback = delegatedSelector;
          delegatedSelector = null;
        }
        handlers.push({selector, event, delegatedSelector, callback});
        return collection;
      },
      each(callback) {
        elements.forEach((element, index) => callback.call(element, index, element));
        return collection;
      },
      map(callback) {
        const mapped = elements.map((element, index) => callback.call(element, index, element));
        return {get: () => mapped};
      },
      get() { return elements; },
      eq(index) { return makeCollection(selector + ':eq(' + index + ')', elements[index] ? [elements[index]] : []); },
      index() { return 0; },
      data(key) { return elements[0] && elements[0].data ? elements[0].data[key] : undefined; },
      val(value) {
        if (arguments.length) {
          elements.forEach((element) => { element.value = value; });
          return collection;
        }
        return elements[0] ? elements[0].value : undefined;
      },
      text(value) {
        if (arguments.length) {
          elements.forEach((element) => { element.text = value; });
          return collection;
        }
        return elements[0] ? elements[0].text || '' : '';
      },
      html(value) {
        if (arguments.length) {
          elements.forEach((element) => { element.html = value; });
          return collection;
        }
        return elements[0] ? elements[0].html || '' : '';
      },
      prop(key, value) {
        if (typeof key === 'object') {
          Object.entries(key).forEach(([name, item]) => collection.prop(name, item));
        } else if (arguments.length > 1) {
          elements.forEach((element) => { element[key] = value; });
        }
        return collection;
      },
      attr() { return collection; },
      removeAttr() { return collection; },
      css() { return collection; },
      addClass() { return collection; },
      removeClass() { return collection; },
      toggleClass() { return collection; },
      show() { return collection; },
      hide() { return collection; },
      empty() { return collection; },
      append() { return collection; },
      prepend() { return collection; },
      trigger() { return collection; },
      filter() { return collection; },
      is() { return false; },
      closest() { return elements[0] && elements[0].form ? elements[0].form : makeCollection('empty', []); },
      find(query) {
        if (elements[0] && elements[0].finds && elements[0].finds[query]) {
          return elements[0].finds[query];
        }
        return makeCollection(query, []);
      },
      appendTo(target) {
        if (target && target.elements && target.elements[0]) {
          target.elements[0].appended = collection;
        }
        return collection;
      }
    };
    return collection;
  }

  function $(selector, attributes) {
    if (typeof selector === 'string') {
      if (selector === '<input>') {
        return makeCollection(selector, [{...attributes}]);
      }
      if (!cache.has(selector)) {
        cache.set(selector, makeCollection(selector));
      }
      return cache.get(selector);
    }
    if (selector && typeof selector === 'object') {
      if (!objectCache.has(selector)) {
        objectCache.set(selector, makeCollection(selector === document ? 'document' : 'object', [selector]));
      }
      return objectCache.get(selector);
    }
    return makeCollection('empty', []);
  }

  $.post = (url, payload) => {
    const response = options.responses && options.responses[payload.action];
    const pending = deferred(response);
    const post = {url, payload: {...payload}};
    Object.defineProperty(post, 'deferred', {value: pending});
    posts.push(post);
    return pending;
  };

  const window = {
    YOTM: {
      ajaxurl: '/wp-admin/admin-ajax.php',
      nonce: 'nonce-123',
      siteId: 42,
      registeredSizesSignature: '',
      i18n: {},
      ...(options.config || {})
    },
    YOTM_RUN_REGENERATE_AFTER_SAVE: !!options.runAfterSave,
    ajaxurl: '/fallback.php',
    alert(message) { alerts.push(message); },
    localStorage: {
      getItem(key) {
        storageReads.push(key);
        return storage.get(key) || null;
      },
      setItem(key, value) { storage.set(key, String(value)); },
      removeItem(key) { storage.delete(key); }
    },
    setTimeout(callback, delay) {
      timers.push({callback, delay});
      return timers.length;
    }
  };

  const context = vm.createContext({
    console,
    document,
    jQuery: $,
    window
  });

  return {alerts, cache, context, document, handlers, posts, storage, storageReads, timers, window, $};
}

function loadAdmin(harness) {
  vm.runInContext(adminSource, harness.context, {filename: 'js/admin.js'});
}

function actionCalls(harness, action) {
  return harness.posts.filter((post) => post.payload.action === action);
}

test('core loads once, exposes a frozen bounded registry, and sends recent-job nonce', () => {
  const harness = createHarness();
  loadAdmin(harness);

  assert.equal(Object.isFrozen(harness.window.YOTMAdmin), true);
  assert.equal(typeof harness.window.YOTMAdmin.registerWorkflow, 'function');
  assert.equal(typeof harness.window.YOTMAdmin.request, 'function');
  assert.equal(harness.window.YOTMAdmin.isMissingJobStatus({status: 404}), true);
  assert.equal(harness.window.YOTMAdmin.isMissingJobStatus({status: 503}), false);
  assert.deepEqual(actionCalls(harness, 'yotm_jobs_recent')[0], {
    url: '/wp-admin/admin-ajax.php',
    payload: {action: 'yotm_jobs_recent', nonce: 'nonce-123'}
  });

  harness.window.YOTMAdmin.getJobStatus('status-token');
  harness.window.YOTMAdmin.cancelJob('cancel-token');
  assert.deepEqual(actionCalls(harness, 'yotm_job_status').at(-1).payload, {
    action: 'yotm_job_status', nonce: 'nonce-123', token: 'status-token'
  });
  assert.deepEqual(actionCalls(harness, 'yotm_job_cancel').at(-1).payload, {
    action: 'yotm_job_cancel', nonce: 'nonce-123', token: 'cancel-token'
  });
});

test('startup preserves prune-before-regenerate precedence and resumes recommendations independently', () => {
  const harness = createHarness({
    storage: {
      yotm_job_42_prune: 'prune-token',
      yotm_job_42_regenerate: 'regen-token',
      yotm_job_42_recommendation: 'recommend-token'
    }
  });
  loadAdmin(harness);

  assert.deepEqual(harness.storageReads, [
    'yotm_job_42_prune',
    'yotm_job_42_regenerate',
    'yotm_job_42_recommendation'
  ]);
  assert.deepEqual(
    actionCalls(harness, 'yotm_job_status').map((call) => call.payload.token),
    ['prune-token', 'recommend-token']
  );
  assert.equal(actionCalls(harness, 'yotm_prune_scan_batch').length, 0);
  assert.equal(actionCalls(harness, 'yotm_recommend_batch').length, 0);
});

test('startup resumes regenerate when no prune token exists', () => {
  const harness = createHarness({storage: {yotm_job_42_regenerate: 'regen-token'}});
  loadAdmin(harness);
  assert.deepEqual(
    actionCalls(harness, 'yotm_job_status').map((call) => call.payload.token),
    ['regen-token']
  );
  assert.equal(actionCalls(harness, 'yotm_regenerate_batch').length, 0);
});

test('404 status revalidation clears every stale token without starting work', () => {
  const cases = [
    {type: 'prune', forbiddenActions: ['yotm_prune_scan_batch', 'yotm_prune_delete_batch']},
    {type: 'regenerate', forbiddenActions: ['yotm_regenerate_batch']},
    {type: 'recommendation', forbiddenActions: ['yotm_recommend_batch']}
  ];

  for (const item of cases) {
    const key = 'yotm_job_42_' + item.type;
    const harness = createHarness({
      storage: {[key]: 'stale-token'},
      responses: {
        yotm_job_status: {kind: 'fail', value: {status: 404, responseJSON: {success: false}}}
      }
    });
    loadAdmin(harness);

    assert.equal(harness.storage.has(key), false, item.type + ' token should be cleared');
    item.forbiddenActions.forEach((action) => {
      assert.equal(actionCalls(harness, action).length, 0, item.type + ' must not start ' + action);
    });
  }
});

test('network and server failures preserve every token for later revalidation', () => {
  for (const status of [0, 503]) {
    for (const type of ['prune', 'regenerate', 'recommendation']) {
      const key = 'yotm_job_42_' + type;
      const harness = createHarness({
        storage: {[key]: 'retry-token'},
        responses: {yotm_job_status: {kind: 'fail', value: {status}}}
      });
      loadAdmin(harness);
      assert.equal(harness.storage.get(key), 'retry-token', type + ' token should survive status ' + status);
    }
  }
});

test('save-and-regenerate schedules one prepare call only when no stored destructive job exists', () => {
  const harness = createHarness({runAfterSave: true});
  loadAdmin(harness);

  assert.equal(harness.timers.length, 1);
  assert.equal(harness.timers[0].delay, 250);
  harness.timers[0].callback();
  assert.equal(actionCalls(harness, 'yotm_regenerate_prepare').length, 1);
  assert.equal(actionCalls(harness, 'yotm_regenerate_prepare')[0].payload.nonce, 'nonce-123');

  const blocked = createHarness({
    runAfterSave: true,
    storage: {yotm_job_42_prune: 'existing-prune'}
  });
  loadAdmin(blocked);
  assert.equal(blocked.timers.length, 0);
  assert.equal(actionCalls(blocked, 'yotm_regenerate_prepare').length, 0);
});

test('late server-discovered destructive jobs suppress delayed save-and-regenerate prepare', () => {
  for (const type of ['prune', 'regenerate']) {
    const harness = createHarness({runAfterSave: true});
    loadAdmin(harness);

    const recent = actionCalls(harness, 'yotm_jobs_recent')[0];
    recent.deferred.resolve({
      success: true,
      data: {
        jobs: [{
          type,
          token: 'server-' + type,
          status: type === 'prune' ? 'scanning' : 'running',
          processed: 2,
          total: 10,
          updated_at: '2026-08-29 00:00:00'
        }]
      }
    });

    assert.deepEqual(
      actionCalls(harness, 'yotm_job_status').map((call) => call.payload.token),
      ['server-' + type]
    );
    harness.timers[0].callback();
    assert.equal(actionCalls(harness, 'yotm_regenerate_prepare').length, 0, type);
  }
});

test('sizes module registers moved handlers against the shared core', () => {
  const harness = createHarness();
  const calls = [];
  harness.window.YOTMAdmin = {
    activateTab(tab) { calls.push(['activateTab', tab]); },
    t(key, fallback) { calls.push(['t', key]); return fallback; }
  };
  vm.runInContext(sizesSource, harness.context, {filename: 'js/admin-sizes.js'});

  const selectors = harness.handlers.map((handler) => handler.delegatedSelector || handler.selector);
  for (const selector of [
    '#yotm_sizes_enable_core',
    '#yotm_sizes_enable_all',
    '#yotm_sizes_disable_all',
    '#yotm_sizes_prune_disabled',
    'button[name="yotm_save_and_regenerate"]'
  ]) {
    assert.ok(selectors.includes(selector), 'missing moved Size handler: ' + selector);
  }
});

test('all localized keys consumed by bundled admin modules are discovered in PHP', () => {
  const jsKeys = new Set(
    [...(adminSource + '\n' + sizesSource).matchAll(/\bt\(\s*'([^']+)'/g)].map((match) => match[1])
  );
  const phpKeys = new Set(
    [...adminPhpSource.matchAll(/'([A-Za-z][A-Za-z0-9]*)'\s*=>\s*(?:__|esc_html__|esc_attr__)\(/g)]
      .map((match) => match[1])
  );
  assert.deepEqual([...jsKeys].filter((key) => !phpKeys.has(key)), []);
});

test('admin modules retain the established AJAX action and storage contracts', () => {
  const combined = adminSource + '\n' + sizesSource;
  const actions = new Set([
    ...combined.matchAll(/action:\s*'(yotm_[^']+)'/g),
    ...combined.matchAll(/request\('(yotm_[^']+)'/g)
  ].map((match) => match[1]));
  assert.deepEqual([...actions].sort(), [
    'yotm_job_cancel',
    'yotm_job_items',
    'yotm_job_status',
    'yotm_jobs_recent',
    'yotm_prune_approve',
    'yotm_prune_delete_batch',
    'yotm_prune_prepare',
    'yotm_prune_scan_batch',
    'yotm_recommend_batch',
    'yotm_recommend_prepare',
    'yotm_regenerate_batch',
    'yotm_regenerate_prepare'
  ]);

  for (const type of ['prune', 'regenerate', 'recommendation']) {
    assert.match(adminSource, new RegExp("recallJob\\('" + type + "'\\)"));
    assert.match(adminSource, new RegExp("registerWorkflow\\('" + type + "'"));
  }
  assert.match(adminSource, /'yotm_job_' \+ siteId \+ '_' \+ type/);
});
