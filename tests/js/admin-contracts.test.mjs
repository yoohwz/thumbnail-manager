import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
import {dirname, join, resolve} from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const adminSource = readFileSync(join(root, 'js/admin.js'), 'utf8');
const pruneSource = readFileSync(join(root, 'js/admin-prune.js'), 'utf8');
const recommendationsSource = readFileSync(join(root, 'js/admin-recommendations.js'), 'utf8');
const regenerateSource = readFileSync(join(root, 'js/admin-regenerate.js'), 'utf8');
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
        if (!elements[0]) {
          return '';
        }
        return elements[0].html === undefined ? elements[0].text || '' : elements[0].html;
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
      append(value) {
        elements.forEach((element) => { element.html = (element.html || '') + String(value ?? ''); });
        return collection;
      },
      prepend(value) {
        elements.forEach((element) => { element.html = String(value ?? '') + (element.html || ''); });
        return collection;
      },
      trigger() { return collection; },
      filter() { return collection; },
      is(query) { return query === ':checked' ? !!(elements[0] && elements[0].checked) : false; },
      hasClass() { return false; },
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
  vm.runInContext(pruneSource, harness.context, {filename: 'js/admin-prune.js'});
  vm.runInContext(recommendationsSource, harness.context, {filename: 'js/admin-recommendations.js'});
  vm.runInContext(regenerateSource, harness.context, {filename: 'js/admin-regenerate.js'});
}

function actionCalls(harness, action) {
  return harness.posts.filter((post) => post.payload.action === action);
}

function invokeHandler(harness, selector, event = 'click') {
  const handler = harness.handlers.find((item) => item.selector === selector && item.event === event && !item.delegatedSelector);
  assert.ok(handler, 'missing ' + event + ' handler for ' + selector);
  handler.callback.call({});
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

test('startup waits until Prune, Recommendations, and Regenerate are registered', () => {
  const harness = createHarness();
  vm.runInContext(adminSource, harness.context, {filename: 'js/admin.js'});
  assert.equal(actionCalls(harness, 'yotm_jobs_recent').length, 0);

  vm.runInContext(pruneSource, harness.context, {filename: 'js/admin-prune.js'});
  assert.equal(actionCalls(harness, 'yotm_jobs_recent').length, 0);

  vm.runInContext(recommendationsSource, harness.context, {filename: 'js/admin-recommendations.js'});
  assert.equal(actionCalls(harness, 'yotm_jobs_recent').length, 0);

  vm.runInContext(regenerateSource, harness.context, {filename: 'js/admin-regenerate.js'});
  assert.equal(actionCalls(harness, 'yotm_jobs_recent').length, 1);
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

test('late status callbacks cannot mutate a replacement workflow token', () => {
  const cases = [
    {type: 'prune', cancel: '#yotm_cancel', batch: ['yotm_prune_scan_batch'], status: {success: true, data: {status: 'scanning', total: 10}}},
    {type: 'regenerate', cancel: '#yotm_regen_cancel', batch: ['yotm_regenerate_batch'], status: {success: true, data: {status: 'running'}}},
    {type: 'recommendation', cancel: '#yotm_recommend_cancel', batch: ['yotm_recommend_batch'], status: {success: true, data: {status: 'scanning'}}}
  ];

  for (const item of cases) {
    for (const lateOutcome of ['404', 'success']) {
      const key = 'yotm_job_42_' + item.type;
      const harness = createHarness({
        storage: {[key]: 'token-a'},
        responses: {yotm_job_cancel: {kind: 'done', value: {success: true}}}
      });
      loadAdmin(harness);
      const statusA = actionCalls(harness, 'yotm_job_status')[0];

      invokeHandler(harness, item.cancel);
      actionCalls(harness, 'yotm_jobs_recent').at(-1).deferred.resolve({
        success: true,
        data: {jobs: [{type: item.type, token: 'token-b', status: item.type === 'regenerate' ? 'running' : 'scanning'}]}
      });
      assert.equal(harness.storage.get(key), 'token-b');

      if (lateOutcome === '404') {
        statusA.deferred.reject({status: 404});
      } else {
        statusA.deferred.resolve(item.status);
      }
      assert.equal(harness.storage.get(key), 'token-b', item.type + ' late ' + lateOutcome);
      item.batch.forEach((action) => assert.equal(actionCalls(harness, action).length, 0, item.type + ' late ' + lateOutcome));
    }
  }
});

test('late cancel callbacks cannot mutate a replacement workflow token', () => {
  const cases = [
    {type: 'prune', cancel: '#yotm_cancel', run: '#yotm_run', prepare: 'yotm_prune_prepare'},
    {type: 'regenerate', cancel: '#yotm_regen_cancel', run: '#yotm_regen_run', prepare: 'yotm_regenerate_prepare'},
    {type: 'recommendation', cancel: '#yotm_recommend_cancel', run: '#yotm_recommend_scan', prepare: 'yotm_recommend_prepare'}
  ];

  for (const item of cases) {
    for (const lateOutcome of ['done', 'fail']) {
      const key = 'yotm_job_42_' + item.type;
      const harness = createHarness({storage: {[key]: 'token-a'}});
      loadAdmin(harness);
      const statusA = actionCalls(harness, 'yotm_job_status')[0];

      invokeHandler(harness, item.cancel);
      const cancelA = actionCalls(harness, 'yotm_job_cancel')[0];
      statusA.deferred.reject({status: 404});
      actionCalls(harness, 'yotm_jobs_recent')[0].deferred.resolve({
        success: true,
        data: {jobs: [{type: item.type, token: 'token-b', status: item.type === 'regenerate' ? 'running' : 'scanning'}]}
      });
      assert.equal(harness.storage.get(key), 'token-b');

      if (lateOutcome === 'done') {
        cancelA.deferred.resolve({success: true});
        assert.equal(harness.storage.get(key), 'token-b', item.type + ' late cancel done');
      } else {
        actionCalls(harness, 'yotm_job_status').at(-1).deferred.reject({status: 404});
        assert.equal(harness.storage.has(key), false, item.type + ' replacement should reach terminal state');
        cancelA.deferred.reject({status: 500});
        invokeHandler(harness, item.run);
        assert.equal(actionCalls(harness, item.prepare).length, 1, item.type + ' late cancel fail must not block a new run');
      }
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

test('Prune module preserves prepare, manifest review, approval, and delete payloads', () => {
  const harness = createHarness();
  loadAdmin(harness);
  harness.$('input[name="yotm_prune_scope"]:checked').val('all');
  harness.$('.yotm_keep:checked').elements[0].value = '150x150';

  invokeHandler(harness, '#yotm_run');
  const prepare = actionCalls(harness, 'yotm_prune_prepare')[0];
  assert.deepEqual(JSON.parse(JSON.stringify(prepare.payload)), {
    action: 'yotm_prune_prepare',
    nonce: 'nonce-123',
    keep: ['150x150'],
    limit_subpaths: [],
    discover_orphans: 0,
    discover_historical: 0
  });
  prepare.deferred.resolve({success: true, data: {token: 'prune-module-token'}});

  assert.equal(harness.storage.get('yotm_job_42_prune'), 'prune-module-token');
  const scan = actionCalls(harness, 'yotm_prune_scan_batch')[0];
  assert.deepEqual(scan.payload, {
    action: 'yotm_prune_scan_batch', nonce: 'nonce-123', token: 'prune-module-token', batch: 100
  });
  scan.deferred.resolve({success: true, data: {
    scan_done: true,
    scan_percent: 100,
    scan_processed: 1,
    scan_total_attachments: 1,
    total: 1,
    manifest_hash: 'reviewed-manifest-hash',
    estimated_bytes: 1024,
    estimated_bytes_human: '1 KB',
    scan_base: 'uploads/2026/08',
    expires_at: '2026-08-29 10:00:00'
  }});

  assert.deepEqual(actionCalls(harness, 'yotm_job_items')[0].payload, {
    action: 'yotm_job_items',
    nonce: 'nonce-123',
    token: 'prune-module-token',
    page: 1,
    per_page: 25,
    search: ''
  });

  invokeHandler(harness, '#yotm_approve_delete');
  assert.equal(actionCalls(harness, 'yotm_prune_approve').length, 0, 'approval requires explicit confirmation');
  harness.$('#yotm_review_confirm').elements[0].checked = true;
  invokeHandler(harness, '#yotm_approve_delete');
  const approve = actionCalls(harness, 'yotm_prune_approve')[0];
  assert.deepEqual(approve.payload, {
    action: 'yotm_prune_approve',
    nonce: 'nonce-123',
    token: 'prune-module-token',
    manifest_hash: 'reviewed-manifest-hash',
    confirmed: 1
  });
  approve.deferred.resolve({success: true, data: {status: 'approved'}});

  const deletion = actionCalls(harness, 'yotm_prune_delete_batch')[0];
  assert.deepEqual(deletion.payload, {
    action: 'yotm_prune_delete_batch',
    nonce: 'nonce-123',
    token: 'prune-module-token',
    manifest_hash: 'reviewed-manifest-hash',
    batch: 100
  });
  deletion.deferred.resolve({success: true, data: {
    done: true, processed: 1, percent: 100, deleted: 1, failed: 0, bytes_human: '1 KB', errors: []
  }});
  assert.equal(harness.storage.has('yotm_job_42_prune'), false);
  assert.match(harness.cache.get('#yotm_results').html(), /Done\. Deleted 1 files/);
});

test('Prune module preserves empty and network-retry state transitions', () => {
  const empty = createHarness({
    responses: {
      yotm_prune_prepare: {kind: 'done', value: {success: true, data: {token: 'prune-empty'}}},
      yotm_prune_scan_batch: {kind: 'done', value: {success: true, data: {
        scan_done: true, scan_percent: 100, scan_processed: 0, scan_total_attachments: 0, total: 0
      }}}
    }
  });
  loadAdmin(empty);
  empty.$('input[name="yotm_prune_scope"]:checked').val('all');
  invokeHandler(empty, '#yotm_run');
  assert.equal(empty.storage.has('yotm_job_42_prune'), false);
  assert.equal(actionCalls(empty, 'yotm_job_items').length, 0);
  assert.equal(actionCalls(empty, 'yotm_prune_approve').length, 0);
  assert.equal(actionCalls(empty, 'yotm_prune_delete_batch').length, 0);
  assert.match(empty.cache.get('#yotm_results').html(), /No matching thumbnails found/);

  const retry = createHarness({
    responses: {
      yotm_prune_prepare: {kind: 'done', value: {success: true, data: {token: 'prune-retry'}}},
      yotm_prune_scan_batch: {kind: 'fail', value: {status: 0}}
    }
  });
  loadAdmin(retry);
  retry.$('input[name="yotm_prune_scope"]:checked').val('all');
  invokeHandler(retry, '#yotm_run');
  assert.equal(retry.storage.get('yotm_job_42_prune'), 'prune-retry');
  assert.equal(retry.cache.get('#yotm_run').elements[0].disabled, true);
  assert.match(retry.cache.get('#yotm_results').html(), /Reload this page to resume it/);
});

test('Prune module rejects an empty selected scope and forwards only canonical parent subpaths', () => {
  const emptySelection = createHarness();
  loadAdmin(emptySelection);
  emptySelection.$('input[name="yotm_prune_scope"]:checked').val('selected');
  emptySelection.$('.yotm_subpath_option:checked').elements.splice(0);
  invokeHandler(emptySelection, '#yotm_run');
  assert.equal(actionCalls(emptySelection, 'yotm_prune_prepare').length, 0);
  assert.match(emptySelection.cache.get('#yotm_results').html(), /Choose at least one folder/);

  const selected = createHarness();
  loadAdmin(selected);
  selected.$('input[name="yotm_prune_scope"]:checked').val('selected');
  selected.$('.yotm_subpath_option:checked').elements.splice(
    0,
    1,
    {value: '2026/08'},
    {value: '2025/01'},
    {value: '2025'}
  );
  invokeHandler(selected, '#yotm_run');
  assert.deepEqual(
    Array.from(actionCalls(selected, 'yotm_prune_prepare')[0].payload.limit_subpaths),
    ['2025', '2026/08']
  );
});

test('Prune approved/deleting resume revalidates status before forwarding the persisted manifest hash', () => {
  for (const status of ['approved', 'deleting']) {
    const harness = createHarness({storage: {yotm_job_42_prune: 'resume-' + status}});
    loadAdmin(harness);
    assert.equal(actionCalls(harness, 'yotm_prune_delete_batch').length, 0, status + ' must wait for status');

    actionCalls(harness, 'yotm_job_status')[0].deferred.resolve({success: true, data: {
      status,
      total: 3,
      processed: 1,
      manifest_hash: 'persisted-' + status + '-hash'
    }});
    assert.deepEqual(actionCalls(harness, 'yotm_prune_delete_batch')[0].payload, {
      action: 'yotm_prune_delete_batch',
      nonce: 'nonce-123',
      token: 'resume-' + status,
      manifest_hash: 'persisted-' + status + '-hash',
      batch: 100
    });
  }
});

test('Regenerate module preserves prepare and bounded batch payloads', () => {
  const harness = createHarness();
  loadAdmin(harness);
  invokeHandler(harness, '#yotm_regen_run');

  const prepare = actionCalls(harness, 'yotm_regenerate_prepare')[0];
  assert.deepEqual(prepare.payload, {
    action: 'yotm_regenerate_prepare',
    nonce: 'nonce-123',
    scope: 'all',
    subpath: '',
    attachment_ids: '',
    only_missing: 1,
    force_all: 0
  });
  prepare.deferred.resolve({
    success: true,
    data: {
      token: 'regen-module-token',
      total_known: true,
      total: 1,
      processed: 0,
      regenerated: 0,
      skipped: 0,
      failed: 0,
      scope_label: 'All media'
    }
  });

  assert.equal(harness.storage.get('yotm_job_42_regenerate'), 'regen-module-token');
  assert.deepEqual(actionCalls(harness, 'yotm_regenerate_batch')[0].payload, {
    action: 'yotm_regenerate_batch', nonce: 'nonce-123', token: 'regen-module-token', batch: 20
  });
});

test('Recommendations module preserves prepare and bounded batch payloads', () => {
  const harness = createHarness();
  loadAdmin(harness);
  invokeHandler(harness, '#yotm_recommend_scan');

  const prepare = actionCalls(harness, 'yotm_recommend_prepare')[0];
  assert.deepEqual(prepare.payload, {action: 'yotm_recommend_prepare', nonce: 'nonce-123'});
  prepare.deferred.resolve({success: true, data: {token: 'recommend-module-token'}});

  assert.equal(harness.storage.get('yotm_job_42_recommendation'), 'recommend-module-token');
  assert.deepEqual(actionCalls(harness, 'yotm_recommend_batch')[0].payload, {
    action: 'yotm_recommend_batch', nonce: 'nonce-123', token: 'recommend-module-token', batch: 100
  });
});

test('Regenerate module preserves terminal, empty, and network-retry state transitions', () => {
  const terminal = createHarness({
    responses: {
      yotm_regenerate_prepare: {kind: 'done', value: {success: true, data: {
        token: 'regen-terminal', total_known: true, total: 1, processed: 0,
        regenerated: 0, skipped: 0, failed: 0, scope_label: 'All media'
      }}},
      yotm_regenerate_batch: {kind: 'done', value: {success: true, data: {
        done: true, total_known: true, total: 1, processed: 1, percent: 100,
        regenerated: 1, skipped: 0, failed: 0, scope_label: 'All media', errors: []
      }}}
    }
  });
  loadAdmin(terminal);
  invokeHandler(terminal, '#yotm_regen_run');
  assert.equal(terminal.storage.has('yotm_job_42_regenerate'), false);
  assert.match(terminal.cache.get('#yotm_regen_results').html(), /Done\. Regenerated 1 attachments/);
  assert.equal(terminal.cache.get('#yotm_regen_run').elements[0].disabled, false);

  const empty = createHarness({
    responses: {
      yotm_regenerate_prepare: {kind: 'done', value: {success: true, data: {
        token: 'regen-empty', total_known: true, total: 0, processed: 0,
        regenerated: 0, skipped: 0, failed: 0, scope_label: 'All media'
      }}},
      yotm_job_cancel: {kind: 'done', value: {success: true}}
    }
  });
  loadAdmin(empty);
  invokeHandler(empty, '#yotm_regen_run');
  assert.equal(actionCalls(empty, 'yotm_regenerate_batch').length, 0);
  assert.equal(actionCalls(empty, 'yotm_job_cancel').length, 1);
  assert.equal(empty.storage.has('yotm_job_42_regenerate'), false);
  assert.match(empty.cache.get('#yotm_regen_results').html(), /No image attachments found/);

  const retry = createHarness({
    responses: {
      yotm_regenerate_prepare: {kind: 'done', value: {success: true, data: {
        token: 'regen-retry', total_known: true, total: 1, processed: 0,
        regenerated: 0, skipped: 0, failed: 0, scope_label: 'All media'
      }}},
      yotm_regenerate_batch: {kind: 'fail', value: {status: 0}}
    }
  });
  loadAdmin(retry);
  invokeHandler(retry, '#yotm_regen_run');
  assert.equal(retry.storage.get('yotm_job_42_regenerate'), 'regen-retry');
  assert.equal(retry.cache.get('#yotm_regen_run').elements[0].disabled, true);
  assert.match(retry.cache.get('#yotm_regen_results').html(), /Reload this page to resume it/);
});

test('Recommendations module preserves terminal, empty, and network-retry state transitions', () => {
  const terminal = createHarness({
    config: {registeredSizesSignature: 'sizes-signature'},
    responses: {
      yotm_recommend_prepare: {kind: 'done', value: {success: true, data: {token: 'recommend-terminal'}}},
      yotm_recommend_batch: {kind: 'done', value: {success: true, data: {
        done: true, phase: 'content', processed: 1, total: 1, percent: 100,
        result: {
          schema_version: 2, registered_sizes_signature: 'sizes-signature',
          protected_count: 1, detected_reference_count: 0, unknown_count: 0,
          generated_bytes_human: '1 KB',
          items: [{name: 'thumbnail', dimensions: '150x150', status: 'protected', apply_action: 'preserve'}]
        }
      }}}
    }
  });
  loadAdmin(terminal);
  invokeHandler(terminal, '#yotm_recommend_scan');
  assert.equal(terminal.storage.has('yotm_job_42_recommendation'), false);
  assert.match(terminal.cache.get('#yotm_recommend_results').html(), /Safe recommendations are ready/);
  assert.equal(terminal.cache.get('#yotm_recommend_scan').elements[0].disabled, false);

  const empty = createHarness({
    config: {registeredSizesSignature: 'sizes-signature'},
    responses: {
      yotm_recommend_prepare: {kind: 'done', value: {success: true, data: {token: 'recommend-empty'}}},
      yotm_recommend_batch: {kind: 'done', value: {success: true, data: {
        done: true, phase: 'content', processed: 0, total: 0, percent: 100,
        result: {schema_version: 2, registered_sizes_signature: 'sizes-signature', items: []}
      }}}
    }
  });
  loadAdmin(empty);
  invokeHandler(empty, '#yotm_recommend_scan');
  assert.equal(empty.storage.has('yotm_job_42_recommendation'), false);
  assert.match(empty.cache.get('#yotm_recommend_results').html(), /No recommendation data found/);

  const retry = createHarness({
    responses: {
      yotm_recommend_prepare: {kind: 'done', value: {success: true, data: {token: 'recommend-retry'}}},
      yotm_recommend_batch: {kind: 'fail', value: {status: 0}}
    }
  });
  loadAdmin(retry);
  invokeHandler(retry, '#yotm_recommend_scan');
  assert.equal(retry.storage.get('yotm_job_42_recommendation'), 'recommend-retry');
  assert.equal(retry.cache.get('#yotm_recommend_scan').elements[0].disabled, true);
  assert.match(retry.cache.get('#yotm_recommend_results').html(), /Reload this page to resume it/);
});

test('workflow implementation lives only in the approved extracted modules', () => {
  assert.doesNotMatch(adminSource, /function (?:prepare|resume)Prune\b|yotm_prune_(?:prepare|scan_batch|approve|delete_batch)/);
  assert.doesNotMatch(adminSource, /function (?:prepare|resume)Regenerate\b|yotm_regenerate_(?:prepare|batch)/);
  assert.doesNotMatch(adminSource, /function (?:prepare|resume)Recommendation\b|yotm_recommend_(?:prepare|batch)/);
  assert.match(pruneSource, /registerWorkflow\('prune'/);
  assert.match(pruneSource, /request\('yotm_prune_prepare'/);
  assert.match(pruneSource, /request\('yotm_prune_scan_batch'/);
  assert.match(pruneSource, /request\('yotm_prune_approve'/);
  assert.match(pruneSource, /request\('yotm_prune_delete_batch'/);
  assert.match(regenerateSource, /registerWorkflow\('regenerate'/);
  assert.match(regenerateSource, /request\('yotm_regenerate_prepare'/);
  assert.match(regenerateSource, /request\('yotm_regenerate_batch'/);
  assert.match(recommendationsSource, /registerWorkflow\('recommendation'/);
  assert.match(recommendationsSource, /request\('yotm_recommend_prepare'/);
  assert.match(recommendationsSource, /request\('yotm_recommend_batch'/);
});

test('WordPress assets register and enqueue each workflow module after the localized core', () => {
  for (const [handle, path] of [
    ['yotm-prune-admin-prune', 'js/admin-prune.js'],
    ['yotm-prune-admin-recommendations', 'js/admin-recommendations.js'],
    ['yotm-prune-admin-regenerate', 'js/admin-regenerate.js'],
    ['yotm-prune-admin-sizes', 'js/admin-sizes.js']
  ]) {
    assert.match(adminPhpSource, new RegExp("'" + handle + "'[\\s\\S]+?" + path.replace('.', '\\.') + "'[\\s\\S]+?array\\( 'yotm-prune-admin' \\)"));
    assert.match(adminPhpSource, new RegExp("wp_enqueue_script\\( '" + handle + "' \\)"));
  }
  assert.ok(adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-prune' )") < adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-recommendations' )"));
  assert.ok(adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-recommendations' )") < adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-regenerate' )"));
  assert.ok(adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-regenerate' )") < adminPhpSource.indexOf("wp_enqueue_script( 'yotm-prune-admin-sizes' )"));
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

test('disabled-size cleanup enables reviewed legacy discovery without approving or deleting', () => {
  const harness = createHarness();
  loadAdmin(harness);
  harness.$('input[name="yotm_enable_sizes[]"]:checked').elements.splice(0, 1, {value: 'thumbnail'});
  harness.$('.yotm_keep').elements.splice(0, 1, {value: 'thumbnail'}, {value: 'medium'});
  vm.runInContext(sizesSource, harness.context, {filename: 'js/admin-sizes.js'});

  invokeHandler(harness, '#yotm_sizes_prune_disabled');
  invokeHandler(harness, '#yotm_run');

  assert.equal(harness.$('#yotm_discover_orphans').elements[0].checked, true);
  assert.equal(harness.$('#yotm_discover_historical').elements[0].checked, false);
  assert.equal(actionCalls(harness, 'yotm_prune_prepare')[0].payload.discover_orphans, 1);
  assert.equal(actionCalls(harness, 'yotm_prune_prepare')[0].payload.discover_historical, 0);
  assert.equal(actionCalls(harness, 'yotm_prune_approve').length, 0);
  assert.equal(actionCalls(harness, 'yotm_prune_delete_batch').length, 0);
  assert.match(sizesSource, /#yotm_run[^\n]+trigger\('click'\)/);
});

test('all localized keys consumed by bundled admin modules are discovered in PHP', () => {
  const combined = [adminSource, pruneSource, recommendationsSource, regenerateSource, sizesSource].join('\n');
  const jsKeys = new Set(
    [...combined.matchAll(/\bt\(\s*'([^']+)'/g)].map((match) => match[1])
  );
  const phpKeys = new Set(
    [...adminPhpSource.matchAll(/'([A-Za-z][A-Za-z0-9]*)'\s*=>\s*(?:__|esc_html__|esc_attr__)\(/g)]
      .map((match) => match[1])
  );
  assert.deepEqual([...jsKeys].filter((key) => !phpKeys.has(key)), []);
});

test('admin modules retain the established AJAX action and storage contracts', () => {
  const combined = [adminSource, pruneSource, recommendationsSource, regenerateSource, sizesSource].join('\n');
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
    assert.match(combined, new RegExp("registerWorkflow\\('" + type + "'"));
  }
  assert.match(adminSource, /'yotm_job_' \+ siteId \+ '_' \+ type/);
});
