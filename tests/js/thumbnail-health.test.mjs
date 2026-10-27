import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../js/admin-regenerate.js', import.meta.url), 'utf8');

function harness(config = {}) {
  const nodes = new Map();
  const calls = [];
  const handlers = [];
  const timers = [];
  const pickerEvents = {};
  let workflow;
  let scope = 'all';
  let mode = 'missing';
  let pickerItems = [];
  const selection = {toJSON: () => pickerItems, reset() {}, add() {}};
  const frame = {on(event, callback) { pickerEvents[event] = callback; }, state: () => ({get: () => selection}), open() { pickerEvents.open(); }};
  function $(key) {
    if (typeof key === 'object') return {data: name => key[name]};
    if (!nodes.has(key)) {
      const item = {value: '', content: '', disabled: false, hidden: false};
      const chain = {
        on(event, selector, callback) { handlers.push({key, event, selector: typeof selector === 'string' ? selector : null, callback: callback || selector}); return chain; },
        val(value) {
          if (arguments.length) { item.value = value; return chain; }
          if (key === 'input[name="yotm_regen_scope"]:checked') return scope;
          if (key === 'input[name="yotm_regen_mode"]:checked') return mode;
          return item.value;
        },
        prop(name, value) {
          if (name === 'checked' && value && key.includes('[value="ids"]')) scope = 'ids';
          item[name] = value; return chain;
        },
        text(value) { if (arguments.length) { item.content = value; return chain; } return item.content; },
        html(value) { if (arguments.length) { item.content = value; return chain; } return item.content; },
        empty() { item.content = ''; return chain; },
        prepend(value) { item.content = value + item.content; return chain; },
        addClass() { item.hidden = true; return chain; },
        removeClass() { item.hidden = false; return chain; },
        toggleClass() { return chain; }, show() { return chain; }, hide() { return chain; },
        attr() { return chain; }, css() { return chain; }, trigger() { return chain; }, item
      };
      nodes.set(key, chain);
    }
    return nodes.get(key);
  }
  function request(action, payload) {
    const callbacks = {};
    const pending = {done(callback) { callbacks.done = callback; return pending; }, fail(callback) { callbacks.fail = callback; return pending; }, always(callback) { callbacks.always = callback; return pending; }};
    const call = {action, payload: JSON.parse(JSON.stringify(payload)), resolve(data) { callbacks.done({success: true, data}); if (callbacks.always) callbacks.always(); }};
    calls.push(call);
    return pending;
  }
  const core = {
    activateTab() {}, cancelJob: token => request('cancel', {token}),
    errorDetails: () => '', escapeHtml: value => String(value ?? '').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
    forgetJob() {}, formatTemplate: (text, args) => Object.entries(args).reduce((result, [key, value]) => result.replaceAll(key, value), text),
    getJobStatus: token => request('status', {token}), htmlNotice: (type, message) => '<p>' + message + '</p>',
    isJobStopping: () => false, stopPreparation() {}, stopJobMessage: () => 'Job stopped.',
    isMissingJobStatus: () => false, loadRecentJobs() {}, registerWorkflow(type, value) {workflow = value;},
    rememberJob() {}, request, responseError: (response, fallback) => fallback, t: (key, fallback) => fallback
  };
  vm.runInNewContext(source, {jQuery: $, window: {YOTMAdmin: core, YOTM: config, wp: {media: Object.assign(() => frame, {attachment: id => ({id})})}, setTimeout: callback => timers.push(callback)}});
  return {
    calls, nodes, timers, $, workflow,
    setScope(value) {scope = value;}, setMode(value) {mode = value;},
    click(key, data) {
      const handler = handlers.find(item => item.key === key && item.event === 'click' && (!!data === !!item.selector));
      assert.ok(handler, 'handler exists for ' + key);
      handler.callback.call(data || {});
    },
    pick(ids) { pickerItems = ids.map(id => ({id})); pickerEvents.select(); },
    last(action) { return calls.filter(item => item.action === action).at(-1); }
  };
}

function completeCheck(h, token = 'report-one', failed = 0) {
  h.click('#yotm_regen_run');
  h.last('yotm_regenerate_prepare').resolve({token, operation: 'check', total: 1, total_known: true});
  h.last('yotm_regenerate_batch').resolve({operation: 'check', done: true, total: 1, total_known: true, processed: 1, regenerated: 1, skipped: 0, failed});
  h.last('yotm_thumbnail_health_results').resolve({operation: 'check', done: true, page: 1, pages: 2, items: [{attachment_id: 42, title: '<image>', status: 'missing', reason: 'file_missing', action: 'Repair', sizes: {medium: {status: 'missing', reason: 'file_missing', action: 'Repair'}}}]});
}

test('navigation prefill only selects images and never starts a check or repair', () => {
  const h = harness({mediaSelection: {requested: true, ids: [42, 43]}});
  assert.equal(h.$('#yotm_regen_attachment_ids').val(), '42,43');
  assert.equal(h.calls.length, 0);
  h.click('#yotm_regen_run');
  assert.equal(h.last('yotm_regenerate_prepare').payload.scope, 'ids');
  assert.equal(h.last('yotm_regenerate_prepare').payload.operation, 'check');
});

test('invalid explicit navigation scope does not fall back to all media', () => {
  const h = harness({mediaSelection: {requested: true, ids: [], error: 'Invalid selection'}});
  h.click('#yotm_regen_run');
  assert.equal(h.calls.length, 0);
});

test('native image picker updates selection and rejects more than 500 without truncation', () => {
  const h = harness();
  h.click('#yotm_regen_choose');
  h.pick([11, 12]);
  assert.equal(h.$('#yotm_regen_attachment_ids').val(), '11,12');
  assert.match(h.$('#yotm_regen_selection_count').text(), /2 images selected/);
  h.pick(Array.from({length: 501}, (_, i) => i + 1));
  assert.equal(h.$('#yotm_regen_attachment_ids').val(), '11,12');
  assert.match(h.$('#yotm_regen_selection_error').html(), /500/);
  assert.equal(h.calls.length, 0);
});

test('check completion renders escaped paginated details and only explicit repair sends the report token', () => {
  const h = harness();
  completeCheck(h, 'report-one', 1);
  assert.equal(h.calls.filter(call => call.action === 'yotm_regenerate_prepare').length, 1);
  assert.equal(h.$('#yotm_regen_repair').item.hidden, false);
  assert.match(h.$('#yotm_health_results').html(), /&lt;image&gt;/);
  assert.match(h.$('#yotm_health_results').html(), /Thumbnail file is missing/);
  assert.doesNotMatch(h.$('#yotm_regen_results').html(), /Regenerated:/);
  h.click('#yotm_health_results', {page: 2});
  assert.deepEqual(h.last('yotm_thumbnail_health_results').payload, {token: 'report-one', page: 2, per_page: 25});
  h.setScope('all');
  h.$('#yotm_regen_attachment_ids').val('999');
  h.click('#yotm_regen_repair');
  assert.deepEqual(h.last('yotm_regenerate_prepare').payload, {operation: 'repair', report_token: 'report-one'});
});

test('viewing a completed check loads results without replaying a batch or repair', () => {
  const h = harness();
  h.workflow.resume('completed-report');
  h.last('status').resolve({status: 'completed', context: {operation: 'check'}});
  assert.deepEqual(h.last('yotm_thumbnail_health_results').payload, {token: 'completed-report', page: 1, per_page: 25});
  assert.equal(h.calls.some(call => call.action === 'yotm_regenerate_batch' || call.action === 'yotm_regenerate_prepare'), false);
});

test('Force retains its existing prepare payload and does not use health repair authority', () => {
  const h = harness();
  h.setMode('force');
  h.click('#yotm_regen_run');
  assert.deepEqual(h.last('yotm_regenerate_prepare').payload, {scope: 'all', subpath: '', attachment_ids: '', only_missing: 0, force_all: 1});
});

test('stale results cannot expose repair for a different active job', () => {
  const h = harness();
  completeCheck(h);
  h.click('#yotm_health_results', {page: 2});
  const stale = h.last('yotm_thumbnail_health_results');
  h.workflow.resume('another-job');
  stale.resolve({operation: 'check', done: true, items: []});
  assert.equal(h.$('#yotm_regen_repair').item.hidden, true);
  h.click('#yotm_regen_repair');
  assert.equal(h.calls.filter(call => call.action === 'yotm_regenerate_prepare').length, 1);
});


test('stopping during prepare prevents a late response from starting or retaining a job', () => {
  const h = harness();
  h.click('#yotm_regen_run');
  const late = h.last('yotm_regenerate_prepare');
  h.click('#yotm_regen_cancel');
  late.resolve({token: 'late-report', operation: 'check', total: 1});
  assert.equal(h.calls.some(call => call.action === 'yotm_regenerate_batch'), false);
  assert.deepEqual(h.last('cancel').payload, {token: 'late-report'});
  h.last('cancel').resolve({job: {token: 'late-report', type: 'regenerate', status: 'cancelled'}});
  h.click('#yotm_regen_run');
  assert.equal(h.calls.filter(call => call.action === 'yotm_regenerate_prepare').length, 2);
});

test('healthy image rows summarize size counts while retaining closed accessible details', () => {
  const h = harness();
  h.workflow.resume('compact-report');
  h.last('status').resolve({status: 'completed', context: {operation: 'check'}});
  const sizes = Object.fromEntries(Array.from({length: 7}, (_, i) => ['size_' + i, {status: 'healthy', reason: 'file_present'}]));
  sizes.large = {status: 'not_needed', reason: 'source_size_not_resizable'};
  sizes.extra_large = {status: 'not_needed', reason: 'source_size_not_resizable'};
  h.last('yotm_thumbnail_health_results').resolve({operation: 'check', done: true, items: [{attachment_id: 42, title: '<long image title>', status: 'healthy', item_status: 'done', message: 'No required enabled thumbnails are missing.', sizes}]});
  const html = h.$('#yotm_health_results').html();
  const overview = html.split('<details')[0];
  assert.match(overview, /Healthy: 7/);
  assert.match(overview, /Not needed: 2/);
  assert.doesNotMatch(overview, /Thumbnail file is present|No required enabled thumbnails|Job result:|Suggested action/);
  assert.match(html, /<details class="yo-health-details"><summary>View size details \(9\)/);
  assert.match(html, /screen-reader-text.*&lt;long image title&gt;.*#42/);
  assert.match(html, /<th scope="row">size_6<\/th>/);
  assert.match(html, /<th scope="row">extra_large<\/th>/);
});

test('blocked reasons, actions and actual repair outcomes stay visible before size disclosure', () => {
  const h = harness();
  h.workflow.resume('blocked-repair');
  h.last('status').resolve({status: 'completed', context: {operation: 'repair'}});
  h.last('yotm_thumbnail_health_results').resolve({operation: 'repair', done: true, items: [{attachment_id: 9, title: 'Image', status: 'blocked', item_status: 'failed', message: '<Source unavailable>', action: '<Restore source>', restored_count: 1, restored_sizes: ['medium'], sizes: {thumbnail: {status: 'healthy', reason: 'file_present'}, medium: {status: 'missing', reason: 'file_missing'}, large: {status: 'blocked', action: 'Review size'}}}]});
  const html = h.$('#yotm_health_results').html();
  const overview = html.split('<details')[0];
  assert.match(overview, /&lt;Source unavailable&gt;/);
  assert.match(overview, /&lt;Restore source&gt;/);
  assert.match(overview, /Restored: 1/);
  assert.match(overview, /Job result: Failed/);
  assert.match(overview, /Blocked: 1/);
  assert.match(overview, /Missing: 1/);
  assert.ok(html.indexOf('<th scope="row">large') < html.indexOf('<th scope="row">medium'));
  assert.ok(html.indexOf('<th scope="row">medium') < html.indexOf('<th scope="row">thumbnail'));
});


test('custom thumbnail scope sends only selected slugs and never changes upload settings', () => {
  const h = harness();
  h.$('input[name="yotm_regen_size_scope"]:checked').val('selected');
  h.$('#yotm_regen_selected_sizes').val(['medium', 'large']);
  h.click('#yotm_regen_run');
  assert.deepEqual(h.last('yotm_regenerate_prepare').payload.selected_sizes, ['medium', 'large']);
  assert.equal(h.calls.length, 1);
  assert.equal(h.last('yotm_regenerate_prepare').payload.operation, 'check');
});

test('empty custom thumbnail scope blocks preparation without falling back to all sizes', () => {
  const h = harness();
  h.$('input[name="yotm_regen_size_scope"]:checked').val('selected');
  h.$('#yotm_regen_selected_sizes').val([]);
  h.click('#yotm_regen_run');
  assert.equal(h.calls.length, 0);
  assert.match(h.$('#yotm_regen_sizes_error').html(), /Choose at least one/);
});

test('default thumbnail scope omits selection and explicit repair retains saved report authority', () => {
  const h = harness();
  completeCheck(h);
  assert.equal(Object.hasOwn(h.calls[0].payload, 'selected_sizes'), false);
  h.$('input[name="yotm_regen_size_scope"]:checked').val('selected');
  h.$('#yotm_regen_selected_sizes').val(['other']);
  h.click('#yotm_regen_repair');
  assert.deepEqual(h.last('yotm_regenerate_prepare').payload, {operation: 'repair', report_token: 'report-one'});
});
