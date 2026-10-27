import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../js/admin-results.js', import.meta.url), 'utf8');
function fixture(options = {}) {
  const handlers = new Map();
  const calls = [];
  const nodes = new Map();
  const downloads = [];
  const blobs = [];
  const $ = function(selector) {
    if (selector && typeof selector === 'object') { return selector; }
    if (!nodes.has(selector)) {
      const node = {
        value: '', attributes: {}, content: '',
        on(event, match, handler) { handlers.set(event + ':' + match, handler); return node; },
        html(value) { if (value !== undefined) { node.content = value; } return node; },
        text(value) { if (value !== undefined) { node.content = value; } return node; },
        removeClass() { return node; }, addClass() { return node; }, trigger() { return node; },
        prop(name, value) { if (value !== undefined) { node.attributes[name] = value; return node; } return node.attributes[name]; },
        val() { return node.value; }, attr(name) { return node.attributes[name]; }
      };
      nodes.set(selector, node);
    }
    return nodes.get(selector);
  };
  const report = {
    schema_version: 1, page: 1, pages: 1, total: 1, filter: '', unit: 'images', read_at: '2026-10-27 00:00:00', complete: true, can_retry: true, limitations: ['Saved snapshot'],
    job: {token: 'source-token', type: options.type || 'regenerate', status: options.status || 'completed', processed: 1, total: 1, succeeded: 0, failed: 1, skipped: 0, created_at: '2026-10-26 00:00:00', updated_at: '2026-10-26 00:00:00', expires_at: '2026-11-02 00:00:00', context: {selected_sizes: ['thumbnail']}},
    items: [{key: 'a'.repeat(64), title: '<bad title>', status: 'failed', error: '<script>unsafe</script>', retryable: true, health: {sizes: {thumbnail: {status: 'missing', reason: 'file_missing', action: 'Restore source'}}}}]
  };
  const core = {
    escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character])); },
    t(key, fallback) { return fallback; },
    htmlNotice(type, value) { return String(value); },
    responseError(response, fallback) { return response?.data?.msg || fallback; },
    formatBytes(value) { return String(value); },
    request(action, data) {
      calls.push({action, data});
      const response = options.respond ? options.respond(action, data, report) : {success: true, data: structuredClone(report)};
      return {done(callback) { callback(response); return this; }, fail() { return this; }};
    },
    resumeWorkflow(type, token) { calls.push({action: 'resume', type, token}); },
    rememberJob(type, token) { calls.push({action: 'remember', type, token}); },
    loadRecentJobs() { calls.push({action: 'recent'}); }
  };
  const window = {YOTMAdmin: core, Blob: class {constructor(parts) { blobs.push(parts.join('')); }}, URL: {createObjectURL() { return 'blob:test'; }, revokeObjectURL() {}}, setTimeout(callback) { callback(); }};
  const document = {createElement() { return {click() { downloads.push(this.download); }}; }};
  vm.runInNewContext(source, {jQuery: $, window, document, Set, JSON, Date});
  return {window, calls, nodes, handlers, $, report, blobs, downloads};
}

test('opening each saved job type reads results and never resumes or batches', () => {
  for (const type of ['prune', 'regenerate', 'recommendation']) {
    const harness = fixture({type});
    harness.window.YOTMResults.open('source-token');
    assert.deepEqual(harness.calls.map(call => call.action), ['yotm_job_results']);
    const html = harness.nodes.get('#yotm_job_report').content;
    assert.match(html, /Saved job results/);
    if (type !== 'recommendation') { assert.match(html, /&lt;bad title&gt;/); } else { assert.doesNotMatch(html, /Successful:/); assert.doesNotMatch(html, /yotm_result_filter/); }
    assert.doesNotMatch(html, /<script>unsafe/);
    assert.match(html, /Saved deadline/);
    assert.match(html, /thumbnail/);
  }
});

test('filter and pagination remain read-only and propagate the selected filter', () => {
  const harness = fixture();
  harness.window.YOTMResults.open('source-token');
  harness.$('#filter').value = 'unsuccessful';
  harness.handlers.get('change:#yotm_result_filter').call(harness.$('#filter'));
  assert.equal(harness.calls.at(-1).data.filter, 'unsuccessful');
  assert.ok(harness.calls.every(call => call.action === 'yotm_job_results'));
});

test('retry sends only explicitly checked keys and starts the new token', () => {
  const harness = fixture({respond(action, data, report) { return action === 'yotm_job_retry' ? {success: true, data: {token: 'new-token'}} : {success: true, data: structuredClone(report)}; }});
  harness.window.YOTMResults.open('source-token');
  const checkbox = harness.$('#checkbox');
  checkbox.value = 'a'.repeat(64);
  checkbox.prop('checked', true);
  harness.handlers.get('change:.yotm-result-select').call(checkbox);
  harness.handlers.get('click:#yotm_result_retry')();
  const retry = harness.calls.find(call => call.action === 'yotm_job_retry');
  assert.equal(retry.data.token, 'source-token');
  assert.deepEqual(Array.from(retry.data.keys), ['a'.repeat(64)]);
  const resumes = harness.calls.filter(call => call.action === 'resume');
  assert.equal(resumes.length, 1);
  assert.equal(resumes[0].token, 'new-token');
});

test('JSON export uses public result pages and never starts processing', () => {
  const harness = fixture({respond(action, data, report) { const result = structuredClone(report); result.page = data.page || 1; result.pages = 2; result.total = 2; result.items[0].key = String(result.page); return {success: true, data: result}; }});
  harness.window.YOTMResults.open('source-token');
  harness.handlers.get('click:#yotm_result_export')();
  assert.deepEqual(harness.calls.map(call => call.action), ['yotm_job_results', 'yotm_job_results', 'yotm_job_results']);
  const exported = JSON.parse(harness.blobs[0]);
  assert.equal(exported.items.length, 2);
  assert.equal(exported.export_complete, true);
  assert.deepEqual(exported.job.context.selected_sizes, ['thumbnail']);
  assert.equal(harness.downloads[0], 'thumbnail-manager-source-token.json');
});

test('missing or expired result errors are explicit without processing', () => {
  const harness = fixture({respond() { return {success: false, data: {msg: 'Result expired. Run a new scan.'}}; }});
  harness.window.YOTMResults.open('missing');
  assert.match(harness.nodes.get('#yotm_job_report').content, /Result expired/);
  assert.deepEqual(harness.calls.map(call => call.action), ['yotm_job_results']);
});


test('export from a filtered page uses the full export pagination metadata', () => {
  const harness = fixture({respond(action, data, report) {
    const result = structuredClone(report);
    if (!data.filter) { result.page = data.page || 1; result.pages = 2; result.total = 101; result.items = Array.from({length: result.page === 1 ? 100 : 1}, (_, index) => ({key: String(index)})); }
    return {success: true, data: result};
  }});
  harness.window.YOTMResults.open('source-token', 1, 'unsuccessful');
  harness.handlers.get('click:#yotm_result_export')();
  const exported = JSON.parse(harness.blobs[0]);
  assert.equal(exported.total, 101);
  assert.equal(exported.pages, 2);
  assert.equal(exported.filter, '');
  assert.equal(exported.items.length, 101);
});


test('the action beside current results opens only the public saved report', () => {
  const harness = fixture();
  const button = harness.$('#result-button');
  button.attributes['data-token'] = 'source-token';
  harness.handlers.get('click:.yotm-open-report').call(button);
  assert.deepEqual(harness.calls.map(call => call.action), ['yotm_job_results']);
});
